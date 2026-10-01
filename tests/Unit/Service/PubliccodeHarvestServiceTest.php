<?php

/**
 * Tests for PubliccodeHarvestService: inert without integriq, set-up is
 * idempotent by slug, switching on adopts the flow first, and a run is refused
 * until the harvest is set up and switched on.
 *
 * The OpenRegister collaborators are doubled with `onlyMethods`, so a method
 * the real class does not have cannot be invented here and pass. Entities are
 * the real OpenRegister classes.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/publiccode-github-harvest/specs/publiccode-github-harvest/spec.md#requirement-req-pgh-006-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Service;

use OCA\OpenCatalogi\Service\PubliccodeHarvestService;
use DateTime;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Flow\FlowService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenCatalogi\Service\PubliccodeHarvestService
 */
class PubliccodeHarvestServiceTest extends TestCase {

	/**
	 * Objects "stored" in integriq, by slug.
	 *
	 * @var array<string, ObjectEntity>
	 */
	private array $store = [];

	/**
	 * Skip where OpenRegister's real classes are not next to the app.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		foreach ([Flow::class, ObjectEntity::class, FlowService::class, ObjectService::class] as $class) {
			if (class_exists($class) === false) {
				$this->markTestSkipped('OpenRegister source is not next to this app: ' . $class . ' is missing.');
			}
		}
	}//end setUp()

	/**
	 * An app manager answering for the apps given as enabled.
	 *
	 * @param array<int, string> $enabled Enabled app ids.
	 *
	 * @return IAppManager
	 */
	private function apps(array $enabled): IAppManager {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForAnyone')->willReturnCallback(static fn (string $app): bool => in_array($app, $enabled, true));
		$apps->method('getAppPath')->willReturn(__DIR__ . '/../../..');
		$apps->method('isInstalled')->willReturnCallback(static fn (string $app): bool => in_array($app, $enabled, true));

		return $apps;
	}//end apps()

	/**
	 * An ObjectService double backed by $this->store.
	 *
	 * @return ObjectService
	 */
	private function objects(): ObjectService {
		$objects = $this->getMockBuilder(ObjectService::class)
			->disableOriginalConstructor()
			->onlyMethods(['find', 'saveObject'])
			->getMock();

		$objects->method('find')->willReturnCallback(
			fn (int|string $id): ?ObjectEntity => ($this->store[(string)$id] ?? null)
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ?array $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null): ObjectEntity {
				$entity = ($this->store[$object['slug']] ?? new ObjectEntity());
				if ($uuid !== null) {
					$this->assertSame($uuid, $entity->getUuid(), 'An update must name the stored object.');
				}

				$entity->setUuid($uuid ?? ('uuid-' . $object['slug']));
				$entity->setObject($object);
				$this->store[$object['slug']] = $entity;

				return $entity;
			}
		);

		return $objects;
	}//end objects()

	/**
	 * A container resolving the given services.
	 *
	 * @param array<string, object> $services Services by class name.
	 *
	 * @return ContainerInterface
	 */
	private function container(array $services): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($services): object {
				if (isset($services[$id]) === false) {
					throw new RuntimeException('Not in this test: ' . $id);
				}

				return $services[$id];
			}
		);

		return $container;
	}//end container()

	/**
	 * The shipped flow, as the flow store would hold it.
	 *
	 * @param bool $enabled Whether it is switched on.
	 *
	 * @return Flow
	 */
	private function flow(bool $enabled): Flow {
		$flow = new Flow();
		$flow->setUuid('flow-1');
		$flow->setName(PubliccodeHarvestService::FLOW_NAME);
		$flow->setEnabled($enabled);
		$flow->setNodes([['id' => 'nightly', 'type' => 'openregister.trigger-schedule', 'config' => ['cron' => '15 3 * * *', 'runAs' => 'admin']]]);

		return $flow;
	}//end flow()

	/**
	 * Without integriq the status says so and every action refuses.
	 *
	 * @return void
	 */
	public function testWithoutIntegriqNothingCanStart(): void {
		$service = new PubliccodeHarvestService(
			$this->apps(enabled: []),
			$this->container(services: []),
			$this->createMock(LoggerInterface::class)
		);

		$status = $service->status();
		$this->assertFalse($status['integriq']);
		$this->assertNull($status['source']);
		$this->assertSame(['expected' => 24, 'present' => 0], $status['shards']);
		$this->assertFalse($status['flow']['imported']);

		foreach (['setUp', 'runNow'] as $action) {
			try {
				$service->$action();
				$this->fail($action . ' must refuse without integriq.');
			} catch (RuntimeException $e) {
				$this->assertStringContainsString('integriq', $e->getMessage());
			}
		}

		$this->expectException(RuntimeException::class);
		$service->setEnabled(enabled: true);
	}//end testWithoutIntegriqNothingCanStart()

	/**
	 * Setting up twice updates the 24 shards in place.
	 *
	 * @return void
	 */
	public function testSettingUpTwiceUpdatesInPlace(): void {
		$service = new PubliccodeHarvestService(
			$this->apps(enabled: ['integriq', 'openregister']),
			$this->container(services: ['OCA\OpenRegister\Service\ObjectService' => $this->objects()]),
			$this->createMock(LoggerInterface::class)
		);

		$this->assertSame(['created' => 24, 'updated' => 0], $service->setUp());
		$this->assertSame(['created' => 0, 'updated' => 24], $service->setUp());
		$this->assertCount(24, $this->store);
		$this->assertSame('github-api', $this->store['opencatalogi-publiccode-github-01']->getObject()['sourceId']);
	}//end testSettingUpTwiceUpdatesInPlace()

	/**
	 * Switching on adopts the flow before enabling it; switching off does not adopt.
	 *
	 * @return void
	 */
	public function testSwitchingOnAdoptsTheFlowFirst(): void {
		$calls = [];
		$flows = $this->getMockBuilder(FlowService::class)
			->disableOriginalConstructor()
			->onlyMethods(['findAll', 'adopt', 'save'])
			->getMock();
		$flow = $this->flow(enabled: false);
		$flows->method('findAll')->willReturnCallback(static fn (): array => [$flow]);
		$flows->method('adopt')->willReturnCallback(
			static function (Flow $adopted) use (&$calls): Flow {
				$calls[] = 'adopt';
				$adopted->setOwner('admin');
				return $adopted;
			}
		);
		$flows->method('save')->willReturnCallback(
			static function (array $data, ?string $uuid) use (&$calls, $flow): Flow {
				$calls[] = 'save:' . $uuid . ':' . json_encode($data);
				$flow->setEnabled($data['enabled']);
				return $flow;
			}
		);

		$service = new PubliccodeHarvestService(
			$this->apps(enabled: ['integriq', 'openregister']),
			$this->container(services: ['OCA\OpenRegister\Service\Flow\FlowService' => $flows]),
			$this->createMock(LoggerInterface::class)
		);

		$status = $service->setEnabled(enabled: true);
		$this->assertSame(['adopt', 'save:flow-1:{"enabled":true}'], $calls);
		$this->assertTrue($status['enabled']);
		$this->assertSame('admin', $status['runAs']);

		$calls = [];
		$service->setEnabled(enabled: false);
		$this->assertSame(['save:flow-1:{"enabled":false}'], $calls);
	}//end testSwitchingOnAdoptsTheFlowFirst()

	/**
	 * A run is refused while the flow is off, and while shards are missing.
	 *
	 * @return void
	 */
	public function testARunNeedsTheFlowOnAndEveryShardPresent(): void {
		$flows = $this->getMockBuilder(FlowService::class)
			->disableOriginalConstructor()
			->onlyMethods(['findAll', 'run'])
			->getMock();
		$flow = $this->flow(enabled: false);
		$flows->method('findAll')->willReturnCallback(static fn (): array => [$flow]);
		$flows->expects($this->never())->method('run');

		$service = new PubliccodeHarvestService(
			$this->apps(enabled: ['integriq', 'openregister']),
			$this->container(
				services: [
					'OCA\OpenRegister\Service\Flow\FlowService' => $flows,
					'OCA\OpenRegister\Service\ObjectService' => $this->objects(),
				]
			),
			$this->createMock(LoggerInterface::class)
		);

		try {
			$service->runNow();
			$this->fail('A run must be refused while the flow is off.');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('Switch the harvest on', $e->getMessage());
		}

		$flow->setEnabled(true);
		$this->expectExceptionMessage('Set the harvest up');
		$service->runNow();
	}//end testARunNeedsTheFlowOnAndEveryShardPresent()
	/**
	 * A stored integriq object.
	 *
	 * @param string $slug The slug it is found by.
	 * @param array<string, mixed> $data Its data.
	 *
	 * @return void
	 */
	private function stored(string $slug, array $data): void {
		$entity = new ObjectEntity();
		$entity->setUuid('uuid-' . $slug);
		$entity->setObject($data);
		$this->store[$slug] = $entity;
	}//end stored()

	/**
	 * Every shard of the shipped shard file, stored.
	 *
	 * @return void
	 */
	private function storeAllShards(): void {
		$definitions = (array)json_decode(
			(string)file_get_contents(__DIR__ . '/../../..' . PubliccodeHarvestService::SHARDS_FILE),
			true
		);
		foreach ($definitions['shards'] as $shard) {
			$this->stored(slug: $shard['synchronization'], data: ['slug' => $shard['synchronization']]);
		}
	}//end storeAllShards()

	/**
	 * A FlowService double that lists the given flows.
	 *
	 * @param array<int, Flow> $flows The flows findAll returns.
	 * @param array<int, string> $methods Further methods to double.
	 *
	 * @return FlowService
	 */
	private function flows(array $flows, array $methods = []): FlowService {
		$service = $this->getMockBuilder(FlowService::class)
			->disableOriginalConstructor()
			->onlyMethods(array_merge(['findAll'], $methods))
			->getMock();
		$service->method('findAll')->willReturn($flows);

		return $service;
	}//end flows()

	/**
	 * A FlowRunMapper double returning the given runs.
	 *
	 * @param array<int, FlowRun> $runs The runs.
	 *
	 * @return FlowRunMapper
	 */
	private function runs(array $runs): FlowRunMapper {
		$mapper = $this->getMockBuilder(FlowRunMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['findAllRuns'])
			->getMock();
		$mapper->method('findAllRuns')->willReturnCallback(
			function (?string $flowId = null, ?string $status = null, int $limit = 50) use ($runs): array {
				$this->assertSame('flow-1', $flowId, 'The last run is read for the harvest flow only.');
				$this->assertSame(1, $limit);
				return $runs;
			}
		);

		return $mapper;
	}//end runs()

	/**
	 * A run paused on the rate limit.
	 *
	 * @return FlowRun
	 */
	private function suspendedRun(): FlowRun {
		$run = new FlowRun();
		$run->setUuid('run-1');
		$run->setStatus('suspended');
		$run->setTrigger('manual');
		$run->setCreated(new DateTime('2026-10-01T05:47:14+00:00'));
		$run->setResumeAt(new DateTime('2026-10-01T05:48:34+00:00'));

		return $run;
	}//end suspendedRun()

	/**
	 * The status shows the source, every shard, the flow and the last run.
	 *
	 * @return void
	 */
	public function testTheStatusReportsSourceShardsFlowAndLastRun(): void {
		$this->stored(slug: 'github-api', data: ['isEnabled' => true]);
		$this->storeAllShards();

		$service = new PubliccodeHarvestService(
			$this->apps(enabled: ['integriq', 'openregister']),
			$this->container(
				services: [
					'OCA\OpenRegister\Service\ObjectService' => $this->objects(),
					'OCA\OpenRegister\Service\Flow\FlowService' => $this->flows(flows: [$this->flow(enabled: true)]),
					'OCA\OpenRegister\Db\FlowRunMapper' => $this->runs(runs: [$this->suspendedRun()]),
				]
			),
			$this->createMock(LoggerInterface::class)
		);

		$status = $service->status();

		$this->assertTrue($status['integriq']);
		$this->assertSame(['slug' => 'github-api', 'exists' => true, 'enabled' => true, 'uuid' => 'uuid-github-api'], $status['source']);
		$this->assertSame(['expected' => 24, 'present' => 24], $status['shards']);
		$this->assertSame(
			['uuid' => 'flow-1', 'imported' => true, 'enabled' => true, 'owner' => null, 'runAs' => 'admin', 'cron' => '15 3 * * *'],
			$status['flow']
		);
		$this->assertSame('suspended', $status['lastRun']['status']);
		$this->assertSame('2026-10-01T05:47:14+00:00', $status['lastRun']['started']);
		$this->assertSame('2026-10-01T05:48:34+00:00', $status['lastRun']['resumeAt']);
		$this->assertNull($status['lastRun']['updated']);
	}//end testTheStatusReportsSourceShardsFlowAndLastRun()

	/**
	 * A missing source, a disabled one and a flow that never ran each read as such.
	 *
	 * @return void
	 */
	public function testAMissingSourceAndANeverRunFlowReadAsSuch(): void {
		$service = new PubliccodeHarvestService(
			$this->apps(enabled: ['integriq', 'openregister']),
			$this->container(
				services: [
					'OCA\OpenRegister\Service\ObjectService' => $this->objects(),
					'OCA\OpenRegister\Service\Flow\FlowService' => $this->flows(flows: [$this->flow(enabled: false)]),
					'OCA\OpenRegister\Db\FlowRunMapper' => $this->runs(runs: []),
				]
			),
			$this->createMock(LoggerInterface::class)
		);

		$status = $service->status();
		$this->assertSame(['slug' => 'github-api', 'exists' => false, 'enabled' => false, 'uuid' => null], $status['source']);
		$this->assertSame(0, $status['shards']['present']);
		$this->assertNull($status['lastRun']);

		$this->stored(slug: 'github-api', data: ['isEnabled' => false]);
		$this->assertFalse($service->status()['source']['enabled']);
	}//end testAMissingSourceAndANeverRunFlowReadAsSuch()

	/**
	 * A flow store that cannot be read reads as "not imported", and says so in the log.
	 *
	 * @return void
	 */
	public function testAnUnreadableFlowStoreReadsAsNotImported(): void {
		$flows = $this->getMockBuilder(FlowService::class)
			->disableOriginalConstructor()
			->onlyMethods(['findAll'])
			->getMock();
		$flows->method('findAll')->willThrowException(new RuntimeException('no organisation'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringContains('no organisation'));

		$service = new PubliccodeHarvestService(
			$this->apps(enabled: ['openregister']),
			$this->container(services: ['OCA\OpenRegister\Service\Flow\FlowService' => $flows]),
			$logger
		);

		$this->assertFalse($service->status()['flow']['imported']);
	}//end testAnUnreadableFlowStoreReadsAsNotImported()

	/**
	 * Run now queues a run of the harvest flow and reports it.
	 *
	 * @return void
	 */
	public function testRunNowQueuesTheHarvestFlow(): void {
		$this->storeAllShards();
		$queued = new FlowRun();
		$queued->setUuid('run-2');
		$queued->setStatus('queued');
		$queued->setTrigger('manual');
		$queued->setCreated(new DateTime('2026-10-01T06:00:00+00:00'));

		$flows = $this->flows(flows: [$this->flow(enabled: true)], methods: ['run']);
		$flows->expects($this->once())->method('run')->with('flow-1')->willReturn($queued);

		$service = new PubliccodeHarvestService(
			$this->apps(enabled: ['integriq', 'openregister']),
			$this->container(
				services: [
					'OCA\OpenRegister\Service\ObjectService' => $this->objects(),
					'OCA\OpenRegister\Service\Flow\FlowService' => $flows,
				]
			),
			$this->createMock(LoggerInterface::class)
		);

		$run = $service->runNow();
		$this->assertSame('run-2', $run['uuid']);
		$this->assertSame('queued', $run['status']);
		$this->assertSame('2026-10-01T06:00:00+00:00', $run['started']);
		$this->assertNull($run['resumeAt']);
	}//end testRunNowQueuesTheHarvestFlow()

	/**
	 * With integriq but without OpenRegister nothing reaches for OpenRegister's services.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterEveryActionRefuses(): void {
		$service = new PubliccodeHarvestService(
			$this->apps(enabled: ['integriq']),
			$this->container(services: []),
			$this->createMock(LoggerInterface::class)
		);

		foreach (['setUp', 'runNow'] as $action) {
			try {
				$service->$action();
				$this->fail($action . ' must refuse without OpenRegister.');
			} catch (RuntimeException $e) {
				$this->assertStringContainsString('needs OpenRegister', $e->getMessage());
			}
		}
	}//end testWithoutOpenRegisterEveryActionRefuses()

	/**
	 * Switching on a flow that was never imported says what to do.
	 *
	 * @return void
	 */
	public function testSwitchingOnAFlowThatIsNotImportedSaysToReimport(): void {
		$service = new PubliccodeHarvestService(
			$this->apps(enabled: ['integriq', 'openregister']),
			$this->container(services: ['OCA\OpenRegister\Service\Flow\FlowService' => $this->flows(flows: [])]),
			$this->createMock(LoggerInterface::class)
		);

		$this->expectExceptionMessage('Reimport the OpenCatalogi configuration');
		$service->setEnabled(enabled: true);
	}//end testSwitchingOnAFlowThatIsNotImportedSaysToReimport()

	/**
	 * A missing or broken shard file is reported, never read as zero shards.
	 *
	 * @return void
	 */
	public function testAMissingOrBrokenShardFileIsReported(): void {
		$root = sys_get_temp_dir() . '/oc-shards-' . bin2hex(random_bytes(4));
		mkdir($root . '/lib/Settings', 0777, true);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppPath')->willReturn($root);
		$service = new PubliccodeHarvestService($apps, $this->container(services: []), $this->createMock(LoggerInterface::class));

		try {
			$service->shardDefinitions();
			$this->fail('A missing shard file must be reported.');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('is missing', $e->getMessage());
		}

		file_put_contents($root . PubliccodeHarvestService::SHARDS_FILE, '{"source": "github-api"}');
		try {
			$service->shardDefinitions();
			$this->fail('A shard file without shards must be reported.');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('is not valid', $e->getMessage());
		} finally {
			unlink($root . PubliccodeHarvestService::SHARDS_FILE);
			rmdir($root . '/lib/Settings');
			rmdir($root . '/lib');
			rmdir($root);
		}
	}//end testAMissingOrBrokenShardFileIsReported()
}//end class
