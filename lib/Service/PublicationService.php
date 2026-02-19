<?php
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
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 */

namespace OCA\OpenCatalogi\Service;

use OCP\IRequest;
use OCP\IAppConfig;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use Psr\Container\ContainerInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use OCP\AppFramework\Http\JSONResponse;
use Exception;
use OCP\Common\Exception\NotFoundException;
use OCP\IURLGenerator;
use OCP\IServerContainer;

/**
 * Service for handling publication-related operations.
 *
 * Provides functionality for retrieving, saving, updating, and deleting publications,
 * as well as managing publication-related data and filters.
 */
class PublicationService
{

    /**
     * @var string $appName The name of the app
     */
    private string $appName;

    /**
     * @var array<string> List of available registers from catalogs
     */
    private array $availableRegisters = [];

    /**
     * @var array<string> List of available schemas from catalogs
     */
    private array $availableSchemas = [];

    /**
     * @var array|null Cached local catalogs to avoid repeated database queries
     */
    private ?array $cachedLocalCatalogs = null;

    /**
     * @var array Cached catalog filters by catalog ID to avoid repeated database queries
     */
    private array $cachedCatalogFilters = [];


    /**
     * Constructor for PublicationService.
     *
     * @param IAppConfig       $config           App configuration interface
     * @param IRequest         $request          Request interface
     * @param IServerContainer $container        Server container for dependency injection
     * @param IAppManager      $appManager       App manager for checking installed apps
     * @param DirectoryService $directoryService Directory service for federation
     * @param IURLGenerator    $urlGenerator     URL generator for building URLs
     */
    public function __construct(
        private readonly IAppConfig $config,
        private readonly IRequest $request,
        private readonly ContainerInterface $container,
        private readonly IAppManager $appManager,
        private readonly DirectoryService $directoryService,
        private readonly IURLGenerator $urlGenerator,
    ) {
        $this->appName = 'opencatalogi';

    }//end __construct()


    /**
     * Attempts to retrieve the OpenRegister service from the container.
     *
     * @return mixed|null The OpenRegister service if available, null otherwise.
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     */
    public function getObjectService(): ?\OCA\OpenRegister\Service\ObjectService
    {
        if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
            $this->objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            return $this->objectService;
        }

        throw new \RuntimeException('OpenRegister service is not available.');

    }//end getObjectService()


    /**
     * Attempts to retrieve the OpenRegister service from the container.
     *
     * @return mixed|null The OpenRegister service if available, null otherwise.
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     */
    public function getFileService(): ?\OCA\OpenRegister\Service\FileService
    {
        if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
            $this->objectService = $this->container->get('OCA\OpenRegister\Service\FileService');

            return $this->objectService;
        }

        throw new \RuntimeException('OpenRegister service is not available.');

    }//end getFileService()


    /**
     * Get register and schema combinations from catalogs.
     *
     * This method retrieves all catalogs (or a specific one if ID is provided),
     * extracts their registers and schemas, and stores them as general variables.
     *
     * @param  string|integer|null $catalogId Optional ID of a specific catalog to filter by
     * @return array<string, array<string>> Array containing available registers and schemas
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     */
    public function getCatalogFilters(null|string|int $catalogId = null): array
    {
        // Create cache key based on catalog ID
        $cacheKey = $catalogId === null ? 'all' : (string) $catalogId;

        // Return cached result if available
        if (isset($this->cachedCatalogFilters[$cacheKey])) {
            // Restore class properties from cache
            $cached                   = $this->cachedCatalogFilters[$cacheKey];
            $this->availableRegisters = $cached['availableRegisters'];
            $this->availableSchemas   = $cached['availableSchemas'];
            return $cached['result'];
        }

        // Establish the default schema and register
        $schema   = $this->config->getValueString($this->appName, 'catalog_schema', '');
        $register = $this->config->getValueString($this->appName, 'catalog_register', '');

        $config = [];
        if ($catalogId !== null) {
            $catalogs = [$this->getObjectService()->find($catalogId)];
        } else {
            // Setup the config array for searchObjects
            $query = [
                '@self' => [
                    'register' => $register,
                    'schema'   => $schema,
                ],
            ];
            // Get all catalogs using searchObjects
            $catalogs = $this->getObjectService()->searchObjects($query);
        }

        // Initialize arrays to store unique registers and schemas
        $uniqueRegisters = [];
        $uniqueSchemas   = [];

        // Iterate over each catalog to extract registers and schemas
        foreach ($catalogs as $catalog) {
            $catalog = $catalog->jsonSerialize();
            // Check if 'registers' is an array and merge unique values
            if (isset($catalog['registers']) && is_array($catalog['registers'])) {
                $uniqueRegisters = array_merge($uniqueRegisters, $catalog['registers']);
            }

            // Check if 'schemas' is an array and merge unique values
            if (isset($catalog['schemas']) && is_array($catalog['schemas'])) {
                $uniqueSchemas = array_merge($uniqueSchemas, $catalog['schemas']);
            }
        }

        // Remove duplicate values and assign to class properties
        $this->availableRegisters = array_unique($uniqueRegisters);
        $this->availableSchemas   = array_unique($uniqueSchemas);

        $result = [
            'registers' => array_values($this->availableRegisters),
            'schemas'   => array_values($this->availableSchemas),
        ];

        // Cache the result and class properties
        $this->cachedCatalogFilters[$cacheKey] = [
            'result'             => $result,
            'availableRegisters' => $this->availableRegisters,
            'availableSchemas'   => $this->availableSchemas,
        ];

        return $result;

    }//end getCatalogFilters()


    /**
     * Get the list of available registers.
     *
     * @return array<string> List of available registers
     */
    public function getAvailableRegisters(): array
    {
        return $this->availableRegisters;

    }//end getAvailableRegisters()


    /**
     * Get the list of available schemas.
     *
     * @return array<string> List of available schemas
     */
    public function getAvailableSchemas(): array
    {
        return $this->availableSchemas;

    }//end getAvailableSchemas()


    /**
     * Generic method to search publications with catalog filtering and security
     *
     * This method provides a common interface for searching publications across all endpoints.
     * It handles catalog context validation, security parameters, and consistent filtering.
     *
     * @param  null|string|integer $catalogId    Optional catalog ID to filter objects by
     * @param  array|null          $ids          Optional array of specific IDs to filter by
     * @param  array|null          $customParams Optional custom parameters to use instead of request params
     * @return array Array containing search results with pagination and facets
     * @throws \InvalidArgumentException When invalid registers or schemas are requested
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     */
    private function searchPublications(null|string|int $catalogId = null, ?array $ids = null, ?array $customParams = null): array
    {
        // Use custom parameters if provided, otherwise use request parameters
        $searchQuery = ($customParams ?? $this->request->getParams());

        // @todo this is a temporary fix to map the parameters to _extend format
        // Define parameters that should be mapped to _extend format
        $parametersToMap = [
            'extend',
            'fields',
            'facets',
            'order',
            'page',
            'limit',
        ];

        // Map specified parameters to _extend format and unset originals
        foreach ($parametersToMap as $param) {
            if (isset($searchQuery[$param])) {
                // Map the parameter to _extend format
                $searchQuery['_extend'] = $searchQuery[$param];
                // Unset the original parameter to prevent conflicts
                unset($searchQuery[$param]);
            }
        }

        // Bit of route cleanup
        unset($searchQuery['id']);
        unset($searchQuery['_route']);

        // Filter out virtual field facet requests before passing to OpenRegister
        // Directory and catalogs are virtual fields injected at runtime, not database columns
        $requestedDirectoryFacets = false;
        $requestedCatalogFacets   = false;

        if (isset($searchQuery['_facets']['@self']['directory'])) {
            $requestedDirectoryFacets = true;
            unset($searchQuery['_facets']['@self']['directory']);
        }

        if (isset($searchQuery['_facets']['@self']['catalogs'])) {
            $requestedCatalogFacets = true;
            unset($searchQuery['_facets']['@self']['catalogs']);
        }

        // Get the context for the catalog
        $context = $this->getCatalogFilters($catalogId);

        // Validate requested registers and schemas against the context
        $requestedRegisters = ($searchQuery['@self']['register'] ?? []);
        $requestedSchemas   = ($searchQuery['@self']['schema'] ?? []);

        // Ensure requested registers are part of the context
        if (!empty($requestedRegisters)) {
            // Normalize to array if a single value is provided
            $requestedRegisters = is_array($requestedRegisters) ? $requestedRegisters : [$requestedRegisters];
            if (array_diff($requestedRegisters, $context['registers'])) {
                throw new \InvalidArgumentException('Invalid register(s) requested');
            }
        }

        // Ensure requested schemas are part of the context
        if (!empty($requestedSchemas)) {
            // Normalize to array if a single value is provided
            $requestedSchemas = is_array($requestedSchemas) ? $requestedSchemas : [$requestedSchemas];
            if (array_diff($requestedSchemas, $context['schemas'])) {
                throw new \InvalidArgumentException('Invalid schema(s) requested');
            }
        }

        // Get the object service
        $objectService = $this->getObjectService();

        // Overwrite certain values in the existing search query
        // Use scalar value when only one register/schema to avoid magic_mapper overhead
        $registers = $requestedRegisters ?: $context['registers'];
        $schemas   = $requestedSchemas ?: $context['schemas'];
        $searchQuery['@self']['register'] = count($registers) === 1 ? $registers[0] : $registers;
        $searchQuery['@self']['schema']   = count($schemas) === 1 ? $schemas[0] : $schemas;
        $searchQuery['_includeDeleted']   = false;

        // Add IDs filter if provided (for uses/used functionality)
        if ($ids !== null && !empty($ids)) {
            $searchQuery['_ids'] = $ids;
        }

        // Search objects using the new structure
        $result = $objectService->searchObjectsPaginated($searchQuery);

        // Filter unwanted properties from results
        $result['results'] = $this->filterUnwantedProperties($result['results']);

        // Add virtual field facets if they were requested
        if (($requestedDirectoryFacets || $requestedCatalogFacets) && isset($result['facets'])) {
            $result['facets'] = $this->addVirtualFieldFacets($result['facets'], $requestedDirectoryFacets, $requestedCatalogFacets);
        }

        return $result;

    }//end searchPublications()


    /**
     * Get external catalogs from listings stored in the directory service
     *
     * This method retrieves all listings and extracts catalog information from them
     * to provide catalog facets that include external/federated catalogs.
     *
     * @return array Array of catalog objects with 'key' and 'label' fields
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     */
    private function getExternalCatalogsFromListings(): array
    {
        try {
            // Get the directory (which includes listings)
            $directoryResult = $this->directoryService->getDirectory();
            $listings        = ($directoryResult['results'] ?? []);

            $externalCatalogs = [];
            $seenCatalogs     = [];

            foreach ($listings as $listing) {
                // Extract catalog information from listing
                $catalogId    = ($listing['catalog'] ?? $listing['id'] ?? null);
                $catalogTitle = ($listing['title'] ?? $catalogId ?? 'Unknown Catalog');

                if ($catalogId && !isset($seenCatalogs[$catalogId])) {
                    $externalCatalogs[]       = [
                        'key'   => $catalogId,
                        'label' => $catalogTitle,
                    ];
                    $seenCatalogs[$catalogId] = true;
                }
            }

            return $externalCatalogs;
        } catch (\Exception $e) {
            // If we can't get external catalog information, return empty array
            return [];
        }//end try

    }//end getExternalCatalogsFromListings()


    /**
     * Add virtual field facets manually (directory and catalog facets)
     *
     * This method handles faceting for virtual fields that are injected at runtime
     * rather than stored as real database columns. It supports directory and catalog facets.
     *
     * @param  array   $existingFacets         The existing facets from the search result
     * @param  boolean $includeDirectoryFacets Whether to include directory facets
     * @param  boolean $includeCatalogFacets   Whether to include catalog facets
     * @return array The facets with virtual field facets added
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     */
    private function addVirtualFieldFacets(array $existingFacets, bool $includeDirectoryFacets = false, bool $includeCatalogFacets = false): array
    {
        try {
            // Ensure @self section exists
            if (!isset($existingFacets['@self'])) {
                $existingFacets['@self'] = [];
            }

            // Add directory facets if requested
            if ($includeDirectoryFacets) {
                // Get unique directories from the directory service
                $uniqueDirectories = $this->directoryService->getUniqueDirectories(availableOnly: true);

                // Add local directory
                $directoryBuckets = [
                    [
                        'key'     => 'local',
                        'label'   => 'local',
                        'results' => 1,
// We'll count this properly later if needed
                    ],
                ];

                // Add federated directories
                foreach ($uniqueDirectories as $directoryUrl) {
                    $directoryName      = parse_url($directoryUrl, PHP_URL_HOST) ?: $directoryUrl;
                    $directoryBuckets[] = [
                        'key'     => $directoryName,
                        'label'   => $directoryName,
                        'results' => 1,
// We'll count this properly later if needed
                    ];
                }

                $existingFacets['@self']['directory'] = [
                    'type'    => 'terms',
                    'buckets' => $directoryBuckets,
                ];
            }//end if

            // Add catalog facets if requested
            if ($includeCatalogFacets) {
                $catalogBuckets = [];

                // Get local catalogs
                $localCatalogs = $this->getLocalCatalogs();
                foreach ($localCatalogs as $catalog) {
                    $catalogKey   = $catalog['id'] ?: ($catalog['title'] ?: 'unknown');
                    $catalogLabel = $catalog['title'] ?: $catalog['id'] ?: 'unknown';

                    $catalogBuckets[] = [
                        'key'     => $catalogKey,
                        'label'   => $catalogLabel,
                        'results' => 1,
// We'll count this properly later if needed
                    ];
                }

                // Get external catalogs from listings
                $externalCatalogs = $this->getExternalCatalogsFromListings();
                foreach ($externalCatalogs as $catalog) {
                    $catalogKey   = $catalog['key'];
                    $catalogLabel = $catalog['label'];

                    // Check if this catalog is already in our list to avoid duplicates
                    $exists = false;
                    foreach ($catalogBuckets as $existingCatalog) {
                        if ($existingCatalog['key'] === $catalogKey) {
                            $exists = true;
                            break;
                        }
                    }

                    if (!$exists) {
                        $catalogBuckets[] = [
                            'key'     => $catalogKey,
                            'label'   => $catalogLabel,
                            'results' => 1,
// We'll count this properly later if needed
                        ];
                    }
                }//end foreach

                // If no catalogs found, add a default entry
                if (empty($catalogBuckets)) {
                    $catalogBuckets[] = [
                        'key'     => 'default',
                        'label'   => 'Default Catalog',
                        'results' => 1,
                    ];
                }

                $existingFacets['@self']['catalogs'] = [
                    'type'    => 'terms',
                    'buckets' => $catalogBuckets,
                ];
            }//end if

            return $existingFacets;
        } catch (\Exception $e) {
            // If we can't get virtual field information, return existing facets unchanged
            return $existingFacets;
        }//end try

    }//end addVirtualFieldFacets()


    /**
     * Retrieves a list of all objects for a specific register and schema
     *
     * This method returns a paginated list of objects that match the specified register and schema.
     * It supports filtering, sorting, and pagination through query parameters using the new search structure.
     *
     * @param  null|string|integer $catalogId    Optional catalog ID to filter objects by
     * @param  array|null          $customParams Optional custom parameters to use instead of request params
     * @return JSONResponse A JSON response containing the list of objects
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     */
    public function index(null|string|int $catalogId = null, ?array $customParams = null): JSONResponse
    {
        try {
            $result = $this->searchPublications($catalogId, null, $customParams);
            return new JSONResponse($result);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], 400);
        }

    }//end index()


    /**
     * Shows a specific object from a register and schema
     *
     * Retrieves and returns a single object from the specified register and schema,
     * with support for field filtering and related object extension.
     *
     * @param string        $id            The object ID
     * @param string        $register      The register slug or identifier
     * @param string        $schema        The schema slug or identifier
     * @param ObjectService $objectService The object service
     *
     * @return JSONResponse A JSON response containing the object
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function show(string $id): JSONResponse
    {

        // Get request parameters for filtering and searching.
        $requestParams = $this->request->getParams();

        // @todo validate if it in the calaogue etc etc (this is a bit dangerues now)        // Extract parameters for rendering.
        // $filter = ($requestParams['filter'] ?? $requestParams['_filter'] ?? null);
        // $fields = ($requestParams['fields'] ?? $requestParams['_fields'] ?? null);        // Find and validate the object.
        $extend = ($requestParams['extend'] ?? $requestParams['_extend'] ?? null);
        // Normalize to array
        $extend = is_array($extend) ? $extend : [$extend];
        // Filter only values that start with '@self.'
        $extend = array_filter($extend, fn($val) => is_string($val) && str_starts_with($val, '@self.'));

        try {
            // Render the object with requested extensions and filters.
            // Use positional parameters for compatibility with different ObjectService versions
            return new JSONResponse(
                $this->getObjectService()->find($id, $extend)
            );
        } catch (DoesNotExistException $exception) {
            return new JSONResponse(['error' => 'Not Found'], 404);
        }//end try

    }//end show()


    /**
     * Shows attachments of a publication
     *
     * Retrieves and returns attachments of a publication using code from OpenRegister.
     *
     * @param string $id The object ID
     *
     * @return JSONResponse A JSON response containing attachments
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function attachments(string $id): JSONResponse
    {
        $object = $this->getObjectService()->find(id: $id, extend: [])->jsonSerialize();

        $fileService = $this->getFileService();

        try {
            // Get the raw files from the file service
            $files = $fileService->getFiles(object: $id, sharedFilesOnly: true);

            // Format the files with pagination using request parameters
            $formattedFiles = $fileService->formatFiles($files, $this->request->getParams());

            return new JSONResponse($formattedFiles);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Object not found'], 404);
        } catch (NotFoundException $e) {
            return new JSONResponse(['error' => 'Files folder not found'], 404);
        } catch (Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }//end try

    }//end attachments()


     /**
      * Download all files of an object as a ZIP archive
      *
      * This method creates a ZIP file containing all files associated with a specific object
      * and returns it as a downloadable file. The ZIP file includes all files stored in the
      * object's folder with their original names.
      *
      * @param string        $id            The identifier of the object to download files for
      * @param string        $register      The register (identifier or slug) to search within
      * @param string        $schema        The schema (identifier or slug) to search within
      * @param ObjectService $objectService The object service for handling object operations
      *
      * @return DataDownloadResponse|JSONResponse ZIP file download response or error response
      *
      * @throws ContainerExceptionInterface If there's an issue with dependency injection
      * @throws NotFoundExceptionInterface If the FileService dependency is not found
      *
      * @NoAdminRequired
      * @NoCSRFRequired
      */
    public function download(
        string $id
    ): DataDownloadResponse | JSONResponse {
        try {
            // Create the ZIP archive
            $fileService = $this->getFileService();
            $zipInfo     = $fileService->createObjectFilesZip($id);

            // Read the ZIP file content
            $zipContent = file_get_contents($zipInfo['path']);
            if ($zipContent === false) {
                // Clean up temporary file
                if (file_exists($zipInfo['path'])) {
                    unlink($zipInfo['path']);
                }

                throw new \Exception('Failed to read ZIP file content');
            }

            // Clean up temporary file after reading
            if (file_exists($zipInfo['path'])) {
                unlink($zipInfo['path']);
            }

            // Return the ZIP file as a download response
            return new DataDownloadResponse(
                $zipContent,
                $zipInfo['filename'],
                $zipInfo['mimeType']
            );
        } catch (DoesNotExistException $exception) {
            return new JSONResponse(['error' => 'Object not found'], 404);
        } catch (\Exception $exception) {
            return new JSONResponse(
                [
                    'error' => 'Failed to create ZIP file: '.$exception->getMessage(),
                ],
                500
            );
        }//end try

    }//end download()


    /**
     * Filter out unwanted properties from objects
     *
     * This method removes unwanted properties from the '@self' array in each object.
     * It ensures consistent object structure across all endpoints. Additionally, it checks
     * for a 'files' property within '@self' and ensures each file has a 'published' property.
     * Files without a 'published' property are removed.
     *
     * @param  array $objects Array of objects to filter
     * @return array Filtered array of objects
     */
    private function filterUnwantedProperties(array $objects): array
    {
        // List of properties to remove from @self
        $unwantedProperties = [
            'schemaVersion',
            'relations',
            'locked',
            'owner',
            'folder',
            'application',
            'validation',
            'retention',
            'size',
            'deleted',
        ];

        // Filter each object
        return array_map(
            function ($object) use ($unwantedProperties) {
                // Use jsonSerialize to get an array representation of the object
                $objectArray = $object->jsonSerialize();

                // Remove unwanted properties from the '@self' array
                if (isset($objectArray['@self']) && is_array($objectArray['@self'])) {
                    $objectArray['@self'] = array_diff_key($objectArray['@self'], array_flip($unwantedProperties));

                    // Check for 'files' property and filter files without 'published'
                    if (isset($objectArray['@self']['files']) && is_array($objectArray['@self']['files'])) {
                        $objectArray['@self']['files'] = array_filter(
                            $objectArray['@self']['files'],
                            function ($file) {
                                return isset($file['published']);
                            }
                        );
                    }
                }

                return $objectArray;
            },
            $objects
        );

    }//end filterUnwantedProperties()


    /**
     * Retrieves all objects that this publication references
     *
     * This method returns all objects that this publication uses/references. A -> B means that A (This publication) references B (Another object).
     *
     * @param  string $id The ID of the publication to retrieve relations for
     * @return JSONResponse A JSON response containing the related objects
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     */
    public function uses(string $id): JSONResponse
    {
        try {
            // Get the object service
            $objectService = $this->getObjectService();

            // Get the relations for the object
            $relationsArray = $objectService->find(id: $id)->getRelations();
            $relations      = array_values($relationsArray);

            // Check if relations array is empty
            if (empty($relations)) {
                // If relations is empty, return empty paginated response
                return new JSONResponse(
                    [
                        'results' => [],
                        'total'   => 0,
                        'page'    => 1,
                        'pages'   => 1,
                        'facets'  => [],
                    ]
                );
            }

            // Use the generic search function with the relation IDs
            $result = $this->searchPublications(catalogId: null, ids: $relations);

            return new JSONResponse($result);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], 400);
        }//end try

    }//end uses()


    /**
     * Retrieves all objects that use this publication
     *
     * This method returns all objects that reference (use) this publication. B -> A means that B (Another object) references A (This publication).
     *
     * @param  string $id The ID of the publication to retrieve uses for
     * @return JSONResponse A JSON response containing the referenced objects
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     */
    public function used(string $id): JSONResponse
    {
        try {
            // Get the object service
            $objectService = $this->getObjectService();

            // Get the relations for the object
            $relationsArray = $objectService->findByRelations($id);
            $relations      = array_map(static fn($relation) => $relation->getUuid(), $relationsArray);

            // Check if relations array is empty
            if (empty($relations)) {
                // If relations is empty, return empty paginated response
                return new JSONResponse(
                    [
                        'results' => [],
                        'total'   => 0,
                        'page'    => 1,
                        'pages'   => 1,
                        'facets'  => [],
                    ]
                );
            }

            // Use the generic search function with the relation IDs
            $result = $this->searchPublications(catalogId: null, ids: $relations);

            return new JSONResponse($result);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], 400);
        }//end try

    }//end used()


    /**
     * Get aggregated publications from local and federated sources
     *
     * This method handles both local and aggregated search results when the _aggregate
     * parameter is not set to false. It supports faceting when _facetable parameter is provided.
     *
     * PERFORMANCE OPTIMIZATIONS:
     * - Fast path for single catalog scenarios (no federation overhead)
     * - Optional catalog information via _include_catalogs parameter
     * - Cached expensive operations to reduce database queries
     * - Optimized timeouts for better responsiveness
     *
     * AGGREGATION FEATURES:
     * - Proper pagination: Collects sufficient data from all sources, merges and deduplicates,
     *   then applies pagination to ensure consistent totals and page counts
     * - Ordering: Supports _order parameters like _order[@self.published]=DESC to sort the
     *   combined dataset from all sources according to specified criteria
     * - Deduplication: Removes duplicate entries based on object ID across all sources
     * - Faceting: Merges facet data from multiple sources when _facetable=true
     *
     * @param  array  $queryParams   Query parameters for filtering, pagination, ordering, etc.
     * @param  array  $requestParams Original request parameters for building pagination links
     * @param  string $baseUrl       Base URL for building pagination links
     * @return array Response data containing publications, pagination info, and optionally facets
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     */
    public function getAggregatedPublications(array $queryParams = [], array $requestParams = [], string $baseUrl = ''): array
    {
        // Performance monitoring - start timing
        $startTime = microtime(true);

        // Extract pagination parameters
        $limit  = (int) ($queryParams['_limit'] ?? $queryParams['limit'] ?? 20);
        $page   = (int) ($queryParams['_page'] ?? $queryParams['page'] ?? 1);
        $offset = (int) ($queryParams['offset'] ?? (($page - 1) * $limit));

        // Ensure minimum values (limit=0 is valid for count/facets-only requests)
        $limit  = max(0, $limit);
        $page   = max(1, $page);
        $offset = max(0, $offset);

        // Add pagination parameters to query
        $queryParams['_limit'] = $limit;
        $queryParams['_page']  = $page;
        if ($offset > 0) {
            $queryParams['_offset'] = $offset;
        }

        // Check if aggregation is enabled (default: true, unless explicitly set to false)
        $aggregate       = ($queryParams['_aggregate'] ?? 'true');
        $shouldAggregate = $aggregate !== 'false' && $aggregate !== '0';

        // Check if catalog information should be included (performance optimization)
        $includeCatalogs = isset($queryParams['_include_catalogs']) && $queryParams['_include_catalogs'] !== 'false';

        // Check if faceting is requested
        $facetable           = $queryParams['_facetable'] ?? null;
        $shouldIncludeFacets = ($facetable === 'true' || $facetable === true);

        // Check if virtual field facets were specifically requested
        $requestedDirectoryFacets = isset($queryParams['_facets']['@self']['directory']);
        $requestedCatalogFacets   = isset($queryParams['_facets']['@self']['catalogs']);

        // PERFORMANCE OPTIMIZATION: Ultra-fast path for basic requests
        // Use ultra-fast path when no special processing is needed
        $useUltraFast = !$includeCatalogs && !$requestedDirectoryFacets && !$requestedCatalogFacets;

        // Allow explicit control via query parameter
        if (isset($queryParams['_ultra_fast'])) {
            $useUltraFast = $queryParams['_ultra_fast'] !== 'false' && $queryParams['_ultra_fast'] !== '0';
        }

        // If aggregation is disabled, use optimized paths
        if (!$shouldAggregate) {
            if ($useUltraFast) {
                return $this->getLocalPublicationsUltraFast($queryParams, $requestParams, $baseUrl, microtime(true));
            }

            return $this->getLocalPublicationsFast($queryParams, $requestParams, $baseUrl, $includeCatalogs, $requestedDirectoryFacets, $requestedCatalogFacets);
        }

        // Check if we have any federated directories to aggregate from
        try {
            $federatedDirectories = $this->directoryService->getUniqueDirectories(availableOnly: true);

            // If no federated directories available, use optimized paths
            if (empty($federatedDirectories)) {
                if ($useUltraFast) {
                    return $this->getLocalPublicationsUltraFast($queryParams, $requestParams, $baseUrl, microtime(true));
                }

                return $this->getLocalPublicationsFast($queryParams, $requestParams, $baseUrl, $includeCatalogs, $requestedDirectoryFacets, $requestedCatalogFacets);
            }
        } catch (\Exception $e) {
            // If we can't get directories info, fall back to optimized local paths
            if ($useUltraFast) {
                return $this->getLocalPublicationsUltraFast($queryParams, $requestParams, $baseUrl, microtime(true));
            }

            return $this->getLocalPublicationsFast($queryParams, $requestParams, $baseUrl, $includeCatalogs, $requestedDirectoryFacets, $requestedCatalogFacets);
        }

        // Note: @self.schema and @self.register are now provided at response @self level,
        // not duplicated in each result. No need to add them to _extend.

        // Get local publications first
        $localResponse = $this->index(null, $queryParams);
        $localData     = json_decode($localResponse->render(), true);

        // Add catalog and directory information to local publications
        // @todo This adds ~200ms overhead - consider making optional via query parameter
        $localResults = ($localData['results'] ?? []);
        if (!empty($localResults)) {
            $localCatalogs = $this->getLocalCatalogs();
            foreach ($localResults as &$publication) {
                if (isset($publication['@self']) && is_array($publication['@self'])) {
                    // Add directory information for faceting
                    $publication['@self']['directory'] = 'local';

                    // Add catalog information if available
                    if (!empty($localCatalogs)) {
                        $publication['@self']['catalogs'] = $localCatalogs;
                    }
                } else {
                    // Ensure @self exists with directory information
                    $publication['@self'] = ['directory' => 'local'];
                    if (!empty($localCatalogs)) {
                        $publication['@self']['catalogs'] = $localCatalogs;
                    }
                }
            }

            unset($publication);
// Break the reference
        }//end if

        // Calculate pagination info
        $totalResults = ($localData['total'] ?? 0);
        $totalPages   = $limit > 0 ? max(1, ceil($totalResults / $limit)) : 1;

        // Initialize response structure with pagination
        $responseData = [
            'results' => $localResults,
            'total'   => $totalResults,
            'limit'   => $limit,
            'offset'  => $offset,
            'page'    => $page,
            'pages'   => $totalPages,
        ];

        // Add pagination links
        if ($page < $totalPages) {
            $nextParams           = $requestParams;
            $nextParams['_page']  = ($page + 1);
            $responseData['next'] = $baseUrl.'?'.http_build_query($nextParams);
        }

        if ($page > 1) {
            $prevParams           = $requestParams;
            $prevParams['_page']  = ($page - 1);
            $responseData['prev'] = $baseUrl.'?'.http_build_query($prevParams);
        }

        // Add facets and facetable from local service if present
        if (isset($localData['facets'])) {
            // Check if facets are nested (unwrap if needed)
            $facetsData = $localData['facets'];
            if (isset($facetsData['facets']) && is_array($facetsData['facets'])) {
                $facetsData = $facetsData['facets'];
            }

            // Add virtual field facets if they were requested
            if ($requestedDirectoryFacets || $requestedCatalogFacets) {
                $facetsData = $this->addVirtualFieldFacets($facetsData, $requestedDirectoryFacets, $requestedCatalogFacets);
            }

            $responseData['facets'] = $facetsData;
        } else if ($requestedDirectoryFacets || $requestedCatalogFacets) {
            // If virtual field facets were requested but no other facets exist, create them
            $responseData['facets'] = $this->addVirtualFieldFacets([], $requestedDirectoryFacets, $requestedCatalogFacets);
        }

        if (isset($localData['facetable'])) {
            $responseData['facetable'] = $this->mergeFacetableData($localData['facetable'], []);
        } else if ($shouldIncludeFacets) {
            // If faceting is requested but no facetable data exists, create basic structure
            $responseData['facetable'] = $this->mergeFacetableData([], []);
        }

        // If aggregation is disabled, return only local results
        if (!$shouldAggregate) {
            return $responseData;
        }

        try {
            /*
             * AGGREGATION PAGINATION FIX
             *
             * When aggregating results from multiple sources (local + federated), we cannot
             * simply request the same page from each source and merge the results, because:
             *
             * 1. Each source has different data, so page 2 from Source A + page 2 from Source B
             *    does not equal page 2 of the merged dataset
             * 2. This causes inconsistent totals between pages (e.g., page 1 shows total=22,
             *    page 2 shows total=20)
             * 3. Users may see missing results or empty pages
             *
             * SOLUTION:
             * 1. Calculate how many items we need: requestedPage * itemsPerPage
             * 2. Request that many items from page 1 of ALL sources
             * 3. Merge and deduplicate all collected results
             * 4. Apply pagination to the merged dataset to get the correct slice
             *
             * Example: User requests page 2 with limit 20
             * - We request 40 items (2*20) from page 1 of each source
             * - Merge all results, remove duplicates
             * - Take items 21-40 from the merged list (page 2)
             *
             * This ensures consistent pagination and totals across all pages.
             */

            // For aggregation, we need to collect enough data from all sources
            // to properly paginate the merged results
            // Calculate how many items we need to collect from each source
            // to ensure we have enough data for the requested page
            // Example: page 3 with limit 10 = need 30 items to get items 21-30
            $itemsNeeded = ($page * $limit);

            // Prepare query parameters for fetching from sources
            $localQueryParams     = $queryParams;
            $federatedQueryParams = $queryParams;

            // For local results: request from page 1 with enough items
            $localQueryParams['_page']  = 1;
            $localQueryParams['_limit'] = $itemsNeeded;
            unset($localQueryParams['_offset']);
// Remove offset to start from beginning
            // For federated results: request from page 1 with enough items
            $federatedQueryParams['_page']  = 1;
            $federatedQueryParams['_limit'] = $itemsNeeded;
            unset($federatedQueryParams['_offset']);
// Remove offset to start from beginning
            // Get local results with modified parameters
            // Pass the modified parameters directly to the service
            $allLocalResponse = $this->index(null, $localQueryParams);
            $allLocalData     = json_decode($allLocalResponse->render(), true);

            // Add catalog and directory information to local publications
            // @todo This adds ~200ms overhead - consider making optional via query parameter
            $allLocalResults = ($allLocalData['results'] ?? []);
            if (!empty($allLocalResults)) {
                $localCatalogs = $this->getLocalCatalogs();
                foreach ($allLocalResults as &$publication) {
                    if (isset($publication['@self']) && is_array($publication['@self'])) {
                        // Add directory information for faceting
                        $publication['@self']['directory'] = 'local';

                        // Add catalog information if available
                        if (!empty($localCatalogs)) {
                            $publication['@self']['catalogs'] = $localCatalogs;
                        }
                    } else {
                        // Ensure @self exists with directory information
                        $publication['@self'] = ['directory' => 'local'];
                        if (!empty($localCatalogs)) {
                            $publication['@self']['catalogs'] = $localCatalogs;
                        }
                    }
                }

                unset($publication);
// Break the reference
            }//end if

            // Get federated results with modified parameters
            // Prepare query parameters for federation - exclude pagination and directory filter params since aggregation handles those
            $federatedFilterParams = array_filter(
                $federatedQueryParams,
                function ($key) {
                    // Exclude pagination parameters since aggregation handles those
                    if (in_array($key, ['_limit', '_page', '_offset', 'offset'])) {
                        return false;
                    }

                    // Exclude virtual field facet filters when querying external directories
                    // since these are handled locally after aggregation
                    if (strpos($key, '_facets[@self][directory]') !== false) {
                        return false;
                    }

                    if (strpos($key, '_facets[directory]') !== false) {
                        return false;
                    }

                    if (strpos($key, '_facets[@self][catalogs]') !== false) {
                        return false;
                    }

                    if (strpos($key, '_facets[catalogs]') !== false) {
                        return false;
                    }

                    return true;
                },
                ARRAY_FILTER_USE_KEY
            );

            // Also filter out virtual field facets from the nested _facets array structure
            if (isset($federatedFilterParams['_facets']['@self']['directory'])) {
                unset($federatedFilterParams['_facets']['@self']['directory']);
            }

            if (isset($federatedFilterParams['_facets']['@self']['catalogs'])) {
                unset($federatedFilterParams['_facets']['@self']['catalogs']);
            }

            // Pass query parameters in the format expected by DirectoryService
            $federationGuzzleConfig = ['query_params' => $federatedFilterParams];

            $federationResult = $this->directoryService->getPublications($federationGuzzleConfig);

            // Merge local and federated results
            $allResults = array_merge(
                $allLocalResults,
                ($federationResult['results'] ?? [])
            );

            // Remove duplicates based on ID
            $uniqueResults = [];
            $seenIds       = [];
            foreach ($allResults as $result) {
                $id = ($result['id'] ?? $result['uuid'] ?? uniqid());
                if (!isset($seenIds[$id])) {
                    $uniqueResults[] = $result;
                    $seenIds[$id]    = true;
                }
            }

            $totalResults = (($federationResult['total'] ?? 0) + $localData['total']);

            // Apply ordering to the merged and deduplicated results
            // This is crucial for aggregation because each source may have different ordering,
            // so we need to re-sort the combined dataset according to the requested criteria
            // Supports formats like: _order[@self.published]=DESC, _order[title]=ASC, etc.
            $uniqueResults = $this->applyCumulativeOrdering($uniqueResults, $queryParams);

            // Apply pagination to the merged results
            $totalPages = $limit > 0 ? max(1, ceil($totalResults / $limit)) : 1;

            // Calculate the correct slice for this page
            $startIndex       = (($page - 1) * $limit);
            $paginatedResults = array_slice($uniqueResults, $startIndex, $limit);

            // Update response with paginated combined data
            $responseData = [
                'results' => $paginatedResults,
                'total'   => $totalResults,
                'limit'   => $limit,
                'offset'  => $startIndex,
                'page'    => $page,
                'pages'   => $totalPages,
            ];

            // Update pagination links
            if ($page < $totalPages) {
                $nextParams           = $requestParams;
                $nextParams['_page']  = ($page + 1);
                $responseData['next'] = $baseUrl.'?'.http_build_query($nextParams);
            }

            if ($page > 1) {
                $prevParams           = $requestParams;
                $prevParams['_page']  = ($page - 1);
                $responseData['prev'] = $baseUrl.'?'.http_build_query($prevParams);
            }

            // Merge facets and facetable data if present
            if (isset($allLocalData['facets']) || isset($federationResult['facets'])) {
                $localFacets     = ($allLocalData['facets'] ?? []);
                $federatedFacets = ($federationResult['facets'] ?? []);

                // Check if facets are nested and unwrap if needed
                if (isset($localFacets['facets']) && is_array($localFacets['facets'])) {
                    $localFacets = $localFacets['facets'];
                }

                if (isset($federatedFacets['facets']) && is_array($federatedFacets['facets'])) {
                    $federatedFacets = $federatedFacets['facets'];
                }

                $mergedFacets = $this->mergeFacetsData($localFacets, $federatedFacets);

                // Add virtual field facets if they were requested
                if ($requestedDirectoryFacets || $requestedCatalogFacets) {
                    $mergedFacets = $this->addVirtualFieldFacets($mergedFacets, $requestedDirectoryFacets, $requestedCatalogFacets);
                }

                $responseData['facets'] = $mergedFacets;
            } else if ($requestedDirectoryFacets || $requestedCatalogFacets) {
                // If virtual field facets were requested but no other facets exist, create them
                $responseData['facets'] = $this->addVirtualFieldFacets([], $requestedDirectoryFacets, $requestedCatalogFacets);
            }//end if

            if (isset($allLocalData['facetable']) || isset($federationResult['facetable'])) {
                $responseData['facetable'] = $this->mergeFacetableData(
                    ($allLocalData['facetable'] ?? []),
                    ($federationResult['facetable'] ?? [])
                );
            } else if ($shouldIncludeFacets) {
                $responseData['facetable'] = $this->mergeFacetableData([], []);
            }

            // Performance monitoring for aggregated results
            $executionTime = ((microtime(true) - $startTime) * 1000);
// Convert to milliseconds
            $responseData['_performance'] = [
                'execution_time_ms' => round($executionTime, 2),
                'fast_path'         => false,
                'federation'        => true,
                'cached_catalogs'   => $this->cachedLocalCatalogs !== null,
                'cached_filters'    => !empty($this->cachedCatalogFilters),
            ];

            return $responseData;
        } catch (\Exception $e) {
            // If aggregation fails, return local results only
            // Performance monitoring for fallback case
            $executionTime                = ((microtime(true) - $startTime) * 1000);
            $responseData['_performance'] = [
                'execution_time_ms' => round($executionTime, 2),
                'fast_path'         => false,
                'federation'        => false,
                'error'             => 'Federation failed, returned local only',
                'cached_catalogs'   => $this->cachedLocalCatalogs !== null,
                'cached_filters'    => !empty($this->cachedCatalogFilters),
            ];
            return $responseData;
        }//end try

    }//end getAggregatedPublications()


    /**
     * Get local publications without federation overhead (performance optimized)
     *
     * This is a fast path method that skips expensive federation operations when:
     * - Aggregation is disabled (_aggregate=false)
     * - No federated directories are available
     * - Only local catalog data is needed
     *
     * Optimizations applied:
     * - Single database query for publications
     * - Optional catalog information (controlled via _include_catalogs parameter)
     * - Minimal virtual field facet processing
     * - Direct pagination without aggregation logic
     *
     * @param  array   $queryParams              Query parameters for filtering, pagination, ordering, etc.
     * @param  array   $requestParams            Original request parameters for building pagination links
     * @param  string  $baseUrl                  Base URL for building pagination links
     * @param  boolean $includeCatalogs          Whether to include catalog information in @self
     * @param  boolean $requestedDirectoryFacets Whether directory facets were requested
     * @param  boolean $requestedCatalogFacets   Whether catalog facets were requested
     * @return array Response data containing publications, pagination info, and optionally facets
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     */
    private function getLocalPublicationsFast(array $queryParams, array $requestParams, string $baseUrl, bool $includeCatalogs, bool $requestedDirectoryFacets, bool $requestedCatalogFacets): array
    {
        // Performance monitoring - start timing
        $startTime = microtime(true);
        $timings   = [
            'setup'      => 0,
            'query'      => 0,
            'processing' => 0,
        ];

        // Setup timing start
        $setupStart = microtime(true);

        // Note: @self.schema and @self.register are now provided at response @self level,
        // not duplicated in each result. No need to add them to _extend.

        $timings['setup'] = ((microtime(true) - $setupStart) * 1000);

        // ULTRA-FAST PATH: Skip all middleware and call ObjectService directly
        if (!$includeCatalogs && !$requestedDirectoryFacets && !$requestedCatalogFacets) {
            return $this->getLocalPublicationsUltraFast($queryParams, $requestParams, $baseUrl, $startTime);
        }

        // Query timing start
        $queryStart = microtime(true);

        // Get local publications with a single optimized query
        $localResponse = $this->index(null, $queryParams);
        $localData     = json_decode($localResponse->render(), true);

        $timings['query'] = ((microtime(true) - $queryStart) * 1000);

        // Extract pagination parameters for response structure
        $limit  = (int) ($queryParams['_limit'] ?? $queryParams['limit'] ?? 20);
        $page   = (int) ($queryParams['_page'] ?? $queryParams['page'] ?? 1);
        $offset = (int) ($queryParams['offset'] ?? (($page - 1) * $limit));

        // Get local results
        $localResults = ($localData['results'] ?? []);

        // Add catalog and directory information only if requested or needed for faceting
        if (($includeCatalogs || $requestedDirectoryFacets || $requestedCatalogFacets) && !empty($localResults)) {
            // Get local catalogs only if needed
            $localCatalogs = $includeCatalogs ? $this->getLocalCatalogs() : [];

            foreach ($localResults as &$publication) {
                if (isset($publication['@self']) && is_array($publication['@self'])) {
                    // Add directory information for faceting
                    if ($requestedDirectoryFacets) {
                        $publication['@self']['directory'] = 'local';
                    }

                    // Add catalog information only if requested
                    if ($includeCatalogs && !empty($localCatalogs)) {
                        $publication['@self']['catalogs'] = $localCatalogs;
                    }
                } else {
                    // Ensure @self exists with minimal required information
                    $publication['@self'] = [];
                    if ($requestedDirectoryFacets) {
                        $publication['@self']['directory'] = 'local';
                    }

                    if ($includeCatalogs && !empty($localCatalogs)) {
                        $publication['@self']['catalogs'] = $localCatalogs;
                    }
                }//end if
            }//end foreach

            unset($publication);
// Break the reference
        }//end if

        // Calculate pagination info
        $totalResults = ($localData['total'] ?? 0);
        $totalPages   = $limit > 0 ? max(1, ceil($totalResults / $limit)) : 1;

        // Build response structure with pagination
        $responseData = [
            'results' => $localResults,
            'total'   => $totalResults,
            'limit'   => $limit,
            'offset'  => $offset,
            'page'    => $page,
            'pages'   => $totalPages,
        ];

        // Add pagination links
        if ($page < $totalPages) {
            $nextParams           = $requestParams;
            $nextParams['_page']  = ($page + 1);
            $responseData['next'] = $baseUrl.'?'.http_build_query($nextParams);
        }

        if ($page > 1) {
            $prevParams           = $requestParams;
            $prevParams['_page']  = ($page - 1);
            $responseData['prev'] = $baseUrl.'?'.http_build_query($prevParams);
        }

        // Add facets from local service if present
        if (isset($localData['facets'])) {
            // Check if facets are nested (unwrap if needed)
            $facetsData = $localData['facets'];
            if (isset($facetsData['facets']) && is_array($facetsData['facets'])) {
                $facetsData = $facetsData['facets'];
            }

            // Add virtual field facets only if they were requested
            if ($requestedDirectoryFacets || $requestedCatalogFacets) {
                $facetsData = $this->addVirtualFieldFacets($facetsData, $requestedDirectoryFacets, $requestedCatalogFacets);
            }

            $responseData['facets'] = $facetsData;
        } else if ($requestedDirectoryFacets || $requestedCatalogFacets) {
            // If virtual field facets were requested but no other facets exist, create them
            $responseData['facets'] = $this->addVirtualFieldFacets([], $requestedDirectoryFacets, $requestedCatalogFacets);
        }

        // Add facetable metadata if present in local response
        if (isset($localData['facetable'])) {
            $responseData['facetable'] = $localData['facetable'];
        }

        // Processing timing start
        $processingStart       = microtime(true);
        $timings['processing'] = ((microtime(true) - $processingStart) * 1000);

        // Performance monitoring - calculate execution time
        $executionTime = ((microtime(true) - $startTime) * 1000);
// Convert to milliseconds
        $responseData['_performance'] = [
            'execution_time_ms' => round($executionTime, 2),
            'fast_path'         => true,
            'ultra_fast_path'   => false,
            'include_catalogs'  => $includeCatalogs,
            'cached_catalogs'   => $this->cachedLocalCatalogs !== null,
            'timings'           => $timings,
        ];

        return $responseData;

    }//end getLocalPublicationsFast()


    /**
     * Get local publications with ultra-fast path (minimal overhead)
     *
     * This method bypasses ALL middleware overhead and calls ObjectService directly.
     * Use when no catalog info, facets, or additional processing is needed.
     *
     * Optimizations:
     * - Direct ObjectService call (no searchPublications() overhead)
     * - Skip catalog filter validation
     * - Skip virtual field processing
     * - Minimal response structure building
     *
     * @param  array  $queryParams   Query parameters
     * @param  array  $requestParams Original request parameters for pagination links
     * @param  string $baseUrl       Base URL for building pagination links
     * @param  float  $startTime     Start time for performance monitoring
     * @return array Minimal response with publications and pagination
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     */
    private function getLocalPublicationsUltraFast(array $queryParams, array $requestParams, string $baseUrl, float $startTime): array
    {

        $timings = [
            'setup'         => 0,
            'objectservice' => 0,
            'response'      => 0,
        ];

        // Setup timing
        $setupStart = microtime(true);

        // Extract pagination parameters
        $limit  = (int) ($queryParams['_limit'] ?? $queryParams['limit'] ?? 20);
        $page   = (int) ($queryParams['_page'] ?? $queryParams['page'] ?? 1);
        $offset = (int) ($queryParams['offset'] ?? (($page - 1) * $limit));

        // Get minimal required config from cache or defaults
        $schema   = $this->config->getValueString($this->appName, 'catalog_schema', '');
        $register = $this->config->getValueString($this->appName, 'catalog_register', '');

        $timings['setup'] = ((microtime(true) - $setupStart) * 1000);

        // Catalog context timing
        $catalogContextStart = microtime(true);

        // Build search query properly - preserve all original parameters
        $searchQuery = $queryParams;

        // Get the proper catalog context (use cached version)
        $cacheKey = 'all';
        if (!isset($this->cachedCatalogFilters[$cacheKey])) {
            // Quick catalog context setup without full getCatalogFilters overhead
            $query = [
                '@self' => [
                    'register' => $register,
                    'schema'   => $schema,
                ],
            ];

            try {
                $catalogs        = $this->getObjectService()->searchObjects($query);
                $uniqueRegisters = [];
                $uniqueSchemas   = [];

                foreach ($catalogs as $catalog) {
                    $catalog = $catalog->jsonSerialize();
                    if (isset($catalog['registers']) && is_array($catalog['registers'])) {
                        $uniqueRegisters = array_merge($uniqueRegisters, $catalog['registers']);
                    }

                    if (isset($catalog['schemas']) && is_array($catalog['schemas'])) {
                        $uniqueSchemas = array_merge($uniqueSchemas, $catalog['schemas']);
                    }
                }

                $this->availableRegisters = array_unique($uniqueRegisters);
                $this->availableSchemas   = array_unique($uniqueSchemas);

                $catalogContext = [
                    'registers' => array_values($this->availableRegisters),
                    'schemas'   => array_values($this->availableSchemas),
                ];

                $this->cachedCatalogFilters[$cacheKey] = [
                    'result'             => $catalogContext,
                    'availableRegisters' => $this->availableRegisters,
                    'availableSchemas'   => $this->availableSchemas,
                ];
            } catch (\Exception $e) {
                // Fallback to defaults
                $this->availableRegisters = [$register];
                $this->availableSchemas   = [$schema];
                $catalogContext           = [
                    'registers' => [$register],
                    'schemas'   => [$schema],
                ];
            }//end try
        } else {
            $cached                   = $this->cachedCatalogFilters[$cacheKey];
            $this->availableRegisters = $cached['availableRegisters'];
            $this->availableSchemas   = $cached['availableSchemas'];
            $catalogContext           = $cached['result'];
        }//end if

        // Set up the search query properly (preserve original logic from searchPublications)
        if (!isset($searchQuery['@self'])) {
            $searchQuery['@self'] = [];
        }

        // Use scalar value when only one register/schema to avoid magic_mapper overhead
        $registers = $catalogContext['registers'];
        $schemas   = $catalogContext['schemas'];
        $searchQuery['@self']['register'] = count($registers) === 1 ? $registers[0] : $registers;
        $searchQuery['@self']['schema']   = count($schemas) === 1 ? $schemas[0] : $schemas;
        $searchQuery['_includeDeleted']   = false;

        // Clean up unwanted parameters
        unset($searchQuery['id'], $searchQuery['_route']);

        $timings['catalog_context'] = ((microtime(true) - $catalogContextStart) * 1000);

        // ObjectService timing
        $objectServiceStart = microtime(true);

        // Call ObjectService directly - bypass all middleware
        $objectService = $this->getObjectService();
        $result        = $objectService->searchObjectsPaginated($searchQuery);

        $timings['objectservice'] = ((microtime(true) - $objectServiceStart) * 1000);

        // Response building timing
        $responseStart = microtime(true);

        // Handle virtual field facet processing if needed (before unwrapping)
        $requestedDirectoryFacets = isset($queryParams['_facets']['@self']['directory']);
        $requestedCatalogFacets   = isset($queryParams['_facets']['@self']['catalogs']);

        if (isset($result['facets']) && ($requestedDirectoryFacets || $requestedCatalogFacets)) {
            // Need to unwrap facets first, then add virtual fields, then the unwrapping logic will handle it consistently
            $facetsForProcessing = $result['facets'];
            if (isset($facetsForProcessing['facets']) && is_array($facetsForProcessing['facets'])) {
                $facetsForProcessing = $facetsForProcessing['facets'];
            }

            $facetsForProcessing = $this->addVirtualFieldFacets($facetsForProcessing, $requestedDirectoryFacets, $requestedCatalogFacets);
            $result['facets']    = $facetsForProcessing;
        }

        // Skip filtering for maximum performance if requested
        $skipFiltering = isset($queryParams['_skip_filtering']) && $queryParams['_skip_filtering'] !== 'false';

        if ($skipFiltering) {
            $filteredResults = ($result['results'] ?? []);
        } else {
            // Filter unwanted properties from results (minimal processing)
            $filteredResults = $this->filterUnwantedProperties(($result['results'] ?? []));
        }

        // Calculate pagination info
        $totalResults = ($result['total'] ?? 0);
        $totalPages   = $limit > 0 ? max(1, ceil($totalResults / $limit)) : 1;

        // Build minimal response structure
        $responseData = [
            'results' => $filteredResults,
            'total'   => $totalResults,
            'limit'   => $limit,
            'offset'  => $offset,
            'page'    => $page,
            'pages'   => $totalPages,
        ];

        // Add pagination links only if needed
        if ($page < $totalPages) {
            $nextParams           = $requestParams;
            $nextParams['_page']  = ($page + 1);
            $responseData['next'] = $baseUrl.'?'.http_build_query($nextParams);
        }

        if ($page > 1) {
            $prevParams           = $requestParams;
            $prevParams['_page']  = ($page - 1);
            $responseData['prev'] = $baseUrl.'?'.http_build_query($prevParams);
        }

        // Add facets if present (already processed and unwrapped above if needed)
        if (isset($result['facets'])) {
            $facetsData = $result['facets'];
            // Only unwrap if virtual field processing didn't already handle it
            if (!($requestedDirectoryFacets || $requestedCatalogFacets)) {
                // Check if facets are nested and unwrap if needed (same logic as original)
                if (isset($facetsData['facets']) && is_array($facetsData['facets'])) {
                    $facetsData = $facetsData['facets'];
                }
            }

            $responseData['facets'] = $facetsData;
        }

        if (isset($result['facetable'])) {
            $responseData['facetable'] = $result['facetable'];
        }

        $timings['response'] = ((microtime(true) - $responseStart) * 1000);

        // Performance monitoring
        $executionTime                = ((microtime(true) - $startTime) * 1000);
        $responseData['_performance'] = [
            'execution_time_ms'        => round($executionTime, 2),
            'fast_path'                => true,
            'ultra_fast_path'          => true,
            'include_catalogs'         => false,
            'cached_catalogs'          => false,
            'bypassed_middleware'      => true,
            'skipped_filtering'        => $skipFiltering,
            'processed_virtual_facets' => $requestedDirectoryFacets || $requestedCatalogFacets,
            'cached_catalog_filters'   => isset($this->cachedCatalogFilters['all']),

            'timings'                  => $timings,
        ];

        return $responseData;

    }//end getLocalPublicationsUltraFast()


    /**
     * Get local catalog information for adding to publication @self.catalogs property.
     *
     * This method retrieves all local catalogs that are available in the current instance.
     * It returns an array of catalog objects with their basic information.
     *
     * PERFORMANCE OPTIMIZATION: Results are cached to avoid repeated database queries
     * that were adding ~200ms overhead per call. Cache is invalidated on object destruction.
     *
     * @return array Array of catalog objects with id, title, summary, description, etc.
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     */
    private function getLocalCatalogs(): array
    {
        // Return cached result if available
        if ($this->cachedLocalCatalogs !== null) {
            return $this->cachedLocalCatalogs;
        }

        try {
            // Get catalog configuration from settings
            $catalogSchema   = $this->config->getValueString($this->appName, 'catalog_schema', '');
            $catalogRegister = $this->config->getValueString($this->appName, 'catalog_register', '');

            if (empty($catalogSchema) || empty($catalogRegister)) {
                return [];
            }

            // Setup query for finding catalogs
            $query = [
                '@self' => [
                    'schema'   => $catalogSchema,
                    'register' => $catalogRegister,
                ],
            ];

            // Get all catalogs using ObjectService
            $objectService = $this->getObjectService();
            $catalogs      = $objectService->searchObjects($query);

            // Convert catalog objects to arrays and filter for public use
            $catalogArray = [];
            foreach ($catalogs as $catalog) {
                $catalogData = $catalog instanceof \OCP\AppFramework\Db\Entity ? $catalog->jsonSerialize() : $catalog;

                // Extract the relevant catalog information
                $catalogInfo = [
                    'id'           => ($catalogData['id'] ?? ''),
                    'title'        => ($catalogData['title'] ?? 'Local Catalog'),
                    'summary'      => ($catalogData['summary'] ?? 'Local catalog instance'),
                    'description'  => $catalogData['description'] ?? null,
                    'organization' => $catalogData['organization'] ?? null,
                    'listed'       => $catalogData['listed'] ?? false,
                ];

                // Only include non-empty catalog info
                if (!empty($catalogInfo['id'])) {
                    $catalogArray[] = $catalogInfo;
                }
            }

            // Cache the result before returning
            $this->cachedLocalCatalogs = $catalogArray;
            return $catalogArray;
        } catch (\Exception $e) {
            // If we can't get catalog information, return empty array and cache it
            $this->cachedLocalCatalogs = [];
            return [];
        }//end try

    }//end getLocalCatalogs()


    /**
     * Simple merge function for facets data from multiple sources.
     *
     * @param  array $localFacets     Facets from local source
     * @param  array $federatedFacets Facets from federated sources
     * @return array Merged facets data
     */
    private function mergeFacetsData(array $localFacets, array $federatedFacets): array
    {
        // For now, just return local facets as they're more reliable
        // In the future, we could implement more sophisticated merging
        return !empty($localFacets) ? $localFacets : $federatedFacets;

    }//end mergeFacetsData()


    /**
     * Simple merge function for facetable metadata from multiple sources.
     *
     * @param  array $localFacetable     Facetable metadata from local source
     * @param  array $federatedFacetable Facetable metadata from federated sources
     * @return array Merged facetable metadata
     */
    private function mergeFacetableData(array $localFacetable, array $federatedFacetable): array
    {
        // Start with local facetable as base
        $mergedFacetable = !empty($localFacetable) ? $localFacetable : $federatedFacetable;

        // Ensure @self section exists
        if (!isset($mergedFacetable['@self'])) {
            $mergedFacetable['@self'] = [];
        }

        // Add directory facet for filtering by catalog source
        // This will be populated dynamically based on available directories
        $mergedFacetable['@self']['directory'] = [
            'type'          => 'categorical',
            'description'   => 'Directory/catalog source of the publication',
            'facet_types'   => ['terms'],
            'has_labels'    => true,
            'sample_values' => [
                [
                    'value' => 'local',
                    'label' => 'Local OpenCatalogi',
                    'count' => 1,
                ],
                [
                    'value' => 'directory.opencatalogi.nl',
                    'label' => 'OpenCatalogi Directory',
                    'count' => 1,
                ],
                [
                    'value' => 'dimpact.commonground.nu',
                    'label' => 'Dimpact',
                    'count' => 1,
                ],
            ],
        ];

        // Add catalogs facet for filtering by catalog
        // This will be populated dynamically based on available catalogs
        $mergedFacetable['@self']['catalogs'] = [
            'type'          => 'categorical',
            'description'   => 'Catalog that contains the publication',
            'facet_types'   => ['terms'],
            'has_labels'    => true,
            'sample_values' => [
                [
                    'value' => 'default',
                    'label' => 'Default Catalog',
                    'count' => 1,
                ],
                [
                    'value' => 'test-catalog',
                    'label' => 'Test Catalog',
                    'count' => 1,
                ],
            ],
        ];

        return $mergedFacetable;

    }//end mergeFacetableData()


    /**
     * Apply ordering to the cumulated dataset from multiple sources
     *
     * This method handles ordering parameters in the format _order[field]=direction
     * and applies them to the merged results from local and federated sources.
     * Since each source may have different ordering, we need to re-sort the combined dataset.
     *
     * @param  array $results     The merged and deduplicated results to order
     * @param  array $queryParams The query parameters containing ordering instructions
     * @return array The ordered results
     */
    private function applyCumulativeOrdering(array $results, array $queryParams): array
    {
        // Extract ordering parameters
        $orderParams = ($queryParams['_order'] ?? $queryParams['order'] ?? []);

        if (empty($orderParams) || !is_array($orderParams)) {
            return $results;
        }

        // Convert single field ordering to array format for consistency
        if (!isset($orderParams[0])) {
            $orderParams = [$orderParams];
        }

        // Apply multiple field ordering (PHP's usort is stable for equal values)
        usort(
            $results,
            function ($a, $b) use ($orderParams) {
                foreach ($orderParams as $field => $direction) {
                    // Handle both associative array format: ['field' => 'direction']
                    // and indexed array format: [0 => ['field' => 'direction']]
                    if (is_numeric($field) && is_array($direction)) {
                        // Format: [0 => ['@self.published' => 'DESC']]
                        $fieldName     = array_key_first($direction);
                        $sortDirection = strtoupper(($direction[$fieldName] ?? 'ASC'));
                    } else {
                        // Format: ['@self.published' => 'DESC']
                        $fieldName     = $field;
                        $sortDirection = strtoupper(($direction ?? 'ASC'));
                    }

                    // Extract values for comparison
                    $valueA = $this->extractFieldValue($a, $fieldName);
                    $valueB = $this->extractFieldValue($b, $fieldName);

                    // Compare values
                    $comparison = $this->compareValues($valueA, $valueB);

                    if ($comparison !== 0) {
                        // Return result based on sort direction
                        return $sortDirection === 'DESC' ? -$comparison : $comparison;
                    }

                    // If values are equal, continue to next sort field
                }//end foreach

                return 0;
            // All compared fields are equal
            }
        );

        return $results;

    }//end applyCumulativeOrdering()


    /**
     * Extract field value from a result object using dot notation
     *
     * Supports nested field access like '@self.published' or 'data.title'
     *
     * @param  array  $result    The result object to extract value from
     * @param  string $fieldPath The field path in dot notation
     * @return mixed The extracted value or null if not found
     */
    private function extractFieldValue(array $result, string $fieldPath)
    {
        $parts = explode('.', $fieldPath);
        $value = $result;

        foreach ($parts as $part) {
            if (!is_array($value) || !isset($value[$part])) {
                return null;
            }

            $value = $value[$part];
        }

        return $value;

    }//end extractFieldValue()


    /**
     * Compare two values for sorting
     *
     * Handles different data types appropriately for sorting
     *
     * @param  mixed $a First value
     * @param  mixed $b Second value
     * @return integer -1, 0, or 1 for less than, equal, or greater than
     */
    private function compareValues($a, $b): int
    {
        // Handle null values
        if ($a === null && $b === null) {
return 0;
        }

        if ($a === null) {
return -1;
        }

        if ($b === null) {
return 1;
        }

        // Handle date strings
        if (is_string($a) && is_string($b)) {
            $dateA = strtotime($a);
            $dateB = strtotime($b);

            if ($dateA !== false && $dateB !== false) {
                return ($dateA <=> $dateB);
            }
        }

        // Handle numeric values
        if (is_numeric($a) && is_numeric($b)) {
            return ($a <=> $b);
        }

        // Handle string comparison
        return strcmp((string) $a, (string) $b);

    }//end compareValues()


    /**
     * Get a single publication with optional federation support
     *
     * This method searches for a publication in the local catalog first,
     * and optionally in federated catalogs if _aggregate parameter is not
     * set to false and the publication is not found locally.
     *
     * @param  string $id          The ID of the publication to retrieve
     * @param  array  $queryParams Query parameters including aggregation settings
     * @return array Response data containing the publication or error information
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     */
    public function getFederatedPublication(string $id, array $queryParams = []): array
    {
        // Try to get the publication locally first
        $localResponse = $this->show(id: $id);
        $localData     = json_decode($localResponse->render(), true);

        // If found locally, return it (unless we want to also search federally for additional data)
        if ($localResponse->getStatus() === 200 && !empty($localData)) {
            // Check if aggregation is enabled for enrichment
            $aggregate       = ($queryParams['_aggregate'] ?? 'true');
            $shouldAggregate = $aggregate !== 'false' && $aggregate !== '0';

            if (!$shouldAggregate) {
                return [
                    'success' => true,
                    'data'    => $localData,
                    'status'  => 200,
                ];
            }

            // Add local catalog information to @self.catalogs for local publications
            // @todo This adds ~200ms overhead - consider making optional via query parameter
            if (isset($localData['@self']) && is_array($localData['@self'])) {
                $localCatalogs = $this->getLocalCatalogs();
                if (!empty($localCatalogs)) {
                    $localData['@self']['catalogs'] = $localCatalogs;
                }
            }

            return [
                'success' => true,
                'data'    => $localData,
                'status'  => 200,
            ];
        }//end if

        // Check if aggregation is enabled for federation search
        $aggregate       = ($queryParams['_aggregate'] ?? 'true');
        $shouldAggregate = $aggregate !== 'false' && $aggregate !== '0';

        // If aggregation is disabled and not found locally, return 404
        if (!$shouldAggregate) {
            return [
                'success' => false,
                'error'   => 'Publication not found',
                'status'  => 404,
            ];
        }

        try {
            // Search in federated catalogs
            $guzzleConfig = [];

            // Allow timeout configuration via query parameter
            if (isset($queryParams['timeout'])) {
                $timeout = (int) $queryParams['timeout'];
                if ($timeout > 0 && $timeout <= 120) {
// Max 2 minutes
                    $guzzleConfig['timeout'] = $timeout;
                }
            }

            // Allow connect timeout configuration via query parameter
            if (isset($queryParams['connect_timeout'])) {
                $connectTimeout = (int) $queryParams['connect_timeout'];
                if ($connectTimeout > 0 && $connectTimeout <= 30) {
// Max 30 seconds
                    $guzzleConfig['connect_timeout'] = $connectTimeout;
                }
            }

            // Pass through current query parameters (DirectoryService will handle _aggregate and _extend)
            $guzzleConfig['query_params'] = $queryParams;

            // Get publication from directory service
            $federatedResult = $this->directoryService->getPublication($id, $guzzleConfig);

            if (!empty($federatedResult) && isset($federatedResult['result'])) {
                // Merge the result with source information
                $responseData            = $federatedResult['result'];
                $responseData['sources'] = ($federatedResult['source'] ?? []);

                return [
                    'success' => true,
                    'data'    => $responseData,
                    'status'  => 200,
                ];
            }

            // Not found in federated catalogs either, return 404
            return [
                'success' => false,
                'error'   => 'Publication not found',
                'status'  => 404,
            ];
        } catch (\Exception $e) {
            // If federation search fails, return the original local response (likely 404)
            return [
                'success' => false,
                'error'   => 'Publication not found',
                'status'  => 404,
            ];
        }//end try

    }//end getFederatedPublication()


    /**
     * Get publications that use this publication with federation support
     *
     * This method returns all objects that reference (use) this publication. B -> A means that B (Another object) references A (This publication).
     * When aggregation is enabled, it also searches federated catalogs.
     *
     * @param  string $id          The ID of the publication to retrieve uses for
     * @param  array  $queryParams Query parameters including aggregation settings
     * @return array Response data containing the referenced objects
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     */
    public function getFederatedUsed(string $id, array $queryParams = []): array
    {
        // Check if aggregation is enabled (default: true, unless explicitly set to false)
        $aggregate       = ($queryParams['_aggregate'] ?? 'true');
        $shouldAggregate = $aggregate !== 'false' && $aggregate !== '0';

        // Get local results first
        $localResponse = $this->used(id: $id);
        $localData     = json_decode($localResponse->render(), true);

        // If aggregation is disabled, return only local results
        if (!$shouldAggregate) {
            return [
                'success' => true,
                'data'    => $localData,
                'status'  => 200,
            ];
        }

        try {
            // Get optional Guzzle configuration from query parameters
            $guzzleConfig = [];

            // Allow timeout configuration via query parameter
            if (isset($queryParams['timeout'])) {
                $timeout = (int) $queryParams['timeout'];
                if ($timeout > 0 && $timeout <= 120) {
// Max 2 minutes
                    $guzzleConfig['timeout'] = $timeout;
                }
            }

            // Allow connect timeout configuration via query parameter
            if (isset($queryParams['connect_timeout'])) {
                $connectTimeout = (int) $queryParams['connect_timeout'];
                if ($connectTimeout > 0 && $connectTimeout <= 30) {
// Max 30 seconds
                    $guzzleConfig['connect_timeout'] = $connectTimeout;
                }
            }

            // Pass through current query parameters (DirectoryService will handle _aggregate and _extend)
            $guzzleConfig['query_params'] = $queryParams;

            // Get federated results from directory service
            $federatedResults = $this->directoryService->getUsed($id, $guzzleConfig);

            // Merge local and federated results
            $mergedResults = [
                'results' => array_merge(
                    ($localData['results'] ?? []),
                    ($federatedResults['results'] ?? [])
                ),
                'sources' => array_merge(
                    ['local' => 'Local OpenCatalogi instance'],
                    ($federatedResults['sources'] ?? [])
                ),
            ];

            return [
                'success' => true,
                'data'    => $mergedResults,
                'status'  => 200,
            ];
        } catch (\Exception $e) {
            // If federation fails, return local results
            return [
                'success' => true,
                'data'    => $localData,
                'status'  => 200,
            ];
        }//end try

    }//end getFederatedUsed()


    /**
     * Get publications that this publication uses with federation support
     *
     * This method returns all objects that this publication uses/references. A -> B means that A (This publication) references B (Another object).
     * When aggregation is enabled, it also searches federated catalogs.
     *
     * @param  string $id          The ID of the publication to retrieve relations for
     * @param  array  $queryParams Query parameters including aggregation settings
     * @return array Response data containing the related objects
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     */
    public function getFederatedUses(string $id, array $queryParams = []): array
    {
        // Check if aggregation is enabled (default: true, unless explicitly set to false)
        $aggregate       = ($queryParams['_aggregate'] ?? 'true');
        $shouldAggregate = $aggregate !== 'false' && $aggregate !== '0';

        // Get local results first
        $localResponse = $this->uses(id: $id);
        $localData     = json_decode($localResponse->render(), true);

        // If aggregation is disabled, return only local results
        if (!$shouldAggregate) {
            return [
                'success' => true,
                'data'    => $localData,
                'status'  => 200,
            ];
        }

        try {
            // Get optional Guzzle configuration from query parameters
            $guzzleConfig = [];

            // Allow timeout configuration via query parameter
            if (isset($queryParams['timeout'])) {
                $timeout = (int) $queryParams['timeout'];
                if ($timeout > 0 && $timeout <= 120) {
// Max 2 minutes
                    $guzzleConfig['timeout'] = $timeout;
                }
            }

            // Allow connect timeout configuration via query parameter
            if (isset($queryParams['connect_timeout'])) {
                $connectTimeout = (int) $queryParams['connect_timeout'];
                if ($connectTimeout > 0 && $connectTimeout <= 30) {
// Max 30 seconds
                    $guzzleConfig['connect_timeout'] = $connectTimeout;
                }
            }

            // Pass through current query parameters (DirectoryService will handle _aggregate and _extend)
            $guzzleConfig['query_params'] = $queryParams;

            // Note: For 'uses' we don't have a specific DirectoryService method yet
            // This would need to be implemented similar to getUsed() if needed
            // For now, return local results only
            return [
                'success' => true,
                'data'    => $localData,
                'status'  => 200,
            ];
        } catch (\Exception $e) {
            // If federation fails, return local results
            return [
                'success' => true,
                'data'    => $localData,
                'status'  => 200,
            ];
        }//end try

    }//end getFederatedUses()


}//end class
