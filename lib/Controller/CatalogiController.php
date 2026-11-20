<?php

/**
 * OpenCatalogi Catalogi Controller.
 *
 * Controller for handling catalog-related operations in the OpenCatalogi app.
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
 * @spec openspec/specs/catalogs/spec.md
 * @spec openspec/specs/catalogs/spec.md
 */

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\Contract\AppIdentity;
use OCA\OpenCatalogi\Contract\CatalogiServiceInterface;
use OCA\OpenCatalogi\Contract\OpenRegisterLookupInterface;
use OCA\OpenCatalogi\Contract\PublicationQueryServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IAppConfig;
use OCP\IRequest;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
 * Controller for handling catalog-related operations.

 */
class CatalogiController extends Controller {
	use AnswersCrossOriginListRequests;
	use AnswersInTheVisitorsLanguage;
	use ResolvesRegisterConfiguration;
	use SendsListCorsHeaders;

	/**
	 * CatalogiController constructor.
	 *
	 * @param IRequest $request The request object.
	 * @param CatalogiServiceInterface $catalogiService The catalogi service.
	 * @param IAppConfig $config App configuration interface.
	 * @param OpenRegisterLookupInterface $registers Resolves the configured register pair and OpenRegister's ObjectService.
	 * @param CorsSettings $cors Allowed CORS methods, headers and max age.
	 * @param PublicationQueryServiceInterface|null $queryService Negotiates the visitor's language (REQ-PTR-002).
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
	 */
	public function __construct(
		IRequest $request,
		private readonly CatalogiServiceInterface $catalogiService,
		private readonly IAppConfig $config,
		private readonly OpenRegisterLookupInterface $registers,
		private readonly CorsSettings $cors = new CorsSettings(),
		private readonly ?PublicationQueryServiceInterface $queryService = null,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Get the schema and register configuration for catalogs.
	 *
	 * Resolved through OpenRegister's RegisterResolverService (no empty-string
	 * fallback); an unconfigured `catalog_register`/`catalog_schema` raises
	 * MissingConfigException which the caller converts to a 503.
	 *
	 * @return array<string, string> Array containing schema and register configuration.
	 *
	 * @throws \RuntimeException When OpenRegister is unavailable.
	 * @throws \OCA\OpenRegister\Service\Resolver\Exception\MissingConfigException When a context key is unconfigured.
	 *
	 * @spec openspec/specs/opencatalogi-adopt-or-abstractions/spec.md (Requirement: Adopt RegisterResolverService)
	 */
	private function getCatalogConfiguration(): array {
		return $this->resolveRegisterConfiguration(registerKey: 'catalog_register', schemaKey: 'catalog_schema');
	}//end getCatalogConfiguration()

	// Generous, as for every preflightedCors() in this app: the browser sends
	// this OPTIONS request BEFORE each cross-origin call it makes. A ceiling
	// tight enough to bite here would break ordinary cross-origin use rather
	// than an attack.
	/**
	 * Implements a preflighted CORS response for OPTIONS requests.
	 *
	 * @return Response The CORS response.
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @spec openspec/specs/cross-origin-api-access/spec.md#requirement-answer-cors-preflight-requests-on-public-api-controllers-cor-001
	 * @spec openspec/changes/catalogs/tasks.md#task-5
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 240, period: 60)]
	public function preflightedCors(): Response {
		return $this->withListPreflightHeaders(response: new Response());
	}//end preflightedCors()

	/**
	 * Retrieve a list of publications based on all available catalogs.
	 *
	 * @return JSONResponse JSON response containing the list of publications.
	 *
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @spec openspec/specs/catalogs/spec.md
	 * @spec openspec/changes/publication-translations/specs/publications/spec.md#requirement-the-public-api-answers-in-the-visitor-s-language-req-ptr-002
	 * @spec openspec/changes/catalogs/tasks.md#task-3
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function index(): JSONResponse {
		$this->negotiateVisitorLanguage(queryService: $this->queryService);

		// Get catalog configuration from settings (resolved via OpenRegister; 503 if unconfigured).
		// The 503 carries the CORS headers too, so a cross-origin caller can read it (CAT-001).
		try {
			$catalogConfig = $this->getCatalogConfiguration();
		} catch (\Throwable $e) {
			return $this->withListCorsHeaders(response: $this->registerConfigErrorResponse(e: $e));
		}

		// Scope pinning, the WOO-581 caller-scope strip and the RBAC'd search live in the service.
		$result = $this->catalogiService->listCatalogs(requestParams: $this->request->getParams(), catalogConfig: $catalogConfig);

		// Visibility is governed by OpenRegister RBAC (the catalog schema grants the public
		// group read only when published <= $now); the search above runs with _rbac: true.
		// Add CORS headers for public API access (#735 — never reflect arbitrary Origin).
		$response = $this->withVisitorLanguageHeaders(response: new JSONResponse($result), queryService: $this->queryService);
		return $this->withListCorsHeaders(response: $response);
	}//end index()

	/**
	 * Retrieve a list of catalogs based on provided filters and parameters.
	 *
	 * @param string|integer $id The ID of the catalog to use as a filter.
	 *
	 * @return JSONResponse JSON response containing the list of catalogs.
	 *
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @spec openspec/specs/catalogs/spec.md
	 * @spec openspec/changes/publication-translations/specs/publications/spec.md#requirement-the-public-api-answers-in-the-visitor-s-language-req-ptr-002
	 * @spec openspec/changes/catalogs/tasks.md#task-4
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function show(string|int $id): JSONResponse {
		$this->negotiateVisitorLanguage(queryService: $this->queryService);

		// Get all objects using the catalog's registers and schemas as filters.
		$response = $this->withVisitorLanguageHeaders(response: $this->catalogiService->index($id), queryService: $this->queryService);

		// Add CORS headers for public API access (#735 — never reflect arbitrary Origin).
		return $this->withListCorsHeaders(response: $response);
	}//end show()
}//end class
