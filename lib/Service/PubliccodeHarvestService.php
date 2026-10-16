<?php

/**
 * The publiccode harvests, as the administrator sees and drives them.
 *
 * Each harvest is a flow OpenCatalogi ships on the `publiccode` schema and
 * OpenRegister runs: the GitHub harvest
 * (`lib/Settings/register.d/publiccode-github-harvest.json`) and the
 * developer.overheid.nl harvest
 * (`lib/Settings/register.d/publiccode-developer-overheid-harvest.json`). This
 * service is the admin's handle on them: it reports whether the pieces are in
 * place, writes the synchronizations into integriq when asked, switches a flow
 * on and off and starts a run. Every public method takes the harvest key, and
 * `github` is the default, so the GitHub harvest's callers are unchanged.
 *
 * It never touches a token or an API key. Each source authenticates through
 * integriq's credential broker, and this service only reads whether that source
 * exists and is enabled. It does not look up the credential at all.
 *
 * Everything is resolved from the container by class name, so the app keeps
 * working when integriq is absent: the harvest then reports what it needs and
 * does nothing.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/publiccode-github-harvest/specs/publiccode-github-harvest/spec.md#requirement-req-pgh-006-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi
 * @spec openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-008-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service;

use DateTimeInterface;
use InvalidArgumentException;
use OCA\OpenCatalogi\Exception\HarvestNotReadyException;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Status, set-up, switch and run for the publiccode harvests.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class PubliccodeHarvestService {

	/**
	 * The GitHub harvest's key, and the default of every method.
	 *
	 * @var string
	 */
	public const HARVEST_GITHUB = 'github';

	/**
	 * The developer.overheid.nl harvest's key.
	 *
	 * @var string
	 */
	public const HARVEST_DEVELOPER_OVERHEID = 'developer-overheid';

	/**
	 * The name the shipped GitHub flow carries. The flow store identifies a
	 * declared flow by (app, name, trigger schema), so this is its key.
	 *
	 * @var string
	 */
	public const FLOW_NAME = 'GitHub publiccode harvest';

	/**
	 * The name the shipped developer.overheid.nl flow carries.
	 *
	 * @var string
	 */
	public const DEVELOPER_OVERHEID_FLOW_NAME = 'developer.overheid.nl publiccode harvest';

	/**
	 * The app the declared flows belong to.
	 *
	 * @var string
	 */
	public const FLOW_APP = 'opencatalogi';

	/**
	 * The integriq app id.
	 *
	 * @var string
	 */
	public const INTEGRIQ = 'integriq';

	/**
	 * Integriq's register, which holds sources and synchronizations.
	 *
	 * @var string
	 */
	public const INTEGRIQ_REGISTER = 'integriq';

	/**
	 * The GitHub shard definitions, relative to the app root.
	 *
	 * @var string
	 */
	public const SHARDS_FILE = '/lib/Settings/publiccode-github-shards.json';

	/**
	 * The developer.overheid.nl synchronization definitions, relative to the app root.
	 *
	 * @var string
	 */
	public const DEVELOPER_OVERHEID_FILE = '/lib/Settings/publiccode-developer-overheid-sync.json';

	/**
	 * How each harvest names itself in messages and log lines.
	 *
	 * @var array<string, string>
	 */
	private const LABELS = [
		self::HARVEST_GITHUB => 'GitHub',
		self::HARVEST_DEVELOPER_OVERHEID => 'developer.overheid.nl',
	];

	/**
	 * Constructor.
	 *
	 * @param IAppManager $appManager Tells whether integriq and OpenRegister are installed.
	 * @param ContainerInterface $container Resolves OpenRegister's services by name.
	 * @param LoggerInterface $logger Records set-up and switch actions.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Everything the admin section of one harvest shows.
	 *
	 * The GitHub status counts its search shards under `shards`; the
	 * developer.overheid.nl status counts its synchronization under
	 * `synchronizations` and also reports the raw-file sources under `sources`.
	 * Neither has a credential block: no credential is looked up.
	 *
	 * @param string $harvest The harvest key.
	 *
	 * @return array<string, mixed> The status.
	 *
	 * @throws RuntimeException When the harvest's definition file is missing or malformed.
	 *
	 * @spec openspec/changes/publiccode-github-harvest/specs/publiccode-github-harvest/spec.md#requirement-req-pgh-006-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi
	 * @spec openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-008-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi
	 * @spec openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-009-the-api-key-never-touches-opencatalogi
	 */
	public function status(string $harvest = self::HARVEST_GITHUB): array {
		$definition = $this->definition(harvest: $harvest);
		$integriq = $this->integriqInstalled();
		$counted = $this->countKey(harvest: $harvest);

		$status = [
			'integriq' => $integriq,
			'source' => null,
		];
		if ($harvest === self::HARVEST_DEVELOPER_OVERHEID) {
			$status['sources'] = null;
		}

		$status[$counted] = ['expected' => count($definition['synchronizations']), 'present' => 0];
		$status['flow'] = $this->flowStatus(flowName: $definition['flowName']);
		$status['lastRun'] = null;

		if ($integriq === true) {
			$status['source'] = $this->sourceStatus(slug: $definition['source']);
			$status[$counted]['present'] = $this->countPresent(synchronizations: $definition['synchronizations']);
			if ($harvest === self::HARVEST_DEVELOPER_OVERHEID) {
				$status['sources'] = $this->otherSourcesStatus(slugs: $definition['sources']);
			}
		}

		if (($status['flow']['uuid'] ?? null) !== null) {
			$status['lastRun'] = $this->lastRun(flowUuid: (string)$status['flow']['uuid']);
		}

		return $status;
	}//end status()

	/**
	 * Write the harvest's integriq synchronizations, updating those that exist.
	 *
	 * @param string $harvest The harvest key.
	 *
	 * @return array{created: int, updated: int} What was written.
	 *
	 * @throws HarvestNotReadyException When integriq or OpenRegister is not installed.
	 * @throws RuntimeException When the harvest's definition file is missing or malformed.
	 *
	 * @spec openspec/changes/publiccode-github-harvest/specs/publiccode-github-harvest/spec.md#requirement-req-pgh-006-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi
	 * @spec openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-002-the-register-is-read-page-by-page-through-one-synchronization
	 */
	public function setUp(string $harvest = self::HARVEST_GITHUB): array {
		$label = $this->label(harvest: $harvest);
		$this->assertIntegriq(label: $label);

		$definition = $this->definition(harvest: $harvest);
		$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

		$created = 0;
		$updated = 0;
		foreach ($definition['synchronizations'] as $synchronization) {
			$existing = $this->findIntegriqObject(schema: 'synchronization', slug: (string)$synchronization['slug']);

			$objectService->saveObject(
				object: $synchronization,
				register: self::INTEGRIQ_REGISTER,
				schema: 'synchronization',
				uuid: $existing,
			);

			if ($existing === null) {
				$created++;
				continue;
			}

			$updated++;
		}//end foreach

		$this->logger->info(
			'OpenCatalogi: ' . $label . ' publiccode harvest set up',
			['created' => $created, 'updated' => $updated]
		);

		return ['created' => $created, 'updated' => $updated];
	}//end setUp()

	/**
	 * Switch a harvest flow on or off.
	 *
	 * Switching on adopts the flow first, so the administrator who switches it
	 * on becomes its owner. The flow arrives unowned on purpose (OpenRegister's
	 * adoption contract) and an unowned flow never dispatches.
	 *
	 * @param bool $enabled Whether the harvest should run.
	 * @param string $harvest The harvest key.
	 *
	 * @return array<string, mixed> The flow status after the switch.
	 *
	 * @throws HarvestNotReadyException When integriq is missing or the flow was not imported.
	 *
	 * @spec openspec/changes/publiccode-github-harvest/specs/publiccode-github-harvest/spec.md#requirement-req-pgh-006-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi
	 * @spec openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-008-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi
	 */
	public function setEnabled(bool $enabled, string $harvest = self::HARVEST_GITHUB): array {
		$label = $this->label(harvest: $harvest);
		$this->assertIntegriq(label: $label);
		$flowName = $this->flowName(harvest: $harvest);

		$flowService = $this->container->get('OCA\OpenRegister\Service\Flow\FlowService');
		$flow = $this->findFlow(flowName: $flowName);
		if ($flow === null) {
			throw new HarvestNotReadyException(
				message: 'The harvest flow is not imported yet. Reimport the OpenCatalogi configuration first.',
				error: HarvestNotReadyException::FLOW_NOT_IMPORTED
			);
		}

		if ($enabled === true) {
			$flow = $flowService->adopt($flow);
		}

		$flowService->save(['enabled' => $enabled], (string)$flow->getUuid());

		$this->logger->info('OpenCatalogi: ' . $label . ' publiccode harvest switched', ['enabled' => $enabled]);

		return $this->flowStatus(flowName: $flowName);
	}//end setEnabled()

	/**
	 * Start a run now. The run is queued; the flow engine's worker advances it.
	 *
	 * @param string $harvest The harvest key.
	 *
	 * @return array<string, mixed> The queued run.
	 *
	 * @throws HarvestNotReadyException When the harvest is not set up and switched on.
	 * @throws RuntimeException When the harvest's definition file is missing or malformed.
	 *
	 * @spec openspec/changes/publiccode-github-harvest/specs/publiccode-github-harvest/spec.md#requirement-req-pgh-006-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi
	 * @spec openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-008-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi
	 */
	public function runNow(string $harvest = self::HARVEST_GITHUB): array {
		$this->assertIntegriq(label: $this->label(harvest: $harvest));

		$flow = $this->findFlow(flowName: $this->flowName(harvest: $harvest));
		if ($flow === null || $flow->getEnabled() !== true) {
			throw new HarvestNotReadyException(
				message: 'Switch the harvest on before you run it.',
				error: HarvestNotReadyException::SWITCHED_OFF
			);
		}

		$definition = $this->definition(harvest: $harvest);
		if ($this->countPresent(synchronizations: $definition['synchronizations']) < count($definition['synchronizations'])) {
			throw new HarvestNotReadyException(message: $definition['notSetUp'], error: HarvestNotReadyException::NOT_SET_UP);
		}

		$run = $this->container->get('OCA\OpenRegister\Service\Flow\FlowService')->run((string)$flow->getUuid());

		return self::runSummary(run: $run);
	}//end runNow()

	/**
	 * The integriq synchronization one GitHub shard becomes.
	 *
	 * @param array<string, mixed> $definitions The shard file.
	 * @param array<string, mixed> $shard One shard entry.
	 *
	 * @return array<string, mixed> The synchronization object.
	 *
	 * @spec openspec/changes/publiccode-github-harvest/specs/publiccode-github-harvest/spec.md#requirement-req-pgh-002-shards-partition-the-file-sizes-so-no-search-passes-1000-results
	 */
	public static function synchronizationFor(array $definitions, array $shard): array {
		$range = (int)$shard['min'] . '..' . (int)$shard['max'];

		return [
			'slug' => (string)$shard['synchronization'],
			'name' => 'GitHub publiccode harvest, files of ' . $range . ' bytes',
			'description' => 'One shard of OpenCatalogi\'s GitHub publiccode harvest. Written by OpenCatalogi '
				. 'from lib/Settings/publiccode-github-shards.json when an administrator sets the harvest up; '
				. 'change the ranges there, not here. The flow "' . self::FLOW_NAME . '" fetches its pages.',
			'sourceId' => (string)$definitions['source'],
			'sourceType' => 'api',
			'sourceConfig' => [
				'endpoint' => (string)$definitions['endpoint'],
				'query' => [
					'q' => (string)$definitions['query'] . ' size:' . $range,
					'per_page' => (int)$definitions['perPage'],
				],
				'resultsPosition' => 'items',
				'totalPosition' => 'total_count',
				'maxPages' => (int)$definitions['maxPages'],
				'prefetchConcurrency' => 1,
			],
		];
	}//end synchronizationFor()

	/**
	 * The integriq synchronization one developer.overheid.nl entry becomes.
	 *
	 * The entry's `sourceConfig` is taken verbatim: the file is the one place
	 * the list call is defined.
	 *
	 * @param array<string, mixed> $definitions The synchronization file.
	 * @param array<string, mixed> $entry One entry of its `synchronizations`.
	 *
	 * @return array<string, mixed> The synchronization object.
	 *
	 * @spec openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-002-the-register-is-read-page-by-page-through-one-synchronization
	 */
	public static function developerOverheidSynchronizationFor(array $definitions, array $entry): array {
		return [
			'slug' => (string)$entry['synchronization'],
			'name' => (string)$entry['name'],
			'description' => 'The list step of OpenCatalogi\'s developer.overheid.nl publiccode harvest. Written by '
				. 'OpenCatalogi from lib/Settings/publiccode-developer-overheid-sync.json when an administrator sets '
				. 'the harvest up; change it there, not here. The flow "' . self::DEVELOPER_OVERHEID_FLOW_NAME
				. '" fetches its pages.',
			'sourceId' => (string)$definitions['source'],
			'sourceType' => 'api',
			'sourceConfig' => (array)$entry['sourceConfig'],
		];
	}//end developerOverheidSynchronizationFor()

	/**
	 * Read the GitHub shard file.
	 *
	 * @return array<string, mixed> The decoded definitions.
	 *
	 * @throws RuntimeException When the file is missing or malformed.
	 *
	 * @spec openspec/changes/publiccode-github-harvest/specs/publiccode-github-harvest/spec.md#requirement-req-pgh-002-shards-partition-the-file-sizes-so-no-search-passes-1000-results
	 */
	public function shardDefinitions(): array {
		return $this->readDefinitionFile(file: self::SHARDS_FILE, noun: 'shard file', listKey: 'shards');
	}//end shardDefinitions()

	/**
	 * Read the developer.overheid.nl synchronization file.
	 *
	 * Fails closed: a missing file, a file without a source or an entry without
	 * a synchronization slug or sourceConfig raises, so nothing is written.
	 *
	 * @return array<string, mixed> The decoded definitions.
	 *
	 * @throws RuntimeException When the file is missing or malformed.
	 *
	 * @spec openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-002-the-register-is-read-page-by-page-through-one-synchronization
	 */
	public function developerOverheidDefinitions(): array {
		$decoded = $this->readDefinitionFile(file: self::DEVELOPER_OVERHEID_FILE, noun: 'synchronization file', listKey: 'synchronizations');

		$valid = (is_string($decoded['source'] ?? null) === true && $decoded['source'] !== '')
			&& is_array($decoded['sources'] ?? []) === true;
		foreach ($decoded['synchronizations'] as $entry) {
			$valid = $valid
				&& is_string($entry['synchronization'] ?? null) === true
				&& is_string($entry['name'] ?? null) === true
				&& is_array($entry['sourceConfig'] ?? null) === true;
		}

		if ($valid === false) {
			throw new RuntimeException('The synchronization file ' . self::DEVELOPER_OVERHEID_FILE . ' is not valid.');
		}

		return $decoded;
	}//end developerOverheidDefinitions()

	/**
	 * Read one definition file from the app root.
	 *
	 * @param string $file The path relative to the app root.
	 * @param string $noun How the file is named in the message.
	 * @param string $listKey The key that must hold a list.
	 *
	 * @return array<string, mixed> The decoded file.
	 *
	 * @throws RuntimeException When the file is missing or malformed.
	 */
	private function readDefinitionFile(string $file, string $noun, string $listKey): array {
		$path = $this->appManager->getAppPath('opencatalogi') . $file;
		$content = false;
		if (is_readable($path) === true) {
			$content = file_get_contents($path);
		}

		if ($content === false) {
			throw new RuntimeException('The ' . $noun . ' ' . $file . ' is missing.');
		}

		$decoded = json_decode($content, true);
		if (is_array($decoded) === false || is_array($decoded[$listKey] ?? null) === false) {
			throw new RuntimeException('The ' . $noun . ' ' . $file . ' is not valid.');
		}

		return $decoded;
	}//end readDefinitionFile()

	/**
	 * One harvest, in the shape every method works with.
	 *
	 * @param string $harvest The harvest key.
	 *
	 * @return array{flowName: string, source: string, sources: array<int, string>, synchronizations: array<int, array<string, mixed>>, notSetUp: string}
	 *
	 * @throws RuntimeException When the definition file is missing or malformed.
	 */
	private function definition(string $harvest): array {
		$this->label(harvest: $harvest);

		if ($harvest === self::HARVEST_DEVELOPER_OVERHEID) {
			$definitions = $this->developerOverheidDefinitions();

			return [
				'flowName' => self::DEVELOPER_OVERHEID_FLOW_NAME,
				'source' => (string)$definitions['source'],
				'sources' => array_values(array_map('strval', (array)($definitions['sources'] ?? []))),
				'synchronizations' => array_map(
					static fn (array $entry): array => self::developerOverheidSynchronizationFor(definitions: $definitions, entry: $entry),
					array_values($definitions['synchronizations'])
				),
				'notSetUp' => 'Set the harvest up before you run it: the synchronization does not exist in integriq.',
			];
		}

		$definitions = $this->shardDefinitions();

		return [
			'flowName' => self::FLOW_NAME,
			'source' => (string)$definitions['source'],
			'sources' => [],
			'synchronizations' => array_map(
				static fn (array $shard): array => self::synchronizationFor(definitions: $definitions, shard: $shard),
				array_values($definitions['shards'])
			),
			'notSetUp' => 'Set the harvest up before you run it: not every shard exists in integriq.',
		];
	}//end definition()

	/**
	 * The harvest's name in messages, refusing an unknown key.
	 *
	 * @param string $harvest The harvest key.
	 *
	 * @return string The label.
	 *
	 * @throws InvalidArgumentException When the key names no harvest.
	 */
	private function label(string $harvest): string {
		if (isset(self::LABELS[$harvest]) === false) {
			throw new InvalidArgumentException('Unknown publiccode harvest: ' . $harvest);
		}

		return self::LABELS[$harvest];
	}//end label()

	/**
	 * The name of the harvest's shipped flow.
	 *
	 * @param string $harvest The harvest key.
	 *
	 * @return string The flow name.
	 */
	private function flowName(string $harvest): string {
		if ($harvest === self::HARVEST_DEVELOPER_OVERHEID) {
			return self::DEVELOPER_OVERHEID_FLOW_NAME;
		}

		return self::FLOW_NAME;
	}//end flowName()

	/**
	 * The status key the synchronizations are counted under.
	 *
	 * @param string $harvest The harvest key.
	 *
	 * @return string `shards` for GitHub, `synchronizations` otherwise.
	 */
	private function countKey(string $harvest): string {
		if ($harvest === self::HARVEST_GITHUB) {
			return 'shards';
		}

		return 'synchronizations';
	}//end countKey()

	/**
	 * Whether integriq is installed and enabled.
	 *
	 * @return bool True when the harvest can run at all.
	 */
	private function integriqInstalled(): bool {
		return $this->appManager->isEnabledForAnyone(self::INTEGRIQ);
	}//end integriqInstalled()

	/**
	 * Refuse when integriq is absent.
	 *
	 * @param string $label The harvest's name in the message.
	 *
	 * @return void
	 *
	 * @throws HarvestNotReadyException When integriq or OpenRegister is not installed.
	 */
	private function assertIntegriq(string $label): void {
		if ($this->integriqInstalled() === false) {
			throw new HarvestNotReadyException(
				message: 'The ' . $label . ' harvest needs integriq. Install and enable integriq first.',
				error: HarvestNotReadyException::NEEDS_INTEGRIQ
			);
		}

		// Every action reaches OpenRegister's services through the container
		// (ADR-083: an optional capability establishes availability first, so
		// this class stays constructible on an instance without OpenRegister).
		if ($this->appManager->isInstalled('openregister') === false) {
			throw new HarvestNotReadyException(
				message: 'The ' . $label . ' harvest needs OpenRegister. Install and enable OpenRegister first.',
				error: HarvestNotReadyException::NEEDS_OPENREGISTER
			);
		}
	}//end assertIntegriq()

	/**
	 * The uuid of an integriq object by slug, or null.
	 *
	 * @param string $schema The integriq schema slug.
	 * @param string $slug The object slug.
	 *
	 * @return string|null The uuid, or null when absent.
	 */
	private function findIntegriqObject(string $schema, string $slug): ?string {
		$object = $this->findIntegriqEntity(schema: $schema, slug: $slug);
		if ($object === null) {
			return null;
		}

		return (string)$object->getUuid();
	}//end findIntegriqObject()

	/**
	 * An integriq object by slug, or null.
	 *
	 * @param string $schema The integriq schema slug.
	 * @param string $slug The object slug.
	 *
	 * @return object|null The object entity, or null when absent.
	 */
	private function findIntegriqEntity(string $schema, string $slug): ?object {
		try {
			$object = $this->container->get('OCA\OpenRegister\Service\ObjectService')->find(
				id: $slug,
				register: self::INTEGRIQ_REGISTER,
				schema: $schema,
				_multitenancy: false
			);
		} catch (Throwable) {
			return null;
		}

		if (is_object($object) === false) {
			return null;
		}

		return $object;
	}//end findIntegriqEntity()

	/**
	 * How many of the given synchronizations exist.
	 *
	 * @param array<int, array<string, mixed>> $synchronizations The synchronization objects.
	 *
	 * @return int The count.
	 */
	private function countPresent(array $synchronizations): int {
		$present = 0;
		foreach ($synchronizations as $synchronization) {
			if ($this->findIntegriqObject(schema: 'synchronization', slug: (string)$synchronization['slug']) !== null) {
				$present++;
			}
		}

		return $present;
	}//end countPresent()

	/**
	 * Whether a source exists and is enabled. The section links to it by uuid,
	 * so the administrator can add the token or key there.
	 *
	 * @param string $slug The source slug.
	 *
	 * @return array<string, mixed> The source status.
	 *
	 * @spec openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-009-the-api-key-never-touches-opencatalogi
	 */
	private function sourceStatus(string $slug): array {
		$source = $this->findIntegriqEntity(schema: 'source', slug: $slug);
		if ($source === null) {
			return [
				'slug' => $slug,
				'exists' => false,
				'enabled' => false,
				'uuid' => null,
			];
		}

		$data = $source->getObject();

		return [
			'slug' => $slug,
			'exists' => true,
			'enabled' => (($data['isEnabled'] ?? false) === true),
			'uuid' => (string)$source->getUuid(),
		];
	}//end sourceStatus()

	/**
	 * Whether each raw-file source exists and is enabled, by slug.
	 *
	 * @param array<int, string> $slugs The source slugs.
	 *
	 * @return array<string, array{exists: bool, enabled: bool}> The statuses.
	 */
	private function otherSourcesStatus(array $slugs): array {
		$statuses = [];
		foreach ($slugs as $slug) {
			$source = $this->sourceStatus(slug: $slug);
			$statuses[$slug] = ['exists' => $source['exists'], 'enabled' => $source['enabled']];
		}

		return $statuses;
	}//end otherSourcesStatus()

	/**
	 * A shipped flow by name, or null when it was not imported.
	 *
	 * @param string $flowName The flow's name.
	 *
	 * @return object|null The flow entity.
	 */
	private function findFlow(string $flowName): ?object {
		if ($this->appManager->isEnabledForAnyone('openregister') === false) {
			return null;
		}

		try {
			$flows = $this->container->get('OCA\OpenRegister\Service\Flow\FlowService')->findAll(app: self::FLOW_APP, limit: 500);
		} catch (Throwable $e) {
			$this->logger->warning('OpenCatalogi: could not list flows: ' . $e->getMessage());
			return null;
		}

		foreach ($flows as $flow) {
			if ($flow->getName() === $flowName) {
				return $flow;
			}
		}

		return null;
	}//end findFlow()

	/**
	 * What the section shows about a flow.
	 *
	 * @param string $flowName The flow's name.
	 *
	 * @return array<string, mixed> The flow status.
	 */
	private function flowStatus(string $flowName): array {
		$flow = $this->findFlow(flowName: $flowName);
		if ($flow === null) {
			return ['uuid' => null, 'imported' => false, 'enabled' => false, 'owner' => null, 'runAs' => null, 'cron' => null];
		}

		$runAs = null;
		$cron = null;
		foreach (($flow->getNodes() ?? []) as $node) {
			if (($node['type'] ?? '') === 'openregister.trigger-schedule') {
				$runAs = ($node['config']['runAs'] ?? null);
				$cron = ($node['config']['cron'] ?? null);
			}
		}

		return [
			'uuid' => (string)$flow->getUuid(),
			'imported' => true,
			'enabled' => ($flow->getEnabled() === true),
			'owner' => $flow->getOwner(),
			'runAs' => $runAs,
			'cron' => $cron,
		];
	}//end flowStatus()

	/**
	 * The latest run of the flow.
	 *
	 * @param string $flowUuid The flow.
	 *
	 * @return array<string, mixed>|null The run summary, or null when it never ran.
	 */
	private function lastRun(string $flowUuid): ?array {
		try {
			$runs = $this->container->get('OCA\OpenRegister\Db\FlowRunMapper')->findAllRuns(flowId: $flowUuid, limit: 1);
		} catch (Throwable) {
			return null;
		}

		if ($runs === []) {
			return null;
		}

		return self::runSummary(run: $runs[0]);
	}//end lastRun()

	/**
	 * The fields of a run the section shows.
	 *
	 * @param object $run A FlowRun entity.
	 *
	 * @return array<string, mixed> The summary.
	 *
	 * @spec openspec/changes/publiccode-github-harvest/specs/publiccode-github-harvest/spec.md#requirement-req-pgh-004-a-file-that-cannot-be-read-is-skipped-not-written
	 * @spec openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-006-a-file-that-cannot-be-read-is-skipped-not-written
	 */
	private static function runSummary(object $run): array {
		return [
			'uuid' => $run->getUuid(),
			'status' => $run->getStatus(),
			'trigger' => $run->getTrigger(),
			'started' => self::atom(value: $run->getCreated()),
			'updated' => self::atom(value: $run->getUpdated()),
			'resumeAt' => self::atom(value: $run->getResumeAt()),
			'error' => $run->getError(),
			// Steps carried past by `onError: continue`: a shard whose write the
			// schema refused ends on a failed step while the run still reads as
			// finished, so without this count 24 clean shards and 24 shards of
			// which three lost a page look the same (REQ-PGH-004).
			'failedSteps' => (array_count_values(array_column((array)$run->getLog(), 'status'))['failed'] ?? 0),
		];
	}//end runSummary()

	/**
	 * A date as ISO 8601, or null.
	 *
	 * @param mixed $value A date, or anything else.
	 *
	 * @return string|null The formatted date.
	 */
	private static function atom(mixed $value): ?string {
		if ($value instanceof DateTimeInterface) {
			return $value->format(DATE_ATOM);
		}

		return null;
	}//end atom()
}//end class
