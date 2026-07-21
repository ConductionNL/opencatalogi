<?php
/**
 * Unit tests for PublicationQueryService.
 *
 * Covers: findObjectLocation (the constrained object-location query), isObjectPublic
 * (the public-relation-endpoint visibility guard), and the `_content` opt-in content-
 * search flag (WOO-517, SCH-PFTS-CONTENT-001/-002/-003). Bulk visibility filtering
 * lives in OpenRegister RBAC; isObjectPublic mirrors the same RBAC rule
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
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
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
     * Mirrors the real query shape `resolveDocumentPublicationSummary()` sends —
     * `@self.slug`, not a bare `slug` key (a bare key would become a schema-property
     * filter against `publication`, which has no `slug` property; see the comment on
     * that method).
     *
     * @param array   $query         The search query (only `@self.slug` is consulted).
     * @param boolean $_rbac         Unused (test double).
     * @param boolean $_multitenancy Unused (test double).
     *
     * @return array<int, array<string, mixed>>
     */
    public function searchObjects(array $query, bool $_rbac=true, bool $_multitenancy=true): array
    {
        $slug = ($query['@self']['slug'] ?? null);
        return ($this->bySlug[$slug] ?? []);

    }//end searchObjects()
}//end class

/**
 * Unit tests for PublicationQueryService.
 *
 * Focuses on the constrained findObjectLocation lookup (#734), which now routes
 * through OpenRegister's ObjectService (ADR-022) instead of raw SQL against OR's
 * internal magic-mapper tables. Object visibility is enforced by OpenRegister RBAC,
 * not by an app-side published predicate.
 */
class PublicationQueryServiceTest extends TestCase
{

    /**
     * DI container mock.
     *
     * @var ContainerInterface|MockObject
     */
    private ContainerInterface|MockObject $container;

    /**
     * OpenRegister ObjectService mock resolved from the container.
     *
     * @var ObjectService|MockObject
     */
    private ObjectService|MockObject $objectService;

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
        $this->container     = $this->createMock(ContainerInterface::class);
        $this->objectService = $this->createMock(ObjectService::class);
        $this->config        = $this->createMock(IAppConfig::class);
        $this->logger        = $this->createMock(LoggerInterface::class);

        $this->configStore = [];
        $this->config->method('getValueString')
            ->willReturnCallback(
                fn (string $app, string $key, string $default='') => ($this->configStore[$key] ?? $default)
            );

        $this->service = new PublicationQueryService(
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
        // ConfigStore intentionally empty — resolveConfiguredId() returns null for every key.
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

    // -------------------------------------------------------------------------
    // `_content` opt-in content-search tests (WOO-517, SCH-PFTS-CONTENT-001/-002/-003)
    // -------------------------------------------------------------------------

    /**
     * Default (`_content` absent) MUST be byte-identical to the WOO-506 baseline —
     * no `_content_search` key is forwarded to OR (SCH-PFTS-CONTENT-001).
     *
     * @return void
     */
    public function testAssemblePublicSearchResultsOmitsContentSearchFlagByDefault(): void
    {
        $this->configureRegisterAndSchemas();
        $fake = new FakeSearchObjectService();

        $this->service->assemblePublicSearchResults([], $fake);

        $this->assertArrayNotHasKey('_content_search', $fake->capturedQuery);

    }//end testAssemblePublicSearchResultsOmitsContentSearchFlagByDefault()

    /**
     * `_content=false` (explicit) MUST also omit the forwarded OR flag — byte-identical
     * to the WOO-506 baseline (SCH-PFTS-CONTENT-001 "default omits content search").
     *
     * @return void
     */
    public function testAssemblePublicSearchResultsOmitsContentSearchFlagWhenExplicitlyFalse(): void
    {
        $this->configureRegisterAndSchemas();
        $fake = new FakeSearchObjectService();

        $this->service->assemblePublicSearchResults(['_content' => 'false'], $fake);

        $this->assertArrayNotHasKey('_content_search', $fake->capturedQuery);

    }//end testAssemblePublicSearchResultsOmitsContentSearchFlagWhenExplicitlyFalse()

    /**
     * `_content=true` MUST forward OR's `_content_search` flag on the delegated query,
     * and MUST NOT leak OC's own `_content` key into that query (SCH-PFTS-CONTENT-001).
     *
     * @return void
     */
    public function testAssemblePublicSearchResultsForwardsContentSearchFlagWhenTrue(): void
    {
        $this->configureRegisterAndSchemas();
        $fake = new FakeSearchObjectService();

        $this->service->assemblePublicSearchResults(['_content' => 'true'], $fake);

        $this->assertTrue($fake->capturedQuery['_content_search']);
        $this->assertArrayNotHasKey('_content', $fake->capturedQuery);

    }//end testAssemblePublicSearchResultsForwardsContentSearchFlagWhenTrue()

    /**
     * A document surfaced ONLY via a content-search fan-out (i.e. present in the
     * candidate set only when `_content=true`) is present when `_content=true` and
     * MUST NOT be considered when the flag is absent — locked in by asserting the
     * flag-forwarding test above; this test proves the document row's shape is
     * indistinguishable from a metadata-matched row (SCH-PFTS-002/SCH-PFTS-CONTENT-002).
     *
     * @return void
     */
    public function testAssemblePublicSearchResultsContentMatchedDocumentRowShapeMatchesMetadataMatch(): void
    {
        $this->configureRegisterAndSchemas();
        $fake = new FakeSearchObjectService();
        $fake->candidateRows   = [
            [
                '@self'           => ['schema' => 2, 'id' => 'uuid-doc-content-match'],
                'title'           => 'Document only matched via body text',
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

        $result = $this->service->assemblePublicSearchResults(['_content' => 'true'], $fake);

        $this->assertSame(1, $result['total']);
        $this->assertSame('document', $result['results'][0]['@self']['schema']);
        $this->assertSame(
            expected: ['id' => 'uuid-pub-a', 'slug' => 'pub-a', 'title' => 'Pub A'],
            actual: $result['results'][0]['publication']
        );
        // No field distinguishes a content-match row from a metadata-match row —
        // the row shape is identical to testAssemblePublicSearchResultsDiscriminatesAndEmbedsPublicationSummary().
        $this->assertArrayNotHasKey('_snippet', $result['results'][0]);
        $this->assertArrayNotHasKey('chunk', $result['results'][0]);

    }//end testAssemblePublicSearchResultsContentMatchedDocumentRowShapeMatchesMetadataMatch()

    /**
     * A document matching on BOTH metadata AND content (represented as OR returning
     * the same `@self.id` twice in the candidate set — e.g. once from the metadata
     * arm, once from the chunk arm) MUST appear exactly once in the response,
     * deduplicated on `@self.id` (SCH-PFTS-CONTENT-002, MODIFIED SCH-PFTS-002).
     *
     * @return void
     */
    public function testAssemblePublicSearchResultsDedupesDocumentMatchingBothSurfaces(): void
    {
        $this->configureRegisterAndSchemas();
        $fake = new FakeSearchObjectService();
        $fake->candidateRows   = [
            [
                '@self'           => ['schema' => 2, 'id' => 'uuid-doc-both'],
                'title'           => 'Matches on title AND body text',
                'publicatiedatum' => '2024-01-01T00:00:00+00:00',
                'publication'     => ['slug' => 'pub-a', 'title' => 'Stale denormalised title'],
            ],
            [
                '@self'           => ['schema' => 2, 'id' => 'uuid-doc-both'],
                'title'           => 'Matches on title AND body text',
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

        $result = $this->service->assemblePublicSearchResults(['_content' => 'true'], $fake);

        $this->assertSame(1, $result['total']);
        $this->assertSame('uuid-doc-both', $result['results'][0]['@self']['id']);

    }//end testAssemblePublicSearchResultsDedupesDocumentMatchingBothSurfaces()

    /**
     * A content-matched document on a depublished document MUST be dropped from the
     * anonymous response — content matches inherit the same `isObjectPublic()` gate
     * as metadata matches (SCH-PFTS-CONTENT-003).
     *
     * @return void
     */
    public function testAssemblePublicSearchResultsDropsContentMatchedDepublishedDocument(): void
    {
        $this->configureRegisterAndSchemas();
        $fake = new FakeSearchObjectService();
        $fake->candidateRows   = [
            [
                '@self'             => ['schema' => 2, 'id' => 'uuid-doc-depublished'],
                'title'             => 'Body text matched, but document is depublished',
                'publicatiedatum'   => '2024-01-01T00:00:00+00:00',
                'depublicatiedatum' => '2024-06-01T00:00:00+00:00',
                'publication'       => ['slug' => 'pub-a', 'title' => 'Pub A'],
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

        $result = $this->service->assemblePublicSearchResults(['_content' => 'true'], $fake);

        $this->assertSame(expected: ['results' => [], 'total' => 0], actual: $result);

    }//end testAssemblePublicSearchResultsDropsContentMatchedDepublishedDocument()

    /**
     * A content-matched document whose linked publication is depublished MUST be
     * dropped from the anonymous response — transitive visibility applies to
     * content matches exactly as it does to metadata matches (SCH-PFTS-CONTENT-003).
     *
     * @return void
     */
    public function testAssemblePublicSearchResultsDropsContentMatchedDocumentWithDepublishedParent(): void
    {
        $this->configureRegisterAndSchemas();
        $fake = new FakeSearchObjectService();
        $fake->candidateRows = [
            [
                '@self'           => ['schema' => 2, 'id' => 'uuid-doc-orphaned-by-depublish'],
                'title'           => 'Body text matched, but parent publication is depublished',
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

        $result = $this->service->assemblePublicSearchResults(['_content' => 'true'], $fake);

        $this->assertSame(expected: ['results' => [], 'total' => 0], actual: $result);

    }//end testAssemblePublicSearchResultsDropsContentMatchedDocumentWithDepublishedParent()

    /**
     * Raw chunk-search fields (`_snippet`, `chunk`, `score` etc.) attached to a
     * candidate row by OR MUST be stripped before the row surfaces on the anonymous
     * response — SCH-PFTS-CONTENT-002 requires the endpoint return documents, not
     * chunks. Defence-in-depth: OR resolves chunk hits to their owning ObjectEntity
     * before returning, so these fields should never be present; strip regardless
     * so any future regression cannot leak them.
     *
     * @return void
     */
    public function testAssemblePublicSearchResultsStripsRawChunkFieldsFromContentMatchedRow(): void
    {
        $this->configureRegisterAndSchemas();
        $fake = new FakeSearchObjectService();
        $fake->candidateRows = [
            [
                '@self'           => ['schema' => 2, 'id' => 'uuid-doc-with-chunk-fields'],
                'title'           => 'Doc with rogue chunk fields',
                'publicatiedatum' => '2024-01-01T00:00:00+00:00',
                'publication'     => ['slug' => 'pub-a', 'title' => 'Stale denormalised title'],
                // OR-side regression / debug leak — these MUST be stripped.
                '_snippet'        => 'lorem ipsum ... marker ...',
                'snippet'         => 'lorem ipsum ... marker ...',
                'chunk'           => ['id' => 42, 'text' => 'body-text snippet'],
                'chunk_id'        => 42,
                'chunkId'         => 42,
                'score'           => 0.87,
                '_score'          => 0.87,
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

        $result = $this->service->assemblePublicSearchResults(['_content' => 'true'], $fake);

        $this->assertSame(1, $result['total']);
        $row = $result['results'][0];
        $this->assertArrayNotHasKey('_snippet', $row);
        $this->assertArrayNotHasKey('snippet', $row);
        $this->assertArrayNotHasKey('chunk', $row);
        $this->assertArrayNotHasKey('chunk_id', $row);
        $this->assertArrayNotHasKey('chunkId', $row);
        $this->assertArrayNotHasKey('score', $row);
        $this->assertArrayNotHasKey('_score', $row);

    }//end testAssemblePublicSearchResultsStripsRawChunkFieldsFromContentMatchedRow()

    /**
     * When OR returns two rows with the same `@self.id` but the first row fails
     * per-row validation (e.g. carries a stale `publication.slug` whose lookup
     * returns null — SCH-PFTS-003 drops the row), the second row that would have
     * passed MUST NOT be silently swallowed by the dedup — the seen-set is
     * stamped only on emission, not on candidate encounter. Regression test for
     * the review finding on `PublicationQueryService::assemblePublicSearchResults()`
     * dedup ordering.
     *
     * @return void
     */
    public function testAssemblePublicSearchResultsDedupIsStampedOnEmissionNotOnCandidateEncounter(): void
    {
        $this->configureRegisterAndSchemas();
        $fake = new FakeSearchObjectService();
        $fake->candidateRows   = [
            [
                // First candidate: same id, stale slug — resolveDocumentPublicationSummary()
                // returns null → row is dropped, MUST NOT claim the seen slot.
                '@self'           => ['schema' => 2, 'id' => 'uuid-doc-shared'],
                'title'           => 'Stale denormalised summary path',
                'publicatiedatum' => '2024-01-01T00:00:00+00:00',
                'publication'     => ['slug' => 'pub-stale-slug-not-in-bySlug', 'title' => 'Ghost'],
            ],
            [
                // Second candidate: same id, fresh slug — MUST be emitted.
                '@self'           => ['schema' => 2, 'id' => 'uuid-doc-shared'],
                'title'           => 'Fresh denormalised summary path',
                'publicatiedatum' => '2024-01-01T00:00:00+00:00',
                'publication'     => ['slug' => 'pub-a', 'title' => 'Fresh'],
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

        $result = $this->service->assemblePublicSearchResults(['_content' => 'true'], $fake);

        $this->assertSame(1, $result['total']);
        $this->assertSame('uuid-doc-shared', $result['results'][0]['@self']['id']);
        $this->assertSame('Fresh denormalised summary path', $result['results'][0]['title']);

    }//end testAssemblePublicSearchResultsDedupIsStampedOnEmissionNotOnCandidateEncounter()

    /**
     * `filter_var(FILTER_VALIDATE_BOOLEAN)` correctly interprets the full range of
     * boolean-like inputs on `_content`. This data-provider test locks the contract
     * against any future refactor that swaps out `filter_var` for a hand-rolled
     * `=== 'true'` check that would mishandle `'yes'` / `'on'` / etc.
     *
     * @param mixed   $input     Value assigned to `_content`.
     * @param boolean $expectFwd Whether `_content_search` MUST be forwarded to OR.
     *
     * @dataProvider contentFlagEdgeCasesProvider
     * @return       void
     */
    public function testAssemblePublicSearchResultsHandlesContentFlagEdgeCases(mixed $input, bool $expectFwd): void
    {
        $this->configureRegisterAndSchemas();
        $fake = new FakeSearchObjectService();

        $this->service->assemblePublicSearchResults(['_content' => $input], $fake);

        if ($expectFwd === true) {
            $this->assertTrue(
                $fake->capturedQuery['_content_search'] ?? false,
                sprintf('_content=%s MUST forward _content_search=true', var_export($input, true))
            );
        } else {
            $this->assertArrayNotHasKey(
                key: '_content_search',
                array: $fake->capturedQuery,
                message: sprintf('_content=%s MUST NOT forward _content_search', var_export($input, true))
            );
        }

    }//end testAssemblePublicSearchResultsHandlesContentFlagEdgeCases()

    /**
     * Boolean edge cases exercised by
     * {@see testAssemblePublicSearchResultsHandlesContentFlagEdgeCases()}.
     *
     * @return array<string, array{0: mixed, 1: bool}>
     */
    public static function contentFlagEdgeCasesProvider(): array
    {
        return [
            'true string'      => ['true', true],
            'TRUE string'      => ['TRUE', true],
            'yes string'       => ['yes', true],
            'on string'        => ['on', true],
            '1 string'         => ['1', true],
            '1 int'            => [1, true],
            'true bool'        => [true, true],
            'false string'     => ['false', false],
            'FALSE string'     => ['FALSE', false],
            'no string'        => ['no', false],
            'off string'       => ['off', false],
            '0 string'         => ['0', false],
            '0 int'            => [0, false],
            'empty string'     => ['', false],
            'malformed string' => ['maybe', false],
            'other numeric'    => ['2', false],
            'negative numeric' => ['-1', false],
            'array wrapper'    => [['true'], false],
            'null'             => [null, false],
            'false bool'       => [false, false],
        ];

    }//end contentFlagEdgeCasesProvider()

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

    /**
     * Wire the container so it resolves the ObjectService mock.
     *
     * @return void
     */
    private function wireObjectService(): void
    {
        $this->container->method('get')->willReturnCallback(
            function (string $id) {
                if ($id === 'OCA\OpenRegister\Service\ObjectService') {
                    return $this->objectService;
                }

                throw new \RuntimeException('unexpected container id: '.$id);
            }
        );

    }//end wireObjectService()

    // -------------------------------------------------------------------------
    // findObjectLocation() tests
    // -------------------------------------------------------------------------

    /**
     * Security (#734): findObjectLocation MUST return null without touching
     * OpenRegister when no constraint is supplied.
     *
     * @return void
     */
    public function testFindObjectLocationFailsClosedWithoutConstraint(): void
    {
        // OpenRegister must NOT be resolved or queried at all.
        $this->container->expects($this->never())->method('get');

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
     * Constrained lookup locates the object via ObjectService::find within the
     * allowed (register × schema) pair — no raw SQL against OR storage internals.
     *
     * @return void
     *
     * @spec openspec/specs/opencatalogi-adopt-or-abstractions/spec.md
     */
    public function testFindObjectLocationLocatesViaObjectService(): void
    {
        $this->wireObjectService();

        $entity = $this->createMock(ObjectEntity::class);

        // Object lives only in register 21 / schema 11; every other pair misses.
        $this->objectService->method('find')->willReturnCallback(
            function (int|string $id, ?array $_extend, bool $files, $register, $schema) use ($entity) {
                if ((int) $register === 21 && (int) $schema === 11) {
                    return $entity;
                }

                return null;
            }
        );

        $location = $this->service->findObjectLocation(
            uuid: 'uuid-found',
            allowedRegisters: [20, 21],
            allowedSchemas: [10, 11]
        );

        $this->assertSame(expected: ['register' => 21, 'schema' => 11], actual: $location);

    }//end testFindObjectLocationLocatesViaObjectService()

    /**
     * Returns null when ObjectService finds the UUID in none of the allowed pairs.
     *
     * @return void
     *
     * @spec openspec/specs/opencatalogi-adopt-or-abstractions/spec.md
     */
    public function testFindObjectLocationReturnsNullWhenNotFound(): void
    {
        $this->wireObjectService();

        $this->objectService->method('find')->willReturn(null);

        $location = $this->service->findObjectLocation(
            uuid: 'uuid-missing',
            allowedRegisters: [1],
            allowedSchemas: [2]
        );

        $this->assertNull($location);

    }//end testFindObjectLocationReturnsNullWhenNotFound()

    /**
     * A DoesNotExistException from one pair is swallowed and the search continues
     * to the next pair rather than bubbling up.
     *
     * @return void
     *
     * @spec openspec/specs/opencatalogi-adopt-or-abstractions/spec.md
     */
    public function testFindObjectLocationContinuesPastMissingPair(): void
    {
        $this->wireObjectService();

        $entity = $this->createMock(ObjectEntity::class);

        $this->objectService->method('find')->willReturnCallback(
            function (int|string $id, ?array $_extend, bool $files, $register, $schema) use ($entity) {
                if ((int) $register === 1 && (int) $schema === 2) {
                    throw new \OCP\AppFramework\Db\DoesNotExistException('missing table');
                }

                if ((int) $register === 1 && (int) $schema === 3) {
                    return $entity;
                }

                return null;
            }
        );

        $location = $this->service->findObjectLocation(
            uuid: 'uuid-found',
            allowedRegisters: [1],
            allowedSchemas: [2, 3]
        );

        $this->assertSame(expected: ['register' => 1, 'schema' => 3], actual: $location);

    }//end testFindObjectLocationContinuesPastMissingPair()

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
