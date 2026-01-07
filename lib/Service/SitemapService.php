<?php

namespace OCA\OpenCatalogi\Service;

use OCP\App\IAppManager;
use OCA\OpenCatalogi\Http\XMLResponse;
use Psr\Container\ContainerInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use OCA\OpenCatalogi\Service\SettingsService;
use RuntimeException;
use OCP\IURLGenerator;

/**
 * Service for handling publication-related operations.
 *
 * Provides functionality for retrieving, saving, updating, and deleting publications,
 * as well as managing publication-related data and filters.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2025 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 */

/**
 * Service for handling publication-related operations.
 *
 * Provides functionality for retrieving, saving, updating, and deleting publications,
 * as well as managing publication-related data and filters.
 */
class SitemapService
{

    private const MAX_PER_PAGE = 1000;

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
     * @var string $appName The name of the app
     */
    private string $appName;


    /**
     * Constructor for PublicationService.
     *
     * @param SettingsService    $settingsService The settings service
     * @param ContainerInterface $container       Server container for dependency injection
     * @param IAppManager        $appManager      App manager for checking installed apps
     * @param IURLGenerator      $urlGenerator    The Nextcloud URL generator
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly IAppManager $appManager,
        private readonly SettingsService $settingsService,
        private readonly IURLGenerator $urlGenerator,
    ) {
        $this->appName = 'opencatalogi';

    }//end __construct()


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
     * Build sitemap index based on woo category
     *
     * @param string $catalogSlug
     * @param string $categoryCode
     *
     * @return XMLResponse
     */
    public function buildSitemapIndex(string $catalogSlug, string $categoryCode): XMLResponse
    {
        $catalog = (object) [];
// Create reference for $catalog
        $registerId = null;
// Create reference for $registerId
        $schemaId = null;
// Create reference for $catalogId
        $objectService = $this->getObjectService();
        $isValid       = $this->isValidSitemapRequest(catalogSlug: $catalogSlug, categoryCode: $categoryCode, objectService: $objectService, catalog: $catalog, registerId: $registerId, schemaId: $schemaId);
        if ($isValid instanceof XMLResponse === true) {
            return $isValid;
        }

        $searchQuery                      = [];
        $searchQuery['@self']['register'] = $registerId;
        $searchQuery['@self']['schema']   = $schemaId;
        $searchQuery['_order']['updated'] = 'desc';
        $searchQuery['_limit']            = $this::MAX_PER_PAGE;
        $page                             = 1;

        // First call: only to retrieve total publications count
        $firstPage = $objectService->searchObjectsPaginated(
            query: $searchQuery,
            _rbac: false,
            _multitenancy: false,
            published: true,
            // must be published
            deleted: false
        );

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

        // Determine lastMod for this specific batch
        $lastMod       = null;
        $lastModObject = $firstPage['results'][0];
// first item, sorted DESC
        $lastMod = $lastModObject->jsonSerialize()['@self']['updated'] ?? null;

        $sitemaps       = [];
        $sitemapBaseUri = "$baseUrl/apps/opencatalogi/api/{$catalog->getSlug()}/sitemaps/$categoryCode/publications";
        // Add sitemap entry
        $sitemaps[] = [
            'loc'     => "$sitemapBaseUri?page=$page",
            'lastmod' => $lastMod,
        ];

        $next = $firstPage['next'] ?? null;

        while ($next) {
            $page++;

            // Fetch the current 1000-publication batch
            $searchQuery['_page'] = $page;

            $batch = $objectService->searchObjectsPaginated(
                query: $searchQuery,
                _rbac: false,
                _multitenancy: false,
                published: true,
                // must be published
                deleted: false
            );

            $next    = $batch['next'] ?? null;
            $results = ($batch['results'] ?? []);

            // Determine lastMod for this specific batch
            $lastMod = null;
            if (empty($results) === false) {
                $lastModObject = $results[0];
// first item, sorted DESC
                $lastMod = $lastModObject->jsonSerialize()['@self']['updated'] ?? null;
            } else {
                // If no results, break
                break;
            }

            // Add sitemap entry
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
     * Build sitemap based on woo publications
     *
     * @param string  $catalogSlug
     * @param string  $categoryCode
     * @param integer $page
     *
     * @return XMLResponse
     */
    public function buildSitemap(string $catalogSlug, string $categoryCode, int $page): XMLResponse
    {
        $registerId = null;
// Create reference for $registerId
        $schemaId = null;
// Create reference for $catalogId
        $objectService = $this->getObjectService();
        $isValid       = $this->isValidSitemapRequest(catalogSlug: $catalogSlug, categoryCode: $categoryCode, objectService: $objectService, registerId: $registerId, schemaId: $schemaId);
        if ($isValid instanceof XMLResponse === true) {
            return $isValid;
        }

        $searchQuery                      = [];
        $searchQuery['@self']['register'] = $registerId;
        $searchQuery['@self']['schema']   = $schemaId;
        $searchQuery['_limit']            = $this::MAX_PER_PAGE;
        $searchQuery['_page']             = $page;

        $publications = ($objectService->searchObjectsPaginated(
            query: $searchQuery,
            _rbac: false,
            _multitenancy: false,
            published: true,
            // must be published publications
            deleted: false
        )['results'] ?? []);

        $fileService = $this->getFileService();

        $xmlDiwooDocuments = [];
        foreach ($publications as $publication) {
            $publication = $publication->jsonSerialize();

            // This should not be necessary but objectService->searchObjectsPaginated does not return files
            $publication['@self']['files'] = ($fileService->formatFiles($fileService->getFiles(object: $publication['id']))['results'] ?? []);

            // Map each file to a separate DIWOO Document
            foreach ($publication['@self']['files'] as $file) {
                if (isset($file['downloadUrl']) === false) {
                    continue;
                }

                $xmlDiwooDocuments[] = $this->mapDiwooDocument($publication, $file)['diwoo:Document'];
            }
        }

        $xmlContent = [
            '@root'          => 'diwoo:Documents',
            '@attributes'    => [
                'xmlns'              => 'http://www.sitemaps.org/schemas/sitemap/0.9',
                'xmlns:xsi'          => 'http://www.w3.org/2001/XMLSchema-instance',
                'xmlns:diwoo'        => 'https://standaarden.overheid.nl/diwoo/metadata/',
                'xsi:schemaLocation' => 'http://www.sitemaps.org/schemas/sitemap/0.9 http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd https://standaarden.overheid.nl/diwoo/metadata/ https://standaarden.overheid.nl/diwoo/metadata/0.9.1/xsd/diwoo-metadata.xsd',
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
     * @param string                                  $catalogSlug   The slug of the Woo catalog
     * @param string                                  $categoryCode  The sitemap category code
     * @param \OCA\OpenRegister\Service\ObjectService $objectService The OpenRegister ObjectService
     * @param object|null                             $catalog       Resolved catalog object (output reference)
     * @param string|null                             $schemaId      Resolved schema ID (output reference)
     * @param string|null                             $registerId    Resolved register ID (output reference)
     *
     * @return boolean|XMLResponse Returns true if the request is valid, otherwise an XMLResponse error
     */
    private function isValidSitemapRequest(string $catalogSlug, string $categoryCode, $objectService, &$catalog = null, &$schemaId = null, &$registerId = null)
    {
        $settings = $this->settingsService->getSettings();

        if (isset($settings['availableRegisters']) === false) {
            return new XMLResponse('Could net fetch settings', 500);
        }

        foreach ($settings['availableRegisters'] as $register) {
            if (strcasecmp(($register['title'] ?? ''), 'woo') === 0) {
                $registerId = $register['id'];
                $schemas    = $register['schemas'];
            }
        }

        if (isset($this::INFO_CAT[$categoryCode]) === false) {
            return new XMLResponse('Invalid category code', 400);
        }

        // Off case because our schema doesnt follow the woo category precisely
        $schemas = array_map(
            function ($schema) {
                if (trim($schema['title']) === 'Jaarplan of jaarverslag') {
                    $schema['title'] = 'Jaarplannen en jaarverslagen';
                }

                return $schema;
            },
            $schemas
        );

        // Have to trim whitespcae because of typo in schema title definition..
        $needle   = trim($this::INFO_CAT[$categoryCode]);
        $haystack = array_map(fn($s) => trim($s['title']), $schemas);

        // Get current schema belonging to requested category code.
        $index    = array_search(needle: $needle, haystack: $haystack);
        $schemaId = $index !== false ? $schemas[$index]['id'] : null;

        $searchQuery['@self']['register'] = $settings['configuration']['catalog_register'];
        $searchQuery['@self']['schema']   = $settings['configuration']['catalog_schema'];
        $searchQuery['slug']              = $catalogSlug;
        $searchQuery['hasWooSitemap']     = true;

        $catalog = ($objectService->searchObjectsPaginated(
            query: $searchQuery,
            _rbac: false,
            _multitenancy: false,
            published: false,
            deleted: false
        )['results'][0] ?? []);

        if (!$catalog) {
            return new XMLResponse('Invalid Woo catalog', 400);
        }

        // Check if schema in catalog
        if (in_array(needle: $schemaId, haystack: $catalog->getObject()['schemas']) === false) {
            return new XMLResponse('Schema not configured in catalog', 400);
        }

        return true;

    }//end isValidSitemapRequest()


    /**
     * Maps publication + file metadata into a DIWOO Document XML array structure.
     *
     * Used to generate items inside <diwoo:Documents>.
     *
     * @param array $publication The publication as returned from ObjectService
     * @param array $file        The file metadata belonging to that publication
     *
     * @return array A DIWOO metadata array ready for XMLResponse
     */
    private function mapDiwooDocument(array $publication, array $file): array
    {
        return [
            'diwoo:Document' => [
                'diwoo:DiWoo' => [
                    'loc'                          => $file['downloadUrl'],
                    'lastmod'                      => date('Y-m-d H:i:s', strtotime(($file['published'] ?? $publication['@self']['updated'] ?? 'now'))),
                    'diwoo:creatiedatum'           => date('Y-m-d', strtotime(($publication['@self']['created'] ?? 'now'))),
                    'diwoo:publisher'              => [
                        '@resource' => ($publication['@self']['organisation'] ?? 'PLACEHOLDER_ORG_URI'),
                        '#text'     => ($file['owner'] ?? $publication['@self']['owner'] ?? 'PLACEHOLDER_OWNER'),
                    ],
                    'diwoo:format'                 => [
                        '@resource' => "http://publications.europa.eu/resource/authority/file-type/".strtoupper($file['extension']),
                        '#text'     => strtolower($file['extension']),
                    ],
                    'diwoo:classificatiecollectie' => [
                        'diwoo:informatiecategorieen' => [
                            'diwoo:informatiecategorie' => [
                                '#text'     => ($publication['tooiCategorieNaam'] ?? 'PLACEHOLDER_CATEGORY'),
                                '@resource' => ($publication['tooiCategorieUri'] ?? 'PLACEHOLDER_CATEGORY_URI'),
                            ],
                        ],
                    ],
                    'diwoo:documenthandelingen'    => [
                        'diwoo:documenthandeling' => [
                            'diwoo:soortHandeling' => [
                                '#text'     => 'ontvangst',
                                '@resource' => 'https://identifier.overheid.nl/tooi/def/thes/kern/c_dfcee535',
                            ],
                            'diwoo:atTime'         => ($file['published'] ?? $publication['@self']['published'] ?? date('Y-m-d H:i:s')),
                        ],
                    ],
                ],
            ],
        ];

    }//end mapDiwooDocument()


}//end class
