<?php
/**
 * DirectoryService
 *
 * Service for managing and synchronizing directories and listings.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service
 * @author   Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  GIT: <git_id>
 * @link     https://www.OpenCatalogi.nl
 */

namespace OCA\OpenCatalogi\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\RequestOptions;
use OCA\OpenCatalogi\Service\BroadcastService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\App\IAppManager;
use OCP\IServerContainer;
use OCP\IRequest;
use Psr\Container\ContainerInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use React\Promise\Promise;
use React\Promise\PromiseInterface;

/**
 * DirectoryService
 *
 * Service for managing and synchronizing directories and listings.
 * 
 * Includes anti-loop protection to prevent infinite broadcast cycles when
 * synchronizing with other OpenCatalogi instances.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service
 */
class DirectoryService
{
    /**
     * @var string The name of the app
     */
    private readonly string $appName;

    /**
     * @var Client The HTTP client for making requests
     */
    private readonly Client $client;

    /**
     * @var array<string> Cached unique directory URLs for cross-directory checks
     */
    private array $uniqueDirectories = [];

    /**
     * Constructor for DirectoryService
     *
     * Initializes the DirectoryService with required dependencies for managing
     * and synchronizing directories and listings.
     *
     * @param IURLGenerator      $urlGenerator     URL generator interface
     * @param IAppConfig         $config           App configuration interface  
     * @param ContainerInterface $container        Server container for dependency injection
     * @param IAppManager        $appManager       App manager for checking installed apps
     * @param BroadcastService   $broadcastService Broadcast service for notifying other directories
     * @param IServerContainer   $server          Server container for logging and other services
     * @param IRequest           $request         Request interface for accessing HTTP headers
     */
    public function __construct(
        private readonly IURLGenerator $urlGenerator,
        private readonly IAppConfig $config,
        private readonly ContainerInterface $container,
        private readonly IAppManager $appManager,
        private readonly BroadcastService $broadcastService,
        private readonly IServerContainer $server,
        private readonly IRequest $request
    ) {
        $this->appName = 'opencatalogi';
        $this->client = new Client([]);
    }

    /**
     * Execute synchronization during cron job (asynchronous)
     *
     * Performs scheduled synchronization of all configured directories
     * asynchronously using React PHP promises for better performance.
     *
     * @return array<string, mixed> Array containing synchronization results
     * @throws DoesNotExistException|MultipleObjectsReturnedException
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     * @throws GuzzleException
     */
    public function doCronSync(): array
    {
        // Get all unique directory URLs to sync and cache them globally
        $this->uniqueDirectories = $this->getUniqueDirectories();
        
        // Add default OpenCatalogi directory if not already present
        $defaultDirectory = 'https://directory.opencatalogi.nl/apps/opencatalogi/api/directory';
        if (!in_array($defaultDirectory, $this->uniqueDirectories)) {
            $this->uniqueDirectories[] = $defaultDirectory;
        }
        
        $uniqueDirectoryUrls = $this->uniqueDirectories;
        
        $results = [
            'total_directories' => count($uniqueDirectoryUrls),
            'synced_directories' => 0,
            'failed_directories' => 0,
            'errors' => []
        ];

        // Create promises for async directory synchronization
        $syncPromises = [];
        foreach ($uniqueDirectoryUrls as $directoryUrl) {
            $syncPromises[] = new Promise(function ($resolve) use ($directoryUrl) {
                try {
                    $syncResult = $this->syncDirectory($directoryUrl);
                    
                    // Directory sync completed successfully
                    
                    $resolve([
                        'success' => true,
                        'directory' => $directoryUrl,
                        'result' => $syncResult
                    ]);
                } catch (\Exception $e) {
                    // Log sync error
                    $this->server->getLogger()->error(
                        'DirectoryService: Failed to sync directory ' . $directoryUrl . ': ' . $e->getMessage()
                    );
                    
                    $resolve([
                        'success' => false,
                        'directory' => $directoryUrl,
                        'error' => $e->getMessage()
                    ]);
                }
            });
        }

        // Execute all directory sync promises concurrently
        $syncResults = \React\Async\await(\React\Promise\all($syncPromises));

        // Process results
        foreach ($syncResults as $syncResult) {
            if ($syncResult['success']) {
                $results['synced_directories']++;
            } else {
                $results['failed_directories']++;
                $results['errors'][] = [
                    'directory' => $syncResult['directory'],
                    'error' => $syncResult['error']
                ];
            }
        }

        return $results;
    }

    /**
     * Get unique directory URLs from stored listings
     *
     * Retrieves all unique directory URLs from listings that are currently 
     * stored in the system and available for synchronization.
     *
     * @param bool $availableOnly Whether to include only available listings
     * @param bool $defaultOnly Whether to include only default listings
     * @return array<string> Array of unique directory URLs
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     * @throws DoesNotExistException|MultipleObjectsReturnedException
     */
    public function getUniqueDirectories(bool $availableOnly = false, bool $defaultOnly = false): array
    {
        // Check if OpenRegister service is available
        if (!in_array('openregister', $this->appManager->getInstalledApps())) {
            throw new \RuntimeException('OpenRegister service is not available.');
        }

        // Get ObjectService from container
        $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        
        // Get listing configuration
        $listingSchema = $this->config->getValueString($this->appName, 'listing_schema', '');
        $listingRegister = $this->config->getValueString($this->appName, 'listing_register', '');

        $uniqueDirectoryUrls = [];

        // Get listings if configuration is available
        if (!empty($listingSchema) && !empty($listingRegister)) {
            try {
                $filters = [
                    'register' => $listingRegister,
                    'schema' => $listingSchema
                ];
                
                // Add optional filters
                if ($availableOnly) {
                    $filters['available'] = true;
                }
                
                if ($defaultOnly) {
                    $filters['default'] = true;
                }
                
                $config = ['filters' => $filters];
                $listings = $objectService->findAll($config);
                
                // Build unique directory URLs using URL as key to automatically handle duplicates
                foreach ($listings as $listing) {
                    $listingData = $listing->jsonSerialize();
                    if (isset($listingData['directory']) && !empty($listingData['directory'])) {
                        $uniqueDirectoryUrls[$listingData['directory']] = $listingData['directory'];
                    }
                }
            } catch (\Exception $e) {
                $this->server->getLogger()->warning(
                    'DirectoryService: Failed to get unique directories: ' . $e->getMessage()
                );
            }
        }

        // Return just the unique URLs as an indexed array
        return array_values($uniqueDirectoryUrls);
    }

    /**
     * Synchronize a specific directory (asynchronous)
     *
     * Synchronizes listings and catalogs from a specific external directory URL
     * asynchronously using React PHP promises for better performance.
     * 
     * To prevent infinite broadcast loops, this method checks if the current request
     * is from a system broadcast (identified by User-Agent header containing 
     * 'OpenCatalogi-Broadcast'). If so, it will sync but not broadcast back.
     *
     * @param string $directoryUrl The URL of the directory to synchronize
     * 
     * @return array<string, mixed> Array containing sync results and statistics
     * @throws GuzzleException
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     * @throws DoesNotExistException|MultipleObjectsReturnedException
     * @throws \InvalidArgumentException If directory URL is invalid
     */
    public function syncDirectory(string $directoryUrl): array
    {
        // Ensure unique directories are cached for cross-directory checks
        if (empty($this->uniqueDirectories)) {
            $this->uniqueDirectories = $this->getUniqueDirectories();
            
            // Add default OpenCatalogi directory if not already present
            $defaultDirectory = 'https://directory.opencatalogi.nl/apps/opencatalogi/api/directory';
            if (!in_array($defaultDirectory, $this->uniqueDirectories)) {
                $this->uniqueDirectories[] = $defaultDirectory;
            }
        }
        
        // Validate directory URL
        if (empty($directoryUrl)) {
            throw new \InvalidArgumentException('Directory URL cannot be empty');
        }

        if (filter_var($directoryUrl, FILTER_VALIDATE_URL) === false) {
            throw new \InvalidArgumentException('Invalid directory URL provided');
        }

        // Prevent syncing with self
        if (str_contains(strtolower($directoryUrl), $this->urlGenerator->getBaseUrl())) {
            throw new \InvalidArgumentException('Cannot sync with current directory');
        }

        // Initialize results
        $results = [
            'directory_url' => $directoryUrl,
            'sync_time' => new \DateTime(),
            'listings_created' => 0,
            'listings_updated' => 0,
            'listings_unchanged' => 0,
            'listings_skipped' => 0,
            'listings_failed' => 0,
            'total_processed' => 0,
            'errors' => [],
            'listing_details' => []
        ];

        try {
            // Fetch directory data with limit to get all listings
            $directoryUrlWithLimit = $directoryUrl . '?_limit=10000';
            $response = $this->client->get($directoryUrlWithLimit);
            $directoryData = json_decode($response->getBody()->getContents(), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \InvalidArgumentException('Invalid JSON response from directory');
            }

            // Get our own directory URL for filtering
            $ourDirectoryUrl = $this->urlGenerator->getAbsoluteURL(
                $this->urlGenerator->linkToRoute('opencatalogi.directory.index')
            );

            // Process directory results asynchronously
            if (isset($directoryData['results']) && is_array($directoryData['results'])) {
                // Filter out listings that have our directory URL to prevent syncing ourselves
                // Also filter out listings with localhost or .local extensions
                $filteredListings = array_filter($directoryData['results'], function ($listingData) use ($ourDirectoryUrl) {
                    // Skip if listing has our directory URL (prevent self-sync)
                    if (isset($listingData['directory']) && $listingData['directory'] === $ourDirectoryUrl) {
                        return false;
                    }
                    
                    // Skip if listing has a local URL (localhost, .local, private IPs)
                    if (isset($listingData['directory']) && $this->isLocalUrl($listingData['directory'])) {
                        return false;
                    }
                    
                    return true;
                });

                // Check if the directory has any listings from our directory (before filtering)
                $hasOurListings = count($directoryData['results']) > count($filteredListings);

                $listingPromises = [];
                
                // Create promises for each filtered listing sync
                foreach ($filteredListings as $listingData) {
                    $listingPromises[] = new Promise(function ($resolve) use ($listingData, $directoryUrl) {
                        $resolve($this->syncListing($listingData, $directoryUrl));
                    });
                }

                // Execute all listing sync promises concurrently
                if (!empty($listingPromises)) {
                    $listingResults = \React\Async\await(\React\Promise\all($listingPromises));
                    
                    // Process results
                    foreach ($listingResults as $listingResult) {
                        $results['total_processed']++;
                        $results['listing_details'][] = $listingResult;
                        
                        if ($listingResult['success']) {
                            switch ($listingResult['action']) {
                                case 'created':
                                    $results['listings_created']++;
                                    break;
                                case 'updated':
                                    $results['listings_updated']++;
                                    break;
                                case 'unchanged':
                                    $results['listings_unchanged']++;
                                    break;
                                case 'skipped_outdated':
                                case 'skipped_other_directory':
                                    $results['listings_skipped']++;
                                    break;
                            }
                        } else {
                            $results['listings_failed']++;
                            if ($listingResult['error']) {
                                $results['errors'][] = 'Listing ' . $listingResult['listing_id'] . ': ' . $listingResult['error'];
                            }
                        }
                    }
                }

                // Broadcast to the directory if it doesn't have our listings and our URL is not local
                // Skip broadcasting if this sync was triggered by a system broadcast to prevent infinite loops
                if (!$hasOurListings && !$this->isLocalUrl($ourDirectoryUrl) && !$this->isSystemBroadcast()) {
                    try {
                        $this->broadcastService->broadcast($directoryUrl);
                    } catch (\Exception $e) {
                        $this->server->getLogger()->warning(
                            'DirectoryService: Failed to broadcast to directory ' . $directoryUrl . ': ' . $e->getMessage()
                        );
                    }
                } elseif ($this->isSystemBroadcast()) {
                    // Log that we're skipping broadcast due to system broadcast to avoid loops
                    $this->server->getLogger()->debug(
                        'DirectoryService: Skipping broadcast to ' . $directoryUrl . ' as this sync was triggered by a system broadcast'
                    );
                }
            }

            // Log significant failures only
            if ($results['listings_failed'] > 0) {
                $this->server->getLogger()->warning(
                    'DirectoryService: Directory sync had failures - ' . $directoryUrl . 
                    ' - Failed: ' . $results['listings_failed']
                );
            }

        } catch (GuzzleException $e) {
            $error = 'Failed to fetch directory data: ' . $e->getMessage();
            $results['errors'][] = $error;
            
            // Try to update existing listings with error status
            try {
                $this->updateDirectoryStatusOnError($directoryUrl, $e->getCode() ?: 500);
            } catch (\Exception $updateException) {
                $this->server->getLogger()->warning(
                    'DirectoryService: Failed to update directory error status: ' . $updateException->getMessage()
                );
            }
            
            // Re-throw as a RequestException (concrete GuzzleException implementation)
            if ($e instanceof RequestException) {
                throw new RequestException($error, $e->getRequest(), $e->getResponse(), $e);
            } else {
                throw new RequestException($error, null, null, $e);
            }
        } catch (\Exception $e) {
            $error = 'Sync failed: ' . $e->getMessage();
            $results['errors'][] = $error;
            
            // Try to update existing listings with error status
            try {
                $this->updateDirectoryStatusOnError($directoryUrl, 500);
            } catch (\Exception $updateException) {
                $this->server->getLogger()->warning(
                    'DirectoryService: Failed to update directory error status: ' . $updateException->getMessage()
                );
            }
            
            throw $e;
        }

        return $results;
    }

    /**
     * Synchronize a single listing from directory data
     *
     * Processes an individual listing from a directory response, validates it,
     * and saves or updates it in the local storage.
     * 
     * For new listings, sets 'available' and 'default' to false (conservative approach).
     * For updates, preserves existing 'available' and 'default' values to avoid overwriting status.
     *
     * @param array $listingData The listing data to synchronize
     * @param string $sourceDirectoryUrl The source directory URL for reference
     *
     * @return array<string, mixed> Array containing sync results for this listing
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     * @throws DoesNotExistException|MultipleObjectsReturnedException
     */
    public function syncListing(array $listingData, string $sourceDirectoryUrl): array
    {
        $result = [
            'listing_id' => $listingData['id'] ?? 'unknown',
            'listing_title' => $listingData['title'] ?? 'Unknown',
            'action' => 'none',
            'success' => false,
            'error' => null
        ];

        try {
            // Check if this listing belongs to a different directory that we already have as a source
            if (isset($listingData['directory']) && 
                !empty($listingData['directory']) && 
                $listingData['directory'] !== $sourceDirectoryUrl &&
                in_array($listingData['directory'], $this->uniqueDirectories)) {
                
                $result['action'] = 'skipped_other_directory';
                $result['success'] = true;
                $result['reason'] = 'Listing belongs to directory ' . $listingData['directory'] . ' which is processed separately';
                
                // No need to log routine skips
                
                return $result;
            }
            
            // Check if OpenRegister service is available
            if (!in_array('openregister', $this->appManager->getInstalledApps())) {
                throw new \RuntimeException('OpenRegister service is not available.');
            }

            // Get ObjectService from container
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            
            // Get listing configuration
            $listingSchema = $this->config->getValueString($this->appName, 'listing_schema', '');
            $listingRegister = $this->config->getValueString($this->appName, 'listing_register', '');

            if (empty($listingSchema) || empty($listingRegister)) {
                throw new \RuntimeException('Listing schema or register not configured');
            }

            // Validate listing data - only accept 'catalogusId'
            if (empty($listingData['id']) || empty($listingData['catalogusId'])) {
                throw new \InvalidArgumentException('Invalid listing data: missing id or catalogusId');
            }

            $catalogId = $listingData['catalogusId'];

            // Clean up listing data to match schema
            // Keep the @self metadata for UUID handling, but clean it up
            $uuid = null;
            if (isset($listingData['@self']['id'])) {
                $uuid = $listingData['@self']['id'];
            } elseif (isset($listingData['id'])) {
                $uuid = $listingData['id'];
            } elseif (isset($listingData['catalogusId'])) {
                // Use catalogusId as UUID if no explicit ID is provided
                $uuid = $listingData['catalogusId'];
            }
            
            // Remove @self metadata from the object data (but keep UUID for saveObject)
            unset($listingData['@self']);
            
            // Set directory URL in listing data for reference
            $listingData['directory'] = $sourceDirectoryUrl;
            
            // Set lastSync as ISO string format instead of DateTime object
            $listingData['lastSync'] = (new \DateTime())->format('c');
            
            // Set catalog field from catalogusId for internal consistency
            $listingData['catalog'] = $catalogId;
            
            // Set summary to 'unknown' if empty (required field)
            if (empty($listingData['summary'])) {
                $listingData['summary'] = 'unknown';
            }
            
            // Count schemas if available
            if (isset($listingData['schemas']) && is_array($listingData['schemas'])) {
                $listingData['schemaCount'] = count($listingData['schemas']);
            } else {
                $listingData['schemaCount'] = 0;
            }
            
            // Detect or generate publication endpoint
            $listingData['publications'] = $this->detectPublicationEndpoint($listingData);

            // Check if listing already exists to determine action type
            $existingListings = $objectService->findAll([
                'filters' => [
                    'register' => $listingRegister,
                    'schema' => $listingSchema,
                    'catalog' => $catalogId
                ]
            ]);

            $isUpdate = !empty($existingListings);
            
            // Set directory properties based on whether it's new or updated
            if ($isUpdate) {
                // For updates, preserve existing available and default values
                $existingListing = $existingListings[0];
                $existingListingData = $existingListing->jsonSerialize();
                $existingObject = $existingListingData['object'] ?? [];
                                
                // Preserve existing availability and default status, but set smart defaults for missing fields
                $listingData['available'] = $existingObject['available'] ?? true; // Default to true if not set (successful sync)
                $listingData['default'] = $existingObject['default'] ?? ($sourceDirectoryUrl === 'https://directory.opencatalogi.nl/apps/opencatalogi/api/directory');
                $listingData['statusCode'] = 200; // Update status code to show successful fetch
            } else {
                // For new listings, start with conservative defaults
                $listingData['available'] = false; // New listings start as unavailable until proven
                $listingData['default'] = false; // New listings are not default by default
                $listingData['statusCode'] = 200; // Successful fetch
            }
            
            if ($isUpdate) {
                // For updates, check for race conditions and data changes
                // (existingListing and existingListingData already retrieved above)
                
                // Check for race condition: skip if incoming data is older than our last sync
                if ($this->isListingDataOutdated($listingData, $existingListingData)) {
                    $result['action'] = 'skipped_outdated';
                    $result['success'] = true;
                    $result['reason'] = 'Incoming listing data is older than existing data';
                    
                    // Skipping outdated listing (no logging needed for routine operations)
                    
                    return $result;
                }
                
                // Check if listing has actually changed using hash comparison
                $newHash = hash('sha256', json_encode($listingData));
                $oldHash = hash('sha256', json_encode($existingListingData['object'] ?? []));
                
                if ($newHash === $oldHash) {
                    $result['action'] = 'unchanged';
                    $result['success'] = true;
                } else {
                    // Use existing UUID for update
                    $uuid = $existingListingData['id'];
                    
                    // Use saveObject which respects hard validation settings
                    $objectService->saveObject(
                        object: $listingData,
                        register: $listingRegister,
                        schema: $listingSchema,
                        uuid: $uuid
                    );
                    
                    $result['action'] = 'updated';
                    $result['success'] = true;
                }
            } else {
                // Create new listing using saveObject
                $objectService->saveObject(
                    object: $listingData,
                    register: $listingRegister,
                    schema: $listingSchema,
                    uuid: $uuid
                );
                
                $result['action'] = 'created';
                $result['success'] = true;
            }

        } catch (\Exception $e) {
            $result['error'] = $e->getMessage();
            
            // Try to update the listing with error status if it exists
            try {
                if (!empty($existingListings)) {
                    $existingListing = $existingListings[0];
                    $existingListingData = $existingListing->jsonSerialize();
                    $errorData = $existingListingData['object'] ?? [];
                    
                    // Update with error status
                    $errorData['available'] = false;
                    $errorData['statusCode'] = 500; // Internal server error
                    $errorData['lastSync'] = (new \DateTime())->format('c');
                    
                    $objectService->saveObject(
                        object: $errorData,
                        register: $listingRegister,
                        schema: $listingSchema,
                        uuid: $existingListingData['id']
                    );
                }
            } catch (\Exception $updateException) {
                // Ignore update errors, just log them
                $this->server->getLogger()->warning(
                    'DirectoryService: Failed to update listing error status: ' . $updateException->getMessage()
                );
            }
            
            $this->server->getLogger()->error(
                'DirectoryService: Failed to sync listing ' . $result['listing_id'] . ': ' . $e->getMessage()
            );
        }

        return $result;
    }

    /**
     * Get publications from all available federated catalogs asynchronously
     *
     * Fetches publications from all publication endpoints of listings marked as available,
     * combining results into a single array.
     *
     * @param array $guzzleConfig Optional Guzzle configuration for HTTP requests
     * @param bool $includeDefault Whether to include only default listings or all available listings
     *
     * @return array Array containing combined publications
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     * @throws DoesNotExistException|MultipleObjectsReturnedException
     */
    public function getPublications(array $guzzleConfig = [], bool $includeDefault = false): array
    {
        // Get directories based on criteria
        $directories = $this->getUniqueDirectories(availableOnly: true, defaultOnly: $includeDefault);
        
        if (empty($directories)) {
            return ['results' => [], 'sources' => []];
        }

        // Get our own directory URL to exclude from search
        $ourDirectoryUrl = $this->urlGenerator->getAbsoluteURL(
            $this->urlGenerator->linkToRoute('opencatalogi.directory.index')
        );

        // Prepare Guzzle client
        $defaultGuzzleConfig = [
            RequestOptions::TIMEOUT => 30,
            RequestOptions::CONNECT_TIMEOUT => 10,
            RequestOptions::HEADERS => [
                'Accept' => 'application/json',
                'User-Agent' => 'OpenCatalogi-DirectoryService/1.0'
            ],
            RequestOptions::HTTP_ERRORS => false
        ];
        
        $finalGuzzleConfig = array_merge($defaultGuzzleConfig, $guzzleConfig);
        $queryParams = $finalGuzzleConfig['query_params'] ?? [];
        $queryParams['_aggregate'] = 'false'; // Prevent circular aggregation
        $queryParams['_extend'] = ['@self.schema', '@self.register']; // Add self-extension
        
        $client = new Client($finalGuzzleConfig);
        $promises = [];
        $urlToDirectoryMap = [];

        // Create promises for each directory
        foreach ($directories as $index => $directoryUrl) {
            // Skip our own directory and local URLs
            if ($directoryUrl === $ourDirectoryUrl || $this->isLocalUrl($directoryUrl)) {
                continue;
            }

            // Build the publications endpoint URL
            // Convert directory URL to publications URL by replacing /directory with /publications
            $publicationsUrl = str_replace('/api/directory', '/api/publications', rtrim($directoryUrl, '/'));
            
            if (!empty($queryParams)) {
                $publicationsUrl .= '?' . http_build_query($queryParams);
            }
            
            // Store mapping for later source tracking
            $urlToDirectoryMap[count($promises)] = [
                'url' => $publicationsUrl,
                'directoryUrl' => $directoryUrl,
                'name' => parse_url($directoryUrl, PHP_URL_HOST) ?: $directoryUrl
            ];
            
            $promises[] = new Promise(function ($resolve) use ($client, $publicationsUrl) {
                try {
                    $response = $client->get($publicationsUrl);
                    $statusCode = $response->getStatusCode();
                    
                    if ($statusCode >= 200 && $statusCode < 300) {
                        $body = $response->getBody()->getContents();
                        $data = json_decode($body, true);
                        
                        if (json_last_error() === JSON_ERROR_NONE) {
                            // Handle different response formats
                            if (isset($data['results']) && is_array($data['results'])) {
                                $resolve(['success' => true, 'results' => $data['results']]);
                            } elseif (is_array($data)) {
                                $resolve(['success' => true, 'results' => $data]);
                            } else {
                                $resolve(['success' => false, 'results' => []]);
                            }
                        } else {
                            $resolve(['success' => false, 'results' => []]);
                        }
                    } else {
                        $resolve(['success' => false, 'results' => []]);
                    }
                } catch (\Exception $e) {
                    // Only log actual errors, not expected failures
                    if (!str_contains($e->getMessage(), 'Could not resolve host')) {
                        $this->server->getLogger()->error('DirectoryService: Federation request failed to ' . $publicationsUrl . ': ' . $e->getMessage());
                    }
                    $resolve(['success' => false, 'results' => []]);
                }
            });
        }

        // Execute all promises and collect results
        $allResults = \React\Async\await(\React\Promise\all($promises));
        
        // Flatten and deduplicate results, track sources
        $combinedResults = [];
        $seenIds = [];
        $sources = [];
        
        foreach ($allResults as $index => $result) {
            $directoryInfo = $urlToDirectoryMap[$index];
            
            if ($result['success'] && !empty($result['results'])) {
                $sources[$directoryInfo['name']] = $directoryInfo['url'];
                
                foreach ($result['results'] as $item) {
                    $itemId = $item['id'] ?? $item['uuid'] ?? uniqid();
                    if (!isset($seenIds[$itemId])) {
                        $combinedResults[] = $item;
                        $seenIds[$itemId] = true;
                    }
                }
            }
        }



        return [
            'results' => $combinedResults,
            'sources' => $sources
        ];
    }

    /**
     * Update listing status after publication endpoint call
     *
     * Updates the listing with the latest status code and availability based on
     * the result of calling its publication endpoint.
     *
     * @param object $objectService The OpenRegister ObjectService instance
     * @param string $listingRegister The listing register ID
     * @param string $listingSchema The listing schema ID
     * @param string $listingId The listing ID to update
     * @param int $statusCode The HTTP status code from the endpoint call
     * @param bool $success Whether the call was successful
     *
     * @return void
     */
    private function updateListingStatus($objectService, string $listingRegister, string $listingSchema, string $listingId, int $statusCode, bool $success): void
    {
        try {
            // Get the existing listing
            $existingListings = $objectService->findAll([
                'filters' => [
                    'register' => $listingRegister,
                    'schema' => $listingSchema,
                    'id' => $listingId
                ]
            ]);
            
            if (!empty($existingListings)) {
                $existingListing = $existingListings[0];
                $existingListingData = $existingListing->jsonSerialize();
                $listingObject = $existingListingData['object'] ?? [];
                
                // Update status information
                $listingObject['statusCode'] = $statusCode;
                $listingObject['available'] = $success;
                $listingObject['lastSync'] = (new \DateTime())->format('c');
                
                // Save the updated listing
                $objectService->saveObject(
                    object: $listingObject,
                    register: $listingRegister,
                    schema: $listingSchema,
                    uuid: $existingListingData['id']
                );
                

            }
        } catch (\Exception $e) {
            $this->server->getLogger()->warning(
                'DirectoryService: Failed to update listing status for ID ' . $listingId . ': ' . $e->getMessage()
            );
        }
    }

    /**
     * Detect or generate publication endpoint for a listing
     *
     * Checks if the listing has a publication endpoint, and if not,
     * generates one based on the search endpoint by replacing 'search' with 'publications'.
     *
     * @param array $listingData The listing data to process
     *
     * @return string|null The publication endpoint URL or null if cannot be determined
     */
    private function detectPublicationEndpoint(array $listingData): ?string
    {
        // Check if listing already has a publication endpoint
        if (!empty($listingData['publications'])) {
            return $listingData['publications'];
        }
        
        // Check if listing already has a publication endpoint (alternative field name)
        if (!empty($listingData['publication'])) {
            return $listingData['publication'];
        }
        
        // Try to generate from search endpoint
        if (!empty($listingData['search'])) {
            // Replace 'search' with 'publications' in the URL
            $publicationEndpoint = str_replace('/search', '/publications', $listingData['search']);
            
            // Also handle cases where 'search' might be a query parameter or different pattern
            if ($publicationEndpoint === $listingData['search']) {
                // Try replacing 'search' anywhere in the URL path
                $publicationEndpoint = preg_replace('/\/search(?=\/|$)/', '/publications', $listingData['search']);
            }
            
            // If still no change, try a more generic approach
            if ($publicationEndpoint === $listingData['search']) {
                // Parse URL and replace 'search' in path segments
                $urlParts = parse_url($listingData['search']);
                if ($urlParts && isset($urlParts['path'])) {
                    $pathSegments = explode('/', trim($urlParts['path'], '/'));
                    $pathSegments = array_map(function($segment) {
                        return $segment === 'search' ? 'publications' : $segment;
                    }, $pathSegments);
                    
                    $newPath = '/' . implode('/', $pathSegments);
                    $publicationEndpoint = $urlParts['scheme'] . '://' . $urlParts['host'];
                    if (isset($urlParts['port'])) {
                        $publicationEndpoint .= ':' . $urlParts['port'];
                    }
                    $publicationEndpoint .= $newPath;
                    if (isset($urlParts['query'])) {
                        $publicationEndpoint .= '?' . $urlParts['query'];
                    }
                }
            }
            
            // Only return if we actually made a change
            if ($publicationEndpoint !== $listingData['search']) {
                return $publicationEndpoint;
            }
        }
        
        return null;
    }

    /**
     * Check if incoming listing data is outdated compared to existing data
     *
     * Prevents race conditions by comparing timestamps to ensure we don't
     * overwrite newer data with older data from different directories.
     *
     * @param array $incomingData The incoming listing data from directory
     * @param array $existingData The existing listing data from database
     *
     * @return bool True if incoming data is outdated and should be skipped
     */
    private function isListingDataOutdated(array $incomingData, array $existingData): bool
    {
        try {
            // Get existing object data
            $existingObject = $existingData['object'] ?? [];
            
            // Get the last sync time from existing data
            $existingLastSync = $existingObject['lastSync'] ?? null;
            
            // If no existing last sync, allow the update
            if (empty($existingLastSync)) {
                return false;
            }
            
            // Get timestamps for comparison
            $incomingUpdated = $this->extractTimestamp($incomingData);
            $existingUpdated = $this->extractTimestamp($existingObject);
            
            // If we can't determine timestamps, allow the update to be safe
            if ($incomingUpdated === null || $existingUpdated === null) {
                return false;
            }
            
            // Skip if incoming data is older than existing data
            $isOutdated = $incomingUpdated < $existingUpdated;
            
            // Incoming data is outdated, skip silently
            
            return $isOutdated;
            
        } catch (\Exception $e) {
            // If we can't determine timestamps, log warning and allow update to be safe
            $this->server->getLogger()->warning(
                'DirectoryService: Failed to check listing timestamp: ' . $e->getMessage()
            );
            return false;
        }
    }

    /**
     * Extract timestamp from listing data for comparison
     *
     * Tries to find the most relevant timestamp for determining data freshness.
     * Looks for updated, created, or @self.updated timestamps.
     *
     * @param array $data The listing data to extract timestamp from
     *
     * @return \DateTime|null The extracted timestamp or null if not found
     */
    private function extractTimestamp(array $data): ?\DateTime
    {
        // Priority order for timestamp fields
        $timestampFields = [
            'updated',           // Standard updated field
            '@self.updated',     // Metadata updated field
            'created',           // Creation timestamp
            '@self.created',     // Metadata creation timestamp
            'lastSync'           // Last sync timestamp
        ];
        
        foreach ($timestampFields as $field) {
            $value = null;
            
            // Handle nested field notation (e.g., '@self.updated')
            if (str_contains($field, '.')) {
                $parts = explode('.', $field);
                $value = $data;
                foreach ($parts as $part) {
                    if (isset($value[$part])) {
                        $value = $value[$part];
                    } else {
                        $value = null;
                        break;
                    }
                }
            } else {
                $value = $data[$field] ?? null;
            }
            
            if ($value !== null) {
                try {
                    // Handle different timestamp formats
                    if (is_string($value)) {
                        return new \DateTime($value);
                    } elseif (is_array($value) && isset($value['date'])) {
                        // Handle DateTime object serialized as array
                        return new \DateTime($value['date']);
                    } elseif ($value instanceof \DateTime) {
                        return $value;
                    }
                } catch (\Exception $e) {
                    // Continue to next field if this one is invalid
                    continue;
                }
            }
        }
        
        return null;
    }

    /**
     * Update directory status on error
     *
     * Updates existing listings from a directory with error status when
     * the directory sync fails at the HTTP level.
     *
     * @param string $directoryUrl The directory URL that failed
     * @param int $statusCode The HTTP status code of the error
     *
     * @return void
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     */
    private function updateDirectoryStatusOnError(string $directoryUrl, int $statusCode): void
    {
        try {
            // Check if OpenRegister service is available
            if (!in_array('openregister', $this->appManager->getInstalledApps())) {
                return; // Can't update if OpenRegister is not available
            }

            // Get ObjectService from container
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            
            // Get listing configuration
            $listingSchema = $this->config->getValueString($this->appName, 'listing_schema', '');
            $listingRegister = $this->config->getValueString($this->appName, 'listing_register', '');

            if (empty($listingSchema) || empty($listingRegister)) {
                return; // Can't update without schema/register configuration
            }

            // Find all listings from this directory
            $existingListings = $objectService->findAll([
                'filters' => [
                    'register' => $listingRegister,
                    'schema' => $listingSchema,
                    'directory' => $directoryUrl
                ]
            ]);

            // Update each listing with error status
            foreach ($existingListings as $listing) {
                $listingData = $listing->jsonSerialize();
                $errorData = $listingData['object'] ?? [];
                
                // Update with error status
                $errorData['available'] = false;
                $errorData['statusCode'] = $statusCode;
                $errorData['lastSync'] = (new \DateTime())->format('c');
                
                $objectService->saveObject(
                    object: $errorData,
                    register: $listingRegister,
                    schema: $listingSchema,
                    uuid: $listingData['id']
                );
            }

        } catch (\Exception $e) {
            // Log but don't throw - this is a best-effort update
            $this->server->getLogger()->warning(
                'DirectoryService: Failed to update directory error status for ' . $directoryUrl . ': ' . $e->getMessage()
            );
        }
    }

    /**
     * Check if the current request is from a system broadcast
     *
     * Determines if the current HTTP request is from another OpenCatalogi instance
     * sending a broadcast notification, to prevent infinite broadcast loops.
     *
     * @return bool True if request is from a system broadcast, false otherwise
     */
    private function isSystemBroadcast(): bool
    {
        $userAgent = $this->request->getHeader('User-Agent');
        
        // Check if User-Agent contains 'OpenCatalogi-Broadcast' (future-proof for version changes)
        return !empty($userAgent) && str_contains($userAgent, 'OpenCatalogi-Broadcast');
    }

    /**
     * Check if a URL is considered local
     *
     * Determines if a URL represents a local address that should not be
     * used for broadcasting (localhost, 127.0.0.1, local domain, etc.).
     *
     * @param string $url The URL to check
     *
     * @return bool True if the URL is local, false otherwise
     */
    private function isLocalUrl(string $url): bool
    {
        $parsedUrl = parse_url($url);
        
        if (!$parsedUrl || !isset($parsedUrl['host'])) {
            return true; // Invalid URL is considered local
        }

        $host = strtolower($parsedUrl['host']);

        // Check for localhost, local IPs, and local domains
        $localPatterns = [
            'localhost',
            '127.0.0.1',
            '::1',
            '0.0.0.0'
        ];

        // Check exact matches
        if (in_array($host, $localPatterns)) {
            return true;
        }

        // Check for private IP ranges (192.168.x.x, 10.x.x.x, 172.16-31.x.x)
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return true;
            }
        }

        // Check for .local domain
        if (str_ends_with($host, '.local')) {
            return true;
        }

        return false;
    }

    /**
     * Get objects from other catalogs that use/reference our local object
     *
     * Calls the /publications/{uuid}/used endpoint on all available federated
     * catalogs to find objects that reference or use the specified local object UUID.
     *
     * @param string $uuid The UUID of the local object to find uses for
     * @param array $guzzleConfig Optional Guzzle configuration for HTTP requests
     *
     * @return array Array with 'results' and 'sources' keys
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     * @throws DoesNotExistException|MultipleObjectsReturnedException
     */
    public function getUsed(string $uuid, array $guzzleConfig = []): array
    {
        // Get available listings with publication endpoints
        $directories = $this->getUniqueDirectories(availableOnly: true);
        
        if (empty($directories)) {
            return ['results' => [], 'sources' => []];
        }

        // Get our own directory URL to exclude from search
        $ourDirectoryUrl = $this->urlGenerator->getAbsoluteURL(
            $this->urlGenerator->linkToRoute('opencatalogi.directory.index')
        );

        // Prepare Guzzle client
        $defaultGuzzleConfig = [
            RequestOptions::TIMEOUT => 30,
            RequestOptions::CONNECT_TIMEOUT => 10,
            RequestOptions::HEADERS => [
                'Accept' => 'application/json',
                'User-Agent' => 'OpenCatalogi-DirectoryService/1.0'
            ],
            RequestOptions::HTTP_ERRORS => false
        ];
        
        $finalGuzzleConfig = array_merge($defaultGuzzleConfig, $guzzleConfig);
        $queryParams = $finalGuzzleConfig['query_params'] ?? [];
        $queryParams['_aggregate'] = 'false'; // Prevent circular aggregation
        $queryParams['_extend'] = ['@self.schema', '@self.register']; // Add self-extension
        
        $client = new Client($finalGuzzleConfig);
        $promises = [];
        $urlToDirectoryMap = [];

        // Create promises for each directory
        foreach ($directories as $index => $directoryUrl) {
            // Skip our own directory and local URLs
            if ($directoryUrl === $ourDirectoryUrl || $this->isLocalUrl($directoryUrl)) {
                continue;
            }

            // Build the /used endpoint URL
            // Convert directory URL to publications URL by replacing /api/directory with /api/publications
            $baseUrl = str_replace('/api/directory', '/api/publications', rtrim($directoryUrl, '/'));
            $usedUrl = $baseUrl . '/' . urlencode($uuid) . '/used';
            
            if (!empty($queryParams)) {
                $usedUrl .= '?' . http_build_query($queryParams);
            }
            
            // Store mapping for later source tracking
            $urlToDirectoryMap[count($promises)] = [
                'url' => $usedUrl,
                'directoryUrl' => $directoryUrl,
                'name' => parse_url($directoryUrl, PHP_URL_HOST) ?: $directoryUrl
            ];
            
            $promises[] = new Promise(function ($resolve) use ($client, $usedUrl) {
                try {
                    $response = $client->get($usedUrl);
                    
                    if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                        $data = json_decode($response->getBody()->getContents(), true);
                        
                        if (json_last_error() === JSON_ERROR_NONE) {
                            // Handle different response formats
                            if (isset($data['results']) && is_array($data['results'])) {
                                $resolve(['success' => true, 'results' => $data['results']]);
                            } elseif (is_array($data)) {
                                $resolve(['success' => true, 'results' => $data]);
                            } else {
                                $resolve(['success' => false, 'results' => []]);
                            }
                        } else {
                            $resolve(['success' => false, 'results' => []]);
                        }
                    } else {
                        $resolve(['success' => false, 'results' => []]);
                    }
                } catch (\Exception $e) {
                    $resolve(['success' => false, 'results' => []]);
                }
            });
        }

        // Execute all promises and collect results
        $allResults = \React\Async\await(\React\Promise\all($promises));
        
        // Flatten and deduplicate results, track sources
        $combinedResults = [];
        $seenIds = [];
        $sources = [];
        
        foreach ($allResults as $index => $result) {
            $directoryInfo = $urlToDirectoryMap[$index];
            
            if ($result['success'] && !empty($result['results'])) {
                $sources[$directoryInfo['name']] = $directoryInfo['url'];
                
                foreach ($result['results'] as $item) {
                    $itemId = $item['id'] ?? $item['uuid'] ?? uniqid();
                    if (!isset($seenIds[$itemId])) {
                        $combinedResults[] = $item;
                        $seenIds[$itemId] = true;
                    }
                }
            }
        }

        return [
            'results' => $combinedResults,
            'sources' => $sources
        ];
    }

    /**
     * Get a single publication from federated catalogs by ID
     *
     * Searches across all available federated catalogs to find a specific
     * publication by its ID. Returns the first match found with source information.
     *
     * @param string $publicationId The ID of the publication to find
     * @param array $guzzleConfig Optional Guzzle configuration for HTTP requests
     *
     * @return array|null Array containing publication data and source, or null if not found
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     * @throws DoesNotExistException|MultipleObjectsReturnedException
     */
    public function getPublication(string $publicationId, array $guzzleConfig = []): ?array
    {
        // Get available directories
        $directories = $this->getUniqueDirectories(availableOnly: true);
        
        if (empty($directories)) {
            return null;
        }

        // Get our own directory URL to exclude from search
        $ourDirectoryUrl = $this->urlGenerator->getAbsoluteURL(
            $this->urlGenerator->linkToRoute('opencatalogi.directory.index')
        );

        // Prepare Guzzle client
        $defaultGuzzleConfig = [
            RequestOptions::TIMEOUT => 30,
            RequestOptions::CONNECT_TIMEOUT => 10,
            RequestOptions::HEADERS => [
                'Accept' => 'application/json',
                'User-Agent' => 'OpenCatalogi-DirectoryService/1.0'
            ],
            RequestOptions::HTTP_ERRORS => false
        ];
        
        $finalGuzzleConfig = array_merge($defaultGuzzleConfig, $guzzleConfig);
        $queryParams = $finalGuzzleConfig['query_params'] ?? [];
        $queryParams['_aggregate'] = 'false'; // Prevent circular aggregation
        $queryParams['_extend'] = ['@self.schema', '@self.register']; // Add self-extension
        
        $client = new Client($finalGuzzleConfig);
        $promises = [];
        $urlToDirectoryMap = [];

        // Create promises for each directory
        foreach ($directories as $index => $directoryUrl) {
            // Skip our own directory and local URLs
            if ($directoryUrl === $ourDirectoryUrl || $this->isLocalUrl($directoryUrl)) {
                continue;
            }

            // Build the publication endpoint URL
            // Convert directory URL to publications URL by replacing /api/directory with /api/publications
            $baseUrl = str_replace('/api/directory', '/api/publications', rtrim($directoryUrl, '/'));
            $publicationUrl = $baseUrl . '/' . urlencode($publicationId);
            
            if (!empty($queryParams)) {
                $publicationUrl .= '?' . http_build_query($queryParams);
            }
            
            // Store mapping for later source tracking
            $urlToDirectoryMap[count($promises)] = [
                'url' => $publicationUrl,
                'directoryUrl' => $directoryUrl,
                'name' => parse_url($directoryUrl, PHP_URL_HOST) ?: $directoryUrl
            ];
            
            $promises[] = new Promise(function ($resolve) use ($client, $publicationUrl) {
                try {
                    $response = $client->get($publicationUrl);
                    
                    if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                        $data = json_decode($response->getBody()->getContents(), true);
                        
                        if (json_last_error() === JSON_ERROR_NONE && !empty($data)) {
                            $resolve(['success' => true, 'data' => $data]);
                            return;
                        }
                    }
                    
                    $resolve(['success' => false, 'data' => null]);
                } catch (\Exception $e) {
                    $resolve(['success' => false, 'data' => null]);
                }
            });
        }

        // Execute all promises and return first successful result with source
        $allResults = \React\Async\await(\React\Promise\all($promises));
        
                 // Return the first successful result with source information
         foreach ($allResults as $index => $result) {
             if ($result['success'] && $result['data'] !== null) {
                 $directoryInfo = $urlToDirectoryMap[$index];
                 return [
                     'result' => $result['data'],
                     'source' => [
                         $directoryInfo['name'] => $directoryInfo['url']
                     ]
                 ];
             }
         }
         
         return null;
    }

    /**
     * Get directory entries (listings and catalogs formatted as listings)
     *
     * Retrieves both discovered listings and local catalogs, formatting catalogs
     * as listing objects according to the publication register schema.
     *
     * @param array $requestParams Request parameters for filtering, pagination, etc.
     * @return array<string, mixed> Array containing results and total count
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     * @throws DoesNotExistException|MultipleObjectsReturnedException
     */
    public function getDirectory(array $requestParams = []): array
    {
        // Check if OpenRegister service is available
        if (!in_array('openregister', $this->appManager->getInstalledApps())) {
            throw new \RuntimeException('OpenRegister service is not available.');
        }

        // Get ObjectService from container
        $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        
        // Get configuration
        $listingSchema = $this->config->getValueString($this->appName, 'listing_schema', '');
        $listingRegister = $this->config->getValueString($this->appName, 'listing_register', '');
        $catalogSchema = $this->config->getValueString($this->appName, 'catalog_schema', 'catalog');
        $catalogRegister = $this->config->getValueString($this->appName, 'catalog_register', '');



        $allResults = [];

        // Build base config for filters and pagination
        $baseConfig = [
            'filters' => []
        ];
        
        // Add any additional filters from request params
        if (isset($requestParams['filters'])) {
            $baseConfig['filters'] = array_merge($baseConfig['filters'], $requestParams['filters']);
        }
        
        // Add pagination params
        if (isset($requestParams['limit'])) {
            $baseConfig['limit'] = (int) $requestParams['limit'];
        }
        if (isset($requestParams['offset'])) {
            $baseConfig['offset'] = (int) $requestParams['offset'];
        }

        // Fetch discovered listings
        if (!empty($listingSchema) && !empty($listingRegister)) {
            $listingConfig = $baseConfig;
            $listingConfig['filters']['schema'] = $listingSchema;
            $listingConfig['filters']['register'] = $listingRegister;

            try {
                $listingResult = $objectService->findAll($listingConfig);
                
                // Convert listing objects to arrays and filter out internal properties
                // Note: Don't expand schemas for listings as they already come with expanded schemas from external directories
                $listings = array_map(function ($object) {
                    $listingData = $object instanceof \OCP\AppFramework\Db\Entity ? $object->jsonSerialize() : $object;
                    return $this->filterListingProperties($listingData);
                }, $listingResult['results'] ?? $listingResult ?? []);

                $allResults = array_merge($allResults, $listings);
            } catch (\Exception $e) {
                $this->server->getLogger()->warning(
                    'DirectoryService: Failed to fetch listings: ' . $e->getMessage()
                );
            }
        }

        // Fetch local catalogs and convert them to listing format
        if (!empty($catalogSchema) && !empty($catalogRegister)) {
            $catalogConfig = $baseConfig;
            $catalogConfig['filters']['schema'] = $catalogSchema;
            $catalogConfig['filters']['register'] = $catalogRegister;

            try {
                $catalogResult = $objectService->findAll($catalogConfig);
                
                // Convert catalog objects to listing format and expand schemas
                $catalogsAsListings = array_map(function ($catalogObject) {
                    $listing = $this->convertCatalogToListing($catalogObject);
                    return $this->processSchemaExpansion($listing);
                }, $catalogResult['results'] ?? $catalogResult ?? []);

                $allResults = array_merge($allResults, $catalogsAsListings);
            } catch (\Exception $e) {
                $this->server->getLogger()->warning(
                    'DirectoryService: Failed to fetch catalogs: ' . $e->getMessage()
                );
            }
        }

        // Calculate total
        $total = count($allResults);



        return [
            'results' => $allResults,
            'total' => $total
        ];
    }

    /**
     * Convert a catalog object to listing format
     *
     * Transforms a catalog object into a listing object format according to the
     * publication register schema, ensuring all required fields are present.
     *
     * @param mixed $catalogObject The catalog object to convert
     * @return array The catalog formatted as a listing object
     */
    private function convertCatalogToListing($catalogObject): array
    {
        // Extract catalog data
        $catalogData = $catalogObject instanceof \OCP\AppFramework\Db\Entity 
            ? $catalogObject->jsonSerialize() 
            : $catalogObject;
        
        $catalog = $catalogData['object'] ?? $catalogData;
        
        // Get our directory URL for the listing
        $directoryUrl = $this->urlGenerator->getAbsoluteURL(
            $this->urlGenerator->linkToRoute('opencatalogi.directory.index')
        );
        
        // Get our search and publications URLs
        $searchUrl = $this->urlGenerator->getAbsoluteURL(
            $this->urlGenerator->linkToRoute('opencatalogi.search.index')
        );
        $publicationsUrl = $this->urlGenerator->getAbsoluteURL(
            $this->urlGenerator->linkToRoute('opencatalogi.publications.index')
        );

        // Create listing object from catalog - only core API fields
        $listing = [
            // Required fields for listing
            'id' => $catalog['id'] ?? $catalogData['id'] ?? '',
            'catalogusId' => $catalog['id'] ?? $catalogData['id'] ?? '',
            'title' => $catalog['title'] ?? 'Unknown Catalog',
            'summary' => $catalog['summary'] ?? $catalog['description'] ?? 'Local catalog',
            
            // Optional fields from catalog
            'description' => $catalog['description'] ?? null,
            'organization' => $catalog['organization'] ?? null,
            'schemas' => $catalog['schemas'] ?? [],
            
            // Directory-specific fields
            'directory' => $directoryUrl,
            'search' => $searchUrl,
            'publications' => $publicationsUrl
        ];

        return $listing;
    }

    /**
     * Filter listing object to remove properties not meant for external API
     *
     * Removes internal properties and unwanted properties that shouldn't be exposed 
     * in the public API, keeping only the core listing properties.
     *
     * @param array $listing The listing object to filter
     * @return array The filtered listing object with only public properties
     */
    private function filterListingProperties(array $listing): array
    {
        // Extract the actual object data
        $objectData = $listing['object'] ?? $listing;
        
        // List of properties to remove for external API
        $propertiesToRemove = [
            // Internal/system properties 
            'status',
            'statusCode', 
            'lastSync',
            'available',
            'default',
            // Unwanted properties as requested
            'metadata',
            'image',
            'listed',
            'filters'
        ];
        
        // Remove unwanted properties
        foreach ($propertiesToRemove as $property) {
            unset($objectData[$property]);
        }
        
        // If this was a nested object structure, maintain it
        if (isset($listing['object'])) {
            $listing['object'] = $objectData;
            return $listing;
        }
        
        // Otherwise return the cleaned object directly
        return $objectData;
    }

    /**
     * Convert catalogi to listings format
     *
     * Public method to convert catalog objects to listing format for external use.
     * This is the main function for translating catalogi to listings.
     *
     * @param array $catalogs Array of catalog objects to convert
     * @return array Array of catalogs converted to listing format with expanded schemas
     */
    public function convertCatalogiToListings(array $catalogs): array
    {
        return array_map(function ($catalogObject) {
            $listing = $this->convertCatalogToListing($catalogObject);
            return $this->processSchemaExpansion($listing);
        }, $catalogs);
    }

    /**
     * Expand schema IDs to full schema objects
     *
     * Takes an array of schema IDs and returns the corresponding full schema objects
     * using the OpenRegister SchemaMapper.
     *
     * @param array $schemaIds Array of schema IDs to expand
     * @return array Array of full schema objects
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     */
    private function expandSchemas(array $schemaIds): array
    {
        if (empty($schemaIds)) {
            return [];
        }

        // Check if OpenRegister service is available
        if (!in_array('openregister', $this->appManager->getInstalledApps())) {
            return $schemaIds; // Return IDs if OpenRegister is not available
        }

        try {
            // Get SchemaMapper from container
            $schemaMapper = $this->container->get('OCA\OpenRegister\Db\SchemaMapper');
            
            // Use findMultiple to get all schemas in one call
            $schemas = $schemaMapper->findMultiple($schemaIds);
            
            // Convert schema entities to arrays
            return array_map(function ($schema) {
                return $schema instanceof \OCP\AppFramework\Db\Entity ? $schema->jsonSerialize() : $schema;
            }, $schemas);
            
        } catch (\Exception $e) {
            $this->server->getLogger()->warning(
                'DirectoryService: Failed to expand schemas: ' . $e->getMessage()
            );
            // Return original IDs if expansion fails
            return $schemaIds;
        }
    }

    /**
     * Process listing or catalog data to expand schema IDs
     *
     * Takes listing or catalog data and expands any schema IDs to full objects.
     *
     * @param array $data The listing or catalog data to process
     * @return array The processed data with expanded schemas
     */
    private function processSchemaExpansion(array $data): array
    {
        // Extract the actual object data
        $objectData = $data['object'] ?? $data;
        
        // Expand schemas if they exist and are an array of IDs
        if (isset($objectData['schemas']) && is_array($objectData['schemas'])) {
            $objectData['schemas'] = $this->expandSchemas($objectData['schemas']);
        }
        
        // If this was a nested object structure, maintain it
        if (isset($data['object'])) {
            $data['object'] = $objectData;
            return $data;
        }
        
        // Otherwise return the processed object directly
        return $objectData;
    }
}
