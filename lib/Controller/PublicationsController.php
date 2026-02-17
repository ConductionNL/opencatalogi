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
use OCP\IDBConnection;
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
     * @var integer CORS max age
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
     * @param integer            $corsMaxAge         CORS max age
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
        private readonly IDBConnection $db,
        string $corsMethods = 'PUT, POST, GET, DELETE, PATCH',
        string $corsAllowedHeaders = 'Authorization, Content-Type, Accept',
        int $corsMaxAge = 1728000
    ) {
        parent::__construct($appName, $request);
        $this->corsMethods        = $corsMethods;
        $this->corsAllowedHeaders = $corsAllowedHeaders;
        $this->corsMaxAge         = $corsMaxAge;

    }//end __construct()


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

    }//end getObjectService()


    /**
     * Find the register and schema IDs for an object UUID by searching all magic tables.
     *
     * OpenRegister stores objects in per-register-per-schema "magic tables" named
     * oc_openregister_table_{register}_{schema}. Without knowing the register/schema,
     * we need to search across all these tables to find where an object lives.
     *
     * @param string $uuid The UUID of the object to find
     *
     * @return array{register: int, schema: int}|null The register/schema IDs, or null if not found
     */
    private function findObjectLocation(string $uuid): ?array
    {
        // Get all magic table names from the database schema
        $qb = $this->db->getQueryBuilder();
        $result = $this->db->executeQuery(
            "SELECT table_name FROM information_schema.tables WHERE table_name LIKE 'oc_openregister_table_%' ORDER BY table_name"
        );

        $tables = [];
        while ($row = $result->fetch()) {
            $tables[] = $row['table_name'];
        }
        $result->closeCursor();

        if (empty($tables)) {
            return null;
        }

        // Build a UNION ALL query to search all magic tables for the UUID in one query
        $unionParts = [];
        $quotedUuid = $this->db->quote($uuid);
        foreach ($tables as $table) {
            // Extract register/schema from table name (oc_openregister_table_{register}_{schema})
            if (preg_match('/^oc_openregister_table_(\d+)_(\d+)$/', $table, $matches)) {
                $register = (int) $matches[1];
                $schema   = (int) $matches[2];
                $unionParts[] = "(SELECT {$register} AS register_id, {$schema} AS schema_id FROM {$table} WHERE _uuid = {$quotedUuid})";
            }
        }

        if (empty($unionParts)) {
            return null;
        }

        $sql = implode(' UNION ALL ', $unionParts) . ' LIMIT 1';
        $result = $this->db->executeQuery($sql);
        $row = $result->fetch();
        $result->closeCursor();

        if ($row === false) {
            return null;
        }

        return [
            'register' => (int) $row['register_id'],
            'schema'   => (int) $row['schema_id'],
        ];

    }//end findObjectLocation()


    /**
     * Extract filter values from various filter formats
     *
     * Handles:
     * - Single value: 1
     * - Simple array: [1, 2, 3]
     * - OR operator: ['or' => '1,2,3'] or ['or' => [1, 2, 3]]
     * - AND operator: ['and' => '1,2,3'] or ['and' => [1, 2, 3]]
     *
     * @param  mixed $filter The filter value in any supported format
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
            } else if (isset($filter['and'])) {
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
                return array_map(
                    'intval',
                    array_filter(
                        $values,
                        function ($v) {
                            return is_numeric($v) || (is_string($v) && trim($v) !== '');
                        }
                    )
                );
            }

            // Single value in the operator
            if (is_numeric($values)) {
                return [(int) $values];
            }
        }//end if

        // String format (comma-separated)
        if (is_string($filter)) {
            $values = explode(',', $filter);
            return array_map(
                'intval',
                array_filter(
                    $values,
                    function ($v) {
                        return trim($v) !== '';
                    }
                )
            );
        }

        return [];

    }//end extractFilterValues()


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

    }//end preflightedCors()


    /**
     * Retrieve all publications from this catalog - DIRECT OBJECTSERVICE HACK with catalog filtering
     *
     * This method bypasses ALL middleware and calls ObjectService directly for maximum performance.
     * Filters by catalog's schemas and registers as well as published=true.
     *
     * @param  string $catalogSlug The slug of the catalog to retrieve publications from
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
            $searchQuery                    = $objectService->buildSearchQuery($queryParams);
            $searchQuery['_includeDeleted'] = false;

            // Clean up catalog-specific parameters
            unset($searchQuery['catalogSlug'], $searchQuery['fq']);

            // DATABASE-LEVEL FILTERING: Handle catalog filtering intelligently
            // Use _schemas for multi-schema search and faceting
            // Note: schemas/registers may be JSON-encoded strings or arrays
            if (!empty($catalog['schemas'])) {
                $schemas = $catalog['schemas'];
                // Parse JSON string if needed
                if (is_string($schemas)) {
                    $schemas = json_decode($schemas, true) ?? [];
                }
                $schemas = array_map('intval', $schemas);
                // Pass all schemas for both search and faceting (enables multi-schema search)
                $searchQuery['_schemas'] = $schemas;
                // Only set _schema for single-schema catalogs (enables magic mapper optimization)
                if (count($schemas) === 1) {
                    $searchQuery['_schema'] = $schemas[0];
                } else {
                    // Explicitly unset _schema for multi-schema search to prevent auto-setting
                    unset($searchQuery['_schema']);
                }
            }

            if (!empty($catalog['registers'])) {
                $registers = $catalog['registers'];
                // Parse JSON string if needed
                if (is_string($registers)) {
                    $registers = json_decode($registers, true) ?? [];
                }
                $registers = array_map('intval', $registers);
                // Use first register for magic mapper routing
                $searchQuery['_register'] = $registers[0];
            }

            // DIRECT ObjectService call - WITH CATALOG FILTERING
            // Filtering is now done at database/Solr level for maximum performance
            // Set rbac=true to enable schema authorization (conditional rules like geregistreerdDoor)
            // Set multi=false for public access (no organization filtering)
            // published=false to show all objects (schema authorization determines access, not published status)
            $result = $objectService->searchObjectsPaginated(
                query: $searchQuery,
                _rbac: true,
                _multitenancy: false,
                published: false
            );

            // Add catalog information to the response
            $result['@catalog'] = [
                'slug'      => $catalogSlug,
                'title'     => ($catalog['title'] ?? ''),
                'schemas'   => ($catalog['schemas'] ?? []),
                'registers' => ($catalog['registers'] ?? []),
            ];

            // Add CORS headers for public API access
            $response = new JSONResponse($result, 200);
            $origin   = isset($this->request->server['HTTP_ORIGIN']) ? $this->request->server['HTTP_ORIGIN'] : '*';
            $response->addHeader('Access-Control-Allow-Origin', $origin);
            $response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
            $response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);

            return $response;
        } catch (\Exception $e) {
            return new JSONResponse(['error' => 'Failed to retrieve publications: '.$e->getMessage()], 500);
        }//end try

    }//end index()


    /**
     * Retrieve a specific publication by its ID - DIRECT OBJECTSERVICE HACK with catalog validation
     *
     * This method bypasses ALL middleware and calls ObjectService directly for maximum performance.
     * Validates that the object belongs to the specified catalog's schemas and registers.
     *
     * @param  string $catalogSlug The slug of the catalog
     * @param  string $id          The ID of the publication to retrieve
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
                return new JSONResponse(
                    [
                        'error'       => 'Catalog not found',
                        'message'     => 'The catalog "'.$catalogSlug.'" does not exist.',
                        'catalogSlug' => $catalogSlug,
                    ],
                    404
                );
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
            } else if (!is_array($extend)) {
                $extend = [$extend];
            }

            // Note: @self.schema and @self.register are now provided at response @self level
            // for list operations, and can be requested via _extend for single object fetches.

            // Debug logging.
            $this->logger->debug(
                '[PublicationsController::show] Attempting to find publication',
                [
                    'id'          => $id,
                    'catalogSlug' => $catalogSlug,
                    'catalog'     => $catalog,
                    'extend'      => $extend,
                    'rbac'        => false,
                    'multi'       => false,
                ]
            );

            // DIRECT OBJECT FETCH: Use searchObjects with UUID filter instead of find()
            // because find() has a `deleted IS NULL` condition that fails on objects
            // with `deleted: []` (empty array, not NULL).
            //
            // OpenRegister routes to magic tables only when register+schema are provided.
            // Strategy: first try catalog's register/schema combos (fast path),
            // then fall back to searching all magic tables via DB lookup.
            $object  = null;
            $objects = [];

            // Fast path: try catalog's register/schema combinations first
            $catalogRegisters = $catalog['registers'] ?? [];
            $catalogSchemas   = $catalog['schemas'] ?? [];

            if (!empty($catalogRegisters) && !empty($catalogSchemas)) {
                foreach ($catalogRegisters as $reg) {
                    foreach ($catalogSchemas as $sch) {
                        $searchQuery = [
                            '@self' => [
                                'uuid'     => $id,
                                'register' => $reg,
                                'schema'   => $sch,
                            ],
                        ];

                        $objects = $objectService->searchObjects(
                            query: $searchQuery,
                            _rbac: false,
                            _multitenancy: false,
                        );

                        if (!empty($objects)) {
                            $object = $objects[0];
                            break 2;
                        }
                    }
                }
            }

            // Fallback: find the object's register/schema across all magic tables
            if ($object === null) {
                $location = $this->findObjectLocation($id);

                if ($location !== null) {
                    $searchQuery = [
                        '@self' => [
                            'uuid'     => $id,
                            'register' => $location['register'],
                            'schema'   => $location['schema'],
                        ],
                    ];

                    $objects = $objectService->searchObjects(
                        query: $searchQuery,
                        _rbac: false,
                        _multitenancy: false,
                    );

                    if (!empty($objects)) {
                        $object = $objects[0];
                    }
                }
            }

            if ($object === null) {
                $this->logger->warning(
                    '[PublicationsController::show] Object not found in any register/schema',
                    [
                        'id'          => $id,
                        'catalogSlug' => $catalogSlug,
                    ]
                );
                return new JSONResponse(
                    [
                        'error'       => 'Publication not found',
                        'message'     => 'The publication with ID "'.$id.'" does not exist or is not accessible.',
                        'id'          => $id,
                        'catalogSlug' => $catalogSlug,
                    ],
                    404
                );
            }

            $this->logger->debug(
                '[PublicationsController::show] Object found successfully',
                [
                    'id'          => $id,
                    'objectId'    => $object->getId(),
                    'schema'      => $object->getSchema(),
                    'register'    => $object->getRegister(),
                ]
            );

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
            // return new JSONResponse(['error' => 'Publication not found in this catalog'], 404);
            // }

            // Render the object with extensions
            $result = $objectService->renderEntity(
                entity: $object,
                _extend: $extend,
                depth: 0,
                filter: [],
                fields: [],
                unset: [],
                _rbac: false,
                _multitenancy: false,
            );

            // Add CORS headers for public API access
            $response = new JSONResponse($result, 200);
            $origin   = isset($this->request->server['HTTP_ORIGIN']) ? $this->request->server['HTTP_ORIGIN'] : '*';
            $response->addHeader('Access-Control-Allow-Origin', $origin);
            $response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
            $response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);

            return $response;
        } catch (DoesNotExistException $exception) {
            return new JSONResponse(
                [
                    'error'       => 'Publication not found',
                    'message'     => 'The publication with ID "'.$id.'" does not exist in the database.',
                    'id'          => $id,
                    'catalogSlug' => $catalogSlug,
                    'exception'   => 'DoesNotExistException',
                ],
                404
            );
        } catch (\Exception $e) {
            $this->logger->error(
                '[PublicationsController::show] Failed to retrieve publication',
                [
                    'id'          => $id,
                    'catalogSlug' => $catalogSlug,
                    'error'       => $e->getMessage(),
                    'trace'       => $e->getTraceAsString(),
                ]
            );
            return new JSONResponse(
                [
                    'error'       => 'Failed to retrieve publication',
                    'message'     => $e->getMessage(),
                    'id'          => $id,
                    'catalogSlug' => $catalogSlug,
                    'hint'        => 'Check server logs for more details.',
                ],
                500
            );
        }//end try

    }//end show()


    /**
     * Retrieve attachments/files of a publication.
     *
     * @param string $catalogSlug The slug of the catalog
     * @param string $id          Id of publication
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
        try {
            // Get the catalog from cache or database
            $catalogData = $this->catalogiService->getCatalogBySlug($catalogSlug);

            if ($catalogData === null) {
                return new JSONResponse(
                    [
                        'error'       => 'Catalog not found',
                        'message'     => 'The catalog "'.$catalogSlug.'" does not exist.',
                        'catalogSlug' => $catalogSlug,
                    ],
                    404
                );
            }

            // Convert ObjectEntity to array if needed
            $catalog = is_array($catalogData) ? $catalogData : $catalogData->jsonSerialize();

            // Extract register and schema from catalog for magic table support.
            $catalogRegisters = $catalog['registers'] ?? [];
            $catalogSchemas = $catalog['schemas'] ?? [];
            // Parse JSON string if needed (catalog fields may be JSON-encoded)
            if (is_string($catalogRegisters)) {
                $catalogRegisters = json_decode($catalogRegisters, true) ?? [];
            }
            if (is_string($catalogSchemas)) {
                $catalogSchemas = json_decode($catalogSchemas, true) ?? [];
            }
            $register = !empty($catalogRegisters) ? (int) $catalogRegisters[0] : null;

            // First verify the object exists in this catalog's register/schema
            $objectService = $this->getObjectService();

            // For multi-schema catalogs, loop through all schemas to find the object.
            $object = null;
            $schemasToTry = array_map('intval', $catalogSchemas);
            foreach ($schemasToTry as $schemaId) {
                try {
                    $object = $objectService->find(
                        id: $id,
                        _extend: [],
                        files: false,
                        register: $register,
                        schema: $schemaId,
                        _rbac: false,
                        _multitenancy: false
                    );
                    if ($object !== null) {
                        break;
                    }
                } catch (DoesNotExistException $e) {
                    // Object not found in this schema, try next one.
                    continue;
                }
            }

            if ($object === null) {
                return new JSONResponse(
                    [
                        'error'       => 'Publication not found',
                        'message'     => 'The publication with ID "'.$id.'" does not exist.',
                        'id'          => $id,
                        'catalogSlug' => $catalogSlug,
                    ],
                    404
                );
            }

            return $this->publicationService->attachments(id: $id);
        } catch (DoesNotExistException $exception) {
            return new JSONResponse(
                [
                    'error'       => 'Publication not found',
                    'message'     => 'The publication with ID "'.$id.'" does not exist in the database.',
                    'id'          => $id,
                    'catalogSlug' => $catalogSlug,
                ],
                404
            );
        } catch (\Exception $e) {
            return new JSONResponse(
                [
                    'error'   => 'Failed to retrieve attachments',
                    'message' => $e->getMessage(),
                ],
                500
            );
        }

    }//end attachments()


    /**
     * Download a publication file.
     *
     * @param string $catalogSlug The slug of the catalog
     * @param string $id          Id of publication
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
        try {
            // Get the catalog from cache or database
            $catalogData = $this->catalogiService->getCatalogBySlug($catalogSlug);

            if ($catalogData === null) {
                return new JSONResponse(
                    [
                        'error'       => 'Catalog not found',
                        'message'     => 'The catalog "'.$catalogSlug.'" does not exist.',
                        'catalogSlug' => $catalogSlug,
                    ],
                    404
                );
            }

            // Convert ObjectEntity to array if needed
            $catalog = is_array($catalogData) ? $catalogData : $catalogData->jsonSerialize();

            // Extract register and schema from catalog for magic table support.
            $catalogRegisters = $catalog['registers'] ?? [];
            $catalogSchemas = $catalog['schemas'] ?? [];
            // Parse JSON string if needed (catalog fields may be JSON-encoded)
            if (is_string($catalogRegisters)) {
                $catalogRegisters = json_decode($catalogRegisters, true) ?? [];
            }
            if (is_string($catalogSchemas)) {
                $catalogSchemas = json_decode($catalogSchemas, true) ?? [];
            }
            $register = !empty($catalogRegisters) ? (int) $catalogRegisters[0] : null;

            // First verify the object exists in this catalog's register/schema
            $objectService = $this->getObjectService();

            // For multi-schema catalogs, loop through all schemas to find the object.
            $object = null;
            $schemasToTry = array_map('intval', $catalogSchemas);
            foreach ($schemasToTry as $schemaId) {
                try {
                    $object = $objectService->find(
                        id: $id,
                        _extend: [],
                        files: false,
                        register: $register,
                        schema: $schemaId,
                        _rbac: false,
                        _multitenancy: false
                    );
                    if ($object !== null) {
                        break;
                    }
                } catch (DoesNotExistException $e) {
                    // Object not found in this schema, try next one.
                    continue;
                }
            }

            if ($object === null) {
                return new JSONResponse(
                    [
                        'error'       => 'Publication not found',
                        'message'     => 'The publication with ID "'.$id.'" does not exist.',
                        'id'          => $id,
                        'catalogSlug' => $catalogSlug,
                    ],
                    404
                );
            }

            return $this->publicationService->download(id: $id);
        } catch (DoesNotExistException $exception) {
            return new JSONResponse(
                [
                    'error'       => 'Publication not found',
                    'message'     => 'The publication with ID "'.$id.'" does not exist in the database.',
                    'id'          => $id,
                    'catalogSlug' => $catalogSlug,
                ],
                404
            );
        } catch (\Exception $e) {
            return new JSONResponse(
                [
                    'error'   => 'Failed to download publication',
                    'message' => $e->getMessage(),
                ],
                500
            );
        }

    }//end download()


    /**
     * Retrieves all objects that this publication references - DIRECT OBJECTSERVICE HACK
     *
     * This method returns all objects that this publication uses/references. A -> B means that A (This publication) references B (Another object).
     * Bypasses ALL middleware and calls ObjectService directly for maximum performance.
     *
     * @param  string $catalogSlug The slug of the catalog
     * @param  string $id          The ID of the publication to retrieve relations for
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
            // Get the catalog from cache or database
            $catalogData = $this->catalogiService->getCatalogBySlug($catalogSlug);

            if ($catalogData === null) {
                return new JSONResponse(
                    [
                        'error'       => 'Catalog not found',
                        'message'     => 'The catalog "'.$catalogSlug.'" does not exist.',
                        'catalogSlug' => $catalogSlug,
                    ],
                    404
                );
            }

            // Convert ObjectEntity to array if needed
            $catalog = is_array($catalogData) ? $catalogData : $catalogData->jsonSerialize();

            // Get ObjectService directly
            $objectService = $this->getObjectService();

            // Extract register and schema from catalog for magic table support.
            $catalogRegisters = $catalog['registers'] ?? [];
            $catalogSchemas = $catalog['schemas'] ?? [];
            // Parse JSON string if needed (catalog fields may be JSON-encoded)
            if (is_string($catalogRegisters)) {
                $catalogRegisters = json_decode($catalogRegisters, true) ?? [];
            }
            if (is_string($catalogSchemas)) {
                $catalogSchemas = json_decode($catalogSchemas, true) ?? [];
            }
            $register = !empty($catalogRegisters) ? (int) $catalogRegisters[0] : null;

            // For multi-schema catalogs, loop through all schemas to find the object.
            $object = null;
            $schemasToTry = array_map('intval', $catalogSchemas);
            foreach ($schemasToTry as $schemaId) {
                try {
                    $object = $objectService->find(
                        id: $id,
                        _extend: [],
                        files: false,
                        register: $register,
                        schema: $schemaId,
                        _rbac: false,
                        _multitenancy: false
                    );
                    if ($object !== null) {
                        break;
                    }
                } catch (DoesNotExistException $e) {
                    // Object not found in this schema, try next one.
                    continue;
                }
            }

            if ($object === null) {
                return new JSONResponse(
                    [
                        'error'       => 'Publication not found',
                        'message'     => 'The publication with ID "'.$id.'" does not exist or is not accessible.',
                        'id'          => $id,
                        'catalogSlug' => $catalogSlug,
                        'hint'        => 'This could be because: 1) The publication does not exist, 2) It is not published yet, 3) It has been depublished, or 4) Multi-tenancy restrictions apply.',
                    ],
                    404
                );
            }

            $relationsArray = $object->getRelations();

            // DEBUG: Log what we got from getRelations()
            if (is_array($relationsArray)) {
                $this->logger->debug('[PublicationsController::uses] Relations count: '.count($relationsArray));
                foreach ($relationsArray as $key => $value) {
                    $this->logger->debug('[PublicationsController::uses] Relation ['.$key.']: '.json_encode($value).' (type: '.gettype($value).')');
                }
            }

            // Filter relations, we only want uuids
            $logger = $this->logger;
// Capture for use in closure
            $relations = array_values(
                array_filter(
                    $relationsArray,
                    function ($value) use ($logger) {
                        // Accept only strings that look like uuids
                        $isValid = is_string($value) && preg_match('/^[0-9a-fA-F\-]{32,36}$/', $value);
                        if (!$isValid && is_string($value)) {
                            $logger->debug('[PublicationsController::uses] Filtered out: '.$value.' (length: '.strlen($value).')');
                        }

                        return $isValid;
                    }
                )
            );

            // Check if relations array is empty
            if (empty($relations)) {
                // If relations is empty, return empty paginated response
                $searchQuery = [
                    '_extend' => ['@self.schema'],
                    '_limit'  => 1000,
                ];
                $result = [
                    'results' => [],
                    'total'   => 0,
                    'page'    => 1,
                    'pages'   => 1,
                    'limit'   => 1000,
                    'offset'  => 0,
                    'facets'  => [],
                    '@self'   => [
                        'source'    => 'database',
                        'query'     => $searchQuery,
                        'rbac'      => false,
                        'multi'     => false,
                        'published' => true,
                        'deleted'   => false,
                    ],
                ];
            } else {
                // Get request parameters for extensions
                $requestParams = $this->request->getParams();
                $extend = ($requestParams['extend'] ?? $requestParams['_extend'] ?? []);
                // Normalize to array - handle comma-separated strings.
                if (is_string($extend)) {
                    $extend = array_map('trim', explode(',', $extend));
                } else if (!is_array($extend)) {
                    $extend = [$extend];
                }
                // Ensure @self.schema is always included for related objects.
                if (!in_array('@self.schema', $extend) && !in_array('_schema', $extend)) {
                    $extend[] = '@self.schema';
                }

                // Search for objects by their UUIDs across ALL registers and schemas.
                // Related objects can be in ANY register/schema, not just the catalog's.
                // Explicitly set _register and _schema to null to prevent ObjectService from auto-setting them.
                $searchQuery = [
                    '_extend'   => $extend,
                    '_limit'    => 1000,
                    '_ids'      => $relations,
                    '_register' => null,
                    '_schema'   => null,
                ];

                // Create a fresh ObjectService to avoid state from previous operations.
                // Note: Don't filter by published status for related objects - they may not be published
                // but should still be visible when referenced by a published object.
                $freshObjectService = $this->getObjectService();
                $result             = $freshObjectService->searchObjectsPaginated(
                    query: $searchQuery,
                    deleted: false,
                    published: false
                );
            }//end if

            // Add what we're searching for in debugging
            $result["@self"]['ids'] = $relations;

            // Add CORS headers for public API access
            $response = new JSONResponse($result, 200);
            $origin   = isset($this->request->server['HTTP_ORIGIN']) ? $this->request->server['HTTP_ORIGIN'] : '*';
            $response->addHeader('Access-Control-Allow-Origin', $origin);
            $response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
            $response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);

            return $response;
        } catch (DoesNotExistException $exception) {
            return new JSONResponse(
                [
                    'error'       => 'Publication not found',
                    'message'     => 'The publication with ID "'.$id.'" does not exist in the database.',
                    'id'          => $id,
                    'catalogSlug' => $catalogSlug,
                    'exception'   => 'DoesNotExistException',
                ],
                404
            );
        } catch (\Exception $e) {
            $this->logger->error(
                '[PublicationsController::uses] Failed to retrieve publication uses',
                [
                    'id'          => $id,
                    'catalogSlug' => $catalogSlug,
                    'error'       => $e->getMessage(),
                    'trace'       => $e->getTraceAsString(),
                ]
            );
            return new JSONResponse(
                [
                    'error'       => 'Failed to retrieve publication uses',
                    'message'     => $e->getMessage(),
                    'id'          => $id,
                    'catalogSlug' => $catalogSlug,
                    'hint'        => 'Check server logs for more details.',
                ],
                500
            );
        }//end try

    }//end uses()


    /**
     * Retrieves all objects that use this publication - DIRECT OBJECTSERVICE HACK
     *
     * This method returns all objects that reference (use) this publication. B -> A means that B (Another object) references A (This publication).
     * Bypasses ALL middleware and calls ObjectService directly for maximum performance.
     *
     * @param  string $catalogSlug The slug of the catalog
     * @param  string $id          The ID of the publication to retrieve uses for
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
            // Get the catalog from cache or database
            $catalogData = $this->catalogiService->getCatalogBySlug($catalogSlug);

            if ($catalogData === null) {
                return new JSONResponse(
                    [
                        'error'       => 'Catalog not found',
                        'message'     => 'The catalog "'.$catalogSlug.'" does not exist.',
                        'catalogSlug' => $catalogSlug,
                    ],
                    404
                );
            }

            // Convert ObjectEntity to array if needed
            $catalog = is_array($catalogData) ? $catalogData : $catalogData->jsonSerialize();

            // Get ObjectService directly - bypass all PublicationService overhead
            $objectService = $this->getObjectService();

            // Get request parameters for extensions
            $requestParams = $this->request->getParams();
            $extend = ($requestParams['extend'] ?? $requestParams['_extend'] ?? []);
            // Normalize to array - handle comma-separated strings.
            if (is_string($extend)) {
                $extend = array_map('trim', explode(',', $extend));
            } else if (!is_array($extend)) {
                $extend = [$extend];
            }
            // Ensure @self.schema is always included for related objects.
            if (!in_array('@self.schema', $extend) && !in_array('_schema', $extend)) {
                $extend[] = '@self.schema';
            }

            // Build search query for cross-register search.
            // Search for objects that have this UUID in their _relations using _relations_contains filter.
            // Explicitly set _register and _schema to null to prevent ObjectService from auto-setting them.
            $searchQuery = [
                '_extend'             => $extend,
                '_limit'              => 1000,
                '_relations_contains' => $id,
                '_register'           => null,
                '_schema'             => null,
            ];

            // Search for objects that have this UUID in their relations.
            // Note: Don't filter by published status - related objects may not be published
            // but should still be visible when showing usage relationships.
            $result = $objectService->searchObjectsPaginated(
                query: $searchQuery,
                deleted: false,
                published: false
            );

            // Add relations being searched for debugging
            $result['@self']['used'] = $id;

            // Add CORS headers for public API access
            $response = new JSONResponse($result, 200);
            $origin   = isset($this->request->server['HTTP_ORIGIN']) ? $this->request->server['HTTP_ORIGIN'] : '*';
            $response->addHeader('Access-Control-Allow-Origin', $origin);
            $response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
            $response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);

            return $response;
        } catch (DoesNotExistException $exception) {
            return new JSONResponse(
                [
                    'error'       => 'Publication not found',
                    'message'     => 'The publication with ID "'.$id.'" does not exist in the database.',
                    'id'          => $id,
                    'catalogSlug' => $catalogSlug,
                    'exception'   => 'DoesNotExistException',
                ],
                404
            );
        } catch (\Exception $e) {
            $this->logger->error(
                '[PublicationsController::used] Failed to retrieve publication used',
                [
                    'id'          => $id,
                    'catalogSlug' => $catalogSlug,
                    'error'       => $e->getMessage(),
                    'trace'       => $e->getTraceAsString(),
                ]
            );
            return new JSONResponse(
                [
                    'error'       => 'Failed to retrieve publication used',
                    'message'     => $e->getMessage(),
                    'id'          => $id,
                    'catalogSlug' => $catalogSlug,
                    'hint'        => 'Check server logs for more details.',
                ],
                500
            );
        }//end try

    }//end used()


}//end class
