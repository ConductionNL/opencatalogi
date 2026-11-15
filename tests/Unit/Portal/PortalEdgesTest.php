<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The edges of the portal classes the other Portal tests leave out: a service
 * status that is not configured, rows that are no component, a state set at a
 * moment that cannot be read, OpenRegister handing back entities or wrapped
 * objects, a provider without a status index, and an assertion whose header
 * is not base64.
 */

declare(strict_types=1);

namespace Unit\Portal;

use DateTimeImmutable;
use OCA\OpenCatalogi\Portal\PortalAssertionVerifier;
use OCA\OpenCatalogi\Portal\PortalContributionProvider;
use OCA\OpenCatalogi\Portal\ServiceStatusIndex;
use OCA\OpenCatalogi\Service\Community\StatusPageService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Edge cases of the portal classes.
 *
 * @covers \OCA\OpenCatalogi\Portal\ServiceStatusIndex
 * @covers \OCA\OpenCatalogi\Portal\PortalContributionProvider
 * @covers \OCA\OpenCatalogi\Portal\PortalAssertionVerifier
 * @uses   \OCA\OpenCatalogi\Service\Community\StatusPageService
 */
class PortalEdgesTest extends TestCase {

	/**
	 * The index over these OpenRegister results, with this configuration.
	 *
	 * @param array<int, mixed> $results The results OpenRegister answers.
	 * @param array<string, string> $settings The app settings.
	 * @param ContainerInterface|null $container A container to use instead.
	 *
	 * @return ServiceStatusIndex The index.
	 */
	private function index(array $results, array $settings = ['publication_register' => '31', 'service_status_schema' => '1558'], ?ContainerInterface $container = null): ServiceStatusIndex {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key): string => ($settings[$key] ?? '')
		);
		$config->method('getValueInt')->willReturn(24);

		if ($container === null) {
			$container = $this->createMock(ContainerInterface::class);
			$container->method('get')->willReturn(
				new class($results) {
					/**
					 * @param array<int, mixed> $rows The rows.
					 */
					public function __construct(
						private array $rows,
					) {
					}

					/**
					 * @param array<string, mixed> $query The query.
					 * @param bool $_rbac Ignored.
					 * @param bool $_multitenancy Ignored.
					 *
					 * @return array<string, mixed>
					 */
					public function searchObjectsPaginated(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
						return ['results' => $this->rows];
					}
				}
			);
		}

		return new ServiceStatusIndex($config, $container, new StatusPageService(), $this->createMock(LoggerInterface::class));
	}//end index()

	/**
	 * Without a register or a schema configured, OpenRegister is not asked.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredStatusAsksNothingAndGivesNoItems(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');

		$this->assertSame([], $this->index([], ['publication_register' => '31'], $container)->items());
		$this->assertSame([], $this->index([], ['service_status_schema' => '1558'], $container)->items());
	}//end testAnUnconfiguredStatusAsksNothingAndGivesNoItems()

	/**
	 * An entity is read through jsonSerialize, a wrapped object through its
	 * `object`, and a value that is neither is no item.
	 *
	 * @return void
	 */
	public function testEntitiesAndWrappedObjectsAreReadAndOtherValuesSkipped(): void {
		$entity = new class() implements \JsonSerializable {
			/**
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return ['object' => ['component' => 'Afvalkalender', 'state' => 'available', 'stateSetAt' => '2026-10-08T07:00:00+00:00', 'order' => 1]];
			}
		};

		$items = $this->index(
			[
				$entity,
				'not an object',
				['component' => 'Parkeervergunning', 'state' => 'maintenance', 'message' => 'Vanavond', 'stateSetAt' => '2026-10-08T07:00:00+00:00', 'order' => 2],
			]
		)->items(now: new DateTimeImmutable('2026-10-08T12:00:00+00:00'));

		$this->assertSame(['Afvalkalender', 'Parkeervergunning'], array_column($items, 'title'));
		$this->assertSame('Alles werkt zoals verwacht.', $items[0]['summary']);
		$this->assertSame('Vanavond Sinds 8 oktober, 07:00.', $items[1]['summary']);
		$this->assertSame(ServiceStatusIndex::TYPE . '-1', $items[1]['id']);
	}//end testEntitiesAndWrappedObjectsAreReadAndOtherValuesSkipped()

	/**
	 * A row without a component name is no item; its place in the order still counts.
	 *
	 * @return void
	 */
	public function testARowWithoutAComponentIsLeftOut(): void {
		$items = $this->index(
			[
				['component' => '', 'state' => 'outage', 'stateSetAt' => '2026-10-08T07:00:00+00:00', 'order' => 1],
				['component' => 'Mijn dossiers', 'state' => 'available', 'stateSetAt' => '2026-10-08T07:00:00+00:00', 'order' => 2],
			]
		)->items(now: new DateTimeImmutable('2026-10-08T12:00:00+00:00'));

		$this->assertCount(1, $items);
		$this->assertSame('Mijn dossiers', $items[0]['title']);
	}//end testARowWithoutAComponentIsLeftOut()

	/**
	 * A state set at a moment that cannot be read, or at none, says no "Sinds".
	 *
	 * @return void
	 */
	public function testAnUnreadableMomentAddsNoSince(): void {
		$items = $this->index(
			[
				['component' => 'Betalen', 'state' => 'degraded', 'message' => 'Traag.', 'stateSetAt' => 'not a moment', 'order' => 1],
				['component' => 'Afspraken', 'state' => 'degraded', 'message' => 'Traag.', 'stateSetAt' => '', 'order' => 2],
			]
		)->items(now: new DateTimeImmutable('2026-10-08T12:00:00+00:00'));

		$this->assertSame(['Betalen', 'Afspraken'], array_column($items, 'title'));
		$this->assertSame(['Traag.', 'Traag.'], array_column($items, 'summary'));
	}//end testAnUnreadableMomentAddsNoSince()

	/**
	 * A provider without a status index offers no index kinds.
	 *
	 * @return void
	 */
	public function testAProviderWithoutAStatusIndexOffersNoKinds(): void {
		$this->assertSame([], (new PortalContributionProvider())->getPublicIndexKinds('zuiddrecht'));
	}//end testAProviderWithoutAStatusIndexOffersNoKinds()

	/**
	 * A header that is not base64 is refused as an unexpected algorithm.
	 *
	 * @return void
	 */
	public function testAHeaderThatIsNotBase64IsRefused(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('debug')->with(
			'OpenCatalogi: portal assertion rejected',
			['reason' => 'unexpected algorithm']
		);
		$verifier = new PortalAssertionVerifier(null, $logger, 'a-dedicated-secret-of-enough-length');

		$this->assertNull($verifier->verify('*not-base64*.e30.c2ln'));
	}//end testAHeaderThatIsNotBase64IsRefused()
}//end class
