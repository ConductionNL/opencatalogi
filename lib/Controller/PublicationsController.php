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
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/retrofit-2026-05-25-annotate-opencatalogi/tasks.md#task-28
 * @spec openspec/changes/retrofit-2026-05-25-annotate-opencatalogi/tasks.md#task-29
 * @spec openspec/changes/retrofit-2026-05-25-annotate-opencatalogi/tasks.md#task-30
 * @spec openspec/changes/retrofit-2026-05-25-annotate-opencatalogi/tasks.md#task-31
 * @spec openspec/changes/retrofit-2026-05-25-annotate-opencatalogi/tasks.md#task-32
 * @spec openspec/changes/retrofit-2026-05-25-annotate-opencatalogi/tasks.md#task-33
 * @spec openspec/changes/retrofit-2026-05-25-annotate-opencatalogi/tasks.md#task-34
 * @spec openspec/changes/retrofit-2026-05-25-annotate-opencatalogi/tasks.md#task-35
 */

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\Service\PublicationService;
use OCA\OpenCatalogi\Service\CatalogiService;
use OCA\OpenCatalogi\Service\PublicationQueryService;
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
     * @param string                  $appName            The name of the app
     * @param IRequest                $request            The request object
     * @param PublicationService      $publicationService The publication service
     * @param CatalogiService         $catalogiService    The catalogi service
     * @param PublicationQueryService $queryService       Query-building/shaping helpers
     * @param ContainerInterface      $container          The container for dependency injection
     * @param IAppManager             $appManager         The app manager
     * @param LoggerInterface         $logger             PSR-3 logger
     * @param IL10N                   $l10n               Localization service
     * @param string                  $corsMethods        Allowed CORS methods
     * @param string                  $corsAllowedHeaders Allowed CORS headers
     * @param integer                 $corsMaxAge         CORS max age
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        $appName,
        IRequest $request,
        private readonly PublicationService $publicationService,
        private readonly CatalogiService $catalogiService,
        private readonly PublicationQueryService $queryService,
        private readonly ContainerInterface $container,
        private readonly IAppManager $appManager,
        private readonly LoggerInterface $logger,
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
     * Add standard CORS headers to a response.
     *
     * @param JSONResponse $response The response to add headers to.
     *
     * @return void
     */
    private function addCorsHeaders(JSONResponse $response): void
    {
        $origin = $this->request->server['HTTP_ORIGIN'] ?? '*';
        $response->addHeader('Access-Control-Allow-Origin', $origin);
        $response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
        $response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);

    }//end addCorsHeaders()

    /**
     * Implements a preflighted CORS response for OPTIONS requests.
     *
     * @return Response The CORS response
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     *
     * @spec openspec/changes/retrofit-2026-05-25-cross-origin-api-access/tasks.md#task-1
     */
    public function preflightedCors(): Response
    {
        // Determine the origin.
        $origin = $this->request->getHeader('Origin');
        if ($origin === '') {
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
     * @NoCSRFRequired
     * @PublicPage
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     *
     * @spec openspec/changes/retrofit-2026-05-25-annotate-opencatalogi/tasks.md#task-28
     */
    public function index(string $catalogSlug): JSONResponse
    {
        try {
            // Get the catalog from cache or database.
            $catalog = $this->catalogiService->getCatalogBySlug($catalogSlug);

            if ($catalog === null) {
                return new JSONResponse(['error' => $this->l10n->t('Catalog not found')], 404);
            }

            // Get ObjectService directly bypassing PublicationService overhead.
            $objectService = $this->getObjectService();

            // Build the catalog-scoped search query via the query service.
            $searchQuery = $this->queryService->buildCatalogSearchQuery(
                catalog: $catalog,
                queryParams: $this->request->getParams(),
                objectService: $objectService
            );

            // DIRECT ObjectService call with catalog filtering.
            // Set rbac=true to enable schema authorization.
            // Set multi=false for public access.
            $result = $objectService->searchObjectsPaginated(
                query: $searchQuery,
                _rbac: true,
                _multitenancy: false
            );

            // Enforce server-side published predicate for anonymous callers.
            // Authenticated callers keep RBAC-scoped behavior; anonymous callers only
            // see objects that are published (and not depublished). Anon-vs-auth is
            // derived from the server-side user session, never from a client param.
            $result = $this->queryService->enforcePublishedForAnonymous($result);

            // Strip empty values from results unless _empty=true is set.
            $includeEmpty = filter_var(
                value: $this->request->getParam(key: '_empty', default: false),
                filter: FILTER_VALIDATE_BOOLEAN
            );
            if ($includeEmpty === false && isset($result['results']) === true && is_array($result['results']) === true) {
                $result['results'] = array_map(
                    callback: function ($item) {
                        // Serialize ObjectEntity instances to arrays before stripping empty values.
                        if (is_array($item) === false
                            && method_exists(object_or_class: $item, method: 'jsonSerialize') === true
                        ) {
                            $item = $item->jsonSerialize();
                        }

                        if (is_array($item) === true) {
                            return $this->queryService->stripEmptyValues(data: $item);
                        }

                        return $item;
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
            $resolved                      = $this->queryService->resolveSchemaAndRegisterObjects($catalog);
            $result['@self']['schemas']   = $resolved['schemas'];
            $result['@self']['registers'] = $resolved['registers'];

            // Add CORS headers for public API access.
            $response = new JSONResponse($result, 200);
            $this->addCorsHeaders($response);

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
     * @NoCSRFRequired
     * @PublicPage
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     *
     * @spec openspec/changes/retrofit-2026-05-25-annotate-opencatalogi/tasks.md#task-29
     */
    public function show(string $catalogSlug, string $id): JSONResponse
    {
        try {
            // Get the catalog from cache or database.
            $catalog = $this->catalogiService->getCatalogBySlug($catalogSlug);

            if ($catalog === null) {
                return new JSONResponse(
                    [
                        'error'       => $this->l10n->t('Catalog not found'),
                        'message'     => $this->l10n->t('The catalog "%s" does not exist.', [$catalogSlug]),
                        'catalogSlug' => $catalogSlug,
                    ],
                    404
                );
            }

            // Get ObjectService directly.
            $objectService = $this->getObjectService();

            // Build extend parameters.
            $requestParams = $this->request->getParams();
            $extend        = ($requestParams['extend'] ?? $requestParams['_extend'] ?? []);
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
                            _rbac: true,
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
                $location = $this->queryService->findObjectLocation($id);

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
                        _rbac: true,
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

            // Enforce server-side published predicate for anonymous callers.
            // Anonymous callers may only retrieve published (and non-depublished)
            // objects; an unpublished object is reported as not found. Authenticated
            // callers keep RBAC-scoped behavior. Anon-vs-auth is derived server-side.
            if ($this->queryService->isAnonymous() === true
                && $this->queryService->isObjectPublic($object) === false
            ) {
                $this->logger->warning(
                    '[PublicationsController::show] Anonymous request for non-published object denied',
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

            // @todo: Catalog validation disabled for now.
            // Render the object with extensions.
            $result = $objectService->renderEntity(
                entity: $object,
                _extend: $extend,
                depth: 0,
                filter: [],
                fields: [],
                unset: [],
                _rbac: true,
                _multitenancy: false,
            );

            // Add CORS headers for public API access.
            $response = new JSONResponse($result, 200);
            $this->addCorsHeaders($response);

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
     * @NoCSRFRequired
     * @PublicPage
     *
     * @spec openspec/changes/retrofit-2026-05-25-annotate-opencatalogi/tasks.md#task-30
     */
    public function attachments(string $catalogSlug, string $id): JSONResponse
    {

        try {
            // Get the catalog from cache or database.
            $catalog = $this->catalogiService->getCatalogBySlug($catalogSlug);

            if ($catalog === null) {
                return new JSONResponse(
                    [
                        'error'       => $this->l10n->t('Catalog not found'),
                        'message'     => $this->l10n->t('The catalog "%s" does not exist.', [$catalogSlug]),
                        'catalogSlug' => $catalogSlug,
                    ],
                    404
                );
            }

            // First verify the object exists in this catalog register and schema.
            $objectService = $this->getObjectService();
            $object        = $this->queryService->findObjectInCatalog($catalog, $id, $objectService);

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
     * @NoCSRFRequired
     * @PublicPage
     *
     * @spec openspec/changes/retrofit-2026-05-25-annotate-opencatalogi/tasks.md#task-31
     */
    public function download(string $catalogSlug, string $id): DataDownloadResponse|JSONResponse
    {
        try {
            // Get the catalog from cache or database.
            $catalog = $this->catalogiService->getCatalogBySlug($catalogSlug);

            if ($catalog === null) {
                return new JSONResponse(
                    [
                        'error'       => $this->l10n->t('Catalog not found'),
                        'message'     => $this->l10n->t('The catalog "%s" does not exist.', [$catalogSlug]),
                        'catalogSlug' => $catalogSlug,
                    ],
                    404
                );
            }

            // First verify the object exists in this catalog register and schema.
            $objectService = $this->getObjectService();
            $object        = $this->queryService->findObjectInCatalog($catalog, $id, $objectService);

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
     * @NoCSRFRequired
     * @PublicPage
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) catalogSlug required by route pattern.
     *
     * @spec openspec/changes/retrofit-2026-05-25-annotate-opencatalogi/tasks.md#task-32
     */
    public function uses(string $catalogSlug, string $id): JSONResponse
    {
        try {
            $objectService = $this->getObjectService();

            // Set register/schema context so RelationHandler can find the object in magic tables.
            $location = $this->queryService->findObjectLocation($id);
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
            $this->addCorsHeaders($response);

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
     * @NoCSRFRequired
     * @PublicPage
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) catalogSlug required by route pattern.
     *
     * @spec openspec/changes/retrofit-2026-05-25-annotate-opencatalogi/tasks.md#task-33
     */
    public function used(string $catalogSlug, string $id): JSONResponse
    {
        try {
            $objectService = $this->getObjectService();

            // Set register/schema context so RelationHandler can find the object in magic tables.
            $location = $this->queryService->findObjectLocation($id);
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
            $this->addCorsHeaders($response);

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

}//end class
