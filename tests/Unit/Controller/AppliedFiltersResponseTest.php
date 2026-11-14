<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Contract\CatalogiServiceInterface;
use OCA\OpenCatalogi\Contract\PublicationQueryServiceInterface;
use OCA\OpenCatalogi\Contract\PublicationServiceInterface;
use OCA\OpenCatalogi\Contract\UsageCounterServiceInterface;
use OCA\OpenCatalogi\Controller\FederationController;
use OCA\OpenCatalogi\Controller\PublicationsController;
use OCA\OpenCatalogi\Controller\SearchController;
use OCA\OpenCatalogi\Service\AppliedFilters;
use OCA\OpenCatalogi\Service\PublicationQueryService;
use OCP\App\IAppManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Every public list response says which filters the server applied on its own
 * (REQ-ASF-001, REQ-ASF-002), driven through the controller methods the routes name.
 *
 * @covers \OCA\OpenCatalogi\Controller\PublicationsController
 * @covers \OCA\OpenCatalogi\Controller\SearchController
 * @covers \OCA\OpenCatalogi\Controller\FederationController
 * @uses \OCA\OpenCatalogi\Service\AppliedFilters
 * @uses \OCA\OpenCatalogi\Service\PublicationQueryService
 * @uses \OCA\OpenCatalogi\Service\CallerScope
 * @uses \OCA\OpenCatalogi\Service\CatalogScopeIds
 * @uses \OCA\OpenCatalogi\Controller\AnswersCacheably
 *
 * @spec openspec/changes/api-says-what-it-filtered/specs/search/spec.md
 */
class AppliedFiltersResponseTest extends TestCase {

	/**
	 * The list routes of the four public controllers and the method each names.
	 * A new list route on these controllers must be added here, and so be driven.
	 *
	 * @var array<string, string>
	 */
	private const LIST_ROUTES = [
		'publications#index' => '/api/{catalogSlug}',
		'search#index' => '/api/search',
		'themes#index' => '/api/themes',
		'federation#publications' => '/api/federation/publications',
	];

	/**
	 * The queries OpenRegister was asked.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $queries = [];

	/**
	 * The catalogue list reports its scope as slugs and the anonymous evaluation.
	 *
	 * @return void
	 */
	public function testTheCatalogueListReportsItsScope(): void {
		$filters = new AppliedFilters();
		$data = $this->catalogueIndex(params: [], filters: $filters)->getData();

		$this->assertSame(
			[
				[
					'name' => 'publicOnly',
					'value' => true,
					'reason' => 'Only what an anonymous reader may see is listed.',
				],
				[
					'name' => 'catalogScope',
					'value' => ['schemas' => ['publication', 'besluit'], 'registers' => ['woo-register']],
					'reason' => 'The list is limited to the schemas and registers of the catalogue.',
				],
			],
			$data['appliedFilters']
		);
	}//end testTheCatalogueListReportsItsScope()

	/**
	 * A caller's `_schemas[]=secret` is ignored, named, and not echoed.
	 *
	 * @return void
	 */
	public function testAStrippedCallerScopeIsReported(): void {
		$filters = new AppliedFilters();
		$data = $this->catalogueIndex(params: ['_schemas' => ['secret']], filters: $filters)->getData();

		$this->assertSame([1, 2], $this->queries[0]['_schemas'], 'the results are the catalogue\'s own');
		$entries = array_column($data['appliedFilters'], null, 'name');
		$this->assertSame(['_schemas'], $entries['callerScopeIgnored']['value']);
		$this->assertStringNotContainsString('secret', (string)json_encode($data['appliedFilters']));
	}//end testAStrippedCallerScopeIsReported()

	/**
	 * A search with no caller scope keys does not claim callerScopeIgnored.
	 *
	 * @return void
	 */
	public function testAFilterThatDidNotApplyIsNotListed(): void {
		$data = $this->catalogueIndex(params: ['_search' => 'x'], filters: new AppliedFilters())->getData();

		$this->assertSame(['publicOnly', 'catalogScope'], array_column($data['appliedFilters'], 'name'));
	}//end testAFilterThatDidNotApplyIsNotListed()

	/**
	 * Archived rows dropped from a search page are reported by the search route.
	 *
	 * @return void
	 */
	public function testArchivedRowsDroppedAreReported(): void {
		$filters = new AppliedFilters();
		$queryService = $this->createMock(PublicationQueryServiceInterface::class);
		$queryService->method('assemblePublicSearchResults')->willReturnCallback(
			static function () use ($filters): array {
				// The query service records at the drop site; two archived rows left this page.
				$filters->increment(name: AppliedFilters::ARCHIVED_EXCLUDED, reason: 'Archived publications are never shown on a public list.');
				$filters->increment(name: AppliedFilters::ARCHIVED_EXCLUDED, reason: 'Archived publications are never shown on a public list.');
				return ['results' => [], 'total' => 0];
			}
		);

		$data = $this->searchController(queryService: $queryService, filters: $filters)->index()->getData();

		$this->assertSame(
			[['name' => 'archivedExcluded', 'value' => 2, 'reason' => 'Archived publications are never shown on a public list.']],
			$data['appliedFilters']
		);
	}//end testArchivedRowsDroppedAreReported()

	/**
	 * A collector that throws is answered as the single `unknown` entry, never an empty list.
	 *
	 * @return void
	 */
	public function testAFailingCollectorReportsUnknown(): void {
		$failing = new class extends AppliedFilters {
			public function all(): array {
				throw new RuntimeException('collector broke');
			}
		};

		$data = $this->catalogueIndex(params: [], filters: $failing)->getData();

		$this->assertSame([['name' => 'unknown', 'value' => null, 'reason' => 'not recorded']], $data['appliedFilters']);
		$this->assertSame([['id' => 'p-1']], $data['results']);
	}//end testAFailingCollectorReportsUnknown()

	/**
	 * A failed list carries no appliedFilters: filters that ran for a list that
	 * was never answered are not reported.
	 *
	 * @return void
	 */
	public function testAFailedListCarriesNoAppliedFilters(): void {
		$publications = $this->createMock(PublicationServiceInterface::class);
		$publications->method('getAggregatedPublications')->willThrowException(new \Exception('down'));

		$response = $this->federationController(publications: $publications, filters: new AppliedFilters())->publications();

		$this->assertSame(500, $response->getStatus());
		$this->assertArrayNotHasKey('appliedFilters', $response->getData());
	}//end testAFailedListCarriesNoAppliedFilters()

	/**
	 * Every list route in appinfo/routes.php is one this test drives, and each
	 * driven list answer carries appliedFilters (themes: PublicObjectListControllersTest).
	 *
	 * @return void
	 */
	public function testEveryPublicListRouteCarriesAppliedFilters(): void {
		$routes = (require __DIR__ . '/../../../appinfo/routes.php')['routes'];
		$lists = [];
		foreach ($routes as $route) {
			[$controller] = explode('#', $route['name']);
			if (in_array($controller, ['publications', 'search', 'themes', 'federation'], true) === false
				|| ($route['verb'] ?? 'GET') !== 'GET'
				|| str_contains($route['url'], '{id}') === true
			) {
				continue;
			}

			$lists[$route['name']] = $route['url'];
		}

		$expected = self::LIST_ROUTES;
		ksort($expected);
		ksort($lists);
		$this->assertSame($expected, $lists);

		$filters = new AppliedFilters();
		$queryService = $this->createMock(PublicationQueryServiceInterface::class);
		$queryService->method('assemblePublicSearchResults')->willReturn(['results' => [], 'total' => 0]);
		$publications = $this->createMock(PublicationServiceInterface::class);
		$publications->method('getAggregatedPublications')->willReturn(['results' => []]);

		$answers = [
			'publications#index' => $this->catalogueIndex(params: [], filters: $filters),
			'search#index' => $this->searchController(queryService: $queryService, filters: $filters)->index(),
			'federation#publications' => $this->federationController(publications: $publications, filters: $filters)->publications(),
		];
		foreach ($answers as $name => $response) {
			$this->assertSame(200, $response->getStatus(), $name);
			$this->assertArrayHasKey('appliedFilters', $response->getData(), $name);
		}
	}//end testEveryPublicListRouteCarriesAppliedFilters()

	/**
	 * `GET /api/woo` as an anonymous caller, over a real query service and the given collector.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 * @param AppliedFilters $filters The request's collector.
	 *
	 * @return \OCP\AppFramework\Http\JSONResponse The answer.
	 */
	private function catalogueIndex(array $params, AppliedFilters $filters): \OCP\AppFramework\Http\JSONResponse {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default));
		$request->method('getHeader')->willReturn('');
		$request->method('getMethod')->willReturn('GET');

		$catalogs = $this->createMock(CatalogiServiceInterface::class);
		$catalogs->method('getCatalogBySlug')->willReturn(['slug' => 'woo', 'title' => 'Woo', 'schemas' => [1, 2], 'registers' => [7]]);

		$container = $this->container();
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$queryService = new PublicationQueryService(container: $container, userSession: $session, appliedFilters: $filters);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['openregister']);

		$controller = new PublicationsController(
			'opencatalogi',
			$request,
			$this->createMock(PublicationServiceInterface::class),
			$catalogs,
			$queryService,
			$container,
			$appManager,
			$this->createMock(LoggerInterface::class),
			$this->l10n(),
			$this->createMock(UsageCounterServiceInterface::class),
			appliedFilters: $filters
		);

		return $controller->index(catalogSlug: 'woo');
	}//end catalogueIndex()

	/**
	 * A container with OpenRegister's ObjectService and mappers, recording each search.
	 *
	 * @return ContainerInterface The double.
	 */
	private function container(): ContainerInterface {
		$queries = &$this->queries;
		$objects = new class($queries) {
			public function __construct(
				private array &$queries,
			) {
			}

			public function buildSearchQuery(array $params): array {
				return $params;
			}

			public function searchObjectsPaginated(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->queries[] = $query;
				return ['results' => [['id' => 'p-1']], 'total' => 1];
			}
		};
		$schemas = $this->mapper(slugs: [1 => 'publication', 2 => 'besluit']);
		$registers = $this->mapper(slugs: [7 => 'woo-register']);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => match ($id) {
				'OCA\OpenRegister\Service\ObjectService' => $objects,
				'OCA\OpenRegister\Db\SchemaMapper' => $schemas,
				'OCA\OpenRegister\Db\RegisterMapper' => $registers,
				default => throw new RuntimeException('Unexpected service ' . $id),
			}
		);

		return $container;
	}//end container()

	/**
	 * A schema or register mapper answering entities with a slug and a public read rule.
	 *
	 * @param array<int, string> $slugs Slug per id.
	 *
	 * @return object The double.
	 */
	private function mapper(array $slugs): object {
		return new class($slugs) {
			public function __construct(
				private array $slugs,
			) {
			}

			public function find(int|string $id): object {
				$slug = $this->slugs[(int)$id];
				return new class((int)$id, $slug) {
					public function __construct(
						private int $id,
						private string $slug,
					) {
					}

					public function getId(): int {
						return $this->id;
					}

					public function getSlug(): string {
						return $this->slug;
					}

					public function getTitle(): string {
						return ucfirst($this->slug);
					}

					public function getAuthorization(): array {
						return ['read' => [['group' => 'public']]];
					}
				};
			}
		};
	}//end mapper()

	/**
	 * The search controller over a query-service double.
	 *
	 * @param PublicationQueryServiceInterface $queryService The query service.
	 * @param AppliedFilters $filters The request's collector.
	 *
	 * @return SearchController The controller.
	 */
	private function searchController(PublicationQueryServiceInterface $queryService, AppliedFilters $filters): SearchController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn([]);
		$request->method('getHeader')->willReturn('');
		$request->method('getMethod')->willReturn('GET');
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['openregister']);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn(new \stdClass());
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);

		return new SearchController(
			'opencatalogi',
			$request,
			$this->createMock(PublicationServiceInterface::class),
			$session,
			$this->l10n(),
			$queryService,
			$container,
			$appManager,
			$this->createMock(LoggerInterface::class),
			appliedFilters: $filters
		);
	}//end searchController()

	/**
	 * The federation controller over a publication-service double.
	 *
	 * @param PublicationServiceInterface $publications The publication service.
	 * @param AppliedFilters $filters The request's collector.
	 *
	 * @return FederationController The controller.
	 */
	private function federationController(PublicationServiceInterface $publications, AppliedFilters $filters): FederationController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn([]);

		return new FederationController($request, $publications, $this->l10n(), appliedFilters: $filters);
	}//end federationController()

	/**
	 * A translator that answers the source text.
	 *
	 * @return IL10N The double.
	 */
	private function l10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return $l10n;
	}//end l10n()

}//end class
