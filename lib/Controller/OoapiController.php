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

use OCA\OpenCatalogi\Contract\CatalogiServiceInterface;
use OCA\OpenCatalogi\Contract\OoapiPage;
use OCA\OpenCatalogi\Contract\OoapiScope;
use OCA\OpenCatalogi\Contract\OoapiServiceInterface;
use OCA\OpenCatalogi\Settings\OpenCatalogiAdmin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
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
class OoapiController extends Controller {
	use AnswersCrossOriginReadRequests;
	use ResolvesRegisterConfiguration;

	/**
	 * OoapiController constructor.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The request object.
	 * @param OoapiServiceInterface $ooapiService The OOAPI 5.0 orchestration service.
	 * @param CatalogiServiceInterface $catalogiService Catalog resolution (slug →
	 *                                         catalog).
	 * @param IUserSession $userSession Nextcloud user session (OOAPI-008 auth gate).
	 * @param IL10N $l10n Localization service.
	 * @param LoggerInterface $logger PSR-3 logger.
	 * @param ContainerInterface $container Server container (ResolvesRegisterConfiguration).
	 * @param IAppConfig|null $appConfig App config for the CORS allowlist.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly OoapiServiceInterface $ooapiService,
		private readonly CatalogiServiceInterface $catalogiService,
		private readonly IUserSession $userSession,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
		private readonly ContainerInterface $container,
		private readonly ?IAppConfig $appConfig = null,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * Build a JSON response carrying the standard OOAPI CORS headers (OOAPI-001).
	 *
	 * @param mixed $data The response body.
	 * @param integer $status The HTTP status code.
	 *
	 * @return JSONResponse The CORS-headed JSON response.
	 */
	private function json(mixed $data, int $status): JSONResponse {
		return $this->withCorsHeaders(response: new JSONResponse($data, $status));
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
	 * @spec openspec/specs/ooapi-catalog-publication/spec.md#requirement-per-catalog-ooapi-5-0-resource-endpoints-ooapi-001
	 */
	#[AnonRateLimit(limit: 240, period: 60)]
	public function preflightedCors(): Response {
		return $this->readOnlyPreflight();
	}//end preflightedCors()

	/**
	 * Enforce the OOAPI-008 consumer-credential auth gate.
	 *
	 * @return JSONResponse|null A 401/403 error response, or null when the caller is allowed to proceed.
	 *
	 * @spec openspec/specs/ooapi-catalog-publication/spec.md#requirement-consumer-credential-authenticated-access-ooapi-008
	 */
	private function requireAuthenticatedConsumer(): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->json(data: ['error' => $this->l10n->t('Authentication required')], status: Http::STATUS_UNAUTHORIZED);
		}

		if ($this->ooapiService->isConsumerAllowed($user->getUID()) === false) {
			$notAllowed = $this->l10n->t('This account is not an authorized OOAPI consumer');
			return $this->json(data: ['error' => $notAllowed], status: Http::STATUS_FORBIDDEN);
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
	 * @spec openspec/specs/ooapi-catalog-publication/spec.md#requirement-per-catalog-ooapi-5-0-resource-endpoints-ooapi-001
	 */
	private function resolveEnabledCatalog(string $catalogSlug): array {
		$catalog = $this->catalogiService->getCatalogBySlug($catalogSlug);
		if ($catalog === null) {
			$notFound = $this->l10n->t('Catalog not found');
			return ['catalog' => null, 'error' => $this->json(data: ['error' => $notFound], status: Http::STATUS_NOT_FOUND)];
		}

		if ($this->ooapiService->isOoapiEnabled($catalog) === false) {
			$notEnabled = $this->l10n->t('OOAPI 5.0 publication is not enabled for this catalog');
			return ['catalog' => null, 'error' => $this->json(data: ['error' => $notEnabled], status: Http::STATUS_NOT_FOUND)];
		}

		return ['catalog' => $catalog, 'error' => null];
	}//end resolveEnabledCatalog()

	/**
	 * Resolve a `<context>_register`/`<context>_schema` pair, or a ready-to-return 503.
	 *
	 * @param string $registerKey The `<context>_register` config key.
	 * @param string $schemaKey The `<context>_schema` config key.
	 *
	 * @return array{pair: array{register: string, schema: string}|null, error: JSONResponse|null} The resolved pair, or an error response.
	 *
	 * @spec openspec/specs/ooapi-catalog-publication/spec.md#requirement-admin-configuration-for-ooapi-publication-ooapi-010
	 */
	private function resolveContext(string $registerKey, string $schemaKey): array {
		try {
			return ['pair' => $this->resolveRegisterConfiguration(registerKey: $registerKey, schemaKey: $schemaKey), 'error' => null];
		} catch (\Throwable $e) {
			return ['pair' => null, 'error' => $this->registerConfigErrorResponse(e: $e)];
		}

	}//end resolveContext()

	/**
	 * Resolve `pageNumber`/`pageSize` query parameters (OOAPI-007).
	 *
	 * @return OoapiPage The page asked for.
	 */
	private function resolvePagination(): OoapiPage {
		return new OoapiPage(
			number: max(1, (int)$this->request->getParam('pageNumber', 1)),
			size: max(1, (int)$this->request->getParam('pageSize', OoapiServiceInterface::DEFAULT_PAGE_SIZE))
		);

	}//end resolvePagination()

	/**
	 * Wrap a paginated collection result in the OOAPI 5.0 list envelope.
	 *
	 * @param array{items: array<int, array<string, mixed>>, pageNumber: int, pageSize: int, hasNext: bool} $result The service result.
	 *
	 * @return JSONResponse The envelope response.
	 *
	 * @spec openspec/specs/ooapi-catalog-publication/spec.md#requirement-ooapi-5-0-pagination-ooapi-007
	 */
	private function collectionResponse(array $result): JSONResponse {
		return $this->json(
			data: [
				'pageNumber' => $result['pageNumber'],
				'pageSize' => $result['pageSize'],
				'hasNext' => $result['hasNext'],
				'items' => $result['items'],
			],
			status: Http::STATUS_OK
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
	private function guard(callable $action): JSONResponse {
		try {
			return $action();
		} catch (\Throwable $e) {
			$this->logger->error('[OoapiController] Unhandled error', ['error' => $e->getMessage()]);
			return $this->json(data: ['error' => $this->l10n->t('Internal server error')], status: Http::STATUS_INTERNAL_SERVER_ERROR);
		}

	}//end guard()

	/**
	 * Run a consumer endpoint inside an enabled catalog and its configured register/schema pair.
	 *
	 * Refuses an unauthenticated or disallowed consumer, an unknown or OOAPI-disabled
	 * catalog and an unconfigured context before $render runs; an unexpected failure
	 * answers 500.
	 *
	 * @param array{catalogSlug: string, context: string} $lookup The catalog slug and the `<context>` of the
	 *                                                           `<context>_register`/`<context>_schema` config keys.
	 * @param callable                                    $render Builds the answer from the OoapiScope of the catalog and its pair.
	 *
	 * @return JSONResponse The answer, or the refusal.
	 *
	 * @spec openspec/specs/ooapi-catalog-publication/spec.md#requirement-per-catalog-ooapi-5-0-resource-endpoints-ooapi-001
	 */
	private function inCatalogContext(array $lookup, callable $render): JSONResponse {
		$authError = $this->requireAuthenticatedConsumer();
		if ($authError !== null) {
			return $authError;
		}

		return $this->guard(
			action: function () use ($lookup, $render): JSONResponse {
				$resolved = $this->resolveEnabledCatalog(catalogSlug: $lookup['catalogSlug']);
				if ($resolved['error'] !== null) {
					return $resolved['error'];
				}

				$context = $this->resolveContext(
					registerKey: $lookup['context'].'_register',
					schemaKey: $lookup['context'].'_schema'
				);
				if ($context['error'] !== null) {
					return $context['error'];
				}

				return $render(
					new OoapiScope(
						catalog: $resolved['catalog'],
						register: $context['pair']['register'],
						schema: $context['pair']['schema']
					)
				);
			}
		);

	}//end inCatalogContext()

	/**
	 * A single resource, or a 404 with the given message when there is none.
	 *
	 * @param array<string, mixed>|null $resource The resource.
	 * @param string                    $notFound The translated not-found message.
	 *
	 * @return JSONResponse The resource, or the 404.
	 *
	 * @spec openspec/specs/ooapi-catalog-publication/spec.md#requirement-per-catalog-ooapi-5-0-resource-endpoints-ooapi-001
	 */
	private function resourceOrNotFound(?array $resource, string $notFound): JSONResponse {
		if ($resource === null) {
			return $this->json(data: ['error' => $notFound], status: Http::STATUS_NOT_FOUND);
		}

		return $this->json(data: $resource, status: Http::STATUS_OK);

	}//end resourceOrNotFound()

	/**
	 * Fetch one materialized resource by its OOAPI id field.
	 *
	 * @param array{catalogSlug: string, context: string, id: string, idField: string} $lookup   The catalog slug, config context,
	 *                                                                                           id and the field that holds it.
	 * @param string                                                                   $notFound The translated not-found message.
	 *
	 * @return JSONResponse The resource, or a 404 or refusal.
	 *
	 * @spec openspec/specs/ooapi-catalog-publication/spec.md#requirement-course-program-offering-resources-are-materialized-not-rendered-live-from-scholiq-ooapi-003
	 */
	private function materializedResource(array $lookup, string $notFound): JSONResponse {
		return $this->inCatalogContext(
			lookup: $lookup,
			render: fn (OoapiScope $scope): JSONResponse => $this->resourceOrNotFound(
				resource: $this->ooapiService->getResource(
					scope: $scope,
					id: $lookup['id'],
					idField: $lookup['idField']
				),
				notFound: $notFound
			)
		);

	}//end materializedResource()

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
	 * @spec openspec/specs/ooapi-catalog-publication/spec.md#requirement-organization-resource-renders-the-existing-organisation-object-live-ooapi-002
	 */
	public function organizations(string $catalogSlug): JSONResponse {
		return $this->inCatalogContext(
			lookup: ['catalogSlug' => $catalogSlug, 'context' => 'organization'],
			render: function (OoapiScope $scope): JSONResponse {
				$organization = $this->ooapiService->renderOrganization(scope: $scope);

				$items = [];
				if ($organization !== null) {
					$items = [$organization];
				}

				return $this->json(
					data: [
						'pageNumber' => 1,
						'pageSize' => count($items),
						'hasNext' => false,
						'items' => $items,
					],
					status: Http::STATUS_OK
				);
			}
		);

	}//end organizations()

	/**
	 * Fetch a single OOAPI 5.0 organization resource.
	 *
	 * @param string $catalogSlug The catalog slug.
	 * @param string $id The organization id.
	 *
	 * @return JSONResponse The organization resource, or 404.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/ooapi-catalog-publication/spec.md#requirement-organization-resource-renders-the-existing-organisation-object-live-ooapi-002
	 */
	public function organization(string $catalogSlug, string $id): JSONResponse {
		return $this->inCatalogContext(
			lookup: ['catalogSlug' => $catalogSlug, 'context' => 'organization'],
			render: fn (OoapiScope $scope): JSONResponse => $this->resourceOrNotFound(
				resource: $this->ooapiService->organizationById(scope: $scope, id: $id),
				notFound: $this->l10n->t('Organization not found')
			)
		);

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
	 * @spec openspec/specs/ooapi-catalog-publication/spec.md#requirement-per-catalog-ooapi-5-0-resource-endpoints-ooapi-001
	 */
	public function courses(string $catalogSlug): JSONResponse {
		return $this->inCatalogContext(
			lookup: ['catalogSlug' => $catalogSlug, 'context' => 'ooapi_courses'],
			render: fn (OoapiScope $scope): JSONResponse => $this->collectionResponse(
				result: $this->ooapiService->listCourses(scope: $scope, page: $this->resolvePagination())
			)
		);

	}//end courses()

	/**
	 * Fetch a single materialized `course` resource.
	 *
	 * @param string $catalogSlug The catalog slug.
	 * @param string $id The course id.
	 *
	 * @return JSONResponse The course resource, or 404.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/ooapi-catalog-publication/spec.md#requirement-course-program-offering-resources-are-materialized-not-rendered-live-from-scholiq-ooapi-003
	 */
	public function course(string $catalogSlug, string $id): JSONResponse {
		return $this->materializedResource(
			lookup: ['catalogSlug' => $catalogSlug, 'context' => 'ooapi_courses', 'id' => $id, 'idField' => 'courseId'],
			notFound: $this->l10n->t('Course not found')
		);

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
	 * @spec openspec/specs/ooapi-catalog-publication/spec.md#requirement-per-catalog-ooapi-5-0-resource-endpoints-ooapi-001
	 */
	public function programs(string $catalogSlug): JSONResponse {
		return $this->inCatalogContext(
			lookup: ['catalogSlug' => $catalogSlug, 'context' => 'ooapi_programs'],
			render: fn (OoapiScope $scope): JSONResponse => $this->collectionResponse(
				result: $this->ooapiService->listPrograms(scope: $scope, page: $this->resolvePagination())
			)
		);

	}//end programs()

	/**
	 * Fetch a single materialized `program` resource.
	 *
	 * @param string $catalogSlug The catalog slug.
	 * @param string $id The program id.
	 *
	 * @return JSONResponse The program resource, or 404.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/ooapi-catalog-publication/spec.md#requirement-course-program-offering-resources-are-materialized-not-rendered-live-from-scholiq-ooapi-003
	 */
	public function program(string $catalogSlug, string $id): JSONResponse {
		return $this->materializedResource(
			lookup: ['catalogSlug' => $catalogSlug, 'context' => 'ooapi_programs', 'id' => $id, 'idField' => 'programId'],
			notFound: $this->l10n->t('Program not found')
		);

	}//end program()

	/**
	 * List a course's materialized `offering` resources (OOAPI-006).
	 *
	 * @param string $catalogSlug The catalog slug.
	 * @param string $courseId The parent course id.
	 *
	 * @return JSONResponse The paginated offering list, filtered to the given course.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/ooapi-catalog-publication/spec.md#requirement-offerings-nest-under-their-course-ooapi-006
	 */
	public function courseOfferings(string $catalogSlug, string $courseId): JSONResponse {
		return $this->inCatalogContext(
			lookup: ['catalogSlug' => $catalogSlug, 'context' => 'ooapi_offerings'],
			render: fn (OoapiScope $scope): JSONResponse => $this->collectionResponse(
				result: $this->ooapiService->listOfferings(
					scope: $scope,
					page: $this->resolvePagination(),
					courseId: $courseId
				)
			)
		);

	}//end courseOfferings()

	/**
	 * Fetch a single materialized `offering` resource.
	 *
	 * @param string $catalogSlug The catalog slug.
	 * @param string $id The offering id.
	 *
	 * @return JSONResponse The offering resource, or 404.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/ooapi-catalog-publication/spec.md#requirement-offerings-nest-under-their-course-ooapi-006
	 */
	public function offering(string $catalogSlug, string $id): JSONResponse {
		return $this->materializedResource(
			lookup: ['catalogSlug' => $catalogSlug, 'context' => 'ooapi_offerings', 'id' => $id, 'idField' => 'offeringId'],
			notFound: $this->l10n->t('Offering not found')
		);

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
	 * @spec openspec/specs/ooapi-catalog-publication/spec.md#requirement-admin-configuration-for-ooapi-publication-ooapi-010
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function validate(string $catalogSlug): JSONResponse {
		return $this->guard(
			action: function () use ($catalogSlug): JSONResponse {
				$catalog = $this->catalogiService->getCatalogBySlug($catalogSlug);
				if ($catalog === null) {
					return $this->json(data: ['error' => $this->l10n->t('Catalog not found')], status: Http::STATUS_NOT_FOUND);
				}

				$context = $this->resolveContext(registerKey: 'ooapi_courses_register', schemaKey: 'ooapi_courses_schema');
				if ($context['error'] !== null) {
					return $context['error'];
				}

				$violations = $this->ooapiService->validateCatalog(
					scope: new OoapiScope(
						catalog: $catalog,
						register: $context['pair']['register'],
						schema: $context['pair']['schema']
					)
				);

				return $this->json(
					data: [
						'catalogSlug' => $catalogSlug,
						'valid' => empty($violations),
						'violations' => $violations,
					],
					status: Http::STATUS_OK
				);
			}
		);

	}//end validate()
}//end class
