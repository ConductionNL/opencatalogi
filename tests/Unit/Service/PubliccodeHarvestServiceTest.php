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
 * The developer.overheid.nl harvest runs through the same service under its
 * own key; its tests below also pin that the GitHub status keeps its shape and
 * that no status reads or returns a credential.
 *
 * @spec openspec/changes/publiccode-github-harvest/specs/publiccode-github-harvest/spec.md#requirement-req-pgh-006-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi
 * @spec openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-008-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Service;

use OCA\OpenCatalogi\Exception\HarvestNotReadyException;
use OCA\OpenCatalogi\Service\PubliccodeHarvestService;
use DateTime;
use InvalidArgumentException;
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
	 * Objects "stored" in integriq by schema and slug, for the
	 * developer.overheid.nl tests, where a source and a credential share a name.
	 *
	 * @var array<string, ObjectEntity>
	 */
	private array $scoped = [];

	/**
	 * Every (schema, id) the scoped double was asked for.
	 *
	 * @var array<int, string>
	 */
	private array $lookups = [];

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
	 * The status counts the steps of the last run that failed and were carried
	 * past, so a finished run that lost a shard's page does not read as clean.
	 *
	 * @return void
	 */
	public function testTheStatusCountsTheFailedStepsOfTheLastRun(): void {
		$this->stored(slug: 'github-api', data: ['isEnabled' => true]);
		$run = new FlowRun();
		$run->setUuid('run-2');
		$run->setStatus('stopped');
		$run->setTrigger('manual');
		$run->setCreated(new DateTime('2026-10-02T09:00:00+00:00'));
		$run->setLog(
			[
				['transition' => 'shard-01', 'status' => 'completed'],
				['transition' => 'write-07', 'status' => 'failed', 'error' => 'releaseDate is not a date'],
				['transition' => 'write-12', 'status' => 'failed', 'error' => 'url is not a uri'],
				['transition' => 'end', 'status' => 'completed'],
			]
		);

		$service = new PubliccodeHarvestService(
			$this->apps(enabled: ['integriq', 'openregister']),
			$this->container(
				services: [
					'OCA\OpenRegister\Service\ObjectService' => $this->objects(),
					'OCA\OpenRegister\Service\Flow\FlowService' => $this->flows(flows: [$this->flow(enabled: true)]),
					'OCA\OpenRegister\Db\FlowRunMapper' => $this->runs(runs: [$run]),
				]
			),
			$this->createMock(LoggerInterface::class)
		);

		$status = $service->status();
		$this->assertSame(2, $status['lastRun']['failedSteps']);
		$this->assertSame('stopped', $status['lastRun']['status']);
	}//end testTheStatusCountsTheFailedStepsOfTheLastRun()

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

	/**
	 * An ObjectService double keyed by schema and slug, recording every lookup.
	 *
	 * @return ObjectService
	 */
	private function scopedObjects(): ObjectService {
		$objects = $this->getMockBuilder(ObjectService::class)
			->disableOriginalConstructor()
			->onlyMethods(['find', 'saveObject'])
			->getMock();

		$objects->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, mixed $register = null, mixed $schema = null): ?ObjectEntity {
				$this->lookups[] = $schema . '/' . $id;
				return ($this->scoped[$schema . '/' . $id] ?? null);
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ?array $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null): ObjectEntity {
				$this->assertSame('integriq', $register);
				$key = $schema . '/' . $object['slug'];
				$entity = ($this->scoped[$key] ?? new ObjectEntity());
				if ($uuid !== null) {
					$this->assertSame($uuid, $entity->getUuid(), 'An update must name the stored object.');
				}

				$entity->setUuid($uuid ?? ('uuid-' . $object['slug']));
				$entity->setObject($object);
				$this->scoped[$key] = $entity;

				return $entity;
			}
		);

		return $objects;
	}//end scopedObjects()

	/**
	 * Store one object in the scoped double.
	 *
	 * @param string $schema The integriq schema.
	 * @param string $slug The slug.
	 * @param array<string, mixed> $data Its data.
	 *
	 * @return void
	 */
	private function storedIn(string $schema, string $slug, array $data): void {
		$entity = new ObjectEntity();
		$entity->setUuid('uuid-' . $slug);
		$entity->setObject($data);
		$this->scoped[$schema . '/' . $slug] = $entity;
	}//end storedIn()

	/**
	 * The shipped developer.overheid.nl flow, as the flow store would hold it.
	 *
	 * @param bool $enabled Whether it is switched on.
	 *
	 * @return Flow
	 */
	private function developerOverheidFlow(bool $enabled): Flow {
		$flow = new Flow();
		$flow->setUuid('flow-2');
		$flow->setName(PubliccodeHarvestService::DEVELOPER_OVERHEID_FLOW_NAME);
		$flow->setEnabled($enabled);
		$flow->setNodes([['id' => 'nightly', 'type' => 'openregister.trigger-schedule', 'config' => ['cron' => '15 4 * * *', 'runAs' => 'admin']]]);

		return $flow;
	}//end developerOverheidFlow()

	/**
	 * Without integriq the developer.overheid.nl status says so and every action
	 * refuses with a slug the controller can answer.
	 *
	 * @spec openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-008-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi
	 *
	 * @return void
	 */
	public function testWithoutIntegriqTheDeveloperOverheidHarvestCannotStart(): void {
		$service = new PubliccodeHarvestService(
			$this->apps(enabled: []),
			$this->container(services: []),
			$this->createMock(LoggerInterface::class)
		);

		$status = $service->status(harvest: 'developer-overheid');
		$this->assertSame(['integriq', 'source', 'sources', 'synchronizations', 'flow', 'lastRun'], array_keys($status));
		$this->assertFalse($status['integriq']);
		$this->assertNull($status['source']);
		$this->assertNull($status['sources']);
		$this->assertSame(['expected' => 1, 'present' => 0], $status['synchronizations']);
		$this->assertFalse($status['flow']['imported']);

		foreach (['setUp', 'runNow'] as $action) {
			try {
				$service->$action(harvest: 'developer-overheid');
				$this->fail($action . ' must refuse without integriq.');
			} catch (HarvestNotReadyException $e) {
				$this->assertSame('needs-integriq', $e->getError());
				$this->assertStringContainsString('developer.overheid.nl harvest needs integriq', $e->getMessage());
			}
		}

		try {
			$service->setEnabled(enabled: true, harvest: 'developer-overheid');
			$this->fail('Switching on must refuse without integriq.');
		} catch (HarvestNotReadyException $e) {
			$this->assertSame('needs-integriq', $e->getError());
		}

		$service = new PubliccodeHarvestService(
			$this->apps(enabled: ['integriq']),
			$this->container(services: []),
			$this->createMock(LoggerInterface::class)
		);
		$this->expectExceptionObject(new HarvestNotReadyException(message: 'The developer.overheid.nl harvest needs OpenRegister. Install and enable OpenRegister first.', error: 'needs-openregister'));
		$service->setUp(harvest: 'developer-overheid');
	}//end testWithoutIntegriqTheDeveloperOverheidHarvestCannotStart()

	/**
	 * Setting the developer.overheid.nl harvest up twice writes its one
	 * synchronization once and then updates it in place.
	 *
	 * @spec openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-002-the-register-is-read-page-by-page-through-one-synchronization
	 *
	 * @return void
	 */
	public function testSettingTheDeveloperOverheidHarvestUpTwiceUpdatesInPlace(): void {
		$service = new PubliccodeHarvestService(
			$this->apps(enabled: ['integriq', 'openregister']),
			$this->container(services: ['OCA\OpenRegister\Service\ObjectService' => $this->scopedObjects()]),
			$this->createMock(LoggerInterface::class)
		);

		$this->assertSame(['created' => 1, 'updated' => 0], $service->setUp(harvest: 'developer-overheid'));
		$this->assertSame(['created' => 0, 'updated' => 1], $service->setUp(harvest: 'developer-overheid'));
		$this->assertSame(['synchronization/opencatalogi-publiccode-developer-overheid'], array_keys($this->scoped));

		$synchronization = $this->scoped['synchronization/opencatalogi-publiccode-developer-overheid']->getObject();
		$this->assertSame('developer-overheid-oss', $synchronization['sourceId']);
		$this->assertSame('/repositories', $synchronization['sourceConfig']['endpoint']);
		$this->assertSame(['publiccode' => 'true', 'perPage' => 100], $synchronization['sourceConfig']['query']);
		$this->assertSame('_root', $synchronization['sourceConfig']['resultsPosition']);
		$this->assertSame(10, $synchronization['sourceConfig']['maxPages']);
		$this->assertStringNotContainsString('apikey', strtolower((string)json_encode($synchronization)));
	}//end testSettingTheDeveloperOverheidHarvestUpTwiceUpdatesInPlace()

	/**
	 * The developer.overheid.nl status reports its source the way the GitHub
	 * status reports github-api, plus the raw-file sources, and never reads or
	 * returns a credential.
	 *
	 * @spec openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-009-the-api-key-never-touches-opencatalogi
	 *
	 * @return void
	 */
	public function testTheDeveloperOverheidStatusReportsTheSourceAndNoCredential(): void {
		$this->storedIn(
			schema: 'source',
			slug: 'developer-overheid-oss',
			data: ['isEnabled' => false, 'configuration' => ['headers' => ['X-Api-Key' => 'YOUR_API_KEY_HERE']]]
		);
		$this->storedIn(schema: 'credential', slug: 'developer-overheid-oss', data: ['provider' => 'generic-apikey', 'secret' => 'YOUR_API_KEY_HERE']);
		$this->storedIn(schema: 'source', slug: 'github-raw', data: ['isEnabled' => true]);
		$this->storedIn(schema: 'synchronization', slug: 'opencatalogi-publiccode-developer-overheid', data: []);
		$run = new FlowRun();
		$run->setUuid('run-3');
		$run->setStatus('completed');
		$run->setTrigger('manual');
		$run->setLog([['transition' => 'write-gitlab', 'status' => 'failed']]);
		$runs = $this->getMockBuilder(FlowRunMapper::class)->disableOriginalConstructor()->onlyMethods(['findAllRuns'])->getMock();
		$runs->method('findAllRuns')->willReturnCallback(
			function (?string $flowId = null) use ($run): array {
				$this->assertSame('flow-2', $flowId, 'The last run is read for the developer.overheid.nl flow.');
				return [$run];
			}
		);

		$service = new PubliccodeHarvestService(
			$this->apps(enabled: ['integriq', 'openregister']),
			$this->container(
				services: [
					'OCA\OpenRegister\Service\ObjectService' => $this->scopedObjects(),
					'OCA\OpenRegister\Service\Flow\FlowService' => $this->flows(flows: [$this->flow(enabled: true), $this->developerOverheidFlow(enabled: false)]),
					'OCA\OpenRegister\Db\FlowRunMapper' => $runs,
				]
			),
			$this->createMock(LoggerInterface::class)
		);

		$status = $service->status(harvest: 'developer-overheid');

		$this->assertSame(['integriq', 'source', 'sources', 'synchronizations', 'flow', 'lastRun'], array_keys($status));
		$this->assertSame(['slug' => 'developer-overheid-oss', 'exists' => true, 'enabled' => false, 'uuid' => 'uuid-developer-overheid-oss'], $status['source']);
		$this->assertSame(
			['github-raw' => ['exists' => true, 'enabled' => true], 'gitlab-raw' => ['exists' => false, 'enabled' => false]],
			$status['sources']
		);
		$this->assertSame(['expected' => 1, 'present' => 1], $status['synchronizations']);
		$this->assertSame(
			['uuid' => 'flow-2', 'imported' => true, 'enabled' => false, 'owner' => null, 'runAs' => 'admin', 'cron' => '15 4 * * *'],
			$status['flow']
		);
		$this->assertSame(1, $status['lastRun']['failedSteps']);

		$this->assertStringNotContainsString('YOUR_API_KEY_HERE', (string)json_encode($status));
		$this->assertStringNotContainsString('credential', strtolower((string)json_encode(array_keys($status))));
		foreach ($this->lookups as $lookup) {
			$this->assertStringStartsNotWith('credential/', $lookup, 'The status must not look the credential up.');
		}
	}//end testTheDeveloperOverheidStatusReportsTheSourceAndNoCredential()

	/**
	 * The GitHub status keeps the shape it had before the developer.overheid.nl
	 * harvest arrived, as the default and when named.
	 *
	 * @spec openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-008-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi
	 *
	 * @return void
	 */
	public function testTheGithubStatusKeepsItsShape(): void {
		$this->stored(slug: 'github-api', data: ['isEnabled' => true]);
		$service = new PubliccodeHarvestService(
			$this->apps(enabled: ['integriq', 'openregister']),
			$this->container(
				services: [
					'OCA\OpenRegister\Service\ObjectService' => $this->objects(),
					'OCA\OpenRegister\Service\Flow\FlowService' => $this->flows(flows: [$this->developerOverheidFlow(enabled: true), $this->flow(enabled: false)]),
					'OCA\OpenRegister\Db\FlowRunMapper' => $this->runs(runs: []),
				]
			),
			$this->createMock(LoggerInterface::class)
		);

		$status = $service->status();
		$this->assertSame(['integriq', 'source', 'shards', 'flow', 'lastRun'], array_keys($status));
		$this->assertSame(['slug', 'exists', 'enabled', 'uuid'], array_keys($status['source']));
		$this->assertSame('flow-1', $status['flow']['uuid'], 'The GitHub status reads the GitHub flow, not the other one.');
		$this->assertFalse($status['flow']['enabled']);
		$this->assertSame($status, $service->status(harvest: 'github'));
	}//end testTheGithubStatusKeepsItsShape()

	/**
	 * Switching the developer.overheid.nl harvest on adopts and enables its own
	 * flow and leaves the GitHub flow alone.
	 *
	 * @spec openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-008-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi
	 *
	 * @return void
	 */
	public function testSwitchingTheDeveloperOverheidHarvestTouchesOnlyItsFlow(): void {
		$calls = [];
		$flow = $this->developerOverheidFlow(enabled: false);
		$flows = $this->flows(flows: [$this->flow(enabled: false), $flow], methods: ['adopt', 'save']);
		$flows->method('adopt')->willReturnCallback(
			static function (Flow $adopted) use (&$calls): Flow {
				$calls[] = 'adopt:' . $adopted->getUuid();
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

		$this->assertTrue($service->setEnabled(enabled: true, harvest: 'developer-overheid')['enabled']);
		$this->assertSame(['adopt:flow-2', 'save:flow-2:{"enabled":true}'], $calls);
	}//end testSwitchingTheDeveloperOverheidHarvestTouchesOnlyItsFlow()

	/**
	 * A developer.overheid.nl run is refused while the flow is off or not set
	 * up, with the slug the controller answers, and queued once both hold.
	 *
	 * @spec openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-008-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi
	 *
	 * @return void
	 */
	public function testADeveloperOverheidRunNeedsTheFlowOnAndTheSynchronization(): void {
		$flow = $this->developerOverheidFlow(enabled: false);
		$queued = new FlowRun();
		$queued->setUuid('run-4');
		$queued->setStatus('queued');
		$queued->setTrigger('manual');
		$flows = $this->flows(flows: [$this->flow(enabled: true), $flow], methods: ['run']);
		$flows->expects($this->once())->method('run')->with('flow-2')->willReturn($queued);

		$service = new PubliccodeHarvestService(
			$this->apps(enabled: ['integriq', 'openregister']),
			$this->container(
				services: [
					'OCA\OpenRegister\Service\Flow\FlowService' => $flows,
					'OCA\OpenRegister\Service\ObjectService' => $this->scopedObjects(),
				]
			),
			$this->createMock(LoggerInterface::class)
		);

		foreach ([[false, 'switched-off'], [true, 'not-set-up']] as [$enabled, $slug]) {
			$flow->setEnabled($enabled);
			try {
				$service->runNow(harvest: 'developer-overheid');
				$this->fail('A run must be refused: ' . $slug);
			} catch (HarvestNotReadyException $e) {
				$this->assertSame($slug, $e->getError());
			}
		}

		$this->storedIn(schema: 'synchronization', slug: 'opencatalogi-publiccode-developer-overheid', data: []);
		$this->assertSame('run-4', $service->runNow(harvest: 'developer-overheid')['uuid']);
	}//end testADeveloperOverheidRunNeedsTheFlowOnAndTheSynchronization()

	/**
	 * A developer.overheid.nl file without a usable entry is reported, and an
	 * unknown harvest key is refused, never read as some default.
	 *
	 * @spec openspec/changes/publiccode-developer-overheid-harvest/specs/publiccode-developer-overheid-harvest/spec.md#requirement-req-pdh-002-the-register-is-read-page-by-page-through-one-synchronization
	 *
	 * @return void
	 */
	public function testABrokenSynchronizationFileAndAnUnknownHarvestAreRefused(): void {
		$root = sys_get_temp_dir() . '/oc-do-' . bin2hex(random_bytes(4));
		mkdir($root . '/lib/Settings', 0777, true);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppPath')->willReturn($root);
		$service = new PubliccodeHarvestService($apps, $this->container(services: []), $this->createMock(LoggerInterface::class));

		try {
			foreach (['' => 'is missing', '{"source": "developer-overheid-oss"}' => 'is not valid', '{"source": "developer-overheid-oss", "synchronizations": [{"synchronization": "x", "name": "x"}]}' => 'is not valid'] as $content => $expected) {
				if ($content !== '') {
					file_put_contents($root . PubliccodeHarvestService::DEVELOPER_OVERHEID_FILE, $content);
				}

				try {
					$service->status(harvest: 'developer-overheid');
					$this->fail('A broken file must be reported: ' . $content);
				} catch (RuntimeException $e) {
					$this->assertStringContainsString($expected, $e->getMessage());
					$this->assertNotInstanceOf(HarvestNotReadyException::class, $e, 'A broken file is a fault, not a precondition.');
				}
			}
		} finally {
			@unlink($root . PubliccodeHarvestService::DEVELOPER_OVERHEID_FILE);
			rmdir($root . '/lib/Settings');
			rmdir($root . '/lib');
			rmdir($root);
		}

		$this->expectException(InvalidArgumentException::class);
		$service->status(harvest: 'gitlab');
	}//end testABrokenSynchronizationFileAndAnUnknownHarvestAreRefused()
}//end class
