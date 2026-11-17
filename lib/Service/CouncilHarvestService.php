<?php

/**
 * Sets up, switches and runs the council documents harvest.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/council-documents-from-notubiz-and-ibabs/specs/council-documents-harvest/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service;

use OCA\OpenCatalogi\Contract\CouncilHarvestServiceInterface;
use OCA\OpenRegister\Db\FlowRunFilter;
use OCA\OpenRegister\Db\FlowRunListing;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Db\FlowRunViewer;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Throwable;

/**
 * The council documents harvest: one shipped flow per council system, the
 * integriq synchronization and fetch-file rule set-up writes for the system
 * an administrator picks, and the switches of the admin section.
 *
 * Every OpenRegister and integriq service is reached through the container
 * after availability is established (ADR-083), so the class stays
 * constructible on an instance without them.
 *
 * @spec openspec/changes/council-documents-from-notubiz-and-ibabs/specs/council-documents-harvest/spec.md#requirement-an-administrator-sets-up-the-council-documents-harvest-req-cdh-001
 */
class CouncilHarvestService implements CouncilHarvestServiceInterface {

	/**
	 * The templates set-up writes, relative to the app root.
	 */
	public const SOURCES_FILE = '/lib/Settings/council-documents-sources.json';

	/**
	 * The app config key holding the set-up council system (`notubiz` or `ibabs`).
	 */
	public const KIND_KEY = 'council_harvest_kind';

	/**
	 * The app config key holding whether documents are public on arrival.
	 */
	public const PUBLISH_KEY = 'council_harvest_publish_on_arrival';

	/**
	 * The mapping a flow's map node uses, before the publish-on-arrival suffix.
	 */
	private const MAPPING_PREFIX = 'council-document-to-publication-';

	/**
	 * The object service id in the container.
	 */
	private const OBJECTS = 'OCA\OpenRegister\Service\ObjectService';

	/**
	 * The flow service id in the container.
	 */
	private const FLOWS = 'OCA\OpenRegister\Service\Flow\FlowService';

	/**
	 * Constructor.
	 *
	 * @param IAppManager        $appManager Tells whether integriq and OpenRegister are installed.
	 * @param ContainerInterface $container  Resolves OpenRegister's services by name.
	 * @param IAppConfig         $appConfig  Holds the set-up system and publish on arrival.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
	) {

	}//end __construct()

	/**
	 * Everything the admin section shows.
	 *
	 * @return array<string, mixed> The status.
	 *
	 * @spec openspec/changes/council-documents-from-notubiz-and-ibabs/specs/council-documents-harvest/spec.md#requirement-an-administrator-sets-up-the-council-documents-harvest-req-cdh-001
	 */
	public function status(): array {
		$kind = $this->setUpKind();
		$flow = null;
		if ($kind !== null) {
			$flow = $this->flowOf(kind: $kind);
		}

		return [
			'integriq' => $this->appManager->isEnabledForAnyone('integriq'),
			// decidiq can publish council papers into the same catalogue; the section warns about duplicates.
			'decidiq' => $this->appManager->isEnabledForAnyone('decidiq'),
			'sources' => $this->sourceChoices(),
			'source' => $this->templates()[$kind]['source'] ?? null,
			'publishOnArrival' => $this->publishesOnArrival(),
			'flow' => $this->flowSummary(flow: $flow),
			'lastRun' => $this->lastRun(flow: $flow),
		];
	}//end status()

	/**
	 * Write the synchronization and the fetch-file rule for one council source.
	 *
	 * @param string $sourceSlug The integriq source.
	 *
	 * @return array<string, mixed> What was written, and the status after it.
	 *
	 * @throws RuntimeException Naming the missing piece.
	 *
	 * @spec openspec/changes/council-documents-from-notubiz-and-ibabs/specs/council-documents-harvest/spec.md#requirement-an-administrator-sets-up-the-council-documents-harvest-req-cdh-001
	 */
	public function setUp(string $sourceSlug): array {
		$this->assertAvailable();
		$kind = $this->kindOfSource(sourceSlug: $sourceSlug);
		$template = $this->templates()[$kind];
		$this->assertSourceReady(template: $template);

		$written = [
			'synchronization' => $this->writeIntegriq(schema: 'synchronization', object: $template['synchronization']),
			'rule' => $this->writeIntegriq(schema: 'rule', object: $template['rule']),
		];
		$this->appConfig->setValueString('opencatalogi', self::KIND_KEY, $kind);

		return $written + ['status' => $this->status()];
	}//end setUp()

	/**
	 * Switch the harvest of the set-up source on or off.
	 *
	 * Switching on adopts the flow first: it arrives unowned and an unowned
	 * flow never dispatches. The flows of the other system are switched off,
	 * so only one source writes into the catalogue.
	 *
	 * @param bool $enabled Whether it should run.
	 *
	 * @return array<string, mixed> The status after the switch.
	 *
	 * @throws RuntimeException When the harvest is not set up or its flow is not imported.
	 *
	 * @spec openspec/changes/council-documents-from-notubiz-and-ibabs/specs/council-documents-harvest/spec.md#requirement-an-administrator-sets-up-the-council-documents-harvest-req-cdh-001
	 */
	public function setEnabled(bool $enabled): array {
		$flow = $this->setUpFlow();
		$flows = $this->container->get(self::FLOWS);
		if ($enabled === true) {
			$flow = $flows->adopt($flow);
			$this->switchOffOthers(kind: (string)$this->setUpKind());
		}

		$flows->save(['enabled' => $enabled], (string)$flow->getUuid());

		return $this->status();
	}//end setEnabled()

	/**
	 * Whether harvested documents are public on arrival: the flow's map step
	 * switches between the concept mapping and the published one.
	 *
	 * @param bool $publish True to keep the source's publication date.
	 *
	 * @return array<string, mixed> The status after the change.
	 *
	 * @throws RuntimeException When the harvest is not set up or its flow is not imported.
	 *
	 * @spec openspec/changes/council-documents-from-notubiz-and-ibabs/specs/council-documents-harvest/spec.md#requirement-each-public-council-document-becomes-a-publication-with-its-file-req-cdh-002
	 */
	public function setPublishOnArrival(bool $publish): array {
		$flow = $this->setUpFlow();
		$mapping = self::mappingFor(kind: (string)$this->setUpKind(), publish: $publish);
		$this->container->get(self::FLOWS)->save(
			['nodes' => self::withMapping(nodes: ($flow->getNodes() ?? []), mapping: $mapping)],
			(string)$flow->getUuid()
		);
		$this->appConfig->setValueBool('opencatalogi', self::PUBLISH_KEY, $publish);

		return $this->status();
	}//end setPublishOnArrival()

	/**
	 * Start a run now. The run is queued; the flow engine's worker advances it.
	 *
	 * @return array<string, mixed> The queued run.
	 *
	 * @throws RuntimeException When the harvest is not set up and switched on.
	 *
	 * @spec openspec/changes/council-documents-from-notubiz-and-ibabs/specs/council-documents-harvest/spec.md#requirement-an-administrator-sets-up-the-council-documents-harvest-req-cdh-001
	 */
	public function runNow(): array {
		$flow = $this->setUpFlow();
		if ($flow->getEnabled() !== true) {
			throw new RuntimeException('Switch the council documents harvest on before you run it.');
		}

		$run = $this->container->get(self::FLOWS)->run((string)$flow->getUuid());

		return (new FlowReport())->runSummary(run: $run);
	}//end runNow()

	/**
	 * The mapping a flow's map step uses for a system.
	 *
	 * @param string $kind    The council system.
	 * @param bool   $publish Whether documents are public on arrival.
	 *
	 * @return string The mapping slug.
	 *
	 * @spec openspec/changes/council-documents-from-notubiz-and-ibabs/specs/council-documents-harvest/spec.md#requirement-each-public-council-document-becomes-a-publication-with-its-file-req-cdh-002
	 */
	public static function mappingFor(string $kind, bool $publish): string {
		if ($publish === true) {
			return self::MAPPING_PREFIX . $kind . '-published';
		}

		return self::MAPPING_PREFIX . $kind;
	}//end mappingFor()

	/**
	 * The nodes with the map step pointed at a mapping.
	 *
	 * @param array<int, array<string, mixed>> $nodes   The flow's nodes.
	 * @param string                           $mapping The mapping slug.
	 *
	 * @return array<int, array<string, mixed>> The nodes.
	 */
	private static function withMapping(array $nodes, string $mapping): array {
		foreach ($nodes as $index => $node) {
			if (($node['type'] ?? '') === 'openregister.map') {
				$nodes[$index]['config']['mapping'] = $mapping;
			}
		}

		return $nodes;
	}//end withMapping()

	/**
	 * The templates per council system.
	 *
	 * @return array<string, array<string, mixed>> The templates, keyed by system.
	 *
	 * @throws RuntimeException When the template file is missing or broken.
	 */
	private function templates(): array {
		$path = $this->appManager->getAppPath('opencatalogi') . self::SOURCES_FILE;
		$decoded = json_decode((string)@file_get_contents($path), true);
		if (is_array($decoded['kinds'] ?? null) === false) {
			throw new RuntimeException('The council source file ' . self::SOURCES_FILE . ' is missing or not valid.');
		}

		return $decoded['kinds'];
	}//end templates()

	/**
	 * The system an integriq source belongs to.
	 *
	 * @param string $sourceSlug The integriq source.
	 *
	 * @return string The system.
	 *
	 * @throws RuntimeException When the source is no council system this harvest knows.
	 */
	private function kindOfSource(string $sourceSlug): string {
		foreach ($this->templates() as $kind => $template) {
			if ($template['source'] === $sourceSlug) {
				return (string)$kind;
			}
		}

		throw new RuntimeException('Choose a Notubiz or iBabs source: "' . $sourceSlug . '" is not one.');
	}//end kindOfSource()

	/**
	 * Refuse when the source is missing in integriq or lacks a setting the system needs.
	 *
	 * @param array<string, mixed> $template The system's template.
	 *
	 * @return void
	 *
	 * @throws RuntimeException With the message for the first missing setting.
	 *
	 * @spec openspec/changes/council-documents-from-notubiz-and-ibabs/specs/council-documents-harvest/spec.md#scenario-ibabs-without-a-key-is-refused
	 */
	private function assertSourceReady(array $template): void {
		$source = $this->integriqObject(schema: 'source', slug: (string)$template['source']);
		if ($source === null) {
			throw new RuntimeException('The source ' . $template['source'] . ' does not exist in integriq. Import it there first.');
		}

		$settings = (array)(($source->getObject() ?? [])['configuration'] ?? []);
		foreach ($template['required'] as $required) {
			if (trim((string)($settings[$required['key']] ?? '')) === '') {
				throw new RuntimeException((string)$required['missing']);
			}
		}
	}//end assertSourceReady()

	/**
	 * Refuse when integriq or OpenRegister is absent.
	 *
	 * @return void
	 *
	 * @throws RuntimeException Naming the missing app.
	 */
	private function assertAvailable(): void {
		if ($this->appManager->isEnabledForAnyone('integriq') === false) {
			throw new RuntimeException('The council documents harvest needs integriq. Install and enable integriq first.');
		}

		if ($this->appManager->isInstalled('openregister') === false) {
			throw new RuntimeException('The council documents harvest needs OpenRegister. Install and enable OpenRegister first.');
		}
	}//end assertAvailable()

	/**
	 * Write an integriq object by slug, over the stored one when it exists.
	 *
	 * @param string               $schema The integriq schema.
	 * @param array<string, mixed> $object The object.
	 *
	 * @return string 'created' or 'updated'.
	 */
	private function writeIntegriq(string $schema, array $object): string {
		$existing = $this->integriqObject(schema: $schema, slug: (string)$object['slug']);
		$this->container->get(self::OBJECTS)->saveObject(
			object: $object,
			options: new ObjectSaveOptions(target: new ObjectFindTarget(register: 'integriq', schema: $schema), uuid: $existing?->getUuid())
		);

		if ($existing === null) {
			return 'created';
		}

		return 'updated';
	}//end writeIntegriq()

	/**
	 * An integriq object by slug, or null.
	 *
	 * @param string $schema The integriq schema.
	 * @param string $slug   The object slug.
	 *
	 * @return object|null The object entity.
	 */
	private function integriqObject(string $schema, string $slug): ?object {
		try {
			$found = $this->container->get(self::OBJECTS)->find(
				id: $slug,
				options: new ObjectFindOptions(register: 'integriq', schema: $schema),
				_multitenancy: false
			);
		} catch (Throwable) {
			return null;
		}

		if (is_object($found) === false) {
			return null;
		}

		return $found;
	}//end integriqObject()

	/**
	 * The integriq sources the section offers: those of the known council systems that exist.
	 *
	 * @return array<int, array{slug: string, label: string, uuid: string}> The choices.
	 */
	private function sourceChoices(): array {
		if ($this->appManager->isEnabledForAnyone('integriq') === false || $this->appManager->isInstalled('openregister') === false) {
			return [];
		}

		$choices = [];
		foreach ($this->templates() as $template) {
			$source = $this->integriqObject(schema: 'source', slug: (string)$template['source']);
			if ($source !== null) {
				$choices[] = ['slug' => (string)$template['source'], 'label' => (string)$template['label'], 'uuid' => (string)$source->getUuid()];
			}
		}

		return $choices;
	}//end sourceChoices()

	/**
	 * The set-up system, or null before set-up.
	 *
	 * @return string|null The system.
	 */
	private function setUpKind(): ?string {
		$kind = $this->appConfig->getValueString('opencatalogi', self::KIND_KEY, '');
		if ($kind === '') {
			return null;
		}

		return $kind;
	}//end setUpKind()

	/**
	 * Whether documents are public on arrival.
	 *
	 * @return bool True when they keep the source's publication date.
	 */
	private function publishesOnArrival(): bool {
		return $this->appConfig->getValueBool('opencatalogi', self::PUBLISH_KEY, false);
	}//end publishesOnArrival()

	/**
	 * The flow of the set-up system.
	 *
	 * @return object The flow entity.
	 *
	 * @throws RuntimeException When the harvest is not set up or its flow is not imported.
	 */
	private function setUpFlow(): object {
		$this->assertAvailable();
		$kind = $this->setUpKind();
		if ($kind === null) {
			throw new RuntimeException('Set the council documents harvest up first: choose a source and press Set up.');
		}

		$flow = $this->flowOf(kind: $kind);
		if ($flow === null) {
			throw new RuntimeException('The council documents flow is not imported yet. Reimport the OpenCatalogi configuration first.');
		}

		return $flow;
	}//end setUpFlow()

	/**
	 * Switch off the flows of the systems other than one.
	 *
	 * @param string $kind The system that stays.
	 *
	 * @return void
	 */
	private function switchOffOthers(string $kind): void {
		foreach (array_keys($this->templates()) as $other) {
			$flow = $this->flowOf(kind: (string)$other);
			if ($other !== $kind && $flow !== null && $flow->getEnabled() === true) {
				$this->container->get(self::FLOWS)->save(['enabled' => false], (string)$flow->getUuid());
			}
		}
	}//end switchOffOthers()

	/**
	 * The shipped flow of a system, or null when it was not imported.
	 *
	 * @param string $kind The system.
	 *
	 * @return object|null The flow entity.
	 */
	private function flowOf(string $kind): ?object {
		$name = (string)($this->templates()[$kind]['flow'] ?? '');
		if ($name === '' || $this->appManager->isEnabledForAnyone('openregister') === false) {
			return null;
		}

		try {
			$flows = $this->container->get(self::FLOWS)->findAll(app: 'opencatalogi', limit: 500);
		} catch (Throwable) {
			return null;
		}

		$named = array_filter($flows, static fn (object $flow): bool => $flow->getName() === $name);

		return (array_values($named)[0] ?? null);
	}//end flowOf()

	/**
	 * What the section shows about a flow.
	 *
	 * @param object|null $flow The flow.
	 *
	 * @return array<string, mixed> The flow status.
	 */
	private function flowSummary(?object $flow): array {
		if ($flow === null) {
			return ['uuid' => null, 'imported' => false, 'enabled' => false, 'cron' => null];
		}

		return ['uuid' => (string)$flow->getUuid(), 'imported' => true, 'enabled' => ($flow->getEnabled() === true)]
			+ (new FlowReport())->schedule(nodes: ($flow->getNodes() ?? []));
	}//end flowSummary()

	/**
	 * The latest run of a flow, with its counts.
	 *
	 * @param object|null $flow The flow.
	 *
	 * @return array<string, mixed>|null The run summary, or null when it never ran.
	 */
	private function lastRun(?object $flow): ?array {
		if ($flow === null) {
			return null;
		}

		try {
			// A system read of the app's own harvest flow: no requester, so no per-user scoping.
			$runs = $this->container->get(FlowRunMapper::class)->findAllRuns(
				listing: new FlowRunListing(filter: new FlowRunFilter(flowId: (string)$flow->getUuid()), limit: 1),
				viewer: new FlowRunViewer(requesterUid: null, ownedFlowIds: [])
			);
		} catch (Throwable) {
			return null;
		}

		if ($runs === []) {
			return null;
		}

		return (new FlowReport())->runSummary(run: $runs[0]);
	}//end lastRun()
}//end class
