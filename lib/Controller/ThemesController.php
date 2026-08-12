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
		parent::__construct($appName, $request);
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
		return $this->resolveRegisterConfiguration('theme_register', 'theme_schema');
	}//end getThemeConfiguration()

	/**
	 * Resolve the Access-Control-Allow-Origin header value for the current request.
	 *
	 * Reads the configured allowlist from IAppConfig key 'cors_allowed_origins' (CSV).
	 * Special value '*' (the default) means "any origin allowed" and emits a literal '*'
	 * — the caller's Origin is NEVER echoed back unless it appears on the allowlist (#735).
	 *
	 * @return string The header value to use for Access-Control-Allow-Origin.
	 */
	private function resolveAllowedOrigin(): string {
		$configured = trim($this->config->getValueString($this->appName, 'cors_allowed_origins', '*'));
		if ($configured === '' || $configured === '*') {
			return '*';
		}

		$allowlist = array_filter(
			array_map('trim', explode(',', $configured)),
			static fn (string $entry): bool => $entry !== ''
		);

		$callerOrigin = $this->request->getHeader('Origin');
		if ($callerOrigin === '') {
			$callerOrigin = ($this->request->server['HTTP_ORIGIN'] ?? '');
		}

		if ($callerOrigin !== '' && in_array($callerOrigin, $allowlist, true) === true) {
			return $callerOrigin;
		}

		return ($allowlist[0] ?? '*');
	}//end resolveAllowedOrigin()

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
	public function preflightedCors(): Response {
		// Create and configure the response.
		$response = new Response();
		$response->addHeader('Access-Control-Allow-Origin', $this->resolveAllowedOrigin());
		$response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
		$response->addHeader('Access-Control-Max-Age', (string)$this->corsMaxAge);
		$response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);
		$response->addHeader('Access-Control-Allow-Credentials', 'false');

		return $response;
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
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 *
	 * @spec openspec/specs/content-management/spec.md
	 */
	public function index(): JSONResponse {
		// Get theme configuration from settings (resolved via OpenRegister; 503 if unconfigured).
		try {
			$themeConfig = $this->getThemeConfiguration();
		} catch (\Throwable $e) {
			return $this->registerConfigErrorResponse($e);
		}

		// Get query parameters from request.
		$queryParams = $this->request->getParams();

		// Build search query.
		$searchQuery = $queryParams;

		// Clean up unwanted parameters.
		unset($searchQuery['id'], $searchQuery['_route']);

		// Add schema filter if configured.
		if (empty($themeConfig['schema']) === false) {
			$searchQuery['@self']['schema'] = $themeConfig['schema'];
		}

		// Add register filter if configured.
		if (empty($themeConfig['register']) === false) {
			$searchQuery['@self']['register'] = $themeConfig['register'];
		}

		// Use searchObjectsPaginated for better performance and pagination support.
		// rbac=true enforces schema authorization; multi=false for public theme access.
		$result = $this->getObjectService()->searchObjectsPaginated(
			$searchQuery,
			_rbac: true,
			_multitenancy: false
		);

		// Visibility governed by RBAC on the search above (_rbac: true).
		// Build paginated response structure.
		$responseData = [
			'results' => ($result['results'] ?? []),
			'total' => ($result['total'] ?? 0),
			'limit' => ($result['limit'] ?? 20),
			'offset' => ($result['offset'] ?? 0),
			'page' => ($result['page'] ?? 1),
			'pages' => ($result['pages'] ?? 1),
		];

		// Add pagination links if present.
		if (isset($result['next']) === true) {
			$responseData['next'] = $result['next'];
		}

		if (isset($result['prev']) === true) {
			$responseData['prev'] = $result['prev'];
		}

		// Add facets if present.
		if (isset($result['facets']) === true) {
			$facetsData = $result['facets'];
			// Unwrap nested facets if needed.
			if (isset($facetsData['facets']) === true && is_array($facetsData['facets']) === true) {
				$facetsData = $facetsData['facets'];
			}

			$responseData['facets'] = $facetsData;
		}

		if (isset($result['facetable']) === true) {
			$responseData['facetable'] = $result['facetable'];
		}

		// Add CORS headers for public API access.
		$response = new JSONResponse($responseData);

		$response->addHeader('Access-Control-Allow-Origin', $this->resolveAllowedOrigin());
		$response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
		$response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);

		return $response;
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
	public function show(string|int $id): JSONResponse {
		// Get theme configuration from settings (resolved via OpenRegister; 503 if
		// unconfigured), exactly as index() does.
		try {
			$themeConfig = $this->getThemeConfiguration();
		} catch (\Throwable $e) {
			return $this->registerConfigErrorResponse($e);
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

		// Add CORS headers for public API access (#735 — never reflect arbitrary Origin).
		$response = new JSONResponse($data);

		$response->addHeader('Access-Control-Allow-Origin', $this->resolveAllowedOrigin());
		$response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
		$response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);

		return $response;
	}//end show()
}//end class
