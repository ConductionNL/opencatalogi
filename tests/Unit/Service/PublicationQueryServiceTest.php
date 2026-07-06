<?php
/**
 * Unit tests for PublicationQueryService.
 *
 * Covers: findObjectLocation (the constrained object-location query) and isObjectPublic
 * (the public-relation-endpoint visibility guard). Bulk visibility filtering lives in
 * OpenRegister RBAC; isObjectPublic mirrors the same RBAC rule
 * (`publicatiedatum <= now`, APB-006) for the per-object guard on the public uses/used
 * relation endpoints. The removed object-level @self.published predicate is not consulted.
 *
 * @category Test
 * @package  Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\PublicationQueryService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Fake OpenRegister ObjectService double for assemblePublicSearchResults() tests.
 *
 * Captures the query passed to searchObjectsPaginated() so scope-enforcement can be
 * asserted, and lets searchObjects() (used for the document → publication slug lookup)
 * return a canned row keyed by the requested slug.
 */
class FakeSearchObjectService
{

    /**
     * Captured query passed to searchObjectsPaginated().
     *
     * @var array<string, mixed>|null
     */
    public ?array $capturedQuery = null;

    /**
     * Rows returned by searchObjectsPaginated().
     *
     * @var array<int, array<string, mixed>>
     */
    public array $candidateRows = [];

    /**
     * Results of searchObjects(), keyed by slug.
     *
     * @var array<string, array<int, array<string, mixed>>>
     */
    public array $bySlug = [];

    /**
     * Mirrors OR's ObjectService::buildSearchQuery() closely enough for these tests:
     * passes request params through unchanged so scope-override tests can prove
     * assemblePublicSearchResults() strips/overwrites the scope-widening keys.
     *
     * @param array $requestParams Raw request params.
     *
     * @return array
     */
    public function buildSearchQuery(array $requestParams): array
    {
        return $requestParams;

    }//end buildSearchQuery()

    /**
     * Records the query and returns the canned candidate rows.
     *
     * @param array   $query         The search query.
     * @param boolean $_rbac         Unused (test double).
     * @param boolean $_multitenancy Unused (test double).
     *
     * @return array{results: array<int, array<string, mixed>>}
     */
    public function searchObjectsPaginated(array $query, bool $_rbac=true, bool $_multitenancy=true): array
    {
        $this->capturedQuery = $query;
        return ['results' => $this->candidateRows];

    }//end searchObjectsPaginated()

    /**
     * Returns the canned rows for the requested slug.
     *
     * @param array   $query         The search query (only `slug` is consulted).
     * @param boolean $_rbac         Unused (test double).
     * @param boolean $_multitenancy Unused (test double).
     *
     * @return array<int, array<string, mixed>>
     */
    public function searchObjects(array $query, bool $_rbac=true, bool $_multitenancy=true): array
    {
        $slug = ($query['slug'] ?? null);
        return ($this->bySlug[$slug] ?? []);

    }//end searchObjects()
}//end class

/**
 * Unit tests for PublicationQueryService.
 *
 * Focuses on the constrained findObjectLocation query (#734). Object visibility is now
 * enforced by OpenRegister RBAC, not by an app-side published predicate.
 */
class PublicationQueryServiceTest extends TestCase
{

    /**
     * Database connection mock.
     *
     * @var IDBConnection|MockObject
     */
    private IDBConnection|MockObject $db;

    /**
     * DI container mock.
     *
     * @var ContainerInterface|MockObject
     */
    private ContainerInterface|MockObject $container;

    /**
     * App config mock.
     *
     * @var IAppConfig|MockObject
     */
    private IAppConfig|MockObject $config;

    /**
     * In-memory app-config store consulted by the config mock's getValueString().
     *
     * @var array<string, string>
     */
    private array $configStore = [];

    /**
     * Logger captured for assertions on the fail-closed observability signal.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface $logger;

    /**
     * Service under test.
     *
     * @var PublicationQueryService
     */
    private PublicationQueryService $service;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->db        = $this->createMock(IDBConnection::class);
        $this->container = $this->createMock(ContainerInterface::class);
        $this->config    = $this->createMock(IAppConfig::class);
        $this->logger    = $this->createMock(LoggerInterface::class);

        $this->configStore = [];
        $this->config->method('getValueString')
            ->willReturnCallback(
                fn (string $app, string $key, string $default='') => ($this->configStore[$key] ?? $default)
            );

        $this->service = new PublicationQueryService(
            db: $this->db,
            container: $this->container,
            config: $this->config,
            logger: $this->logger
        );

    }//end setUp()

    /**
     * Build a service instance with an authenticated (non-anonymous) user session.
     *
     * @return PublicationQueryService
     */
    private function makeAuthenticatedService(): PublicationQueryService
    {
        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn($this->createMock(IUser::class));

        return new PublicationQueryService(
            db: $this->db,
            container: $this->container,
            userSession: $userSession,
            config: $this->config,
            logger: $this->logger
        );

    }//end makeAuthenticatedService()

    // -------------------------------------------------------------------------
    // assemblePublicSearchResults() tests (SCH-PFTS-002/003/004/007)
    // -------------------------------------------------------------------------

    /**
     * Unconfigured register/schema ids fail closed to an empty envelope rather than
     * falling back to an unscoped, platform-wide search.
     *
     * @return void
     */
    public function testAssemblePublicSearchResultsFailsClosedWhenUnconfigured(): void
    {
        $fake = new FakeSearchObjectService();

        $result = $this->service->assemblePublicSearchResults([], $fake);

        $this->assertSame(expected: ['results' => [], 'total' => 0], actual: $result);
        $this->assertNull($fake->capturedQuery, 'searchObjectsPaginated MUST NOT run without a resolved scope.');

    }//end testAssemblePublicSearchResultsFailsClosedWhenUnconfigured()

    /**
     * Security: caller-supplied `_register`/`_schemas`/`catalogSlug` MUST NOT widen the
     * search beyond the configured publication/document schemas.
     *
     * @return void
     */
    public function testAssemblePublicSearchResultsFixesScopeRegardlessOfCallerParams(): void
    {
        $this->configureRegisterAndSchemas();
        $fake = new FakeSearchObjectService();

        $this->service->assemblePublicSearchResults(
            queryParams: [
                '_register'   => 999,
                '_schemas'    => [999],
                '_schema'     => 999,
                'catalogSlug' => 'unrelated-catalog',
                'fq'          => 'unrelated-filter',
            ],
            objectService: $fake
        );

        $this->assertSame(expected: 10, actual: $fake->capturedQuery['_register']);
        $this->assertSame(expected: [1, 2], actual: $fake->capturedQuery['_schemas']);
        $this->assertArrayNotHasKey('_schema', $fake->capturedQuery);
        $this->assertArrayNotHasKey('catalogSlug', $fake->capturedQuery);
        $this->assertArrayNotHasKey('fq', $fake->capturedQuery);

    }//end testAssemblePublicSearchResultsFixesScopeRegardlessOfCallerParams()

    /**
     * Mixed publication/document rows are discriminated by `@self.schema`, and every
     * document row carries the embedded `{id, slug, title}` publication summary
     * (SCH-PFTS-002/003).
     *
     * @return void
     */
    public function testAssemblePublicSearchResultsDiscriminatesAndEmbedsPublicationSummary(): void
    {
        $this->configureRegisterAndSchemas();
        $fake = new FakeSearchObjectService();
        $fake->candidateRows   = [
            [
                '@self'           => ['schema' => 1, 'slug' => 'pub-a'],
                'title'           => 'Pub A',
                'publicatiedatum' => '2024-01-01T00:00:00+00:00',
            ],
            [
                '@self'           => ['schema' => 2, 'slug' => 'doc-a'],
                'title'           => 'Doc A',
                'publicatiedatum' => '2024-01-01T00:00:00+00:00',
                'publication'     => ['slug' => 'pub-a', 'title' => 'Stale denormalised title'],
            ],
        ];
        $fake->bySlug['pub-a'] = [
            [
                'id'              => 'uuid-pub-a',
                '@self'           => ['slug' => 'pub-a'],
                'title'           => 'Pub A',
                'publicatiedatum' => '2024-01-01T00:00:00+00:00',
            ],
        ];

        $result = $this->service->assemblePublicSearchResults([], $fake);

        $this->assertSame(2, $result['total']);
        $this->assertSame('publication', $result['results'][0]['@self']['schema']);
        $this->assertSame('document', $result['results'][1]['@self']['schema']);
        $this->assertSame(
            expected: ['id' => 'uuid-pub-a', 'slug' => 'pub-a', 'title' => 'Pub A'],
            actual: $result['results'][1]['publication']
        );

    }//end testAssemblePublicSearchResultsDiscriminatesAndEmbedsPublicationSummary()

    /**
     * Anonymous callers never see a candidate row whose own visibility fails
     * `isObjectPublic()`, even though the filter runs AFTER scoring/merge (SCH-PFTS-004).
     *
     * @return void
     */
    public function testAssemblePublicSearchResultsFiltersUnpublishedForAnonymous(): void
    {
        $this->configureRegisterAndSchemas();
        $future = (new \DateTime('+10 days'))->format(\DateTimeInterface::ATOM);

        $fake = new FakeSearchObjectService();
        $fake->candidateRows = [
            [
                '@self'           => ['schema' => 1, 'slug' => 'pub-live'],
                'title'           => 'Live publication',
                'publicatiedatum' => '2024-01-01T00:00:00+00:00',
            ],
            [
                '@self'           => ['schema' => 1, 'slug' => 'pub-embargoed'],
                'title'           => 'Embargoed publication',
                'publicatiedatum' => $future,
            ],
        ];

        $result = $this->service->assemblePublicSearchResults([], $fake);

        $this->assertSame(1, $result['total']);
        $this->assertSame('pub-live', $result['results'][0]['@self']['slug']);

    }//end testAssemblePublicSearchResultsFiltersUnpublishedForAnonymous()

    /**
     * A document whose OWN publicatiedatum is public but whose linked publication is
     * depublished MUST NOT surface for anonymous callers (transitive visibility,
     * SCH-PFTS-004).
     *
     * @return void
     */
    public function testAssemblePublicSearchResultsEnforcesTransitiveVisibilityOnDocuments(): void
    {
        $this->configureRegisterAndSchemas();
        $fake = new FakeSearchObjectService();
        $fake->candidateRows = [
            [
                '@self'           => ['schema' => 2, 'slug' => 'doc-orphaned-by-depublish'],
                'title'           => 'Document of a depublished report',
                'publicatiedatum' => '2024-01-01T00:00:00+00:00',
                'publication'     => ['slug' => 'pub-depublished', 'title' => 'Depublished report'],
            ],
        ];
        $fake->bySlug['pub-depublished'] = [
            [
                'id'                => 'uuid-pub-depublished',
                '@self'             => ['slug' => 'pub-depublished'],
                'title'             => 'Depublished report',
                'publicatiedatum'   => '2024-01-01T00:00:00+00:00',
                'depublicatiedatum' => '2024-06-01T00:00:00+00:00',
            ],
        ];

        $result = $this->service->assemblePublicSearchResults([], $fake);

        $this->assertSame(expected: ['results' => [], 'total' => 0], actual: $result);

    }//end testAssemblePublicSearchResultsEnforcesTransitiveVisibilityOnDocuments()

    /**
     * A document with no linked publication MUST NOT appear in the result set
     * (SCH-PFTS-003).
     *
     * @return void
     */
    public function testAssemblePublicSearchResultsDropsDocumentWithoutLinkedPublication(): void
    {
        $this->configureRegisterAndSchemas();
        $fake = new FakeSearchObjectService();
        $fake->candidateRows = [
            [
                '@self'           => ['schema' => 2, 'slug' => 'doc-orphan'],
                'title'           => 'Orphan document',
                'publicatiedatum' => '2024-01-01T00:00:00+00:00',
            ],
        ];

        $result = $this->service->assemblePublicSearchResults([], $fake);

        $this->assertSame(expected: ['results' => [], 'total' => 0], actual: $result);

    }//end testAssemblePublicSearchResultsDropsDocumentWithoutLinkedPublication()

    /**
     * A candidate row whose schema id does not match the configured publication/document
     * schema ids is dropped (defensive — should not occur given the fixed `_schemas`
     * scope, but the assembly must not surface an unrecognised row type).
     *
     * @return void
     */
    public function testAssemblePublicSearchResultsSkipsRowsWithUnknownSchemaId(): void
    {
        $this->configureRegisterAndSchemas();
        $fake = new FakeSearchObjectService();
        $fake->candidateRows = [
            [
                '@self'           => ['schema' => 999, 'slug' => 'unexpected'],
                'title'           => 'Unexpected schema',
                'publicatiedatum' => '2024-01-01T00:00:00+00:00',
            ],
        ];

        $result = $this->service->assemblePublicSearchResults([], $fake);

        $this->assertSame(expected: ['results' => [], 'total' => 0], actual: $result);

    }//end testAssemblePublicSearchResultsSkipsRowsWithUnknownSchemaId()

    /**
     * SCH-PFTS-004 requires the visibility filter to run unconditionally. A prior
     * `$isAnonymous === true &&` guard let any authenticated NC user enumerate the
     * whole register through a public URL (broken authorisation / OWASP A01:2021).
     * This test locks in the correct behaviour: an authenticated non-admin caller
     * MUST NOT see draft/embargoed content via the public search endpoint. Admins
     * who need drafts use the admin `/publications` endpoint instead.
     *
     * @return void
     */
    public function testAssemblePublicSearchResultsFiltersUnpublishedForAuthenticatedNonAdmin(): void
    {
        $this->configureRegisterAndSchemas();
        $future = (new \DateTime('+10 days'))->format(\DateTimeInterface::ATOM);

        $fake = new FakeSearchObjectService();
        $fake->candidateRows = [
            [
                '@self'           => ['schema' => 1, 'slug' => 'pub-live'],
                'title'           => 'Live publication',
                'publicatiedatum' => '2024-01-01T00:00:00+00:00',
            ],
            [
                '@self'           => ['schema' => 1, 'slug' => 'pub-embargoed'],
                'title'           => 'Embargoed publication',
                'publicatiedatum' => $future,
            ],
        ];

        $service = $this->makeAuthenticatedService();
        $result  = $service->assemblePublicSearchResults([], $fake);

        // Authenticated non-admin gets EXACTLY the same filter treatment as anonymous —
        // the public endpoint is one visibility surface, not two.
        $this->assertSame(1, $result['total']);
        $this->assertSame('pub-live', $result['results'][0]['@self']['slug']);

    }//end testAssemblePublicSearchResultsFiltersUnpublishedForAuthenticatedNonAdmin()

    /**
     * Transitive visibility gate also applies unconditionally. A document whose
     * linked publication is depublished MUST NOT surface for ANY caller through
     * the public endpoint (SCH-PFTS-004 scenario 3, post-fix wording).
     *
     * @return void
     */
    public function testAssemblePublicSearchResultsEnforcesTransitiveVisibilityForAuthenticatedNonAdmin(): void
    {
        $this->configureRegisterAndSchemas();
        $fake = new FakeSearchObjectService();
        $fake->candidateRows = [
            [
                '@self'           => ['schema' => 2, 'slug' => 'doc-orphaned-by-depublish'],
                'title'           => 'Document of a depublished report',
                'publicatiedatum' => '2024-01-01T00:00:00+00:00',
                'publication'     => ['slug' => 'pub-depublished', 'title' => 'Depublished report'],
            ],
        ];
        $fake->bySlug['pub-depublished'] = [
            [
                'id'                => 'uuid-pub-depublished',
                '@self'             => ['slug' => 'pub-depublished'],
                'title'             => 'Depublished report',
                'publicatiedatum'   => '2024-01-01T00:00:00+00:00',
                'depublicatiedatum' => '2024-06-01T00:00:00+00:00',
            ],
        ];

        $service = $this->makeAuthenticatedService();
        $result  = $service->assemblePublicSearchResults([], $fake);

        $this->assertSame(expected: ['results' => [], 'total' => 0], actual: $result);

    }//end testAssemblePublicSearchResultsEnforcesTransitiveVisibilityForAuthenticatedNonAdmin()

    /**
     * The fail-closed empty-envelope branch (register/schema config not resolved)
     * MUST emit a warning-level log entry, so the misconfiguration is observable in
     * production. Silent empty responses are indistinguishable from "no matches"
     * in the envelope — operators need a signal that the deploy is broken.
     *
     * @return void
     */
    public function testAssemblePublicSearchResultsLogsWarningWhenConfigMissing(): void
    {
        // configStore intentionally empty — resolveConfiguredId() returns null for every key.
        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('assemblePublicSearchResults returning empty envelope'),
                $this->callback(
                    fn (array $ctx): bool => (($ctx['publication_register'] ?? '') === 'MISSING')
                        && (($ctx['publication_schema'] ?? '') === 'MISSING')
                        && (($ctx['document_schema'] ?? '') === 'MISSING')
                )
            );

        $fake   = new FakeSearchObjectService();
        $result = $this->service->assemblePublicSearchResults([], $fake);

        $this->assertSame(expected: ['results' => [], 'total' => 0], actual: $result);

    }//end testAssemblePublicSearchResultsLogsWarningWhenConfigMissing()

    /**
     * Populate the register/schema app-config keys assemblePublicSearchResults() reads.
     *
     * @return void
     */
    private function configureRegisterAndSchemas(): void
    {
        $this->configStore = [
            'publication_register' => '10',
            'publication_schema'   => '1',
            'document_schema'      => '2',
        ];

    }//end configureRegisterAndSchemas()

    // -------------------------------------------------------------------------
    // findObjectLocation() tests
    // -------------------------------------------------------------------------

    /**
     * Security (#734): findObjectLocation MUST return null without touching the
     * database when no constraint is supplied.
     *
     * @return void
     */
    public function testFindObjectLocationFailsClosedWithoutConstraint(): void
    {
        // The DB must NOT be touched at all.
        $this->db->expects($this->never())->method('executeQuery');
        $this->db->expects($this->never())->method('getQueryBuilder');

        $this->assertNull($this->service->findObjectLocation('any-uuid'));
        $this->assertNull(
            $this->service->findObjectLocation(uuid: 'any-uuid', allowedRegisters: [], allowedSchemas: [])
        );
        $this->assertNull(
            $this->service->findObjectLocation(uuid: 'any-uuid', allowedRegisters: [1], allowedSchemas: [])
        );
        $this->assertNull(
            $this->service->findObjectLocation(uuid: 'any-uuid', allowedRegisters: [], allowedSchemas: [1])
        );

    }//end testFindObjectLocationFailsClosedWithoutConstraint()

    /**
     * Security (#734): constrained lookup queries only the expected magic table.
     *
     * @return void
     */
    public function testFindObjectLocationLooksUpOnlyConstrainedTables(): void
    {
        // Stub magicTableExists to claim only one table exists.
        $this->stubMagicTableExists(['oc_openregister_table_21_11' => true]);

        $resultRow   = ['register_id' => 21, 'schema_id' => 11];
        $unionResult = $this->createMock(IResult::class);
        $unionResult->method('fetch')->willReturn($resultRow);
        $unionResult->method('closeCursor');

        $this->db->method('quote')->willReturn("'uuid-found'");
        $this->db->expects($this->once())
            ->method('executeQuery')
            ->with($this->stringContains('oc_openregister_table_21_11'))
            ->willReturn($unionResult);

        $location = $this->service->findObjectLocation(
            uuid: 'uuid-found',
            allowedRegisters: [21],
            allowedSchemas: [11]
        );

        $this->assertSame(expected: ['register' => 21, 'schema' => 11], actual: $location);

    }//end testFindObjectLocationLooksUpOnlyConstrainedTables()

    /**
     * Security (#734): returns null when no magic tables exist for the constraints.
     *
     * @return void
     */
    public function testFindObjectLocationReturnsNullWhenNoTablesExist(): void
    {
        $this->stubMagicTableExists([]);

        // No UNION query should ever execute — only the existence probes.
        $this->db->expects($this->never())->method('executeQuery');

        $location = $this->service->findObjectLocation(
            uuid: 'uuid-missing',
            allowedRegisters: [1],
            allowedSchemas: [2]
        );

        $this->assertNull($location);

    }//end testFindObjectLocationReturnsNullWhenNoTablesExist()

    // -------------------------------------------------------------------------
    // Helper
    // -------------------------------------------------------------------------

    /**
     * Stub the IQueryBuilder chain used by magicTableExists().
     *
     * @param array<string, bool> $tableExistence Map of table_name => exists?
     *
     * @return void
     */
    private function stubMagicTableExists(array $tableExistence): void
    {
        $this->db->method('getQueryBuilder')->willReturnCallback(
            function () use ($tableExistence) {
                $qb          = $this->createMock(IQueryBuilder::class);
                $funcBuilder = $this->createMock(IFunctionBuilder::class);
                $funcBuilder->method('count')->willReturn($this->createMock(IQueryFunction::class));
                $expr = $this->createMock(IExpressionBuilder::class);
                $expr->method('eq')->willReturn('eq');
                // NOTE: andWhere() lives on the query builder ($qb->andWhere()), not on
                // the expression builder — IExpressionBuilder has no such method, so a
                // stub here raises MethodCannotBeConfigured. The $qb->andWhere() stub is
                // configured below.
                $qb->method('select')->willReturnSelf();
                $qb->method('from')->willReturnSelf();
                $qb->method('func')->willReturn($funcBuilder);
                $qb->method('expr')->willReturn($expr);
                $qb->method('createNamedParameter')->willReturnCallback(fn($v) => $v);
                $qb->method('createFunction')->willReturn('DATABASE()');
                $qb->method('where')->willReturnSelf();
                $qb->method('andWhere')->willReturnSelf();

                $qb->method('executeQuery')->willReturnCallback(
                    function () use ($tableExistence) {
                        $result = $this->createMock(IResult::class);
                        // If any tableExistence entry is true return 1, else 0.
                        $anyExists = (array_filter($tableExistence) !== []);
                        $cntValue  = 0;
                        if ($anyExists === true) {
                            $cntValue = 1;
                        }

                        $result->method('fetch')->willReturn(['cnt' => $cntValue]);
                        $result->method('closeCursor');
                        return $result;
                    }
                );

                return $qb;
            }
        );

    }//end stubMagicTableExists()

    // -------------------------------------------------------------------------
    // isObjectPublic() tests — RBAC publicatiedatum model (APB-006)
    // -------------------------------------------------------------------------

    /**
     * A past publicatiedatum with no depublicatiedatum is publicly visible.
     *
     * @return void
     *
     * @spec openspec/specs/auto-publishing/spec.md#APB-006
     */
    public function testIsObjectPublicWithPastPublicatiedatum(): void
    {
        $this->assertTrue(
            $this->service->isObjectPublic(['publicatiedatum' => '2024-01-15T10:00:00+00:00'])
        );

    }//end testIsObjectPublicWithPastPublicatiedatum()

    /**
     * No publicatiedatum means the object is not public (concept).
     *
     * @return void
     *
     * @spec openspec/specs/auto-publishing/spec.md#APB-006
     */
    public function testIsObjectPublicWithoutPublicatiedatum(): void
    {
        $this->assertFalse($this->service->isObjectPublic([]));
        $this->assertFalse(
            $this->service->isObjectPublic(['depublicatiedatum' => '2024-01-15T10:00:00+00:00'])
        );

    }//end testIsObjectPublicWithoutPublicatiedatum()

    /**
     * A future publicatiedatum (embargo) is not yet public.
     *
     * @return void
     *
     * @spec openspec/specs/auto-publishing/spec.md#APB-006
     */
    public function testIsObjectPublicWithFuturePublicatiedatum(): void
    {
        $future = (new \DateTime('+10 days'))->format(\DateTimeInterface::ATOM);
        $this->assertFalse($this->service->isObjectPublic(['publicatiedatum' => $future]));

    }//end testIsObjectPublicWithFuturePublicatiedatum()

    /**
     * A reached depublicatiedatum hides the object; a future one keeps it visible.
     *
     * @return void
     *
     * @spec openspec/specs/auto-publishing/spec.md#APB-006
     */
    public function testIsObjectPublicRespectsDepublicatiedatum(): void
    {
        $future = (new \DateTime('+10 days'))->format(\DateTimeInterface::ATOM);

        $this->assertFalse(
            $this->service->isObjectPublic(
                objectData: [
                    'publicatiedatum'   => '2024-01-15T10:00:00+00:00',
                    'depublicatiedatum' => '2024-06-01T10:00:00+00:00',
                ]
            )
        );

        $this->assertTrue(
            $this->service->isObjectPublic(
                objectData: [
                    'publicatiedatum'   => '2024-01-15T10:00:00+00:00',
                    'depublicatiedatum' => $future,
                ]
            )
        );

    }//end testIsObjectPublicRespectsDepublicatiedatum()
}//end class
