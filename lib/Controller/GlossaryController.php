<?php

/**
 * OpenCatalogi Glossary Controller.
 *
 * Controller for handling glossary-related operations in the OpenCatalogi app.
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
 * @spec openspec/specs/content-management/spec.md
 * @spec openspec/specs/content-management/spec.md
 */

namespace OCA\OpenCatalogi\Controller;

use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

/**
 * Class GlossaryController
 *
 * Controller for handling glossary-related operations in the OpenCatalogi app.
 *
 * @category  Controller
 * @package   opencatalogi
 * @author    Ruben van der Linde
 * @copyright 2024
 * @version   1.0.0
 * @link      https://github.com/opencatalogi/opencatalogi
 */
class GlossaryController extends Controller {
	use ResolvesRegisterConfiguration;
	use ListsConfiguredObjects;

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
	 * GlossaryController constructor.
	 *
	 * @param string $appName The name of the app
	 * @param IRequest $request The request object
	 * @param IAppConfig $config App configuration interface
	 * @param ContainerInterface $container Server container for dependency injection
	 * @param IAppManager $appManager App manager for checking installed apps
	 * @param IL10N $l10n Localization service
	 * @param string $corsMethods Allowed CORS methods
	 * @param string $corsAllowedHeaders Allowed CORS headers
	 * @param integer $corsMaxAge CORS max age
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly IAppConfig $config,
		private readonly ContainerInterface $container,
		private readonly IAppManager $appManager,
		private readonly IL10N $l10n,
		string $corsMethods = 'PUT, POST, GET, DELETE, PATCH',
		string $corsAllowedHeaders = 'Authorization, Content-Type, Accept',
		int $corsMaxAge = 1728000,
	) {
		parent::__construct(appName: $appName, request: $request);
		$this->corsMethods = $corsMethods;
		$this->corsAllowedHeaders = $corsAllowedHeaders;
		$this->corsMaxAge = $corsMaxAge;

	}//end __construct()

	/**
	 * Attempts to retrieve the OpenRegister ObjectService from the container.
	 *
	 * @return \OCA\OpenRegister\Service\ObjectService|null The OpenRegister ObjectService if available, null otherwise.
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface
	 */
	private function getObjectService(): ?\OCA\OpenRegister\Service\ObjectService {
		if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
			return $this->container->get('OCA\OpenRegister\Service\ObjectService');
		}

		throw new RuntimeException('OpenRegister service is not available.');
	}//end getObjectService()

	/**
	 * Get the schema and register configuration for glossary.
	 *
	 * Resolved through OpenRegister's RegisterResolverService (no empty-string
	 * fallback); an unconfigured `glossary_register`/`glossary_schema` raises
	 * MissingConfigException which the caller converts to a 503.
	 *
	 * @return array<string, string> Array containing schema and register configuration
	 *
	 * @throws \RuntimeException When OpenRegister is unavailable.
	 * @throws \OCA\OpenRegister\Service\Resolver\Exception\MissingConfigException When a context key is unconfigured.
	 *
	 * @spec openspec/specs/opencatalogi-adopt-or-abstractions/spec.md (Requirement: Adopt RegisterResolverService)
	 */
	private function getGlossaryConfiguration(): array {
		return $this->resolveRegisterConfiguration(registerKey: 'glossary_register', schemaKey: 'glossary_schema');
	}//end getGlossaryConfiguration()

	/**
	 * Implements a preflighted CORS response for OPTIONS requests.
	 *
	 * @return Response The CORS response
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @spec openspec/specs/cross-origin-api-access/spec.md#requirement-answer-cors-preflight-requests-on-public-api-controllers-cor-001
	 */
	#[AnonRateLimit(limit: 240, period: 60)]
	public function preflightedCors(): Response {
		return $this->withListPreflightHeaders(response: new Response());
	}//end preflightedCors()

	/**
	 * Get all glossary terms - OPTIMIZED with searchObjectsPaginated.
	 *
	 * @return JSONResponse The JSON response containing the list of glossary terms
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @spec openspec/specs/content-management/spec.md
	 */
	#[AnonRateLimit(limit: 120, period: 60)]
	public function index(): JSONResponse {
		// Get glossary configuration from settings (resolved via OpenRegister; 503 if unconfigured).
		try {
			$glossaryConfig = $this->getGlossaryConfiguration();
		} catch (\Throwable $e) {
			return $this->registerConfigErrorResponse(e: $e);
		}

		$result = $this->getObjectService()->searchObjectsPaginated(
			$this->scopedSearchQuery(objectConfig: $glossaryConfig),
			_rbac: true,
			_multitenancy: false
		);

		// Visibility governed by RBAC on the search above (_rbac: true); rbac=true
		// enforces schema authorization, multi=false allows public glossary access.
		return $this->publicJsonResponse(data: $this->paginatedResponseData(result: $result));
	}//end index()

	/**
	 * Get a specific glossary term by its ID.
	 *
	 * @param string|integer $id The ID of the glossary term to retrieve
	 *
	 * @return JSONResponse The JSON response containing the glossary term details
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @spec openspec/specs/content-management/spec.md
	 */
	#[AnonRateLimit(limit: 120, period: 60)]
	public function show(string|int $id): JSONResponse {
		// Get glossary configuration from settings (resolved via OpenRegister; 503 if
		// unconfigured), exactly as index() does.
		try {
			$glossaryConfig = $this->getGlossaryConfiguration();
		} catch (\Throwable $e) {
			return $this->registerConfigErrorResponse(e: $e);
		}

		// Scope the lookup to the glossary register/schema. Unscoped, OpenRegister answers an
		// `_ids` search from every magic table on the instance, and this route is @PublicPage.
		// `_rbac: true` alone does not close that: a schema without an authorization block
		// grants read to everyone.
		$searchQuery = [
			'_ids' => [$id],
			'_limit' => 1,
			'@self' => [
				'register' => $glossaryConfig['register'],
				'schema' => $glossaryConfig['schema'],
			],
		];
		$result = $this->getObjectService()->searchObjectsPaginated($searchQuery, _rbac: true, _multitenancy: false);

		if (empty($result['results']) === true) {
			return new JSONResponse(['error' => $this->l10n->t('Glossary term not found')], 404);
		}

		$glossaryTerm = $result['results'][0];

		// Visibility governed by RBAC on the search above (_rbac: true); a term the caller
		// may not read resolves to an empty result and 404s above.
		$data = $glossaryTerm;
		if ($glossaryTerm instanceof \OCP\AppFramework\Db\Entity) {
			$data = $glossaryTerm->jsonSerialize();
		}

		return $this->publicJsonResponse(data: $data);
	}//end show()
}//end class
