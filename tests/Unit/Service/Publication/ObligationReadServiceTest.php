<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Tests for ObligationReadService: every enabled source is asked through the
 * real ObligationsRequestedEvent, and a source that fails or that nobody
 * answers for stays visible as unread.
 */

declare(strict_types=1);

namespace Unit\Service\Publication;

use DateTimeImmutable;
use OCA\OpenCatalogi\Event\ObligationsRequestedEvent;
use OCA\OpenCatalogi\Service\Publication\ObligationOverviewService;
use OCA\OpenCatalogi\Service\Publication\ObligationReadService;
use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Unit tests for ObligationReadService.
 */
class ObligationReadServiceTest extends TestCase {

	/**
	 * The service over the given sources and listeners.
	 *
	 * @param array<int, array<string, mixed>> $sources   The obligationSource rows.
	 * @param array<string, callable>          $listeners Per app id, what its listener does with the event.
	 *
	 * @return ObligationReadService The service.
	 */
	private function service(array $sources, array $listeners): ObligationReadService {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key): string => match ($key) {
				'publication_register' => '31',
				'obligation_source_schema' => '1553',
				default => '',
			}
		);

		$objectService = new class($sources) {
			/**
			 * @param array<int, array<string, mixed>> $sources The rows to answer.
			 */
			public function __construct(private array $sources) {
			}

			/**
			 * @param array<string, mixed> $query         The query.
			 * @param bool                 $_rbac         Ignored.
			 * @param bool                 $_multitenancy Ignored.
			 *
			 * @return array<string, mixed> The rows.
			 */
			public function searchObjectsPaginated(array $query, bool $_rbac=true, bool $_multitenancy=true): array {
				return ['results' => $this->sources];
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (ObligationsRequestedEvent $event) use ($listeners): void {
				if (isset($listeners[$event->getAppId()]) === true) {
					($listeners[$event->getAppId()])($event);
				}
			}
		);

		return new ObligationReadService($dispatcher, new ObligationOverviewService(), $config, $container, $this->appManager(installed: true));

	}//end service()

	/**
	 * One source answers, one throws and one has no reader.
	 */
	public function testEverySourceIsAskedAndTheUnreadOnesAreNamed(): void {
		$service = $this->service(
			sources: [
				['appId' => 'dossiq', 'title' => 'Woo-besluiten', 'enabled' => true],
				['appId' => 'decos', 'title' => 'Convenanten en brieven', 'enabled' => true],
				['appId' => 'notubiz', 'title' => 'Raadsstukken', 'enabled' => true],
				['appId' => 'retired', 'title' => 'Uit', 'enabled' => false],
			],
			listeners: [
				'dossiq' => static function (ObligationsRequestedEvent $event): void {
					$event->setObligations(
						[
							['title' => 'Besluit jeugdzorgcontracten', 'dueDate' => '2026-10-06T00:00:00+00:00', 'publishedAt' => ''],
							['title' => 'Besluit Molenweg', 'dueDate' => '2026-10-09T00:00:00+00:00', 'publishedAt' => ''],
							['title' => 'Besluit kermis', 'dueDate' => '2026-10-01T00:00:00+00:00', 'publishedAt' => '2026-10-02T10:00:00+00:00'],
						]
					);
				},
				'decos' => static function (): void {
					throw new RuntimeException('Decos JOIN answered 502');
				},
			]
		);

		$overview = $service->read(now: new DateTimeImmutable('2026-10-08T09:00:00+00:00'));

		$this->assertSame(3, $overview['total']);
		$this->assertSame(1, $overview['late']);
		$this->assertSame(2, $overview['outstanding']);
		$this->assertSame(1, $overview['dueThisWeek']);
		$this->assertSame(1, $overview['publishedThisMonth']);
		$this->assertSame(3, $overview['sourcesRegistered']);
		$this->assertSame(
			[
				['appId' => 'decos', 'reason' => 'Decos JOIN answered 502'],
				['appId' => 'notubiz', 'reason' => ObligationReadService::NO_READER],
			],
			$overview['unreadSources']
		);
		$this->assertSame(['late', 'due', 'published'], array_column($overview['obligations'], 'state'));

		$dossiq = $overview['bySource'][0];
		$this->assertSame('dossiq', $dossiq['appId']);
		$this->assertSame(2, $dossiq['outstanding']);
		$this->assertSame(1, $dossiq['late']);
		$this->assertSame('2026-10-06T00:00:00+00:00', $dossiq['nextDue']);
		$this->assertCount(3, $overview['bySource']);

	}//end testEverySourceIsAskedAndTheUnreadOnesAreNamed()

	/**
	 * Without a configured schema there are no sources, and nothing is asked.
	 */
	public function testAnUnconfiguredSchemaHasNoSources(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->expects($this->never())->method('dispatchTyped');

		$service = new ObligationReadService(
			$dispatcher,
			new ObligationOverviewService(),
			$config,
			$this->createMock(ContainerInterface::class),
			$this->appManager(installed: true)
		);

		$overview = $service->read();

		$this->assertSame(0, $overview['sourcesRegistered']);
		$this->assertSame([], $overview['bySource']);

	}//end testAnUnconfiguredSchemaHasNoSources()

	/**
	 * Sources that cannot be read are an error, not an empty overview.
	 */
	public function testUnreadableSourcesAreAnError(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('7');
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('OpenRegister is not installed'));

		$service = new ObligationReadService(
			$this->createMock(IEventDispatcher::class),
			new ObligationOverviewService(),
			$config,
			$container,
			$this->appManager(installed: true)
		);

		$this->expectException(RuntimeException::class);
		$service->read();

	}//end testUnreadableSourcesAreAnError()

	/**
	 * Without OpenRegister the sources are an error, and the container is not asked.
	 */
	public function testWithoutOpenRegisterTheSourcesAreAnError(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('7');
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');

		$service = new ObligationReadService(
			$this->createMock(IEventDispatcher::class),
			new ObligationOverviewService(),
			$config,
			$container,
			$this->appManager(installed: false)
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('OpenRegister is not installed');
		$service->read();

	}//end testWithoutOpenRegisterTheSourcesAreAnError()

	/**
	 * An app manager that answers whether OpenRegister is installed.
	 *
	 * @param bool $installed The answer.
	 *
	 * @return IAppManager
	 */
	private function appManager(bool $installed): IAppManager {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn($installed);
		return $appManager;

	}//end appManager()
}//end class
