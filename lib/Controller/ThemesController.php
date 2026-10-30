<?php

/**
 * OpenCatalogi Themes Controller.
 *
 * Controller for handling theme-related operations in the OpenCatalogi app.
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
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IAppConfig;
use OCP\IRequest;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

/**
 * Controller for handling theme-related operations.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *   Catching DoesNotExistException in show() takes the coupling count from 12 to 13.
 *   The six sibling controllers that resolve OpenRegister objects — including
 *   ListingsController and PublicationsController, whose scoped-find() shape show()
 *   now follows — already carry this suppression for the same reason. Dropping the
 *   catch to keep a design metric one point lower would leave a public JSON route
 *   answering an unknown identifier with an HTML 500.
 */
class ThemesController extends Controller {
	use ResolvesRegisterConfiguration;
	use ListsConfiguredObjects;

	/**
	 * Allowed CORS methods.
	 *
	 * @var string
	 */
	private string $corsMethods;

	/**
	 * Allowed CORS headers.
	 *
	 * @var string
	 */
	private string $corsAllowedHeaders;

	/**
	 * CORS max age.
	 *
	 * @var integer
	 */
	private int $corsMaxAge;

	/**
	 * ThemesController constructor.
	 *
	 * @param string $appName The name of the app.
	 * @param IRequest $request The request object.
	 * @param IAppConfig $config App configuration interface.
	 * @param ContainerInterface $container Server container for DI.
	 * @param IAppManager $appManager App manager.
	 * @param string $corsMethods Allowed CORS methods.
	 * @param string $corsAllowedHeaders Allowed CORS headers.
	 * @param integer $corsMaxAge CORS max age.
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly IAppConfig $config,
		private readonly ContainerInterface $container,
		private readonly IAppManager $appManager,
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
	 * @return \OCA\OpenRegister\Service\ObjectService|null The ObjectService.
	 *
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface
	 */
	private function getObjectService(): ?\OCA\OpenRegister\Service\ObjectService {
		if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
			return $this->container->get('OCA\OpenRegister\Service\ObjectService');
		}

		throw new RuntimeException('OpenRegister service is not available.');
	}//end getObjectService()

	/**
	 * Get the schema and register configuration for themes.
	 *
	 * Resolved through OpenRegister's RegisterResolverService (no empty-string
	 * fallback); an unconfigured `theme_register`/`theme_schema` raises
	 * MissingConfigException which the caller converts to a 503.
	 *
	 * @return array<string, string> Array containing schema and register configuration.
	 *
	 * @throws \RuntimeException When OpenRegister is unavailable.
	 * @throws \OCA\OpenRegister\Service\Resolver\Exception\MissingConfigException When a context key is unconfigured.
	 *
	 * @spec openspec/specs/opencatalogi-adopt-or-abstractions/spec.md (Requirement: Adopt RegisterResolverService)
	 */
	private function getThemeConfiguration(): array {
		return $this->resolveRegisterConfiguration(registerKey: 'theme_register', schemaKey: 'theme_schema');
	}//end getThemeConfiguration()

	/**
	 * Implements a preflighted CORS response for OPTIONS requests.
	 *
	 * @return Response The CORS response.
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
	 * Get all themes with pagination support.
	 *
	 * @return JSONResponse The JSON response containing the list of themes.
	 *
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @spec openspec/specs/content-management/spec.md
	 */
	#[AnonRateLimit(limit: 120, period: 60)]
	public function index(): JSONResponse {
		// Get theme configuration from settings (resolved via OpenRegister; 503 if unconfigured).
		try {
			$themeConfig = $this->getThemeConfiguration();
		} catch (\Throwable $e) {
			return $this->registerConfigErrorResponse(e: $e);
		}

		$result = $this->getObjectService()->searchObjectsPaginated(
			$this->scopedSearchQuery(objectConfig: $themeConfig),
			_rbac: true,
			_multitenancy: false
		);

		// Visibility governed by RBAC on the search above (_rbac: true); rbac=true
		// enforces schema authorization, multi=false allows public theme access.
		return $this->publicJsonResponse(data: $this->paginatedResponseData(result: $result));
	}//end index()

	/**
	 * Get a specific theme by its ID.
	 *
	 * @param string|integer $id The ID of the theme to retrieve.
	 *
	 * @return JSONResponse The JSON response containing the theme details.
	 *
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @spec openspec/specs/content-management/spec.md
	 */
	#[AnonRateLimit(limit: 120, period: 60)]
	public function show(string|int $id): JSONResponse {
		// Get theme configuration from settings (resolved via OpenRegister; 503 if
		// unconfigured), exactly as index() does.
		try {
			$themeConfig = $this->getThemeConfiguration();
		} catch (\Throwable $e) {
			return $this->registerConfigErrorResponse(e: $e);
		}

		// Scope the lookup to the theme register/schema. Without them, find() falls back
		// to OpenRegister's findAcrossAllMagicTables() path and resolves the identifier in
		// ANY register on the instance — and this route is @PublicPage, so that was an
		// unauthenticated read of arbitrary objects. Measured on a live instance: an
		// anonymous request for an identifier belonging to an unrelated app's register
		// returned that object's full body with HTTP 200.
		//
		// `_rbac: true` was never sufficient on its own: OpenRegister grants read by
		// default on a schema that declares no authorization block, which is the state of
		// most registers on a shared instance.
		try {
			$theme = $this->getObjectService()->find(
				$id,
				[],
				false,
				$themeConfig['register'],
				$themeConfig['schema'],
				_rbac: true,
				_multitenancy: false
			);
		} catch (DoesNotExistException $e) {
			$theme = null;
		}

		// An identifier that is not a theme and one that does not exist at all are the
		// same answer here. Unhandled, the lookup surfaced as a 500 rendering Nextcloud's
		// HTML error page on a public JSON route.
		if ($theme === null) {
			return new JSONResponse(['error' => 'Not Found'], 404);
		}

		$data = $theme;
		if ($theme instanceof \OCP\AppFramework\Db\Entity) {
			$data = $theme->jsonSerialize();
		}

		return $this->publicJsonResponse(data: $data);
	}//end show()
}//end class
