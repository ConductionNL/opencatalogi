<?php

/**
 * Tests for CouncilHarvestService: set-up, the refusals, the switches and a run.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/council-documents-from-notubiz-and-ibabs/specs/council-documents-harvest/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Service;

use OCA\OpenCatalogi\Service\CouncilHarvestService;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\Flow\FlowService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenCatalogi\Service\CouncilHarvestService
 * @uses \OCA\OpenCatalogi\Service\FlowReport
 */
class CouncilHarvestServiceTest extends TestCase {

	/**
	 * The integriq objects by slug.
	 *
	 * @var array<string, ObjectEntity>
	 */
	private array $store = [];

	/**
	 * Each integriq write: schema, slug and the uuid it updated.
	 *
	 * @var array<int, array{schema: string, slug: string, uuid: string|null}>
	 */
	private array $writes = [];

	/**
	 * The app config values.
	 *
	 * @var array<string, mixed>
	 */
	private array $config = [];

	/**
	 * The shipped flows by name.
	 *
	 * @var array<string, Flow>
	 */
	private array $flows = [];

	/**
	 * The flow service.
	 *
	 * @var FlowService&MockObject
	 */
	private FlowService $flowService;

	/**
	 * Ship both flows switched off.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		foreach (['Notubiz', 'iBabs'] as $index => $label) {
			$flow = new Flow();
			$flow->setUuid('flow-' . strtolower($label));
			$flow->setName('Council documents harvest (' . $label . ')');
			$flow->setEnabled(false);
			$flow->setNodes([
				['id' => 'nightly', 'type' => 'openregister.trigger-schedule', 'config' => ['cron' => '30 2 * * *', 'runAs' => 'admin']],
				['id' => 'map', 'type' => 'openregister.map', 'config' => ['mapping' => 'council-document-to-publication-' . strtolower($label)]],
			]);
			$this->flows[$flow->getName()] = $flow;
		}

		$this->flowService = $this->createMock(FlowService::class);
		$this->flowService->method('findAll')->willReturnCallback(fn (): array => array_values($this->flows));
		$this->flowService->method('adopt')->willReturnArgument(0);
		$this->flowService->method('save')->willReturnCallback(
			function (array $data, string $uuid): Flow {
				foreach ($this->flows as $flow) {
					if ($flow->getUuid() === $uuid) {
						$flow->setEnabled($data['enabled'] ?? $flow->getEnabled());
						$flow->setNodes($data['nodes'] ?? $flow->getNodes());
						return $flow;
					}
				}

				$this->fail('No flow ' . $uuid);
			}
		);
	}//end setUp()

	/**
	 * Put an integriq source in the store.
	 *
	 * @param string $slug The source slug.
	 * @param array<string, string> $configuration Its settings.
	 *
	 * @return void
	 */
	private function source(string $slug, array $configuration): void {
		$entity = new ObjectEntity();
		$entity->setUuid('uuid-' . $slug);
		$entity->setObject(['slug' => $slug, 'configuration' => $configuration]);
		$this->store[$slug] = $entity;
	}//end source()

	/**
	 * The service with the given apps enabled.
	 *
	 * @param array<int, string> $apps The enabled apps.
	 *
	 * @return CouncilHarvestService
	 */
	private function service(array $apps = ['integriq', 'openregister']): CouncilHarvestService {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForAnyone')->willReturnCallback(static fn (string $app): bool => in_array($app, $apps, true));
		$appManager->method('isInstalled')->willReturnCallback(static fn (string $app): bool => in_array($app, $apps, true));
		$appManager->method('getAppPath')->willReturn(__DIR__ . '/../../..');

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => (string)($this->config[$key] ?? $default));
		$appConfig->method('getValueBool')->willReturnCallback(fn (string $app, string $key, bool $default = false): bool => (bool)($this->config[$key] ?? $default));
		$appConfig->method('setValueString')->willReturnCallback(fn (string $app, string $key, string $value): bool => (bool)($this->config[$key] = $value));
		$appConfig->method('setValueBool')->willReturnCallback(function (string $app, string $key, bool $value): bool {
			$this->config[$key] = $value;
			return true;
		});

		$runs = $this->createMock(FlowRunMapper::class);
		$runs->method('findAllRuns')->willReturn([]);
		$services = [
			'OCA\OpenRegister\Service\ObjectService' => $this->objects(),
			'OCA\OpenRegister\Service\Flow\FlowService' => $this->flowService,
			FlowRunMapper::class => $runs,
		];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static fn (string $id): object => $services[$id] ?? throw new RuntimeException('Not in this test: ' . $id));

		return new CouncilHarvestService(appManager: $appManager, container: $container, appConfig: $appConfig);
	}//end service()

	/**
	 * An ObjectService over the in-memory store.
	 *
	 * @return ObjectService
	 */
	private function objects(): ObjectService {
		$objects = $this->getMockBuilder(ObjectService::class)->disableOriginalConstructor()->onlyMethods(['find', 'saveObject'])->getMock();
		$objects->method('find')->willReturnCallback(fn (int|string $id): ?ObjectEntity => $this->store[(string)$id] ?? null);
		$objects->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ObjectSaveOptions $options): ObjectEntity {
				$this->writes[] = ['schema' => (string)$options->target->schema, 'slug' => (string)$object['slug'], 'uuid' => $options->uuid];
				$entity = new ObjectEntity();
				$entity->setUuid($options->uuid ?? ('uuid-' . $object['slug']));
				$entity->setObject($object);
				$this->store[(string)$object['slug']] = $entity;
				return $entity;
			}
		);

		return $objects;
	}//end objects()

	/**
	 * Set-up for a filled-in Notubiz source writes its synchronization and its file rule (REQ-CDH-001).
	 *
	 * @return void
	 */
	public function testSetUpWritesTheSynchronizationAndRuleForNotubiz(): void {
		$this->source(slug: 'notubiz-ris-v1', configuration: ['organisation_id' => '686']);

		$result = $this->service()->setUp(sourceSlug: 'notubiz-ris-v1');

		$this->assertSame(['created', 'created'], [$result['synchronization'], $result['rule']]);
		$this->assertSame(
			[
				['schema' => 'synchronization', 'slug' => 'opencatalogi-council-documents-notubiz', 'uuid' => null],
				['schema' => 'rule', 'slug' => 'opencatalogi-council-document-file-notubiz', 'uuid' => null],
			],
			$this->writes
		);
		$this->assertSame('notubiz-ris-v1', $this->store['opencatalogi-council-documents-notubiz']->getObject()['sourceId']);
		$this->assertSame('notubiz', $this->config[CouncilHarvestService::KIND_KEY]);
		$this->assertSame('notubiz-ris-v1', $result['status']['source']);
		$this->assertSame('flow-notubiz', $result['status']['flow']['uuid']);
	}//end testSetUpWritesTheSynchronizationAndRuleForNotubiz()

	/**
	 * Setting up twice updates the stored objects instead of adding new ones.
	 *
	 * @return void
	 */
	public function testSettingUpTwiceUpdatesInPlace(): void {
		$this->source(slug: 'notubiz-ris-v1', configuration: ['organisation_id' => '686']);
		$service = $this->service();
		$service->setUp(sourceSlug: 'notubiz-ris-v1');

		$again = $service->setUp(sourceSlug: 'notubiz-ris-v1');

		$this->assertSame(['updated', 'updated'], [$again['synchronization'], $again['rule']]);
		$this->assertSame('uuid-opencatalogi-council-documents-notubiz', $this->writes[2]['uuid']);
	}//end testSettingUpTwiceUpdatesInPlace()

	/**
	 * An iBabs source without its key is refused with the message the section shows (REQ-CDH-001).
	 *
	 * @return void
	 */
	public function testSetUpRefusesAnIbabsSourceWithoutAKey(): void {
		$this->source(slug: 'ibabs-ris-v1', configuration: ['headers.Authorization' => '', 'organisatieId' => 'zuiddrecht']);

		try {
			$this->service()->setUp(sourceSlug: 'ibabs-ris-v1');
			$this->fail('Set-up must refuse an iBabs source without a key.');
		} catch (RuntimeException $e) {
			$this->assertSame('Fill in the iBabs key in integriq first.', $e->getMessage());
		}

		$this->assertSame([], $this->writes);
		$this->assertArrayNotHasKey(CouncilHarvestService::KIND_KEY, $this->config);
	}//end testSetUpRefusesAnIbabsSourceWithoutAKey()

	/**
	 * A Notubiz source without its organisation is refused, naming the setting.
	 *
	 * @return void
	 */
	public function testSetUpRefusesANotubizSourceWithoutAnOrganisation(): void {
		$this->source(slug: 'notubiz-ris-v1', configuration: ['organisation_id' => ' ']);

		$this->expectExceptionMessage('Fill in the Notubiz organisation id in integriq first.');
		$this->service()->setUp(sourceSlug: 'notubiz-ris-v1');
	}//end testSetUpRefusesANotubizSourceWithoutAnOrganisation()

	/**
	 * Without integriq nothing is written and the refusal names integriq (REQ-CDH-001).
	 *
	 * @return void
	 */
	public function testSetUpRefusesWithoutIntegriq(): void {
		$this->source(slug: 'notubiz-ris-v1', configuration: ['organisation_id' => '686']);

		try {
			$this->service(apps: ['openregister'])->setUp(sourceSlug: 'notubiz-ris-v1');
			$this->fail('Set-up must refuse without integriq.');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('needs integriq', $e->getMessage());
		}

		$this->assertSame([], $this->writes);
	}//end testSetUpRefusesWithoutIntegriq()

	/**
	 * A source missing in integriq, or one that is no council system, is refused.
	 *
	 * @return void
	 */
	public function testSetUpRefusesAMissingOrUnknownSource(): void {
		try {
			$this->service()->setUp(sourceSlug: 'notubiz-ris-v1');
			$this->fail('A missing source must be refused.');
		} catch (RuntimeException $e) {
			$this->assertSame('The source notubiz-ris-v1 does not exist in integriq. Import it there first.', $e->getMessage());
		}

		$this->expectExceptionMessage('Choose a Notubiz or iBabs source: "github-api" is not one.');
		$this->service()->setUp(sourceSlug: 'github-api');
	}//end testSetUpRefusesAMissingOrUnknownSource()

	/**
	 * Switching on adopts and enables the set-up system's flow and switches the other one off.
	 *
	 * @return void
	 */
	public function testSwitchingOnEnablesOnlyTheSetUpSystem(): void {
		$this->flows['Council documents harvest (iBabs)']->setEnabled(true);
		$this->config[CouncilHarvestService::KIND_KEY] = 'notubiz';
		$this->flowService->expects($this->once())->method('adopt');

		$status = $this->service()->setEnabled(enabled: true);

		$this->assertTrue($this->flows['Council documents harvest (Notubiz)']->getEnabled());
		$this->assertFalse($this->flows['Council documents harvest (iBabs)']->getEnabled());
		$this->assertTrue($status['flow']['enabled']);
		$this->assertSame('30 2 * * *', $status['flow']['cron']);
	}//end testSwitchingOnEnablesOnlyTheSetUpSystem()

	/**
	 * Before set-up the switches and Run now refuse and say what to do.
	 *
	 * @return void
	 */
	public function testBeforeSetUpEveryActionSaysToSetUpFirst(): void {
		$service = $this->service();
		foreach ([fn () => $service->setEnabled(enabled: true), fn () => $service->runNow(), fn () => $service->setPublishOnArrival(publish: true)] as $action) {
			try {
				$action();
				$this->fail('An action before set-up must refuse.');
			} catch (RuntimeException $e) {
				$this->assertSame('Set the council documents harvest up first: choose a source and press Set up.', $e->getMessage());
			}
		}
	}//end testBeforeSetUpEveryActionSaysToSetUpFirst()

	/**
	 * Publish on arrival points the map step at the published mapping, and back (REQ-CDH-002).
	 *
	 * @return void
	 */
	public function testPublishOnArrivalSwitchesTheMapping(): void {
		$this->config[CouncilHarvestService::KIND_KEY] = 'ibabs';
		$service = $this->service();

		$status = $service->setPublishOnArrival(publish: true);
		$this->assertTrue($status['publishOnArrival']);
		$this->assertSame('council-document-to-publication-ibabs-published', $this->flows['Council documents harvest (iBabs)']->getNodes()[1]['config']['mapping']);

		$service->setPublishOnArrival(publish: false);
		$this->assertSame('council-document-to-publication-ibabs', $this->flows['Council documents harvest (iBabs)']->getNodes()[1]['config']['mapping']);
		$this->assertSame('council-document-to-publication-notubiz', $this->flows['Council documents harvest (Notubiz)']->getNodes()[1]['config']['mapping']);
	}//end testPublishOnArrivalSwitchesTheMapping()

	/**
	 * Run now refuses a switched-off harvest and queues a switched-on one.
	 *
	 * @return void
	 */
	public function testRunNowNeedsTheFlowSwitchedOn(): void {
		$this->config[CouncilHarvestService::KIND_KEY] = 'notubiz';
		$run = new FlowRun();
		$run->setUuid('run-1');
		$run->setStatus('queued');
		$this->flowService->expects($this->once())->method('run')->with('flow-notubiz')->willReturn($run);
		$service = $this->service();

		try {
			$service->runNow();
			$this->fail('A switched-off harvest must not run.');
		} catch (RuntimeException $e) {
			$this->assertSame('Switch the council documents harvest on before you run it.', $e->getMessage());
		}

		$this->flows['Council documents harvest (Notubiz)']->setEnabled(true);
		$this->assertSame('run-1', $service->runNow()['uuid']);
	}//end testRunNowNeedsTheFlowSwitchedOn()

	/**
	 * The status lists the council sources integriq holds, and nothing is set up yet.
	 *
	 * @return void
	 */
	public function testTheStatusListsTheCouncilSourcesIntegriqHolds(): void {
		$this->source(slug: 'ibabs-ris-v1', configuration: []);

		$status = $this->service()->status();

		$this->assertTrue($status['integriq']);
		$this->assertSame([['slug' => 'ibabs-ris-v1', 'label' => 'iBabs', 'uuid' => 'uuid-ibabs-ris-v1']], $status['sources']);
		$this->assertNull($status['source']);
		$this->assertFalse($status['publishOnArrival']);
		$this->assertSame(['uuid' => null, 'imported' => false, 'enabled' => false, 'cron' => null], $status['flow']);
		$this->assertNull($status['lastRun']);
		$this->assertSame([], $this->service(apps: ['openregister'])->status()['sources']);
	}//end testTheStatusListsTheCouncilSourcesIntegriqHolds()
}//end class
