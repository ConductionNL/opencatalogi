<?php
/**
 * OpenCatalogi OOAPI 5.0 catalog-publication controller.
 *
 * Consumer-credential authenticated, CORS-enabled OOAPI 5.0 resource
 * endpoints over the materialized course/program/offering objects and the
 * live-rendered Organisation object. A thin rendering layer (hydra ADR-022):
 * all selection, mapping and pagination live in OoapiService/
 * OoapiMappingService. Unlike DcatController/SchemaOrgController, these
 * routes are NOT anonymous-public (OOAPI-008) — every action requires an
 * authenticated Nextcloud user, optionally narrowed by the `ooapi_consumers`
 * allowlist. CORS headers are still emitted on every response (COR-001) —
 * being non-anonymous does not exempt the endpoint from cross-origin-api-access.
 *
 * @category Controller
 * @package  OCA\OpenCatalogi\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/ooapi-catalog-publication/tasks.md#task-3-public-endpoints-serializer
 * @spec openspec/changes/ooapi-catalog-publication/tasks.md#task-4-pagination-consumer-credential-auth
 */

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\Service\CatalogiService;
use OCA\OpenCatalogi\Service\OoapiService;
use OCA\OpenCatalogi\Settings\OpenCatalogiAdmin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Controller for the consumer-credential authenticated OOAPI 5.0 surface.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 *
 * @spec openspec/changes/ooapi-catalog-publication/tasks.md#task-3-public-endpoints-serializer
 */
class OoapiController extends Controller
{
    use ResolvesRegisterConfiguration;

    /**
     * OoapiController constructor.
     *
     * @param string              $appName         The app name.
     * @param IRequest            $request         The request object.
     * @param OoapiService        $ooapiService    The OOAPI 5.0 orchestration service.
     * @param CatalogiService     $catalogiService Catalog resolution (slug → catalog).
     * @param IUserSession        $userSession     Nextcloud user session (OOAPI-008 auth gate).
     * @param IL10N               $l10n            Localization service.
     * @param LoggerInterface     $logger          PSR-3 logger.
     * @param ContainerInterface  $container       Server container (ResolvesRegisterConfiguration).
     * @param IAppConfig|null     $appConfig       App config for the CORS allowlist.
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        $appName,
        IRequest $request,
        private readonly OoapiService $ooapiService,
        private readonly CatalogiService $catalogiService,
        private readonly IUserSession $userSession,
        private readonly IL10N $l10n,
        private readonly LoggerInterface $logger,
        private readonly ContainerInterface $container,
        private readonly ?IAppConfig $appConfig=null,
    ) {
        parent::__construct($appName, $request);

    }//end __construct()

    /**
     * Resolve the Access-Control-Allow-Origin header value for the current request.
     *
     * Reads the configured allowlist from IAppConfig key 'cors_allowed_origins' (CSV).
     * '*' (the default) emits a literal '*'; an unvetted caller Origin is never echoed
     * back unless explicitly allowlisted — mirrors DcatController/PublicationsController.
     *
     * @return string The Access-Control-Allow-Origin value.
     */
    private function resolveAllowedOrigin(): string
    {
        $configured = '*';
        if ($this->appConfig !== null) {
            $configured = trim($this->appConfig->getValueString($this->appName, 'cors_allowed_origins', '*'));
        }

        if ($configured === '' || $configured === '*') {
            return '*';
        }

        $allowlist = array_values(
            array_filter(
                array_map('trim', explode(',', $configured)),
                static fn(string $entry): bool => $entry !== ''
            )
        );

        $callerOrigin = $this->request->getHeader('Origin');
        if ($callerOrigin !== '' && in_array($callerOrigin, $allowlist, true) === true) {
            return $callerOrigin;
        }

        return ($allowlist[0] ?? '*');

    }//end resolveAllowedOrigin()

    /**
     * Build the standard CORS header set for OOAPI responses.
     *
     * @return array<string, string> The header map.
     *
     * @spec openspec/changes/ooapi-catalog-publication/specs/ooapi-catalog-publication/spec.md#requirement-per-catalog-ooapi-5-0-resource-endpoints-ooapi-001
     */
    private function corsHeaders(): array
    {
        return [
            'Access-Control-Allow-Origin'  => $this->resolveAllowedOrigin(),
            'Access-Control-Allow-Methods' => 'GET, OPTIONS',
            'Access-Control-Allow-Headers' => 'Authorization, Content-Type, Accept',
        ];

    }//end corsHeaders()

    /**
     * Build a JSON response carrying the standard OOAPI CORS headers (OOAPI-001).
     *
     * @param mixed   $data   The response body.
     * @param integer $status The HTTP status code.
     *
     * @return JSONResponse The CORS-headed JSON response.
     */
    private function json(mixed $data, int $status): JSONResponse
    {
        $response = new JSONResponse($data, $status);
        foreach ($this->corsHeaders() as $name => $value) {
            $response->addHeader($name, $value);
        }

        return $response;

    }//end json()

    /**
     * Preflighted CORS response for the OOAPI OPTIONS routes.
     *
     * Per `cross-origin-api-access` (COR-001) every public controller answers
     * preflight requests; OOAPI's actual GET routes remain authenticated
     * (OOAPI-008) — the preflight itself carries no resource data.
     *
     * @return Response The CORS preflight response.
     *
     * @NoCSRFRequired
     * @PublicPage
     *
     * @spec openspec/changes/ooapi-catalog-publication/specs/ooapi-catalog-publication/spec.md#requirement-per-catalog-ooapi-5-0-resource-endpoints-ooapi-001
     */
    public function preflightedCors(): Response
    {
        $response = new Response();
        foreach ($this->corsHeaders() as $name => $value) {
            $response->addHeader($name, $value);
        }

        $response->addHeader('Access-Control-Max-Age', '1728000');
        $response->addHeader('Access-Control-Allow-Credentials', 'false');
        return $response;

    }//end preflightedCors()

    /**
     * Enforce the OOAPI-008 consumer-credential auth gate.
     *
     * @return JSONResponse|null A 401/403 error response, or null when the caller is allowed to proceed.
     *
     * @spec openspec/changes/ooapi-catalog-publication/specs/ooapi-catalog-publication/spec.md#requirement-consumer-credential-authenticated-access-ooapi-008
     */
    private function requireAuthenticatedConsumer(): ?JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return $this->json(['error' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
        }

        if ($this->ooapiService->isConsumerAllowed($user->getUID()) === false) {
            return $this->json(['error' => $this->l10n->t('This account is not an authorized OOAPI consumer')], Http::STATUS_FORBIDDEN);
        }

        return null;

    }//end requireAuthenticatedConsumer()

    /**
     * Resolve an OOAPI-enabled catalog by slug, or a ready-to-return 404 response.
     *
     * @param string $catalogSlug The catalog slug.
     *
     * @return array{catalog: array<string, mixed>|null, error: JSONResponse|null} The resolved catalog, or an error response.
     *
     * @spec openspec/changes/ooapi-catalog-publication/specs/ooapi-catalog-publication/spec.md#requirement-per-catalog-ooapi-5-0-resource-endpoints-ooapi-001
     */
    private function resolveEnabledCatalog(string $catalogSlug): array
    {
        $catalog = $this->catalogiService->getCatalogBySlug($catalogSlug);
        if ($catalog === null) {
            return ['catalog' => null, 'error' => $this->json(['error' => $this->l10n->t('Catalog not found')], Http::STATUS_NOT_FOUND)];
        }

        if ($this->ooapiService->isOoapiEnabled($catalog) === false) {
            return ['catalog' => null, 'error' => $this->json(['error' => $this->l10n->t('OOAPI 5.0 publication is not enabled for this catalog')], Http::STATUS_NOT_FOUND)];
        }

        return ['catalog' => $catalog, 'error' => null];

    }//end resolveEnabledCatalog()

    /**
     * Resolve a `<context>_register`/`<context>_schema` pair, or a ready-to-return 503.
     *
     * @param string $registerKey The `<context>_register` config key.
     * @param string $schemaKey   The `<context>_schema` config key.
     *
     * @return array{pair: array{register: string, schema: string}|null, error: JSONResponse|null} The resolved pair, or an error response.
     *
     * @spec openspec/changes/ooapi-catalog-publication/specs/ooapi-catalog-publication/spec.md#requirement-admin-configuration-for-ooapi-publication-ooapi-010
     */
    private function resolveContext(string $registerKey, string $schemaKey): array
    {
        try {
            return ['pair' => $this->resolveRegisterConfiguration($registerKey, $schemaKey), 'error' => null];
        } catch (\Throwable $e) {
            return ['pair' => null, 'error' => $this->registerConfigErrorResponse($e)];
        }

    }//end resolveContext()

    /**
     * Resolve `pageNumber`/`pageSize` query parameters (OOAPI-007).
     *
     * @return array{page: int, pageSize: int} The resolved pagination parameters.
     */
    private function resolvePagination(): array
    {
        return [
            'page'     => max(1, (int) $this->request->getParam('pageNumber', 1)),
            'pageSize' => max(1, (int) $this->request->getParam('pageSize', OoapiService::DEFAULT_PAGE_SIZE)),
        ];

    }//end resolvePagination()

    /**
     * Wrap a paginated collection result in the OOAPI 5.0 list envelope.
     *
     * @param array{items: array<int, array<string, mixed>>, pageNumber: int, pageSize: int, hasNext: bool} $result The service result.
     *
     * @return JSONResponse The envelope response.
     *
     * @spec openspec/changes/ooapi-catalog-publication/specs/ooapi-catalog-publication/spec.md#requirement-ooapi-5-0-pagination-ooapi-007
     */
    private function collectionResponse(array $result): JSONResponse
    {
        return $this->json(
            [
                'pageNumber' => $result['pageNumber'],
                'pageSize'   => $result['pageSize'],
                'hasNext'    => $result['hasNext'],
                'items'      => $result['items'],
            ],
            Http::STATUS_OK
        );

    }//end collectionResponse()

    /**
     * Run an action body, converting any unexpected `\Throwable` into a logged
     * 500 response instead of letting it bubble up as an unhandled framework
     * error — mirrors DcatController's per-action try/catch. Expected control
     * flow (catalog/resource not found, register misconfigured) is signalled
     * by the callable returning a JSONResponse directly, not by throwing.
     *
     * @param callable():JSONResponse $action The action body.
     *
     * @return JSONResponse The action's result, or a 500 on unexpected failure.
     */
    private function guard(callable $action): JSONResponse
    {
        try {
            return $action();
        } catch (\Throwable $e) {
            $this->logger->error('[OoapiController] Unhandled error', ['error' => $e->getMessage()]);
            return $this->json(['error' => $this->l10n->t('Internal server error')], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

    }//end guard()

    /**
     * List an institution's OOAPI 5.0 organizations for a catalog.
     *
     * @param string $catalogSlug The catalog slug.
     *
     * @return JSONResponse The organization list (0 or 1 entries — the catalog's own owning Organisation, OOAPI-002).
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/ooapi-catalog-publication/specs/ooapi-catalog-publication/spec.md#requirement-organization-resource-renders-the-existing-organisation-object-live-ooapi-002
     */
    public function organizations(string $catalogSlug): JSONResponse
    {
        $authError = $this->requireAuthenticatedConsumer();
        if ($authError !== null) {
            return $authError;
        }

        return $this->guard(function () use ($catalogSlug): JSONResponse {
            $resolved = $this->resolveEnabledCatalog($catalogSlug);
            if ($resolved['error'] !== null) {
                return $resolved['error'];
            }

            $context = $this->resolveContext('organization_register', 'organization_schema');
            if ($context['error'] !== null) {
                return $context['error'];
            }

            $organization = $this->ooapiService->renderOrganization($resolved['catalog'], $context['pair']['register'], $context['pair']['schema']);
            $items        = ($organization === null) ? [] : [$organization];

            return $this->json(['pageNumber' => 1, 'pageSize' => count($items), 'hasNext' => false, 'items' => $items], Http::STATUS_OK);
        });

    }//end organizations()

    /**
     * Fetch a single OOAPI 5.0 organization resource.
     *
     * @param string $catalogSlug The catalog slug.
     * @param string $id          The organization id.
     *
     * @return JSONResponse The organization resource, or 404.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/ooapi-catalog-publication/specs/ooapi-catalog-publication/spec.md#requirement-organization-resource-renders-the-existing-organisation-object-live-ooapi-002
     */
    public function organization(string $catalogSlug, string $id): JSONResponse
    {
        $authError = $this->requireAuthenticatedConsumer();
        if ($authError !== null) {
            return $authError;
        }

        return $this->guard(function () use ($catalogSlug, $id): JSONResponse {
            $resolved = $this->resolveEnabledCatalog($catalogSlug);
            if ($resolved['error'] !== null) {
                return $resolved['error'];
            }

            $context = $this->resolveContext('organization_register', 'organization_schema');
            if ($context['error'] !== null) {
                return $context['error'];
            }

            $organization = $this->ooapiService->organizationById($resolved['catalog'], $id, $context['pair']['register'], $context['pair']['schema']);
            if ($organization === null) {
                return $this->json(['error' => $this->l10n->t('Organization not found')], Http::STATUS_NOT_FOUND);
            }

            return $this->json($organization, Http::STATUS_OK);
        });

    }//end organization()

    /**
     * List an OOAPI-enabled catalog's materialized `course` resources (OOAPI-001/OOAPI-003/OOAPI-007).
     *
     * @param string $catalogSlug The catalog slug.
     *
     * @return JSONResponse The paginated course list.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/ooapi-catalog-publication/specs/ooapi-catalog-publication/spec.md#requirement-per-catalog-ooapi-5-0-resource-endpoints-ooapi-001
     */
    public function courses(string $catalogSlug): JSONResponse
    {
        $authError = $this->requireAuthenticatedConsumer();
        if ($authError !== null) {
            return $authError;
        }

        return $this->guard(function () use ($catalogSlug): JSONResponse {
            $resolved = $this->resolveEnabledCatalog($catalogSlug);
            if ($resolved['error'] !== null) {
                return $resolved['error'];
            }

            $context = $this->resolveContext('ooapi_courses_register', 'ooapi_courses_schema');
            if ($context['error'] !== null) {
                return $context['error'];
            }

            $pagination = $this->resolvePagination();
            $result     = $this->ooapiService->listCourses($resolved['catalog'], $context['pair']['register'], $context['pair']['schema'], $pagination['page'], $pagination['pageSize']);

            return $this->collectionResponse($result);
        });

    }//end courses()

    /**
     * Fetch a single materialized `course` resource.
     *
     * @param string $catalogSlug The catalog slug.
     * @param string $id          The course id.
     *
     * @return JSONResponse The course resource, or 404.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/ooapi-catalog-publication/specs/ooapi-catalog-publication/spec.md#requirement-course-program-offering-resources-are-materialized-not-rendered-live-from-scholiq-ooapi-003
     */
    public function course(string $catalogSlug, string $id): JSONResponse
    {
        $authError = $this->requireAuthenticatedConsumer();
        if ($authError !== null) {
            return $authError;
        }

        return $this->guard(function () use ($catalogSlug, $id): JSONResponse {
            $resolved = $this->resolveEnabledCatalog($catalogSlug);
            if ($resolved['error'] !== null) {
                return $resolved['error'];
            }

            $context = $this->resolveContext('ooapi_courses_register', 'ooapi_courses_schema');
            if ($context['error'] !== null) {
                return $context['error'];
            }

            $course = $this->ooapiService->getResource($resolved['catalog'], $context['pair']['register'], $context['pair']['schema'], $id, 'courseId');
            if ($course === null) {
                return $this->json(['error' => $this->l10n->t('Course not found')], Http::STATUS_NOT_FOUND);
            }

            return $this->json($course, Http::STATUS_OK);
        });

    }//end course()

    /**
     * List an OOAPI-enabled catalog's materialized `program` resources.
     *
     * @param string $catalogSlug The catalog slug.
     *
     * @return JSONResponse The paginated program list.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/ooapi-catalog-publication/specs/ooapi-catalog-publication/spec.md#requirement-per-catalog-ooapi-5-0-resource-endpoints-ooapi-001
     */
    public function programs(string $catalogSlug): JSONResponse
    {
        $authError = $this->requireAuthenticatedConsumer();
        if ($authError !== null) {
            return $authError;
        }

        return $this->guard(function () use ($catalogSlug): JSONResponse {
            $resolved = $this->resolveEnabledCatalog($catalogSlug);
            if ($resolved['error'] !== null) {
                return $resolved['error'];
            }

            $context = $this->resolveContext('ooapi_programs_register', 'ooapi_programs_schema');
            if ($context['error'] !== null) {
                return $context['error'];
            }

            $pagination = $this->resolvePagination();
            $result     = $this->ooapiService->listPrograms($resolved['catalog'], $context['pair']['register'], $context['pair']['schema'], $pagination['page'], $pagination['pageSize']);

            return $this->collectionResponse($result);
        });

    }//end programs()

    /**
     * Fetch a single materialized `program` resource.
     *
     * @param string $catalogSlug The catalog slug.
     * @param string $id          The program id.
     *
     * @return JSONResponse The program resource, or 404.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/ooapi-catalog-publication/specs/ooapi-catalog-publication/spec.md#requirement-course-program-offering-resources-are-materialized-not-rendered-live-from-scholiq-ooapi-003
     */
    public function program(string $catalogSlug, string $id): JSONResponse
    {
        $authError = $this->requireAuthenticatedConsumer();
        if ($authError !== null) {
            return $authError;
        }

        return $this->guard(function () use ($catalogSlug, $id): JSONResponse {
            $resolved = $this->resolveEnabledCatalog($catalogSlug);
            if ($resolved['error'] !== null) {
                return $resolved['error'];
            }

            $context = $this->resolveContext('ooapi_programs_register', 'ooapi_programs_schema');
            if ($context['error'] !== null) {
                return $context['error'];
            }

            $program = $this->ooapiService->getResource($resolved['catalog'], $context['pair']['register'], $context['pair']['schema'], $id, 'programId');
            if ($program === null) {
                return $this->json(['error' => $this->l10n->t('Program not found')], Http::STATUS_NOT_FOUND);
            }

            return $this->json($program, Http::STATUS_OK);
        });

    }//end program()

    /**
     * List a course's materialized `offering` resources (OOAPI-006).
     *
     * @param string $catalogSlug The catalog slug.
     * @param string $courseId    The parent course id.
     *
     * @return JSONResponse The paginated offering list, filtered to the given course.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/ooapi-catalog-publication/specs/ooapi-catalog-publication/spec.md#requirement-offerings-nest-under-their-course-ooapi-006
     */
    public function courseOfferings(string $catalogSlug, string $courseId): JSONResponse
    {
        $authError = $this->requireAuthenticatedConsumer();
        if ($authError !== null) {
            return $authError;
        }

        return $this->guard(function () use ($catalogSlug, $courseId): JSONResponse {
            $resolved = $this->resolveEnabledCatalog($catalogSlug);
            if ($resolved['error'] !== null) {
                return $resolved['error'];
            }

            $context = $this->resolveContext('ooapi_offerings_register', 'ooapi_offerings_schema');
            if ($context['error'] !== null) {
                return $context['error'];
            }

            $pagination = $this->resolvePagination();
            $result     = $this->ooapiService->listOfferings($resolved['catalog'], $context['pair']['register'], $context['pair']['schema'], $courseId, $pagination['page'], $pagination['pageSize']);

            return $this->collectionResponse($result);
        });

    }//end courseOfferings()

    /**
     * Fetch a single materialized `offering` resource.
     *
     * @param string $catalogSlug The catalog slug.
     * @param string $id          The offering id.
     *
     * @return JSONResponse The offering resource, or 404.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/ooapi-catalog-publication/specs/ooapi-catalog-publication/spec.md#requirement-offerings-nest-under-their-course-ooapi-006
     */
    public function offering(string $catalogSlug, string $id): JSONResponse
    {
        $authError = $this->requireAuthenticatedConsumer();
        if ($authError !== null) {
            return $authError;
        }

        return $this->guard(function () use ($catalogSlug, $id): JSONResponse {
            $resolved = $this->resolveEnabledCatalog($catalogSlug);
            if ($resolved['error'] !== null) {
                return $resolved['error'];
            }

            $context = $this->resolveContext('ooapi_offerings_register', 'ooapi_offerings_schema');
            if ($context['error'] !== null) {
                return $context['error'];
            }

            $offering = $this->ooapiService->getResource($resolved['catalog'], $context['pair']['register'], $context['pair']['schema'], $id, 'offeringId');
            if ($offering === null) {
                return $this->json(['error' => $this->l10n->t('Offering not found')], Http::STATUS_NOT_FOUND);
            }

            return $this->json($offering, Http::STATUS_OK);
        });

    }//end offering()

    /**
     * Validate a catalog's OOAPI feed against the mandatory-property checklist.
     *
     * Admin-only (no @NoAdminRequired → NC SecurityMiddleware default enforces the
     * admin gate). Advisory only — never alters serving (OOAPI-010).
     *
     * @param string $catalogSlug The catalog slug.
     *
     * @return JSONResponse The list of violations (empty when the feed is compliant).
     *
     * @NoCSRFRequired
     *
     * @spec openspec/changes/ooapi-catalog-publication/specs/ooapi-catalog-publication/spec.md#requirement-admin-configuration-for-ooapi-publication-ooapi-010
     */
    #[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
    public function validate(string $catalogSlug): JSONResponse
    {
        return $this->guard(function () use ($catalogSlug): JSONResponse {
            $catalog = $this->catalogiService->getCatalogBySlug($catalogSlug);
            if ($catalog === null) {
                return $this->json(['error' => $this->l10n->t('Catalog not found')], Http::STATUS_NOT_FOUND);
            }

            $context = $this->resolveContext('ooapi_courses_register', 'ooapi_courses_schema');
            if ($context['error'] !== null) {
                return $context['error'];
            }

            $violations = $this->ooapiService->validateCatalog($catalog, $context['pair']['register'], $context['pair']['schema']);

            return $this->json(
                [
                    'catalogSlug' => $catalogSlug,
                    'valid'       => empty($violations),
                    'violations'  => $violations,
                ],
                Http::STATUS_OK
            );
        });

    }//end validate()
}//end class
