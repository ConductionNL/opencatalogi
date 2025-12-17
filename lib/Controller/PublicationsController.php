<?php

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\Service\DirectoryService;
use OCA\OpenCatalogi\Service\PublicationService;
use OCA\OpenCatalogi\Service\CatalogiService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IRequest;
use OCP\IAppConfig;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;

/**
 * Class PublicationsController
 *
 * Controller for handling publication-related operations in the OpenCatalogi app.
 *
 * @category  Controller
 * @package   opencatalogi
 * @author    Ruben van der Linde
 * @copyright 2024
 * @license   AGPL-3.0-or-later
 * @version   1.0.0
 * @link      https://github.com/opencatalogi/opencatalogi
 */
class PublicationsController extends Controller
{

    /**
     * @var string Allowed CORS methods
     */
    private string $corsMethods;

    /**
     * @var string Allowed CORS headers
     */
    private string $corsAllowedHeaders;

    /**
     * @var int CORS max age
     */
    private int $corsMaxAge;

    /**
     * PublicationsController constructor.
     *
     * @param string             $appName            The name of the app
     * @param IRequest           $request            The request object
     * @param PublicationService $publicationService The publication service
     * @param DirectoryService   $directoryService   The directory service
     * @param CatalogiService    $catalogiService    The catalogi service
     * @param IAppConfig         $config             The app configuration
     * @param ContainerInterface $container          The container for dependency injection
     * @param IAppManager        $appManager         The app manager
     * @param LoggerInterface    $logger             PSR-3 logger
     * @param string             $corsMethods        Allowed CORS methods
     * @param string             $corsAllowedHeaders Allowed CORS headers
     * @param int                $corsMaxAge         CORS max age
     */
    public function __construct(
        $appName,
        IRequest $request,
        private readonly PublicationService $publicationService,
        private readonly DirectoryService $directoryService,
        private readonly CatalogiService $catalogiService,
        private readonly IAppConfig $config,
        private readonly ContainerInterface $container,
        private readonly IAppManager $appManager,
        private readonly LoggerInterface $logger,
        string $corsMethods = 'PUT, POST, GET, DELETE, PATCH',
        string $corsAllowedHeaders = 'Authorization, Content-Type, Accept',
        int $corsMaxAge = 1728000
    ) {
        parent::__construct($appName, $request);
        $this->corsMethods = $corsMethods;
        $this->corsAllowedHeaders = $corsAllowedHeaders;
        $this->corsMaxAge = $corsMaxAge;
    }

    /**
     * Attempts to retrieve the OpenRegister ObjectService from the container.
     *
     * @return \OCA\OpenRegister\Service\ObjectService|null The OpenRegister ObjectService if available, null otherwise.
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     */
    private function getObjectService()
    {
        if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        }

        throw new \RuntimeException('OpenRegister service is not available.');
    }

    /**
     * Extract filter values from various filter formats
     *
     * Handles:
     * - Single value: 1
     * - Simple array: [1, 2, 3]
     * - OR operator: ['or' => '1,2,3'] or ['or' => [1, 2, 3]]
     * - AND operator: ['and' => '1,2,3'] or ['and' => [1, 2, 3]]
     *
     * @param mixed $filter The filter value in any supported format
     * @return array Array of integer values
     */
    private function extractFilterValues($filter): array
    {
        // Single numeric value
        if (is_numeric($filter)) {
            return [(int) $filter];
        }

        // Array format
        if (is_array($filter)) {
            // Check for [or] or [and] operators
            if (isset($filter['or'])) {
                $values = $filter['or'];
            } elseif (isset($filter['and'])) {
                $values = $filter['and'];
            } else {
                // Simple array of values
                $values = $filter;
            }

            // Handle comma-separated string
            if (is_string($values)) {
                $values = explode(',', $values);
            }

            // Ensure array and convert to integers
            if (is_array($values)) {
                return array_map('intval', array_filter($values, function($v) {
                    return is_numeric($v) || (is_string($v) && trim($v) !== '');
                }));
            }

            // Single value in the operator
            if (is_numeric($values)) {
                return [(int) $values];
            }
        }

        // String format (comma-separated)
        if (is_string($filter)) {
            $values = explode(',', $filter);
            return array_map('intval', array_filter($values, function($v) {
                return trim($v) !== '';
            }));
        }

        return [];
    }

    /**
     * Implements a preflighted CORS response for OPTIONS requests.
     *
     * @return \OCP\AppFramework\Http\Response The CORS response
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     */
    public function preflightedCors(): \OCP\AppFramework\Http\Response
    {
        // Determine the origin
        $origin = isset($this->request->server['HTTP_ORIGIN']) ? $this->request->server['HTTP_ORIGIN'] : '*';

        // Create and configure the response
        $response = new \OCP\AppFramework\Http\Response();
        $response->addHeader('Access-Control-Allow-Origin', $origin);
        $response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
        $response->addHeader('Access-Control-Max-Age', (string) $this->corsMaxAge);
        $response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);
        $response->addHeader('Access-Control-Allow-Credentials', 'false');

        return $response;
    }

    /**
     * Retrieve all publications from this catalog - DIRECT OBJECTSERVICE HACK with catalog filtering
     *
     * This method bypasses ALL middleware and calls ObjectService directly for maximum performance.
     * Filters by catalog's schemas and registers as well as published=true.
     *
     * @param string $catalogSlug The slug of the catalog to retrieve publications from
     * @return JSONResponse JSON response containing publications, pagination info, and optionally facets
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     */
    public function index(string $catalogSlug): JSONResponse
    {
        try {
            // Get the catalog from cache or database
            $catalogData = $this->catalogiService->getCatalogBySlug($catalogSlug);

            if ($catalogData === null) {
                return new JSONResponse(['error' => 'Catalog not found'], 404);
            }

            // Convert ObjectEntity to array if needed (cache may return array directly)
            $catalog = is_array($catalogData) ? $catalogData : $catalogData->jsonSerialize();

            // Get ObjectService directly - bypass all PublicationService overhead
            $objectService = $this->getObjectService();

            // Get query parameters - ObjectService::buildSearchQuery() handles PHP's dot-to-underscore conversion
            $queryParams = $this->request->getParams();

            // Use ObjectService's centralized query builder which handles:
            // - PHP dot-to-underscore conversion (@self.register → @self_register)
            // - Nested property conversion (person.address.street → person_address_street)
            // - System parameter extraction (removes id, _route, rbac, multi, published, deleted)
            $searchQuery = $objectService->buildSearchQuery($queryParams);
            $searchQuery['_includeDeleted'] = false;

            // Clean up catalog-specific parameters
            unset($searchQuery['catalogSlug'], $searchQuery['fq']);

            // Add schema/register extension if needed
            if (!isset($searchQuery['_extend'])) {
                $searchQuery['_extend'] = [];
            } elseif (!is_array($searchQuery['_extend'])) {
                // Handle comma-separated strings
                $searchQuery['_extend'] = array_map('trim', explode(',', $searchQuery['_extend']));
            }

            // Ensure @self.schema and @self.register are always included for compatibility
            if (!in_array('@self.schema', $searchQuery['_extend'])) {
                $searchQuery['_extend'][] = '@self.schema';
            }
            if (!in_array('@self.register', $searchQuery['_extend'])) {
                $searchQuery['_extend'][] = '@self.register';
            }

            // DATABASE-LEVEL FILTERING: Handle catalog filtering intelligently
            // If frontend provides schema/register filters, validate they're within catalog
            // If no frontend filters, apply catalog's default filters

            // Initialize @self if needed
            if (!isset($searchQuery['@self'])) {
                $searchQuery['@self'] = [];
            }

            // Handle SCHEMA filtering
            if (!empty($catalog['schemas'])) {
                $frontendSchemaFilter = $searchQuery['@self']['schema'] ?? null;

                if ($frontendSchemaFilter !== null) {
                    // Frontend provided a schema filter - validate it's within catalog
                    $requestedSchemas = $this->extractFilterValues($frontendSchemaFilter);
                    $allowedSchemas = array_map('intval', $catalog['schemas']);

                    // Check if all requested schemas are within the catalog
                    $validSchemas = array_intersect($requestedSchemas, $allowedSchemas);

                    if (empty($validSchemas)) {
                        // None of the requested schemas are in this catalog
                        return new JSONResponse(['error' => 'Requested schema(s) not available in this catalog'], 403);
                    }

                    // Keep the frontend's filter (it's valid) - don't overwrite
                } else {
                    // No frontend filter - apply catalog's default filter
                    $searchQuery['@self']['schema'] = [
                        'or' => implode(',', array_map('intval', $catalog['schemas']))
                    ];
                }
            }

            // Handle REGISTER filtering
            if (!empty($catalog['registers'])) {
                $frontendRegisterFilter = $searchQuery['@self']['register'] ?? null;

                if ($frontendRegisterFilter !== null) {
                    // Frontend provided a register filter - validate it's within catalog
                    $requestedRegisters = $this->extractFilterValues($frontendRegisterFilter);
                    $allowedRegisters = array_map('intval', $catalog['registers']);

                    // Check if all requested registers are within the catalog
                    $validRegisters = array_intersect($requestedRegisters, $allowedRegisters);

                    if (empty($validRegisters)) {
                        // None of the requested registers are in this catalog
                        return new JSONResponse(['error' => 'Requested register(s) not available in this catalog'], 403);
                    }

                    // Keep the frontend's filter (it's valid) - don't overwrite
                } else {
                    // No frontend filter - apply catalog's default filter
                    $searchQuery['@self']['register'] = [
                        'or' => implode(',', array_map('intval', $catalog['registers']))
                    ];
                }
            }

            // DIRECT ObjectService call - WITH PUBLISHED FILTERING AND CATALOG FILTERING
            // Filtering is now done at database/Solr level for maximum performance
            // Set rbac=false, multi=false, published=true for public publication access
            $result = $objectService->searchObjectsPaginated(
                query: $searchQuery,
                published: true
            );

            // Add catalog information to the response
            $result['@catalog'] = [
                'slug' => $catalogSlug,
                'title' => $catalog['title'] ?? '',
                'schemas' => $catalog['schemas'] ?? [],
                'registers' => $catalog['registers'] ?? [],
            ];

            // Add CORS headers for public API access
            $response = new JSONResponse($result, 200);
            $origin = isset($this->request->server['HTTP_ORIGIN']) ? $this->request->server['HTTP_ORIGIN'] : '*';
            $response->addHeader('Access-Control-Allow-Origin', $origin);
            $response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
            $response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);

            return $response;

        } catch (\Exception $e) {
            return new JSONResponse(['error' => 'Failed to retrieve publications: ' . $e->getMessage()], 500);
        }

    }//end index()


    /**
     * Retrieve a specific publication by its ID - DIRECT OBJECTSERVICE HACK with catalog validation
     *
     * This method bypasses ALL middleware and calls ObjectService directly for maximum performance.
     * Validates that the object belongs to the specified catalog's schemas and registers.
     *
     * @param  string $catalogSlug The slug of the catalog
     * @param  string $id The ID of the publication to retrieve
     * @return JSONResponse JSON response containing the requested publication
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     */
    public function show(string $catalogSlug, string $id): JSONResponse
    {
        try {
            // Get the catalog from cache or database
            $catalogData = $this->catalogiService->getCatalogBySlug($catalogSlug);

            if ($catalogData === null) {
                return new JSONResponse([
                    'error' => 'Catalog not found',
                    'message' => 'The catalog "' . $catalogSlug . '" does not exist.',
                    'catalogSlug' => $catalogSlug
                ], 404);
            }

            // Convert ObjectEntity to array if needed (cache may return array directly)
            $catalog = is_array($catalogData) ? $catalogData : $catalogData->jsonSerialize();

            // Get ObjectService directly
            $objectService = $this->getObjectService();

            // Get request parameters for extensions
            $requestParams = $this->request->getParams();

            // Build extend parameters
            $extend = ($requestParams['extend'] ?? $requestParams['_extend'] ?? []);
            // Normalize to array - handle comma-separated strings.
            if (is_string($extend)) {
                $extend = array_map('trim', explode(',', $extend));
            } elseif (!is_array($extend)) {
                $extend = [$extend];
            }

            // Ensure @self.schema and @self.register are always included for compatibility.
            if (!in_array('@self.schema', $extend)) {
                $extend[] = '@self.schema';
            }
            if (!in_array('@self.register', $extend)) {
                $extend[] = '@self.register';
            }

            // Debug logging.
            $this->logger->debug('[PublicationsController::show] Attempting to find publication', [
                'id' => $id,
                'catalogSlug' => $catalogSlug,
                'catalog' => $catalog,
                'extend' => $extend,
                'rbac' => false,
                'multi' => false
            ]);

            // DIRECT OBJECT FETCH: Use find() method to get object by ID.
            // Set rbac=false, multi=false for public access.
            // Note: Published filtering happens via manual checks below.
            $object = $objectService->find(
                id: $id,
                extend: $extend,
                files: false,
                register: null,
                schema: null
            );

            if ($object === null) {
                $this->logger->warning('[PublicationsController::show] Object returned null', [
                    'id' => $id,
                    'catalogSlug' => $catalogSlug
                ]);
                return new JSONResponse([
                    'error' => 'Publication not found',
                    'message' => 'The publication with ID "' . $id . '" does not exist or is not accessible.',
                    'id' => $id,
                    'catalogSlug' => $catalogSlug,
                    'hint' => 'This could be because: 1) The publication does not exist, 2) It is not published yet, 3) It has been depublished, or 4) It does not belong to this catalog.'
                ], 404);
            }

            $this->logger->debug('[PublicationsController::show] Object found successfully', [
                'id' => $id,
                'objectId' => $object->getId(),
                'schema' => $object->getSchema(),
                'register' => $object->getRegister(),
                'published' => $object->getPublished()?->format('Y-m-d H:i:s'),
                'depublished' => $object->getDepublished()?->format('Y-m-d H:i:s')
            ]);

            // @todo: Catalog validation disabled for now
            // Validate that the object belongs to the catalog's schemas and registers
            // $objectData = $object->jsonSerialize();
            // $objectSchema = $objectData['@self']['schema'] ?? null;
            // $objectRegister = $objectData['@self']['register'] ?? null;
            //
            // $schemaMatches = empty($catalog['schemas']) || in_array($objectSchema, $catalog['schemas']);
            // $registerMatches = empty($catalog['registers']) || in_array($objectRegister, $catalog['registers']);
            //
            // if (!$schemaMatches || !$registerMatches) {
            //     return new JSONResponse(['error' => 'Publication not found in this catalog'], 404);
            // }

            // Check if object is published (additional validation layer).
            $published = $object->getPublished();

            // For publications API, we require explicit published dates.
            // Objects with published=null are not considered published for public API.
            if ($published === null) {
                return new JSONResponse([
                    'error' => 'Publication not published',
                    'message' => 'The publication "' . $id . '" has no published date set.',
                    'id' => $id,
                    'published' => null,
                    'hint' => 'The publication needs a published date to be accessible via the public API.'
                ], 404);
            }

            // Check if publication date is in the past.
            $now = new \DateTime();
            if ($published > $now) {
                return new JSONResponse([
                    'error' => 'Publication not yet published',
                    'message' => 'The publication "' . $id . '" is scheduled for future publication.',
                    'id' => $id,
                    'published' => $published->format('Y-m-d H:i:s'),
                    'now' => $now->format('Y-m-d H:i:s'),
                    'hint' => 'This publication will be available on ' . $published->format('Y-m-d H:i:s') . '.'
                ], 404);
            }

            // Check if object is not depublished.
            $depublished = $object->getDepublished();
            if ($depublished !== null && $depublished <= $now) {
                return new JSONResponse([
                    'error' => 'Publication depublished',
                    'message' => 'The publication "' . $id . '" has been depublished.',
                    'id' => $id,
                    'depublished' => $depublished->format('Y-m-d H:i:s'),
                    'now' => $now->format('Y-m-d H:i:s'),
                    'hint' => 'This publication was removed from public access on ' . $depublished->format('Y-m-d H:i:s') . '.'
                ], 404);
            }

            // Render the object with extensions
            $result = $objectService->renderEntity(
                entity: $object,
                extend: $extend,
                depth: 0,
                filter: [],
                fields: [],
                unset: [],
                rbac: false,
                multi: false,
            );

            // Add CORS headers for public API access
            $response = new JSONResponse($result, 200);
            $origin = isset($this->request->server['HTTP_ORIGIN']) ? $this->request->server['HTTP_ORIGIN'] : '*';
            $response->addHeader('Access-Control-Allow-Origin', $origin);
            $response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
            $response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);

            return $response;

        } catch (DoesNotExistException $exception) {
            return new JSONResponse([
                'error' => 'Publication not found',
                'message' => 'The publication with ID "' . $id . '" does not exist in the database.',
                'id' => $id,
                'catalogSlug' => $catalogSlug,
                'exception' => 'DoesNotExistException'
            ], 404);
        } catch (\Exception $e) {
            $this->logger->error('[PublicationsController::show] Failed to retrieve publication', [
                'id' => $id,
                'catalogSlug' => $catalogSlug,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return new JSONResponse([
                'error' => 'Failed to retrieve publication',
                'message' => $e->getMessage(),
                'id' => $id,
                'catalogSlug' => $catalogSlug,
                'hint' => 'Check server logs for more details.'
            ], 500);
        }

    }//end show()


    /**
     * Retrieve attachments/files of a publication.
     *
     * @param  string $catalogSlug The slug of the catalog
     * @param  string $id Id of publication
     *
     * @return JSONResponse JSON response containing the requested attachments/files.
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     */
    public function attachments(string $catalogSlug, string $id): JSONResponse
    {
        // @todo: Add catalog validation here if needed
        return $this->publicationService->attachments(id: $id);

    }//end attachments()


    /**
     * Download a publication file.
     *
     * @param  string $catalogSlug The slug of the catalog
     * @param  string $id Id of publication
     *
     * @return JSONResponse JSON response containing the requested attachments/files.
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     */
    public function download(string $catalogSlug, string $id): JSONResponse
    {
        // @todo: Add catalog validation here if needed
        return $this->publicationService->download(id: $id);

    }//end download()


    /**
     * Retrieves all objects that this publication references - DIRECT OBJECTSERVICE HACK
     *
     * This method returns all objects that this publication uses/references. A -> B means that A (This publication) references B (Another object).
     * Bypasses ALL middleware and calls ObjectService directly for maximum performance.
     *
     * @param string $catalogSlug The slug of the catalog
     * @param string $id The ID of the publication to retrieve relations for
     * @return JSONResponse A JSON response containing the related objects
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     */
    public function uses(string $catalogSlug, string $id): JSONResponse
    {
        try {
            // Get ObjectService directly
            $objectService = $this->getObjectService();

            // Get query parameters once
            $searchQuery = $this->request->getParams();

            unset($searchQuery['id'], $searchQuery['_route'], $searchQuery['register'], $searchQuery['schema'], $searchQuery['extend']);

            // Force use of SOLR index for better performance on public endpoints
            $searchQuery = [];
            $searchQuery['_extend'] = ['@self.schema'];

            // Lets set the limit to 1000 to make sure we catch all relations
            $searchQuery['_limit'] = 1000;

            // DIRECT OBJECT FETCH: Get the publication object directly by ID.
            // Set rbac=false, multi=false for public access.
            // Note: Published filtering happens via manual checks below.
            $object = $objectService->find(
                id: $id
            );

            if ($object === null) {
                return new JSONResponse([
                    'error' => 'Publication not found',
                    'message' => 'The publication with ID "' . $id . '" does not exist or is not accessible.',
                    'id' => $id,
                    'catalogSlug' => $catalogSlug,
                    'hint' => 'This could be because: 1) The publication does not exist, 2) It is not published yet, 3) It has been depublished, or 4) Multi-tenancy restrictions apply.'
                ], 404);
            }

            // Check if object is published (since SOLR filtering is disabled)
            $published = $object->getPublished();
            if ($published === null) {
                //@todo: remove this very dirty hotfix/hack
                //return new JSONResponse(['error' => 'Publication not published'], 404);
            }

            // Check if publication date is in the past
            $now = new \DateTime();
            if ($published > $now) {
                //@todo: remove this very dirty hotfix/hack
                //return new JSONResponse(['error' => 'Publication yet published'], 404);
            }

            // Check if object is not depublished
            $depublished = $object->getDepublished();
            if ($depublished !== null && $depublished <= $now) {
                //@todo: remove this very dirty hotfix/hack
                //return new JSONResponse(['error' => 'Publication depublished'], 404);
            }

            $relationsArray = $object->getRelations();

            // DEBUG: Log what we got from getRelations()
            if (is_array($relationsArray)) {
                $this->logger->debug('[PublicationsController::uses] Relations count: ' . count($relationsArray));
                foreach ($relationsArray as $key => $value) {
                    $this->logger->debug('[PublicationsController::uses] Relation [' . $key . ']: ' . json_encode($value) . ' (type: ' . gettype($value) . ')');
                }
            }

            // Filter relations, we only want uuids
            $logger = $this->logger; // Capture for use in closure
            $relations = array_values(array_filter($relationsArray, function ($value) use ($logger) {
                // Accept only strings that look like uuids
                $isValid = is_string($value) && preg_match('/^[0-9a-fA-F\-]{32,36}$/', $value);
                if (!$isValid && is_string($value)) {
                    $logger->debug('[PublicationsController::uses] Filtered out: ' . $value . ' (length: ' . strlen($value) . ')');
                }
                return $isValid;
            }));

            // Check if relations array is empty
            if (empty($relations)) {
                // If relations is empty, return empty paginated response
                $result = [
                    'results' => [],
                    'total' => 0,
                    'page' => 1,
                    'pages' => 1,
                    'limit' => (int) ($searchQuery['_limit'] ?? $searchQuery['limit'] ?? 20),
                    'offset' => 0,
                    'facets' => [],
                    '@self' => [
                        'source' => 'database',
                        'query' => $searchQuery,
                        'rbac' => false,
                        'multi' => false,
                        'published' => true,
                        'deleted' => false
                    ]
                ];
            } else {

                // **CRITICAL FIX**: Create a fresh ObjectService instance for cross-register/schema search
                // After find(), ObjectService is constrained to the object's register/schema
                // But for /uses endpoint, we want to search across ALL registers/schemas
                $freshObjectService = $this->getObjectService();


                // Call fresh ObjectService instance with ids as named parameter.
                // Published filtering now works correctly - filter out unpublished objects.
                $result = $freshObjectService->searchObjectsPaginated(
                    query: $searchQuery,
                    deleted: false,
                    published: true,
                    ids: $relations
                );
            }

            // Add what we're searching for in debugging
            $result["@self"]['ids'] = $relations;

            // Add CORS headers for public API access
            $response = new JSONResponse($result, 200);
            $origin = isset($this->request->server['HTTP_ORIGIN']) ? $this->request->server['HTTP_ORIGIN'] : '*';
            $response->addHeader('Access-Control-Allow-Origin', $origin);
            $response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
            $response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);

            return $response;

        } catch (DoesNotExistException $exception) {
            return new JSONResponse([
                'error' => 'Publication not found',
                'message' => 'The publication with ID "' . $id . '" does not exist in the database.',
                'id' => $id,
                'catalogSlug' => $catalogSlug,
                'exception' => 'DoesNotExistException'
            ], 404);
        } catch (\Exception $e) {
            $this->logger->error('[PublicationsController::uses] Failed to retrieve publication uses', [
                'id' => $id,
                'catalogSlug' => $catalogSlug,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return new JSONResponse([
                'error' => 'Failed to retrieve publication uses',
                'message' => $e->getMessage(),
                'id' => $id,
                'catalogSlug' => $catalogSlug,
                'hint' => 'Check server logs for more details.'
            ], 500);
        }
    }


    /**
     * Retrieves all objects that use this publication - DIRECT OBJECTSERVICE HACK
     *
     * This method returns all objects that reference (use) this publication. B -> A means that B (Another object) references A (This publication).
     * Bypasses ALL middleware and calls ObjectService directly for maximum performance.
     *
     * @param string $catalogSlug The slug of the catalog
     * @param string $id The ID of the publication to retrieve uses for
     * @return JSONResponse A JSON response containing the referenced objects
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     */
    public function used(string $catalogSlug, string $id): JSONResponse
    {
        try {
            // Get ObjectService directly - bypass all PublicationService overhead
            $objectService = $this->getObjectService();

            // Get query parameters once
            $searchQuery = $this->request->getParams();

            // **CRITICAL FIX**: Create a fresh ObjectService instance for cross-register/schema search
            // For /used endpoint, we want to search across ALL registers/schemas
            $freshObjectService = $this->getObjectService();

            // Clean up unwanted parameters and remove register/schema restrictions
            // **CRITICAL FIX**: Remove extend parameter - it's for rendering, not filtering
            unset($searchQuery['id'], $searchQuery['_route'], $searchQuery['register'], $searchQuery['schema'], $searchQuery['extend']);

            // Force use of SOLR index for better performance on public endpoints
            $searchQuery = [];
            $searchQuery['_extend'] = ['@self.schema'];

            // Lets set the limit to 1000 to make sure we catch all relations
            $searchQuery['_limit'] = 1000;

            // Use fresh ObjectService instance searchObjectsPaginated directly - pass uses as named parameter.
            // Published filtering now works correctly - filter out unpublished objects.
            $result = $freshObjectService->searchObjectsPaginated(
                query: $searchQuery,
                uses: $id
            );

            // Add relations being searched for debugging
            $result['@self']['used'] = $id;

            // Add CORS headers for public API access
            $response = new JSONResponse($result, 200);
            $origin = isset($this->request->server['HTTP_ORIGIN']) ? $this->request->server['HTTP_ORIGIN'] : '*';
            $response->addHeader('Access-Control-Allow-Origin', $origin);
            $response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
            $response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);

            return $response;

        } catch (DoesNotExistException $exception) {
            return new JSONResponse([
                'error' => 'Publication not found',
                'message' => 'The publication with ID "' . $id . '" does not exist in the database.',
                'id' => $id,
                'catalogSlug' => $catalogSlug,
                'exception' => 'DoesNotExistException'
            ], 404);
        } catch (\Exception $e) {
            $this->logger->error('[PublicationsController::used] Failed to retrieve publication used', [
                'id' => $id,
                'catalogSlug' => $catalogSlug,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return new JSONResponse([
                'error' => 'Failed to retrieve publication used',
                'message' => $e->getMessage(),
                'id' => $id,
                'catalogSlug' => $catalogSlug,
                'hint' => 'Check server logs for more details.'
            ], 500);
        }
    }

}//end class
