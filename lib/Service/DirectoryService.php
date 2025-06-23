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
     */
    public function __construct(
        private readonly IURLGenerator $urlGenerator,
        private readonly IAppConfig $config,
        private readonly ContainerInterface $container,
        private readonly IAppManager $appManager,
        private readonly BroadcastService $broadcastService
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
                    
                    // Log successful sync
                    \OC::$server->getLogger()->info(
                        'DirectoryService: Successfully synced directory: ' . $directoryUrl
                    );
                    
                    $resolve([
                        'success' => true,
                        'directory' => $directoryUrl,
                        'result' => $syncResult
                    ]);
                } catch (\Exception $e) {
                    // Log sync error
                    \OC::$server->getLogger()->error(
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
     * @return array<string> Array of unique directory URLs
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     * @throws DoesNotExistException|MultipleObjectsReturnedException
     */
    public function getUniqueDirectories(): array
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
                $config = [
                    'filters' => [
                        'register' => $listingRegister,
                        'schema' => $listingSchema
                    ]
                ];
                
                $listings = $objectService->findAll($config);
                
                // Build unique directory URLs using URL as key to automatically handle duplicates
                foreach ($listings as $listing) {
                    $listingData = $listing->jsonSerialize();
                    if (isset($listingData['directory']) && !empty($listingData['directory'])) {
                        $uniqueDirectoryUrls[$listingData['directory']] = $listingData['directory'];
                    }
                }
            } catch (\Exception $e) {
                \OC::$server->getLogger()->warning(
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
                        \OC::$server->getLogger()->debug(
                            'DirectoryService: Skipping local listing with directory: ' . $listingData['directory']
                        );
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
                if (!$hasOurListings && !$this->isLocalUrl($ourDirectoryUrl)) {
                    try {
                        $this->broadcastService->broadcast($directoryUrl);
                        \OC::$server->getLogger()->info(
                            'DirectoryService: Broadcasted to directory: ' . $directoryUrl . 
                            ' (they did not have our listings)'
                        );
                    } catch (\Exception $e) {
                        \OC::$server->getLogger()->warning(
                            'DirectoryService: Failed to broadcast to directory ' . $directoryUrl . ': ' . $e->getMessage()
                        );
                    }
                }
            }

            // Log successful sync
            \OC::$server->getLogger()->info(
                'DirectoryService: Successfully processed ' . $results['total_processed'] . 
                ' listings from directory: ' . $directoryUrl . 
                ' (Created: ' . $results['listings_created'] . 
                ', Updated: ' . $results['listings_updated'] . 
                ', Unchanged: ' . $results['listings_unchanged'] . 
                ', Skipped: ' . $results['listings_skipped'] . 
                ', Failed: ' . $results['listings_failed'] . ')'
            );

        } catch (GuzzleException $e) {
            $error = 'Failed to fetch directory data: ' . $e->getMessage();
            $results['errors'][] = $error;
            
            // Try to update existing listings with error status
            try {
                $this->updateDirectoryStatusOnError($directoryUrl, $e->getCode() ?: 500);
            } catch (\Exception $updateException) {
                \OC::$server->getLogger()->warning(
                    'DirectoryService: Failed to update directory error status: ' . $updateException->getMessage()
                );
            }
            
            throw new GuzzleException($error, $e->getCode(), $e);
        } catch (\Exception $e) {
            $error = 'Sync failed: ' . $e->getMessage();
            $results['errors'][] = $error;
            
            // Try to update existing listings with error status
            try {
                $this->updateDirectoryStatusOnError($directoryUrl, 500);
            } catch (\Exception $updateException) {
                \OC::$server->getLogger()->warning(
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
                
                \OC::$server->getLogger()->info(
                    'DirectoryService: Skipped listing ' . $result['listing_id'] . 
                    ' from ' . $sourceDirectoryUrl . ' because it belongs to ' . $listingData['directory'] . 
                    ' which is processed separately'
                );
                
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

            // Validate listing data
            if (empty($listingData['id']) || empty($listingData['catalog'])) {
                throw new \InvalidArgumentException('Invalid listing data: missing id or catalog');
            }

            // Clean up listing data to match schema
            // Keep the @self metadata for UUID handling, but clean it up
            $uuid = null;
            if (isset($listingData['@self']['id'])) {
                $uuid = $listingData['@self']['id'];
            } elseif (isset($listingData['id'])) {
                $uuid = $listingData['id'];
            } elseif (isset($listingData['catalog'])) {
                // Use catalog ID as UUID if no explicit ID is provided
                $uuid = $listingData['catalog'];
            }
            
            // Remove @self metadata from the object data (but keep UUID for saveObject)
            unset($listingData['@self']);
            
            // Set directory URL in listing data for reference
            $listingData['directory'] = $sourceDirectoryUrl;
            
            // Set lastSync as ISO string format instead of DateTime object
            $listingData['lastSync'] = (new \DateTime())->format('c');
            
            // Set catalogusId to the catalog ID, not the directory URL
            $listingData['catalogusId'] = $listingData['catalog'];
            
            // Set summary to 'unknown' if empty (required field)
            if (empty($listingData['summary'])) {
                $listingData['summary'] = 'unknown';
            }
            
            // Set additional directory properties for display
            $listingData['available'] = true; // Successfully fetched, so it's available
            $listingData['default'] = $sourceDirectoryUrl === 'https://directory.opencatalogi.nl/apps/opencatalogi/api/directory';
            $listingData['statusCode'] = 200; // Successful fetch
            
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
                    'catalog' => $listingData['catalog']
                ]
            ]);

            $isUpdate = !empty($existingListings);
            
            if ($isUpdate) {
                // For updates, check for race conditions and data changes
                $existingListing = $existingListings[0];
                $existingListingData = $existingListing->jsonSerialize();
                
                // Check for race condition: skip if incoming data is older than our last sync
                if ($this->isListingDataOutdated($listingData, $existingListingData)) {
                    $result['action'] = 'skipped_outdated';
                    $result['success'] = true;
                    $result['reason'] = 'Incoming listing data is older than existing data';
                    
                    \OC::$server->getLogger()->info(
                        'DirectoryService: Skipped outdated listing ' . $result['listing_id'] . 
                        ' from directory: ' . $sourceDirectoryUrl
                    );
                    
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
                \OC::$server->getLogger()->warning(
                    'DirectoryService: Failed to update listing error status: ' . $updateException->getMessage()
                );
            }
            
            \OC::$server->getLogger()->error(
                'DirectoryService: Failed to sync listing ' . $result['listing_id'] . ': ' . $e->getMessage()
            );
        }

        return $result;
    }

    /**
     * Get aggregated publications from all available listings asynchronously
     *
     * Fetches publications from all publication endpoints of listings marked as available,
     * combining results into a single array with proper error handling and status tracking.
     *
     * @param array $guzzleConfig Optional Guzzle configuration for HTTP requests
     * @param bool $includeDefault Whether to include only default listings or all available listings
     *
     * @return array<string, mixed> Array containing combined results and metadata
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     * @throws DoesNotExistException|MultipleObjectsReturnedException
     */
    public function getAggregatedPublications(array $guzzleConfig = [], bool $includeDefault = false): array
    {
        $startTime = microtime(true);
        
        // Initialize results structure
        $results = [
            'results' => [],
            'total' => 0,
            'sources' => [],
            'errors' => [],
            'statistics' => [
                'total_endpoints' => 0,
                'successful_calls' => 0,
                'failed_calls' => 0,
                'total_publications' => 0,
                'execution_time' => 0
            ]
        ];

        try {
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

            // Get listings with publication endpoints based on criteria
            $filters = [
                'register' => $listingRegister,
                'schema' => $listingSchema,
                'available' => true
            ];
            
            // Add default filter if only default listings should be included
            if ($includeDefault) {
                $filters['default'] = true;
            }
            
            $config = ['filters' => $filters];
            
            $listings = $objectService->findAll($config);
            
            // Extract unique publication endpoints
            $publicationEndpoints = [];
            $endpointToListing = [];
            
            foreach ($listings as $listing) {
                $listingData = $listing->jsonSerialize();
                $listingObject = $listingData['object'] ?? [];
                
                // Skip if no publication endpoint
                if (empty($listingObject['publications'])) {
                continue;
            }

                $endpoint = $listingObject['publications'];
                
                // Store unique endpoints and their source listings
                if (!isset($publicationEndpoints[$endpoint])) {
                    $publicationEndpoints[$endpoint] = [
                        'url' => $endpoint,
                        'listing_id' => $listingData['id'],
                        'listing_title' => $listingObject['title'] ?? 'Unknown',
                        'catalog_id' => $listingObject['catalogusId'] ?? null
                    ];
                    $endpointToListing[$endpoint] = $listingData['id'];
                }
            }

            $results['statistics']['total_endpoints'] = count($publicationEndpoints);

                        if (empty($publicationEndpoints)) {
                \OC::$server->getLogger()->info(
                    'DirectoryService: No available listings with publication endpoints found'
                );
                return $results;
            }

            // Prepare Guzzle client with default configuration
            $defaultGuzzleConfig = [
                RequestOptions::TIMEOUT => 30,
                RequestOptions::CONNECT_TIMEOUT => 10,
                RequestOptions::HEADERS => [
                    'Accept' => 'application/json',
                    'User-Agent' => 'OpenCatalogi-DirectoryService/1.0'
                ],
                RequestOptions::HTTP_ERRORS => false // Handle errors manually
            ];
            
            // Merge with provided configuration
            $finalGuzzleConfig = array_merge($defaultGuzzleConfig, $guzzleConfig);
            $client = new Client($finalGuzzleConfig);

            // Create promises for async requests
            $promises = [];
            foreach ($publicationEndpoints as $endpoint => $endpointData) {
                $promises[$endpoint] = new Promise(function ($resolve) use ($client, $endpoint, $endpointData, $objectService, $listingRegister, $listingSchema) {
                    try {
                        \OC::$server->getLogger()->debug(
                            'DirectoryService: Fetching publications from: ' . $endpoint
                        );
                        
                        $response = $client->get($endpoint);
                        $statusCode = $response->getStatusCode();
                        
                        $result = [
                            'endpoint' => $endpoint,
                            'listing_data' => $endpointData,
                            'status_code' => $statusCode,
                            'success' => false,
                            'publications' => [],
                            'total' => 0,
                            'error' => null
                        ];
                        
                        if ($statusCode >= 200 && $statusCode < 300) {
                            // Successful response
                            $body = $response->getBody()->getContents();
                            $data = json_decode($body, true);
                            
                            if (json_last_error() === JSON_ERROR_NONE && isset($data['results'])) {
                                $result['success'] = true;
                                $result['publications'] = is_array($data['results']) ? $data['results'] : [];
                                $result['total'] = count($result['publications']);
                                
                                // Update listing status to success
                                $this->updateListingStatus($objectService, $listingRegister, $listingSchema, $endpointData['listing_id'], $statusCode, true);
                                
                                \OC::$server->getLogger()->info(
                                    'DirectoryService: Successfully fetched ' . $result['total'] . 
                                    ' publications from: ' . $endpoint
                                );
                            } else {
                                $result['error'] = 'Invalid JSON response or missing results property';
                                $this->updateListingStatus($objectService, $listingRegister, $listingSchema, $endpointData['listing_id'], $statusCode, false);
                            }
                        } else {
                            // HTTP error status
                            $result['error'] = 'HTTP ' . $statusCode . ': ' . $response->getReasonPhrase();
                            $this->updateListingStatus($objectService, $listingRegister, $listingSchema, $endpointData['listing_id'], $statusCode, false);
                        }
                        
                        $resolve($result);
                        
                    } catch (RequestException $e) {
                        // Guzzle request exception
                        $statusCode = $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;
                        $result = [
                            'endpoint' => $endpoint,
                            'listing_data' => $endpointData,
                            'status_code' => $statusCode,
                            'success' => false,
                            'publications' => [],
                            'total' => 0,
                            'error' => 'Request failed: ' . $e->getMessage()
                        ];
                        
                        $this->updateListingStatus($objectService, $listingRegister, $listingSchema, $endpointData['listing_id'], $statusCode, false);
                        
                        \OC::$server->getLogger()->error(
                            'DirectoryService: Request failed for ' . $endpoint . ': ' . $e->getMessage()
                        );
                        
                        $resolve($result);
                        
                    } catch (\Exception $e) {
                        // Other exceptions
                        $result = [
                            'endpoint' => $endpoint,
                            'listing_data' => $endpointData,
                            'status_code' => 0,
                            'success' => false,
                            'publications' => [],
                            'total' => 0,
                            'error' => 'Unexpected error: ' . $e->getMessage()
                        ];
                        
                        $this->updateListingStatus($objectService, $listingRegister, $listingSchema, $endpointData['listing_id'], 0, false);
                        
                        \OC::$server->getLogger()->error(
                            'DirectoryService: Unexpected error for ' . $endpoint . ': ' . $e->getMessage()
                        );
                        
                        $resolve($result);
                    }
                });
            }

            // Execute all promises concurrently and wait for results
            $endpointResults = \React\Async\await(\React\Promise\all($promises));

            // Process results and combine publications
            $allPublications = [];
            $sourceInfo = [];
            
            foreach ($endpointResults as $endpointResult) {
                if ($endpointResult['success']) {
                    $results['statistics']['successful_calls']++;
                    
                    // Add source information to each publication
                    foreach ($endpointResult['publications'] as $publication) {
                        // Add metadata about the source
                        $publication['_source'] = [
                            'endpoint' => $endpointResult['endpoint'],
                            'listing_title' => $endpointResult['listing_data']['listing_title'],
                            'catalog_id' => $endpointResult['listing_data']['catalog_id']
                        ];
                        $allPublications[] = $publication;
                    }
                    
                    $results['statistics']['total_publications'] += $endpointResult['total'];
                    
                    // Track successful sources
                    $sourceInfo[] = [
                        'endpoint' => $endpointResult['endpoint'],
                        'listing_title' => $endpointResult['listing_data']['listing_title'],
                        'publication_count' => $endpointResult['total'],
                        'status' => 'success'
                    ];
                } else {
                    $results['statistics']['failed_calls']++;
                    
                    // Track failed sources
                    $sourceInfo[] = [
                        'endpoint' => $endpointResult['endpoint'],
                        'listing_title' => $endpointResult['listing_data']['listing_title'],
                        'publication_count' => 0,
                        'status' => 'error',
                        'error' => $endpointResult['error']
                    ];
                    
                    $results['errors'][] = [
                        'endpoint' => $endpointResult['endpoint'],
                        'listing_title' => $endpointResult['listing_data']['listing_title'],
                        'error' => $endpointResult['error'],
                        'status_code' => $endpointResult['status_code']
                    ];
                }
            }

            // Set final results
            $results['results'] = $allPublications;
            $results['total'] = count($allPublications);
            $results['sources'] = $sourceInfo;
            
            // Calculate execution time
            $results['statistics']['execution_time'] = round((microtime(true) - $startTime) * 1000, 2); // in milliseconds

            \OC::$server->getLogger()->info(
                'DirectoryService: Publication aggregation completed. ' .
                'Total: ' . $results['total'] . ' publications from ' . 
                $results['statistics']['successful_calls'] . '/' . $results['statistics']['total_endpoints'] . 
                ' endpoints in ' . $results['statistics']['execution_time'] . 'ms'
            );

        } catch (\Exception $e) {
            $results['errors'][] = [
                'type' => 'system_error',
                'error' => $e->getMessage(),
                'status_code' => 0
            ];
            
            \OC::$server->getLogger()->error(
                'DirectoryService: Failed to get publications: ' . $e->getMessage()
            );
        }

        return $results;
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
                
                \OC::$server->getLogger()->debug(
                    'DirectoryService: Updated listing status for ID ' . $listingId . 
                    ' - Status: ' . $statusCode . ', Available: ' . ($success ? 'true' : 'false')
                );
            }
        } catch (\Exception $e) {
            \OC::$server->getLogger()->warning(
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
            \OC::$server->getLogger()->debug(
                'DirectoryService: Found existing publication endpoint: ' . $listingData['publications']
            );
            return $listingData['publications'];
        }
        
        // Check if listing already has a publication endpoint (alternative field name)
        if (!empty($listingData['publication'])) {
            \OC::$server->getLogger()->debug(
                'DirectoryService: Found existing publication endpoint (singular): ' . $listingData['publication']
            );
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
                \OC::$server->getLogger()->info(
                    'DirectoryService: Generated publication endpoint from search URL. ' .
                    'Search: ' . $listingData['search'] . ', ' .
                    'Publications: ' . $publicationEndpoint
                );
                return $publicationEndpoint;
            }
        }
        
        \OC::$server->getLogger()->debug(
            'DirectoryService: Could not detect or generate publication endpoint for listing: ' . 
            ($listingData['title'] ?? $listingData['id'] ?? 'unknown')
        );
        
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
            
            if ($isOutdated) {
                \OC::$server->getLogger()->debug(
                    'DirectoryService: Detected outdated listing data. ' .
                    'Incoming: ' . $incomingUpdated->format('c') . ', ' .
                    'Existing: ' . $existingUpdated->format('c')
                );
            }
            
            return $isOutdated;
            
        } catch (\Exception $e) {
            // If we can't determine timestamps, log warning and allow update to be safe
            \OC::$server->getLogger()->warning(
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
            \OC::$server->getLogger()->warning(
                'DirectoryService: Failed to update directory error status for ' . $directoryUrl . ': ' . $e->getMessage()
            );
        }
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
}
