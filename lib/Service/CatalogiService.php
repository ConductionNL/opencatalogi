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
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 */

namespace OCA\OpenCatalogi\Service;

use OCP\IRequest;
use OCP\IAppConfig;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use Psr\Container\ContainerInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use OCP\AppFramework\Http\JSONResponse;
use Exception;
use OCP\Common\Exception\NotFoundException;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;

/**
 * Service for handling publication-related operations.
 *
 * Provides functionality for retrieving, saving, updating, and deleting publications,
 * as well as managing publication-related data and filters.
 */
class CatalogiService
{

    /**
     * @var string $appName The name of the app
     */
    private string $appName;

    /**
     * @var array<string> List of available registers from catalogs
     */
    private array $availableRegisters = [];

    /**
     * @var array<string> List of available schemas from catalogs
     */
    private array $availableSchemas = [];

    /**
     * @var ICache Cache instance for storing catalog data
     */
    private ICache $cache;


    /**
     * Constructor for PublicationService.
     *
     * @param IAppConfig       $config       App configuration interface
     * @param IRequest         $request      Request interface
     * @param ContainerInterface $container  Server container for dependency injection
     * @param IAppManager      $appManager   App manager for checking installed apps
     * @param ICacheFactory    $cacheFactory Cache factory for creating cache instances
     * @param LoggerInterface  $logger       Logger for logging errors and debug information
     */
    public function __construct(
        private readonly IAppConfig $config,
        private readonly IRequest $request,
        private readonly ContainerInterface $container,
        private readonly IAppManager $appManager,
        private readonly ICacheFactory $cacheFactory,
        private readonly LoggerInterface $logger,
    ) {
        $this->appName = 'opencatalogi';
        $this->cache = $cacheFactory->createDistributed('opencatalogi_catalogs');

    }//end __construct()


    /**
     * Attempts to retrieve the OpenRegister service from the container.
     *
     * @return mixed|null The OpenRegister service if available, null otherwise.
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     */
    public function getObjectService(): ?\OCA\OpenRegister\Service\ObjectService
    {
        if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
            $this->objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            return $this->objectService;
        }

        throw new \RuntimeException('OpenRegister service is not available.');

    }//end getObjectService()

    /**
     * Attempts to retrieve the OpenRegister service from the container.
     *
     * @return mixed|null The OpenRegister service if available, null otherwise.
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     */
    public function getFileService(): ?\OCA\OpenRegister\Service\FileService
    {
        if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
            $this->objectService = $this->container->get('OCA\OpenRegister\Service\FileService');

            return $this->objectService;
        }

        throw new \RuntimeException('OpenRegister service is not available.');

    }//end getObjectService()


    /**
     * Get register and schema combinations from catalogs.
     *
     * This method retrieves all catalogs (or a specific one if ID is provided),
     * extracts their registers and schemas, and stores them as general variables.
     *
     * @param  string|int|null $catalogId Optional ID of a specific catalog to filter by
     * @return array<string, array<string>> Array containing available registers and schemas
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     */
    public function getCatalogFilters(null|string|int $catalogId = null): array
    {
        // Establish the default schema and register
        $schema   = $this->config->getValueString($this->appName, 'catalog_schema', '');
        $register = $this->config->getValueString($this->appName, 'catalog_register', '');

        $config = [];
        if ($catalogId !== null) {
            $catalogs = [$this->getObjectService()->find($catalogId)];
        } else {
            // Setup the query for searchObjects
            $query = [
                '@self' => [
                    'register' => $register,
                    'schema' => $schema,
                ],
            ];
            // Get all catalogs using searchObjects
            $catalogs = $this->getObjectService()->searchObjects($query);
        }

        // Initialize arrays to store unique registers and schemas
        $uniqueRegisters = [];
        $uniqueSchemas   = [];

        // Iterate over each catalog to extract registers and schemas
        foreach ($catalogs as $catalog) {
            $catalog = $catalog->jsonSerialize();
            // Check if 'registers' is an array and merge unique values
            if (isset($catalog['registers']) && is_array($catalog['registers'])) {
                $uniqueRegisters = array_merge($uniqueRegisters, $catalog['registers']);
            }

            // Check if 'schemas' is an array and merge unique values
            if (isset($catalog['schemas']) && is_array($catalog['schemas'])) {
                $uniqueSchemas = array_merge($uniqueSchemas, $catalog['schemas']);
            }
        }

        // Remove duplicate values and assign to class properties
        $this->availableRegisters = array_unique($uniqueRegisters);
        $this->availableSchemas   = array_unique($uniqueSchemas);

        return [
            'registers' => array_values($this->availableRegisters),
            'schemas'   => array_values($this->availableSchemas),
        ];

    }//end getCatalogFilters()


    /**
     * Get the list of available registers.
     *
     * @return array<string> List of available registers
     */
    public function getAvailableRegisters(): array
    {
        return $this->availableRegisters;

    }//end getAvailableRegisters()


    /**
     * Get the list of available schemas.
     *
     * @return array<string> List of available schemas
     */
    public function getAvailableSchemas(): array
    {
        return $this->availableSchemas;

    }//end getAvailableSchemas()


    /**
     * Private helper method to handle pagination of results.
     *
     * This method paginates the given results array based on the provided total, limit, offset, and page parameters.
     * It calculates the number of pages, sets the appropriate offset and page values, and returns the paginated results
     * along with metadata such as total items, current page, total pages, limit, and offset.
     *
     * @param array    $results The array of objects to paginate.
     * @param int|null $total   The total number of items (before pagination). Defaults to 0.
     * @param int|null $limit   The number of items per page. Defaults to 20.
     * @param int|null $offset  The offset of items. Defaults to 0.
     * @param int|null $page    The current page number. Defaults to 1.
     * @param array|null $facets    The already fetched facets. Defaults to empty array.
     *
     * @return array The paginated results with metadata.
     *
     * @phpstan-param  array<int, mixed> $results
     * @phpstan-return array<string, mixed>
     * @psalm-param    array<int, mixed> $results
     * @psalm-return   array<string, mixed>
     */
    private function paginate(array $results, ?int $total=0, ?int $limit=20, ?int $offset=0, ?int $page=1, ?array $facets = []): array
    {
        // Ensure we have valid values (never null)
        $total = max(0, ($total ?? 0));
        $limit = max(1, ($limit ?? 20));
        // Minimum limit of 1
        $offset = max(0, ($offset ?? 0));
        $page   = max(1, ($page ?? 1));
        // Minimum page of 1        // Calculate the number of pages (minimum 1 page)
        $pages = max(1, ceil($total / $limit));

        // If we have a page but no offset, calculate the offset
        if ($offset === 0) {
            $offset = (($page - 1) * $limit);
        }

        // If we have an offset but page is 1, calculate the page
        if ($page === 1 && $offset > 0) {
            $page = (floor($offset / $limit) + 1);
        }

        // If total is smaller than the number of results, set total to the number of results
        // @todo: this is a hack to ensure the pagination is correct when the total is not known. That sugjest that the underlaying count service has a problem that needs to be fixed instead
        if ($total < count($results)) {
            $total = count($results);
            $pages = max(1, ceil($total / $limit));
        }

        // Initialize the results array with pagination information
        $paginatedResults = [
            'results' => $results,
            'total'   => $total,
            'page'    => $page,
            'pages'   => $pages,
            'limit'   => $limit,
            'offset'  => $offset,
            'facets'  => $facets,
        ];

        // Add next/prev page URLs if applicable
        $currentUrl = $_SERVER['REQUEST_URI'];

        // Add next page link if there are more pages
        if ($page < $pages) {
            $nextPage = ($page + 1);
            $nextUrl  = preg_replace('/([?&])page=\d+/', '$1page='.$nextPage, $currentUrl);
            if (strpos($nextUrl, 'page=') === false) {
                $nextUrl .= (strpos($nextUrl, '?') === false ? '?' : '&').'page='.$nextPage;
            }

            $paginatedResults['next'] = $nextUrl;
        }

        // Add previous page link if not on first page
        if ($page > 1) {
            $prevPage = ($page - 1);
            $prevUrl  = preg_replace('/([?&])page=\d+/', '$1page='.$prevPage, $currentUrl);
            if (strpos($prevUrl, 'page=') === false) {
                $prevUrl .= (strpos($prevUrl, '?') === false ? '?' : '&').'page='.$prevPage;
            }

            $paginatedResults['prev'] = $prevUrl;
        }

        return $paginatedResults;

    }//end paginate()


    /**
     * Helper method to get configuration array from the current request
     *
     * @param string|null $register Optional register identifier
     * @param string|null $schema   Optional schema identifier
     * @param array|null  $ids      Optional array of specific IDs to filter
     *
     * @return array Configuration array containing:
     *               - limit: (int) Maximum number of items per page
     *               - offset: (int|null) Number of items to skip
     *               - page: (int|null) Current page number
     *               - filters: (array) Filter parameters
     *               - sort: (array) Sort parameters
     *               - search: (string|null) Search term
     *               - extend: (array|null) Properties to extend
     *               - fields: (array|null) Fields to include
     *               - unset: (array|null) Fields to exclude
     *               - register: (string|null) Register identifier
     *               - schema: (string|null) Schema identifier
     *               - ids: (array|null) Specific IDs to filter
     */
    private function getConfig(?string $register=null, ?string $schema=null, ?array $ids=null): array
    {
        $params = $this->request->getParams();

        unset($params['id']);
        unset($params['_route']);

        // Extract and normalize parameters
        $limit  = (int) ($params['limit'] ?? $params['_limit'] ?? 20);
        $offset = isset($params['offset']) ? (int) $params['offset'] : (isset($params['_offset']) ? (int) $params['_offset'] : null);
        $page   = isset($params['page']) ? (int) $params['page'] : (isset($params['_page']) ? (int) $params['_page'] : null);

        // If we have a page but no offset, calculate the offset
        if ($page !== null && $offset === null) {
            $offset = (($page - 1) * $limit);
        }

        $queries = ($params['queries'] ?? $params['_queries'] ?? []);
        if (is_string($queries) === true) {
            $queries = [$queries];
        }

        return [
            'limit'   => $limit,
            'offset'  => $offset,
            'page'    => $page,
            'filters' => $params,
            'sort'    => ($params['order'] ?? $params['_order'] ?? []),
            'search'  => ($params['_search'] ?? null),
            'extend'  => ($params['extend'] ?? $params['_extend'] ?? null),
            'fields'  => ($params['fields'] ?? $params['_fields'] ?? null),
            'unset'   => ($params['unset'] ?? $params['_unset'] ?? null),
            'queries' => $queries,
            'ids'     => $ids,
        ];

    }//end getConfig()


    /**
     * Get a catalog by its slug from cache or database.
     *
     * This method first attempts to retrieve the catalog from cache. If not found in cache,
     * it queries the database and stores the result in cache for future requests.
     *
     * @param string $slug The slug of the catalog to retrieve
     *
     * @return array|null The catalog data as an array, or null if not found
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     */
    public function getCatalogBySlug(string $slug): ?array
    {
        // Step 1: Try to get from cache
        $cacheKey = 'catalog_slug_' . $slug;
        $cachedCatalog = $this->cache->get($cacheKey);

        if ($cachedCatalog !== null) {
            $this->logger->debug('Catalog retrieved from cache', ['slug' => $slug]);
            return $cachedCatalog;
        }

        // Step 2: Not in cache, query the database
        $this->logger->debug('Catalog not in cache, querying database', ['slug' => $slug]);

        try {
            // Get catalog schema and register from config
            $schema = $this->config->getValueString($this->appName, 'catalog_schema', '');
            $register = $this->config->getValueString($this->appName, 'catalog_register', '');

            if (empty($schema) === true || empty($register) === true) {
                $this->logger->error(
                    'Catalog schema or register not found in config',
                    [
                        'slug' => $slug,
                        'schema' => $schema,
                        'register' => $register,
                    ]
                );
                return null;
            }

            $query = [
                '@self' => [
                    'register' => $register,
                    'schema' => $schema,
                ],
                'slug' => $slug,
                '_limit' => 1,
            ];

            $catalogs = $this->getObjectService()->searchObjects($query);

            if (empty($catalogs)) {
                $this->logger->error('Catalog not found', ['slug' => $slug]);
                return null;
            }

            $catalog = $catalogs[0]->jsonSerialize();

            // Step 3: Store in cache (TTL: 1 hour = 3600 seconds)
            $this->cache->set($cacheKey, $catalog, 3600);
            $this->logger->debug('Catalog stored in cache', ['slug' => $slug]);

            return $catalog;
        } catch (Exception $e) {
            $this->logger->error('Error retrieving catalog', [
                'slug' => $slug,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

    }//end getCatalogBySlug()


    /**
     * Invalidate the cache for a specific catalog.
     *
     * This method removes the catalog from cache, forcing the next request
     * to fetch fresh data from the database.
     *
     * @param string $slug The slug of the catalog to invalidate
     *
     * @return void
     */
    public function invalidateCatalogCache(string $slug): void
    {
        $cacheKey = 'catalog_slug_' . $slug;
        $this->cache->remove($cacheKey);
        $this->logger->debug('Catalog cache invalidated', ['slug' => $slug]);

    }//end invalidateCatalogCache()


    /**
     * Invalidate cache for a catalog by its ID.
     *
     * This method retrieves the catalog by ID to get its slug, then invalidates the cache.
     *
     * @param int|string $catalogId The ID of the catalog
     *
     * @return void
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     */
    public function invalidateCatalogCacheById(int|string $catalogId): void
    {
        try {
            $catalog = $this->getObjectService()->find($catalogId);
            $catalogData = $catalog->jsonSerialize();

            if (isset($catalogData['slug'])) {
                $this->invalidateCatalogCache($catalogData['slug']);
            }
        } catch (Exception $e) {
            $this->logger->error('Error invalidating catalog cache', [
                'catalogId' => $catalogId,
                'error' => $e->getMessage(),
            ]);
        }

    }//end invalidateCatalogCacheById()


    /**
     * Warm up the cache for a specific catalog.
     *
     * This method pre-loads the catalog into cache to improve performance
     * for subsequent requests.
     *
     * @param string $slug The slug of the catalog to warm up
     *
     * @return void
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     */
    public function warmupCatalogCache(string $slug): void
    {
        // Force a fresh load from database and store in cache
        $this->invalidateCatalogCache($slug);
        $this->getCatalogBySlug($slug);
        $this->logger->debug('Catalog cache warmed up', ['slug' => $slug]);

    }//end warmupCatalogCache()


    /**
     * Warm up cache for a catalog by its ID.
     *
     * @param int|string $catalogId The ID of the catalog
     *
     * @return void
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     */
    public function warmupCatalogCacheById(int|string $catalogId): void
    {
        try {
            $catalog = $this->getObjectService()->find($catalogId);
            $catalogData = $catalog->jsonSerialize();

            if (isset($catalogData['slug'])) {
                $this->warmupCatalogCache($catalogData['slug']);
            }
        } catch (Exception $e) {
            $this->logger->error('Error warming up catalog cache', [
                'catalogId' => $catalogId,
                'error' => $e->getMessage(),
            ]);
        }

    }//end warmupCatalogCacheById()


    /**
     * Retrieves a list of all objects for a specific register and schema
     *
     * This method returns a paginated list of objects that match the specified register and schema.
     * It supports filtering, sorting, and pagination through query parameters.
     *
     * @param ObjectService $objectService The object service
     *
     * @return JSONResponse A JSON response containing the list of objects
     *
     * @NoAdminRequired     *
     * @NoCSRFRequired
     */
    public function index(null|string|int $catalogId = null): JSONResponse
    {
        // Get config and fetch objects
        $config = $this->getConfig();

        // Get the context for the catalog
        $context = $this->getCatalogFilters($catalogId);

        $objectService = $this->getObjectService();

        // Build search query from config
        $query = [];
        if (!empty($context['registers']) || !empty($context['schemas'])) {
            $query['@self'] = [];
            if (!empty($context['registers'])) {
                $query['@self']['register'] = $context['registers'];
            }
            if (!empty($context['schemas'])) {
                $query['@self']['schema'] = $context['schemas'];
            }
        }

        // Add other filters from config
        if (!empty($config['filters'])) {
            foreach ($config['filters'] as $key => $value) {
                if (!in_array($key, ['register', 'schema'])) {
                    $query[$key] = $value;
                }
            }
        }

        // Add special parameters
        if (isset($config['limit'])) {
            $query['_limit'] = $config['limit'];
        }
        if (isset($config['offset'])) {
            $query['_offset'] = $config['offset'];
        }
        if (isset($config['page'])) {
            $query['_page'] = $config['page'];
        }
        if (isset($config['queries'])) {
            $query['_queries'] = $config['queries'];
        }
        if (isset($config['order'])) {
            $query['_order'] = $config['order'];
        }

        // Use searchObjectsPaginated which handles pagination internally
        $result = $objectService->searchObjectsPaginated($query);

        // Filter out unwanted properties from the '@self' array in each object
        $filteredResults = array_map(function ($object) {
            $objectArray = $object->jsonSerialize();

            //@todo: a loggedin user should be able to see the full object
            if (isset($objectArray['@self']) && is_array($objectArray['@self'])) {
                $unwantedProperties = [
                    'schemaVersion', 'relations', 'locked', 'owner', 'folder',
                    'application', 'validation', 'retention',
                    'size', 'deleted'
                ];
                // Remove unwanted properties from the '@self' array
                $objectArray['@self'] = array_diff_key($objectArray['@self'], array_flip($unwantedProperties));
            }
            return $objectArray;
        }, $result['results']);
        
        $result['results'] = $filteredResults;

        // Return paginated results
        return new JSONResponse($result);
    }//end index()

}//end class
