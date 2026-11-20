<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Tests for the shapes in which OpenRegister hands ObligationReadService its
 * obligation source rows: entities that serialise themselves, rows wrapped in
 * an `object` envelope, and rows that are not rows at all.
 */

declare(strict_types=1);

namespace Unit\Service\Publication;

use DateTimeImmutable;
use JsonSerializable;
use OCA\OpenCatalogi\Event\ObligationsRequestedEvent;
use OCA\OpenCatalogi\Service\Publication\ObligationOverviewService;
use OCA\OpenCatalogi\Service\Publication\ObligationReadService;
use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Unit tests for the source rows of ObligationReadService.
 *
 * @covers \OCA\OpenCatalogi\Service\Publication\ObligationReadService
 * @uses   \OCA\OpenCatalogi\Service\Publication\ObligationOverviewService
 * @uses   \OCA\OpenCatalogi\Event\ObligationsRequestedEvent
 */
class ObligationSourceRowsTest extends TestCase {

	/**
	 * The app ids the service asked, in order.
	 *
	 * @var array<int, string>
	 */
	private array $asked = [];

	/**
	 * The service over the given raw search results.
	 *
	 * @param array<int, mixed> $results What OpenRegister's search answers.
	 *
	 * @return ObligationReadService The service.
	 */
	private function service(array $results): ObligationReadService {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key): string => match ($key) {
				'publication_register' => '31',
				'obligation_source_schema' => '1553',
				default => '',
			}
		);

		$objectService = new class($results) {
			/**
			 * @param array<int, mixed> $results The rows to answer.
			 */
			public function __construct(
				private array $results,
			) {
			}

			/**
			 * @param array<string, mixed> $query The query.
			 * @param bool $_rbac Ignored.
			 * @param bool $_multitenancy Ignored.
			 *
			 * @return array<string, mixed> The rows.
			 */
			public function searchObjectsPaginated(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
				return ['results' => $this->results];
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (ObligationsRequestedEvent $event): void {
				$this->asked[] = $event->getAppId();
				if ($event->getAppId() === 'dossiq') {
					$event->setObligations(
						[['title' => 'Besluit Molenweg', 'dueDate' => '2026-10-09T00:00:00+00:00', 'publishedAt' => '']]
					);
				}
			}
		);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);

		return new ObligationReadService($dispatcher, new ObligationOverviewService(), $config, $container, $appManager);
	}//end service()

	/**
	 * An entity that serialises to an `object` envelope is read as its properties.
	 *
	 * @return void
	 */
	public function testASerialisedEntityInAnEnvelopeIsReadAsItsProperties(): void {
		$entity = new class implements JsonSerializable {
			/**
			 * @return array<string, mixed> The OpenRegister shape.
			 */
			public function jsonSerialize(): array {
				return [
					'uuid' => 'src-1',
					'object' => ['appId' => 'dossiq', 'title' => 'Woo-besluiten', 'enabled' => true],
				];
			}
		};

		$overview = $this->service(results: [$entity])->read(now: new DateTimeImmutable('2026-10-08T09:00:00+00:00'));

		$this->assertSame(['dossiq'], $this->asked);
		$this->assertSame(1, $overview['sourcesRegistered']);
		$this->assertSame(1, $overview['total']);
		$this->assertSame(1, $overview['dueThisWeek']);
		$this->assertSame([], $overview['unreadSources']);
		$this->assertSame('dossiq', $overview['bySource'][0]['appId']);

	}//end testASerialisedEntityInAnEnvelopeIsReadAsItsProperties()

	/**
	 * A disabled source inside an envelope stays disabled: it is never asked.
	 *
	 * @return void
	 */
	public function testADisabledSourceInAnEnvelopeIsNotAsked(): void {
		$overview = $this->service(
			results: [['id' => 9, 'object' => ['appId' => 'dossiq', 'title' => 'Uit', 'enabled' => false]]]
		)->read(now: new DateTimeImmutable('2026-10-08T09:00:00+00:00'));

		$this->assertSame([], $this->asked);
		$this->assertSame(0, $overview['total']);

	}//end testADisabledSourceInAnEnvelopeIsNotAsked()

	/**
	 * A row that is neither an array nor serialisable carries no app id.
	 *
	 * @return void
	 */
	public function testARowThatIsNotARowCarriesNoAppId(): void {
		$overview = $this->service(results: ['garbage'])->read(now: new DateTimeImmutable('2026-10-08T09:00:00+00:00'));

		$this->assertSame([''], $this->asked);
		$this->assertSame(0, $overview['total']);

	}//end testARowThatIsNotARowCarriesNoAppId()
}//end class
