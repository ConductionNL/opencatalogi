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
use OCP\IAppConfig;
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
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 */
class PublicationsController extends Controller
{

    /**
     * Maximum number of extend entries accepted on public read endpoints.
     *
     * Caps the breadth of relation traversal triggered by a single anonymous request
     * to prevent N+1 amplification (#732). Five is generous for legitimate '@self.'
     * extends while keeping per-request OR query count bounded.
     *
     * @var integer
     */
    private const MAX_PUBLIC_EXTEND = 5;

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
     * @param IAppConfig|null         $appConfig          App config for CORS allowlist (optional)
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
        private readonly ?IAppConfig $appConfig=null,
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
     * Resolve the Access-Control-Allow-Origin header value for the current request.
     *
     * Reads the configured allowlist from IAppConfig key 'cors_allowed_origins' (CSV).
     * Special value '*' (the default) means "any origin allowed" and emits a literal '*'
     * — the caller's Origin is NEVER echoed back unless it is explicitly listed in the
     * configured allowlist. This prevents arbitrary-origin reflection on public endpoints
     * (#735). When credentials are not allowed (as on these public read endpoints) a
     * static '*' is the safest default; only operators that need credentialed CORS
     * should configure a strict allowlist.
     *
     * @return string The header value to use for Access-Control-Allow-Origin.
     *
     * @spec exclude CORS-policy plumbing extracted to fail-closed on Origin reflection;
     *       reads an IAppConfig allowlist and never echoes an unvetted caller Origin.
     */
    private function resolveAllowedOrigin(): string
    {
        $configured = '*';
        if ($this->appConfig !== null) {
            $configured = $this->appConfig->getValueString($this->appName, 'cors_allowed_origins', '*');
        }

        $configured = trim($configured);
        if ($configured === '' || $configured === '*') {
            return '*';
        }

        $allowlist = array_filter(
            array_map('trim', explode(',', $configured)),
            static fn(string $entry): bool => $entry !== ''
        );

        $callerOrigin = $this->request->getHeader('Origin');
        if ($callerOrigin === '') {
            $callerOrigin = ($this->request->server['HTTP_ORIGIN'] ?? '');
        }

        if ($callerOrigin !== '' && in_array($callerOrigin, $allowlist, true) === true) {
            return $callerOrigin;
        }

        // Caller Origin not on the allowlist — fall back to the first configured entry
        // (or '*' if the allowlist became empty after trimming). Crucially, we do NOT
        // echo back the caller's unvetted Origin.
        return ($allowlist[0] ?? '*');

    }//end resolveAllowedOrigin()

    /**
     * Add standard CORS headers to a response.
     *
     * @param JSONResponse $response The response to add headers to.
     *
     * @return void
     */
    private function addCorsHeaders(JSONResponse $response): void
    {
        $response->addHeader('Access-Control-Allow-Origin', $this->resolveAllowedOrigin());
        $response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
        $response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);

    }//end addCorsHeaders()

    /**
     * Sanitise a public extend list to the '@self.'-prefix allowlist with a max breadth.
     *
     * Public read endpoints accept extend/_extend, but allowing arbitrary extend targets
     * lets an anonymous caller traverse to unrelated objects and amplify a single
     * request into many OR queries (#732). This filter restricts entries to those
     * beginning with '@self.' (mirroring PublicationService::show) and caps the list
     * at MAX_PUBLIC_EXTEND entries.
     *
     * @param array $extend The raw extend list from the request.
     *
     * @return array<string> The sanitised extend list (deduplicated, capped).
     *
     * @spec exclude Input-shaping plumbing extracted from PublicationsController::show;
     *       enforces the public extend allowlist and breadth cap, no domain behaviour.
     */
    private function sanitizePublicExtend(array $extend): array
    {
        $filtered = [];
        foreach ($extend as $entry) {
            if (is_string($entry) === false) {
                continue;
            }

            $trimmed = trim($entry);
            if ($trimmed === '' || str_starts_with($trimmed, '@self.') === false) {
                continue;
            }

            $filtered[$trimmed] = true;
        }

        $sanitised = array_keys($filtered);
        if (count($sanitised) > self::MAX_PUBLIC_EXTEND) {
            $sanitised = array_slice($sanitised, 0, self::MAX_PUBLIC_EXTEND);
        }

        return $sanitised;

    }//end sanitizePublicExtend()

    /**
     * Normalise a register/schema identifier list from a catalog field.
     *
     * Catalog 'registers' and 'schemas' may arrive as a native array or as a JSON
     * string. This helper resolves either shape to a list of integer IDs.
     *
     * @param array|string|null $raw The catalog field value.
     *
     * @return array<int> Integer ID list (empty when nothing usable).
     *
     * @spec exclude Catalog-shape normalisation plumbing used by the show fast path,
     *       fallback, and membership validation; no domain behaviour.
     */
    private function normaliseIdList(array | string | null $raw): array
    {
        if ($raw === null) {
            return [];
        }

        if (is_string($raw) === true) {
            $decoded = json_decode($raw, true);
            $raw     = [];
            if (is_array($decoded) === true) {
                $raw = $decoded;
            }
        }

        $ids = [];
        foreach ($raw as $value) {
            if (is_numeric($value) === true) {
                $ids[] = (int) $value;
            }
        }

        return $ids;

    }//end normaliseIdList()

    /**
     * Determine whether an object's register/schema belongs to the catalog scope.
     *
     * When either scope list is empty the object is treated as outside scope —
     * unscoped catalogs are not allowed to disclose individual objects (#733).
     *
     * @param object $object           ObjectEntity exposing getRegister()/getSchema().
     * @param array  $allowedRegisters Allowed register IDs from the catalog.
     * @param array  $allowedSchemas   Allowed schema IDs from the catalog.
     *
     * @return bool True when the object's register AND schema are both allowed.
     *
     * @spec exclude Catalog-membership predicate extracted from show; pure check, no
     *       domain behaviour.
     */
    private function objectMatchesCatalogScope(object $object, array $allowedRegisters, array $allowedSchemas): bool
    {
        if (empty($allowedRegisters) === true || empty($allowedSchemas) === true) {
            return false;
        }

        $objectRegister = (int) $object->getRegister();
        $objectSchema   = (int) $object->getSchema();

        return (in_array($objectRegister, $allowedRegisters, true) === true
            && in_array($objectSchema, $allowedSchemas, true) === true);

    }//end objectMatchesCatalogScope()

    /**
     * Implements a preflighted CORS response for OPTIONS requests.
     *
     * @return Response The CORS response
     *
     * @NoCSRFRequired
     * @PublicPage
     *
     * @spec openspec/changes/retrofit-2026-05-25-cross-origin-api-access/tasks.md#task-1
     */
    public function preflightedCors(): Response
    {
        // Determine the origin via the same allowlist-aware resolver used elsewhere
        // so we never reflect an arbitrary caller-supplied Origin (#735).
        $response = new Response();
        $response->addHeader('Access-Control-Allow-Origin', $this->resolveAllowedOrigin());
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

            // Sanitise extend before passing to the query builder so that anonymous
            // callers cannot traverse to unrelated objects via arbitrary extend targets.
            $requestParams = $this->request->getParams();
            if (empty($requestParams['extend']) === false || empty($requestParams['_extend']) === false) {
                $rawExtend = ($requestParams['extend'] ?? $requestParams['_extend'] ?? []);
                if (is_string($rawExtend) === true) {
                    $rawExtend = array_map('trim', explode(',', $rawExtend));
                } else if (is_array($rawExtend) === false) {
                    $rawExtend = [$rawExtend];
                }

                $safeExtend = $this->sanitizePublicExtend($rawExtend);
                unset($requestParams['extend'], $requestParams['_extend']);
                $requestParams['_extend'] = $safeExtend;
            }

            // Build the catalog-scoped search query via the query service.
            $searchQuery = $this->queryService->buildCatalogSearchQuery(
                catalog: $catalog,
                queryParams: $requestParams,
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

            // Visibility is governed by OpenRegister RBAC: the publication schema grants the
            // public group read only when publicatiedatum <= $now, applied by the search
            // above (_rbac: true). No extra published filtering — anonymous callers see only
            // live publications, authenticated callers see per their group rights.
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
            $resolved = $this->queryService->resolveSchemaAndRegisterObjects($catalog);
            $result['@self']['schemas']   = $resolved['schemas'];
            $result['@self']['registers'] = $resolved['registers'];

            // Add CORS headers for public API access.
            $response = new JSONResponse($result, 200);
            $this->addCorsHeaders($response);

            return $response;
        } catch (\Exception $e) {
            // Public endpoint — log exception details server-side only and return a
            // generic error body to the caller (#735); never leak raw $e->getMessage().
            $this->logger->error(
                '[PublicationsController::index] Failed to retrieve publications',
                [
                    'catalogSlug' => $catalogSlug,
                    'error'       => $e->getMessage(),
                    'trace'       => $e->getTraceAsString(),
                ]
            );
            return new JSONResponse(
                ['error' => $this->l10n->t('Internal server error')],
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

            // Build extend parameters with controller-layer allowlist + bounds (#732).
            // Public extends are restricted to '@self.'-prefixed paths (mirrors
            // PublicationService::show()) AND a max of 5 entries to prevent N+1
            // amplification through unbounded extends on anonymous endpoints.
            $requestParams = $this->request->getParams();
            $extend        = ($requestParams['extend'] ?? $requestParams['_extend'] ?? []);
            // Normalize to array and handle comma-separated strings.
            if (is_string($extend) === true) {
                $extend = array_map('trim', explode(',', $extend));
            } else if (is_array($extend) === false) {
                $extend = [$extend];
            }

            $extend = $this->sanitizePublicExtend($extend);

            // Resolve catalog scope (parse JSON strings if needed).
            $catalogRegisters = $this->normaliseIdList($catalog['registers'] ?? []);
            $catalogSchemas   = $this->normaliseIdList($catalog['schemas'] ?? []);

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

            // Fallback: locate the object's register/schema, but constrained to the
            // catalog's configured registers/schemas only (#734). Without this scope
            // findObjectLocation would scan every magic table on the platform and would
            // return objects outside this catalog's namespace (#733). When the catalog
            // has no configured scope (e.g. unscoped catalog), the fallback is skipped
            // entirely — there is no safe namespace to search.
            if ($object === null
                && empty($catalogRegisters) === false
                && empty($catalogSchemas) === false
            ) {
                $location = $this->queryService->findObjectLocation(
                    uuid: $id,
                    allowedRegisters: $catalogRegisters,
                    allowedSchemas: $catalogSchemas
                );

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

            // Visibility is governed by OpenRegister RBAC: the searches above run with
            // _rbac: true, so an anonymous caller never receives an unpublished publication
            // (the publication schema grants public read only when publicatiedatum <= $now) —
            // such a request resolves to $object === null and 404s above. No extra check needed.
            // Catalog-membership validation (#733): the resolved object's
            // register/schema MUST belong to this catalog's configured scope. Without
            // this check `/api/{anyCatalogSlug}/{anyUuid}` would return objects from
            // wholly unrelated catalogs, enabling cross-catalog enumeration. When the
            // catalog has no configured scope we treat the object as not found —
            // unscoped catalogs cannot disclose individual objects.
            if ($this->objectMatchesCatalogScope(
                object: $object,
                allowedRegisters: $catalogRegisters,
                allowedSchemas: $catalogSchemas
            ) === false
            ) {
                $this->logger->warning(
                    '[PublicationsController::show] Object outside catalog scope',
                    [
                        'id'             => $id,
                        'catalogSlug'    => $catalogSlug,
                        'objectRegister' => $object->getRegister(),
                        'objectSchema'   => $object->getSchema(),
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

            // Render the object with the sanitised extend list. The '@self.'-prefix
            // allowlist plus max-entries cap has already been applied above (#732).
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
            // Public endpoint — log exception details server-side only and return a
            // generic error body to the caller (#735); never leak raw $e->getMessage().
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
                    'error' => $this->l10n->t('Internal server error'),
                    'hint'  => $this->l10n->t('Check server logs for more details.'),
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
            $object        = $this->queryService->findObjectInCatalog(
                catalog: $catalog,
                id: $id,
                objectService: $objectService
            );

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
            // Public endpoint — log details server-side, return generic body (#735).
            $this->logger->error(
                '[PublicationsController::attachments] Failed to retrieve attachments',
                [
                    'id'          => $id,
                    'catalogSlug' => $catalogSlug,
                    'error'       => $e->getMessage(),
                    'trace'       => $e->getTraceAsString(),
                ]
            );
            return new JSONResponse(
                ['error' => $this->l10n->t('Internal server error')],
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
            $object        = $this->queryService->findObjectInCatalog(
                catalog: $catalog,
                id: $id,
                objectService: $objectService
            );

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
            // Public endpoint — log details server-side, return generic body (#735).
            $this->logger->error(
                '[PublicationsController::download] Failed to download publication',
                [
                    'id'          => $id,
                    'catalogSlug' => $catalogSlug,
                    'error'       => $e->getMessage(),
                    'trace'       => $e->getTraceAsString(),
                ]
            );
            return new JSONResponse(
                ['error' => $this->l10n->t('Internal server error')],
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

            // Set register/schema context so RelationHandler can find the object in
            // magic tables. Constrain the lookup to this catalog's configured scope
            // (#734) — a platform-wide scan would reveal arbitrary cross-catalog
            // objects (#733). When the catalog has no configured scope, skip the
            // context hint entirely.
            $catalog          = $this->catalogiService->getCatalogBySlug($catalogSlug);
            $catalogRegisters = $this->normaliseIdList(($catalog['registers'] ?? []));
            $catalogSchemas   = $this->normaliseIdList(($catalog['schemas'] ?? []));
            if (empty($catalogRegisters) === false && empty($catalogSchemas) === false) {
                $location = $this->queryService->findObjectLocation(
                    uuid: $id,
                    allowedRegisters: $catalogRegisters,
                    allowedSchemas: $catalogSchemas
                );
                if ($location !== null) {
                    $objectService->setRegister(register: (string) $location['register']);
                    $objectService->setSchema(schema: (string) $location['schema']);
                }
            }

            // Published-predicate guard (WF2 / wave-12). Mirrors the guard applied to the
            // federation path (PublicationService::uses, wave-9 C-3) that was missing from
            // this older per-catalog route. An anonymous caller must not be able to enumerate
            // the relation graph of an unpublished object by guessing its UUID.
            // Return 404 (not 401) to avoid leaking object existence.
            $rootObject = $objectService->find(id: $id, _extend: []);
            if ($rootObject === null) {
                return new JSONResponse(['error' => $this->l10n->t('Not Found')], 404);
            }

            if (is_array($rootObject) === true) {
                $rootObjectArray = $rootObject;
            } else {
                $rootObjectArray = $rootObject->jsonSerialize();
            }

            if ($this->queryService->isAnonymous() === true
                && $this->queryService->isObjectPublic($rootObjectArray) === false
            ) {
                return new JSONResponse(['error' => $this->l10n->t('Not Found')], 404);
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
            // Public endpoint — return a generic error body to the caller (#735).
            return new JSONResponse(
                ['error' => $this->l10n->t('Internal server error')],
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

            // Set register/schema context so RelationHandler can find the object in
            // magic tables. Constrain the lookup to this catalog's configured scope
            // (#734) — a platform-wide scan would reveal arbitrary cross-catalog
            // objects (#733). When the catalog has no configured scope, skip the
            // context hint entirely.
            $catalog          = $this->catalogiService->getCatalogBySlug($catalogSlug);
            $catalogRegisters = $this->normaliseIdList(($catalog['registers'] ?? []));
            $catalogSchemas   = $this->normaliseIdList(($catalog['schemas'] ?? []));
            if (empty($catalogRegisters) === false && empty($catalogSchemas) === false) {
                $location = $this->queryService->findObjectLocation(
                    uuid: $id,
                    allowedRegisters: $catalogRegisters,
                    allowedSchemas: $catalogSchemas
                );
                if ($location !== null) {
                    $objectService->setRegister(register: (string) $location['register']);
                    $objectService->setSchema(schema: (string) $location['schema']);
                }
            }

            // Published-predicate guard (WF2 / wave-12). Mirrors the guard applied to the
            // federation path (PublicationService::used, wave-9 C-3) that was missing from
            // this older per-catalog route. An anonymous caller must not be able to enumerate
            // the incoming-relation graph of an unpublished object by guessing its UUID.
            // Return 404 (not 401) to avoid leaking object existence.
            $rootObject = $objectService->find(id: $id, _extend: []);
            if ($rootObject === null) {
                return new JSONResponse(['error' => $this->l10n->t('Not Found')], 404);
            }

            if (is_array($rootObject) === true) {
                $rootObjectArray = $rootObject;
            } else {
                $rootObjectArray = $rootObject->jsonSerialize();
            }

            if ($this->queryService->isAnonymous() === true
                && $this->queryService->isObjectPublic($rootObjectArray) === false
            ) {
                return new JSONResponse(['error' => $this->l10n->t('Not Found')], 404);
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
            // Public endpoint — return a generic error body to the caller (#735).
            return new JSONResponse(
                ['error' => $this->l10n->t('Internal server error')],
                500
            );
        }//end try

    }//end used()
}//end class
