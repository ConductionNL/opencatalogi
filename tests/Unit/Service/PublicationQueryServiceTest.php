<?php

/**
 * Unit tests for PublicationQueryService.
 *
 * Covers the WOO-536 Fase-5 refactor of `assemblePublicSearchResults`
 * (catalog-derived scope, `_rbacAsPublic` uniform visibility, dynamic
 * schema discriminator, N4a document-transitive visibility), the M2
 * fast-path added 2026-08-31 that resolves document→publication via
 * the document's OWN `_relations` (bug 1 fix), the pure helpers
 * (`isCatalogPubliclyAvailable`, `normalizeIds`, `stripEmptyValues`),
 * and `findObjectLocation` (the constrained object-location query).
 *
 * The service is thin over OpenRegister — most branches boil down to
 * "did the right query reach the right OR method with `_rbacAsPublic:
 * true`?". These tests mock the container and the OR ObjectService
 * surface via a stub; the SQL/RBAC pathway is out of scope. The
 * integration smoke script covers the wire-level behaviour end-to-end.
 *
 * @spec openspec/specs/search/spec.md
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\PublicationQueryService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Fake OpenRegister ObjectService double for `assemblePublicSearchResults`
 * tests. Captures the query passed to `searchObjectsPaginated` and the
 * `_rbacAsPublic` flag so scope-enforcement can be asserted. Serves multiple
 * queued response sets to cover per-request candidate + document-refinement
 * calls.
 */
class FakeSearchObjectService {

	/** @var array<int, array<string, mixed>> Captured (query, flags) per invocation. */
	public array $capturedCalls = [];

	/** @var array<int, array{results: array, total: int, facets: array, facetable: array}> Queued response payloads. */
	public array $queuedResponses = [];

	/** @var int Cursor into $queuedResponses. */
	private int $callCursor = 0;

	public function buildSearchQuery(array $requestParams): array {
		// Passes request params through unchanged so scope-override tests can
		// prove `assemblePublicSearchResults` strips scope-widening keys.
		return $requestParams;
	}

	public function searchObjectsPaginated(
		array $query,
		bool $_rbac = true,
		bool $_multitenancy = true,
		bool $_rbacAsPublic = false,
	): array {
		$this->capturedCalls[] = [
			'query'         => $query,
			'_rbac'         => $_rbac,
			'_multitenancy' => $_multitenancy,
			'_rbacAsPublic' => $_rbacAsPublic,
		];

		$response = $this->queuedResponses[$this->callCursor] ?? ['results' => [], 'total' => 0, 'facets' => [], 'facetable' => []];
		$this->callCursor++;
		return $response;
	}

	/**
	 * Emulate the `find()` shape used by the UUID fast-path. Configure a
	 * fixed mapping id => row-array before use.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $findResponses = [];

	public function find(
		string $id,
		array $_extend = [],
		bool $files = false,
		?int $register = null,
		?int $schema = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
		bool $_rbacAsPublic = false,
	): ?array {
		return $this->findResponses[$id] ?? null;
	}
}

/**
 * Fake OC CatalogiService double so `resolveCatalogScope` can enumerate
 * a canned catalog set without touching the DB.
 */
class FakeCatalogiService {

	/** @var array<string, array<string, mixed>> Catalogs to return, keyed by slug. */
	public array $catalogsBySlug = [];

	public function getCatalogBySlug(string $slug): ?array {
		return ($this->catalogsBySlug[$slug] ?? null);
	}

	public function getObjectService(): FakeCatalogObjectService {
		$svc = new FakeCatalogObjectService();
		$svc->catalogs = array_values($this->catalogsBySlug);
		return $svc;
	}
}

class FakeCatalogObjectService {

	/** @var array<int, array<string, mixed>> */
	public array $catalogs = [];

	public function searchObjects(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
		return $this->catalogs;
	}

	public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
		return $this->catalogs;
	}
}

/**
 * Fake OR SchemaMapper double so the dynamic-schema-discriminator step
 * (Fase-5 Stap 3) can resolve slug from id.
 */
class FakeSchemaMapper {

	/** @var array<int, string> id => slug map. */
	public array $slugById = [];

	public function find(int|string $id): object {
		$slug = ($this->slugById[(int) $id] ?? "schema-{$id}");
		return new class ((int) $id, $slug) {
			public function __construct(private int $id, private string $slug) {}
			public function getId(): int { return $this->id; }
			public function getSlug(): string { return $this->slug; }
			public function getTitle(): string { return ucfirst($this->slug); }
		};
	}
}

/**
 * Unit tests for the WOO-536 Fase-5 refactor.
 */
class PublicationQueryServiceTest extends TestCase {

	private ContainerInterface|MockObject $container;
	private ObjectService|MockObject $objectService;
	private IAppConfig|MockObject $config;
	private LoggerInterface|MockObject $logger;
	private PublicationQueryService $service;

	protected function setUp(): void {
		$this->container = $this->createMock(ContainerInterface::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->config = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->service = new PublicationQueryService(
			container: $this->container,
			config: $this->config,
			logger: $this->logger,
		);
	}

	// -------------------------------------------------------------------------
	// Pure helpers — no container, no OR.
	// -------------------------------------------------------------------------

	public function testStripEmptyValuesDropsEmptyStringsNullsEmptyArrays(): void {
		$in = ['a' => 'x', 'b' => '', 'c' => null, 'd' => [], 'e' => 0];
		$this->assertSame(['a' => 'x', 'e' => 0], $this->service->stripEmptyValues($in));
	}

	public function testStripEmptyValuesRecursesIntoNestedArrays(): void {
		$in = ['outer' => ['keep' => 'x', 'drop' => '']];
		$this->assertSame(['outer' => ['keep' => 'x']], $this->service->stripEmptyValues($in));
	}

	public function testNormalizeIdsAcceptsJsonStringAndArrayAndCastsToInt(): void {
		$this->assertSame([1, 2], $this->invokePrivate('normalizeIds', ['[1,2]']));
		$this->assertSame([1, 2], $this->invokePrivate('normalizeIds', ['["1","2"]']));
		$this->assertSame([1, 2], $this->invokePrivate('normalizeIds', [[1, '2']]));
		$this->assertSame([], $this->invokePrivate('normalizeIds', [null]));
		$this->assertSame([], $this->invokePrivate('normalizeIds', ['not-json']));
	}

	public function testIsCatalogPubliclyAvailableAcceptsPublishedInPast(): void {
		$this->assertTrue($this->invokePrivate('isCatalogPubliclyAvailable', [['published' => '2020-01-01T00:00:00+00:00']]));
	}

	public function testIsCatalogPubliclyAvailableRejectsFuturePublished(): void {
		$future = (new \DateTimeImmutable('+1 year'))->format('c');
		$this->assertFalse($this->invokePrivate('isCatalogPubliclyAvailable', [['published' => $future]]));
	}

	public function testIsCatalogPubliclyAvailableRejectsMissingPublished(): void {
		$this->assertFalse($this->invokePrivate('isCatalogPubliclyAvailable', [[]]));
		$this->assertFalse($this->invokePrivate('isCatalogPubliclyAvailable', [['published' => null]]));
		$this->assertFalse($this->invokePrivate('isCatalogPubliclyAvailable', [['published' => '']]));
	}

	public function testIsCatalogPubliclyAvailableRejectsMalformedDate(): void {
		$this->assertFalse($this->invokePrivate('isCatalogPubliclyAvailable', [['published' => 'not-a-date']]));
	}

	// -------------------------------------------------------------------------
	// assemblePublicSearchResults — fail-closed empty scope path.
	// -------------------------------------------------------------------------

	public function testAssembleReturnsEmptyEnvelopeWhenScopeResolvesToNoSchemas(): void {
		// Container has no CatalogiService — resolveCatalogScope returns empty schemas.
		$this->container->method('get')->willThrowException(new \RuntimeException('no CatalogiService'));

		// Warning fires from either the CatalogiService-unavailable branch or the
		// outer fail-closed "empty envelope" branch. Both are acceptable observability
		// signals for the same downstream fact (scope resolves to nothing).
		$this->logger->expects($this->atLeastOnce())->method('warning');

		$fake = new FakeSearchObjectService();
		$result = $this->service->assemblePublicSearchResults(['_search' => 'anything'], $fake);

		$this->assertSame(['results' => [], 'total' => 0], $result);
		$this->assertEmpty($fake->capturedCalls, 'searchObjectsPaginated MUST NOT run without a resolved scope.');
	}

	// -------------------------------------------------------------------------
	// assemblePublicSearchResults — happy path with mocked OR + catalog.
	// -------------------------------------------------------------------------

	/**
	 * SCH-PFTS-001 / SCH-PFTS-004: the endpoint MUST forward `_rbac: true`
	 * AND `_rbacAsPublic: true` to OR, so RBAC evaluates the public group
	 * only — admin bypass suppressed, `_owner` OR-in suppressed. Q1 Option B.
	 */
	public function testAssembleForwardsRbacAsPublicTrueToObjectService(): void {
		$fake = $this->wireHappyPath();
		$this->service->assemblePublicSearchResults($this->withDefaultCatalog(['_search' => 'x']), $fake);

		$this->assertNotEmpty($fake->capturedCalls, 'searchObjectsPaginated was not called');
		$first = $fake->capturedCalls[0];
		$this->assertTrue($first['_rbac'], '_rbac must be true');
		$this->assertTrue($first['_rbacAsPublic'], '_rbacAsPublic must be true (Q1 Option B)');
		$this->assertFalse($first['_multitenancy'], 'multitenancy must be false on public endpoint');
	}

	/**
	 * Q7 Interpretation A: client-supplied `_schema` / `_registers` / `fq` /
	 * `catalogSlug` / `_content` / OC-level `_catalog` / `_catalogi` MUST be
	 * stripped before the query reaches OR — a caller cannot widen scope
	 * past the resolved catalog boundary.
	 */
	public function testAssembleStripsClientScopeWideningParams(): void {
		$fake = $this->wireHappyPath();

		// Note: _catalog=default-catalog (present in fixture) is the OC-level scope
		// resolver; every other listed key MUST be stripped before reaching OR.
		$this->service->assemblePublicSearchResults([
			'_search'     => 'x',
			'_schema'     => 999,
			'_registers'  => [999],
			'catalogSlug' => 'evil',
			'fq'          => 'evil',
			'_content'    => 'true',
			'_catalog'    => 'default-catalog',
			'_catalogi'   => ['a', 'b'],
		], $fake);

		$captured = $fake->capturedCalls[0]['query'];
		$this->assertArrayNotHasKey('_schema', $captured);
		$this->assertArrayNotHasKey('_registers', $captured);
		$this->assertArrayNotHasKey('catalogSlug', $captured);
		$this->assertArrayNotHasKey('fq', $captured);
		$this->assertArrayNotHasKey('_content', $captured);
		$this->assertArrayNotHasKey('_catalog', $captured);
		$this->assertArrayNotHasKey('_catalogi', $captured);
		// Scope-derived _schemas MUST be present, sourced from the catalog fixture.
		$this->assertArrayHasKey('_schemas', $captured);
		$this->assertContains(1, $captured['_schemas']);
	}

	/**
	 * SCH-PFTS-CAT-002: default-scope catalog fixture in wireHappyPath declares
	 * schemas [1,2] on register 1. Those MUST propagate to the OR query.
	 */
	public function testAssembleAppliesResolvedScopeAsSchemas(): void {
		$fake = $this->wireHappyPath();
		$this->service->assemblePublicSearchResults($this->withDefaultCatalog(), $fake);

		$captured = $fake->capturedCalls[0]['query'];
		$this->assertSame([1, 2], $captured['_schemas']);
		$this->assertSame(1, $captured['_register'], 'single-register scope should use _register fast-path');
		$this->assertFalse($captured['_includeDeleted'], 'deleted rows must be excluded on public endpoint');
	}

	// -------------------------------------------------------------------------
	// Envelope-shape (N2 / SCH-PFTS-002).
	// -------------------------------------------------------------------------

	public function testEnvelopeCarriesFacetsWhenOrProvidesThem(): void {
		$fake = $this->wireHappyPath();
		$fake->queuedResponses = [
			['results' => [], 'total' => 0, 'facets' => ['theme' => []], 'facetable' => ['title']],
		];

		$out = $this->service->assemblePublicSearchResults($this->withDefaultCatalog(), $fake);

		$this->assertArrayHasKey('results', $out);
		$this->assertArrayHasKey('total', $out);
		$this->assertArrayHasKey('facets', $out, 'facets pass-through when OR returned them');
		$this->assertArrayHasKey('facetable', $out, 'facetable pass-through when OR returned it');
	}

	// -------------------------------------------------------------------------
	// Envelope `total` — pagination signal + `total ≥ count(results)` invariant.
	// -------------------------------------------------------------------------

	/**
	 * Pagination signal: when OR's global `total` exceeds the current page's
	 * `_limit` and NOTHING is dropped in the row loop, the envelope MUST ship
	 * OR's global total so consumers can compute `has_more`.
	 */
	public function testEnvelopeTotalPreservesGlobalCountWhenNothingDropped(): void {
		$publicationRow = [
			'@self' => ['id' => 'pub-1', 'slug' => 'p1', 'schema' => 1],
			'title' => 'Page 1 publication',
		];
		$fake = $this->wireHappyPath();
		// OR reports total: 42 across all pages; this page has 1 row and nothing drops.
		$fake->queuedResponses = [
			['results' => [$publicationRow], 'total' => 42, 'facets' => [], 'facetable' => []],
		];

		$out = $this->service->assemblePublicSearchResults($this->withDefaultCatalog(), $fake);

		$this->assertCount(1, $out['results'], 'row loop keeps the visible pub');
		$this->assertSame(42, $out['total'], 'total must reflect OR global count so pagination has_more works');
	}

	/**
	 * `total ≥ count(results)` invariant: when the row loop drops rows on this
	 * page (e.g. N4a transitive-visibility on a document whose linked pub isn't
	 * public), `total` is reduced by the drops but MUST NOT dip below the
	 * shipped `results` count. Guarantees `total: X, results: []` never happens.
	 */
	public function testEnvelopeTotalSubtractsPerPageDropsButFloorsAtResultsLength(): void {
		$documentRow = [
			'@self' => ['id' => 'doc-orphan-page1', 'schema' => 2, 'relations' => ['organization' => 'x']],
			'title' => 'Orphan doc on page 1',
		];
		$fake = $this->wireHappyPath();
		// OR reports total: 5 globally; this page returns 1 candidate that N4a-drops.
		$fake->queuedResponses = [
			['results' => [$documentRow], 'total' => 5, 'facets' => [], 'facetable' => []],
			// _relations_contains refinement → 0 pubs → drops the doc.
			['results' => [], 'total' => 0, 'facets' => [], 'facetable' => []],
		];

		$out = $this->service->assemblePublicSearchResults($this->withDefaultCatalog(), $fake);

		$this->assertCount(0, $out['results'], 'orphan doc dropped');
		// 5 (or_total) - 1 (drop) = 4 → floored to max(0, 4) = 4. Pagination signal preserved.
		$this->assertSame(4, $out['total'], 'total = or_total - drops (floored at count(results))');
	}

	/**
	 * When `or_total == drops_on_this_page`, `total` MUST reach 0 (not
	 * remain at OR's pre-drop count). Guards against the SCH-PFTS-004
	 * `total: 1, results: []` bug pattern.
	 */
	public function testEnvelopeTotalGoesToZeroWhenAllOrCandidatesDrop(): void {
		$documentRow = [
			'@self' => ['id' => 'doc-only-invisible', 'schema' => 2, 'relations' => ['organization' => 'x']],
			'title' => 'Only doc is invisible',
		];
		$fake = $this->wireHappyPath();
		$fake->queuedResponses = [
			['results' => [$documentRow], 'total' => 1, 'facets' => [], 'facetable' => []],
			['results' => [], 'total' => 0, 'facets' => [], 'facetable' => []],
		];

		$out = $this->service->assemblePublicSearchResults($this->withDefaultCatalog(), $fake);

		$this->assertSame(['results' => [], 'total' => 0], ['results' => $out['results'], 'total' => $out['total']]);
	}

	/**
	 * Dedup drops (same `@self.id` twice — metadata + chunk hit) MUST NOT
	 * count against `total`. OR reports these as separate candidates but
	 * the dedup collapses one logical hit; treating dedup as a drop would
	 * silently shrink pagination totals on `_content=true` pages.
	 */
	public function testEnvelopeTotalIgnoresDedupDrops(): void {
		$publicationRow = [
			'@self' => ['id' => 'pub-dup', 'slug' => 'dup', 'schema' => 1],
			'title' => 'Duplicate publication row',
		];
		$fake = $this->wireHappyPath();
		// OR returned same pub twice (metadata + content match); dedup collapses.
		$fake->queuedResponses = [
			['results' => [$publicationRow, $publicationRow], 'total' => 10, 'facets' => [], 'facetable' => []],
		];

		$out = $this->service->assemblePublicSearchResults($this->withDefaultCatalog(), $fake);

		$this->assertCount(1, $out['results'], 'dedup collapses to one row');
		$this->assertSame(10, $out['total'], 'dedup drop must NOT be subtracted from total');
	}

	// -------------------------------------------------------------------------
	// M2 fast-path (bug 1 fix, 2026-08-31).
	// SCH-PFTS-004 transitive visibility with the legacy document seed shape.
	// -------------------------------------------------------------------------

	/**
	 * Document rows may store the linked publication as
	 * `_relations['publication.slug']` (denormalised WOO-506/517 seed shape).
	 * The M2 fast-path MUST resolve the linked publication by slug and keep
	 * the document row in the envelope with an embedded `publication` summary.
	 * Regression guard for the bug 1 undercount (total != results len).
	 */
	public function testDocumentWithPublicationSlugRelationIsResolvedAndKept(): void {
		$publicationRow = [
			'@self' => ['id' => 'pub-uuid-1', 'slug' => 'my-report', 'schema' => 1],
			'title' => 'My Report',
		];
		$documentRow = [
			'@self' => [
				'id'        => 'doc-uuid-1',
				'schema'    => 2,
				'relations' => ['publication.slug' => 'my-report'],
			],
			'title' => 'My Report (PDF)',
		];

		$fake = $this->wireHappyPath();
		// 1st call: initial candidate paginated search returns doc row.
		// 2nd call: M2 slug-scan on publication schema returns pub row.
		$fake->queuedResponses = [
			['results' => [$documentRow], 'total' => 1, 'facets' => [], 'facetable' => []],
			['results' => [$publicationRow], 'total' => 1, 'facets' => [], 'facetable' => []],
		];

		$out = $this->service->assemblePublicSearchResults($this->withDefaultCatalog(), $fake);

		$this->assertCount(1, $out['results'], 'document must survive N4a because its slug-linked pub is public');
		$emitted = $out['results'][0];
		$this->assertSame('doc-uuid-1', $emitted['@self']['id']);
		$this->assertArrayHasKey('publication', $emitted, 'document row must carry embedded publication summary');
		$this->assertSame('my-report', $emitted['publication']['slug']);
		$this->assertSame('My Report', $emitted['publication']['title']);
	}

	/**
	 * Documents storing the linked publication as `_relations['publication']`
	 * (UUID form, canonical/new) MUST resolve via the M1 UUID fast-path
	 * (`ObjectService::find()`) without spending a slug scan.
	 */
	public function testDocumentWithPublicationUuidRelationUsesFastPathAndKeeps(): void {
		$publicationRow = [
			'@self' => ['id' => 'pub-uuid-2', 'slug' => 'kb-artikel', 'schema' => 1],
			'title' => 'Kennisbank artikel',
		];
		$documentRow = [
			'@self' => [
				'id'        => 'doc-uuid-2',
				'schema'    => 2,
				'relations' => ['publication' => 'pub-uuid-2'],
			],
			'title' => 'KB artikel (PDF)',
		];

		$fake = $this->wireHappyPath();
		$fake->queuedResponses = [
			['results' => [$documentRow], 'total' => 1, 'facets' => [], 'facetable' => []],
		];
		$fake->findResponses['pub-uuid-2'] = $publicationRow;

		$out = $this->service->assemblePublicSearchResults($this->withDefaultCatalog(), $fake);

		$this->assertCount(1, $out['results'], 'document must survive via UUID fast-path');
		$this->assertSame('kb-artikel', $out['results'][0]['publication']['slug']);
	}

	/**
	 * N4a: document without any resolvable publication link MUST be
	 * silent-dropped from the envelope (with an info log for observability).
	 * Envelope-shape (N2) preserved: no `publication: null` sentinel.
	 */
	public function testDocumentWithoutAnyPublicationRelationIsSilentDropped(): void {
		$documentRow = [
			'@self' => ['id' => 'doc-orphan', 'schema' => 2, 'relations' => ['organization' => 'x']],
			'title' => 'Orphan PDF',
		];

		$fake = $this->wireHappyPath();
		$fake->queuedResponses = [
			['results' => [$documentRow], 'total' => 1, 'facets' => [], 'facetable' => []],
		];

		$this->logger->expects($this->atLeastOnce())
			->method('info')
			->with($this->stringContains('no publicly-visible linked publication'), $this->anything());

		$out = $this->service->assemblePublicSearchResults($this->withDefaultCatalog(), $fake);

		$this->assertCount(0, $out['results'], 'orphan document must be silent-dropped (N4a)');
	}

	/**
	 * RET-006 belt-and-braces on the UUID fast-path: a document pointing at
	 * a publication with `status: 'archived'` MUST be dropped, even though
	 * `_rbacAsPublic: true` would have kept the row visible under a
	 * mis-configured schema RBAC. The linked publication's terminal-hidden
	 * status is a second gate.
	 */
	public function testDocumentDroppedWhenUuidFastPathPublicationIsArchived(): void {
		$archivedPublication = [
			'@self'  => ['id' => 'pub-archived-1', 'slug' => 'shelved-report', 'schema' => 1],
			'title'  => 'Archived report',
			'status' => 'archived',
		];
		$documentRow = [
			'@self' => [
				'id'        => 'doc-uuid-archived',
				'schema'    => 2,
				'relations' => ['publication' => 'pub-archived-1'],
			],
			'title' => 'PDF pointing at archived pub',
		];

		$fake = $this->wireHappyPath();
		$fake->queuedResponses = [
			['results' => [$documentRow], 'total' => 1, 'facets' => [], 'facetable' => []],
			// After the UUID fast-path returns null (archived), the code falls through
			// to the `_relations_contains` refinement, which finds nothing either.
			['results' => [], 'total' => 0, 'facets' => [], 'facetable' => []],
		];
		$fake->findResponses['pub-archived-1'] = $archivedPublication;

		$out = $this->service->assemblePublicSearchResults($this->withDefaultCatalog(), $fake);

		$this->assertCount(0, $out['results'], 'document with archived linked publication must drop (UUID fast-path RET-006)');
	}

	/**
	 * RET-006 belt-and-braces on the slug fast-path: a document whose
	 * `_relations['publication.slug']` resolves to an archived publication
	 * MUST be dropped. Same rule as the UUID fast-path.
	 */
	public function testDocumentDroppedWhenSlugFastPathPublicationIsArchived(): void {
		$archivedPublication = [
			'@self'  => ['id' => 'pub-archived-2', 'slug' => 'shelved-slug', 'schema' => 1],
			'title'  => 'Archived legacy report',
			'status' => 'archived',
		];
		$documentRow = [
			'@self' => [
				'id'        => 'doc-slug-archived',
				'schema'    => 2,
				'relations' => ['publication.slug' => 'shelved-slug'],
			],
			'title' => 'Legacy PDF pointing at archived pub',
		];

		$fake = $this->wireHappyPath();
		$fake->queuedResponses = [
			['results' => [$documentRow],         'total' => 1, 'facets' => [], 'facetable' => []],
			['results' => [$archivedPublication], 'total' => 1, 'facets' => [], 'facetable' => []],
			// Slug fast-path returned null → fall through to `_relations_contains`, which finds nothing.
			['results' => [], 'total' => 0, 'facets' => [], 'facetable' => []],
		];

		$out = $this->service->assemblePublicSearchResults($this->withDefaultCatalog(), $fake);

		$this->assertCount(0, $out['results'], 'document with archived slug-linked publication must drop (slug fast-path RET-006)');
	}

	/**
	 * D2 slug cache: N documents in one page pointing at the same
	 * `_relations['publication.slug']` MUST trigger the slug scan exactly
	 * once — subsequent hits are memoised. Guards against the O(N × 500)
	 * regression on legacy-seed hot pages.
	 */
	public function testSlugFastPathIsCachedAcrossDocumentsInOnePage(): void {
		$sharedPublication = [
			'@self' => ['id' => 'pub-shared', 'slug' => 'shared-slug', 'schema' => 1],
			'title' => 'Shared publication',
		];
		$documentRows = [];
		for ($i = 1; $i <= 3; $i++) {
			$documentRows[] = [
				'@self' => [
					'id'        => "doc-shared-{$i}",
					'schema'    => 2,
					'relations' => ['publication.slug' => 'shared-slug'],
				],
				'title' => "Legacy PDF #{$i}",
			];
		}

		$fake = $this->wireHappyPath();
		// Only TWO responses queued: (1) the candidate page with 3 docs,
		// (2) the ONE slug-scan for 'shared-slug'. If the cache is broken,
		// the second and third docs would consume queued response slots
		// that don't exist and fall through to the `_relations_contains`
		// path with an empty default response — the test would still
		// pass results-count but capturedCalls would show > 2.
		$fake->queuedResponses = [
			['results' => $documentRows,         'total' => 3, 'facets' => [], 'facetable' => []],
			['results' => [$sharedPublication],  'total' => 1, 'facets' => [], 'facetable' => []],
		];

		$out = $this->service->assemblePublicSearchResults($this->withDefaultCatalog(), $fake);

		$this->assertCount(3, $out['results'], 'all 3 docs must resolve via one cached slug lookup');
		// Exactly 2 OR calls: the initial candidate paginate + one slug scan.
		$this->assertCount(2, $fake->capturedCalls, 'slug scan must run exactly once for 3 docs sharing a slug (D2 cache)');
	}

	// -------------------------------------------------------------------------
	// findObjectLocation — constrained (register × schema) lookup (#734).
	// -------------------------------------------------------------------------

	/**
	 * Security (#734): findObjectLocation MUST return null without touching
	 * OpenRegister when no constraint is supplied.
	 */
	public function testFindObjectLocationFailsClosedWithoutConstraint(): void {
		$this->container->expects($this->never())->method('get');

		$this->assertNull($this->service->findObjectLocation('any-uuid'));
		$this->assertNull($this->service->findObjectLocation(uuid: 'any-uuid', allowedRegisters: [], allowedSchemas: []));
		$this->assertNull($this->service->findObjectLocation(uuid: 'any-uuid', allowedRegisters: [1], allowedSchemas: []));
		$this->assertNull($this->service->findObjectLocation(uuid: 'any-uuid', allowedRegisters: [], allowedSchemas: [1]));
	}

	/**
	 * Constrained lookup locates the object via ObjectService::find within the
	 * allowed (register × schema) pair — no raw SQL against OR storage internals.
	 *
	 * @spec openspec/specs/opencatalogi-adopt-or-abstractions/spec.md
	 */
	public function testFindObjectLocationLocatesViaObjectService(): void {
		$this->container->method('get')->willReturnCallback(
			fn(string $id) => $id === 'OCA\OpenRegister\Service\ObjectService' ? $this->objectService : null
		);

		$entity = $this->createMock(ObjectEntity::class);
		$this->objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend, bool $files, $register, $schema) use ($entity) {
				return ((int) $register === 21 && (int) $schema === 11) ? $entity : null;
			}
		);

		$location = $this->service->findObjectLocation(
			uuid: 'uuid-found',
			allowedRegisters: [20, 21],
			allowedSchemas: [10, 11],
		);
		$this->assertSame(['register' => 21, 'schema' => 11], $location);
	}

	public function testFindObjectLocationReturnsNullWhenNotFound(): void {
		$this->container->method('get')->willReturn($this->objectService);
		$this->objectService->method('find')->willReturn(null);

		$this->assertNull($this->service->findObjectLocation(uuid: 'uuid-missing', allowedRegisters: [1], allowedSchemas: [2]));
	}

	public function testFindObjectLocationContinuesPastMissingPair(): void {
		$this->container->method('get')->willReturn($this->objectService);

		$entity = $this->createMock(ObjectEntity::class);
		$this->objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend, bool $files, $register, $schema) use ($entity) {
				if ((int) $register === 1 && (int) $schema === 2) {
					throw new \OCP\AppFramework\Db\DoesNotExistException('missing table');
				}
				return ((int) $register === 1 && (int) $schema === 3) ? $entity : null;
			}
		);

		$location = $this->service->findObjectLocation(uuid: 'uuid-found', allowedRegisters: [1], allowedSchemas: [2, 3]);
		$this->assertSame(['register' => 1, 'schema' => 3], $location);
	}

	// -------------------------------------------------------------------------
	// Test scaffolding.
	// -------------------------------------------------------------------------

	/**
	 * Wire the DI container with the minimum services `assemblePublicSearchResults`
	 * needs to reach the row-loop: CatalogiService (default catalog with schemas
	 * [1,2] on register 1) + SchemaMapper (publication=1, document=2). Returns
	 * the FakeSearchObjectService that the test passes into the service call
	 * (also registered under the ObjectService key for the per-doc lookups).
	 */
	private function wireHappyPath(): FakeSearchObjectService {
		$fakeObjectService = new FakeSearchObjectService();

		$catalog = [
			'@self'     => ['slug' => 'default-catalog'],
			'slug'      => 'default-catalog',
			'listed'    => true,
			'published' => '2020-01-01T00:00:00+00:00',
			'registers' => [1],
			'schemas'   => [1, 2],
		];

		$fakeCatalogiService = new FakeCatalogiService();
		$fakeCatalogiService->catalogsBySlug = ['default-catalog' => $catalog];

		$fakeSchemaMapper = new FakeSchemaMapper();
		$fakeSchemaMapper->slugById = [1 => 'publication', 2 => 'document'];

		// Config keys needed by the default-scope enumeration path; safe to set
		// even for _catalog=<slug> tests since that path doesn't consult them.
		$this->config->method('getValueString')->willReturnCallback(
			fn(string $app, string $key, string $default = '') => match ($key) {
				'catalog_register' => '1',
				'catalog_schema'   => '3',
				default => $default,
			}
		);

		$this->container->method('get')->willReturnCallback(
			function (string $key) use ($fakeCatalogiService, $fakeSchemaMapper, $fakeObjectService) {
				return match ($key) {
					'OCA\\OpenCatalogi\\Service\\CatalogiService' => $fakeCatalogiService,
					'OCA\\OpenRegister\\Db\\SchemaMapper'         => $fakeSchemaMapper,
					'OCA\\OpenRegister\\Service\\ObjectService'   => $fakeObjectService,
					default => throw new \RuntimeException("Unmocked container key: {$key}"),
				};
			}
		);

		return $fakeObjectService;
	}

	/**
	 * Add `_catalog=default-catalog` to a query params array so tests reliably
	 * hit the explicit-catalog scope resolution (bypassing the default-scope
	 * enumeration that requires additional config plumbing).
	 */
	private function withDefaultCatalog(array $params = []): array {
		return $params + ['_catalog' => 'default-catalog'];
	}

	/**
	 * Invoke a private/protected method by name via reflection.
	 *
	 * @param string $method Method name.
	 * @param array  $args   Positional arguments.
	 *
	 * @return mixed
	 */
	private function invokePrivate(string $method, array $args): mixed {
		$ref = new ReflectionClass($this->service);
		$m = $ref->getMethod($method);
		$m->setAccessible(true);
		return $m->invokeArgs($this->service, $args);
	}
}
