<?php
/**
 * DirectoryService
 *
 * Service for managing and synchronizing directories and listings.
 *
 * @category  Service
 * @package   OCA\OpenCatalogi\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenCatalogi.nl
 */

namespace OCA\OpenCatalogi\Service;

use DateTime;
use InvalidArgumentException;
use RuntimeException;
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
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class DirectoryService
{

    /**
     * Default directory URLs to sync from. These are the central OpenCatalogi registries.
     * opencatalogi.nl proxies to the same Nextcloud backend as directory.opencatalogi.nl,
     * serving as a backup in case one endpoint is unavailable.
     *
     * @var array<string>
     */
    private const DEFAULT_DIRECTORIES = [
        'https://directory.opencatalogi.nl/apps/opencatalogi/api/directory',
        'https://opencatalogi.nl/api/apps/opencatalogi/api/directory',
    ];

    /**
     * The name of the app.
     *
     * @var string
     */
    private readonly string $appName;

    /**
     * The HTTP client for making requests.
     *
     * @var Client
     */
    private readonly Client $client;

    /**
     * Cached unique directory URLs for cross-directory checks.
     *
     * @var array<string>
     */
    private array $uniqueDirectories = [];

    /**
     * Cached unique directories to avoid repeated database queries.
     *
     * @var array|null
     */
    private ?array $cachedUniqueDirs = null;

    /**
     * Cache timestamp to determine if cache is still valid (5 minute TTL).
     *
     * @var integer
     */
    private int $cacheTimestamp = 0;

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
     * @param IRequest           $request          Request interface for accessing HTTP headers
     */
    public function __construct(
        private readonly IURLGenerator $urlGenerator,
        private readonly IAppConfig $config,
        private readonly ContainerInterface $container,
        private readonly IAppManager $appManager,
        private readonly BroadcastService $broadcastService,
        private readonly IRequest $request
    ) {
        $this->appName = 'opencatalogi';
        $this->client  = new Client([]);

    }//end __construct()

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
     *
     * @psalm-suppress InvalidArgument React Promise resolve callbacks receive arrays
     */
    public function doCronSync(): array
    {
        // Get all unique directory URLs to sync and cache them globally.
        $this->uniqueDirectories = $this->getUniqueDirectories();

        // Add default OpenCatalogi directories if not already present.
        foreach (self::DEFAULT_DIRECTORIES as $defaultDirectory) {
            if (in_array($defaultDirectory, $this->uniqueDirectories) === false) {
                $this->uniqueDirectories[] = $defaultDirectory;
            }
        }

        $uniqueDirectoryUrls = $this->uniqueDirectories;

        $results = [
            'total_directories'  => count($uniqueDirectoryUrls),
            'synced_directories' => 0,
            'failed_directories' => 0,
            'errors'             => [],
        ];

        // Create promises for async directory synchronization.
        $syncPromises = [];
        foreach ($uniqueDirectoryUrls as $directoryUrl) {
            $syncPromises[] = new Promise(
                function ($resolve) use ($directoryUrl) {
                    try {
                        $syncResult = $this->syncDirectory($directoryUrl);

                        // Directory sync completed successfully.
                        $resolve(
                            [
                                'success'   => true,
                                'directory' => $directoryUrl,
                                'result'    => $syncResult,
                            ]
                        );
                    } catch (\Exception $e) {
                        // Removed redundant logging (error handled silently).
                        $resolve(
                            [
                                'success'   => false,
                                'directory' => $directoryUrl,
                                'error'     => $e->getMessage(),
                            ]
                        );
                    }//end try
                }
            );
        }//end foreach

        // Execute all directory sync promises concurrently.
        $syncResults = \React\Async\await(\React\Promise\all($syncPromises));

        // Process results.
        foreach ($syncResults as $syncResult) {
            if ($syncResult['success'] === true) {
                $results['synced_directories']++;
                continue;
            }

            $results['failed_directories']++;
            $results['errors'][] = [
                'directory' => $syncResult['directory'],
                'error'     => $syncResult['error'],
            ];
        }

        return $results;

    }//end doCronSync()

    /**
     * Get unique directory URLs from stored listings
     *
     * Retrieves all unique directory URLs from listings that are currently
     * stored in the system and available for synchronization.
     *
     * @param boolean $availableOnly Whether to include only available listings
     * @param boolean $defaultOnly   Whether to include only default listings
     *
     * @return array<string> Array of unique directory URLs
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     * @throws DoesNotExistException|MultipleObjectsReturnedException
     *
     * @psalm-suppress InvalidArgument
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function getUniqueDirectories(bool $availableOnly=false, bool $defaultOnly=false): array
    {
        // Check cache validity (5 minute TTL).
        $currentTime = time();
        if ($this->cachedUniqueDirs !== null && ($currentTime - $this->cacheTimestamp) < 300) {
            return $this->cachedUniqueDirs;
        }

        // Check if OpenRegister service is available..
        if (in_array('openregister', $this->appManager->getInstalledApps()) === false) {
            throw new RuntimeException('OpenRegister service is not available.');
        }

        // Get ObjectService from container..
        $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

        // Get listing configuration.
        $listingSchema   = $this->config->getValueString($this->appName, 'listing_schema', '');
        $listingRegister = $this->config->getValueString($this->appName, 'listing_register', '');

        // Removed redundant logging.
        $uniqueDirectoryUrls = [];

        // Get listings if configuration is available.
        if (empty($listingSchema) === false && empty($listingRegister) === false) {
            try {
                $query = [
                    '@self' => [
                        'register' => $listingRegister,
                        'schema'   => $listingSchema,
                    ],
                ];

                // Directory data is public by design — listings/catalogs have authorization.read=["public"].
                // Disable RBAC and multitenancy so public directory discovery works without user context.
                $listings = $objectService->searchObjects($query, _rbac: false, _multitenancy: false);

                // Build unique directory URLs using URL as key to automatically handle duplicates.
                foreach ($listings as $listing) {
                    $listingData = $listing->jsonSerialize();
                    $objectData  = ($listingData['object'] ?? $listingData);

                    $available = ($objectData['integrationLevel'] ?? null) === 'search';
                    $default   = $objectData['default'] ?? false;

                    // Removed redundant logging.
                    // Apply post-query filtering for nested object properties.
                    if ($availableOnly === true && $available === false) {
                        // Skip unavailable listings.
                        continue;
                    }

                    if ($defaultOnly === true && $default === false) {
                        // Skip non-default listings.
                        continue;
                    }

                    // Check for publications URL in the object data (primary) or directory URL (fallback).
                    if (isset($objectData['publications']) === true && empty($objectData['publications']) === false) {
                        $uniqueDirectoryUrls[$objectData['publications']] = $objectData['publications'];
                        // Removed redundant logging (error handled silently).
                    }

                    // If no publications URL found, skip this listing.
                    // We used to have fallback logic here that would try to use the directory field.
                    // but that often pointed to the source directory (where we got the listing from).
                    // rather than the catalog's own API, causing circular queries.
                }//end foreach
            } catch (\Exception $e) {
                // Removed redundant logging.
            }//end try
        }//end if

        // Return just the unique URLs as an indexed array.
        $result = array_values($uniqueDirectoryUrls);

        // Cache the result with current timestamp.
        $this->cachedUniqueDirs = $result;
        $this->cacheTimestamp   = $currentTime;

        return $result;

    }//end getUniqueDirectories()

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
     *
     * @psalm-suppress InvalidArgument React Promise resolve callbacks receive arrays
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function syncDirectory(string $directoryUrl): array
    {
        // Ensure unique directories are cached for cross-directory checks.
        if (empty($this->uniqueDirectories) === true) {
            $this->uniqueDirectories = $this->getUniqueDirectories();

            // Add default OpenCatalogi directories if not already present.
            foreach (self::DEFAULT_DIRECTORIES as $defaultDirectory) {
                if (in_array($defaultDirectory, $this->uniqueDirectories) === false) {
                    $this->uniqueDirectories[] = $defaultDirectory;
                }
            }
        }

        // Validate directory URL.
        if (empty($directoryUrl) === true) {
            throw new InvalidArgumentException('Directory URL cannot be empty');
        }

        if (filter_var($directoryUrl, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('Invalid directory URL provided');
        }

        // Prevent syncing with self.
        if (str_contains(strtolower($directoryUrl), $this->urlGenerator->getBaseUrl()) === true) {
            throw new InvalidArgumentException('Cannot sync with current directory');
        }

        // Initialize results.
        $results = [
            'directory_url'      => $directoryUrl,
            'sync_time'          => new DateTime(),
            'listings_created'   => 0,
            'listings_updated'   => 0,
            'listings_unchanged' => 0,
            'listings_skipped'   => 0,
            'listings_failed'    => 0,
            'total_processed'    => 0,
            'errors'             => [],
            'listing_details'    => [],
        ];

        try {
            // Fetch directory data with limit to get all listings.
            $dirUrlWithLimit = $directoryUrl.'?_limit=10000';
            $response        = $this->client->get($dirUrlWithLimit);
            $directoryData   = json_decode($response->getBody()->getContents(), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new InvalidArgumentException('Invalid JSON response from directory');
            }

            // Get our own directory URL for filtering.
            $ourDirectoryUrl = $this->urlGenerator->getAbsoluteURL(
                $this->urlGenerator->linkToRoute('opencatalogi.directory.index')
            );

            // Process directory results asynchronously.
            if (isset($directoryData['results']) === true && is_array($directoryData['results']) === true) {
                // Filter out listings that have our directory URL to prevent syncing ourselves.
                // Also filter out listings with localhost or .local extensions.
                $filteredListings = array_filter(
                    $directoryData['results'],
                    function ($listingData) use ($ourDirectoryUrl) {
                        // Skip if listing has our directory URL (prevent self-sync).
                        if (isset($listingData['directory']) === true && $listingData['directory'] === $ourDirectoryUrl) {
                            return false;
                        }

                        // Skip if listing has a local URL (localhost, .local, private IPs).
                        if (isset($listingData['directory']) === true && $this->isLocalUrl($listingData['directory']) === true) {
                            return false;
                        }

                        return true;
                    }
                );

                // Check if the directory has any listings from our directory (before filtering).
                $hasOurListings = count($directoryData['results']) > count($filteredListings);

                $listingPromises = [];

                // Create promises for each filtered listing sync.
                foreach ($filteredListings as $listingData) {
                    $listingPromises[] = new Promise(
                        function ($resolve) use ($listingData, $directoryUrl) {
                            $resolve($this->syncListing($listingData, $directoryUrl));
                        }
                    );
                }

                // Execute all listing sync promises concurrently.
                if (empty($listingPromises) === false) {
                    try {
                        $listingResults = \React\Async\await(\React\Promise\all($listingPromises));
                    } catch (\Exception $e) {
                        // If any promise fails, handle gracefully by creating error results.
                        $listingResults = [];
                        $promiseCount   = count($listingPromises);
                        for ($i = 0; $i < $promiseCount; $i++) {
                            $listingResults[] = [
                                'listing_id'    => 'unknown',
                                'listing_title' => 'Failed Promise',
                                'action'        => 'failed',
                                'success'       => false,
                                'error'         => 'Promise execution failed: '.$e->getMessage(),
                            ];
                        }
                    }

                    // Process results.
                    foreach ($listingResults as $listingResult) {
                        $results['total_processed']++;
                        $results['listing_details'][] = $listingResult;

                        if ($listingResult['success'] === true) {
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
                        }

                        if ($listingResult['success'] === false) {
                            $results['listings_failed']++;
                            if ($listingResult['error'] !== null) {
                                $results['errors'][] = 'Listing '.$listingResult['listing_id'].': '.$listingResult['error'];
                            }
                        }
                    }//end foreach
                }//end if

                // Broadcast to the directory if it doesn't have our listings and our URL is not local.
                // Skip broadcasting if this sync was triggered by a system broadcast to prevent infinite loops.
                if ($hasOurListings === false && $this->isLocalUrl($ourDirectoryUrl) === false && $this->isSystemBroadcast() === false) {
                    try {
                        $this->broadcastService->broadcast($directoryUrl);
                    } catch (\Exception $e) {
                        // Removed redundant logging (error handled silently).
                    }
                } else if ($this->isSystemBroadcast() === true) {
                    // Removed redundant logging.
                }
            }//end if
        } catch (GuzzleException $e) {
            $error = 'Failed to fetch directory data: '.$e->getMessage();
            $results['errors'][] = $error;

            // Try to update existing listings with error status.
            try {
                $errorCode = $e->getCode();
                if ($errorCode === 0) {
                    $errorCode = 500;
                }

                $this->updateDirectoryStatusOnError($directoryUrl, $errorCode);
            } catch (\Exception $updateException) {
                // Removed redundant logging.
            }

            // Re-throw as a RequestException (concrete GuzzleException implementation).
            if ($e instanceof RequestException) {
                throw new RequestException(
                    message: $error,
                    request: $e->getRequest(),
                    response: $e->getResponse(),
                    previous: $e
                );
            }

            throw new RequestException(
                message: $error,
                request: null,
                response: null,
                previous: $e
            );
        } catch (\Exception $e) {
            $error = 'Sync failed: '.$e->getMessage();
            $results['errors'][] = $error;

            // Try to update existing listings with error status.
            try {
                $this->updateDirectoryStatusOnError($directoryUrl, 500);
            } catch (\Exception $updateException) {
                // Removed redundant logging.
            }

            throw $e;
        }//end try

        return $results;

    }//end syncDirectory()

    /**
     * Synchronize a single listing from directory data
     *
     * Processes an individual listing from a directory response, validates it,
     * and saves or updates it in the local storage.
     *
     * For new listings, sets 'available' and 'default' to false (conservative approach).
     * For updates, preserves existing 'available' and 'default' values to avoid overwriting status.
     *
     * @param array  $listingData        The listing data to synchronize
     * @param string $sourceDirectoryUrl The source directory URL for reference
     *
     * @return array<string, mixed> Array containing sync results for this listing
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     * @throws DoesNotExistException|MultipleObjectsReturnedException
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function syncListing(array $listingData, string $sourceDirectoryUrl): array
    {
        // Extract fields from OpenRegister object structure.
        $listingId    = ($listingData['id'] ?? null);
        $listingTitle = ($listingData['title'] ?? $listingData['@self']['name'] ?? 'Unknown');

        // Extract catalog ID from relations.catalog or use id as fallback.
        $catalogId = null;
        if (isset($listingData['catalog']) === true) {
            // Catalog field at top level.
            $catalogId = $listingData['catalog'];
        } else if (isset($listingData['@self']['relations']['catalog']) === true) {
            // Catalog in relations.
            $catalogId = $listingData['@self']['relations']['catalog'];
        } else if (empty($listingId) === false) {
            // Fallback: use id as catalog (for listings, catalog ID is often the same as listing ID).
            $catalogId = $listingId;
        }

        // Extract directory from relations if present.
        $listingDirectory = ($listingData['directory'] ?? $listingData['@self']['relations']['directory'] ?? null);

        $result = [
            'listing_id'    => ($listingId ?? 'unknown'),
            'listing_title' => $listingTitle,
            'action'        => 'none',
            'success'       => false,
            'error'         => null,
        ];

        // Initialize variables used in both try and catch blocks.
        $existingListings = [];
        $objectService    = null;
        $listingRegister  = '';
        $listingSchema    = '';

        try {
            // Check if this listing belongs to a different directory that we already have as a source.
            if (empty($listingDirectory) === false
                && $listingDirectory !== $sourceDirectoryUrl
                && in_array($listingDirectory, $this->uniqueDirectories) === true
            ) {
                $result['action']  = 'skipped_other_directory';
                $result['success'] = true;
                $result['reason']  = 'Listing belongs to directory '.$listingDirectory.' which is processed separately';

                // No need to log routine skips.
                return $result;
            }

            // Check if OpenRegister service is available..
            if (in_array('openregister', $this->appManager->getInstalledApps()) === false) {
                throw new RuntimeException('OpenRegister service is not available.');
            }

            // Get ObjectService from container..
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            // Get listing configuration.
            $listingSchema   = $this->config->getValueString($this->appName, 'listing_schema', '');
            $listingRegister = $this->config->getValueString($this->appName, 'listing_register', '');

            if (empty($listingSchema) === true || empty($listingRegister) === true) {
                throw new RuntimeException('Listing schema or register not configured');
            }

            // Validate listing data - require id and catalog.
            if (empty($listingId) === true) {
                throw new InvalidArgumentException('Invalid listing data: missing id');
            }

            if (empty($catalogId) === true) {
                throw new InvalidArgumentException('Invalid listing data: missing catalog');
            }

            // Ensure catalog fields are set in listingData for later use.
            // The listing schema requires 'catalogusId' as a required field.
            $listingData['catalog']     = $catalogId;
            $listingData['catalogusId'] = $catalogId;
            $listingData['id']          = $listingId;

            // Extract title from @self.name if title is not at top level.
            if (empty($listingData['title']) === true && isset($listingData['@self']['name']) === true) {
                $listingData['title'] = $listingData['@self']['name'];
            }

            // Clean up listing data to match schema.
            // Keep the @self metadata for UUID handling, but clean it up.
            // Determine UUID: prefer @self.id, then listing id.
            $uuid = ($listingData['@self']['id'] ?? $listingData['id']);

            // Extract API endpoints from @self.relations BEFORE we unset @self.
            // These endpoints tell us where the actual catalog's API is hosted.
            if (isset($listingData['@self']['relations']) === true && is_array($listingData['@self']['relations']) === true) {
                $relations = $listingData['@self']['relations'];

                // Extract publications endpoint.
                if (isset($relations['publications']) === true && empty($relations['publications']) === false) {
                    $listingData['publications'] = $relations['publications'];
                }

                // Extract search endpoint (alternative).
                if (isset($relations['search']) === true && empty($relations['search']) === false) {
                    $listingData['search'] = $relations['search'];
                }

                // Extract directory endpoint from relations (the actual catalog's directory URL).
                // This is different from $sourceDirectoryUrl which is where we got the listing from.
                if (isset($relations['directory']) === true && empty($relations['directory']) === false) {
                    $listingData['catalogDirectory'] = $relations['directory'];
                }
            }

            // Remove @self metadata from the object data (but keep UUID for saveObject).
            unset($listingData['@self']);

            // Detect or generate publication endpoint BEFORE we overwrite directory field.
            // If not already extracted from relations, try to detect from available data.
            if (empty($listingData['publications']) === true) {
                $listingData['publications'] = $this->detectPublicationEndpoint($listingData);
            }

            // Set sourceDirectory URL in listing data for reference (where we got this listing from).
            $listingData['directory'] = $sourceDirectoryUrl;

            // Set lastSync as ISO string format instead of DateTime object.
            $listingData['lastSync'] = (new DateTime())->format('c');

            // Set published from source if available, otherwise default to now for backwards compatibility.
            // Normalize to ISO 8601 format (date-time validation requires 'T' separator and timezone).
            if (empty($listingData['published']) === true) {
                $listingData['published'] = (new DateTime())->format('c');
            } else {
                try {
                    $listingData['published'] = (new DateTime($listingData['published']))->format('c');
                } catch (\Exception $e) {
                    $listingData['published'] = (new DateTime())->format('c');
                }
            }

            // Catalog field is already present from external listing data.
            // Set summary to 'unknown' if empty (required field).
            if (empty($listingData['summary']) === true) {
                $listingData['summary'] = 'unknown';
            }

            // Count schemas if available.
            $listingData['schemaCount'] = 0;
            if (isset($listingData['schemas']) === true && is_array($listingData['schemas']) === true) {
                $listingData['schemaCount'] = count($listingData['schemas']);
            }

            // Check if listing already exists to determine action type.
            // Match on catalogusId + directory (source directory URL) to correctly deduplicate
            // across instances. Using catalogId alone fails because different instances generate
            // different UUIDs for the same logical catalog.
            $existingListings = $objectService->searchObjects(
                query: [
                    '@self' => [
                        'register' => $listingRegister,
                        'schema'   => $listingSchema,
                    ],
                    'catalogusId' => $catalogId,
                    'directory'   => $sourceDirectoryUrl,
                ]
            );

            $isUpdate = (empty($existingListings) === false);

            // Set directory properties based on whether it's new or updated.
            // Set defaults for new listings.
            $listingData['default']          = in_array($sourceDirectoryUrl, self::DEFAULT_DIRECTORIES, true);
            $listingData['statusCode']       = 200;
            $listingData['status']           = 'development';
            $listingData['integrationLevel'] = 'search';

            if ($isUpdate === true) {
                // For updates, preserve existing available and default values.
                $existingListing     = $existingListings[0];
                $existingListingData = $existingListing->jsonSerialize();
                $existingObject      = ($existingListingData['object'] ?? []);

                // Preserve existing availability and default status, but set smart defaults for missing fields.
                $listingData['default']          = ($existingObject['default'] ?? $listingData['default']);
                $listingData['status']           = ($existingObject['status'] ?? 'development');
                $listingData['integrationLevel'] = ($existingObject['integrationLevel'] ?? 'search');
            }

            if ($isUpdate === true) {
                // For updates, check for race conditions and data changes.
                // (existingListing and existingListingData already retrieved above).
                // Check for race condition: skip if incoming data is older than our last sync.
                if ($this->isListingDataOutdated($listingData, $existingListingData) === true) {
                    $result['action']  = 'skipped_outdated';
                    $result['success'] = true;
                    $result['reason']  = 'Incoming listing data is older than existing data';

                    // Skipping outdated listing (no logging needed for routine operations).
                    return $result;
                }

                // Check if listing has actually changed using hash comparison.
                $newHash = hash('sha256', json_encode($listingData));
                $oldHash = hash('sha256', json_encode(($existingListingData['object'] ?? [])));

                if ($newHash === $oldHash) {
                    $result['action']  = 'unchanged';
                    $result['success'] = true;
                }

                if ($newHash !== $oldHash) {
                    // Use existing UUID for update.
                    $uuid = $existingListingData['id'];

                    // Use saveObject which respects hard validation settings.
                    // Use positional parameters for compatibility with different ObjectService versions..
                    $objectService->saveObject(
                        object: $listingData,
                        extend: [],
                        register: $listingRegister,
                        schema: $listingSchema,
                        uuid: $uuid
                    );

                    $result['action']  = 'updated';
                    $result['success'] = true;
                }
            }//end if

            if ($isUpdate === false) {
                // Create new listing using saveObject.
                // Use positional parameters for compatibility with different ObjectService versions..
                $objectService->saveObject(
                        object: $listingData,
                        extend: [],
                        register: $listingRegister,
                        schema: $listingSchema,
                        uuid: $uuid
                    );

                $result['action']  = 'created';
                $result['success'] = true;
            }
        } catch (\Exception $e) {
            $result['error'] = $e->getMessage();

            // Try to update the listing with error status if it exists.
            // @phpstan-ignore-next-line Variables are assigned in the try block before potential exceptions.
            if (is_array($existingListings) === true && count($existingListings) > 0 && $objectService !== null) {
                try {
                    $existingListing     = $existingListings[0];
                    $existingListingData = $existingListing->jsonSerialize();
                    $errorData           = ($existingListingData['object'] ?? []);

                    // Update with error status.
                    $errorData['statusCode'] = 500;
                    $errorData['lastSync']   = (new DateTime())->format('c');

                    $objectService->saveObject(
                        object: $errorData,
                        extend: [],
                        register: $listingRegister,
                        schema: $listingSchema,
                        uuid: $existingListingData['id']
                    );
                } catch (\Exception $updateException) {
                    // Silently ignore update failures in error handling path.
                }//end try
            }//end if
        }//end try

        return $result;

    }//end syncListing()

    /**
     * Get publications from all available federated catalogs asynchronously
     *
     * Fetches publications from all publication endpoints of listings marked as available,
     * combining results into a single array.
     *
     * @param array   $guzzleConfig   Optional Guzzle configuration for HTTP requests
     * @param boolean $includeDefault Whether to include only default listings or all available listings
     *
     * @return array Array containing combined publications
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     * @throws DoesNotExistException|MultipleObjectsReturnedException
     *
     * @psalm-suppress InvalidArgument React Promise resolve callbacks receive arrays
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function getPublications(array $guzzleConfig=[], bool $includeDefault=false): array
    {
        // Get directories based on criteria.
        $directories = $this->getUniqueDirectories(availableOnly: true, defaultOnly: $includeDefault);

        // Removed redundant logging.
        if (empty($directories) === true) {
                            // Removed redundant logging (error handled silently).
            return [
                'results' => [],
                'sources' => [],
            ];
        }

        // Get our own directory URL to exclude from search..
        $ourDirectoryUrl = $this->urlGenerator->getAbsoluteURL(
            $this->urlGenerator->linkToRoute('opencatalogi.directory.index')
        );

        // Prepare Guzzle client..
        $defaultGuzzleConfig = [
            RequestOptions::TIMEOUT         => 5,
            RequestOptions::CONNECT_TIMEOUT => 2,
            RequestOptions::HEADERS         => [
                'Accept'     => 'application/json',
                'User-Agent' => 'OpenCatalogi-DirectoryService/1.0',
            ],
            RequestOptions::HTTP_ERRORS     => false,
        ];

        $finalGuzzleConfig = array_merge($defaultGuzzleConfig, $guzzleConfig);
        $queryParams       = ($finalGuzzleConfig['query_params'] ?? []);
        $queryParams['_aggregate'] = 'false';
        // Prevent circular aggregation.
        $queryParams['_extend'] = [
            '@self.schema',
            '@self.register',
        ];
        // Add self-extension.
        $client            = new Client($finalGuzzleConfig);
            $promises      = [];
        $urlToDirectoryMap = [];

        // Create promises for each directory.
        foreach ($directories as $index => $directoryUrl) {
            // Skip our own directory and local URLs.
            if ($directoryUrl === $ourDirectoryUrl) {
                // Removed redundant logging.
                continue;
            }

            if ($this->isLocalUrl($directoryUrl) === true) {
                // Removed redundant logging.
                continue;
            }

            // The directoryUrl is now actually a publications URL from getUniqueDirectories().
            $publicationsUrl = $directoryUrl;

            if (empty($queryParams) === false) {
                $publicationsUrl .= '?'.http_build_query($queryParams);
            }

                            // Removed redundant logging (error handled silently).
            // Store mapping for later source tracking.
            $parsedHost = parse_url($directoryUrl, PHP_URL_HOST);
            if (empty($parsedHost) === true) {
                $parsedHost = $directoryUrl;
            }

            $urlToDirectoryMap[count($promises)] = [
                'url'          => $publicationsUrl,
                'directoryUrl' => $directoryUrl,
                'name'         => $parsedHost,
            ];

            $promises[] = new Promise(
                resolver: function ($resolve) use ($client, $publicationsUrl) {
                    $failResult = ['success' => false, 'results' => [], 'facets' => [], 'total' => 0];
                    try {
                        $response   = $client->get($publicationsUrl);
                        $statusCode = $response->getStatusCode();

                        if ($statusCode < 200 || $statusCode >= 300) {
                            $resolve($failResult);
                            return;
                        }

                        $body = $response->getBody()->getContents();
                        $data = json_decode($body, true);

                        if (json_last_error() !== JSON_ERROR_NONE) {
                            $resolve($failResult);
                            return;
                        }

                        // Handle different response formats.
                        if (isset($data['results']) === true && is_array($data['results']) === true) {
                            $resolve(
                                [
                                    'success' => true,
                                    'results' => $data['results'],
                                    'facets'  => $data['facets'] ?? [],
                                    'total'   => $data['total'],
                                ]
                            );
                            return;
                        }

                        if (is_array($data) === true) {
                            $resolve(
                                [
                                    'success' => true,
                                    'results' => $data,
                                    'facets'  => [],
                                    'total'   => $data['total'],
                                ]
                            );
                            return;
                        }

                        $resolve($failResult);
                    } catch (\Exception $e) {
                        $resolve($failResult);
                    }//end try
                }
            );
        }//end foreach

        // Removed redundant logging.
        // Execute all promises and collect results.
        $allResults = \React\Async\await(\React\Promise\all($promises));

        // Removed redundant logging.
        // Flatten and deduplicate results, track sources., aggregate facets.
        $combinedResults = [];
        $seenIds         = [];
        $sources         = [];
        $combinedFacets  = [];
        $combinedTotal   = 0;

        foreach ($allResults as $index => $result) {
            $directoryInfo = $urlToDirectoryMap[$index];

            if ($result['success'] === false || empty($result['results']) === true) {
                continue;
            }

            $sources[$directoryInfo['name']] = $directoryInfo['url'];
            $combinedTotal += $result['total'];

            foreach ($result['results'] as $item) {
                $itemId = ($item['id'] ?? $item['uuid'] ?? uniqid());
                if (isset($seenIds[$itemId]) === true) {
                    continue;
                }

                // Add directory information to federated publications for faceting.
                if (isset($item['@self']) === false || is_array($item['@self']) === false) {
                    $item['@self'] = [];
                }

                $item['@self']['directory'] = $directoryInfo['name'];
                $combinedResults[]          = $item;
                $seenIds[$itemId]           = true;
            }

            // Aggregate facets if they exist.
            if (empty($result['facets']) === false) {
                $combinedFacets = $this->aggregateFacets($combinedFacets, $result['facets']);
            }
        }//end foreach

        // Removed redundant logging.
        return [
            'results' => $combinedResults,
            'sources' => $sources,
            'facets'  => $combinedFacets,
            'total'   => $combinedTotal,
        ];

    }//end getPublications()

    /**
     * Detect or generate publication endpoint for a listing
     *
     * Checks if the listing has a publication endpoint, and if not,
     * generates one based on the search endpoint by replacing 'search' with 'publications'.
     *
     * @param array $listingData The listing data to process
     *
     * @return string|null The publication endpoint URL or null if cannot be determined
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    private function detectPublicationEndpoint(array $listingData): ?string
    {
        // Check if listing already has a publication endpoint.
        if (empty($listingData['publications']) === false) {
            return $listingData['publications'];
        }

        // Check if listing already has a publication endpoint (alternative field name).
        if (empty($listingData['publication']) === false) {
            return $listingData['publication'];
        }

        // Try to generate from search endpoint.
        if (empty($listingData['search']) === false) {
            /*
             * Replace 'search' with 'publications' in the URL.
             *
             * @var string $searchUrl
             */

            $searchUrl           = $listingData['search'];
            $publicationEndpoint = str_replace('/search', '/publications', $searchUrl);

            // Also handle cases where 'search' might be a query parameter or different pattern.
            if ($publicationEndpoint === $listingData['search']) {
                // Try replacing 'search' anywhere in the URL path.
                $publicationEndpoint = (string) preg_replace('/\/search(?=\/|$)/', '/publications', $listingData['search']);
            }

            // If still no change, try a more generic approach.
            if ($publicationEndpoint === $listingData['search']) {
                // Parse URL and replace 'search' in path segments.
                $urlParts = parse_url($listingData['search']);
                if ($urlParts !== false && isset($urlParts['path']) === true) {
                    $pathSegments = explode('/', trim($urlParts['path'], '/'));
                    $pathSegments = array_map(
                        function ($segment) {
                            if ($segment === 'search') {
                                return 'publications';
                            }

                            return $segment;
                        },
                        $pathSegments
                    );

                    $newPath = '/'.implode('/', $pathSegments);
                    $publicationEndpoint = $urlParts['scheme'].'://'.$urlParts['host'];
                    if (isset($urlParts['port']) === true) {
                        $publicationEndpoint .= ':'.$urlParts['port'];
                    }

                    $publicationEndpoint .= $newPath;
                    if (isset($urlParts['query']) === true) {
                        $publicationEndpoint .= '?'.$urlParts['query'];
                    }
                }//end if
            }//end if

            // Only return if we actually made a change.
            if ($publicationEndpoint !== $listingData['search']) {
                return $publicationEndpoint;
            }
        }//end if

        // Try to construct from catalogDirectory (the actual catalog's directory endpoint from relations).
        // Format: Replace /api/directory with /api/publications.
        if (empty($listingData['catalogDirectory']) === false) {
            /*
             * @var string $catalogDir
             */

            $catalogDir = $listingData['catalogDirectory'];
            // Replace /api/directory with /api/publications.
            $publicationEndpoint = str_replace('/api/directory', '/api/publications', $catalogDir);
            if ($publicationEndpoint !== $catalogDir) {
                return $publicationEndpoint;
            }
        }

        // Try to construct from directory hostname (fallback for listings without proper relations).
        // Format: https://{directory-host}/apps/opencatalogi/api/publications.
        if (empty($listingData['directory']) === false) {
            $directory = $listingData['directory'];

            // If directory is just a hostname (e.g., "directory.opencatalogi.nl" or "opencatalogi.nl").
            // construct the full publications URL.
            if (strpos($directory, '://') === false) {
                // No protocol, assume HTTPS and add standard OpenCatalogi API path.
                return 'https://'.$directory.'/apps/opencatalogi/api/publications';
            }

            // Directory is a full URL, extract the base and construct publications endpoint.
            $urlParts = parse_url($directory);
            if ($urlParts !== false && isset($urlParts['host']) === true) {
                $publicationEndpoint = $urlParts['scheme'].'://'.$urlParts['host'];
                if (isset($urlParts['port']) === true) {
                    $publicationEndpoint .= ':'.$urlParts['port'];
                }

                $publicationEndpoint .= '/apps/opencatalogi/api/publications';
                return $publicationEndpoint;
            }
        }//end if

        // Try to infer hostname from catalog title or name.
        // For catalogs named like "OpenCatalogi.nl", "Example.com", try using that as hostname.
        $title = ($listingData['title'] ?? $listingData['name'] ?? '');
        if (empty($title) === false) {
            // Check if title looks like a domain name (contains a dot and no spaces).
            if (strpos($title, '.') !== false && strpos($title, ' ') === false) {
                // Looks like a domain, try using it.
                $hostname = strtolower(trim($title));
                // Remove any trailing slashes or paths.
                $hostname = preg_replace('#[/\\\\].*$#', '', $hostname);
                if (empty($hostname) === false) {
                    return 'https://'.$hostname.'/apps/opencatalogi/api/publications';
                }
            }
        }

        return null;

    }//end detectPublicationEndpoint()

    /**
     * Check if incoming listing data is outdated compared to existing data
     *
     * Prevents race conditions by comparing timestamps to ensure we don't
     * overwrite newer data with older data from different directories.
     *
     * @param array $incomingData The incoming listing data from directory
     * @param array $existingData The existing listing data from database
     *
     * @return boolean True if incoming data is outdated and should be skipped
     */
    private function isListingDataOutdated(array $incomingData, array $existingData): bool
    {
        try {
            // Get existing object data.
            $existingObject = ($existingData['object'] ?? []);

            // Get the last sync time from existing data.
            $existingLastSync = $existingObject['lastSync'] ?? null;

            // If no existing last sync, allow the update.
            if (empty($existingLastSync) === true) {
                return false;
            }

            // Get timestamps for comparison.
            $incomingUpdated = $this->extractTimestamp($incomingData);
            $existingUpdated = $this->extractTimestamp($existingObject);

            // If we can't determine timestamps, allow the update to be safe.
            if ($incomingUpdated === null || $existingUpdated === null) {
                return false;
            }

            // Skip if incoming data is older than existing data.
            $isOutdated = $incomingUpdated < $existingUpdated;

            // Incoming data is outdated, skip silently.
            return $isOutdated;
        } catch (\Exception $e) {
            // Removed redundant logging.
            return false;
        }//end try

    }//end isListingDataOutdated()

    /**
     * Extract timestamp from listing data for comparison
     *
     * Tries to find the most relevant timestamp for determining data freshness.
     * Looks for updated, created, or @self.updated timestamps.
     *
     * @param array $data The listing data to extract timestamp from
     *
     * @return \DateTime|null The extracted timestamp or null if not found
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    private function extractTimestamp(array $data): ?\DateTime
    {
        // Priority order for timestamp fields.
        $timestampFields = [
            'updated',
        // Standard updated field.
            '@self.updated',
        // Metadata updated field.
            'created',
        // Creation timestamp.
            '@self.created',
        // Metadata creation timestamp.
            'lastSync',
        // Last sync timestamp.
        ];

        foreach ($timestampFields as $field) {
            $value = null;

            // Handle nested field notation (e.g., '@self.updated').
            $value = $data[$field] ?? null;
            if (str_contains($field, '.') === true) {
                $parts = explode('.', $field);
                $value = $data;
                foreach ($parts as $part) {
                    if (isset($value[$part]) === false) {
                        $value = null;
                        break;
                    }

                    $value = $value[$part];
                }
            }

            if ($value !== null) {
                try {
                    // Handle different timestamp formats.
                    if (is_string($value) === true) {
                        return new DateTime($value);
                    } else if (is_array($value) === true && isset($value['date']) === true) {
                        // Handle DateTime object serialized as array.
                        return new DateTime($value['date']);
                    } else if ($value instanceof \DateTime) {
                        return $value;
                    }
                } catch (\Exception $e) {
                    // Continue to next field if this one is invalid.
                    continue;
                }
            }
        }//end foreach

        return null;

    }//end extractTimestamp()

    /**
     * Update directory status on error
     *
     * Updates existing listings from a directory with error status when
     * the directory sync fails at the HTTP level.
     *
     * @param string  $directoryUrl The directory URL that failed
     * @param integer $statusCode   The HTTP status code of the error
     *
     * @return void
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     */
    private function updateDirectoryStatusOnError(string $directoryUrl, int $statusCode): void
    {
        try {
            // Check if OpenRegister service is available.
            if (in_array('openregister', $this->appManager->getInstalledApps()) === false) {
                return;
                // Can't update if OpenRegister is not available.
            }

            // Get ObjectService from container..
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            // Get listing configuration.
            $listingSchema   = $this->config->getValueString($this->appName, 'listing_schema', '');
            $listingRegister = $this->config->getValueString($this->appName, 'listing_register', '');

            if (empty($listingSchema) === true || empty($listingRegister) === true) {
                return;
                // Can't update without schema/register configuration.
            }

            // Find all listings from this directory.
            $existingListings = $objectService->searchObjects(
                query: [
                    '@self'     => [
                        'register' => $listingRegister,
                        'schema'   => $listingSchema,
                    ],
                    'directory' => $directoryUrl,
                ]
            );

            // Update each listing with error status.
            foreach ($existingListings as $listing) {
                $listingData = $listing->jsonSerialize();
                $errorData   = ($listingData['object'] ?? []);

                // Update with error status.
                $errorData['statusCode'] = $statusCode;
                $errorData['lastSync']   = (new DateTime())->format('c');

                // Use positional parameters for compatibility with different ObjectService versions.
                $objectService->saveObject(
                    object: $errorData,
                    extend: [],
                    register: $listingRegister,
                    schema: $listingSchema,
                    uuid: $listingData['id']
                );
            }
        } catch (\Exception $e) {
            // Removed redundant logging.
        }//end try

    }//end updateDirectoryStatusOnError()

    /**
     * Check if the current request is from a system broadcast
     *
     * Determines if the current HTTP request is from another OpenCatalogi instance
     * sending a broadcast notification, to prevent infinite broadcast loops.
     *
     * @return boolean True if request is from a system broadcast, false otherwise
     */
    private function isSystemBroadcast(): bool
    {
        $userAgent = $this->request->getHeader('User-Agent');

        // Check if User-Agent contains 'OpenCatalogi-Broadcast' (future-proof for version changes).
        return empty($userAgent) === false && str_contains($userAgent, 'OpenCatalogi-Broadcast') === true;

    }//end isSystemBroadcast()

    /**
     * Check if a URL is considered local
     *
     * Determines if a URL represents a local address that should not be
     * used for broadcasting (localhost, 127.0.0.1, local domain, etc.).
     *
     * @param string $url The URL to check
     *
     * @return boolean True if the URL is local, false otherwise
     */
    private function isLocalUrl(string $url): bool
    {
        $parsedUrl = parse_url($url);

        if ($parsedUrl === false || isset($parsedUrl['host']) === false) {
            return true;
            // Invalid URL is considered local.
        }

        $host = strtolower($parsedUrl['host']);

        // Check for localhost, local IPs, and local domains.
        $localPatterns = [
            'localhost',
            '127.0.0.1',
            '::1',
            '0.0.0.0',
        ];

        // Check exact matches.
        if (in_array($host, $localPatterns) === true) {
            return true;
        }

        // Check for private IP ranges (192.168.x.x, 10.x.x.x, 172.16-31.x.x).
        if (filter_var(value: $host, filter: FILTER_VALIDATE_IP) !== false) {
            if (filter_var(value: $host, filter: FILTER_VALIDATE_IP, options: (FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) === false) {
                return true;
            }
        }

        // Check for .local domain.
        if (str_ends_with($host, '.local') === true) {
            return true;
        }

        return false;

    }//end isLocalUrl()

    /**
     * Get objects from other catalogs that use/reference our local object
     *
     * Calls the /publications/{uuid}/used endpoint on all available federated
     * catalogs to find objects that reference or use the specified local object UUID.
     *
     * @param string $uuid         The UUID of the local object to find uses for
     * @param array  $guzzleConfig Optional Guzzle configuration for HTTP requests
     *
     * @return array Array with 'results' and 'sources' keys
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     * @throws DoesNotExistException|MultipleObjectsReturnedException
     *
     * @psalm-suppress InvalidArgument React Promise resolve callbacks receive arrays
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function getUsed(string $uuid, array $guzzleConfig=[]): array
    {
        // Get available listings with publication endpoints.
        $directories = $this->getUniqueDirectories(availableOnly: true);

        if (empty($directories) === true) {
            return [
                'results' => [],
                'sources' => [],
            ];
        }

        // Get our own directory URL to exclude from search.
        $ourDirectoryUrl = $this->urlGenerator->getAbsoluteURL(
            $this->urlGenerator->linkToRoute('opencatalogi.directory.index')
        );

        // Prepare Guzzle client.
        $defaultGuzzleConfig = [
            RequestOptions::TIMEOUT         => 5,
            RequestOptions::CONNECT_TIMEOUT => 2,
            RequestOptions::HEADERS         => [
                'Accept'     => 'application/json',
                'User-Agent' => 'OpenCatalogi-DirectoryService/1.0',
            ],
            RequestOptions::HTTP_ERRORS     => false,
        ];

        $finalGuzzleConfig = array_merge($defaultGuzzleConfig, $guzzleConfig);
        $queryParams       = ($finalGuzzleConfig['query_params'] ?? []);
        $queryParams['_aggregate'] = 'false';
        // Prevent circular aggregation.
        $queryParams['_extend'] = [
            '@self.schema',
            '@self.register',
        ];
        // Add self-extension.
        $client            = new Client($finalGuzzleConfig);
        $promises          = [];
        $urlToDirectoryMap = [];

        // Create promises for each directory.
        foreach ($directories as $index => $directoryUrl) {
            // Skip our own directory and local URLs.
            if ($directoryUrl === $ourDirectoryUrl || $this->isLocalUrl($directoryUrl) === true) {
                continue;
            }

            // Build the /used endpoint URL.
            // Convert directory URL to publications URL by replacing /api/directory with /api/publications.
            $baseUrl = str_replace('/api/directory', '/api/publications', rtrim($directoryUrl, '/'));
            $usedUrl = $baseUrl.'/'.urlencode($uuid).'/used';

            if (empty($queryParams) === false) {
                $usedUrl .= '?'.http_build_query($queryParams);
            }

            // Store mapping for later source tracking.
            $usedParsedHost = parse_url($directoryUrl, PHP_URL_HOST);
            if (empty($usedParsedHost) === true) {
                $usedParsedHost = $directoryUrl;
            }

            $urlToDirectoryMap[count($promises)] = [
                'url'          => $usedUrl,
                'directoryUrl' => $directoryUrl,
                'name'         => $usedParsedHost,
            ];

            $promises[] = new Promise(
                function ($resolve) use ($client, $usedUrl) {
                    $failResult = ['success' => false, 'results' => []];
                    try {
                        $response = $client->get($usedUrl);

                        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                            $resolve($failResult);
                            return;
                        }

                        $data = json_decode($response->getBody()->getContents(), true);

                        if (json_last_error() !== JSON_ERROR_NONE) {
                            $resolve($failResult);
                            return;
                        }

                        // Handle different response formats.
                        if (isset($data['results']) === true && is_array($data['results']) === true) {
                            $resolve(['success' => true, 'results' => $data['results']]);
                            return;
                        }

                        if (is_array($data) === true) {
                            $resolve(['success' => true, 'results' => $data]);
                            return;
                        }

                        $resolve($failResult);
                    } catch (\Exception $e) {
                        $resolve($failResult);
                    }//end try
                }
            );
        }//end foreach

        // Execute all promises and collect results.
        $allResults = \React\Async\await(\React\Promise\all($promises));

        // Flatten and deduplicate results, track sources.
        $combinedResults = [];
        $seenIds         = [];
        $sources         = [];

        foreach ($allResults as $index => $result) {
            $directoryInfo = $urlToDirectoryMap[$index];

            if ($result['success'] === true && empty($result['results']) === false) {
                $sources[$directoryInfo['name']] = $directoryInfo['url'];

                foreach ($result['results'] as $item) {
                    $itemId = ($item['id'] ?? $item['uuid'] ?? uniqid());
                    if (isset($seenIds[$itemId]) === false) {
                        $combinedResults[] = $item;
                        $seenIds[$itemId]  = true;
                    }
                }
            }
        }

        return [
            'results' => $combinedResults,
            'sources' => $sources,
        ];

    }//end getUsed()

    /**
     * Get a single publication from federated catalogs by ID
     *
     * Searches across all available federated catalogs to find a specific
     * publication by its ID. Returns the first match found with source information.
     *
     * @param string $publicationId The ID of the publication to find
     * @param array  $guzzleConfig  Optional Guzzle configuration for HTTP requests
     *
     * @return array|null Array containing publication data and source, or null if not found
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     * @throws DoesNotExistException|MultipleObjectsReturnedException
     *
     * @psalm-suppress InvalidArgument React Promise resolve callbacks receive arrays
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function getPublication(string $publicationId, array $guzzleConfig=[]): ?array
    {
        // Get available directories.
        $directories = $this->getUniqueDirectories(availableOnly: true);

        if (empty($directories) === true) {
            return null;
        }

        // Get our own directory URL to exclude from search.
        $ourDirectoryUrl = $this->urlGenerator->getAbsoluteURL(
            $this->urlGenerator->linkToRoute('opencatalogi.directory.index')
        );

        // Prepare Guzzle client.
        $defaultGuzzleConfig = [
            RequestOptions::TIMEOUT         => 5,
            RequestOptions::CONNECT_TIMEOUT => 2,
            RequestOptions::HEADERS         => [
                'Accept'     => 'application/json',
                'User-Agent' => 'OpenCatalogi-DirectoryService/1.0',
            ],
            RequestOptions::HTTP_ERRORS     => false,
        ];

        $finalGuzzleConfig = array_merge($defaultGuzzleConfig, $guzzleConfig);
        $queryParams       = ($finalGuzzleConfig['query_params'] ?? []);
        $queryParams['_aggregate'] = 'false';
        // Prevent circular aggregation.
        $queryParams['_extend'] = [
            '@self.schema',
            '@self.register',
        ];
        // Add self-extension.
        $client            = new Client($finalGuzzleConfig);
        $promises          = [];
        $urlToDirectoryMap = [];

        // Create promises for each directory.
        foreach ($directories as $index => $directoryUrl) {
            // Skip our own directory and local URLs.
            if ($directoryUrl === $ourDirectoryUrl || $this->isLocalUrl($directoryUrl) === true) {
                continue;
            }

            // Build the publication endpoint URL.
            // Convert directory URL to publications URL by replacing /api/directory with /api/publications.
            $baseUrl        = str_replace('/api/directory', '/api/publications', rtrim($directoryUrl, '/'));
            $publicationUrl = $baseUrl.'/'.urlencode($publicationId);

            if (empty($queryParams) === false) {
                $publicationUrl .= '?'.http_build_query($queryParams);
            }

            // Store mapping for later source tracking.
            $pubParsedHost = parse_url($directoryUrl, PHP_URL_HOST);
            if (empty($pubParsedHost) === true) {
                $pubParsedHost = $directoryUrl;
            }

            $urlToDirectoryMap[count($promises)] = [
                'url'          => $publicationUrl,
                'directoryUrl' => $directoryUrl,
                'name'         => $pubParsedHost,
            ];

            $promises[] = new Promise(
                function ($resolve) use ($client, $publicationUrl) {
                    try {
                        $response = $client->get($publicationUrl);

                        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                            $data = json_decode($response->getBody()->getContents(), true);

                            if (json_last_error() === JSON_ERROR_NONE && empty($data) === false) {
                                $resolve(['success' => true, 'data' => $data]);
                                return;
                            }
                        }

                        $resolve(['success' => false, 'data' => null]);
                    } catch (\Exception $e) {
                        $resolve(['success' => false, 'data' => null]);
                    }
                }
            );
        }//end foreach

        // Execute all promises and return first successful result with source.
        $allResults = \React\Async\await(\React\Promise\all($promises));

                 // Return the first successful result with source information.
        foreach ($allResults as $index => $result) {
            if ($result['success'] === true && $result['data'] !== null) {
                $directoryInfo = $urlToDirectoryMap[$index];
                return [
                    'result' => $result['data'],
                    'source' => [
                        $directoryInfo['name'] => $directoryInfo['url'],
                    ],
                ];
            }
        }

         return null;

    }//end getPublication()

    /**
     * Get directory entries (listings and catalogs formatted as listings)
     *
     * Retrieves both discovered listings and local catalogs, formatting catalogs
     * as listing objects according to the publication register schema.
     *
     * @param array $requestParams Request parameters for filtering, pagination, etc.
     *
     * @return array<string, mixed> Array containing results and total count
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     * @throws DoesNotExistException|MultipleObjectsReturnedException
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function getDirectory(array $requestParams=[]): array
    {
        // Check if OpenRegister service is available.
        if (in_array('openregister', $this->appManager->getInstalledApps()) === false) {
            throw new RuntimeException('OpenRegister service is not available.');
        }

        // Get ObjectService from container.
        $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

        // Get configuration.
        $listingSchema   = $this->config->getValueString($this->appName, 'listing_schema', '');
        $listingRegister = $this->config->getValueString($this->appName, 'listing_register', '');
        $catalogSchema   = $this->config->getValueString($this->appName, 'catalog_schema', 'catalog');
        $catalogRegister = $this->config->getValueString($this->appName, 'catalog_register', '');

        $allResults = [];

        // Build base config for filters and pagination.
        $baseConfig = [
            'filters' => [],
        ];

        // Add any additional filters from request params.
        if (isset($requestParams['filters']) === true) {
            $baseConfig['filters'] = array_merge($baseConfig['filters'], $requestParams['filters']);
        }

        // Add pagination params.
        if (isset($requestParams['limit']) === true) {
            $baseConfig['limit'] = (int) $requestParams['limit'];
        }

        if (isset($requestParams['offset']) === true) {
            $baseConfig['offset'] = (int) $requestParams['offset'];
        }

        // Fetch discovered listings.
        if (empty($listingSchema) === false && empty($listingRegister) === false) {
            $query = [
                '@self' => [
                    'schema'   => $listingSchema,
                    'register' => $listingRegister,
                ],
            ];

            // Add filters from base config.
            if (empty($baseConfig['filters']) === false) {
                foreach ($baseConfig['filters'] as $key => $value) {
                    if (in_array($key, ['schema', 'register']) === false) {
                        $query[$key] = $value;
                    }
                }
            }

            // Add pagination parameters.
            if (isset($baseConfig['limit']) === true) {
                $query['_limit'] = $baseConfig['limit'];
            }

            if (isset($baseConfig['offset']) === true) {
                $query['_offset'] = $baseConfig['offset'];
            }

            try {
                // RBAC handles public visibility via conditional published date rule.
                // Disable multitenancy so listings from all orgs are visible.
                $listingResult = $objectService->searchObjects($query, _multitenancy: false);

                // Get our directory URL to identify locally-created listings.
                $ourDirectoryUrl = $this->urlGenerator->getAbsoluteURL(
                    $this->urlGenerator->linkToRoute('opencatalogi.directory.index')
                );

                // Only include listings that originated from this instance in the directory.
                // Synced listings from other instances have a foreign directory URL and should
                // NOT be re-broadcast — that would create infinite sync loops between instances.
                $listings = [];
                foreach ($listingResult as $object) {
                    if ($object instanceof \OCP\AppFramework\Db\Entity) {
                        $listingData = $object->jsonSerialize();
                    } else {
                        $listingData = $object;
                    }

                    $objectData  = ($listingData['object'] ?? $listingData);
                    $listingDir  = ($objectData['directory'] ?? '');

                    // Skip listings that were synced from other directories.
                    if (empty($listingDir) === false && $listingDir !== $ourDirectoryUrl) {
                        continue;
                    }

                    $listings[] = $this->filterListingProperties($listingData);
                }

                $allResults = array_merge($allResults, $listings);
            } catch (\Exception $e) {
                // Removed redundant logging.
            }//end try
        }//end if

        // Fetch local catalogs and convert them to listing format.
        if (empty($catalogSchema) === false && empty($catalogRegister) === false) {
            $query = [
                '@self' => [
                    'schema'   => $catalogSchema,
                    'register' => $catalogRegister,
                ],
            ];

            // Add filters from base config.
            if (empty($baseConfig['filters']) === false) {
                foreach ($baseConfig['filters'] as $key => $value) {
                    if (in_array($key, ['schema', 'register']) === false) {
                        $query[$key] = $value;
                    }
                }
            }

            // Add pagination parameters.
            if (isset($baseConfig['limit']) === true) {
                $query['_limit'] = $baseConfig['limit'];
            }

            if (isset($baseConfig['offset']) === true) {
                $query['_offset'] = $baseConfig['offset'];
            }

            try {
                // RBAC handles public visibility via conditional published date rule.
                // Disable multitenancy so catalogs from all orgs are visible.
                $catalogResult = $objectService->searchObjects($query, _multitenancy: false);

                // Convert catalog objects to listing format and expand schemas.
                $catalogsAsListings = array_map(
                    function ($catalogObject) {
                        $listing = $this->convertCatalogToListing($catalogObject);
                        return $this->processSchemaExpansion($listing);
                    },
                    $catalogResult
                );

                $allResults = array_merge($allResults, $catalogsAsListings);
            } catch (\Exception $e) {
                // Removed redundant logging.
            }
        }//end if

        // Calculate total.
        $total = count($allResults);

        return [
            'results' => $allResults,
            'total'   => $total,
        ];

    }//end getDirectory()

    /**
     * Convert a catalog object to listing format
     *
     * Transforms a catalog object into a listing object format according to the
     * publication register schema, ensuring all required fields are present.
     *
     * @param mixed $catalogObject The catalog object to convert
     *
     * @return array The catalog formatted as a listing object
     */
    private function convertCatalogToListing($catalogObject): array
    {
        // Extract catalog data.
        if ($catalogObject instanceof \OCP\AppFramework\Db\Entity) {
            $catalogData = $catalogObject->jsonSerialize();
        } else {
            $catalogData = $catalogObject;
        }

        $catalog = ($catalogData['object'] ?? $catalogData);

        // Get our directory URL for the listing.
        $directoryUrl = $this->urlGenerator->getAbsoluteURL(
            $this->urlGenerator->linkToRoute('opencatalogi.directory.index')
        );

        // Get our search and publications URLs.
        $searchUrl = $this->urlGenerator->getAbsoluteURL(
            $this->urlGenerator->linkToRoute('opencatalogi.search.index')
        );

        // Use the catalog-specific publications endpoint (/api/{slug}) which serves
        // the actual local publications. The federation endpoint only aggregates from
        // remote sources and doesn't serve local data correctly.
        $catalogSlug     = ($catalog['slug'] ?? 'publications');
        $publicationsUrl = $this->urlGenerator->getAbsoluteURL(
            $this->urlGenerator->linkToRoute('opencatalogi.publications.index', ['catalogSlug' => $catalogSlug])
        );

        // Create listing object from catalog - only core API fields.
        $catalogId = ($catalog['id'] ?? $catalogData['id'] ?? '');
        $listing = [
            // Required fields for listing.
            'id'           => $catalogId,
            'catalog'      => $catalogId,
            'catalogusId'  => $catalogId,
            'title'        => ($catalog['title'] ?? 'Unknown Catalog'),
            'summary'      => ($catalog['summary'] ?? $catalog['description'] ?? 'Local catalog'),
            'status'       => ($catalog['status'] ?? 'development'),

            // Optional fields from catalog.
            'description'  => $catalog['description'] ?? null,
            'organization' => $catalog['organization'] ?? null,
            'schemas'      => ($catalog['schemas'] ?? []),

            // Directory-specific fields.
            'directory'    => $directoryUrl,
            'search'       => $searchUrl,
            'publications' => $publicationsUrl,
            'version'      => $this->appManager->getAppInfo('opencatalogi')['version'],

            // Publication date from catalog, default to now for public visibility.
            'published'    => ($catalog['published'] ?? (new DateTime())->format('c')),
        ];

        return $listing;

    }//end convertCatalogToListing()

    /**
     * Filter listing object to remove properties not meant for external API
     *
     * Removes internal properties and unwanted properties that shouldn't be exposed
     * in the public API, keeping only the core listing properties.
     *
     * @param array $listing The listing object to filter
     *
     * @return array The filtered listing object with only public properties
     */
    private function filterListingProperties(array $listing): array
    {
        // Extract the actual object data.
        $objectData = ($listing['object'] ?? $listing);

        // List of properties to remove for external API.
        $propertiesToRemove = [
            // Internal/system properties.
            'status',
            'statusCode',
            'lastSync',
            'available',
            'default',
            // Unwanted properties as requested.
            'metadata',
            'image',
            'listed',
            'filters',
        ];

        // Remove unwanted properties.
        foreach ($propertiesToRemove as $property) {
            unset($objectData[$property]);
        }

        // If this was a nested object structure, maintain it.
        if (isset($listing['object']) === true) {
            $listing['object'] = $objectData;
            return $listing;
        }

        // Otherwise return the cleaned object directly.
        return $objectData;

    }//end filterListingProperties()

    /**
     * Convert catalogi to listings format
     *
     * Public method to convert catalog objects to listing format for external use.
     * This is the main function for translating catalogi to listings.
     *
     * @param array $catalogs Array of catalog objects to convert
     *
     * @return array Array of catalogs converted to listing format with expanded schemas
     */
    public function convertCatalogiToListings(array $catalogs): array
    {
        return array_map(
            function ($catalogObject) {
                $listing = $this->convertCatalogToListing($catalogObject);
                return $this->processSchemaExpansion($listing);
            },
            $catalogs
        );

    }//end convertCatalogiToListings()

    /**
     * Expand schema IDs to full schema objects
     *
     * Takes an array of schema IDs and returns the corresponding full schema objects
     * using the OpenRegister SchemaMapper.
     *
     * @param array $schemaIds Array of schema IDs to expand
     *
     * @return array Array of full schema objects
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     */
    private function expandSchemas(array $schemaIds): array
    {
        if (empty($schemaIds) === true) {
            return [];
        }

        // Check if OpenRegister service is available.
        if (in_array('openregister', $this->appManager->getInstalledApps()) === false) {
            return $schemaIds;
            // Return IDs if OpenRegister is not available.
        }

        try {
            // Get SchemaMapper from container.
            $schemaMapper = $this->container->get('OCA\OpenRegister\Db\SchemaMapper');

            // Use findMultiple to get all schemas in one call.
            $schemas = $schemaMapper->findMultiple($schemaIds);

            // Convert schema entities to arrays.
            return array_map(
                function ($schema) {
                    if ($schema instanceof \OCP\AppFramework\Db\Entity) {
                        return $schema->jsonSerialize();
                    }

                    return $schema;
                },
                $schemas
            );
        } catch (\Exception $e) {
            // Removed redundant logging.
            // Return original IDs if expansion fails.
            return $schemaIds;
        }//end try

    }//end expandSchemas()

    /**
     * Process listing or catalog data to expand schema IDs
     *
     * Takes listing or catalog data and expands any schema IDs to full objects.
     *
     * @param array $data The listing or catalog data to process
     *
     * @return array The processed data with expanded schemas
     */
    private function processSchemaExpansion(array $data): array
    {
        // Extract the actual object data.
        $objectData = ($data['object'] ?? $data);

        // Expand schemas if they exist and are an array of IDs.
        if (isset($objectData['schemas']) === true && is_array($objectData['schemas']) === true) {
            $objectData['schemas'] = $this->expandSchemas($objectData['schemas']);
        }

        // If this was a nested object structure, maintain it.
        if (isset($data['object']) === true) {
            $data['object'] = $objectData;
            return $data;
        }

        // Otherwise return the processed object directly.
        return $objectData;

    }//end processSchemaExpansion()

    /**
     * Aggregate facets from multiple directory sources
     *
     * Combines facet data from different publication endpoints,
     * merging counts for the same facet values.
     *
     * @param array $existingFacets The current aggregated facets
     * @param array $newFacets      The new facets to merge in
     *
     * @return array The merged facets
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    private function aggregateFacets(array $existingFacets, array $newFacets): array
    {
        if (empty($newFacets) === true) {
            return $existingFacets;
        }

        if (empty($existingFacets) === true) {
            return $newFacets;
        }

        $mergedFacets = $existingFacets;

        foreach ($newFacets as $field => $facetValues) {
            // Skip if facetValues is not an array.
            if (is_array($facetValues) === false) {
                // Removed redundant logging.
                continue;
            }

            if (isset($mergedFacets[$field]) === false) {
                $mergedFacets[$field] = $facetValues;
                continue;
            }

            // Skip if existing field data is not an array.
            if (is_array($mergedFacets[$field]) === false) {
                // Removed redundant logging.
                continue;
            }

            // Create a lookup map for existing values.
            $existingValues = [];
            foreach ($mergedFacets[$field] as $index => $existingFacet) {
                // Skip if existingFacet is not an array or doesn't have _id.
                if (is_array($existingFacet) === false || isset($existingFacet['_id']) === false) {
                    // Removed redundant logging.
                    continue;
                }

                $existingValues[$existingFacet['_id']] = $index;
            }

            // Merge or add new values.
            foreach ($facetValues as $facetValue) {
                // Skip if facetValue is not an array or doesn't have required fields.
                if (is_array($facetValue) === false || isset($facetValue['_id']) === false) {
                    // Removed redundant logging.
                    continue;
                }

                $valueId = $facetValue['_id'];

                if (isset($existingValues[$valueId]) === true && isset($facetValue['count']) === true) {
                    // Add to existing count.
                    if (isset($mergedFacets[$field][$existingValues[$valueId]]['count']) === true) {
                        $mergedFacets[$field][$existingValues[$valueId]]['count'] += $facetValue['count'];
                    }

                    continue;
                }

                // Add new value.
                $mergedFacets[$field][] = $facetValue;
            }//end foreach

            // Re-sort merged facets by count (descending) and then by value (ascending).
            // Only sort if we have valid array data.
            if (is_array($mergedFacets[$field]) === true && empty($mergedFacets[$field]) === false) {
                usort(
                    $mergedFacets[$field],
                    function ($a, $b) {
                        // Ensure both items are arrays with required fields.
                        if (is_array($a) === false || is_array($b) === false || isset($a['_id']) === false || isset($b['_id']) === false) {
                            return 0;
                        }

                        $countA = ($a['count'] ?? 0);
                        $countB = ($b['count'] ?? 0);

                        if ($countA === $countB) {
                            return strcmp($a['_id'], $b['_id']);
                        }

                        return ($countB <=> $countA);
                    }
                );
            }
        }//end foreach

        return $mergedFacets;

    }//end aggregateFacets()
}//end class
