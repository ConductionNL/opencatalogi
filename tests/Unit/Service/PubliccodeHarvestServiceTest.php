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
use OCA\OpenRegister\Db\Flow;
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
}//end class
