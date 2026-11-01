<?php

/**
 * Service for handling publication-related operations.
 *
 * Provides functionality for retrieving, saving, updating, and deleting publications,
 * as well as managing publication-related data and filters.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service
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
 * @spec openspec/specs/publications/spec.md
 * @spec openspec/specs/federation/spec.md
 * @spec openspec/specs/federation/spec.md
 * @spec openspec/specs/publications/spec.md
 * @spec openspec/specs/publications/spec.md
 * @spec openspec/specs/federation/spec.md
 * @spec openspec/specs/publications/spec.md
 * @spec openspec/specs/publications/spec.md
 * @spec openspec/specs/publications/spec.md
 * @spec openspec/specs/publications/spec.md
 * @spec openspec/specs/publications/spec.md
 * @spec openspec/specs/publications/spec.md
 * @spec openspec/specs/federation/spec.md
 * @spec openspec/specs/federation/spec.md
 * @spec openspec/specs/federation/spec.md
 * @spec openspec/specs/federation/spec.md
 * @spec openspec/specs/federation/spec.md
 */

namespace OCA\OpenCatalogi\Service;

use Exception;
use InvalidArgumentException;
use OCA\OpenCatalogi\Contract\PublicationServiceInterface;
use OCA\OpenRegister\Exception\RegisterNotFoundException;
use OCA\OpenRegister\Exception\SchemaNotFoundException;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Common\Exception\NotFoundException;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;
use ZipArchive;

/**
 * Service for handling publication-related operations.
 *
 * Provides functionality for retrieving, saving, updating, and deleting publications,
 * as well as managing publication-related data and filters.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyMethods) Listing paths are split into small private steps to stay under the unit size and complexity limits.
 *
 * @spec openspec/specs/publications/spec.md
 * @spec openspec/changes/authenticated-read-parity/specs/catalogs/spec.md
 */
class PublicationService implements PublicationServiceInterface {

	/**
	 * The virtual facet for filtering by directory or catalog source.
	 *
	 * @var array<string, mixed>
	 */
	private const array DIRECTORY_FACET = [
		'type' => 'categorical',
		'description' => 'Directory/catalog source of the publication',
		'facet_types' => ['terms'],
		'has_labels' => true,
		'sample_values' => [
			[
				'value' => 'local',
				'label' => 'Local OpenCatalogi',
				'count' => 1,
			],
			[
				'value' => 'directory.opencatalogi.nl',
				'label' => 'OpenCatalogi Directory',
				'count' => 1,
			],
			[
				'value' => 'dimpact.commonground.nu',
				'label' => 'Dimpact',
				'count' => 1,
			],
		],
	];

	/**
	 * The virtual facet for filtering by catalog.
	 *
	 * @var array<string, mixed>
	 */
	private const array CATALOGS_FACET = [
		'type' => 'categorical',
		'description' => 'Catalog that contains the publication',
		'facet_types' => ['terms'],
		'has_labels' => true,
		'sample_values' => [
			[
				'value' => 'default',
				'label' => 'Default Catalog',
				'count' => 1,
			],
			[
				'value' => 'test-catalog',
				'label' => 'Test Catalog',
				'count' => 1,
			],
		],
	];

	/**
	 * The result of a single-publication lookup that found nothing.
	 *
	 * @var array{success: false, error: string, status: int}
	 */
	private const PUBLICATION_NOT_FOUND = [
		'success' => false,
		'error' => 'Publication not found',
		'status' => 404,
	];

	/**
	 * The object service instance.
	 *
	 * @var object|null
	 */
	private ?object $objectService = null;

	/**
	 * The name of the app.
	 *
	 * @var string $appName The name of the app
	 */
	private string $appName;

	/**
	 * List of available registers from catalogs.
	 *
	 * @var array<string>
	 */
	private array $availableRegisters = [];

	/**
	 * List of available schemas from catalogs.
	 *
	 * @var array<string>
	 */
	private array $availableSchemas = [];

	/**
	 * Cached local catalogs to avoid repeated database queries.
	 *
	 * @var array|null
	 */
	private ?array $cachedLocalCatalogs = null;

	/**
	 * Cached catalog filters by catalog ID to avoid repeated database queries.
	 *
	 * @var array
	 */
	private array $cachedCatalogFilters = [];

	/**
	 * Constructor for PublicationService.
	 *
	 * @param IAppConfig $config App configuration interface
	 * @param IRequest $request Request interface
	 * @param ContainerInterface $container Server container for dependency injection
	 * @param IAppManager $appManager App manager for checking installed apps
	 * @param DirectoryService $directoryService Directory service for federation
	 * @param DownloadService $downloadService Renders the publication metadata PDF that is
	 *                                         piped into the download ZIP (DWN-OR-001 /
	 *                                         DWN-OR-003).
	 * @param IUserSession|null $userSession User session used to decide whether the
	 *                                       anonymous `@self` strip list applies
	 *                                       (authenticated-read-parity, CAT-AUTH-001).
	 *                                       Nullable/optional so existing call sites and
	 *                                       tests keep working unmodified; a null session
	 *                                       fails closed to the anonymous (stripped) envelope.
	 *
	 * @spec openspec/changes/authenticated-read-parity/specs/catalogs/spec.md
	 */
	public function __construct(
		private readonly IAppConfig $config,
		private readonly IRequest $request,
		private readonly ContainerInterface $container,
		private readonly IAppManager $appManager,
		private readonly DirectoryService $directoryService,
		private readonly DownloadService $downloadService,
		private readonly ?IUserSession $userSession = null,
	) {
		$this->appName = 'opencatalogi';

	}//end __construct()

	/**
	 * Attempts to retrieve the OpenRegister service from the container.
	 *
	 * @return \OCA\OpenRegister\Service\ObjectService|null The OpenRegister service if available, null otherwise.
	 *
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface
	 *
	 * @spec exclude Lazy dependency-injection accessor — resolves the OpenRegister
	 *       ObjectService from the container; pure framework plumbing, no domain behavior.
	 */
	public function getObjectService(): ?\OCA\OpenRegister\Service\ObjectService {
		if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
			$this->objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

			return $this->objectService;
		}

		throw new RuntimeException('OpenRegister service is not available.');
	}//end getObjectService()

	/**
	 * Resolve the PublicationQueryService for shared visibility enforcement.
	 *
	 * @return PublicationQueryService The query/visibility helper service.
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface
	 *
	 * @spec exclude Lazy dependency-injection accessor — resolves the shared
	 *       PublicationQueryService from the container; pure framework plumbing.
	 */
	private function getQueryService(): PublicationQueryService {
		return $this->container->get(PublicationQueryService::class);
	}//end getQueryService()

	/**
	 * Hold a catalog-union schema scope to the SCH-PFTS-CAT-002 read-rule guard.
	 *
	 * The union built here serves `#[PublicPage]` routes — `/api/federation/
	 * publications` and its fast paths — and used to reach
	 * `searchObjects(_rbac: true)` unguarded, so an anonymous caller read the full
	 * record of any schema without an `authorization` block in any catalog, listed
	 * or not (WOO-581). The rule itself lives in
	 * {@see PublicationQueryService::applySchemaScopeReadRuleGuard()}; this only
	 * routes both union builders (getCatalogFilters() and the ultra-fast path)
	 * through it. Anonymous-only: signed-in callers get the union back untouched.
	 *
	 * The single-object family follows automatically: isObjectInCatalogScope()
	 * and setObjectServiceContext() build their allowed set from
	 * getCatalogFilters(), so `/api/federation/publications/{id}` and its
	 * `/uses`, `/used`, `/attachments` and `/download` siblings (all
	 * `#[PublicPage]`) answer 404 for an object in a dropped schema.
	 *
	 * @param array $schemas The de-duplicated schema union.
	 *
	 * @return array The union, filtered for an anonymous caller.
	 *
	 * @spec openspec/changes/archive/2026-08-28-fix-fts-catalog-model-alignment/specs/search/spec.md
	 */
	private function guardSchemaScope(array $schemas): array {
		return $this->getQueryService()->applySchemaScopeReadRuleGuard(schemaIds: $schemas);
	}//end guardSchemaScope()

	/**
	 * Set register/schema context on the ObjectService for a given object UUID.
	 *
	 * Locates which register/schema an object belongs to — scoped to the catalogs
	 * configured on this instance — then sets that context on the ObjectService so
	 * subsequent operations can find the object.
	 *
	 * The previous implementation issued a platform-wide DBMS-catalog probe (no scope
	 * restriction) and was reachable from anonymous @PublicPage endpoints — a DoS
	 * vector and a cross-catalog information-disclosure risk (C-2 / wave-7). The
	 * current implementation derives the allowed (register × schema) pairs from the
	 * catalogs configured on this instance and delegates to
	 * PublicationQueryService::findObjectLocation(), which routes the lookup through
	 * OpenRegister's ObjectService within that scope.
	 *
	 * @param \OCA\OpenRegister\Service\ObjectService $objectService The object service instance
	 * @param string $objectId The UUID of the object to locate
	 *
	 * @return void
	 */
	private function setObjectServiceContext($objectService, string $objectId): void {
		try {
			// Derive allowed registers/schemas from the catalogs configured on this
			// instance. getCatalogFilters() returns the union of all catalog scopes,
			// which is the safe upper-bound for any request that reaches this path.
			$context = $this->getCatalogFilters();
			$allowedRegisters = array_map('intval', $context['registers']);
			$allowedSchemas = array_map('intval', $context['schemas']);

			if (empty($allowedRegisters) === true || empty($allowedSchemas) === true) {
				// No catalog scope configured — refuse to do a platform-wide scan.
				return;
			}

			// Delegate to the scoped helper that routes the lookup through OpenRegister's
			// ObjectService, never a raw DBMS-catalog probe, and only within a
			// constrained (register × schema) set.
			$location = $this->getQueryService()->findObjectLocation(
				uuid: $objectId,
				allowedRegisters: $allowedRegisters,
				allowedSchemas: $allowedSchemas
			);

			if ($location !== null) {
				$objectService->setRegister(register: (string)$location['register']);
				$objectService->setSchema(schema: (string)$location['schema']);
			}
		} catch (\Exception $e) {
			// Silently fail — worst case we fall back to the old behavior.
		}//end try
	}//end setObjectServiceContext()

	/**
	 * Attempts to retrieve the OpenRegister service from the container.
	 *
	 * @return \OCA\OpenRegister\Service\FileService|null The OpenRegister service if available, null otherwise.
	 *
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface
	 *
	 * @spec exclude Lazy dependency-injection accessor — resolves the OpenRegister
	 *       FileService from the container; pure framework plumbing, no domain behavior.
	 */
	public function getFileService(): ?\OCA\OpenRegister\Service\FileService {
		if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
			$this->objectService = $this->container->get('OCA\OpenRegister\Service\FileService');

			return $this->objectService;
		}

		throw new RuntimeException('OpenRegister service is not available.');
	}//end getFileService()

	/**
	 * Resolve the configured catalog register/schema pair, failing CLOSED.
	 *
	 * Both callers below derive the instance's entire publication visibility
	 * scope from these two keys, so an unconfigured instance must refuse rather
	 * than search. The read and the check that makes it safe sit adjacent on
	 * purpose: separating them is what let both a reviewer and hydra gate-50
	 * read the site as unguarded.
	 *
	 * WHY THE EMPTY DEFAULT IS NOT SELF-CORRECTING. `''` is not the same as
	 * "absent". `MagicMapper` reads the pair with `??`, which only falls through
	 * on null, so `''` is captured as a *present* filter, passes the
	 * `!== null` guard, and is then cast — `find((int) '')` is `find(0)`, which
	 * resolves nothing. Today that happens to bail out to an empty result, so
	 * this is not a live data leak. But the refusal is BORROWED from another
	 * app's mapper rather than stated here, and an empty string is strictly
	 * worse than null on the paginated path, where the global-search fallbacks
	 * are gated on `register === null && schema === null` and so are silently
	 * suppressed by `''`. This method states the refusal locally instead.
	 *
	 * Empty arrays are the correct refusal rather than an exception, because
	 * that is already the contract callers handle: setObjectServiceContext()
	 * checks `empty($allowedRegisters) || empty($allowedSchemas)` and returns
	 * without querying, explicitly to refuse a platform-wide scan.
	 *
	 * @return array{register: string, schema: string}|null The configured pair,
	 *         or null when either key is unset — the caller must then refuse.
	 *
	 * @spec exclude Configuration plumbing — resolves and validates the two
	 *       app-config keys that scope every publication query.
	 */
	private function resolveCatalogScope(): ?array {
		$schema = $this->config->getValueString($this->appName, 'catalog_schema', '');
		$register = $this->config->getValueString($this->appName, 'catalog_register', '');
		if ($schema === '' || $register === '') {
			return null;
		}

		return [
			'register' => $register,
			'schema' => $schema,
		];
	}//end resolveCatalogScope()

	/**
	 * Collect the unique values of one array-valued property across catalogs.
	 *
	 * Extracted from getCatalogFilters() so the fail-closed guard added there
	 * does not push that method over PHPMD's NPath threshold (measured: 320
	 * against a limit of 200). Mirrors CatalogiService::collectUnique(), which
	 * was extracted for the same reason when the same guard landed there.
	 *
	 * A baseline entry would have been the wrong repair: it is scoped to
	 * (rule, file) and would licence every future violation of that rule in
	 * this file, including ones nobody has looked at.
	 *
	 * @param array<int, mixed> $catalogs The catalog objects returned by the search.
	 * @param string $property The array-valued property to collect ('registers' | 'schemas').
	 *
	 * @return array<int, mixed> The de-duplicated union of that property's values.
	 *
	 * @spec exclude Pure collection helper extracted from getCatalogFilters();
	 *       carries no behaviour of its own.
	 */
	private function collectUnique(array $catalogs, string $property): array {
		$collected = [];

		foreach ($catalogs as $catalog) {
			$catalog = $catalog->jsonSerialize();
			if (isset($catalog[$property]) === true && is_array($catalog[$property]) === true) {
				$collected = array_merge($collected, $catalog[$property]);
			}
		}

		return array_unique($collected);
	}//end collectUnique()

	/**
	 * Get register and schema combinations from catalogs.
	 *
	 * This method retrieves all catalogs (or a specific one if ID is provided),
	 * extracts their registers and schemas, and stores them as general variables.
	 *
	 * When the catalog register/schema pair is unconfigured this returns empty
	 * arrays without querying — see resolveCatalogScope() for why that refusal
	 * is stated here rather than left to the mapper.
	 *
	 * @param string|integer|null $catalogId Optional ID of a specific catalog to filter by
	 *
	 * @return array<string, array<string>> Array containing available registers and schemas
	 *
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface
	 *
	 * @spec openspec/specs/publications/spec.md
	 */
	public function getCatalogFilters(null|string|int $catalogId = null): array {
		// Create cache key based on catalog ID.
		$cacheKey = 'all';
		if ($catalogId !== null) {
			$cacheKey = (string)$catalogId;
		}

		// Return cached result if available.
		if (isset($this->cachedCatalogFilters[$cacheKey]) === true) {
			// Restore class properties from cache.
			$cached = $this->cachedCatalogFilters[$cacheKey];
			$this->availableRegisters = $cached['availableRegisters'];
			$this->availableSchemas = $cached['availableSchemas'];
			return $cached['result'];
		}

		// FAIL CLOSED on an unconfigured catalog scope. See resolveCatalogScope().
		$scope = $this->resolveCatalogScope();
		if ($scope === null) {
			$this->availableRegisters = [];
			$this->availableSchemas = [];
			return [
				'registers' => [],
				'schemas' => [],
			];
		}

		// Setup the config array for searchObjects.
		$query = [
			'@self' => [
				'register' => $scope['register'],
				'schema' => $scope['schema'],
			],
		];

		// Get all catalogs using searchObjects. Its declared return type is
		// `array|int` — it yields an int when the query asks for a count. This query
		// does not, but narrow explicitly so a count-shaped result can never reach
		// the foreach below as a non-iterable.
		$catalogs = $this->getObjectService()->searchObjects($query);
		if (is_array($catalogs) === false) {
			$catalogs = [];
		}

		if ($catalogId !== null) {
			$catalogs = [$this->getObjectService()->find($catalogId)];
		}

		// Remove duplicate values and assign to class properties.
		$this->availableRegisters = $this->collectUnique(catalogs: $catalogs, property: 'registers');
		$this->availableSchemas = $this->guardSchemaScope(
			schemas: $this->collectUnique(catalogs: $catalogs, property: 'schemas')
		);

		$result = [
			'registers' => array_values($this->availableRegisters),
			'schemas' => array_values($this->availableSchemas),
		];

		// Cache the result and class properties.
		$this->cachedCatalogFilters[$cacheKey] = [
			'result' => $result,
			'availableRegisters' => $this->availableRegisters,
			'availableSchemas' => $this->availableSchemas,
		];

		return $result;
	}//end getCatalogFilters()

	/**
	 * Get the list of available registers.
	 *
	 * @return array<string> List of available registers
	 */
	public function getAvailableRegisters(): array {
		return $this->availableRegisters;
	}//end getAvailableRegisters()

	/**
	 * Get the list of available schemas.
	 *
	 * @return array<string> List of available schemas
	 */
	public function getAvailableSchemas(): array {
		return $this->availableSchemas;
	}//end getAvailableSchemas()

	/**
	 * Generic method to search publications with catalog filtering and security
	 *
	 * This method provides a common interface for searching publications across all endpoints.
	 * It handles catalog context validation, security parameters, and consistent filtering.
	 *
	 * @param null|string|integer $catalogId Optional catalog ID to filter objects by
	 * @param array|null $ids Optional array of specific IDs to filter by
	 * @param array|null $customParams Optional custom parameters to use instead of request params
	 *
	 * @return array Array containing search results with pagination and facets
	 *
	 * @throws \InvalidArgumentException When invalid registers or schemas are requested
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface
	 *
	 * @spec openspec/specs/publications/spec.md
	 * @SuppressWarnings(PHPMD.StaticAccess) CallerScope::strip() is a pure function over the query (WOO-581)
	 */
	private function searchPublications(null|string|int $catalogId = null, ?array $ids = null, ?array $customParams = null): array {
		$searchQuery = $this->normaliseSearchQuery(query: ($customParams ?? $this->request->getParams()));

		// Directory and catalogs are virtual fields injected at runtime, not database
		// columns, so their facet requests never reach OpenRegister.
		$virtualFacets = $this->virtualFacetRequests(query: $searchQuery);
		$searchQuery = $this->withoutVirtualFacetRequests(query: $searchQuery);

		$context = $this->getCatalogFilters(catalogId: $catalogId);

		// FAIL CLOSED on an empty schema or register scope (WOO-581): an empty
		// `@self.schema` must never be read as "no schema filter", and no register
		// must never be read as "every register" (review round 4).
		if (empty($context['schemas']) === true || empty($context['registers']) === true) {
			return ['results' => [], 'facets' => [], 'total' => 0];
		}

		$scope = $this->narrowedSearchScope(context: $context, query: $searchQuery);
		$objectService = $this->getObjectService();

		// Only the (validated) narrowing above survives; every other scope key the
		// caller sent goes, or OR's facet path follows it (WOO-581 review round 2).
		// writeScope() keeps the schema a list unless the scope is one schema in one
		// register: a scalar schema next to a register list sends OR to register 1, or
		// past the scope to the all-tables `_ids` lookup (WOO-581 review round 3).
		$searchQuery = CallerScope::strip(query: $searchQuery);
		$searchQuery['_includeDeleted'] = false;

		// IDs filter for the uses/used lookups.
		if ($ids !== null && empty($ids) === false) {
			$searchQuery['_ids'] = $ids;
		}

		// _rbac: true applies the schema authorization rules; _multitenancy: false
		// allows cross-org access for public search results (RBAC rules determine
		// what each user/anonymous can see).
		$result = array_merge(
			['results' => [], 'facets' => []],
			$this->searchObjectsWithinScope(
				objectService: $objectService,
				query: $searchQuery,
				registers: $scope['register'],
				schemas: $scope['schema']
			)
		);

		// Visibility is governed entirely by OpenRegister RBAC (the schemas' conditional
		// public-read rules with the $now variable), applied above via _rbac: true.
		$result['results'] = $this->filterUnwantedProperties(objects: $result['results']);

		return $this->withVirtualFacets(result: $result, requested: $virtualFacets);
	}//end searchPublications()

	/**
	 * Map the legacy parameters to `_extend` and drop the route keys.
	 *
	 * Each of `extend`, `fields`, `facets`, `order`, `page` and `limit` that is set
	 * moves to `_extend`, the last one winning.
	 *
	 * @param array $query The request or custom parameters.
	 *
	 * @return array The normalised query.
	 *
	 * @todo this is a temporary fix to map the parameters to _extend format.
	 *
	 * @spec openspec/specs/publications/spec.md
	 */
	private function normaliseSearchQuery(array $query): array {
		foreach (['extend', 'fields', 'facets', 'order', 'page', 'limit'] as $param) {
			if (isset($query[$param]) === true) {
				$query['_extend'] = $query[$param];
				unset($query[$param]);
			}
		}

		unset($query['id']);
		unset($query['_route']);

		return $query;
	}//end normaliseSearchQuery()

	/**
	 * Which virtual-field facets (directory, catalogs) the query asks for.
	 *
	 * @param array $query The search query.
	 *
	 * @return array{directory: bool, catalogs: bool} The requested virtual facets.
	 *
	 * @spec openspec/specs/publications/spec.md
	 */
	private function virtualFacetRequests(array $query): array {
		return [
			'directory' => isset($query['_facets']['@self']['directory']),
			'catalogs' => isset($query['_facets']['@self']['catalogs']),
		];
	}//end virtualFacetRequests()

	/**
	 * The query without its virtual-field facet requests.
	 *
	 * @param array $query The search query.
	 *
	 * @return array The query OpenRegister can answer.
	 *
	 * @spec openspec/specs/publications/spec.md
	 */
	private function withoutVirtualFacetRequests(array $query): array {
		foreach (['directory', 'catalogs'] as $virtualField) {
			if (isset($query['_facets']['@self'][$virtualField]) === true) {
				unset($query['_facets']['@self'][$virtualField]);
			}
		}

		return $query;
	}//end withoutVirtualFacetRequests()

	/**
	 * The register and schema scope of a search, narrowed to what the caller asked for.
	 *
	 * A requested register or schema (one value or a list) must lie inside the
	 * catalog context; an empty request keeps the whole context.
	 *
	 * @param array $context The catalog context (`registers`, `schemas`).
	 * @param array $query The search query.
	 *
	 * @return array{register: array, schema: array} The scope to search.
	 *
	 * @throws InvalidArgumentException When a requested register or schema lies outside the context.
	 *
	 * @spec openspec/specs/publications/spec.md
	 */
	private function narrowedSearchScope(array $context, array $query): array {
		$scope = [
			'register' => $context['registers'],
			'schema' => $context['schemas'],
		];

		foreach ($scope as $key => $allowed) {
			$requested = ($query['@self'][$key] ?? []);
			if (empty($requested) === true) {
				continue;
			}

			if (is_array($requested) === false) {
				$requested = [$requested];
			}

			if (count(array_diff($requested, $allowed)) > 0) {
				throw new InvalidArgumentException('Invalid ' . $key . '(s) requested');
			}

			$scope[$key] = $requested;
		}

		return $scope;
	}//end narrowedSearchScope()

	/**
	 * Add the requested virtual-field facets to a search result.
	 *
	 * @param array $result The search result.
	 * @param array{directory: bool, catalogs: bool} $requested The requested virtual facets.
	 *
	 * @return array The result, with the virtual facets when any were requested.
	 *
	 * @spec openspec/specs/publications/spec.md
	 */
	private function withVirtualFacets(array $result, array $requested): array {
		if (($requested['directory'] === true || $requested['catalogs'] === true) && isset($result['facets']) === true) {
			$result['facets'] = $this->addVirtualFieldFacets(
				existingFacets: $result['facets'],
				inclDirFacets: $requested['directory'],
				inclCatFacets: $requested['catalogs']
			);
		}

		return $result;
	}//end withVirtualFacets()

	/**
	 * Search OpenRegister within the catalogue scope, leaving out the slugs it refuses.
	 *
	 * A seeded catalogue names its register and schemas by slug until the import
	 * that owns them has run; CatalogScopeSlugResolver keeps an unresolved slug
	 * so a later import can finish the scope. Such an entry matches nothing (the
	 * scope check reads it through intval), and since openregister#3996
	 * OpenRegister refuses an unknown register or schema reference on the read
	 * path instead of answering an empty page. So one unresolved catalogue — the
	 * Applicatielandschap seed on an instance without stackiq — turned every
	 * federated search into a 500 (WOO-585). On that refusal the slugs leave the
	 * scope for this search and it runs once more over the ids; the catalogue
	 * keeps its slugs for the next import. A scope with nothing left is the empty
	 * page, as everywhere else here (WOO-581, fail closed). A refusal of a scope
	 * that holds ids only travels on: OpenRegister searches an unknown id and
	 * finds nothing, so what it refuses in such a scope (a `'0'`, a negative
	 * number) is not a pending seed. The per-catalogue readers of the same stored
	 * scope — CatalogiService::index(), and buildCatalogSearchQuery() in
	 * PublicationQueryService and CatalogScopeIds::normalizeIds() — do not have this yet
	 * (ConductionNL/opencatalogi#1783).
	 *
	 * @param \OCA\OpenRegister\Service\ObjectService $objectService The object service.
	 * @param array<string, mixed>                    $query         The query, stripped of the caller's scope keys.
	 * @param array<int, mixed>                       $registers     The register scope.
	 * @param array<int, mixed>                       $schemas       The schema scope.
	 *
	 * @return array<string, mixed> The paginated result.
	 *
	 * @throws RegisterNotFoundException When OpenRegister refuses a non-slug register entry (such as `'0'`);
	 *                                   a plain unknown id is searched, not refused.
	 * @throws SchemaNotFoundException   When OpenRegister refuses a non-slug schema entry the same way.
	 *
	 * @spec openspec/specs/federation/spec.md
	 * @spec openspec/changes/publish-from-stackiq/specs/publish-from-stackiq/spec.md#requirement-req-pfs-001-opencatalogi-seeds-an-unpublished-applicatielandschap-catalogue-over-stackiq
	 * @SuppressWarnings(PHPMD.StaticAccess) CallerScope::writeScope() is a pure function over the query (WOO-581)
	 */
	private function searchObjectsWithinScope($objectService, array $query, array $registers, array $schemas): array {
		try {
			return $objectService->searchObjectsPaginated(
				query: CallerScope::writeScope(query: $query, registers: $registers, schemas: $schemas),
				_rbac: true,
				_multitenancy: false
			);
		} catch (RegisterNotFoundException | SchemaNotFoundException $e) {
			$registerIds = $this->withoutSlugs(entries: $registers);
			$schemaIds = $this->withoutSlugs(entries: $schemas);
			if (count($registerIds) === count($registers) && count($schemaIds) === count($schemas)) {
				throw $e;
			}

			if ($registerIds === [] || $schemaIds === []) {
				return ['results' => [], 'facets' => [], 'total' => 0];
			}

			return $objectService->searchObjectsPaginated(
				query: CallerScope::writeScope(query: $query, registers: $registerIds, schemas: $schemaIds),
				_rbac: true,
				_multitenancy: false
			);
		}//end try
	}//end searchObjectsWithinScope()

	/**
	 * The entries of a scope list that are ids: ints and numeric strings.
	 *
	 * A non-numeric string is a slug, as {@see CatalogScopeSlugResolver::hasSlug()}
	 * reads it. An empty string goes too: OpenRegister reads `''` as "no register
	 * filter", and the retry has to stay a list of ids.
	 *
	 * @param array<int, mixed> $entries The scope list as stored on the catalogues.
	 *
	 * @return array<int, mixed> The list without its slugs, re-indexed.
	 *
	 * @spec exclude Pure list filter for searchObjectsWithinScope(); carries no behaviour of its own.
	 */
	private function withoutSlugs(array $entries): array {
		return array_values(
			array_filter(
				$entries,
				static fn (mixed $entry): bool => is_string($entry) === false || is_numeric($entry) === true
			)
		);
	}//end withoutSlugs()

	/**
	 * Get external catalogs from listings stored in the directory service
	 *
	 * This method retrieves all listings and extracts catalog information from them
	 * to provide catalog facets that include external/federated catalogs.
	 *
	 * @return array Array of catalog objects with 'key' and 'label' fields
	 *
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	private function getExternalCatalogsFromListings(): array {
		try {
			// Get the directory (which includes listings).
			$directoryResult = $this->directoryService->getDirectory();
			$listings = ($directoryResult['results'] ?? []);

			$externalCatalogs = [];
			$seenCatalogs = [];

			foreach ($listings as $listing) {
				// Extract catalog information from listing.
				$catalogId = ($listing['catalog'] ?? $listing['id'] ?? null);
				$catalogTitle = ($listing['title'] ?? $catalogId ?? 'Unknown Catalog');

				if ($catalogId !== null && isset($seenCatalogs[$catalogId]) === false) {
					$externalCatalogs[] = [
						'key' => $catalogId,
						'label' => $catalogTitle,
					];
					$seenCatalogs[$catalogId] = true;
				}
			}

			return $externalCatalogs;
		} catch (\Exception $e) {
			// If we can't get external catalog information, return empty array.
			return [];
		}//end try

	}//end getExternalCatalogsFromListings()

	/**
	 * Add virtual field facets manually (directory and catalog facets)
	 *
	 * This method handles faceting for virtual fields that are injected at runtime
	 * rather than stored as real database columns. It supports directory and catalog facets.
	 *
	 * @param array $existingFacets The existing facets from the search result
	 * @param boolean $inclDirFacets Whether to include directory facets
	 * @param boolean $inclCatFacets Whether to include catalog facets
	 *
	 * @return array The facets with virtual field facets added
	 *
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	private function addVirtualFieldFacets(
		array $existingFacets,
		bool $inclDirFacets = false,
		bool $inclCatFacets = false,
	): array {
		try {
			// Ensure @self section exists.
			if (isset($existingFacets['@self']) === false) {
				$existingFacets['@self'] = [];
			}

			if ($inclDirFacets === true) {
				$existingFacets['@self']['directory'] = [
					'type' => 'terms',
					'buckets' => $this->directoryFacetBuckets(),
				];
			}

			if ($inclCatFacets === true) {
				$existingFacets['@self']['catalogs'] = [
					'type' => 'terms',
					'buckets' => $this->catalogFacetBuckets(),
				];
			}

			return $existingFacets;
		} catch (\Exception $e) {
			// If we can't get virtual field information, return existing facets unchanged.
			return $existingFacets;
		}//end try

	}//end addVirtualFieldFacets()

	/**
	 * The directory facet buckets: the local directory, then each federated
	 * directory by host name.
	 *
	 * @return array<int, array{key: string, label: string, results: int}> The buckets.
	 *
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	private function directoryFacetBuckets(): array {
		// Add local directory.
		$directoryBuckets = [
			[
				'key' => 'local',
				'label' => 'local',
				'results' => 1,
			],
		];

		// Add federated directories.
		foreach ($this->directoryService->getUniqueDirectories(availableOnly: true) as $directoryUrl) {
			$directoryName = parse_url($directoryUrl, PHP_URL_HOST);
			if (empty($directoryName) === true) {
				$directoryName = $directoryUrl;
			}

			$directoryBuckets[] = [
				'key' => $directoryName,
				'label' => $directoryName,
				'results' => 1,
			];
		}

		return $directoryBuckets;

	}//end directoryFacetBuckets()

	/**
	 * The catalog facet buckets: the local catalogs, then the external catalogs
	 * from the directory listings that are not local, or one default bucket when
	 * there are none.
	 *
	 * @return array<int, array{key: mixed, label: mixed, results: int}> The buckets.
	 *
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	private function catalogFacetBuckets(): array {
		$catalogBuckets = array_map(
			fn ($catalog): array => $this->localCatalogBucket(catalog: $catalog),
			$this->getLocalCatalogs()
		);

		foreach ($this->getExternalCatalogsFromListings() as $catalog) {
			// Skip a catalog that is already in the list.
			if (in_array($catalog['key'], array_column($catalogBuckets, 'key'), true) === false) {
				$catalogBuckets[] = [
					'key' => $catalog['key'],
					'label' => $catalog['label'],
					'results' => 1,
				];
			}
		}

		// If no catalogs found, add a default entry.
		if (empty($catalogBuckets) === true) {
			$catalogBuckets[] = [
				'key' => 'default',
				'label' => 'Default Catalog',
				'results' => 1,
			];
		}

		return $catalogBuckets;

	}//end catalogFacetBuckets()

	/**
	 * The facet bucket of one local catalog: keyed by id (else title), labelled
	 * by title (else id), `unknown` when it has neither.
	 *
	 * @param mixed $catalog The local catalog.
	 *
	 * @return array{key: mixed, label: mixed, results: int} The bucket.
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	private function localCatalogBucket(mixed $catalog): array {
		$catalogKey = 'unknown';
		if (empty($catalog['id']) === false) {
			$catalogKey = $catalog['id'];
		} elseif (empty($catalog['title']) === false) {
			$catalogKey = $catalog['title'];
		}

		$catalogLabel = 'unknown';
		if (empty($catalog['title']) === false) {
			$catalogLabel = $catalog['title'];
		} elseif (empty($catalog['id']) === false) {
			$catalogLabel = $catalog['id'];
		}

		return [
			'key' => $catalogKey,
			'label' => $catalogLabel,
			'results' => 1,
		];

	}//end localCatalogBucket()

	/**
	 * Retrieves a list of all objects for a specific register and schema
	 *
	 * This method returns a paginated list of objects that match the specified register and schema.
	 * It supports filtering, sorting, and pagination through query parameters using the new search structure.
	 *
	 * @param null|string|integer $catalogId Optional catalog ID to filter objects by
	 * @param array|null $customParams Optional custom parameters to use instead of request params
	 *
	 * @return JSONResponse A JSON response containing the list of objects
	 *
	 * @spec openspec/specs/publications/spec.md
	 */
	public function index(null|string|int $catalogId = null, ?array $customParams = null): JSONResponse {
		try {
			$result = $this->searchPublications(catalogId: $catalogId, ids: null, customParams: $customParams);
			return new JSONResponse($result);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], 400);
		}

	}//end index()

	/**
	 * Shows a specific object from a register and schema
	 *
	 * Retrieves and returns a single object from the specified register and schema,
	 * with support for field filtering and related object extension.
	 *
	 * @param string $id The object ID
	 *
	 * @return JSONResponse A JSON response containing the object
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/publications/spec.md
	 */
	public function show(string $id): JSONResponse {
		// Catalog-scope gate (#855): verify the requested object belongs to one of the
		// catalogs configured on this instance before rendering it.
		//
		// The catalog-scoped PublicationsController::show() applies its own check (#733),
		// but this method is also reached from the generic /api/search/{id} route and from
		// the MCP tool provider, neither of which passes through that controller. Without
		// this gate, find() below is called with no register and no schema, so
		// OpenRegister falls back to findAcrossAllMagicTables() and resolves the UUID in
		// ANY register on the instance — and OpenRegister grants read by default to any
		// authenticated user on a schema that declares no `authorization` block.
		// Measured on a live instance: a plain account in no groups read objects from the
		// OpenConnector, LarpingApp and Portaliq registers through this endpoint.
		if ($this->isObjectInCatalogScope(objectId: $id) === false) {
			return new JSONResponse(['error' => 'Not Found'], 404);
		}

		// Get request parameters for filtering and searching.
		$requestParams = $this->request->getParams();

		$extend = ($requestParams['extend'] ?? $requestParams['_extend'] ?? []);
		// Normalize to array.
		if (is_array($extend) === false) {
			$extend = [$extend];
		}

		// Filter only values that start with '@self.' and cap breadth (#732).
		$filtered = array_filter(
			$extend,
			static fn ($val) => is_string($val) === true && str_starts_with($val, '@self.') === true
		);
		// Deduplicate before capping so duplicate entries cannot inflate the cap.
		$extend = array_values(array_unique($filtered));
		if (count($extend) > 5) {
			$extend = array_slice($extend, 0, 5);
		}

		try {
			// Render the object with requested extensions and filters.
			// Use positional parameters for compatibility with different ObjectService versions.
			// find() enforces OpenRegister RBAC: anonymous callers only receive objects the
			// public group may read (e.g. published publications via the $now conditional rule).
			$object = $this->getObjectService()->find($id, $extend);

			return new JSONResponse($object);
		} catch (DoesNotExistException $exception) {
			return new JSONResponse(['error' => 'Not Found'], 404);
		}//end try

	}//end show()

	/**
	 * Determine whether an object (by ID) belongs to any catalog scope configured on
	 * this instance.
	 *
	 * Objects that are not in the register/schema of any configured catalog must not be
	 * served via the public-facing publication endpoints (C-1 / wave-7). When no catalog
	 * scope is configured (empty registers/schemas), the method returns false — an
	 * unconfigured instance may not serve arbitrary objects.
	 *
	 * @param string $objectId UUID of the object to check.
	 *
	 * @return bool True when the object's register/schema is covered by a local catalog.
	 */
	private function isObjectInCatalogScope(string $objectId): bool {
		try {
			$context = $this->getCatalogFilters();
			$allowedRegisters = array_map('intval', $context['registers']);
			$allowedSchemas = array_map('intval', $context['schemas']);

			if (empty($allowedRegisters) === true || empty($allowedSchemas) === true) {
				return false;
			}

			$location = $this->getQueryService()->findObjectLocation(
				uuid: $objectId,
				allowedRegisters: $allowedRegisters,
				allowedSchemas: $allowedSchemas
			);

			return $location !== null;
		} catch (\Exception $e) {
			// Fail closed — if we cannot verify scope, deny.
			return false;
		}//end try

	}//end isObjectInCatalogScope()

	/**
	 * Shows attachments of a publication
	 *
	 * Retrieves and returns attachments of a publication using code from OpenRegister.
	 *
	 * @param string $id The object ID
	 *
	 * @return JSONResponse A JSON response containing attachments
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/publications/spec.md
	 */
	public function attachments(string $id): JSONResponse {
		// Catalog-scope gate (C-1 / wave-7): verify the requested object belongs to one
		// of the catalogs configured on this instance before serving any file list.
		// This prevents anonymous callers from retrieving attachments of objects that are
		// outside this OpenCatalogi installation's configured namespace.
		if ($this->isObjectInCatalogScope(objectId: $id) === false) {
			return new JSONResponse(['error' => 'Not Found'], 404);
		}

		// Set register/schema context so find() can locate the object in its magic table.
		$objectService = $this->getObjectService();
		$this->setObjectServiceContext(objectService: $objectService, objectId: $id);

		// Validate existence and enforce RBAC: find() denies objects the caller may not read
		// (anonymous callers are limited to public-group reads).
		$object = $objectService->find(id: $id, _extend: []);
		if ($object === null) {
			return new JSONResponse(['error' => 'Not Found'], 404);
		}

		$fileService = $this->getFileService();

		try {
			// Get the raw files from the file service.
			$files = $fileService->getFiles(object: $id, sharedFilesOnly: true);

			// Format the files with pagination using request parameters.
			$formattedFiles = $fileService->formatFiles($files, $this->request->getParams());

			return new JSONResponse($formattedFiles);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['error' => 'Object not found'], 404);
		} catch (NotFoundException $e) {
			return new JSONResponse(['error' => 'Files folder not found'], 404);
		} catch (Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], 500);
		}//end try

	}//end attachments()

	/**
	 * Download all files of an object as a ZIP archive
	 *
	 * This method creates a ZIP file containing all files associated with a specific object
	 * and returns it as a downloadable file. The ZIP file includes all files stored in the
	 * object's folder with their original names.
	 *
	 * @param string $id The identifier of the object to download files for
	 *
	 * @return DataDownloadResponse|JSONResponse ZIP file download response or error response
	 *
	 * @throws ContainerExceptionInterface If there's an issue with dependency injection
	 * @throws NotFoundExceptionInterface If the FileService dependency is not found
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 *
	 * @spec openspec/specs/publications/spec.md
	 */
	public function download(
		string $id,
	): DataDownloadResponse|JSONResponse {
		// Catalog-scope gate (C-1 / wave-7): verify the requested object belongs to one
		// of the catalogs configured on this instance before serving any file content.
		// This prevents anonymous callers from downloading files for objects outside
		// this OpenCatalogi installation's configured namespace.
		if ($this->isObjectInCatalogScope(objectId: $id) === false) {
			return new JSONResponse(['error' => 'Not Found'], 404);
		}

		try {
			// Set register/schema context so find() can locate the object in its magic table.
			$objectService = $this->getObjectService();
			$this->setObjectServiceContext(objectService: $objectService, objectId: $id);

			// Validate existence and enforce RBAC before serving any file content: find()
			// denies objects the caller may not read (anonymous → public-group reads only).
			$object = $objectService->find(id: $id, _extend: []);
			if ($object === null) {
				return new JSONResponse(['error' => 'Not Found'], 404);
			}

			// Create the ZIP archive.
			$fileService = $this->getFileService();
			$zipInfo = $fileService->createObjectFilesZip($id);

			// Render the publication metadata PDF and pipe it into the ZIP alongside the
			// attachments streamed from OR's file service (DWN-OR-001 / DWN-OR-003).
			$metadataPdf = $this->downloadService->createPublicationFile(
				objectService: $objectService,
				id: $id,
				options: [
					'download' => true,
					'publication' => $object->jsonSerialize(),
				]
			);

			if ($metadataPdf instanceof JSONResponse) {
				if (file_exists($zipInfo['path']) === true) {
					unlink($zipInfo['path']);
				}

				return $metadataPdf;
			}

			$this->addFileToZip(
				zipPath: $zipInfo['path'],
				entryName: $metadataPdf['filename'],
				content: $metadataPdf['content']
			);

			// Read the ZIP file content.
			$zipContent = file_get_contents($zipInfo['path']);
			if ($zipContent === false) {
				// Clean up temporary file.
				if (file_exists($zipInfo['path']) === true) {
					unlink($zipInfo['path']);
				}

				throw new Exception('Failed to read ZIP file content');
			}

			// Clean up temporary file after reading.
			if (file_exists($zipInfo['path']) === true) {
				unlink($zipInfo['path']);
			}

			// Return the ZIP file as a download response.
			return new DataDownloadResponse(
				$zipContent,
				$zipInfo['filename'],
				$zipInfo['mimeType']
			);
		} catch (DoesNotExistException $exception) {
			return new JSONResponse(['error' => 'Object not found'], 404);
		} catch (\Exception $exception) {
			// Log the exception server-side but return a generic body to the caller
			// so that internal stack traces and file paths are never exposed (#735, H3).
			return new JSONResponse(
				['error' => 'Failed to create download archive'],
				500
			);
		}//end try

	}//end download()

	/**
	 * Add an in-memory artefact to an existing ZIP archive.
	 *
	 * Used to pipe the rendered publication metadata PDF into the attachment ZIP
	 * produced by OpenRegister's file service, so the archive matches the structure
	 * required by DWN-OR-001 (metadata PDF at the root, attachments under Bijlagen/).
	 *
	 * @param string $zipPath Absolute path of the ZIP archive to add to.
	 * @param string $entryName The entry name to write inside the archive.
	 * @param string $content The raw bytes to store under that entry.
	 *
	 * @return void
	 *
	 * @throws Exception When the archive cannot be opened or the entry cannot be added.
	 *
	 * @spec openspec/specs/download-service/spec.md
	 */
	private function addFileToZip(string $zipPath, string $entryName, string $content): void {
		$zip = new ZipArchive();
		if ($zip->open($zipPath) !== true) {
			throw new Exception('Failed to open ZIP archive to add the publication metadata PDF');
		}

		if ($zip->addFromString($entryName, $content) === false) {
			$zip->close();
			throw new Exception('Failed to add the publication metadata PDF to the ZIP archive');
		}

		$zip->close();

	}//end addFileToZip()

	/**
	 * Filter out unwanted properties from objects
	 *
	 * This method removes unwanted properties from the '@self' array in each object.
	 * It ensures consistent object structure across all endpoints. Additionally, it checks
	 * for a 'files' property within '@self' and ensures each file has a 'published' property.
	 * Files without a 'published' property are removed.
	 *
	 * Authenticated-read-parity (CAT-AUTH-001): the strip only applies to anonymous
	 * (logged-out) callers. OpenRegister RBAC already decided which objects the caller
	 * may read (via `_rbac: true` on the delegated search); this method only governs
	 * envelope metadata richness. Authenticated callers receive every `@self` property
	 * OpenRegister provides, unmodified.
	 *
	 * @param array $objects Array of objects to filter
	 *
	 * @return array Filtered array of objects
	 *
	 * @spec openspec/specs/publications/spec.md
	 * @spec openspec/changes/authenticated-read-parity/specs/catalogs/spec.md
	 */
	private function filterUnwantedProperties(array $objects): array {
		// Single source of truth for the anonymous strip list (authenticated-read-parity,
		// CAT-AUTH-001) — see CatalogiService::UNWANTED_SELF_PROPERTIES.
		$unwantedProperties = CatalogiService::UNWANTED_SELF_PROPERTIES;
		$isAnonymous = ($this->userSession === null || $this->userSession->getUser() === null);

		// Filter each object.
		return array_map(
			function ($object) use ($unwantedProperties, $isAnonymous) {
				// The OR SOLR backend returns array shapes (not ObjectEntity instances)
				// from searchObjectsPaginated; the magic-mapper backend returns entities.
				// Guard so we do not fatal with "Call to a member function jsonSerialize()
				// on array" under SOLR (#736), mirroring the dual-shape handling that
				// already exists in PublicationsController::index.
				$objectArray = $object;
				if (is_array($object) === false) {
					$objectArray = $object->jsonSerialize();
				}

				if (isset($objectArray['@self']) === true && is_array($objectArray['@self']) === true) {
					// Remove unwanted properties from the '@self' array — anonymous callers
					// only (authenticated-read-parity, CAT-AUTH-001). This governs envelope
					// metadata richness only.
					if ($isAnonymous === true) {
						$objectArray['@self'] = array_diff_key($objectArray['@self'], array_flip($unwantedProperties));
					}

					// Check for 'files' property and filter files without 'published'.
					// Unrelated to session/metadata richness — unchanged, applies to both
					// audiences, same as before this change.
					if (isset($objectArray['@self']['files']) === true
						&& is_array($objectArray['@self']['files']) === true
					) {
						$objectArray['@self']['files'] = array_filter(
							$objectArray['@self']['files'],
							function ($file) {
								return isset($file['published']) === true;
							}
						);
					}
				}//end if

				return $objectArray;
			},
			$objects
		);

	}//end filterUnwantedProperties()

	/**
	 * Retrieves all objects that this publication references
	 *
	 * This method returns all objects that this publication
	 * uses/references. A -> B means that A (This publication)
	 * references B (Another object).
	 *
	 * @param string $id The ID of the publication to retrieve
	 *                   relations for
	 *
	 * @return JSONResponse A JSON response containing the related objects
	 *
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface
	 *
	 * @spec openspec/specs/publications/spec.md
	 */
	public function uses(string $id): JSONResponse {
		// Catalog-scope gate (#855): the relation traversal below reads the root object
		// itself, so an out-of-scope UUID must be refused here rather than distinguished
		// by its response. Without this gate an existing foreign object answers 200 and a
		// non-existent one answers 500, which is a cross-register existence oracle.
		if ($this->isObjectInCatalogScope(objectId: $id) === false) {
			return new JSONResponse(['error' => 'Not Found'], 404);
		}

		try {
			// Get the object service.
			$objectService = $this->getObjectService();

			// Set register/schema context so the object can be found in magic tables.
			$this->setObjectServiceContext(objectService: $objectService, objectId: $id);

			// The find() call enforces OpenRegister RBAC: an anonymous caller can only
			// traverse the relation graph of objects the public group may read (C-3 / wave-7).
			$rootObject = $objectService->find(id: $id, _extend: []);

			// Object not found or not readable by the caller — return 404 before traversal.
			if ($rootObject === null) {
				return new JSONResponse(['error' => 'Not Found'], 404);
			}

			// Get the relations for the object.
			// $rootObject may be an array (SOLR backend) or an entity (magic-mapper).
			// getRelations() is only available on entity objects.
			$relationsArray = $rootObject->getRelations();
			if (is_array($rootObject) === true) {
				$relationsArray = $rootObject['@self']['relations'] ?? $rootObject['relations'] ?? [];
			}

			$relations = array_values($relationsArray);

			return $this->relatedPublications(relations: $relations);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], 400);
		}//end try

	}//end uses()

	/**
	 * Retrieves all objects that use this publication
	 *
	 * This method returns all objects that reference (use) this
	 * publication. B -> A means that B (Another object) references
	 * A (This publication).
	 *
	 * @param string $id The ID of the publication to retrieve
	 *                   uses for
	 *
	 * @return JSONResponse A JSON response containing the referenced objects
	 *
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface
	 *
	 * @spec openspec/specs/publications/spec.md
	 */
	public function used(string $id): JSONResponse {
		// Catalog-scope gate (#855): same reasoning as uses() — refuse an out-of-scope
		// UUID before the root object is read, so that "exists elsewhere on this
		// instance" and "does not exist" are indistinguishable to the caller.
		if ($this->isObjectInCatalogScope(objectId: $id) === false) {
			return new JSONResponse(['error' => 'Not Found'], 404);
		}

		try {
			// Get the object service.
			$objectService = $this->getObjectService();

			// Set register/schema context so the object can be found in magic tables.
			$this->setObjectServiceContext(objectService: $objectService, objectId: $id);

			// The find() call enforces OpenRegister RBAC: an anonymous caller can only
			// traverse the relation graph of objects the public group may read (C-3 / wave-7).
			$rootObject = $objectService->find(id: $id, _extend: []);

			// Object not found or not readable by the caller — return 404 before traversal.
			if ($rootObject === null) {
				return new JSONResponse(['error' => 'Not Found'], 404);
			}

			// Get the relations for the object.
			$relationsArray = $objectService->findByRelations($id);
			$relations = array_map(static fn ($relation) => $relation->getUuid(), $relationsArray);

			return $this->relatedPublications(relations: $relations);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], 400);
		}//end try

	}//end used()

	/**
	 * The publications among the given related object ids, as one page.
	 *
	 * @param array<int, string> $relations The related object ids.
	 *
	 * @return JSONResponse The search result, or an empty page when there are no relations.
	 *
	 * @throws \InvalidArgumentException When the search refuses its arguments.
	 *
	 * @spec openspec/specs/publications/spec.md
	 */
	private function relatedPublications(array $relations): JSONResponse {
		if (empty($relations) === true) {
			return new JSONResponse(['results' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'facets' => []]);
		}

		return new JSONResponse($this->searchPublications(catalogId: null, ids: $relations));
	}//end relatedPublications()

	/**
	 * Get aggregated publications from local and federated sources
	 *
	 * This method handles both local and aggregated search results when the _aggregate
	 * parameter is not set to false. It supports faceting when _facetable parameter is provided.
	 *
	 * PERFORMANCE OPTIMIZATIONS:
	 * - Fast path for single catalog scenarios (no federation overhead)
	 * - Optional catalog information via _include_catalogs parameter
	 * - Cached expensive operations to reduce database queries
	 * - Optimized timeouts for better responsiveness
	 *
	 * AGGREGATION FEATURES:
	 * - Proper pagination: Collects sufficient data from all sources, merges and deduplicates,
	 *   then applies pagination to ensure consistent totals and page counts
	 * - Ordering: Supports _order parameters like _order[@self.created]=DESC to sort the
	 *   combined dataset from all sources according to specified criteria
	 * - Deduplication: Removes duplicate entries based on object ID across all sources
	 * - Faceting: Merges facet data from multiple sources when _facetable=true
	 *
	 * @param array $queryParams Query parameters for filtering, pagination, ordering, etc.
	 * @param array $requestParams Original request parameters for building pagination links
	 * @param string $baseUrl Base URL for building pagination links
	 *
	 * @return array Response data containing publications, pagination info, and optionally facets
	 *
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	public function getAggregatedPublications(array $queryParams = [], array $requestParams = [], string $baseUrl = ''): array {
		$startTime = microtime(true);
		$context = $this->buildListingContext(queryParams: $queryParams, requestParams: $requestParams, baseUrl: $baseUrl);
		$queryParams = $this->withPagination(queryParams: $queryParams, pagination: $context);

		if ($this->hasFederatedSources(aggregate: $context['aggregate']) === false) {
			return $this->getLocalPublications(queryParams: $queryParams, context: $context);
		}

		// The local-only page is the answer when the federated sources fail.
		$localData = $this->fetchLocalPublicationData(queryParams: $queryParams);
		$responseData = $this->buildLocalAggregateListing(localData: $localData, context: $context);

		try {
			$responseData = $this->buildFederatedListing(queryParams: $queryParams, localTotal: ($localData['total'] ?? 0), context: $context);
			$responseData['_performance'] = $this->aggregationPerformance(startTime: $startTime, federated: true);
		} catch (\Exception $e) {
			$responseData['_performance'] = $this->aggregationPerformance(startTime: $startTime, federated: false);
		}

		return $responseData;
	}//end getAggregatedPublications()

	/**
	 * Resolve the clamped pagination and the switches of a listing request.
	 *
	 * @param array  $queryParams   The request query.
	 * @param array  $requestParams The original request parameters, for the pagination links.
	 * @param string $baseUrl       The base URL of the pagination links.
	 *
	 * @return array Limit, page, offset, the listing switches, requestParams and baseUrl.
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	private function buildListingContext(array $queryParams, array $requestParams, string $baseUrl): array {
		$pagination = $this->readPagination(queryParams: $queryParams);

		return [
			'limit' => max(0, $pagination['limit']),
			'page' => max(1, $pagination['page']),
			'offset' => max(0, $pagination['offset']),
			'requestParams' => $requestParams,
			'baseUrl' => $baseUrl,
		] + $this->readListingOptions(queryParams: $queryParams);
	}//end buildListingContext()

	/**
	 * The local page of an aggregated listing, its results stamped as local.
	 *
	 * @param array $localData The decoded local index response.
	 * @param array $context   Pagination, listing switches, request params and base URL.
	 *
	 * @return array The listing response.
	 *
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	private function buildLocalAggregateListing(array $localData, array $context): array {
		$responseData = $this->buildListingPage(
			results: $this->tagLocalResults(results: ($localData['results'] ?? []), markDirectory: true, withCatalogs: true),
			total: ($localData['total'] ?? 0),
			offset: $context['offset'],
			context: $context
		);
		$responseData = $this->withListingFacets(responseData: $responseData, facets: $this->unwrapFacetsOf(data: $localData), context: $context);

		return $this->withMergedFacetable(responseData: $responseData, sources: [$localData, []], include: $context['includeFacets']);
	}//end buildLocalAggregateListing()

	/**
	 * Read the limit, page and offset of a listing request, unclamped.
	 *
	 * @param array $queryParams The request query.
	 *
	 * @return array{limit: int, page: int, offset: int}
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	private function readPagination(array $queryParams): array {
		$limit = (int)($queryParams['_limit'] ?? $queryParams['limit'] ?? 20);
		$page = (int)($queryParams['_page'] ?? $queryParams['page'] ?? 1);

		return [
			'limit' => $limit,
			'page' => $page,
			'offset' => (int)($queryParams['offset'] ?? (($page - 1) * $limit)),
		];
	}//end readPagination()

	/**
	 * Write the resolved pagination back into the query as `_limit`, `_page` and `_offset`.
	 *
	 * @param array $queryParams The request query.
	 * @param array $pagination  The resolved limit, page and offset.
	 *
	 * @return array The query with its pagination keys set.
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	private function withPagination(array $queryParams, array $pagination): array {
		$queryParams['_limit'] = $pagination['limit'];
		$queryParams['_page'] = $pagination['page'];
		if ($pagination['offset'] > 0) {
			$queryParams['_offset'] = $pagination['offset'];
		}

		return $queryParams;
	}//end withPagination()

	/**
	 * Read the switches of a listing request: aggregation, catalog info, facets and the ultra-fast path.
	 *
	 * @param array $queryParams The request query.
	 *
	 * @return array<string, bool>
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	private function readListingOptions(array $queryParams): array {
		$aggregate = (string)($queryParams['_aggregate'] ?? 'true');
		$facetable = $queryParams['_facetable'] ?? null;
		$options = [
			'aggregate' => $aggregate !== 'false' && $aggregate !== '0',
			'includeCatalogs' => isset($queryParams['_include_catalogs']) && $queryParams['_include_catalogs'] !== 'false',
			'includeFacets' => ($facetable === 'true' || $facetable === true),
			'reqDirFacets' => isset($queryParams['_facets']['@self']['directory']),
			'reqCatFacets' => isset($queryParams['_facets']['@self']['catalogs']),
		];
		$options['useUltraFast'] = $this->readUltraFastSwitch(queryParams: $queryParams, options: $options);

		return $options;
	}//end readListingOptions()

	/**
	 * Decide the ultra-fast path: on when nothing needs local enrichment, unless `_ultra_fast` says otherwise.
	 *
	 * @param array $queryParams The request query.
	 * @param array $options     The other listing switches.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	private function readUltraFastSwitch(array $queryParams, array $options): bool {
		if (isset($queryParams['_ultra_fast']) === true) {
			return $queryParams['_ultra_fast'] !== 'false' && $queryParams['_ultra_fast'] !== '0';
		}

		return !$options['includeCatalogs'] && !$options['reqDirFacets'] && !$options['reqCatFacets'];
	}//end readUltraFastSwitch()

	/**
	 * Whether a listing should aggregate: asked for, and at least one federated directory is available.
	 *
	 * A failing directory lookup counts as no directories.
	 *
	 * @param bool $aggregate Whether the request asked for aggregation.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	private function hasFederatedSources(bool $aggregate): bool {
		if ($aggregate === false) {
			return false;
		}

		try {
			return empty($this->directoryService->getUniqueDirectories(availableOnly: true)) === false;
		} catch (\Exception $e) {
			return false;
		}
	}//end hasFederatedSources()

	/**
	 * Serve a listing from local publications only, on the ultra-fast or the fast path.
	 *
	 * @param array $queryParams The paginated request query.
	 * @param array $context     Pagination, listing switches, request params and base URL.
	 *
	 * @return array The listing response.
	 *
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	private function getLocalPublications(array $queryParams, array $context): array {
		if ($context['useUltraFast'] === true) {
			return $this->getLocalPublicationsUltraFast(
				queryParams: $queryParams,
				requestParams: $context['requestParams'],
				baseUrl: $context['baseUrl'],
				startTime: microtime(true)
			);
		}

		return $this->getLocalPublicationsFast(queryParams: $queryParams, context: $context);
	}//end getLocalPublications()

	/**
	 * Run the local index for a query and decode its response.
	 *
	 * @param array $queryParams The query to pass to index().
	 *
	 * @return array The decoded response data.
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	private function fetchLocalPublicationData(array $queryParams): array {
		$response = $this->index(catalogId: null, customParams: $queryParams);

		return (json_decode($response->render(), true) ?? []);
	}//end fetchLocalPublicationData()

	/**
	 * Stamp local results with their origin: `@self.directory = local` and the local catalogs.
	 *
	 * @param array $results       The local results.
	 * @param bool  $markDirectory Whether to set `@self.directory`.
	 * @param bool  $withCatalogs  Whether to set `@self.catalogs` (only when there are local catalogs).
	 *
	 * @return array The stamped results.
	 *
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	private function tagLocalResults(array $results, bool $markDirectory, bool $withCatalogs): array {
		if (empty($results) === true) {
			return $results;
		}

		$catalogs = [];
		if ($withCatalogs === true) {
			$catalogs = $this->getLocalCatalogs();
		}

		foreach ($results as &$publication) {
			if (isset($publication['@self']) === false || is_array($publication['@self']) === false) {
				$publication['@self'] = [];
			}

			if ($markDirectory === true) {
				$publication['@self']['directory'] = 'local';
			}

			if (empty($catalogs) === false) {
				$publication['@self']['catalogs'] = $catalogs;
			}
		}

		unset($publication);

		return $results;
	}//end tagLocalResults()

	/**
	 * Build the paginated envelope of a listing: results, totals and next/prev links.
	 *
	 * @param array     $results The results of this page.
	 * @param int|float $total   The total number of results.
	 * @param int       $offset  The offset reported for this page.
	 * @param array     $context Holds limit, page, requestParams and baseUrl.
	 *
	 * @return array The envelope.
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	private function buildListingPage(array $results, $total, int $offset, array $context): array {
		$totalPages = 1;
		if ($context['limit'] > 0) {
			$totalPages = max(1, ceil($total / $context['limit']));
		}

		$responseData = [
			'results' => $results,
			'total' => $total,
			'limit' => $context['limit'],
			'offset' => $offset,
			'page' => $context['page'],
			'pages' => $totalPages,
		];

		$links = [
			'next' => ($context['page'] + 1),
			'prev' => ($context['page'] - 1),
		];
		if ($context['page'] >= $totalPages) {
			unset($links['next']);
		}

		if ($context['page'] <= 1) {
			unset($links['prev']);
		}

		foreach ($links as $rel => $targetPage) {
			$linkParams = $context['requestParams'];
			$linkParams['_page'] = $targetPage;
			$responseData[$rel] = $context['baseUrl'] . '?' . http_build_query($linkParams);
		}

		return $responseData;
	}//end buildListingPage()

	/**
	 * The facets of a search response, unwrapped from a nested `facets.facets`, or null when absent.
	 *
	 * @param array $data A search response.
	 *
	 * @return mixed
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	private function unwrapFacetsOf(array $data) {
		if (isset($data['facets']) === false) {
			return null;
		}

		return $this->unwrapFacets(facets: $data['facets']);
	}//end unwrapFacetsOf()

	/**
	 * Unwrap facets nested as `facets.facets`.
	 *
	 * @param mixed $facets Facets as returned by a search.
	 *
	 * @return mixed
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	private function unwrapFacets($facets) {
		if (isset($facets['facets']) === true && is_array($facets['facets']) === true) {
			return $facets['facets'];
		}

		return $facets;
	}//end unwrapFacets()

	/**
	 * Set the response facets, adding the virtual directory and catalog facets when requested.
	 *
	 * @param array      $responseData The response.
	 * @param mixed|null $facets       The facets to set, or null when the sources had none.
	 * @param array      $context      Holds reqDirFacets and reqCatFacets.
	 *
	 * @return array The response.
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	private function withListingFacets(array $responseData, $facets, array $context): array {
		$virtual = ($context['reqDirFacets'] === true || $context['reqCatFacets'] === true);
		if ($facets === null && $virtual === false) {
			return $responseData;
		}

		if ($virtual === true) {
			$facets = $this->addVirtualFieldFacets(
				existingFacets: ($facets ?? []),
				inclDirFacets: $context['reqDirFacets'],
				inclCatFacets: $context['reqCatFacets']
			);
		}

		$responseData['facets'] = $facets;

		return $responseData;
	}//end withListingFacets()

	/**
	 * Set the merged facetable metadata of a local and a federated source.
	 *
	 * @param array $responseData The response.
	 * @param array $sources      The local and the federated search response, in that order.
	 * @param bool  $include      Whether facetable metadata was requested.
	 *
	 * @return array The response.
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	private function withMergedFacetable(array $responseData, array $sources, bool $include): array {
		$local = ($sources[0]['facetable'] ?? null);
		$federated = ($sources[1]['facetable'] ?? null);
		if ($local === null && $federated === null && $include === false) {
			return $responseData;
		}

		$responseData['facetable'] = $this->mergeFacetableData(
			localFacetable: ($local ?? []),
			federatedFacetable: ($federated ?? [])
		);

		return $responseData;
	}//end withMergedFacetable()

	/**
	 * Build one page of the merged local and federated listing.
	 *
	 * Every source is asked for page one with page × limit items, the merged set is
	 * de-duplicated and re-ordered, and then the requested page is sliced out. Asking
	 * each source for the same page would give totals and pages that do not add up.
	 *
	 * @param array     $queryParams The paginated request query.
	 * @param int|float $localTotal  The local total from the first local query.
	 * @param array     $context     Pagination, listing switches, request params and base URL.
	 *
	 * @return array The listing response.
	 *
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	private function buildFederatedListing(array $queryParams, $localTotal, array $context): array {
		$sourceParams = $queryParams;
		$sourceParams['_page'] = 1;
		$sourceParams['_limit'] = ($context['page'] * $context['limit']);
		unset($sourceParams['_offset']);

		$allLocalData = $this->fetchLocalPublicationData(queryParams: $sourceParams);
		$localResults = $this->tagLocalResults(results: ($allLocalData['results'] ?? []), markDirectory: true, withCatalogs: true);
		$federationResult = $this->directoryService->getPublications(
			guzzleConfig: ['query_params' => $this->buildFederatedQueryParams(queryParams: $sourceParams)]
		);

		$merged = $this->applyCumulativeOrdering(
			results: $this->uniqueById(results: array_merge($localResults, ($federationResult['results'] ?? []))),
			queryParams: $queryParams
		);
		$startIndex = (($context['page'] - 1) * $context['limit']);
		$responseData = $this->buildListingPage(
			results: array_slice($merged, $startIndex, $context['limit']),
			total: (($federationResult['total'] ?? 0) + $localTotal),
			offset: $startIndex,
			context: $context
		);

		$responseData = $this->withListingFacets(
			responseData: $responseData,
			facets: $this->mergeSourceFacets(localData: $allLocalData, federationResult: $federationResult),
			context: $context
		);

		return $this->withMergedFacetable(responseData: $responseData, sources: [$allLocalData, $federationResult], include: $context['includeFacets']);
	}//end buildFederatedListing()

	/**
	 * The merged facets of the local and the federated source, or null when neither has facets.
	 *
	 * @param array $localData        The local search response.
	 * @param array $federationResult The federated search response.
	 *
	 * @return mixed
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	private function mergeSourceFacets(array $localData, array $federationResult) {
		if (isset($localData['facets']) === false && isset($federationResult['facets']) === false) {
			return null;
		}

		return $this->mergeFacetsData(
			localFacets: $this->unwrapFacets(facets: ($localData['facets'] ?? [])),
			federatedFacets: $this->unwrapFacets(facets: ($federationResult['facets'] ?? []))
		);
	}//end mergeSourceFacets()

	/**
	 * The query for federated directories: without pagination and without the virtual facet filters.
	 *
	 * Aggregation paginates itself, and the directory and catalog facets are computed locally.
	 *
	 * @param array $queryParams The source query.
	 *
	 * @return array The query to send to the directories.
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	private function buildFederatedQueryParams(array $queryParams): array {
		$federated = array_filter(
			$queryParams,
			function ($key) {
				return $this->isFederatedQueryKey(key: $key);
			},
			ARRAY_FILTER_USE_KEY
		);

		foreach (['directory', 'catalogs'] as $virtualFacet) {
			if (isset($federated['_facets']['@self'][$virtualFacet]) === true) {
				unset($federated['_facets']['@self'][$virtualFacet]);
			}
		}

		return $federated;
	}//end buildFederatedQueryParams()

	/**
	 * Whether a query key is passed on to federated directories.
	 *
	 * @param int|string $key The query key.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	private function isFederatedQueryKey($key): bool {
		if (in_array($key, ['_limit', '_page', '_offset', 'offset']) === true) {
			return false;
		}

		foreach (['_facets[@self][directory]', '_facets[directory]', '_facets[@self][catalogs]', '_facets[catalogs]'] as $virtualFacet) {
			if (strpos((string)$key, $virtualFacet) !== false) {
				return false;
			}
		}

		return true;
	}//end isFederatedQueryKey()

	/**
	 * Drop results whose `id` (or `uuid`) was already seen; results without either are all kept.
	 *
	 * @param array $results The merged results.
	 *
	 * @return array The de-duplicated results.
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	private function uniqueById(array $results): array {
		$unique = [];
		$seenIds = [];
		foreach ($results as $result) {
			$id = ($result['id'] ?? $result['uuid'] ?? uniqid());
			if (isset($seenIds[$id]) === false) {
				$unique[] = $result;
				$seenIds[$id] = true;
			}
		}

		return $unique;
	}//end uniqueById()

	/**
	 * The `_performance` block of an aggregated listing.
	 *
	 * @param float $startTime The request start time.
	 * @param bool  $federated Whether the federated sources answered.
	 *
	 * @return array
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	private function aggregationPerformance(float $startTime, bool $federated): array {
		$performance = [
			'execution_time_ms' => round(((microtime(true) - $startTime) * 1000), 2),
			'fast_path' => false,
			'federation' => $federated,
		];
		if ($federated === false) {
			$performance['error'] = 'Federation failed, returned local only';
		}

		$performance['cached_catalogs'] = $this->cachedLocalCatalogs !== null;
		$performance['cached_filters'] = !empty($this->cachedCatalogFilters);

		return $performance;
	}//end aggregationPerformance()

	/**
	 * Get local publications without federation overhead.
	 *
	 * Used when aggregation is off or no federated directory is available, and the
	 * request needs catalog information or the virtual facets. Without either it
	 * hands over to the ultra-fast path.
	 *
	 * @param array $queryParams The paginated request query.
	 * @param array $context     Listing switches, request params and base URL.
	 *
	 * @return array Response data containing publications, pagination info, and optionally facets
	 *
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	private function getLocalPublicationsFast(array $queryParams, array $context): array {
		$startTime = microtime(true);
		$timings = ['setup' => $this->elapsedMs(since: $startTime), 'query' => 0, 'processing' => 0];

		$enrich = ($context['includeCatalogs'] === true || $context['reqDirFacets'] === true || $context['reqCatFacets'] === true);
		if ($enrich === false) {
			return $this->getLocalPublicationsUltraFast(
				queryParams: $queryParams,
				requestParams: $context['requestParams'],
				baseUrl: $context['baseUrl'],
				startTime: $startTime
			);
		}

		$queryStart = microtime(true);
		$localData = $this->fetchLocalPublicationData(queryParams: $queryParams);
		$timings['query'] = $this->elapsedMs(since: $queryStart);

		$responseData = $this->buildFastListing(localData: $localData, queryParams: $queryParams, context: $context);

		$timings['processing'] = $this->elapsedMs(since: microtime(true));
		$responseData['_performance'] = [
			'execution_time_ms' => round($this->elapsedMs(since: $startTime), 2),
			'fast_path' => true,
			'ultra_fast_path' => false,
			'include_catalogs' => $context['includeCatalogs'],
			'cached_catalogs' => $this->cachedLocalCatalogs !== null,
			'timings' => $timings,
		];

		return $responseData;
	}//end getLocalPublicationsFast()

	/**
	 * The fast-path listing envelope of a local index response.
	 *
	 * @param array $localData   The decoded local index response.
	 * @param array $queryParams The paginated request query.
	 * @param array $context     Listing switches, request params and base URL.
	 *
	 * @return array
	 *
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	private function buildFastListing(array $localData, array $queryParams, array $context): array {
		$pageContext = $this->readPagination(queryParams: $queryParams) + $context;
		$results = $this->tagLocalResults(
			results: ($localData['results'] ?? []),
			markDirectory: $context['reqDirFacets'],
			withCatalogs: $context['includeCatalogs']
		);
		$responseData = $this->buildListingPage(
			results: $results,
			total: ($localData['total'] ?? 0),
			offset: $pageContext['offset'],
			context: $pageContext
		);
		$responseData = $this->withListingFacets(responseData: $responseData, facets: $this->unwrapFacetsOf(data: $localData), context: $context);
		if (isset($localData['facetable']) === true) {
			$responseData['facetable'] = $localData['facetable'];
		}

		return $responseData;
	}//end buildFastListing()

	/**
	 * Milliseconds elapsed since a microtime(true) timestamp.
	 *
	 * @param float $since The start timestamp.
	 *
	 * @return float
	 *
	 * @spec exclude Timing helper for the `_performance` blocks; carries no behaviour of its own.
	 */
	private function elapsedMs(float $since): float {
		return ((microtime(true) - $since) * 1000);
	}//end elapsedMs()

	/**
	 * Get local publications with ultra-fast path (minimal overhead)
	 *
	 * This method bypasses ALL middleware overhead and calls ObjectService directly.
	 * Use when no catalog info, facets, or additional processing is needed.
	 *
	 * @param array  $queryParams   Query parameters
	 * @param array  $requestParams Original request parameters for pagination links
	 * @param string $baseUrl       Base URL for building pagination links
	 * @param float  $startTime     Start time for performance monitoring
	 *
	 * @return array Minimal response with publications and pagination
	 *
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	private function getLocalPublicationsUltraFast(
		array $queryParams,
		array $requestParams,
		string $baseUrl,
		float $startTime,
	): array {
		$timings = ['setup' => 0, 'objectservice' => 0, 'response' => 0];
		$setupStart = microtime(true);
		$pageContext = $this->readPagination(queryParams: $queryParams) + ['requestParams' => $requestParams, 'baseUrl' => $baseUrl];

		// FAIL CLOSED on an unconfigured catalog scope, as getCatalogFilters() does: this
		// path bypasses the full search, so it has to carry the same refusal.
		if ($this->resolveCatalogScope() === null) {
			return $this->emptyListingPage(pagination: $pageContext);
		}

		$timings['setup'] = $this->elapsedMs(since: $setupStart);
		$contextStart = microtime(true);
		$catalogContext = $this->readUltraFastCatalogContext();

		// FAIL CLOSED on an empty schema or register scope (WOO-581): an empty
		// `@self.schema` must never be read as "no schema filter", nor an empty
		// register list as "every register".
		if (empty($catalogContext['schemas']) === true || empty($catalogContext['registers']) === true) {
			return $this->emptyListingPage(pagination: $pageContext);
		}

		$timings['catalog_context'] = $this->elapsedMs(since: $contextStart);
		$searchStart = microtime(true);
		$result = $this->searchUltraFast(queryParams: $queryParams, catalogContext: $catalogContext);
		$timings['objectservice'] = $this->elapsedMs(since: $searchStart);

		$responseStart = microtime(true);
		$responseData = $this->buildUltraFastResponse(result: $result, queryParams: $queryParams, pageContext: $pageContext);
		$timings['response'] = $this->elapsedMs(since: $responseStart);

		$responseData['_performance'] = $this->ultraFastPerformance(queryParams: $queryParams, startTime: $startTime, timings: $timings);

		return $responseData;
	}//end getLocalPublicationsUltraFast()

	/**
	 * The empty first-page answer of a listing that refuses to search.
	 *
	 * @param array $pagination The limit, page and offset of the request.
	 *
	 * @return array
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	private function emptyListingPage(array $pagination): array {
		return [
			'results' => [],
			'total' => 0,
			'limit' => $pagination['limit'],
			'offset' => $pagination['offset'],
			'page' => $pagination['page'],
			'pages' => 1,
		];
	}//end emptyListingPage()

	/**
	 * The catalog register/schema union for the ultra-fast path, failing closed on a lookup error.
	 *
	 * A failing catalog lookup must not fall back to the configured catalog
	 * register/schema (WOO-581): that would search the catalog schema itself.
	 *
	 * @return array{registers: array, schemas: array}
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	private function readUltraFastCatalogContext(): array {
		try {
			return $this->getCatalogFilters();
		} catch (\Exception $e) {
			$this->availableRegisters = [];
			$this->availableSchemas = [];

			return [
				'registers' => [],
				'schemas' => [],
			];
		}
	}//end readUltraFastCatalogContext()

	/**
	 * Search the catalog union directly on ObjectService, bypassing searchPublications().
	 *
	 * @param array $queryParams    The request query.
	 * @param array $catalogContext The guarded catalog register/schema union.
	 *
	 * @return array The ObjectService result.
	 *
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	private function searchUltraFast(array $queryParams, array $catalogContext): array {
		return $this->searchObjectsWithinScope(
			objectService: $this->getObjectService(),
			query: $this->buildUltraFastQuery(queryParams: $queryParams),
			registers: $catalogContext['registers'],
			schemas: $catalogContext['schemas']
		);
	}//end searchUltraFast()

	/**
	 * The ObjectService query of the ultra-fast path.
	 *
	 * The scope is the guarded catalog union, never the caller's (WOO-581): OR's facet
	 * path reads `@self.schemas ?? _schemas` first, so caller keys are stripped.
	 *
	 * @param array $queryParams The request query.
	 *
	 * @return array
	 *
	 * @spec openspec/specs/federation/spec.md
	 * @SuppressWarnings(PHPMD.StaticAccess) CallerScope::strip() is a pure function over the query (WOO-581)
	 */
	private function buildUltraFastQuery(array $queryParams): array {
		$searchQuery = CallerScope::strip(query: $queryParams);
		$searchQuery['_includeDeleted'] = false;
		unset($searchQuery['id'], $searchQuery['_route']);

		return $searchQuery;
	}//end buildUltraFastQuery()

	/**
	 * Read the ultra-fast switches: skip result filtering, and the requested virtual facets.
	 *
	 * @param array $queryParams The request query.
	 *
	 * @return array{skipFiltering: bool, reqDirFacets: bool, reqCatFacets: bool}
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	private function readUltraFastSwitches(array $queryParams): array {
		return [
			'skipFiltering' => isset($queryParams['_skip_filtering']) && $queryParams['_skip_filtering'] !== 'false',
			'reqDirFacets' => isset($queryParams['_facets']['@self']['directory']),
			'reqCatFacets' => isset($queryParams['_facets']['@self']['catalogs']),
		];
	}//end readUltraFastSwitches()

	/**
	 * Build the ultra-fast listing envelope from an ObjectService result.
	 *
	 * @param array $result      The ObjectService result.
	 * @param array $queryParams The request query.
	 * @param array $pageContext Pagination, request params and base URL.
	 *
	 * @return array
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	private function buildUltraFastResponse(array $result, array $queryParams, array $pageContext): array {
		$switches = $this->readUltraFastSwitches(queryParams: $queryParams);

		$results = ($result['results'] ?? []);
		if ($switches['skipFiltering'] === false) {
			$results = $this->filterUnwantedProperties(objects: $results);
		}

		$responseData = $this->buildListingPage(results: $results, total: ($result['total'] ?? 0), offset: $pageContext['offset'], context: $pageContext);
		if (isset($result['facets']) === true) {
			$responseData = $this->withListingFacets(
				responseData: $responseData,
				facets: $this->unwrapFacets(facets: $result['facets']),
				context: $switches
			);
		}

		if (isset($result['facetable']) === true) {
			$responseData['facetable'] = $result['facetable'];
		}

		return $responseData;
	}//end buildUltraFastResponse()

	/**
	 * The `_performance` block of the ultra-fast path.
	 *
	 * @param array $queryParams The request query.
	 * @param float $startTime   The request start time.
	 * @param array $timings     The measured phase timings.
	 *
	 * @return array
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	private function ultraFastPerformance(array $queryParams, float $startTime, array $timings): array {
		$switches = $this->readUltraFastSwitches(queryParams: $queryParams);

		return [
			'execution_time_ms' => round($this->elapsedMs(since: $startTime), 2),
			'fast_path' => true,
			'ultra_fast_path' => true,
			'include_catalogs' => false,
			'cached_catalogs' => false,
			'bypassed_middleware' => true,
			'skipped_filtering' => $switches['skipFiltering'],
			'processed_virtual_facets' => $switches['reqDirFacets'] || $switches['reqCatFacets'],
			'cached_catalog_filters' => isset($this->cachedCatalogFilters['all']),
			'timings' => $timings,
		];
	}//end ultraFastPerformance()

	/**
	 * Get local catalog information for adding to publication @self.catalogs property.
	 *
	 * This method retrieves all local catalogs that are available in the current instance.
	 * It returns an array of catalog objects with their basic information.
	 *
	 * PERFORMANCE OPTIMIZATION: Results are cached to avoid repeated database queries
	 * that were adding ~200ms overhead per call. Cache is invalidated on object destruction.
	 *
	 * @return array Array of catalog objects with id, title, summary, description, etc.
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	private function getLocalCatalogs(): array {
		if ($this->cachedLocalCatalogs !== null) {
			return $this->cachedLocalCatalogs;
		}

		try {
			$query = $this->localCatalogQuery();
			if ($query === null) {
				return [];
			}

			$catalogArray = [];
			foreach ($this->getObjectService()->searchObjects($query) as $catalog) {
				$catalogInfo = $this->localCatalogInfo(catalog: $catalog);
				if (empty($catalogInfo['id']) === false) {
					$catalogArray[] = $catalogInfo;
				}
			}

			$this->cachedLocalCatalogs = $catalogArray;
			return $catalogArray;
		} catch (\Exception $e) {
			// If we can't get catalog information, return empty array and cache it.
			$this->cachedLocalCatalogs = [];
			return [];
		}//end try

	}//end getLocalCatalogs()

	/**
	 * The search query for this instance's catalogs.
	 *
	 * @return array|null The query, or null when the catalog schema or register is not configured.
	 */
	private function localCatalogQuery(): ?array {
		$catalogSchema = $this->config->getValueString($this->appName, 'catalog_schema', '');
		$catalogRegister = $this->config->getValueString($this->appName, 'catalog_register', '');
		if (empty($catalogSchema) === true || empty($catalogRegister) === true) {
			return null;
		}

		return [
			'@self' => [
				'schema' => $catalogSchema,
				'register' => $catalogRegister,
			],
		];
	}//end localCatalogQuery()

	/**
	 * The public summary of one local catalog.
	 *
	 * `slug` is authoritative for URL routing on both sides of the federation
	 * (see FederationSearch.onResultClick: `#/publications/<slug>/<id>`); a peer
	 * without it falls back to the app root instead of the publication (WOO-522).
	 *
	 * @param mixed $catalog The catalog entity or its data.
	 *
	 * @return array The catalog's id, slug, title, summary, description, organization and listed flag.
	 */
	private function localCatalogInfo(mixed $catalog): array {
		$catalogData = $catalog;
		if ($catalog instanceof \OCP\AppFramework\Db\Entity) {
			$catalogData = $catalog->jsonSerialize();
		}

		return [
			'id' => ($catalogData['id'] ?? ''),
			'slug' => ($catalogData['slug'] ?? $catalogData['@self']['slug'] ?? ''),
			'title' => ($catalogData['title'] ?? 'Local Catalog'),
			'summary' => ($catalogData['summary'] ?? 'Local catalog instance'),
			'description' => $catalogData['description'] ?? null,
			'organization' => $catalogData['organization'] ?? null,
			'listed' => $catalogData['listed'] ?? false,
		];
	}//end localCatalogInfo()

	/**
	 * Simple merge function for facets data from multiple sources.
	 *
	 * @param array $localFacets Facets from local source
	 * @param array $federatedFacets Facets from federated sources
	 *
	 * @return array Merged facets data
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	private function mergeFacetsData(array $localFacets, array $federatedFacets): array {
		// For now, just return local facets as they're more reliable
		// In the future, we could implement more sophisticated merging.
		if (empty($localFacets) === false) {
			return $localFacets;
		}

		return $federatedFacets;
	}//end mergeFacetsData()

	/**
	 * Simple merge function for facetable metadata from multiple sources.
	 *
	 * @param array $localFacetable Facetable metadata from local source
	 * @param array $federatedFacetable Facetable metadata from federated sources
	 *
	 * @return array Merged facetable metadata
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	private function mergeFacetableData(array $localFacetable, array $federatedFacetable): array {
		// Start with local facetable as base; fall back to federated.
		$mergedFacetable = $federatedFacetable;
		if (empty($localFacetable) === false) {
			$mergedFacetable = $localFacetable;
		}

		// Ensure @self section exists.
		if (isset($mergedFacetable['@self']) === false) {
			$mergedFacetable['@self'] = [];
		}

		// Add directory facet for filtering by catalog source
		// This will be populated dynamically based on available directories.
		$mergedFacetable['@self']['directory'] = self::DIRECTORY_FACET;

		// Add catalogs facet for filtering by catalog
		// This will be populated dynamically based on available catalogs.
		$mergedFacetable['@self']['catalogs'] = self::CATALOGS_FACET;

		return $mergedFacetable;
	}//end mergeFacetableData()

	/**
	 * Apply ordering to the cumulated dataset from multiple sources
	 *
	 * This method handles ordering parameters in the format _order[field]=direction
	 * and applies them to the merged results from local and federated sources.
	 * Since each source may have different ordering, we need to re-sort the combined dataset.
	 *
	 * @param array $results The merged and deduplicated results to order
	 * @param array $queryParams The query parameters containing ordering instructions
	 *
	 * @return array The ordered results
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	private function applyCumulativeOrdering(array $results, array $queryParams): array {
		// Extract ordering parameters.
		$orderParams = ($queryParams['_order'] ?? $queryParams['order'] ?? []);

		if (empty($orderParams) === true || is_array($orderParams) === false) {
			return $results;
		}

		// Convert single field ordering to array format for consistency.
		if (isset($orderParams[0]) === false) {
			$orderParams = [$orderParams];
		}

		// Apply multiple field ordering (PHP's usort is stable for equal values).
		usort(
			$results,
			function ($a, $b) use ($orderParams) {
				foreach ($orderParams as $field => $direction) {
					// Handle both associative array format: ['field' => 'direction']
					// and indexed array format: [0 => ['field' => 'direction']]
					// Format: ['@self.created' => 'DESC'].
					$fieldName = $field;
					$sortDirection = 'ASC';
					if (is_string($direction) === true) {
						$sortDirection = strtoupper($direction);
					}

					if (is_numeric($field) === true && is_array($direction) === true) {
						// Format: [0 => ['@self.created' => 'DESC']].
						$fieldName = array_key_first($direction);
						$sortDirection = strtoupper(($direction[$fieldName] ?? 'ASC'));
					}

					// Extract values for comparison.
					$valueA = $this->extractFieldValue(result: $a, fieldPath: $fieldName);
					$valueB = $this->extractFieldValue(result: $b, fieldPath: $fieldName);

					// Compare values.
					$comparison = $this->compareValues(a: $valueA, b: $valueB);

					if ($comparison !== 0) {
						// Return result based on sort direction.
						if ($sortDirection === 'DESC') {
							return -$comparison;
						}

						return $comparison;
					}

					// If values are equal, continue to next sort field.
				}//end foreach

				return 0;
				// All compared fields are equal.
			}
		);

		return $results;
	}//end applyCumulativeOrdering()

	/**
	 * Extract field value from a result object using dot notation
	 *
	 * Supports nested field access like '@self.created' or 'data.title'
	 *
	 * @param array $result The result object to extract value from
	 * @param string $fieldPath The field path in dot notation
	 *
	 * @return mixed The extracted value or null if not found
	 */
	private function extractFieldValue(array $result, string $fieldPath) {
		$parts = explode('.', $fieldPath);
		$value = $result;

		foreach ($parts as $part) {
			if (is_array($value) === false || isset($value[$part]) === false) {
				return null;
			}

			$value = $value[$part];
		}

		return $value;
	}//end extractFieldValue()

	/**
	 * Compare two values for sorting
	 *
	 * Handles different data types appropriately for sorting
	 *
	 * @param mixed $a First value
	 * @param mixed $b Second value
	 *
	 * @return integer -1, 0, or 1 for less than, equal, or greater than
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 */
	private function compareValues($a, $b): int {
		// Handle null values.
		if ($a === null && $b === null) {
			return 0;
		}

		if ($a === null) {
			return -1;
		}

		if ($b === null) {
			return 1;
		}

		// Handle date strings.
		if (is_string($a) === true && is_string($b) === true) {
			$dateA = strtotime($a);
			$dateB = strtotime($b);

			if ($dateA !== false && $dateB !== false) {
				return ($dateA <=> $dateB);
			}
		}

		// Handle numeric values.
		if (is_numeric($a) === true && is_numeric($b) === true) {
			return ($a <=> $b);
		}

		// Handle string comparison.
		return strcmp((string)$a, (string)$b);
	}//end compareValues()

	/**
	 * Get a single publication with optional federation support
	 *
	 * This method searches for a publication in the local catalog first,
	 * and optionally in federated catalogs if _aggregate parameter is not
	 * set to false and the publication is not found locally.
	 *
	 * @param string $id The ID of the publication to retrieve
	 * @param array $queryParams Query parameters including aggregation settings
	 *
	 * @return array Response data containing the publication or error information
	 *
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	public function getFederatedPublication(string $id, array $queryParams = []): array {
		$localResponse = $this->show(id: $id);
		$localData = json_decode($localResponse->render(), true);

		$aggregate = ($queryParams['_aggregate'] ?? 'true');
		$shouldAggregate = $aggregate !== 'false' && $aggregate !== '0';

		if ($localResponse->getStatus() === 200 && empty($localData) === false) {
			if ($shouldAggregate === true) {
				$localData = $this->withLocalCatalogs(publication: $localData);
			}

			return [
				'success' => true,
				'data' => $localData,
				'status' => 200,
			];
		}

		if ($shouldAggregate === false) {
			return self::PUBLICATION_NOT_FOUND;
		}

		return $this->fetchFederatedPublication(id: $id, queryParams: $queryParams);
	}//end getFederatedPublication()

	/**
	 * Add this instance's catalogs to the `@self.catalogs` of a local publication.
	 *
	 * @param array $publication The local publication.
	 *
	 * @return array The publication, with its catalogs when it has an `@self` and any catalog exists.
	 *
	 * @todo This adds ~200ms overhead - consider making optional via query parameter.
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	private function withLocalCatalogs(array $publication): array {
		if (isset($publication['@self']) === false || is_array($publication['@self']) === false) {
			return $publication;
		}

		$localCatalogs = $this->getLocalCatalogs();
		if (empty($localCatalogs) === false) {
			$publication['@self']['catalogs'] = $localCatalogs;
		}

		return $publication;
	}//end withLocalCatalogs()

	/**
	 * Look a publication up in the federated catalogs.
	 *
	 * A failing lookup reads as not found.
	 *
	 * @param string $id The ID of the publication.
	 * @param array $queryParams The caller's query parameters.
	 *
	 * @return array The peer's publication with its sources, marked `federated`, or the not-found result.
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	private function fetchFederatedPublication(string $id, array $queryParams): array {
		try {
			$federatedResult = $this->directoryService->getPublication($id, $this->federationRequestConfig(queryParams: $queryParams));
		} catch (\Exception $e) {
			return self::PUBLICATION_NOT_FOUND;
		}

		if (empty($federatedResult) === true || isset($federatedResult['result']) === false) {
			return self::PUBLICATION_NOT_FOUND;
		}

		$responseData = $federatedResult['result'];
		$responseData['sources'] = ($federatedResult['source'] ?? []);

		// `federated` marks a peer's publication: it is not this instance's to describe.
		return [
			'success' => true,
			'data' => $responseData,
			'status' => 200,
			'federated' => true,
		];
	}//end fetchFederatedPublication()

	/**
	 * The request config for a federated lookup.
	 *
	 * `timeout` (at most 120 s) and `connect_timeout` (at most 30 s) are taken from
	 * the query when positive and in bounds; the query itself is passed through for
	 * DirectoryService to read `_aggregate` and `_extend` from.
	 *
	 * @param array $queryParams The caller's query parameters.
	 *
	 * @return array The Guzzle config.
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	private function federationRequestConfig(array $queryParams): array {
		$guzzleConfig = [];
		foreach (['timeout' => 120, 'connect_timeout' => 30] as $option => $maximum) {
			if (isset($queryParams[$option]) === true) {
				$seconds = (int)$queryParams[$option];
				if ($seconds > 0 && $seconds <= $maximum) {
					$guzzleConfig[$option] = $seconds;
				}
			}
		}

		$guzzleConfig['query_params'] = $queryParams;

		return $guzzleConfig;
	}//end federationRequestConfig()

	/**
	 * Get publications that use this publication with federation support
	 *
	 * This method returns all objects that reference (use) this
	 * publication. B -> A means that B (Another object) references
	 * A (This publication).
	 * When aggregation is enabled, it also searches federated
	 * catalogs.
	 *
	 * @param string $id The ID of the publication to retrieve uses for
	 * @param array $queryParams Query parameters including aggregation settings
	 *
	 * @return array Response data containing the referenced objects
	 *
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	public function getFederatedUsed(string $id, array $queryParams = []): array {
		$localData = json_decode($this->used(id: $id)->render(), true);
		if ($this->aggregationRequested(queryParams: $queryParams) === false) {
			return $this->successResult(data: $localData);
		}

		try {
			$federatedResults = $this->directoryService->getUsed($id, $this->federationRequestConfig(queryParams: $queryParams));
		} catch (\Exception $e) {
			// If federation fails, return local results.
			return $this->successResult(data: $localData);
		}

		return $this->successResult(
			data: [
				'results' => array_merge(($localData['results'] ?? []), ($federatedResults['results'] ?? [])),
				'sources' => array_merge(['local' => 'Local OpenCatalogi instance'], ($federatedResults['sources'] ?? [])),
			]
		);

	}//end getFederatedUsed()

	/**
	 * Whether the query asks for federated results: `_aggregate` is on unless it is `false` or `0`.
	 *
	 * @param array $queryParams The caller's query parameters.
	 *
	 * @return bool True when peers should be asked too.
	 */
	private function aggregationRequested(array $queryParams): bool {
		$aggregate = ($queryParams['_aggregate'] ?? 'true');
		return $aggregate !== 'false' && $aggregate !== '0';
	}//end aggregationRequested()

	/**
	 * The successful result shape of a federated lookup.
	 *
	 * @param mixed $data The response data.
	 *
	 * @return array The result with status 200.
	 */
	private function successResult(mixed $data): array {
		return [
			'success' => true,
			'data' => $data,
			'status' => 200,
		];
	}//end successResult()

	/**
	 * Get publications that this publication uses with federation support
	 *
	 * This method returns all objects that this publication
	 * uses/references. A -> B means that A (This publication)
	 * references B (Another object).
	 * When aggregation is enabled, it also searches federated
	 * catalogs.
	 *
	 * @param string $id The ID of the publication to retrieve relations for
	 * @param array $queryParams Query parameters including aggregation settings
	 *
	 * @return array Response data containing the related objects
	 *
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	public function getFederatedUses(string $id, array $queryParams = []): array {
		// Check if aggregation is enabled (default: true, unless explicitly set to false).
		$aggregate = ($queryParams['_aggregate'] ?? 'true');
		$shouldAggregate = $aggregate !== 'false' && $aggregate !== '0';

		// Get local results first.
		$localResponse = $this->uses(id: $id);
		$localData = json_decode($localResponse->render(), true);

		// If aggregation is disabled, return only local results.
		if ($shouldAggregate === false) {
			return [
				'success' => true,
				'data' => $localData,
				'status' => 200,
			];
		}

		// Note: For 'uses' we don't have a specific DirectoryService method yet.
		// This would need to be implemented similar to getUsed() if needed.
		// For now, return local results only.
		return [
			'success' => true,
			'data' => $localData,
			'status' => 200,
		];

	}//end getFederatedUses()
}//end class
