<?php

/**
 * The GitHub publiccode harvest, as the administrator sees and drives it.
 *
 * The harvest itself is a flow OpenCatalogi ships on the `publiccode` schema
 * (`lib/Settings/register.d/publiccode-github-harvest.json`) and OpenRegister
 * runs. This service is the admin's handle on it: it reports whether the pieces
 * are in place, writes the shard synchronizations into integriq when asked,
 * switches the flow on and off and starts a run.
 *
 * It never touches the GitHub token. The `github-api` source authenticates
 * through integriq's credential broker, and this service only reads whether
 * that source exists and is enabled.
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
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service;

use DateTimeInterface;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Status, set-up, switch and run for the GitHub publiccode harvest.
 */
class PubliccodeHarvestService {

	/**
	 * The name the shipped flow carries. The flow store identifies a declared
	 * flow by (app, name, trigger schema), so this is its key.
	 *
	 * @var string
	 */
	public const FLOW_NAME = 'GitHub publiccode harvest';

	/**
	 * The app the declared flow belongs to.
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
	 * The shard definitions, relative to the app root.
	 *
	 * @var string
	 */
	public const SHARDS_FILE = '/lib/Settings/publiccode-github-shards.json';

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
	 * Everything the admin section shows.
	 *
	 * @return array<string, mixed> The status.
	 *
	 * @spec openspec/changes/publiccode-github-harvest/specs/publiccode-github-harvest/spec.md#requirement-req-pgh-006-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi
	 */
	public function status(): array {
		$definitions = $this->shardDefinitions();
		$integriq = $this->integriqInstalled();

		$status = [
			'integriq' => $integriq,
			'source' => null,
			'shards' => ['expected' => count($definitions['shards']), 'present' => 0],
			'flow' => $this->flowStatus(),
			'lastRun' => null,
		];

		if ($integriq === true) {
			$status['source'] = $this->sourceStatus(slug: (string)$definitions['source']);
			$status['shards']['present'] = $this->countPresentShards(definitions: $definitions);
		}

		if (($status['flow']['uuid'] ?? null) !== null) {
			$status['lastRun'] = $this->lastRun(flowUuid: (string)$status['flow']['uuid']);
		}

		return $status;
	}//end status()

	/**
	 * Write one integriq synchronization per shard, updating those that exist.
	 *
	 * @return array{created: int, updated: int} What was written.
	 *
	 * @throws RuntimeException When integriq is not installed.
	 *
	 * @spec openspec/changes/publiccode-github-harvest/specs/publiccode-github-harvest/spec.md#requirement-req-pgh-006-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi
	 */
	public function setUp(): array {
		$this->assertIntegriq();

		$definitions = $this->shardDefinitions();
		$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

		$created = 0;
		$updated = 0;
		foreach ($definitions['shards'] as $shard) {
			$synchronization = self::synchronizationFor(definitions: $definitions, shard: $shard);
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
			'OpenCatalogi: GitHub publiccode harvest set up',
			['created' => $created, 'updated' => $updated]
		);

		return ['created' => $created, 'updated' => $updated];
	}//end setUp()

	/**
	 * Switch the harvest flow on or off.
	 *
	 * Switching on adopts the flow first, so the administrator who switches it
	 * on becomes its owner. The flow arrives unowned on purpose (OpenRegister's
	 * adoption contract) and an unowned flow never dispatches.
	 *
	 * @param bool $enabled Whether the harvest should run.
	 *
	 * @return array<string, mixed> The flow status after the switch.
	 *
	 * @throws RuntimeException When integriq is missing or the flow was not imported.
	 *
	 * @spec openspec/changes/publiccode-github-harvest/specs/publiccode-github-harvest/spec.md#requirement-req-pgh-006-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi
	 */
	public function setEnabled(bool $enabled): array {
		$this->assertIntegriq();

		$flowService = $this->container->get('OCA\OpenRegister\Service\Flow\FlowService');
		$flow = $this->findFlow();
		if ($flow === null) {
			throw new RuntimeException('The harvest flow is not imported yet. Reimport the OpenCatalogi configuration first.');
		}

		if ($enabled === true) {
			$flow = $flowService->adopt($flow);
		}

		$flowService->save(['enabled' => $enabled], (string)$flow->getUuid());

		$this->logger->info('OpenCatalogi: GitHub publiccode harvest switched', ['enabled' => $enabled]);

		return $this->flowStatus();
	}//end setEnabled()

	/**
	 * Start a run now. The run is queued; the flow engine's worker advances it.
	 *
	 * @return array<string, mixed> The queued run.
	 *
	 * @throws RuntimeException When the harvest is not set up and switched on.
	 *
	 * @spec openspec/changes/publiccode-github-harvest/specs/publiccode-github-harvest/spec.md#requirement-req-pgh-006-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi
	 */
	public function runNow(): array {
		$this->assertIntegriq();

		$flow = $this->findFlow();
		if ($flow === null || $flow->getEnabled() !== true) {
			throw new RuntimeException('Switch the harvest on before you run it.');
		}

		$definitions = $this->shardDefinitions();
		if ($this->countPresentShards(definitions: $definitions) < count($definitions['shards'])) {
			throw new RuntimeException('Set the harvest up before you run it: not every shard exists in integriq.');
		}

		$run = $this->container->get('OCA\OpenRegister\Service\Flow\FlowService')->run((string)$flow->getUuid());

		return self::runSummary(run: $run);
	}//end runNow()

	/**
	 * The integriq synchronization one shard becomes.
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
	 * Read the shard file.
	 *
	 * @return array<string, mixed> The decoded definitions.
	 *
	 * @throws RuntimeException When the file is missing or malformed.
	 *
	 * @spec openspec/changes/publiccode-github-harvest/specs/publiccode-github-harvest/spec.md#requirement-req-pgh-002-shards-partition-the-file-sizes-so-no-search-passes-1000-results
	 */
	public function shardDefinitions(): array {
		$path = $this->appManager->getAppPath('opencatalogi') . self::SHARDS_FILE;
		$content = @file_get_contents($path);
		if ($content === false) {
			throw new RuntimeException('The shard file ' . self::SHARDS_FILE . ' is missing.');
		}

		$decoded = json_decode($content, true);
		if (is_array($decoded) === false || is_array($decoded['shards'] ?? null) === false) {
			throw new RuntimeException('The shard file ' . self::SHARDS_FILE . ' is not valid.');
		}

		return $decoded;
	}//end shardDefinitions()

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
	 * @return void
	 *
	 * @throws RuntimeException When integriq is not installed.
	 */
	private function assertIntegriq(): void {
		if ($this->integriqInstalled() === false) {
			throw new RuntimeException('The GitHub harvest needs integriq. Install and enable integriq first.');
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
	 * How many shard synchronizations exist.
	 *
	 * @param array<string, mixed> $definitions The shard file.
	 *
	 * @return int The count.
	 */
	private function countPresentShards(array $definitions): int {
		$present = 0;
		foreach ($definitions['shards'] as $shard) {
			if ($this->findIntegriqObject(schema: 'synchronization', slug: (string)$shard['synchronization']) !== null) {
				$present++;
			}
		}

		return $present;
	}//end countPresentShards()

	/**
	 * Whether the code search source exists and is enabled. The section links
	 * to it by uuid, so the administrator can add the token there.
	 *
	 * @param string $slug The source slug.
	 *
	 * @return array<string, mixed> The source status.
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
	 * The shipped flow, or null when it was not imported.
	 *
	 * @return object|null The flow entity.
	 */
	private function findFlow(): ?object {
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
			if ($flow->getName() === self::FLOW_NAME) {
				return $flow;
			}
		}

		return null;
	}//end findFlow()

	/**
	 * What the section shows about the flow.
	 *
	 * @return array<string, mixed> The flow status.
	 */
	private function flowStatus(): array {
		$flow = $this->findFlow();
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
