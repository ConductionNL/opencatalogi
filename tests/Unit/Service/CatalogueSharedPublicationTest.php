<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\CatalogiService;
use OCA\OpenCatalogi\Service\PublicationQueryService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * One publication in the catalogues of two organisations, without a copy (REQ-CRR-002).
 *
 * A catalogue selects its publications by register and schema; it holds no
 * copies. The fake ObjectService below keeps one store of publications and
 * answers each catalogue's public list from it, so the test sees what two
 * organisations' catalogues answer for the same stored object.
 *
 * @covers \OCA\OpenCatalogi\Service\CatalogiService
 * @uses   \OCA\OpenCatalogi\Service\CatalogScopeResolver
 * @uses   \OCA\OpenCatalogi\Service\CallerScope
 *
 * @spec openspec/changes/capability-rows-get-requirements/specs/catalogs/spec.md#requirement-catalogues-of-several-organisations-show-one-publication-without-copying-it-req-crr-002
 */
class CatalogueSharedPublicationTest extends TestCase {

	/**
	 * The two catalogues, by slug: each its own organisation, both selecting register 7 and schema 12.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private const CATALOGUES = [
		'gemeente-a' => ['slug' => 'gemeente-a', 'organization' => 'org-gemeente-a', 'registers' => [7], 'schemas' => [12]],
		'omgevingsdienst' => ['slug' => 'omgevingsdienst', 'organization' => 'org-omgevingsdienst', 'registers' => [7], 'schemas' => [12]],
	];

	/**
	 * The publication store, by id: the only place a publication lives.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $publications = [];

	/**
	 * Every query OpenRegister was asked for a catalogue's publications.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $listQueries = [];

	/**
	 * The service under test, wired to the fake store.
	 *
	 * @var CatalogiService
	 */
	private CatalogiService $service;

	/**
	 * Wire the service to a store holding one "Milieu" decision and one other publication.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->publications = [
			'pub-milieu' => [
				'id' => 'pub-milieu',
				'title' => 'Besluit geluidsnormen',
				'themes' => ['Milieu'],
				'@self' => ['register' => 7, 'schema' => 12],
			],
			'pub-verkeer' => [
				'id' => 'pub-verkeer',
				'title' => 'Besluit parkeren',
				'themes' => ['Verkeer'],
				'@self' => ['register' => 7, 'schema' => 12],
			],
		];

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnMap([
			['opencatalogi', 'catalog_schema', '', 'catalog-schema'],
			['opencatalogi', 'catalog_register', '', 'catalog-register'],
		]);

		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn(['themes' => 'Milieu']);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['openregister', 'opencatalogi']);

		$queryService = $this->createMock(PublicationQueryService::class);
		$queryService->method('applySchemaScopeReadRuleGuard')->willReturnArgument(0);

		$objectService = $this->objectService();
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($queryService, $objectService): object {
				if ($id === PublicationQueryService::class) {
					return $queryService;
				}

				return $objectService;
			}
		);

		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($this->createMock(ICache::class));

		// An anonymous caller: the public list.
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);

		$this->service = new CatalogiService(
			$config,
			$request,
			$container,
			$appManager,
			$cacheFactory,
			$this->createMock(LoggerInterface::class),
			$session,
		);

	}//end setUp()

	/**
	 * An ObjectService over the catalogues and the one publication store.
	 *
	 * The catalogue search answers the catalogue named by the query's slug;
	 * the paginated search answers the stored publications in the queried
	 * register and schema whose themes hold the requested theme.
	 *
	 * @return ObjectService The fake.
	 */
	private function objectService(): ObjectService {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('searchObjects')->willReturnCallback(
			static function (array $query): array {
				$catalogue = (self::CATALOGUES[$query['slug'] ?? ''] ?? null);

				if ($catalogue === null) {
					return [];
				}

				// OpenRegister answers catalogues as entities.
				return [
					new class($catalogue) implements \JsonSerializable {
						/**
						 * The catalogue as an entity.
						 *
						 * @param array<string, mixed> $object The catalogue's properties.
						 */
						public function __construct(private readonly array $object) {
						}//end __construct()

						/**
						 * The catalogue's properties.
						 *
						 * @return array<string, mixed> The properties.
						 */
						public function jsonSerialize(): array {
							return $this->object;
						}//end jsonSerialize()
					},
				];
			}
		);
		$objectService->method('searchObjectsPaginated')->willReturnCallback(
			function (array $query): array {
				$this->listQueries[] = $query;
				$results = array_values(
					array_filter(
						$this->publications,
						static fn (array $publication): bool => $publication['@self']['register'] === $query['@self']['register']
							&& $publication['@self']['schema'] === $query['@self']['schema']
							&& in_array($query['themes'] ?? null, $publication['themes'], true)
					)
				);

				return ['results' => $results, 'total' => count($results)];
			}
		);

		return $objectService;

	}//end objectService()

	/**
	 * The ids a catalogue's public list answers.
	 *
	 * @param string $slug The catalogue.
	 *
	 * @return array<int, array<string, mixed>> The listed publications.
	 */
	private function listOf(string $slug): array {
		return $this->service->index(catalogId: $slug)->getData()['results'];

	}//end listOf()

	/**
	 * Both organisations' catalogues list the "Milieu" decision, with the same id.
	 *
	 * @return void
	 */
	public function testTwoOrganisationsListTheSameDecision(): void {
		$gemeente = $this->listOf(slug: 'gemeente-a');
		$omgevingsdienst = $this->listOf(slug: 'omgevingsdienst');

		$this->assertSame(['pub-milieu'], array_column($gemeente, 'id'));
		$this->assertSame(['pub-milieu'], array_column($omgevingsdienst, 'id'));

		// Each list was read from the one store by register, schema and theme.
		$this->assertCount(2, $this->listQueries);
		foreach ($this->listQueries as $query) {
			$this->assertSame(7, $query['@self']['register']);
			$this->assertSame(12, $query['@self']['schema']);
			$this->assertSame('Milieu', $query['themes']);
		}

	}//end testTwoOrganisationsListTheSameDecision()

	/**
	 * An edit to the stored publication shows in both catalogues, and no second object exists.
	 *
	 * @return void
	 */
	public function testAnEditShowsInBothCatalogues(): void {
		$this->publications['pub-milieu']['title'] = 'Besluit geluidsnormen (gewijzigd)';

		$gemeente = $this->listOf(slug: 'gemeente-a');
		$omgevingsdienst = $this->listOf(slug: 'omgevingsdienst');

		$this->assertSame('Besluit geluidsnormen (gewijzigd)', $gemeente[0]['title']);
		$this->assertSame('Besluit geluidsnormen (gewijzigd)', $omgevingsdienst[0]['title']);
		$this->assertSame($gemeente[0]['id'], $omgevingsdienst[0]['id']);
		$this->assertCount(2, $this->publications);

	}//end testAnEditShowsInBothCatalogues()

	/**
	 * A catalogue that does not exist lists nothing, so the shared list is not a fallback.
	 *
	 * @return void
	 */
	public function testAnUnknownCatalogueListsNothing(): void {
		$this->assertSame([], $this->listOf(slug: 'onbekend'));
		$this->assertSame([], $this->listQueries);

	}//end testAnUnknownCatalogueListsNothing()
}//end class
