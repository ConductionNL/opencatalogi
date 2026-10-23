<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Tests for ServiceStatusIndex and the provider's public index: the service
 * status reaches a portal as index items with table cells, in their order,
 * and nothing claims "it works" when the status cannot be read.
 */

declare(strict_types=1);

namespace Unit\Portal;

use DateTimeImmutable;
use OCA\OpenCatalogi\Portal\PortalContributionProvider;
use OCA\OpenCatalogi\Portal\ServiceStatusIndex;
use OCA\OpenCatalogi\Service\Community\StatusPageService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for ServiceStatusIndex.
 */
class ServiceStatusIndexTest extends TestCase {

	/**
	 * The index over these stored components.
	 *
	 * @param array<int, array<string, mixed>>|null $components The serviceStatus rows; null when OpenRegister cannot be asked.
	 *
	 * @return ServiceStatusIndex The index.
	 */
	private function index(?array $components): ServiceStatusIndex {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key): string => ['publication_register' => '31', 'service_status_schema' => '1558'][$key] ?? ''
		);
		$config->method('getValueInt')->willReturn(24);

		$container = $this->createMock(ContainerInterface::class);
		if ($components === null) {
			$container->method('get')->willThrowException(new RuntimeException('OpenRegister is not installed'));
		} else {
			$container->method('get')->willReturn(
				new class($components) {
					/**
					 * @param array<int, array<string, mixed>> $rows The rows.
					 */
					public function __construct(private array $rows) {
					}

					/**
					 * @param array<string, mixed> $query         The query.
					 * @param bool                 $_rbac         Ignored.
					 * @param bool                 $_multitenancy Ignored.
					 *
					 * @return array<string, mixed>
					 */
					public function searchObjectsPaginated(array $query, bool $_rbac=true, bool $_multitenancy=true): array {
						return ['results' => $this->rows];
					}
				}
			);
		}

		return new ServiceStatusIndex($config, $container, new StatusPageService(), $this->createMock(LoggerInterface::class));

	}//end index()

	public function testEveryComponentIsAnItemWithItsCells(): void {
		$index = $this->index(
			[
				['component' => 'Woo-verzoek indienen', 'state' => 'degraded', 'message' => 'Formulieren worden traag geladen.', 'stateSetAt' => '2026-10-08T09:40:00+00:00', 'order' => 2],
				['component' => 'Woo-publicaties zoeken', 'state' => 'available', 'message' => '', 'stateSetAt' => '2026-10-08T07:00:00+00:00', 'order' => 1],
				['component' => 'Raadsstukken', 'state' => 'maintenance', 'message' => 'Zaterdag van 07:00 tot 09:00 niet bereikbaar.', 'stateSetAt' => '2026-10-08T08:00:00+00:00', 'order' => 3],
			]
		);

		$items = $index->items(now: new DateTimeImmutable('2026-10-08T12:00:00+00:00'));

		$this->assertSame(['Woo-publicaties zoeken', 'Woo-verzoek indienen', 'Raadsstukken'], array_column($items, 'title'));
		$this->assertSame(
			['service' => 'Woo-publicaties zoeken', 'message' => 'Alles werkt zoals verwacht.', 'state' => 'Werkt'],
			$items[0]['cells']
		);
		$this->assertSame('Verstoring', $items[1]['cells']['state']);
		$this->assertSame('Formulieren worden traag geladen. Sinds 8 oktober, 09:40.', $items[1]['cells']['message']);
		$this->assertSame('warning', $items[1]['noteTone']);
		$this->assertSame('Onderhoud gepland', $items[2]['badge']);
		$this->assertSame(ServiceStatusIndex::TYPE, $items[2]['type']);

	}//end testEveryComponentIsAnItemWithItsCells()

	public function testAnUnreadableStatusGivesNoItems(): void {
		$this->assertSame([], $this->index(null)->items());

	}//end testAnUnreadableStatusGivesNoItems()

	public function testTheProviderOffersTheKindAndItsItems(): void {
		$index = $this->index([['component' => 'Mijn dossiers', 'state' => 'available', 'stateSetAt' => '2026-10-08T07:00:00+00:00']]);
		$provider = new PortalContributionProvider(null, null, $index);

		$kinds = $provider->getPublicIndexKinds('zuiddrecht');
		$this->assertSame(ServiceStatusIndex::TYPE, $kinds[0]['type']);
		$this->assertSame(['service', 'message', 'state'], array_column($kinds[0]['columns'], 'key'));
		$this->assertCount(1, $provider->getPublicIndex('zuiddrecht'));

		$this->assertSame([], (new PortalContributionProvider())->getPublicIndex('zuiddrecht'));

	}//end testTheProviderOffersTheKindAndItsItems()
}//end class
