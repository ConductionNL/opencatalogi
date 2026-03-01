<?php
/**
 * OpenCatalogi Publications Controller.
 *
 * Controller for handling publication-related operations in the OpenCatalogi app.
 *
 * @category Controller
 * @package  OCA\OpenCatalogi\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 */

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\Service\PublicationService;
use OCA\OpenCatalogi\Service\CatalogiService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use OCP\AppFramework\Http\Response;
use RuntimeException;

/**
 * Class PublicationsController.
 *
 * Controller for handling publication-related operations in the OpenCatalogi app.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class PublicationsController extends Controller
{

    /**
     * Allowed CORS methods.
     *
     * @var string Allowed CORS methods
     */
    private string $corsMethods;

    /**
     * Allowed CORS headers.
     *
     * @var string Allowed CORS headers
     */
    private string $corsAllowedHeaders;

    /**
     * CORS max age.
     *
     * @var integer CORS max age
     */
    private int $corsMaxAge;

    /**
     * PublicationsController constructor.
     *
     * @param string             $appName            The name of the app
     * @param IRequest           $request            The request object
     * @param PublicationService $publicationService The publication service
     * @param CatalogiService    $catalogiService    The catalogi service
     * @param ContainerInterface $container          The container for dependency injection
     * @param IAppManager        $appManager         The app manager
     * @param LoggerInterface    $logger             PSR-3 logger
     * @param IDBConnection      $db                 Database connection
     * @param IL10N              $l10n               Localization service
     * @param string             $corsMethods        Allowed CORS methods
     * @param string             $corsAllowedHeaders Allowed CORS headers
     * @param integer            $corsMaxAge         CORS max age
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        $appName,
        IRequest $request,
        private readonly PublicationService $publicationService,
        private readonly CatalogiService $catalogiService,
        private readonly ContainerInterface $container,
        private readonly IAppManager $appManager,
        private readonly LoggerInterface $logger,
        private readonly IDBConnection $db,
        private readonly IL10N $l10n,
        string $corsMethods='PUT, POST, GET, DELETE, PATCH',
        string $corsAllowedHeaders='Authorization, Content-Type, Accept',
        int $corsMaxAge=1728000
    ) {
        parent::__construct($appName, $request);
        $this->corsMethods        = $corsMethods;
        $this->corsAllowedHeaders = $corsAllowedHeaders;
        $this->corsMaxAge         = $corsMaxAge;

    }//end __construct()

    /**
     * Attempts to retrieve the OpenRegister ObjectService from the container.
     *
     * @return object|null The OpenRegister ObjectService if available, null otherwise.
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     */
    private function getObjectService()
    {
        if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        }

        throw new RuntimeException('OpenRegister service is not available.');

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
     * @return array{register: int, schema: int}|null The register/schema IDs, or null if not found.
     */
    private function findObjectLocation(string $uuid): ?array
    {
        // Get all magic table names from the database schema.
        $sql    = "SELECT table_name FROM information_schema.tables";
        $sql   .= " WHERE table_name LIKE 'oc_openregister_table_%'";
        $sql   .= " ORDER BY table_name";
        $result = $this->db->executeQuery($sql);

        $tables = [];
        while (($row = $result->fetch()) !== false) {
            $tables[] = $row['table_name'];
        }

        $result->closeCursor();

        if (empty($tables) === true) {
            return null;
        }

        // Build a UNION ALL query to search all magic tables for the UUID.
        $unionParts = [];
        $quotedUuid = $this->db->quote($uuid);
        $matches    = [];
        foreach ($tables as $table) {
            // Extract register and schema from table name pattern.
            if (preg_match(
                pattern: '/^oc_openregister_table_(\d+)_(\d+)$/',
                subject: $table,
                matches: $matches
            ) === 1
            ) {
                $register     = (int) $matches[1];
                $schema       = (int) $matches[2];
                $part         = "(SELECT {$register} AS register_id,";
                $part        .= " {$schema} AS schema_id";
                $part        .= " FROM {$table} WHERE _uuid = {$quotedUuid})";
                $unionParts[] = $part;
            }
        }

        if (empty($unionParts) === true) {
            return null;
        }

        $sql    = implode(' UNION ALL ', $unionParts).' LIMIT 1';
        $result = $this->db->executeQuery($sql);
        $row    = $result->fetch();
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
     * Implements a preflighted CORS response for OPTIONS requests.
     *
     * @return Response The CORS response
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     */
    public function preflightedCors(): Response
    {
        // Determine the origin.
        $origin = $this->request->getHeader('Origin');
        if ($origin === '' || $origin === false) {
            $origin = '*';
        }

        // Create and configure the response.
        $response = new Response();
        $response->addHeader('Access-Control-Allow-Origin', $origin);
        $response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
        $response->addHeader('Access-Control-Max-Age', (string) $this->corsMaxAge);
        $response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);
        $response->addHeader('Access-Control-Allow-Credentials', 'false');

        return $response;

    }//end preflightedCors()

    /**
     * Retrieve all publications from this catalog with catalog filtering.
     *
     * This method bypasses ALL middleware and calls ObjectService directly for maximum performance.
     * Filters by catalog's schemas and registers as well as published=true.
     *
     * @param string $catalogSlug The slug of the catalog to retrieve publications from
     *
     * @return JSONResponse JSON response containing publications, pagination info, and optionally facets
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function index(string $catalogSlug): JSONResponse
    {
        try {
            // Get the catalog from cache or database.
            $catalogData = $this->catalogiService->getCatalogBySlug($catalogSlug);

            if ($catalogData === null) {
                return new JSONResponse(['error' => $this->l10n->t('Catalog not found')], 404);
            }

            // Convert ObjectEntity to array if needed (cache may return array directly).
            $catalog = $catalogData;

            // Get ObjectService directly bypassing PublicationService overhead.
            $objectService = $this->getObjectService();

            // Get query parameters which ObjectService handles PHP dot-to-underscore conversion.
            $queryParams = $this->request->getParams();

            // Use ObjectService centralized query builder which handles dot-to-underscore conversion.
            $searchQuery = $objectService->buildSearchQuery($queryParams);
            $searchQuery['_includeDeleted'] = false;

            // Clean up catalog-specific parameters.
            unset($searchQuery['catalogSlug'], $searchQuery['fq']);

            // Handle catalog filtering intelligently using _schemas for multi-schema search.
            if (empty($catalog['schemas']) === false) {
                $schemas = $catalog['schemas'];
                // Parse JSON string if needed.
                if (is_string($schemas) === true) {
                    $schemas = json_decode($schemas, true) ?? [];
                }

                $schemas = array_map('intval', $schemas);
                // Pass all schemas for both search and faceting.
                $searchQuery['_schemas'] = $schemas;
                // Only set _schema for single-schema catalogs for magic mapper optimization.
                if (count($schemas) === 1) {
                    $searchQuery['_schema'] = $schemas[0];
                }

                if (count($schemas) !== 1) {
                    // Explicitly unset _schema for multi-schema search to prevent auto-setting.
                    unset($searchQuery['_schema']);
                }
            }//end if

            if (empty($catalog['registers']) === false) {
                $registers = $catalog['registers'];
                // Parse JSON string if needed.
                if (is_string($registers) === true) {
                    $registers = json_decode($registers, true) ?? [];
                }

                $registers = array_map('intval', $registers);
                if (count($registers) === 1) {
                    // Single register: use magic mapper optimization.
                    $searchQuery['_register'] = $registers[0];
                }

                if (count($registers) !== 1) {
                    // Multi-register: pass all register IDs and prevent auto-setting.
                    $searchQuery['_registers'] = $registers;
                    $searchQuery['_register']  = null;

                    // Multi-register search: strip _order on non-universal fields.
                    $universalOrderFields = ['uuid', 'created', 'updated', 'published', 'depublished'];
                    if (empty($searchQuery['_order']) === false && is_array($searchQuery['_order']) === true) {
                        foreach (array_keys($searchQuery['_order']) as $orderField) {
                            if (in_array($orderField, $universalOrderFields, true) === false) {
                                unset($searchQuery['_order'][$orderField]);
                            }
                        }

                        if (empty($searchQuery['_order']) === true) {
                            unset($searchQuery['_order']);
                        }
                    }
                }//end if
            }//end if

            // DIRECT ObjectService call with catalog filtering.
            // Set rbac=true to enable schema authorization.
            // Set multi=false for public access.
            $result = $objectService->searchObjectsPaginated(
                query: $searchQuery,
                _rbac: true,
                _multitenancy: false
            );

            // Strip empty values from results unless _empty=true is set.
            // This reduces response payload by omitting null/empty properties.
            $includeEmpty = filter_var(
                value: $this->request->getParam(key: '_empty', default: false),
                filter: FILTER_VALIDATE_BOOLEAN
            );
            if ($includeEmpty === false && isset($result['results']) === true && is_array($result['results']) === true) {
                $result['results'] = array_map(
                    callback: function ($item) {
                        // Serialize ObjectEntity instances to arrays before stripping empty values.
                        if (is_array($item) === false && method_exists(object_or_class: $item, method: 'jsonSerialize') === true) {
                            $item = $item->jsonSerialize();
                        }

                        return is_array($item) === true ? $this->stripEmptyValues(data: $item) : $item;
                    },
                    array: $result['results']
                );
            }

            // Add catalog information to the response.
            $result['@catalog'] = [
                'slug'      => $catalogSlug,
                'title'     => ($catalog['title'] ?? ''),
                'schemas'   => ($catalog['schemas'] ?? []),
                'registers' => ($catalog['registers'] ?? []),
            ];

            // Enrich @self with resolved schema and register objects for frontend enrichment.
            // The frontend expects @self.schemas[id] = {slug, title} and @self.registers[id] = {slug, title}.
            try {
                $schemaMapper   = $this->container->get('OCA\OpenRegister\Db\SchemaMapper');
                $registerMapper = $this->container->get('OCA\OpenRegister\Db\RegisterMapper');

                $resolvedSchemas = [];
                $schemaIds       = $catalog['schemas'] ?? [];
                if (is_string($schemaIds) === true) {
                    $schemaIds = json_decode($schemaIds, true) ?? [];
                }

                foreach ($schemaIds as $schemaId) {
                    try {
                        $schema                        = $schemaMapper->find((int) $schemaId);
                        $resolvedSchemas[$schemaId] = [
                            'id'    => $schema->getId(),
                            'slug'  => $schema->getSlug(),
                            'title' => $schema->getTitle(),
                        ];
                    } catch (\Exception $e) {
                        // Schema not found, skip.
                    }
                }

                $resolvedRegisters = [];
                $registerIds       = $catalog['registers'] ?? [];
                if (is_string($registerIds) === true) {
                    $registerIds = json_decode($registerIds, true) ?? [];
                }

                foreach ($registerIds as $registerId) {
                    try {
                        $register                        = $registerMapper->find((int) $registerId);
                        $resolvedRegisters[$registerId] = [
                            'id'    => $register->getId(),
                            'slug'  => $register->getSlug(),
                            'title' => $register->getTitle(),
                        ];
                    } catch (\Exception $e) {
                        // Register not found, skip.
                    }
                }

                $result['@self']['schemas']   = $resolvedSchemas;
                $result['@self']['registers'] = $resolvedRegisters;
            } catch (\Exception $e) {
                // OpenRegister not available, skip enrichment.
            }//end try

            // Add CORS headers for public API access.
            $response = new JSONResponse($result, 200);
            $origin   = $this->request->server['HTTP_ORIGIN'] ?? '*';

            $response->addHeader('Access-Control-Allow-Origin', $origin);
            $response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
            $response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);

            return $response;
        } catch (\Exception $e) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Failed to retrieve publications').': '.$e->getMessage()],
                500
            );
        }//end try

    }//end index()

    /**
     * Retrieve a specific publication by its ID with catalog validation.
     *
     * This method bypasses ALL middleware and calls ObjectService directly for maximum performance.
     * Validates that the object belongs to the specified catalog's schemas and registers.
     *
     * @param string $catalogSlug The slug of the catalog
     * @param string $id          The ID of the publication to retrieve
     *
     * @return JSONResponse JSON response containing the requested publication
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function show(string $catalogSlug, string $id): JSONResponse
    {
        try {
            // Get the catalog from cache or database.
            $catalogData = $this->catalogiService->getCatalogBySlug($catalogSlug);

            if ($catalogData === null) {
                return new JSONResponse(
                    [
                        'error'       => $this->l10n->t('Catalog not found'),
                        'message'     => $this->l10n->t('The catalog "%s" does not exist.', [$catalogSlug]),
                        'catalogSlug' => $catalogSlug,
                    ],
                    404
                );
            }

            // Convert ObjectEntity to array if needed (cache may return array directly).
            $catalog = $catalogData;

            // Get ObjectService directly.
            $objectService = $this->getObjectService();

            // Get request parameters for extensions.
            $requestParams = $this->request->getParams();

            // Build extend parameters.
            $extend = ($requestParams['extend'] ?? $requestParams['_extend'] ?? []);
            // Normalize to array and handle comma-separated strings.
            if (is_string($extend) === true) {
                $extend = array_map('trim', explode(',', $extend));
            } else if (is_array($extend) === false) {
                $extend = [$extend];
            }

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
            $object  = null;
            $objects = [];

            // Fast path: try catalog register and schema combinations first.
            $catalogRegisters = $catalog['registers'] ?? [];
            $catalogSchemas   = $catalog['schemas'] ?? [];

            if (empty($catalogRegisters) === false && empty($catalogSchemas) === false) {
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

                        if (empty($objects) === false) {
                            $object = $objects[0];
                            break 2;
                        }
                    }
                }//end foreach
            }//end if

            // Fallback: find the object register and schema across all magic tables.
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

                    if (empty($objects) === false) {
                        $object = $objects[0];
                    }
                }
            }//end if

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
                        'error'       => $this->l10n->t('Publication not found'),
                        'message'     => $this->l10n->t(
                            'The publication with ID "%s" does not exist or is not accessible.',
                            [$id]
                        ),
                        'id'          => $id,
                        'catalogSlug' => $catalogSlug,
                    ],
                    404
                );
            }//end if

            $this->logger->debug(
                '[PublicationsController::show] Object found successfully',
                [
                    'id'       => $id,
                    'objectId' => $object->getId(),
                    'schema'   => $object->getSchema(),
                    'register' => $object->getRegister(),
                ]
            );

            // @todo: Catalog validation disabled for now.
            // Render the object with extensions.
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

            // Add CORS headers for public API access.
            $response = new JSONResponse($result, 200);
            $origin   = $this->request->server['HTTP_ORIGIN'] ?? '*';

            $response->addHeader('Access-Control-Allow-Origin', $origin);
            $response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
            $response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);

            return $response;
        } catch (DoesNotExistException $exception) {
            return new JSONResponse(
                [
                    'error'       => $this->l10n->t('Publication not found'),
                    'message'     => $this->l10n->t(
                        'The publication with ID "%s" does not exist in the database.',
                        [$id]
                    ),
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
                    'error'       => $this->l10n->t('Failed to retrieve publication'),
                    'message'     => $e->getMessage(),
                    'id'          => $id,
                    'catalogSlug' => $catalogSlug,
                    'hint'        => $this->l10n->t('Check server logs for more details.'),
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
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function attachments(string $catalogSlug, string $id): JSONResponse
    {
        try {
            // Get the catalog from cache or database.
            $catalogData = $this->catalogiService->getCatalogBySlug($catalogSlug);

            if ($catalogData === null) {
                return new JSONResponse(
                    [
                        'error'       => $this->l10n->t('Catalog not found'),
                        'message'     => $this->l10n->t('The catalog "%s" does not exist.', [$catalogSlug]),
                        'catalogSlug' => $catalogSlug,
                    ],
                    404
                );
            }

            // Convert ObjectEntity to array if needed.
            $catalog = $catalogData;

            // Extract register and schema from catalog for magic table support.
            $catalogRegisters = $catalog['registers'] ?? [];
            $catalogSchemas   = $catalog['schemas'] ?? [];
            // Parse JSON string if needed (catalog fields may be JSON-encoded).
            if (is_string($catalogRegisters) === true) {
                $catalogRegisters = json_decode($catalogRegisters, true) ?? [];
            }

            if (is_string($catalogSchemas) === true) {
                $catalogSchemas = json_decode($catalogSchemas, true) ?? [];
            }

            $register = null;
            if (empty($catalogRegisters) === false) {
                $register = (int) $catalogRegisters[0];
            }

            // First verify the object exists in this catalog register and schema.
            $objectService = $this->getObjectService();

            // For multi-schema catalogs, loop through all schemas to find the object.
            $object       = null;
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
                        'error'       => $this->l10n->t('Publication not found'),
                        'message'     => $this->l10n->t('The publication with ID "%s" does not exist.', [$id]),
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
                    'error'       => $this->l10n->t('Publication not found'),
                    'message'     => $this->l10n->t(
                        'The publication with ID "%s" does not exist in the database.',
                        [$id]
                    ),
                    'id'          => $id,
                    'catalogSlug' => $catalogSlug,
                ],
                404
            );
        } catch (\Exception $e) {
            return new JSONResponse(
                [
                    'error'   => $this->l10n->t('Failed to retrieve attachments'),
                    'message' => $e->getMessage(),
                ],
                500
            );
        }//end try

    }//end attachments()

    /**
     * Download a publication file.
     *
     * @param string $catalogSlug The slug of the catalog
     * @param string $id          Id of publication
     *
     * @return DataDownloadResponse|JSONResponse JSON response containing the requested attachments/files.
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function download(string $catalogSlug, string $id): DataDownloadResponse|JSONResponse
    {
        try {
            // Get the catalog from cache or database.
            $catalogData = $this->catalogiService->getCatalogBySlug($catalogSlug);

            if ($catalogData === null) {
                return new JSONResponse(
                    [
                        'error'       => $this->l10n->t('Catalog not found'),
                        'message'     => $this->l10n->t('The catalog "%s" does not exist.', [$catalogSlug]),
                        'catalogSlug' => $catalogSlug,
                    ],
                    404
                );
            }

            // Convert ObjectEntity to array if needed.
            $catalog = $catalogData;

            // Extract register and schema from catalog for magic table support.
            $catalogRegisters = $catalog['registers'] ?? [];
            $catalogSchemas   = $catalog['schemas'] ?? [];
            // Parse JSON string if needed (catalog fields may be JSON-encoded).
            if (is_string($catalogRegisters) === true) {
                $catalogRegisters = json_decode($catalogRegisters, true) ?? [];
            }

            if (is_string($catalogSchemas) === true) {
                $catalogSchemas = json_decode($catalogSchemas, true) ?? [];
            }

            $register = null;
            if (empty($catalogRegisters) === false) {
                $register = (int) $catalogRegisters[0];
            }

            // First verify the object exists in this catalog register and schema.
            $objectService = $this->getObjectService();

            // For multi-schema catalogs, loop through all schemas to find the object.
            $object       = null;
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
                        'error'       => $this->l10n->t('Publication not found'),
                        'message'     => $this->l10n->t('The publication with ID "%s" does not exist.', [$id]),
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
                    'error'       => $this->l10n->t('Publication not found'),
                    'message'     => $this->l10n->t(
                        'The publication with ID "%s" does not exist in the database.',
                        [$id]
                    ),
                    'id'          => $id,
                    'catalogSlug' => $catalogSlug,
                ],
                404
            );
        } catch (\Exception $e) {
            return new JSONResponse(
                [
                    'error'   => $this->l10n->t('Failed to download publication'),
                    'message' => $e->getMessage(),
                ],
                500
            );
        }//end try

    }//end download()

    /**
     * Retrieves all objects that this publication references (outgoing relations).
     *
     * Delegates directly to OpenRegister's ObjectService::getObjectUses() and trusts RBAC.
     *
     * @param string $catalogSlug The slug of the catalog (unused, kept for route compatibility)
     * @param string $id          The ID of the publication to retrieve relations for
     *
     * @return JSONResponse A JSON response containing the related objects
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) catalogSlug required by route pattern.
     */
    public function uses(string $catalogSlug, string $id): JSONResponse
    {
        try {
            $objectService = $this->getObjectService();

            // Set register/schema context so RelationHandler can find the object in magic tables.
            $location = $this->findObjectLocation($id);
            if ($location !== null) {
                $objectService->setRegister(register: (string) $location['register']);
                $objectService->setSchema(schema: (string) $location['schema']);
            }

            $queryParams = $this->request->getParams();
            unset($queryParams['id'], $queryParams['_route'], $queryParams['catalogSlug']);

            $result = $objectService->getObjectUses(
                objectId: $id,
                query: $queryParams,
                _rbac: true,
                _multitenancy: true
            );

            // Add CORS headers for public API access.
            $response = new JSONResponse($result, 200);
            $origin   = $this->request->server['HTTP_ORIGIN'] ?? '*';

            $response->addHeader('Access-Control-Allow-Origin', $origin);
            $response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
            $response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);

            return $response;
        } catch (\Exception $e) {
            $this->logger->error(
                '[PublicationsController::uses] Failed to retrieve publication uses',
                [
                    'id'    => $id,
                    'error' => $e->getMessage(),
                ]
            );
            return new JSONResponse(
                [
                    'error'   => $this->l10n->t('Failed to retrieve publication uses'),
                    'message' => $e->getMessage(),
                    'id'      => $id,
                ],
                500
            );
        }//end try

    }//end uses()

    /**
     * Retrieves all objects that use this publication (incoming relations).
     *
     * Delegates directly to OpenRegister's ObjectService::getObjectUsedBy() and trusts RBAC.
     *
     * @param string $catalogSlug The slug of the catalog (unused, kept for route compatibility)
     * @param string $id          The ID of the publication to retrieve uses for
     *
     * @return JSONResponse A JSON response containing the referenced objects
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) catalogSlug required by route pattern.
     */
    public function used(string $catalogSlug, string $id): JSONResponse
    {
        try {
            $objectService = $this->getObjectService();

            // Set register/schema context so RelationHandler can find the object in magic tables.
            $location = $this->findObjectLocation($id);
            if ($location !== null) {
                $objectService->setRegister(register: (string) $location['register']);
                $objectService->setSchema(schema: (string) $location['schema']);
            }

            $queryParams = $this->request->getParams();
            unset($queryParams['id'], $queryParams['_route'], $queryParams['catalogSlug']);

            $result = $objectService->getObjectUsedBy(
                objectId: $id,
                query: $queryParams,
                _rbac: true,
                _multitenancy: true
            );

            // Add CORS headers for public API access.
            $response = new JSONResponse($result, 200);
            $origin   = $this->request->server['HTTP_ORIGIN'] ?? '*';

            $response->addHeader('Access-Control-Allow-Origin', $origin);
            $response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
            $response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);

            return $response;
        } catch (\Exception $e) {
            $this->logger->error(
                '[PublicationsController::used] Failed to retrieve publication used',
                [
                    'id'    => $id,
                    'error' => $e->getMessage(),
                ]
            );
            return new JSONResponse(
                [
                    'error'   => $this->l10n->t('Failed to retrieve publication used'),
                    'message' => $e->getMessage(),
                    'id'      => $id,
                ],
                500
            );
        }//end try

    }//end used()

    /**
     * Recursively strips empty values (null, empty string, empty array) from an array.
     *
     * Used to reduce API response payload by omitting properties that have no value.
     * Values of 0, false, and "0" are preserved as they are meaningful.
     *
     * @param array $data The data array to strip empty values from.
     *
     * @return array The data with empty values removed.
     */
    private function stripEmptyValues(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            if (is_array($value) === true) {
                $isSequential = array_is_list($value);

                if ($isSequential === true) {
                    $stripped = [];
                    foreach ($value as $item) {
                        if (is_array($item) === true) {
                            $stripped[] = $this->stripEmptyValues(data: $item);
                        } else {
                            $stripped[] = $item;
                        }
                    }

                    if (empty($stripped) === false) {
                        $result[$key] = $stripped;
                    }

                    continue;
                }

                $stripped = $this->stripEmptyValues(data: $value);
                if (empty($stripped) === false) {
                    $result[$key] = $stripped;
                }

                continue;
            }//end if

            if ($value === null || $value === '') {
                continue;
            }

            $result[$key] = $value;
        }//end foreach

        return $result;
    }//end stripEmptyValues()


}//end class
