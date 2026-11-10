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

use DateTime;
use OCA\OpenCatalogi\Service\PubliccodeHarvestService;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunListing;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Db\FlowRunViewer;
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
 * @uses \OCA\OpenCatalogi\Service\FlowReport
 */
class PubliccodeHarvestServiceTest extends TestCase {

	/**
	 * Objects "stored" in integriq, by slug.
	 *
	 * @var array<string, ObjectEntity>
	 */
	private array $store = [];

	/**
	 * The ObjectService calls made, in order.
	 *
	 * @var list<mixed>
	 */
	private array $calls = [];

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
			function (int|string $id, ...$rest): ?ObjectEntity {
				$this->calls[] = ['find', self::passedArguments(method: 'find', arguments: func_get_args())];
				return ($this->store[(string)$id] ?? null);
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ?array $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null, mixed ...$rest): ObjectEntity {
				$arguments = self::passedArguments(method: 'saveObject', arguments: func_get_args());
				$arguments['object'] = $object['slug'];
				$this->calls[] = ['saveObject', $arguments];
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
	 * The arguments of an ObjectService call by parameter name, without those left at their default.
	 *
	 * Recorded by name and not by position, so the recording does not follow the
	 * signature of whichever OpenRegister version is loaded.
	 *
	 * @param string $method The ObjectService method.
	 * @param array<int, mixed> $arguments The arguments the double received.
	 *
	 * @return array<string, mixed>
	 */
	private static function passedArguments(string $method, array $arguments): array {
		$named = [];
		foreach ((new \ReflectionMethod(ObjectService::class, $method))->getParameters() as $index => $parameter) {
			if (array_key_exists($index, $arguments) === false) {
				break;
			}

			if ($parameter->isDefaultValueAvailable() === true && $arguments[$index] === $parameter->getDefaultValue()) {
				continue;
			}

			$named[$parameter->getName()] = $arguments[$index];
		}

		return $named;
	}//end passedArguments()

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
	 * The status in several worlds, and a set-up run, match the recording.
	 *
	 * @return void
	 */
	public function testStatusAndSetUpMatchTheRecording(): void {
		$flow = $this->flow(enabled: true);
		$flow->setNodes([['type' => 'openregister.trigger-schedule', 'config' => ['cron' => '1 2 * * *']], ['type' => 'other', 'config' => ['runAs' => 'x']]]);
		$other = $this->flow(enabled: true);
		$other->setName('Another flow');
		$twoSchedules = $this->flow(enabled: true);
		$twoSchedules->setNodes(
			[
				['type' => 'openregister.trigger-schedule', 'config' => ['cron' => '0 1 * * *', 'runAs' => 'first-user']],
				['type' => 'openregister.trigger-schedule', 'config' => ['cron' => '30 4 * * 1']],
			]
		);
		$finished = new FlowRun();
		$finished->setUuid('run-9');
		$finished->setStatus('finished');
		$finished->setTrigger('schedule');
		$finished->setCreated(new DateTime('2026-10-03T03:15:00+00:00'));
		$finished->setUpdated(new DateTime('2026-10-03T03:41:07+00:00'));
		$finished->setError('shard 07: page 3 refused');
		$finished->setLog([['transition' => 'shard-07', 'status' => 'failed'], ['transition' => 'end', 'status' => 'completed']]);
		$runsByWorld = ['two schedule nodes, a finished run' => [$finished]];
		$worlds = [
			'nothing installed' => [[], [], null],
			'openregister only, flow off' => [['openregister'], [], $this->flow(enabled: false)],
			'integriq, nothing stored, flow on' => [['integriq', 'openregister'], [], $flow],
			'integriq, source disabled' => [['integriq', 'openregister'], ['github-api' => ['isEnabled' => 'yes']], null],
			'integriq, source enabled, two shards' => [['integriq', 'openregister'], ['github-api' => ['isEnabled' => true], 'opencatalogi-publiccode-github-01' => [], 'opencatalogi-publiccode-github-24' => []], null],
			'only another flow' => [['openregister'], [], $other],
			'two schedule nodes, a finished run' => [['openregister'], [], $twoSchedules],
		];
		$actual = [];
		foreach ($worlds as $name => [$apps, $stored, $world]) {
			$runs = ($runsByWorld[$name] ?? []);
			$runMapper = $this->getMockBuilder(FlowRunMapper::class)->disableOriginalConstructor()->onlyMethods(['findAllRuns'])->getMock();
			$runMapper->method('findAllRuns')->willReturnCallback(
				function (FlowRunListing $listing, FlowRunViewer $viewer) use ($runs): array {
					$this->assertNull($viewer->requesterUid, 'The harvest status reads its own flow as the system.');
					$this->calls[] = ['findAllRuns', $listing->filter->flowId, $listing->filter->status, $listing->limit];
					return $runs;
				}
			);
			$this->calls = [];
			$this->store = [];
			foreach ($stored as $slug => $data) {
				$this->stored(slug: $slug, data: $data);
			}

			$services = [
				'OCA\OpenRegister\Service\ObjectService' => $this->objects(),
				'OCA\OpenRegister\Service\Flow\FlowService' => $this->flows(flows: array_filter([$world])),
				'OCA\OpenRegister\Db\FlowRunMapper' => $runMapper,
			];
			$service = new PubliccodeHarvestService($this->apps(enabled: $apps), $this->container(services: $services), $this->createMock(LoggerInterface::class));
			$actual[$name] = ['status' => $service->status(), 'calls' => $this->calls];
		}

		$this->store = [];
		$service = new PubliccodeHarvestService(
			$this->apps(enabled: ['integriq', 'openregister']),
			$this->container(services: ['OCA\OpenRegister\Service\ObjectService' => $this->objects()]),
			$this->createMock(LoggerInterface::class)
		);
		$this->calls = [];
		$actual['set up'] = $service->setUp();
		$actual['set up again'] = $service->setUp();
		$actual['saved'] = array_map(static fn (ObjectEntity $entity): array => $entity->getObject(), $this->store);
		$actual['calls'] = $this->calls;

		$fixture = __DIR__ . '/fixtures/publiccode-harvest-status-golden.json';
		$actual = json_decode((string)json_encode($actual), true);
		if (getenv('GOLDEN_RECORD') === '1') {
			file_put_contents($fixture, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertSame(json_decode((string)file_get_contents($fixture), true), $actual);
	}//end testStatusAndSetUpMatchTheRecording()

	/**
	 * A secret in the source, in the flow's nodes or in a run's log never reaches
	 * the status, a queued run, the log lines or the saved synchronizations.
	 *
	 * @return void
	 */
	public function testNoSecretReachesStatusRunsLogsOrSyncs(): void {
		$secret = 'ghp_PLANTED_SECRET_0123456789';
		$this->stored(slug: 'github-api', data: ['isEnabled' => true, 'authentication' => ['token' => $secret], 'headers' => ['Authorization' => 'Bearer ' . $secret]]);
		$this->storeAllShards();
		$flow = $this->flow(enabled: true);
		$flow->setNodes(
			[
				['type' => 'openregister.trigger-schedule', 'config' => ['cron' => '15 3 * * *', 'runAs' => 'admin', 'token' => $secret]],
				['type' => 'integriq.call', 'config' => ['headers' => ['Authorization' => 'Bearer ' . $secret]]],
			]
		);
		$run = $this->suspendedRun();
		$run->setLog([['transition' => 'call', 'status' => 'completed', 'request' => ['headers' => ['Authorization' => 'Bearer ' . $secret]]]]);
		$flows = $this->flows(flows: [$flow], methods: ['run']);
		$flows->method('run')->willReturn($run);

		$logged = [];
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning', 'error', 'debug', 'notice'] as $level) {
			$logger->method($level)->willReturnCallback(
				static function (string|\Stringable $message, array $context = []) use (&$logged, $level): void {
					$logged[] = [$level, (string)$message, $context];
				}
			);
		}

		$service = new PubliccodeHarvestService(
			$this->apps(enabled: ['integriq', 'openregister']),
			$this->container(
				services: [
					'OCA\OpenRegister\Service\ObjectService' => $this->objects(),
					'OCA\OpenRegister\Service\Flow\FlowService' => $flows,
					'OCA\OpenRegister\Db\FlowRunMapper' => $this->runs(runs: [$run]),
				]
			),
			$logger
		);

		$outputs = [
			'status' => $service->status(),
			'run' => $service->runNow(),
			'set up' => $service->setUp(),
			'saved' => array_map(static fn (ObjectEntity $entity): array => $entity->getObject(), array_filter($this->store, static fn (string $slug): bool => $slug !== 'github-api', ARRAY_FILTER_USE_KEY)),
			'logged' => $logged,
		];
		$this->assertSame('run-1', $outputs['run']['uuid'], 'The run was queued.');
		$this->assertNotSame([], $outputs['logged'], 'The set-up was logged.');
		foreach ($outputs as $name => $output) {
			$this->assertStringNotContainsString('PLANTED_SECRET', (string)json_encode($output), $name);
		}
	}//end testNoSecretReachesStatusRunsLogsOrSyncs()

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
}//end class
