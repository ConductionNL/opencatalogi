<?php
/**
 * Service for generating DIWOO-compliant sitemaps.
 *
 * Provides functionality for building sitemap indexes and publication sitemaps
 * following the DIWOO metadata standard for Dutch government document publication.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2025 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/specs/woo-compliance/spec.md
 * @spec openspec/specs/woo-compliance/spec.md
 * @spec openspec/specs/woo-compliance/spec.md
 * @spec openspec/specs/woo-compliance/spec.md
 */

namespace OCA\OpenCatalogi\Service;

use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCA\OpenCatalogi\Http\XMLResponse;
use Psr\Container\ContainerInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use OCA\OpenCatalogi\Service\SettingsService;
use RuntimeException;
use OCP\IURLGenerator;

/**
 * Service for generating DIWOO-compliant sitemaps.
 *
 * Provides functionality for building sitemap indexes and publication sitemaps
 * following the DIWOO metadata standard.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class SitemapService
{

    /**
     * Default maximum number of publications fetched per sitemap page.
     *
     * Overridable via the `sitemap_max_per_page` app-config key (see
     * getMaxPerPage()). The constant is the shipped default only — operators
     * tune the live value without a code change (audit Stream 4).
     *
     * @var int
     */
    private const DEFAULT_MAX_PER_PAGE = 1000;

    /**
     * App-config key that overrides DEFAULT_MAX_PER_PAGE.
     *
     * @var string
     */
    private const CONFIG_MAX_PER_PAGE = 'sitemap_max_per_page';

    /**
     * The app id used for IAppConfig reads.
     *
     * @var string
     */
    private const APP_NAME = 'opencatalogi';

    public const INFO_CAT = [
        'sitemapindex-diwoo-infocat001.xml' => 'Wetten en algemeen verbindende voorschriften',
        'sitemapindex-diwoo-infocat002.xml' => 'Overige besluiten van algemene strekking',
        'sitemapindex-diwoo-infocat003.xml' => 'Ontwerpen van wet- en regelgeving met adviesaanvraag',
        'sitemapindex-diwoo-infocat004.xml' => 'Organisatie en werkwijze',
        'sitemapindex-diwoo-infocat005.xml' => 'Bereikbaarheidsgegevens',
        'sitemapindex-diwoo-infocat006.xml' => 'Bij vertegenwoordigende organen ingekomen stukken',
        'sitemapindex-diwoo-infocat007.xml' => 'Vergaderstukken Staten-Generaal',
        'sitemapindex-diwoo-infocat008.xml' => 'Vergaderstukken decentrale overheden',
        'sitemapindex-diwoo-infocat009.xml' => "Agenda's en besluitenlijsten bestuurscolleges",
        'sitemapindex-diwoo-infocat010.xml' => 'Adviezen',
        'sitemapindex-diwoo-infocat011.xml' => 'Convenanten',
        'sitemapindex-diwoo-infocat012.xml' => 'Jaarplannen en jaarverslagen',
        'sitemapindex-diwoo-infocat013.xml' => 'Subsidieverplichtingen anders dan met beschikking',
        'sitemapindex-diwoo-infocat014.xml' => 'Woo-verzoeken en -besluiten',
        'sitemapindex-diwoo-infocat015.xml' => 'Onderzoeksrapporten',
        'sitemapindex-diwoo-infocat016.xml' => 'Beschikkingen',
        'sitemapindex-diwoo-infocat017.xml' => 'Klachtoordelen',
    ];

    /**
     * Constructor for SitemapService.
     *
     * @param ContainerInterface $container       Server container for dependency injection
     * @param IAppManager        $appManager      App manager for checking installed apps
     * @param SettingsService    $settingsService The settings service
     * @param IURLGenerator      $urlGenerator    The Nextcloud URL generator
     * @param IAppConfig         $config          App configuration (operator-tunable page size)
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly IAppManager $appManager,
        private readonly SettingsService $settingsService,
        private readonly IURLGenerator $urlGenerator,
        private readonly IAppConfig $config,
        private readonly TooiVocabularyService $tooiVocabulary,
    ) {

    }//end __construct()

    /**
     * Per-request cache of resolved organisation → TOOI identifier lookups.
     *
     * @var array<string, string|null>
     */
    private array $orgTooiCache = [];

    /**
     * Resolve the operator-configured maximum publications per sitemap page.
     *
     * Reads `sitemap_max_per_page` from IAppConfig, falling back to
     * DEFAULT_MAX_PER_PAGE. Values below 1 are clamped to 1.
     *
     * @return int The effective per-page limit.
     *
     * @spec openspec/specs/opencatalogi-adopt-or-abstractions/spec.md (Requirement: Promote hardcoded constants)
     */
    private function getMaxPerPage(): int
    {
        $value = $this->config->getValueInt(self::APP_NAME, self::CONFIG_MAX_PER_PAGE, self::DEFAULT_MAX_PER_PAGE);
        return max(1, $value);

    }//end getMaxPerPage()

    /**
     * Attempts to retrieve the OpenRegister ObjectService from the container.
     *
     * @return \OCA\OpenRegister\Service\ObjectService|null The OpenRegister ObjectService if available, null otherwise.
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     */
    private function getObjectService(): ?\OCA\OpenRegister\Service\ObjectService
    {
        if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        }

        throw new RuntimeException('OpenRegister service is not available.');

    }//end getObjectService()

    /**
     * Attempts to retrieve the OpenRegister FileService from the container.
     *
     * @return \OCA\OpenRegister\Service\FileService|null The OpenRegister FileService if available, null otherwise.
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     */
    private function getFileService(): ?\OCA\OpenRegister\Service\FileService
    {
        if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
            return $this->container->get('OCA\OpenRegister\Service\FileService');
        }

        throw new RuntimeException('OpenRegister FileService is not available.');

    }//end getFileService()

    /**
     * Build sitemap index based on woo category.
     *
     * @param string $catalogSlug  The catalog slug identifier.
     * @param string $categoryCode The DIWOO category code.
     *
     * @return XMLResponse The sitemap index XML response.
     *
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     *
     * @spec openspec/specs/woo-compliance/spec.md
     */
    public function buildSitemapIndex(string $catalogSlug, string $categoryCode): XMLResponse
    {
        $catalog = (object) [];
        // Create reference for $catalog.
        $registerId = null;
        // Create reference for $registerId.
        $schemaId = null;
        // Create reference for $catalogId.
        $objectService = $this->getObjectService();
        $isValid       = $this->isValidSitemapRequest(
            catalogSlug: $catalogSlug,
            categoryCode: $categoryCode,
            objectService: $objectService,
            catalog: $catalog,
            registerId: $registerId,
            schemaId: $schemaId
        );
        if ($isValid instanceof XMLResponse === true) {
            return $isValid;
        }

        $searchQuery = [];
        $searchQuery['@self']['register'] = $registerId;
        $searchQuery['@self']['schema']   = $schemaId;
        $searchQuery['_order']['updated'] = 'desc';
        $searchQuery['_limit']            = $this->getMaxPerPage();
        $page = 1;

        // First call: only to retrieve total publications count.
        $firstPage = $objectService->searchObjectsPaginated(
            query: $searchQuery,
            _rbac: true,
            _multitenancy: false,
            deleted: false
        );

        // Visibility is governed by RBAC above (_rbac: true) — sitemaps expose only the
        // published objects the public group may read.
        $baseUrl = rtrim($this->urlGenerator->getBaseUrl(), '/');

        if (empty($firstPage['results']) === true) {
            return new XMLResponse(
                [
                    '@root'       => 'sitemapindex',
                    '@attributes' => ['xmlns' => 'http://www.sitemaps.org/schemas/sitemap/0.9'],
                    'sitemap'     => [],
                ]
            );
        }

        // Determine lastMod for this specific batch.
        $lastModObject = $firstPage['results'][0];
        // First item, sorted DESC.
        $lastMod = $lastModObject->jsonSerialize()['@self']['updated'] ?? null;

        $sitemaps       = [];
        $sitemapBaseUri = "$baseUrl/apps/opencatalogi/api/{$catalog->getSlug()}/sitemaps/$categoryCode/publications";
        // Add sitemap entry.
        $sitemaps[] = [
            'loc'     => "$sitemapBaseUri?page=$page",
            'lastmod' => $lastMod,
        ];

        $next = $firstPage['next'] ?? null;

        while ($next !== null) {
            $page++;

            // Fetch the current 1000-publication batch.
            $searchQuery['_page'] = $page;

            $batch = $objectService->searchObjectsPaginated(
                query: $searchQuery,
                _rbac: true,
                _multitenancy: false,
                deleted: false
            );

            // Visibility governed by RBAC on the paginated search above (_rbac: true).
            $next    = $batch['next'] ?? null;
            $results = ($batch['results'] ?? []);

            // Determine lastMod for this specific batch.
            $lastMod = null;
            if (empty($results) === true) {
                break;
            }

            $lastModObject = $results[0];
            $lastMod       = $lastModObject->jsonSerialize()['@self']['updated'] ?? null;

            // Add sitemap entry.
            $sitemaps[] = [
                'loc'     => "$sitemapBaseUri?page=$page",
                'lastmod' => $lastMod,
            ];
        }//end while

        return new XMLResponse(
            [
                '@root'       => 'sitemapindex',
                '@attributes' => ['xmlns' => 'http://www.sitemaps.org/schemas/sitemap/0.9'],
                'sitemap'     => $sitemaps,
            ]
        );

    }//end buildSitemapIndex()

    /**
     * Build sitemap based on woo publications.
     *
     * @param string  $catalogSlug  The catalog slug identifier.
     * @param string  $categoryCode The DIWOO category code.
     * @param integer $page         The page number to retrieve.
     *
     * @return XMLResponse The publications sitemap XML response.
     *
     * @spec openspec/specs/woo-compliance/spec.md
     */
    public function buildSitemap(string $catalogSlug, string $categoryCode, int $page): XMLResponse
    {
        $registerId = null;
        // Create reference for $registerId.
        $schemaId = null;
        // Create reference for $catalogId.
        $objectService = $this->getObjectService();
        $isValid       = $this->isValidSitemapRequest(
            catalogSlug: $catalogSlug,
            categoryCode: $categoryCode,
            objectService: $objectService,
            registerId: $registerId,
            schemaId: $schemaId
        );
        if ($isValid instanceof XMLResponse === true) {
            return $isValid;
        }

        $searchQuery = [];
        $searchQuery['@self']['register'] = $registerId;
        $searchQuery['@self']['schema']   = $schemaId;
        $searchQuery['_limit']            = $this->getMaxPerPage();
        $searchQuery['_page'] = $page;

        $publicationResult = $objectService->searchObjectsPaginated(
            query: $searchQuery,
            _rbac: true,
            _multitenancy: false,
            deleted: false
        );

        // Visibility governed by RBAC on the search above (_rbac: true).
        $publications = ($publicationResult['results'] ?? []);

        $fileService = $this->getFileService();

        $xmlDiwooDocuments = [];
        foreach ($publications as $publication) {
            $publication = $publication->jsonSerialize();

            // Fetch files since searchObjectsPaginated does not return them.
            $publication['@self']['files'] = (
                $fileService->formatFiles(
                    $fileService->getFiles(object: $publication['id'])
                )['results'] ?? []
            );

            // Map each file to a separate DIWOO Document.
            foreach ($publication['@self']['files'] as $file) {
                if (isset($file['downloadUrl']) === false) {
                    continue;
                }

                $xmlDiwooDocuments[] = $this->mapDiwooDocument(publication: $publication, file: $file)['diwoo:Document'];
            }
        }

        $schemaLoc  = 'http://www.sitemaps.org/schemas/sitemap/0.9 ';
        $schemaLoc .= 'http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd ';
        $schemaLoc .= 'https://standaarden.overheid.nl/diwoo/metadata/ ';
        $schemaLoc .= 'https://standaarden.overheid.nl/diwoo/metadata/0.9.1/xsd/diwoo-metadata.xsd';

        $xmlContent = [
            '@root'          => 'diwoo:Documents',
            '@attributes'    => [
                'xmlns'              => 'http://www.sitemaps.org/schemas/sitemap/0.9',
                'xmlns:xsi'          => 'http://www.w3.org/2001/XMLSchema-instance',
                'xmlns:diwoo'        => 'https://standaarden.overheid.nl/diwoo/metadata/',
                'xsi:schemaLocation' => $schemaLoc,
                'xmlns:xhtml'        => 'http://www.w3.org/1999/xhtml',
                'xmlns:image'        => 'http://www.google.com/schemas/sitemap-image/1.1',
                'xmlns:video'        => 'http://www.google.com/schemas/sitemap-video/1.1',
                'xmlns:news'         => 'http://www.google.com/schemas/sitemap-news/0.9',
            ],
            'diwoo:Document' => $xmlDiwooDocuments,
        ];

        return new XMLResponse($xmlContent);

    }//end buildSitemap()

    /**
     * Validates a sitemap request and resolves catalog, schema, and register IDs.
     *
     * Ensures the requested category and catalog exist, resolves the Woo catalog,
     * verifies the schema belongs to the configured catalog, and returns either:
     * - true on success
     * - XMLResponse with error details on failure
     *
     * @param string      $catalogSlug   The slug of the Woo catalog
     * @param string      $categoryCode  The sitemap category code
     * @param object      $objectService The OpenRegister ObjectService
     * @param object|null $catalog       Resolved catalog object (output reference)
     * @param string|null $schemaId      Resolved schema ID (output reference)
     * @param string|null $registerId    Resolved register ID (output reference)
     *
     * @return boolean|XMLResponse Returns true if the request is valid, otherwise an XMLResponse error.
     *
     * @psalm-suppress InvalidArrayOffset Array offset types are runtime-determined.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     *
     * @spec openspec/specs/woo-compliance/spec.md
     */
    private function isValidSitemapRequest(
        string $catalogSlug,
        string $categoryCode,
        $objectService,
        &$catalog=null,
        &$schemaId=null,
        &$registerId=null
    ) {
        $settings = $this->settingsService->getSettings();

        if (isset($settings['availableRegisters']) === false) {
            return new XMLResponse('Could net fetch settings', 500);
        }

        $schemas = [];
        foreach ($settings['availableRegisters'] as $register) {
            if (strcasecmp(($register['title'] ?? ''), 'woo') === 0) {
                $registerId = $register['id'];
                $schemas    = $register['schemas'];
            }
        }

        if (isset($this::INFO_CAT[$categoryCode]) === false) {
            return new XMLResponse('Invalid category code', 400);
        }

        // Off case because our schema does not follow the woo category precisely.
        $schemas = array_map(
            function ($schema) {
                if (trim($schema['title']) === 'Jaarplan of jaarverslag') {
                    $schema['title'] = 'Jaarplannen en jaarverslagen';
                }

                return $schema;
            },
            $schemas
        );

        // Have to trim whitespace because of typo in schema title definition.
        $needle   = trim($this::INFO_CAT[$categoryCode]);
        $haystack = array_map(
            function ($sch) {
                return trim($sch['title']);
            },
            $schemas
        );

        // Get current schema belonging to requested category code.
        $index    = array_search(needle: $needle, haystack: $haystack);
        $schemaId = null;
        if ($index !== false && isset($schemas[$index]) === true) {
            $schemaId = $schemas[$index]['id'];
        }

        $searchQuery = [
            '@self'         => [
                'register' => $settings['configuration']['catalog_register'],
                'schema'   => $settings['configuration']['catalog_schema'],
            ],
            'slug'          => $catalogSlug,
            'hasWooSitemap' => true,
        ];

        $catalogResult = $objectService->searchObjectsPaginated(
            query: $searchQuery,
            _rbac: true,
            _multitenancy: false,
            deleted: false
        );

        // Visibility governed by RBAC on the search above (_rbac: true).
        $catalog = ($catalogResult['results'][0] ?? []);

        if (empty($catalog) === true) {
            return new XMLResponse('Invalid Woo catalog', 400);
        }

        // Check if schema in catalog.
        if (in_array(needle: $schemaId, haystack: $catalog->getObject()['schemas']) === false) {
            return new XMLResponse('Schema not configured in catalog', 400);
        }

        return true;

    }//end isValidSitemapRequest()

    /**
     * Maps publication + file metadata into a DIWOO Document XML array structure.
     *
     * Used to generate items inside diwoo:Documents.
     *
     * The three Woo-index-controlled axes — informatiecategorie, publisher
     * `@resource`, and soortHandeling — are resolved through the bundled TOOI/DiWoo
     * value lists ({@see TooiVocabularyService}). An axis that cannot resolve to an
     * official URI is OMITTED (never emitted as a free-text `@resource`) and a
     * `{documentLoc, axis, reason}` violation is appended to `$violations`, so the
     * national Woo-index (KOOP) never rejects the document on ingest (WOO-TOOI-001..004).
     *
     * @param array                             $publication The publication as returned from ObjectService
     * @param array                             $file        The file metadata belonging to that publication
     * @param array<int, array<string, string>> $violations  Per-document unresolved-axis report (by reference)
     *
     * @return array A DIWOO metadata array ready for XMLResponse
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     *
     * @spec openspec/specs/woo-compliance/spec.md
     */
    private function mapDiwooDocument(array $publication, array $file, array &$violations=[]): array
    {
        $created   = ($publication['@self']['created'] ?? 'now');
        $updated   = ($publication['@self']['updated'] ?? 'now');
        $published = ($file['published'] ?? $updated);
        $owner     = ($file['owner'] ?? $publication['@self']['owner'] ?? '');
        $loc       = ($file['downloadUrl'] ?? '');

        $formatUri  = "http://publications.europa.eu/resource/authority/file-type/";
        $formatUri .= strtoupper($file['extension']);

        // Diwoo:atTime — the moment the document was made public. Prefer the
        // file's own published timestamp, then the publication's publicatiedatum.
        // The removed object-level @self.published is always empty for the
        // magic-mapped publication objects, so it is no longer consulted.
        $handlingTime = ($file['published'] ?? $publication['publicatiedatum'] ?? date('Y-m-d H:i:s'));

        $diwoo = [
            'loc'                => $loc,
            'lastmod'            => date('Y-m-d H:i:s', strtotime($published)),
            'diwoo:creatiedatum' => date('Y-m-d', strtotime($created)),
        ];

        // Publisher: bind @resource to a valid TOOI organisatie URI, or omit it.
        $publisher = ['#text' => $owner];
        $orgUri    = $this->tooiVocabulary->resolveOrganisatie($this->resolveOrganisationTooiIdentifier($publication));
        if ($orgUri !== null) {
            $publisher['@resource'] = $orgUri;
        } else {
            $this->addViolation($violations, $loc, 'publisher', 'organisation has no TOOI identifier');
        }

        $diwoo['diwoo:publisher'] = $publisher;

        $diwoo['diwoo:format'] = [
            '@resource' => $formatUri,
            '#text'     => strtolower($file['extension']),
        ];

        // Informatiecategorie: bind to an official TOOI category URI, or omit.
        $categoryValue = $this->stringOrNull($publication['category'] ?? $publication['tooiCategorieUri'] ?? $publication['tooiCategorieNaam'] ?? null);
        $category      = $this->tooiVocabulary->resolveInformatiecategorie($categoryValue);
        if ($category !== null) {
            $diwoo['diwoo:classificatiecollectie'] = [
                'diwoo:informatiecategorieen' => [
                    'diwoo:informatiecategorie' => [
                        '#text'     => $category['label'],
                        '@resource' => $category['uri'],
                    ],
                ],
            ];
        } else {
            $this->addViolation($violations, $loc, 'informatiecategorie', 'category does not resolve to a TOOI value-list member');
        }

        // SoortHandeling: resolve through the DiWoo value list (default: ontvangst).
        $handling = $this->tooiVocabulary->resolveSoortHandeling($this->stringOrNull($publication['soortHandeling'] ?? null));
        if ($handling !== null) {
            $diwoo['diwoo:documenthandelingen'] = [
                'diwoo:documenthandeling' => [
                    'diwoo:soortHandeling' => [
                        '#text'     => $handling['label'],
                        '@resource' => $handling['uri'],
                    ],
                    'diwoo:atTime'         => $handlingTime,
                ],
            ];
        } else {
            $this->addViolation($violations, $loc, 'soortHandeling', 'handling type does not resolve to a DiWoo value-list member');
        }

        return ['diwoo:Document' => ['diwoo:DiWoo' => $diwoo]];

    }//end mapDiwooDocument()

    /**
     * Return the value as a string, or null when it is not a non-empty string.
     *
     * @param mixed $value The candidate value.
     *
     * @return string|null The string, or null.
     *
     * @spec exclude Type-narrowing plumbing.
     */
    private function stringOrNull(mixed $value): ?string
    {
        if (is_string($value) === true && trim($value) !== '') {
            return $value;
        }

        return null;

    }//end stringOrNull()

    /**
     * Append a DIWOO value-list violation record.
     *
     * @param array<int, array<string, string>> $violations The violation list (by reference).
     * @param string                            $loc        The document location (download URL).
     * @param string                            $axis       The unresolved axis.
     * @param string                            $reason     The human-readable reason.
     *
     * @return void
     *
     * @spec exclude Violation-record plumbing for the DIWOO validator.
     */
    private function addViolation(array &$violations, string $loc, string $axis, string $reason): void
    {
        $violations[] = ['documentLoc' => $loc, 'axis' => $axis, 'reason' => $reason];

    }//end addViolation()

    /**
     * Resolve the TOOI organisatie identifier for a publication's owning organisation.
     *
     * Prefers a publication-level `tooiIdentifier`, then a `@self.organisation` value
     * that is already a TOOI id URI, and finally the organisation object's own
     * `tooiIdentifier` property (resolved once per organisation via OpenRegister and
     * cached for the request). Returns null when none resolves.
     *
     * @param array<string, mixed> $publication The publication object.
     *
     * @return string|null The candidate TOOI organisatie identifier, or null.
     *
     * @spec openspec/specs/woo-compliance/spec.md
     */
    private function resolveOrganisationTooiIdentifier(array $publication): ?string
    {
        $direct = ($publication['tooiIdentifier'] ?? null);
        if (is_string($direct) === true && trim($direct) !== '') {
            return $direct;
        }

        $organisation = ($publication['@self']['organisation'] ?? null);
        if (is_string($organisation) === false || trim($organisation) === '') {
            return null;
        }

        // Already a TOOI id URI on the publication envelope.
        if (str_starts_with($organisation, TooiVocabularyService::ORG_ID_BASE) === true) {
            return $organisation;
        }

        if (array_key_exists($organisation, $this->orgTooiCache) === true) {
            return $this->orgTooiCache[$organisation];
        }

        $identifier = $this->fetchOrganisationTooiIdentifier($organisation);
        $this->orgTooiCache[$organisation] = $identifier;
        return $identifier;

    }//end resolveOrganisationTooiIdentifier()

    /**
     * Fetch an organisation's `tooiIdentifier` via OpenRegister by its UUID.
     *
     * @param string $organisation The organisation UUID.
     *
     * @return string|null The tooiIdentifier, or null when unresolved/unavailable.
     *
     * @spec exclude Object-lookup plumbing for the DIWOO publisher binding.
     */
    private function fetchOrganisationTooiIdentifier(string $organisation): ?string
    {
        try {
            $objectService = $this->getObjectService();
            $results       = $objectService->searchObjects(
                query: ['@self' => ['uuid' => $organisation]],
                _rbac: false,
                _multitenancy: false,
            );
        } catch (\Throwable $e) {
            return null;
        }

        if (is_array($results) === false || empty($results) === true) {
            return null;
        }

        $org     = $results[0];
        $orgData = [];
        if (is_array($org) === true) {
            $orgData = $org;
        } else if (method_exists($org, 'jsonSerialize') === true) {
            $orgData = $org->jsonSerialize();
        }

        return $this->stringOrNull($orgData['tooiIdentifier'] ?? null);

    }//end fetchOrganisationTooiIdentifier()

    /**
     * Dry-run the DIWOO mapping for a catalog and collect per-document violations.
     *
     * Runs the same {@see mapDiwooDocument()} path used to serve the sitemap, but
     * discards the XML and returns only the unresolved-axis report. Advisory: it
     * never prevents the sitemap from being served (WOO-TOOI-004).
     *
     * @param array<int, array<string, mixed>> $publications The catalog's publications (with `@self.files`).
     *
     * @return array<int, array<string, string>> The `{documentLoc, axis, reason}` violations.
     *
     * @spec openspec/specs/woo-compliance/spec.md
     */
    public function collectDiwooViolations(array $publications): array
    {
        $violations = [];
        foreach ($publications as $publication) {
            $files = ($publication['@self']['files'] ?? []);
            foreach ($files as $file) {
                if (isset($file['downloadUrl']) === false) {
                    continue;
                }

                $this->mapDiwooDocument(publication: $publication, file: $file, violations: $violations);
            }
        }

        return $violations;

    }//end collectDiwooViolations()

    /**
     * Validate a catalog's DIWOO output in a dry run (WOO-TOOI-004).
     *
     * Fetches the catalog's publicly visible publications and their files exactly
     * as {@see buildSitemap()} does, then reports every axis that could not resolve
     * to an official TOOI/DiWoo value-list URI. Advisory only — it does not serve
     * or block the sitemap.
     *
     * @param string  $catalogSlug  The catalog slug.
     * @param string  $categoryCode The DIWOO category code (e.g. `infocat014`).
     * @param integer $page         The 1-based page number.
     *
     * @return array{catalogSlug: string, categoryCode: string, valid: bool, violations: array<int, array<string, string>>, error?: string}
     *
     * @spec openspec/specs/woo-compliance/spec.md
     */
    public function validateDiwooOutput(string $catalogSlug, string $categoryCode, int $page=1): array
    {
        $registerId = null;
        $schemaId   = null;

        try {
            $objectService = $this->getObjectService();
        } catch (\Throwable $e) {
            return $this->diwooError($catalogSlug, $categoryCode, 'OpenRegister is not available');
        }

        $isValid = $this->isValidSitemapRequest(
            catalogSlug: $catalogSlug,
            categoryCode: $categoryCode,
            objectService: $objectService,
            registerId: $registerId,
            schemaId: $schemaId
        );
        if ($isValid instanceof XMLResponse === true) {
            return $this->diwooError($catalogSlug, $categoryCode, 'Unknown catalog or category');
        }

        $searchQuery = [];
        $searchQuery['@self']['register'] = $registerId;
        $searchQuery['@self']['schema']   = $schemaId;
        $searchQuery['_limit']            = $this->getMaxPerPage();
        $searchQuery['_page'] = max(1, $page);

        $publicationResult = $objectService->searchObjectsPaginated(
            query: $searchQuery,
            _rbac: true,
            _multitenancy: false,
            deleted: false
        );

        $fileService  = $this->getFileService();
        $publications = [];
        foreach (($publicationResult['results'] ?? []) as $publication) {
            if (is_array($publication) === false) {
                $publication = $publication->jsonSerialize();
            }

            $publication['@self']['files'] = (
                $fileService->formatFiles(
                    $fileService->getFiles(object: $publication['id'])
                )['results'] ?? []
            );
            $publications[] = $publication;
        }

        $violations = $this->collectDiwooViolations($publications);

        return [
            'catalogSlug'  => $catalogSlug,
            'categoryCode' => $categoryCode,
            'valid'        => ($violations === []),
            'violations'   => $violations,
        ];

    }//end validateDiwooOutput()

    /**
     * Build a DIWOO validator error report.
     *
     * @param string $catalogSlug  The catalog slug.
     * @param string $categoryCode The category code.
     * @param string $error        The error message.
     *
     * @return array{catalogSlug: string, categoryCode: string, valid: bool, violations: array<int, array<string, string>>, error: string}
     *
     * @spec exclude Error-shape plumbing for the DIWOO validator.
     */
    private function diwooError(string $catalogSlug, string $categoryCode, string $error): array
    {
        return [
            'catalogSlug'  => $catalogSlug,
            'categoryCode' => $categoryCode,
            'valid'        => false,
            'violations'   => [],
            'error'        => $error,
        ];

    }//end diwooError()
}//end class
