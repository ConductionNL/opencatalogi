<?php

/**
 * The changed-since read of a catalogue (OAI-006, OAI-007).
 *
 * Drives the real ChangesController over the real ChangesService. OpenRegister
 * is a small in-memory double that answers like its anonymous, RBAC-filtered
 * read: inside the anonymous scope it returns only public publications, and it
 * deliberately ignores the date filter, so the service's own window has to
 * hold. The DCAT mapping is a double that names each publication it maps.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/oai-pmh-endpoint/specs/oai-pmh-endpoint/spec.md
 */

declare(strict_types=1);

namespace Unit\Controller;

use DateTimeImmutable;
use OCA\OpenCatalogi\Contract\CatalogiServiceInterface;
use OCA\OpenCatalogi\Contract\DcatServiceInterface;
use OCA\OpenCatalogi\Contract\OpenRegisterLookupInterface;
use OCA\OpenCatalogi\Contract\PublicationQueryServiceInterface;
use OCA\OpenCatalogi\Controller\ChangesController;
use OCA\OpenCatalogi\Controller\CorsAllowlist;
use OCA\OpenCatalogi\Exception\MalformedChangesParameterException;
use OCA\OpenCatalogi\Service\CallerScope;
use OCA\OpenCatalogi\Service\CatalogScopeIds;
use OCA\OpenCatalogi\Service\ChangesService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for GET /api/{catalogSlug}/changes.
 */
#[CoversClass(ChangesController::class)]
#[CoversClass(ChangesService::class)]
#[UsesClass(MalformedChangesParameterException::class)]
#[UsesClass(CallerScope::class)]
#[UsesClass(CatalogScopeIds::class)]
#[UsesClass(CorsAllowlist::class)]
class ChangesEndpointTest extends TestCase {

	/**
	 * The moment of the read.
	 */
	private const NOW = '2026-11-13T12:00:00Z';

	/**
	 * The catalogue under test: register 1, schema 2.
	 *
	 * @var array<string, mixed>
	 */
	private const CATALOG = ['id' => 'cat-1', 'slug' => 'woo', 'registers' => [1], 'schemas' => [2]];

	/**
	 * The query parameters of the request.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	/**
	 * The in-memory OpenRegister.
	 *
	 * @var object
	 */
	private object $store;

	/**
	 * The DCAT mapping double.
	 *
	 * @var DcatServiceInterface&MockObject
	 */
	private DcatServiceInterface&MockObject $dcat;

	/**
	 * The catalogue lookup.
	 *
	 * @var CatalogiServiceInterface&MockObject
	 */
	private CatalogiServiceInterface&MockObject $catalogs;

	/**
	 * The URL generator.
	 *
	 * @var IURLGenerator&MockObject
	 */
	private IURLGenerator&MockObject $urls;

	/**
	 * Build the store, the doubles and the defaults.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new class {
			/**
			 * Stored publications: uuid, register, schema, updated, public.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $publications = [];

			/**
			 * Stored depublication records.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $depublications = [];

			/**
			 * Every search: its arguments and whether it ran anonymously.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $searches = [];

			/**
			 * The `next` the publication search answers.
			 *
			 * @var string|null
			 */
			public ?string $next = null;

			/**
			 * Whether a read runs inside the anonymous scope.
			 *
			 * @var bool
			 */
			private bool $anonymous = false;

			/**
			 * OpenRegister's anonymous evaluation scope.
			 *
			 * @param callable $operation The read.
			 *
			 * @return mixed
			 */
			public function runAsAnonymous(callable $operation): mixed {
				$this->anonymous = true;
				try {
					return $operation();
				} finally {
					$this->anonymous = false;
				}
			}

			/**
			 * The paginated search.
			 *
			 * @param array<string, mixed> $query The query.
			 * @param bool $_rbac Apply RBAC.
			 * @param bool $_multitenancy Apply multitenancy.
			 * @param bool $deleted Include deleted.
			 * @param array<int, string>|null $ids Restrict to these ids.
			 *
			 * @return array<string, mixed>
			 */
			public function searchObjectsPaginated(array $query = [], bool $_rbac = true, bool $_multitenancy = true, bool $deleted = false, ?array $ids = null): array {
				$this->searches[] = ['query' => $query, 'rbac' => $_rbac, 'anonymous' => $this->anonymous, 'ids' => $ids];

				if (($query['@self']['schema'] ?? null) === 'depublication') {
					return ['results' => $this->depublications];
				}

				$rows = [];
				foreach ($this->publications as $publication) {
					if ($_rbac === true && ($this->anonymous === false || $publication['public'] === false)) {
						continue;
					}

					if ($ids !== null && in_array($publication['uuid'], $ids, true) === false) {
						continue;
					}

					if ($publication['register'] !== 1 || $publication['schema'] !== 2) {
						continue;
					}

					$rows[] = [
						'title' => $publication['title'],
						'@self' => ['uuid' => $publication['uuid'], 'updated' => $publication['updated'], 'schema' => 2],
					];
				}

				return ['results' => $rows, 'next' => ($ids === null ? $this->next : null)];
			}
		};

		$lookup = $this->createMock(OpenRegisterLookupInterface::class);
		$lookup->method('installedObjectService')->willReturnCallback(fn (): object => $this->store);
		$lookup->method('registerConfiguration')->willReturn(['register' => 'publication', 'schema' => 'depublication']);

		$this->dcat = $this->createMock(DcatServiceInterface::class);
		$this->dcat->method('mapDatasets')->willReturnCallback(
			static fn (array $catalog, string $slug, array $publications): array => array_map(
				static fn (array $row): array => ['@id' => 'https://example.org/' . $slug . '/' . $row['@self']['uuid'], '@type' => 'dcat:Dataset'],
				$publications
			)
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('now')->willReturn(new DateTimeImmutable(self::NOW));

		$this->urls = $this->createMock(IURLGenerator::class);
		$this->urls->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route, array $args): string => 'https://cloud.example/' . $route . '?' . http_build_query($args)
		);

		$this->catalogs = $this->createMock(CatalogiServiceInterface::class);
		$this->catalogs->method('getCatalogBySlug')->willReturnCallback(
			static fn (string $slug): ?array => ($slug === 'woo' ? self::CATALOG : null)
		);

		$this->service = new ChangesService(
			openRegister: $lookup,
			dcat: $this->dcat,
			time: $time,
			urls: $this->urls,
			logger: $this->createMock(LoggerInterface::class)
		);
	}

	/**
	 * The service under test.
	 *
	 * @var ChangesService
	 */
	private ChangesService $service;

	/**
	 * A controller for the current params.
	 *
	 * @return ChangesController
	 */
	private function controller(): ChangesController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $key, mixed $default = null): mixed => ($this->params[$key] ?? $default)
		);
		$request->method('getHeader')->willReturn('');

		$guard = $this->createMock(PublicationQueryServiceInterface::class);
		$guard->method('applyCatalogReadRuleGuard')->willReturnArgument(0);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $args = []): string => vsprintf($text, $args));

		return new ChangesController(
			'opencatalogi',
			$request,
			$this->service,
			$this->catalogs,
			$guard,
			$l10n,
			$this->createMock(LoggerInterface::class),
			null
		);
	}

	/**
	 * Store a publication.
	 *
	 * @param string $uuid Its id.
	 * @param string $updated Its last change.
	 * @param bool $public Whether an anonymous visitor may read it.
	 * @param int $register Its register.
	 *
	 * @return void
	 */
	private function publication(string $uuid, string $updated, bool $public, int $register = 1): void {
		$this->store->publications[] = [
			'uuid' => $uuid,
			'title' => 'Title of ' . $uuid,
			'updated' => $updated,
			'public' => $public,
			'register' => $register,
			'schema' => 2,
		];
	}

	/**
	 * Ask the route.
	 *
	 * @param array<string, mixed> $params The query parameters.
	 * @param string $slug The catalogue slug.
	 *
	 * @return JSONResponse
	 */
	private function ask(array $params, string $slug = 'woo'): JSONResponse {
		$this->params = $params;
		return $this->controller()->changes($slug);
	}

	/**
	 * A catalogue with an edited public publication, an unchanged one and an edited draft.
	 *
	 * @return void
	 */
	public function testOnlyPublicRecordsChangedSinceAreListed(): void {
		$this->publication(uuid: 'edited', updated: '2026-11-13T09:00:00+00:00', public: true);
		$this->publication(uuid: 'unchanged', updated: '2026-10-13T09:00:00+00:00', public: true);
		$this->publication(uuid: 'draft', updated: '2026-11-13T10:00:00+00:00', public: false);

		$response = $this->ask(['since' => '2026-11-12T12:00:00Z']);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(
			[
				'changed' => [['@id' => 'https://example.org/woo/edited', '@type' => 'dcat:Dataset']],
				'withdrawn' => [],
				'since' => '2026-11-12T12:00:00Z',
				'until' => self::NOW,
				'next' => null,
			],
			$response->getData()
		);

		$read = $this->store->searches[0];
		$this->assertTrue($read['anonymous']);
		$this->assertTrue($read['rbac']);
		$this->assertSame(
			['gte' => '2026-11-12 12:00:00', 'lte' => '2026-11-13 12:00:00'],
			$read['query']['@self']['updated']
		);
		$this->assertSame(['updated' => 'asc', 'uuid' => 'asc'], $read['query']['_order']);
		$this->assertSame(ChangesService::PAGE_SIZE, $read['query']['_limit']);
	}

	/**
	 * Changed entries are ordered by their last change, then id.
	 *
	 * @return void
	 */
	public function testChangedIsOrderedByUpdatedThenId(): void {
		$this->publication(uuid: 'b', updated: '2026-11-13T09:00:00Z', public: true);
		$this->publication(uuid: 'c', updated: '2026-11-13T08:00:00Z', public: true);
		$this->publication(uuid: 'a', updated: '2026-11-13T09:00:00Z', public: true);

		$data = $this->ask(['since' => '2026-11-13'])->getData();

		$this->assertSame(
			['https://example.org/woo/c', 'https://example.org/woo/a', 'https://example.org/woo/b'],
			array_column($data['changed'], '@id')
		);
	}

	/**
	 * A moment that is not ISO 8601 is refused by name.
	 *
	 * @return void
	 */
	public function testAMalformedSinceIs400(): void {
		foreach ([['since' => 'gisteren'], [], ['since' => '2026-13-45'], ['since' => ['2026-11-12']]] as $params) {
			$response = $this->ask($params);

			$this->assertSame(400, $response->getStatus());
			$this->assertSame('since', $response->getData()['parameter']);
			$this->assertStringContainsString('since', $response->getData()['error']);
		}

		$this->assertSame([], $this->store->searches);
	}

	/**
	 * A cursor this read did not issue is refused by name.
	 *
	 * @return void
	 */
	public function testAForeignCursorIs400(): void {
		$response = $this->ask(['since' => '2026-11-12T00:00:00Z', 'cursor' => 'not-a-cursor']);

		$this->assertSame(400, $response->getStatus());
		$this->assertSame('cursor', $response->getData()['parameter']);
	}

	/**
	 * A page with more behind it links the next; the next page keeps the moment of the first read.
	 *
	 * @return void
	 */
	public function testPagesFollowTheCursor(): void {
		$this->publication(uuid: 'one', updated: '2026-11-13T09:00:00Z', public: true);
		$this->store->depublications[] = ['publication' => 'one', 'depublishedAt' => '2026-11-13T10:00:00Z'];
		$this->store->next = 'more';

		$first = $this->ask(['since' => '2026-11-12T00:00:00Z'])->getData();

		$this->assertNotNull($first['next']);
		parse_str((string)parse_url($first['next'], PHP_URL_QUERY), $query);
		$this->assertSame('woo', $query['catalogSlug']);
		$this->assertSame('2026-11-12T00:00:00Z', $query['since']);

		$this->store->next = null;
		$this->store->searches = [];
		$second = $this->ask(['since' => $query['since'], 'cursor' => $query['cursor']])->getData();

		$this->assertSame(2, $this->store->searches[0]['query']['_page']);
		$this->assertSame($first['until'], $second['until']);
		$this->assertSame([], $second['withdrawn']);
		$this->assertNull($second['next']);
		$this->assertCount(1, $this->store->searches);
	}

	/**
	 * A publication public last week and withdrawn today is named by id and date only.
	 *
	 * @return void
	 */
	public function testAWithdrawnRecordIsListedWithIdAndDateOnly(): void {
		$this->publication(uuid: 'withdrawn', updated: '2026-11-13T08:00:00Z', public: false);
		$this->store->depublications[] = [
			'publication' => 'withdrawn',
			'reason' => 'Published in error',
			'depublishedBy' => 'admin',
			'depublishedAt' => '2026-11-13T08:00:00+00:00',
		];
		$this->store->depublications[] = [
			'publication' => 'withdrawn',
			'depublishedAt' => '2026-11-11T08:00:00+00:00',
		];

		$data = $this->ask(['since' => '2026-11-12T12:00:00Z'])->getData();

		$this->assertSame([['id' => 'withdrawn', 'withdrawnAt' => '2026-11-13T08:00:00Z']], $data['withdrawn']);
		$this->assertSame([], $data['changed']);

		$depublicationRead = $this->store->searches[1];
		$this->assertFalse($depublicationRead['rbac']);
		$this->assertSame(['gte' => '2026-11-12T12:00:00Z'], $depublicationRead['query']['depublishedAt']);
		$this->assertSame(['withdrawn'], $this->store->searches[2]['ids']);
	}

	/**
	 * A draft deleted today, a document taken down, another catalogue's
	 * publication and an old withdrawal are never named.
	 *
	 * @return void
	 */
	public function testANeverPublicDraftIsNotListed(): void {
		$this->publication(uuid: 'elsewhere', updated: '2026-11-13T08:00:00Z', public: false, register: 9);
		$this->publication(uuid: 'with-document', updated: '2026-11-13T08:00:00Z', public: true);
		$this->publication(uuid: 'old', updated: '2026-11-01T08:00:00Z', public: false);
		$this->store->depublications[] = ['publication' => 'elsewhere', 'depublishedAt' => '2026-11-13T08:00:00Z'];
		$this->store->depublications[] = ['publication' => 'with-document', 'file' => 'f-1', 'depublishedAt' => '2026-11-13T08:00:00Z'];
		$this->store->depublications[] = ['publication' => 'old', 'depublishedAt' => '2026-11-01T08:00:00Z'];
		$this->store->depublications[] = ['publication' => '', 'depublishedAt' => '2026-11-13T08:00:00Z'];

		$data = $this->ask(['since' => '2026-11-12T12:00:00Z'])->getData();

		$this->assertSame([], $data['withdrawn']);
		$this->assertStringNotContainsString('draft', (string)json_encode($data));
	}

	/**
	 * An unknown catalogue is a 404, read from nowhere.
	 *
	 * @return void
	 */
	public function testAnUnknownCatalogueIs404(): void {
		$response = $this->ask(['since' => '2026-11-12T00:00:00Z'], slug: 'nope');

		$this->assertSame(404, $response->getStatus());
		$this->assertSame([], $this->store->searches);
	}

	/**
	 * Without OpenRegister's anonymous scope the read is refused, not run with the session.
	 *
	 * @return void
	 */
	public function testWithoutTheAnonymousScopeTheReadIsRefused(): void {
		$this->store = new class {
			/**
			 * Searches made.
			 *
			 * @var int
			 */
			public int $calls = 0;

			/**
			 * A search that must not run.
			 *
			 * @return array<string, mixed>
			 */
			public function searchObjectsPaginated(): array {
				$this->calls++;
				return ['results' => []];
			}
		};

		$response = $this->ask(['since' => '2026-11-12T00:00:00Z']);

		$this->assertSame(503, $response->getStatus());
		$this->assertSame(0, $this->store->calls);
	}

	/**
	 * An empty catalogue scope reads nothing, never the whole register.
	 *
	 * @return void
	 */
	public function testAnEmptyScopeReadsNothing(): void {
		$answer = $this->service->changesSince(
			catalog: ['registers' => [], 'schemas' => []],
			catalogSlug: 'woo',
			since: new DateTimeImmutable('2026-11-12T00:00:00Z')
		);

		$this->assertSame([], $answer['changed']);
		$this->assertSame([], $answer['withdrawn']);
		$this->assertSame([], $this->store->searches);
	}

	/**
	 * The answers carry the read-only CORS headers, and the preflight allows GET only.
	 *
	 * @return void
	 */
	public function testTheRouteAnswersCrossOriginReadsOnly(): void {
		$response = $this->ask(['since' => 'gisteren']);
		$this->assertSame('GET, OPTIONS', $response->getHeaders()['Access-Control-Allow-Methods']);

		$preflight = $this->controller()->preflightedCors();
		$this->assertSame('GET, OPTIONS', $preflight->getHeaders()['Access-Control-Allow-Methods']);
	}

	/**
	 * The route is registered before the publication wildcard, with its preflight.
	 *
	 * @return void
	 */
	public function testTheRouteIsRegisteredBeforeThePublicationWildcard(): void {
		$routes = (require __DIR__ . '/../../../appinfo/routes.php')['routes'];
		$position = static function (string $name, string $verb) use ($routes): int {
			foreach ($routes as $index => $route) {
				if ($route['name'] === $name && $route['verb'] === $verb) {
					return $index;
				}
			}

			return -1;
		};

		$get = $position('changes#changes', 'GET');
		$this->assertGreaterThanOrEqual(0, $get);
		$this->assertStringEndsWith('/{catalogSlug}/changes', $routes[$get]['url']);
		$this->assertLessThan($position('publications#show', 'GET'), $get);

		$options = $position('changes#preflightedCors', 'OPTIONS');
		$this->assertGreaterThanOrEqual(0, $options);
		$this->assertLessThan($position('publications#preflightedCors', 'OPTIONS') + 2, $options);
		$this->assertSame($routes[$get]['url'], $routes[$options]['url']);
	}
}
