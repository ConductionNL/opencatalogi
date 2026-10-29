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
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 * @link    https://www.OpenCatalogi.nl
 *
 * @spec openspec/specs/dashboard/spec.md
 * @spec openspec/specs/dashboard/spec.md
 * @spec openspec/specs/dashboard/spec.md
 * @spec openspec/specs/dashboard/spec.md
 * @spec openspec/specs/federation/spec.md
 * @spec openspec/specs/dashboard/spec.md
 * @spec openspec/specs/dashboard/spec.md
 * @spec openspec/specs/federation/spec.md
 * @spec openspec/specs/federation/spec.md
 * @spec openspec/specs/dashboard/spec.md
 * @spec openspec/specs/dashboard/spec.md
 * @spec openspec/specs/federation/spec.md
 */

namespace OCA\OpenCatalogi\Service;

use DateTime;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\RequestOptions;
use InvalidArgumentException;
use OCA\OpenCatalogi\AppInfo\Application;
use OCA\OpenCatalogi\Service\Connection\ConnectionReporter;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use React\Promise\Promise;
use RuntimeException;

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
 * @spec openspec/specs/dashboard/spec.md
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyMethods) Sync paths are split into small private steps to stay under the unit size and complexity limits.
 */
class DirectoryService {

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
	 * The directory sync counter each successful listing sync action counts toward.
	 *
	 * @var array<string, string>
	 */
	private const SYNC_ACTION_COUNTERS = [
		'created' => 'listings_created',
		'updated' => 'listings_updated',
		'unchanged' => 'listings_unchanged',
		'skipped_outdated' => 'listings_skipped',
		'skipped_other_directory' => 'listings_skipped',
	];

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
	 * @param IURLGenerator $urlGenerator URL generator interface
	 * @param IAppConfig $config App configuration interface
	 * @param ContainerInterface $container Server container for dependency injection
	 * @param IAppManager $appManager App manager for checking installed apps
	 * @param BroadcastService $broadcastService Broadcast service for notifying other directories
	 * @param IRequest $request Request interface for accessing HTTP headers
	 * @param LoggerInterface|null $logger PSR-3 logger for the SSRF-allowance warning
	 * @param ConnectionReporter|null $connectionReporter Tells integriq what a sync met, or nothing when absent.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-oc-conn-003-opencatalogi-reports-what-a-sync-a-broadcast-and-a-readiness-check-met
	 */
	public function __construct(
		private readonly IURLGenerator $urlGenerator,
		private readonly IAppConfig $config,
		private readonly ContainerInterface $container,
		private readonly IAppManager $appManager,
		private readonly BroadcastService $broadcastService,
		private readonly IRequest $request,
		// Nullable and last so every existing construction site — including
		// the unit tests that build this service by hand — keeps working
		// without being touched. The only thing it carries today is the
		// SSRF-allowance warning, and a missing logger must not be the reason
		// directory sync stops working.
		private readonly ?LoggerInterface $logger = null,
		// The integriq connection report (adopt-connection-registry). Nullable
		// and last for the same reason as the logger above.
		private readonly ?ConnectionReporter $connectionReporter = null,
	) {
		$this->appName = 'opencatalogi';
		$this->client = new Client([]);

	}//end __construct()

	/**
	 * Resolve the national OpenCatalogi directory URL.
	 *
	 * Returns the `default_directory_url` app-config override when set, otherwise
	 * the canonical Application::DEFAULT_DIRECTORY_URL constant. Single source of
	 * truth for every default-directory reference (cron sync, manual sync, the
	 * first-time-setup connect-federation action, and the Add-Directory modal).
	 *
	 * @return string The resolved default directory URL.
	 *
	 * @spec openspec/specs/first-time-setup/spec.md#requirement-default-directory-url-single-source-of-truth-onb-008
	 */
	public function getDefaultDirectoryUrl(): string {
		return $this->config->getValueString(
			$this->appName,
			'default_directory_url',
			Application::DEFAULT_DIRECTORY_URL
		);

	}//end getDefaultDirectoryUrl()

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
	 * The outcome is also reported to integriq's connection registry, from the
	 * cron job and the Sync directories now button alike
	 * (adopt-connection-registry). The report never changes the result.
	 *
	 * @psalm-suppress InvalidArgument React Promise resolve callbacks receive arrays
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-oc-conn-003-opencatalogi-reports-what-a-sync-a-broadcast-and-a-readiness-check-met
	 */
	public function doCronSync(): array {
		// BFS-discovery: iterate over peer /api/directory URLs (not publications URLs).
		// The publications-URL variant lives on getUniqueDirectories() and is used by
		// the federation search flow.
		$this->uniqueDirectories = $this->getKnownDirectoryUrls();

		// Add default OpenCatalogi directory if not already present.
		$defaultDirectory = $this->getDefaultDirectoryUrl();
		if (in_array($defaultDirectory, $this->uniqueDirectories) === false) {
			$this->uniqueDirectories[] = $defaultDirectory;
		}

		$uniqueDirectoryUrls = $this->uniqueDirectories;

		$results = [
			'total_directories' => count($uniqueDirectoryUrls),
			'synced_directories' => 0,
			'failed_directories' => 0,
			'errors' => [],
		];

		// Create promises for async directory synchronization.
		$syncPromises = [];
		foreach ($uniqueDirectoryUrls as $directoryUrl) {
			$syncPromises[] = new Promise(
				function ($resolve) use ($directoryUrl) {
					try {
						$syncResult = $this->syncDirectory(directoryUrl: $directoryUrl);

						// Directory sync completed successfully.
						$resolve(
							[
								'success' => true,
								'directory' => $directoryUrl,
								'result' => $syncResult,
							]
						);
					} catch (\Exception $e) {
						// Removed redundant logging (error handled silently).
						$resolve(
							[
								'success' => false,
								'directory' => $directoryUrl,
								'error' => $e->getMessage(),
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
				'error' => $syncResult['error'],
			];
		}

		$this->connectionReporter?->reportDirectorySync(results: $results);

		return $results;
	}//end doCronSync()

	/**
	 * Get unique directory URLs from stored listings
	 *
	 * Retrieves all unique directory URLs from listings that are currently
	 * stored in the system and available for synchronization.
	 *
	 * @param boolean $availableOnly Whether to include only available listings
	 * @param boolean $defaultOnly Whether to include only default listings
	 *
	 * @return array<string> Array of unique directory URLs
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface
	 * @throws DoesNotExistException|MultipleObjectsReturnedException
	 *
	 * @psalm-suppress InvalidArgument
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	public function getUniqueDirectories(bool $availableOnly = false, bool $defaultOnly = false): array {
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
		$listingSchema = $this->config->getValueString($this->appName, 'listing_schema', '');
		$listingRegister = $this->config->getValueString($this->appName, 'listing_register', '');

		// Removed redundant logging.
		$uniqueDirectoryUrls = [];

		// Get listings if configuration is available.
		if (empty($listingSchema) === false && empty($listingRegister) === false) {
			try {
				$query = [
					'@self' => [
						'register' => $listingRegister,
						'schema' => $listingSchema,
					],
				];

				// Directory data is public by design — listings/catalogs have authorization.read=["public"].
				// RBAC is disabled so public directory discovery works without user context (see ADR-002).
				// Multitenancy is NOT bypassed: each tenant sees only its own listing objects.
				// Cross-instance federation uses the explicit URL-based remote-fetch path instead.
				$listings = $objectService->searchObjects($query, _rbac: false);

				// Build unique directory URLs using URL as key to automatically handle duplicates.
				foreach ($listings as $listing) {
					$listingData = $listing->jsonSerialize();
					$objectData = ($listingData['object'] ?? $listingData);

					$available = ($objectData['integrationLevel'] ?? null) === 'search';
					$default = $objectData['default'] ?? false;

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

					// Check for publications URL in the object data.
					// This method historically returns publications URLs — every caller that
					// fetches publications (PublicationService::getPublications, getUsed,
					// getPublication, the aggregated federation search) treats each returned
					// URL as a publications endpoint. The BFS sync flow uses the sibling
					// method getKnownDirectoryUrls() below to walk `directory` URLs instead.
					if (isset($objectData['publications']) === true && empty($objectData['publications']) === false) {
						$uniqueDirectoryUrls[$objectData['publications']] = $objectData['publications'];
					}
				}//end foreach
			} catch (\Exception $e) {
				// Removed redundant logging.
			}//end try
		}//end if

		// Return just the unique URLs as an indexed array.
		$result = array_values($uniqueDirectoryUrls);

		// Cache the result with current timestamp.
		$this->cachedUniqueDirs = $result;
		$this->cacheTimestamp = $currentTime;

		return $result;
	}//end getUniqueDirectories()

	/**
	 * Get the set of known peer `/api/directory` URLs to sync with.
	 *
	 * Distinct from getUniqueDirectories() which returns PUBLICATIONS URLs for the
	 * federation search flow (getPublications / getUsed / getPublication) — this
	 * method returns DIRECTORY URLs for the BFS-discovery sync flow (doCronSync,
	 * syncDirectory).
	 *
	 * Sources:
	 *   - The `directory` field of every stored listing (populated by
	 *     syncListing() with the peer's own /api/directory URL for federated
	 *     listings, or our own URL for local catalogs — filtered via isSelfInstance).
	 *
	 * Guarantees:
	 *   - Skips self (host+port aware, honours instance_aliases).
	 *   - Only URLs that look like `/api/directory` endpoints; blocks a stale row
	 *     with a publications URL from ever being fed back into syncDirectory().
	 *
	 * @return array<string> Unique peer directory URLs safe to sync from.
	 *
	 * @throws \RuntimeException When OpenRegister is not installed.
	 *
	 * @spec openspec/specs/dashboard/spec.md#requirement-synchronize-all-directories-via-cron-every-hour-dir-004
	 */
	public function getKnownDirectoryUrls(): array {
		if (in_array('openregister', $this->appManager->getInstalledApps()) === false) {
			throw new RuntimeException('OpenRegister service is not available.');
		}

		$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

		$listingSchema = $this->config->getValueString($this->appName, 'listing_schema', '');
		$listingRegister = $this->config->getValueString($this->appName, 'listing_register', '');
		if (empty($listingSchema) === true || empty($listingRegister) === true) {
			return [];
		}

		try {
			$listings = $objectService->searchObjects(
				query: [
					'@self' => [
						'register' => $listingRegister,
						'schema' => $listingSchema,
					],
				],
				_rbac: false
			);
		} catch (\Throwable $e) {
			return [];
		}

		$urls = [];
		foreach ($listings as $listing) {
			$listingData = $listing->jsonSerialize();
			$objectData = ($listingData['object'] ?? $listingData);

			$dir = ($objectData['directory'] ?? null);
			if (empty($dir) === true) {
				continue;
			}

			// Only real /api/directory endpoints belong in the sync set.
			if (strpos($dir, '/api/directory') === false) {
				continue;
			}

			// Skip self so cron doesn't sync our own /api/directory back to us.
			if ($this->isSelfInstance(url: $dir) === true) {
				continue;
			}

			$urls[$dir] = $dir;
		}//end foreach

		return array_values($urls);
	}//end getKnownDirectoryUrls()

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
	 * @spec openspec/specs/dashboard/spec.md
	 */
	public function syncDirectory(string $directoryUrl): array {
		$this->ensureKnownDirectories();
		$this->assertSyncableDirectoryUrl(directoryUrl: $directoryUrl);

		$results = [
			'directory_url' => $directoryUrl,
			'sync_time' => new DateTime(),
			'listings_created' => 0,
			'listings_updated' => 0,
			'listings_unchanged' => 0,
			'listings_skipped' => 0,
			'listings_failed' => 0,
			'total_processed' => 0,
			'errors' => [],
			'listing_details' => [],
		];

		// SSRF guard: validate the attacker-controllable directory URL BEFORE fetching.
		// Throws InvalidArgumentException (mapped to HTTP 400) when the target is not safe.
		$this->assertSafeOutboundUrl(url: $directoryUrl);

		try {
			$directoryData = $this->fetchDirectoryData(directoryUrl: $directoryUrl);
			if (isset($directoryData['results']) === true && is_array($directoryData['results']) === true) {
				$results = $this->syncDirectoryListings(results: $results, listings: $directoryData['results'], directoryUrl: $directoryUrl);
			}
		} catch (GuzzleException $e) {
			throw $this->recordDirectoryFetchFailure(directoryUrl: $directoryUrl, exception: $e);
		} catch (\Exception $e) {
			$this->updateDirectoryStatusOnError(directoryUrl: $directoryUrl, statusCode: 500, errorMessage: 'Sync failed: ' . $e->getMessage());
			throw $e;
		}

		return $results;
	}//end syncDirectory()

	/**
	 * Cache the known peer directories, plus the default directory, for the cross-directory check.
	 *
	 * Uses directory URLs, not publication URLs: see getKnownDirectoryUrls().
	 *
	 * @return void
	 *
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	private function ensureKnownDirectories(): void {
		if (empty($this->uniqueDirectories) === false) {
			return;
		}

		$this->uniqueDirectories = $this->getKnownDirectoryUrls();
		$defaultDirectory = $this->getDefaultDirectoryUrl();
		if (in_array($defaultDirectory, $this->uniqueDirectories) === false) {
			$this->uniqueDirectories[] = $defaultDirectory;
		}
	}//end ensureKnownDirectories()

	/**
	 * Refuse an empty or malformed directory URL, or one that points at this instance.
	 *
	 * The self check compares host and port, so an alias of this instance (such as
	 * localhost:9081 against nc-fed-1 on a docker network) is refused too (WOO-516).
	 *
	 * @param string $directoryUrl The directory URL to sync.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the URL cannot be synced.
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	private function assertSyncableDirectoryUrl(string $directoryUrl): void {
		if (empty($directoryUrl) === true) {
			throw new InvalidArgumentException('Directory URL cannot be empty');
		}

		if (filter_var($directoryUrl, FILTER_VALIDATE_URL) === false) {
			throw new InvalidArgumentException('Invalid directory URL provided');
		}

		if ($this->isSelfInstance(url: $directoryUrl) === true) {
			throw new InvalidArgumentException('Cannot sync with current directory');
		}
	}//end assertSyncableDirectoryUrl()

	/**
	 * Fetch and decode a peer directory, including the listings it learned from other peers.
	 *
	 * `include-federated=1` is the discovery opt-in (see getDirectory()); older peers
	 * ignore it and return only their own catalogs. Redirects are checked per hop by
	 * safeGet().
	 *
	 * @param string $directoryUrl The directory URL.
	 *
	 * @return mixed The decoded directory response.
	 *
	 * @throws GuzzleException
	 * @throws InvalidArgumentException When the answer is not valid JSON.
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	private function fetchDirectoryData(string $directoryUrl) {
		$response = $this->safeGet(url: $directoryUrl . '?_limit=10000&include-federated=1');
		$directoryData = json_decode($response->getBody()->getContents(), true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			throw new InvalidArgumentException('Invalid JSON response from directory');
		}

		return $directoryData;
	}//end fetchDirectoryData()

	/**
	 * Sync the listings of a peer directory and broadcast to the peer when it does not list us.
	 *
	 * Listings that point at this instance or at a local address are not synced.
	 *
	 * @param array  $results      The sync results so far.
	 * @param array  $listings     The listings the peer returned.
	 * @param string $directoryUrl The peer directory URL.
	 *
	 * @return array The sync results.
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	private function syncDirectoryListings(array $results, array $listings, string $directoryUrl): array {
		$syncable = array_filter(
			$listings,
			function ($listingData) {
				return $this->isSyncableListing(listingData: $listingData);
			}
		);

		foreach ($this->runListingSyncs(listings: $syncable, directoryUrl: $directoryUrl) as $listingResult) {
			$results = $this->tallyListingResult(results: $results, listingResult: $listingResult);
		}

		$hasOurListings = count($listings) > count($syncable);
		if ($hasOurListings === false) {
			$this->broadcastToDirectory(directoryUrl: $directoryUrl);
		}

		return $results;
	}//end syncDirectoryListings()

	/**
	 * Whether a peer's listing is synced: not when it points at this instance or at a local address.
	 *
	 * @param mixed $listingData A listing from the peer.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	private function isSyncableListing($listingData): bool {
		if (isset($listingData['directory']) === false) {
			return true;
		}

		return $this->isSelfInstance(url: $listingData['directory']) === false
			&& $this->isLocalUrl(url: $listingData['directory']) === false;
	}//end isSyncableListing()

	/**
	 * Sync listings concurrently; when the batch fails, every listing gets a failed result.
	 *
	 * @param array  $listings     The listings to sync.
	 * @param string $directoryUrl The peer directory URL.
	 *
	 * @return array The per-listing sync results.
	 *
	 * @psalm-suppress InvalidArgument React Promise resolve callbacks receive arrays
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	private function runListingSyncs(array $listings, string $directoryUrl): array {
		$listingPromises = [];
		foreach ($listings as $listingData) {
			$listingPromises[] = new Promise(
				function ($resolve) use ($listingData, $directoryUrl) {
					$resolve($this->syncListing(listingData: $listingData, sourceDirectoryUrl: $directoryUrl));
				}
			);
		}

		if (empty($listingPromises) === true) {
			return [];
		}

		try {
			return \React\Async\await(\React\Promise\all($listingPromises));
		} catch (\Exception $e) {
			$failed = [
				'listing_id' => 'unknown',
				'listing_title' => 'Failed Promise',
				'action' => 'failed',
				'success' => false,
				'error' => 'Promise execution failed: ' . $e->getMessage(),
			];

			return array_fill(0, count($listingPromises), $failed);
		}
	}//end runListingSyncs()

	/**
	 * Count one listing sync result into the directory sync results.
	 *
	 * @param array $results       The directory sync results.
	 * @param array $listingResult The result of one listing sync.
	 *
	 * @return array
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	private function tallyListingResult(array $results, array $listingResult): array {
		$results['total_processed']++;
		$results['listing_details'][] = $listingResult;

		if ($listingResult['success'] === true) {
			$counter = (self::SYNC_ACTION_COUNTERS[$listingResult['action']] ?? null);
			if ($counter !== null) {
				$results[$counter]++;
			}
		}

		if ($listingResult['success'] === false) {
			$results['listings_failed']++;
			if ($listingResult['error'] !== null) {
				$results['errors'][] = 'Listing ' . $listingResult['listing_id'] . ': ' . $listingResult['error'];
			}
		}

		return $results;
	}//end tallyListingResult()

	/**
	 * Announce this instance to a peer directory, unless this instance has a local URL or
	 * the sync was itself triggered by a broadcast (which would loop). A failure is ignored.
	 *
	 * @param string $directoryUrl The peer directory URL.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	private function broadcastToDirectory(string $directoryUrl): void {
		$ourDirectoryUrl = $this->urlGenerator->getAbsoluteURL(
			$this->urlGenerator->linkToRoute('opencatalogi.directory.index')
		);
		if ($this->isLocalUrl(url: $ourDirectoryUrl) === true || $this->isSystemBroadcast() === true) {
			return;
		}

		try {
			$this->broadcastService->broadcast($directoryUrl);
		} catch (\Exception $e) {
			// A peer that cannot be reached learns about us on its own next sync.
		}
	}//end broadcastToDirectory()

	/**
	 * Mark the directory's listings with a failed fetch and build the exception to rethrow.
	 *
	 * The rethrown RequestException carries the original request and response, or a
	 * synthetic GET of the directory URL when the failure had no request.
	 *
	 * @param string          $directoryUrl The directory URL.
	 * @param GuzzleException $exception    The fetch failure.
	 *
	 * @return RequestException
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	private function recordDirectoryFetchFailure(string $directoryUrl, GuzzleException $exception): RequestException {
		$error = 'Failed to fetch directory data: ' . $exception->getMessage();
		$errorCode = $exception->getCode();
		if ($errorCode === 0) {
			$errorCode = 500;
		}

		$this->updateDirectoryStatusOnError(directoryUrl: $directoryUrl, statusCode: $errorCode, errorMessage: $error);

		if ($exception instanceof RequestException) {
			return new RequestException(
				message: $error,
				request: $exception->getRequest(),
				response: $exception->getResponse(),
				previous: $exception
			);
		}

		return new RequestException(
			message: $error,
			request: new Psr7Request('GET', $directoryUrl),
			response: null,
			previous: $exception
		);
	}//end recordDirectoryFetchFailure()

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
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	public function syncListing(array $listingData, string $sourceDirectoryUrl): array {
		$listingDirectory = ($listingData['directory'] ?? $listingData['@self']['relations']['directory'] ?? null);
		$result = [
			'listing_id' => ($listingData['id'] ?? 'unknown'),
			'listing_title' => ($listingData['title'] ?? $listingData['@self']['name'] ?? 'Unknown'),
			'action' => 'none',
			'success' => false,
			'error' => null,
		];

		if ($this->isFromOtherKnownDirectory(listingDirectory: $listingDirectory, sourceDirectoryUrl: $sourceDirectoryUrl) === true) {
			return $this->skippedForOtherDirectory(result: $result, listingDirectory: $listingDirectory);
		}

		$target = null;
		$existingListings = [];
		try {
			$target = $this->resolveListingTarget();
			$target['uuid'] = ($listingData['@self']['id'] ?? $listingData['id'] ?? null);
			$listingData = $this->prepareListingData(
				listingData: $listingData,
				sourceDirectoryUrl: $sourceDirectoryUrl,
				listingDirectory: $listingDirectory
			);
			$existingListings = $this->findExistingListings(target: $target, listingData: $listingData);

			return array_merge($result, $this->storeListing(listingData: $listingData, existingListings: $existingListings, target: $target));
		} catch (\Exception $e) {
			$result['error'] = $e->getMessage();
			$this->recordListingError(target: $target, existingListings: $existingListings, exception: $e);
		}

		return $result;
	}//end syncListing()

	/**
	 * Whether a listing belongs to another directory that is synced as a source of its own.
	 *
	 * @param mixed  $listingDirectory   The directory the listing names.
	 * @param string $sourceDirectoryUrl The directory being synced.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	private function isFromOtherKnownDirectory($listingDirectory, string $sourceDirectoryUrl): bool {
		return empty($listingDirectory) === false
			&& $listingDirectory !== $sourceDirectoryUrl
			&& in_array($listingDirectory, $this->uniqueDirectories) === true;
	}//end isFromOtherKnownDirectory()

	/**
	 * The sync result of a listing that is skipped because its own directory is synced separately.
	 *
	 * @param array $result           The initial sync result.
	 * @param mixed $listingDirectory The directory the listing names.
	 *
	 * @return array
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	private function skippedForOtherDirectory(array $result, $listingDirectory): array {
		$result['action'] = 'skipped_other_directory';
		$result['success'] = true;
		$result['reason'] = 'Listing belongs to directory ' . $listingDirectory . ' which is processed separately';

		return $result;
	}//end skippedForOtherDirectory()

	/**
	 * The ObjectService and the listing register/schema that synced listings are stored in.
	 *
	 * @return array{objectService: object, register: string, schema: string}
	 *
	 * @throws RuntimeException When OpenRegister is not installed or the listing register/schema is not configured.
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	private function resolveListingTarget(): array {
		if (in_array('openregister', $this->appManager->getInstalledApps()) === false) {
			throw new RuntimeException('OpenRegister service is not available.');
		}

		$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		$listingSchema = $this->config->getValueString($this->appName, 'listing_schema', '');
		$listingRegister = $this->config->getValueString($this->appName, 'listing_register', '');
		if (empty($listingSchema) === true || empty($listingRegister) === true) {
			throw new RuntimeException('Listing schema or register not configured');
		}

		return [
			'objectService' => $objectService,
			'register' => $listingRegister,
			'schema' => $listingSchema,
		];
	}//end resolveListingTarget()

	/**
	 * Normalise an incoming listing to the local listing schema.
	 *
	 * @param array  $listingData        The listing as the peer sent it.
	 * @param string $sourceDirectoryUrl The directory being synced.
	 * @param mixed  $listingDirectory   The directory the listing names.
	 *
	 * @return array The listing to store.
	 *
	 * @throws InvalidArgumentException When the listing has no id or no catalog.
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	private function prepareListingData(array $listingData, string $sourceDirectoryUrl, $listingDirectory): array {
		$listingData = $this->withListingIdentity(listingData: $listingData);
		$listingData = $this->withRelationEndpoints(listingData: $listingData);
		unset($listingData['@self']);

		if (empty($listingData['publications']) === true) {
			$listingData['publications'] = $this->detectPublicationEndpoint(listingData: $listingData);
		}

		// The peer's own directory URL is kept so the next cron sync can reach that peer
		// directly (discovery); the source directory is only the fallback for a payload
		// without one, so the dedup key is never null.
		$listingData['directory'] = $listingDirectory;
		if (empty($listingDirectory) === true) {
			$listingData['directory'] = $sourceDirectoryUrl;
		}

		$listingData = $this->withSyncStamps(listingData: $listingData);
		if (empty($listingData['summary']) === true) {
			$listingData['summary'] = 'unknown';
		}

		$listingData['schemaCount'] = 0;
		if (isset($listingData['schemas']) === true && is_array($listingData['schemas']) === true) {
			$listingData['schemaCount'] = count($listingData['schemas']);
		}

		return $this->withListingDefaults(listingData: $listingData, sourceDirectoryUrl: $sourceDirectoryUrl);
	}//end prepareListingData()

	/**
	 * Validate the listing id and catalog, and write them as `id`, `catalog` and `catalogusId`.
	 *
	 * The catalog comes from `catalog`, then `@self.relations.catalog`, then the listing id.
	 * A missing top-level title is taken from `@self.name`.
	 *
	 * @param array $listingData The listing as the peer sent it.
	 *
	 * @return array
	 *
	 * @throws InvalidArgumentException When the listing has no id or no catalog.
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	private function withListingIdentity(array $listingData): array {
		$listingId = ($listingData['id'] ?? null);
		$catalogId = ($listingData['catalog'] ?? $listingData['@self']['relations']['catalog'] ?? null);
		if ($catalogId === null && empty($listingId) === false) {
			$catalogId = $listingId;
		}

		if (empty($listingId) === true) {
			throw new InvalidArgumentException('Invalid listing data: missing id');
		}

		if (empty($catalogId) === true) {
			throw new InvalidArgumentException('Invalid listing data: missing catalog');
		}

		$listingData['catalog'] = $catalogId;
		$listingData['catalogusId'] = $catalogId;
		$listingData['id'] = $listingId;
		if (empty($listingData['title']) === true && isset($listingData['@self']['name']) === true) {
			$listingData['title'] = $listingData['@self']['name'];
		}

		return $listingData;
	}//end withListingIdentity()

	/**
	 * Copy the catalog's API endpoints from `@self.relations` onto the listing.
	 *
	 * `@self.relations.directory` is the catalog's own directory URL, stored as
	 * `catalogDirectory`; it differs from the directory the listing was synced from.
	 *
	 * @param array $listingData The listing.
	 *
	 * @return array
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	private function withRelationEndpoints(array $listingData): array {
		$relations = ($listingData['@self']['relations'] ?? null);
		if (is_array($relations) === false) {
			return $listingData;
		}

		$endpoints = [
			'publications' => 'publications',
			'search' => 'search',
			'directory' => 'catalogDirectory',
		];
		foreach ($endpoints as $relation => $property) {
			if (empty($relations[$relation]) === false) {
				$listingData[$property] = $relations[$relation];
			}
		}

		return $listingData;
	}//end withRelationEndpoints()

	/**
	 * Stamp a successful sync and normalise `published` to ISO 8601.
	 *
	 * `lastSync` is the last attempt and `lastSuccessAt` only moves on success, so a
	 * failing peer keeps its last good time (REQ-FLS-001). A missing or unreadable
	 * `published` becomes now.
	 *
	 * @param array $listingData The listing.
	 *
	 * @return array
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	private function withSyncStamps(array $listingData): array {
		$listingData['lastSync']      = (new DateTime())->format('c');
		$listingData['lastSuccessAt'] = $listingData['lastSync'];
		$listingData['lastError']     = '';

		$originalPublished = ($listingData['published'] ?? null);
		$listingData['published'] = (new DateTime())->format('c');
		if (empty($originalPublished) === false) {
			try {
				$listingData['published'] = (new DateTime((string)$originalPublished))->format('c');
			} catch (\Exception $e) {
				$listingData['published'] = (new DateTime())->format('c');
			}
		}

		return $listingData;
	}//end withSyncStamps()

	/**
	 * Set the status fields of a newly seen listing; an update later keeps the stored ones.
	 *
	 * @param array  $listingData        The listing.
	 * @param string $sourceDirectoryUrl The directory being synced.
	 *
	 * @return array
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	private function withListingDefaults(array $listingData, string $sourceDirectoryUrl): array {
		$listingData['default'] = ($sourceDirectoryUrl === $this->getDefaultDirectoryUrl());
		$listingData['statusCode'] = 200;
		$listingData['status'] = 'development';
		$listingData['integrationLevel'] = 'search';

		return $listingData;
	}//end withListingDefaults()

	/**
	 * Find the stored listing for a catalog from a directory.
	 *
	 * Matched on catalogusId plus directory: instances generate different UUIDs for
	 * the same logical catalog, and the directory URL tells those peers apart while
	 * repeated syncs of the same peer still find the same listing.
	 *
	 * @param array $target      The ObjectService and listing register/schema.
	 * @param array $listingData The prepared listing.
	 *
	 * @return mixed The matching listings.
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	private function findExistingListings(array $target, array $listingData) {
		return $target['objectService']->searchObjects(
			query: [
				'@self' => [
					'register' => $target['register'],
					'schema' => $target['schema'],
				],
				'catalogusId' => $listingData['catalogusId'],
				'directory' => $listingData['directory'],
			]
		);
	}//end findExistingListings()

	/**
	 * Create the listing, or update the stored one when the incoming data is newer and differs.
	 *
	 * @param array $listingData      The prepared listing.
	 * @param mixed $existingListings The stored listings that match it.
	 * @param array $target           The ObjectService, listing register/schema and new uuid.
	 *
	 * @return array The outcome: action, success and, for a skip, the reason.
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	private function storeListing(array $listingData, $existingListings, array $target): array {
		if (empty($existingListings) === true) {
			$this->saveListing(target: $target, listingData: $listingData, uuid: $target['uuid']);
			return ['action' => 'created', 'success' => true];
		}

		$existingListingData = $existingListings[0]->jsonSerialize();
		$existingObject = ($existingListingData['object'] ?? []);
		$listingData['default'] = ($existingObject['default'] ?? $listingData['default']);
		$listingData['status'] = ($existingObject['status'] ?? 'development');
		$listingData['integrationLevel'] = ($existingObject['integrationLevel'] ?? 'search');

		if ($this->isListingDataOutdated(incomingData: $listingData, existingData: $existingListingData) === true) {
			return ['action' => 'skipped_outdated', 'success' => true, 'reason' => 'Incoming listing data is older than existing data'];
		}

		if (hash('sha256', json_encode($listingData)) === hash('sha256', json_encode($existingObject))) {
			return ['action' => 'unchanged', 'success' => true];
		}

		$this->saveListing(target: $target, listingData: $listingData, uuid: $existingListingData['id']);

		return ['action' => 'updated', 'success' => true];
	}//end storeListing()

	/**
	 * Save a listing object in the listing register/schema.
	 *
	 * @param array $target      The ObjectService and listing register/schema.
	 * @param array $listingData The listing object.
	 * @param mixed $uuid        The uuid to save it under.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	private function saveListing(array $target, array $listingData, $uuid): void {
		$target['objectService']->saveObject(
			object: $listingData,
			extend: [],
			register: $target['register'],
			schema: $target['schema'],
			uuid: $uuid
		);
	}//end saveListing()

	/**
	 * Record a failed sync on the stored listing; `lastSuccessAt` is left as it was.
	 *
	 * Does nothing when the failure came before a stored listing was found. A failure
	 * to record is ignored.
	 *
	 * @param array|null $target           The ObjectService and listing register/schema, or null.
	 * @param mixed      $existingListings The stored listings found so far.
	 * @param \Exception $exception        The sync failure.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	private function recordListingError(?array $target, $existingListings, \Exception $exception): void {
		if ($target === null || is_array($existingListings) === false || count($existingListings) === 0) {
			return;
		}

		try {
			$existingListingData = $existingListings[0]->jsonSerialize();
			$errorData = ($existingListingData['object'] ?? []);
			$errorData['statusCode'] = 500;
			$errorData['lastSync']   = (new DateTime())->format('c');
			$errorData['lastError']  = self::sanitizeSyncError(message: $exception->getMessage());

			$this->saveListing(target: $target, listingData: $errorData, uuid: $existingListingData['id']);
		} catch (\Exception $updateException) {
			// A failure to record the error must not mask the sync failure.
		}
	}//end recordListingError()

	/**
	 * Get publications from all available federated catalogs asynchronously
	 *
	 * Fetches publications from all publication endpoints of listings marked as available,
	 * combining results into a single array.
	 *
	 * @param array $guzzleConfig Optional Guzzle configuration for HTTP requests
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
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	public function getPublications(array $guzzleConfig = [], bool $includeDefault = false): array {
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
			RequestOptions::TIMEOUT => 5,
			RequestOptions::CONNECT_TIMEOUT => 2,
			RequestOptions::HEADERS => [
				'Accept' => 'application/json',
				'User-Agent' => 'OpenCatalogi-DirectoryService/1.0',
			],
			RequestOptions::HTTP_ERRORS => false,
		];

		$finalGuzzleConfig = array_merge($defaultGuzzleConfig, $guzzleConfig);
		$queryParams = ($finalGuzzleConfig['query_params'] ?? []);
		// Prevent circular aggregation — the peer must not fan out to its own
		// peers (which would eventually loop back to us).
		$queryParams['_aggregate'] = 'false';
		// Force catalog enrichment on the peer response so the client-side
		// @result-click handler in FederationSearch can build a peer deep-link
		// (`<peer>/#/publications/<catalogSlug>/<id>`). Without this, the
		// peer's _aggregate=false path takes the ultra-fast branch that skips
		// catalog stubs and returns @self.catalogs=null — click-through then
		// silently falls back to the peer app-root (WOO-522 / WOO-533).
		$queryParams['_include_catalogs'] = 'true';
		$queryParams['_extend'] = [
			'@self.schema',
			'@self.register',
		];
		// Add self-extension.
		$client = new Client($finalGuzzleConfig);
		$promises = [];
		$urlToDirectoryMap = [];

		// Create promises for each directory.
		foreach ($directories as $index => $directoryUrl) {
			// Skip our own directory.
			if ($directoryUrl === $ourDirectoryUrl) {
				continue;
			}

			// Skip local/unsafe URLs — assertSafeOutboundUrl performs DNS resolution.
			try {
				$this->assertSafeOutboundUrl(url: $directoryUrl);
			} catch (InvalidArgumentException $e) {
				continue;
			}

			// The directoryUrl is now actually a publications URL from getUniqueDirectories().
			$publicationsUrl = $directoryUrl;

			if (empty($queryParams) === false) {
				$publicationsUrl .= '?' . http_build_query($queryParams);
			}

			// Removed redundant logging (error handled silently).
			// Store mapping for later source tracking.
			$parsedHost = parse_url($directoryUrl, PHP_URL_HOST);
			if (empty($parsedHost) === true) {
				$parsedHost = $directoryUrl;
			}

			$urlToDirectoryMap[count($promises)] = [
				'url' => $publicationsUrl,
				'directoryUrl' => $directoryUrl,
				'name' => $parsedHost,
			];

			$promises[] = new Promise(
				resolver: function ($resolve) use ($client, $publicationsUrl) {
					$failResult = ['success' => false, 'results' => [], 'facets' => [], 'total' => 0];
					try {
						// SSRF hardening (SB1 / wave-12): disable automatic redirect following so
						// that a federated peer cannot pivot to cloud-metadata via a 302 response.
						// The initial URL is already validated by assertSafeOutboundUrl(); here we
						// ensure Guzzle never silently follows a redirect to an unvalidated host.
						$response = $client->get($publicationsUrl, [RequestOptions::ALLOW_REDIRECTS => false]);
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
									'facets' => $data['facets'] ?? [],
									'total' => $data['total'],
								]
							);
							return;
						}

						if (is_array($data) === true) {
							$resolve(
								[
									'success' => true,
									'results' => $data,
									'facets' => [],
									'total' => $data['total'],
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
		$seenIds = [];
		$sources = [];
		$combinedFacets = [];
		$combinedTotal = 0;

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
				$combinedResults[] = $item;
				$seenIds[$itemId] = true;
			}

			// Aggregate facets if they exist.
			if (empty($result['facets']) === false) {
				$combinedFacets = $this->aggregateFacets(existingFacets: $combinedFacets, newFacets: $result['facets']);
			}
		}//end foreach

		// Removed redundant logging.
		return [
			'results' => $combinedResults,
			'sources' => $sources,
			'facets' => $combinedFacets,
			'total' => $combinedTotal,
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
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	private function detectPublicationEndpoint(array $listingData): ?string {
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

			$searchUrl = (string)$listingData['search'];
			$publicationEndpoint = (string)str_replace('/search', '/publications', $searchUrl);

			// Also handle cases where 'search' might be a query parameter or different pattern.
			if ($publicationEndpoint === $listingData['search']) {
				// Try replacing 'search' anywhere in the URL path.
				$publicationEndpoint = (string)preg_replace('/\/search(?=\/|$)/', '/publications', $listingData['search']);
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

					$newPath = '/' . implode('/', $pathSegments);
					$publicationEndpoint = $urlParts['scheme'] . '://' . $urlParts['host'];
					if (isset($urlParts['port']) === true) {
						$publicationEndpoint .= ':' . $urlParts['port'];
					}

					$publicationEndpoint .= $newPath;
					if (isset($urlParts['query']) === true) {
						$publicationEndpoint .= '?' . $urlParts['query'];
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

			$catalogDir = (string)$listingData['catalogDirectory'];
			// Replace /api/directory with /api/publications.
			$publicationEndpoint = (string)str_replace('/api/directory', '/api/publications', $catalogDir);
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
				return 'https://' . $directory . '/apps/opencatalogi/api/publications';
			}

			// Directory is a full URL, extract the base and construct publications endpoint.
			$urlParts = parse_url($directory);
			if ($urlParts !== false && isset($urlParts['host']) === true) {
				$publicationEndpoint = $urlParts['scheme'] . '://' . $urlParts['host'];
				if (isset($urlParts['port']) === true) {
					$publicationEndpoint .= ':' . $urlParts['port'];
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
					return 'https://' . $hostname . '/apps/opencatalogi/api/publications';
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
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	private function isListingDataOutdated(array $incomingData, array $existingData): bool {
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
			$incomingUpdated = $this->extractTimestamp(data: $incomingData);
			$existingUpdated = $this->extractTimestamp(data: $existingObject);

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
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	private function extractTimestamp(array $data): ?\DateTime {
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
					} elseif (is_array($value) === true && isset($value['date']) === true) {
						// Handle DateTime object serialized as array.
						return new DateTime($value['date']);
					} elseif ($value instanceof \DateTime) {
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
	 * @param string $directoryUrl The directory URL that failed
	 * @param integer $statusCode   The HTTP status code of the error
	 * @param string  $errorMessage The error text; stored without credentials or query strings
	 *
	 * @return void
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface
	 *
	 * @spec openspec/changes/federation-connection-last-success/specs/federation/spec.md#REQ-FLS-001
	 */
	private function updateDirectoryStatusOnError(string $directoryUrl, int $statusCode, string $errorMessage=''): void {
		try {
			// Check if OpenRegister service is available.
			if (in_array('openregister', $this->appManager->getInstalledApps()) === false) {
				return;
				// Can't update if OpenRegister is not available.
			}

			// Get ObjectService from container..
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

			// Get listing configuration.
			$listingSchema = $this->config->getValueString($this->appName, 'listing_schema', '');
			$listingRegister = $this->config->getValueString($this->appName, 'listing_register', '');

			if (empty($listingSchema) === true || empty($listingRegister) === true) {
				return;
				// Can't update without schema/register configuration.
			}

			// Find all listings from this directory.
			$existingListings = $objectService->searchObjects(
				query: [
					'@self' => [
						'register' => $listingRegister,
						'schema' => $listingSchema,
					],
					'directory' => $directoryUrl,
				]
			);

			// Update each listing with error status.
			foreach ($existingListings as $listing) {
				$listingData = $listing->jsonSerialize();
				$errorData = ($listingData['object'] ?? []);

				// Update with error status. lastSuccessAt is left as it was.
				$errorData['statusCode'] = $statusCode;
				$errorData['lastSync']   = (new DateTime())->format('c');
				$errorData['lastError']  = self::sanitizeSyncError(message: $errorMessage);

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
	 * Strip credentials and query strings from a sync error before it is stored.
	 *
	 * Peer URLs can carry a token in the query string or a user and password in
	 * the authority part, and Guzzle repeats the full URL in its messages. The
	 * stored error is shown to administrators, so neither may survive. The text
	 * is also capped so a peer cannot fill the listing with a huge body.
	 *
	 * @param string $message The raw error message
	 *
	 * @return string The message without credentials or query strings
	 *
	 * @spec openspec/changes/federation-connection-last-success/specs/federation/spec.md#REQ-FLS-001
	 */
	private static function sanitizeSyncError(string $message): string {
		$clean = preg_replace_callback(
			'~\b([a-z][a-z0-9+.-]*://)([^\s/?#`\'"<>]*@)?([^\s?#`\'"<>]*)(\?[^\s#`\'"<>]*)?(#[^\s`\'"<>]*)?~i',
			static function (array $match): string {
				return $match[1].$match[3];
			},
			$message
		);
		if ($clean === null) {
			$clean = '';
		}

		// Any query-looking fragment left outside a URL (for example "?token=abc").
		$clean = (string) preg_replace('~\?[^\s`\'"<>]*=[^\s`\'"<>]*~', '', $clean);

		return mb_substr($clean, 0, 1000);

	}//end sanitizeSyncError()

	/**
	 * Check if the current request is from a system broadcast
	 *
	 * Determines if the current HTTP request is from another OpenCatalogi instance
	 * sending a broadcast notification, to prevent infinite broadcast loops.
	 *
	 * @return boolean True if request is from a system broadcast, false otherwise
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	private function isSystemBroadcast(): bool {
		$userAgent = $this->request->getHeader('User-Agent');

		// Check if User-Agent contains 'OpenCatalogi-Broadcast' (future-proof for version changes).
		return empty($userAgent) === false && str_contains($userAgent, 'OpenCatalogi-Broadcast') === true;
	}//end isSystemBroadcast()

	/**
	 * Determine whether a URL points at THIS OpenCatalogi instance.
	 *
	 * The container is often reachable via several equivalent host-aliases
	 * (e.g. `localhost:9081` from the host, `nc-fed-1` on the docker network,
	 * or the operator's public FQDN behind a reverse proxy). All of them are
	 * legitimately "this instance". `isSelfInstance()` normalises the URL and
	 * compares host+port against:
	 *
	 * 1. `$this->urlGenerator->getBaseUrl()` — the canonical NC-configured base.
	 * 2. The `opencatalogi/instance_aliases` app-config CSV — extra aliases the
	 *    operator has declared (entries may be `host` or `host:port`).
	 *
	 * Port normalisation: an omitted port on `http://` normalises to `80`,
	 * on `https://` to `443` — so `http://cloud/dir` and `http://cloud:80/dir`
	 * both resolve to the same identity. IPv6 host literals (`[::1]`) are
	 * parsed via `parse_url` for both the target and the alias entries so
	 * the FIRST-colon `explode` never truncates a bracketed host.
	 *
	 * @param string $url The URL to test.
	 *
	 * @return boolean True when $url resolves to this instance.
	 *
	 * @spec exclude URL-canonicalisation helper for self-detection; security
	 *       plumbing that hardens syncDirectory()'s self-check, no new domain
	 *       requirement. Enforced by DirectoryServiceTest::testIsSelfInstance*.
	 */
	private function isSelfInstance(string $url): bool {
		$targetPair = self::hostAndPortFromUrl(url: strtolower($url));
		if ($targetPair === null) {
			return false;
		}

		[$targetHost, $targetPort] = $targetPair;

		// 1. Canonical NC base.
		$basePair = self::hostAndPortFromUrl(url: strtolower($this->urlGenerator->getBaseUrl()));
		if ($basePair !== null && $basePair[0] === $targetHost && $basePair[1] === $targetPort) {
			return true;
		}

		// 2. Operator-declared aliases (CSV of host or host:port).
		$aliases = $this->config->getValueString($this->appName, 'instance_aliases', '');
		if ($aliases === '') {
			return false;
		}

		foreach (array_filter(array_map('trim', explode(',', strtolower($aliases)))) as $alias) {
			$aliasPair = self::hostAndPortFromAlias(alias: $alias);
			if ($aliasPair === null || $aliasPair[0] !== $targetHost) {
				continue;
			}

			// Alias without a port matches ANY port on that host (operator asked for it).
			// Alias with a port must match the target's normalised port exactly.
			if ($aliasPair[1] === null || $aliasPair[1] === $targetPort) {
				return true;
			}
		}

		return false;
	}//end isSelfInstance()

	/**
	 * Extract (host, normalised-port) from a URL. Returns null when the URL
	 * cannot be parsed or has no host. Default ports (80 for http, 443 for
	 * https) are materialised so `http://x` and `http://x:80` compare equal.
	 *
	 * @param string $url The URL to parse (expected already lower-cased).
	 *
	 * @return array{0:string,1:?int}|null Two-element array [host, port]; null on parse failure.
	 *
	 * @spec exclude URL-normalisation helper; security plumbing, no new domain behaviour.
	 */
	private static function hostAndPortFromUrl(string $url): ?array {
		$parts = parse_url($url);
		if ($parts === false || empty($parts['host']) === true) {
			return null;
		}

		$host = $parts['host'];
		$port = ($parts['port'] ?? null);

		// Materialise scheme-default so http://x compares equal to http://x:80.
		if ($port === null && isset($parts['scheme']) === true) {
			if ($parts['scheme'] === 'http') {
				$port = 80;
			} elseif ($parts['scheme'] === 'https') {
				$port = 443;
			}
		}

		return [$host, $port];
	}//end hostAndPortFromUrl()

	/**
	 * Extract (host, port|null) from an `instance_aliases` CSV entry. Accepts
	 * `host`, `host:port`, `[ipv6]`, and `[ipv6]:port`. Uses `parse_url` so
	 * bracketed IPv6 literals aren't corrupted by a naive first-colon split.
	 * Returns null on malformed input (empty host, trailing colon with no port).
	 *
	 * @param string $alias The alias entry (expected already lower-cased and trimmed).
	 *
	 * @return array{0:string,1:?int}|null Two-element array [host, port]; null on parse failure.
	 *
	 * @spec exclude URL-normalisation helper; security plumbing, no new domain behaviour.
	 */
	private static function hostAndPortFromAlias(string $alias): ?array {
		if ($alias === '') {
			return null;
		}

		// Route through parse_url so IPv6 brackets survive. Prepend a dummy
		// scheme when missing — `parse_url('foo:9081')` mis-parses `foo` as
		// scheme; `parse_url('x://foo:9081')` parses the port correctly.
		$probe = $alias;
		if (str_contains($alias, '://') === false) {
			$probe = 'x://' . $alias;
		}

		$parts = parse_url($probe);
		if ($parts === false || empty($parts['host']) === true) {
			return null;
		}

		// A trailing colon with no port (`host:`) yields `port` unset here;
		// that's ambiguous, so reject the entry rather than treat as catchall.
		if (str_ends_with($alias, ':') === true && isset($parts['port']) === false) {
			return null;
		}

		return [$parts['host'], ($parts['port'] ?? null)];
	}//end hostAndPortFromAlias()

	/**
	 * Check whether a host is on the dev-only local-federation allowlist.
	 *
	 * The SSRF guard ({@see assertSafeOutboundUrl()}) and the local-URL listing filter
	 * ({@see isLocalUrl()}) reject localhost, .local and RFC1918 hosts so a production
	 * instance can never be coerced into contacting an internal address. For local
	 * development we sometimes need two instances on a private docker network to federate
	 * with each other. The `opencatalogi/local_federation_hosts` app-config key holds a
	 * comma-separated allowlist of hostnames (no port) that are exempted from those checks.
	 * It is empty by default, so production — and any instance that has not opted in —
	 * keeps the full guard.
	 *
	 * @param string $host The lower-cased hostname (without port) to test.
	 *
	 * @return boolean True when the host is explicitly allowlisted for local federation.
	 *
	 * @spec exclude Dev-only SSRF allowlist toggle; security plumbing, no new domain behavior.
	 */
	private function isAllowlistedFederationHost(string $host): bool {
		$allowlist = $this->config->getValueString($this->appName, 'local_federation_hosts', '');
		if ($allowlist === '') {
			return false;
		}

		$allowedHosts = array_filter(array_map('trim', explode(',', strtolower($allowlist))));

		return in_array($host, $allowedHosts, true);
	}//end isAllowlistedFederationHost()

	/**
	 * Assert that an outbound URL is safe to fetch (SSRF guard).
	 *
	 * Restricts the scheme to http/https and rejects any URL whose host resolves
	 * to a private, loopback, link-local, unique-local, or cloud-metadata address.
	 * The host is resolved via DNS so a public hostname that maps to an internal IP
	 * (DNS-rebinding-style payload) is also rejected.
	 *
	 * @param string $url The URL to validate.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the URL or its resolved host is not safe.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 *
	 * @spec exclude SSRF input-validation guard for outbound directory fetches; security
	 *       plumbing that hardens an existing fetch, no new domain behavior.
	 */
	private function assertSafeOutboundUrl(string $url): void {
		$parsed = parse_url($url);
		if ($parsed === false || isset($parsed['scheme']) === false || isset($parsed['host']) === false) {
			throw new InvalidArgumentException('Invalid directory URL provided');
		}

		$scheme = strtolower($parsed['scheme']);
		if (in_array($scheme, ['http', 'https'], true) === false) {
			throw new InvalidArgumentException('Directory URL scheme must be http or https');
		}

		$host = strtolower($parsed['host']);

		// Dev-only: explicitly allowlisted hosts skip the local/private-address guard so
		// two local instances on a private network can federate. Empty by default, so a
		// production instance keeps the full SSRF protection below.
		if ($this->isAllowlistedFederationHost(host: $host) === true) {
			return;
		}

		// Reject obvious local hostnames outright.
		if ($host === 'localhost' || str_ends_with($host, '.local') === true
			|| str_ends_with($host, '.localhost') === true
		) {
			throw new InvalidArgumentException('Directory URL host is not allowed');
		}

		// Collect the IPs to check: the literal host if it is already an IP,
		// otherwise every address the host resolves to via DNS.
		$ipsToCheck = [];
		if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
			$ipsToCheck[] = $host;
		}

		if (empty($ipsToCheck) === true) {
			// Strip IPv6 brackets if present.
			$lookupHost = trim($host, '[]');
			if (filter_var($lookupHost, FILTER_VALIDATE_IP) !== false) {
				$ipsToCheck[] = $lookupHost;
			}

			if (empty($ipsToCheck) === true) {
				$records = dns_get_record($lookupHost, (DNS_A | DNS_AAAA));
				if ($records !== false) {
					foreach ($records as $record) {
						if (isset($record['ip']) === true) {
							$ipsToCheck[] = $record['ip'];
						}

						if (isset($record['ipv6']) === true) {
							$ipsToCheck[] = $record['ipv6'];
						}
					}
				}

				// Fallback to gethostbyname for A records when dns_get_record is empty.
				if (empty($ipsToCheck) === true) {
					$resolved = gethostbyname($lookupHost);
					if ($resolved !== $lookupHost && filter_var($resolved, FILTER_VALIDATE_IP) !== false) {
						$ipsToCheck[] = $resolved;
					}
				}
			}//end if
		}//end if

		if (empty($ipsToCheck) === true) {
			throw new InvalidArgumentException('Directory URL host could not be resolved');
		}

		foreach ($ipsToCheck as $ipAddress) {
			if ($this->isBlockedIp(ipAddress: $ipAddress) === true) {
				throw new InvalidArgumentException('Directory URL resolves to a disallowed (internal) address');
			}
		}

	}//end assertSafeOutboundUrl()

	/**
	 * Validate that a URL is safe for outbound use (SSRF guard) without fetching it.
	 *
	 * Public wrapper around {@see assertSafeOutboundUrl()} for callers that need to
	 * validate an attacker-controllable URL before persisting it (e.g.
	 * `ListingsController::create()` validating the `directory` field at
	 * registration time), as opposed to callers that are about to fetch it.
	 *
	 * @param string $url The URL to validate.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the URL or its resolved host is not safe.
	 *
	 * @spec openspec/changes/harden-listings-admin-write-surface/tasks.md#task-1
	 */
	public function validateOutboundUrl(string $url): void {
		$this->assertSafeOutboundUrl(url: $url);

	}//end validateOutboundUrl()

	/**
	 * Determine whether an IP address falls in a blocked range.
	 *
	 * Blocks loopback (127.0.0.0/8, ::1), private RFC1918 ranges, link-local
	 * (169.254.0.0/16 incl. the 169.254.169.254 metadata endpoint, fe80::/10),
	 * unique-local IPv6 (fc00::/7), and other reserved ranges via PHP's range flags.
	 *
	 * @param string $ipAddress The IP address to evaluate.
	 *
	 * @return boolean True when the IP must not be contacted.
	 *
	 * @spec exclude SSRF range check supporting assertSafeOutboundUrl(); security plumbing.
	 */
	private function isBlockedIp(string $ipAddress): bool {
		if (filter_var($ipAddress, FILTER_VALIDATE_IP) === false) {
			// Unparseable address — treat as blocked to fail safe.
			return true;
		}

		// NEVER ALLOWED, whatever the configuration says. These are checked
		// FIRST so the private-range allowance below cannot reach them: the
		// cloud-metadata endpoint is the single most valuable SSRF target on a
		// hosted instance, and it lives in a link-local range that a naive
		// "allow internal" switch would open along with everything else.
		$blockedExact = [
			'169.254.169.254',
			'::1',
			'0.0.0.0',
			'::',
		];
		if (in_array($ipAddress, $blockedExact, true) === true) {
			return true;
		}

		if ($this->isBlockedIpv6Prefix(ipAddress: $ipAddress) === true) {
			return true;
		}

		// FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE returns false for
		// private (RFC1918), loopback, link-local, and reserved ranges (IPv4 + IPv6).
		$isPublic = filter_var(
			value: $ipAddress,
			filter: FILTER_VALIDATE_IP,
			options: (FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
		);

		if ($isPublic !== false) {
			return false;
		}

		// A PRIVATE ADDRESS, AND THE ONLY PLACE THE ALLOWANCE APPLIES.
		//
		// Federating two instances on one Docker network is not an exotic
		// case — it is the ONLY way to exercise directory sync without two
		// publicly-routable hosts, and this guard makes it impossible by
		// default. docker-compose.federation.yml has always described itself
		// as a federation test rig and has never been able to sync a
		// directory, because every peer it can reach is RFC1918.
		//
		// So the allowance exists, and it is deliberately awkward: OFF unless
		// an operator sets it, scoped to private ranges only, never reaching
		// the exact addresses refused above, and logged at WARNING every time
		// it lets something through. An SSRF bypass that is quiet is one
		// nobody remembers is on.
		if ($this->allowsInternalDirectories() === false) {
			return true;
		}

		$this->logger?->warning(
			'[OpenCatalogi] SSRF guard bypassed for a private address — '
			. 'allow_internal_directories is ON. This must never be set on an '
			. 'internet-facing instance.',
			['ip' => $ipAddress]
		);

		return false;
	}//end isBlockedIp()


	/**
	 * Whether this instance may federate with directories on private addresses.
	 *
	 * Defaults to OFF. Enabled with:
	 *
	 *     occ config:app:set opencatalogi allow_internal_directories --value yes
	 *
	 * Intended for a test or demo rig where the peer is another container. The
	 * default is the safe one, so an instance that never touches this setting
	 * keeps exactly the behaviour it had before the setting existed.
	 *
	 * @return boolean True when private-address directories are permitted.
	 *
	 * @spec exclude Configuration read for the SSRF allowance; the behaviour it
	 *       gates is specified on isBlockedIp().
	 */
	private function allowsInternalDirectories(): bool {
		return $this->config->getValueString($this->appName, 'allow_internal_directories', 'no') === 'yes';
	}//end allowsInternalDirectories()

	/**
	 * Determine whether an IPv6 address matches a blocked prefix range.
	 *
	 * Checks fc00::/7 (unique-local) and fe80::/10 (link-local) by inspecting
	 * the lower-cased string prefix. Extracted from isBlockedIp() to reduce
	 * cyclomatic complexity.
	 *
	 * @param string $ipAddress The IP address string to check.
	 *
	 * @return boolean True when the address has a blocked IPv6 prefix.
	 *
	 * @spec exclude SSRF range-check helper extracted from isBlockedIp(); security plumbing.
	 */
	private function isBlockedIpv6Prefix(string $ipAddress): bool {
		$lower = strtolower($ipAddress);
		// Match fc00::/7 (unique-local) and fe80::/10 (link-local) IPv6 prefixes.
		if (str_starts_with($lower, 'fc') === true || str_starts_with($lower, 'fd') === true
			|| str_starts_with($lower, 'fe8') === true || str_starts_with($lower, 'fe9') === true
			|| str_starts_with($lower, 'fea') === true || str_starts_with($lower, 'feb') === true
		) {
			return true;
		}

		return false;
	}//end isBlockedIpv6Prefix()

	/**
	 * Perform a GET request with SSRF-safe, per-hop-validated bounded redirects.
	 *
	 * Guzzle's automatic redirect following is disabled so that each redirect target
	 * can be re-validated by {@see self::assertSafeOutboundUrl()} before it is fetched,
	 * preventing a public URL from redirecting into an internal address.
	 *
	 * @param string $url The (already validated) initial URL to fetch.
	 *
	 * @return \Psr\Http\Message\ResponseInterface The final HTTP response.
	 *
	 * @throws InvalidArgumentException When a redirect points to a disallowed address
	 *                                  or the redirect limit is exceeded.
	 * @throws GuzzleException On transport errors.
	 *
	 * @spec exclude SSRF-safe fetch helper with bounded, per-hop-validated redirects;
	 *       security plumbing wrapping the existing Guzzle client.
	 */
	private function safeGet(string $url): \Psr\Http\Message\ResponseInterface {
		$maxRedirects = 5;
		$current = $url;

		for ($hop = 0; $hop <= $maxRedirects; $hop++) {
			$response = $this->client->get(
				$current,
				[
					RequestOptions::ALLOW_REDIRECTS => false,
					RequestOptions::TIMEOUT => 10,
					RequestOptions::CONNECT_TIMEOUT => 5,
				]
			);

			$status = $response->getStatusCode();
			if ($status < 300 || $status >= 400) {
				return $response;
			}

			// Redirect: resolve, validate, and follow manually.
			$location = $response->getHeaderLine('Location');
			if ($location === '') {
				return $response;
			}

			// Resolve relative redirects against the current URL.
			if (parse_url($location, PHP_URL_HOST) === null) {
				$base = parse_url($current);
				$scheme = ($base['scheme'] ?? 'https');
				$hostPart = ($base['host'] ?? '');
				if (isset($base['port']) === true) {
					$hostPart .= ':' . $base['port'];
				}

				$path = $location;
				if (str_starts_with($location, '/') === false) {
					$path = '/' . $location;
				}

				$location = $scheme . '://' . $hostPart . $path;
			}

			$this->assertSafeOutboundUrl(url: $location);
			$current = $location;
		}//end for

		throw new InvalidArgumentException('Too many redirects while fetching directory');
	}//end safeGet()

	/**
	 * Check if a URL is considered local
	 *
	 * Determines if a URL represents a local address that should not be
	 * used for broadcasting (localhost, 127.0.0.1, local domain, etc.).
	 *
	 * @param string $url The URL to check
	 *
	 * @return boolean True if the URL is local, false otherwise
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	private function isLocalUrl(string $url): bool {
		$parsedUrl = parse_url($url);

		if ($parsedUrl === false || isset($parsedUrl['host']) === false) {
			return true;
			// Invalid URL is considered local.
		}

		$host = strtolower($parsedUrl['host']);

		// Dev-only: allowlisted hosts are treated as remote so their synced listings
		// survive the local-URL filter. Empty allowlist by default keeps production behavior.
		if ($this->isAllowlistedFederationHost(host: $host) === true) {
			return false;
		}

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
			$isPrivateIp = filter_var(
				value: $host,
				filter: FILTER_VALIDATE_IP,
				options: (FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
			);
			if ($isPrivateIp === false) {
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
	 * @param string $uuid The UUID of the local object to find uses for
	 * @param array $guzzleConfig Optional Guzzle configuration for HTTP requests
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
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	public function getUsed(string $uuid, array $guzzleConfig = []): array {
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
			RequestOptions::TIMEOUT => 5,
			RequestOptions::CONNECT_TIMEOUT => 2,
			RequestOptions::HEADERS => [
				'Accept' => 'application/json',
				'User-Agent' => 'OpenCatalogi-DirectoryService/1.0',
			],
			RequestOptions::HTTP_ERRORS => false,
		];

		$finalGuzzleConfig = array_merge($defaultGuzzleConfig, $guzzleConfig);
		$queryParams = ($finalGuzzleConfig['query_params'] ?? []);
		$queryParams['_aggregate'] = 'false';
		// Prevent circular aggregation.
		$queryParams['_extend'] = [
			'@self.schema',
			'@self.register',
		];
		// Add self-extension.
		$client = new Client($finalGuzzleConfig);
		$promises = [];
		$urlToDirectoryMap = [];

		// Create promises for each directory.
		foreach ($directories as $index => $directoryUrl) {
			// Skip our own directory.
			if ($directoryUrl === $ourDirectoryUrl) {
				continue;
			}

			// Skip local/unsafe URLs — assertSafeOutboundUrl performs DNS resolution.
			try {
				$this->assertSafeOutboundUrl(url: $directoryUrl);
			} catch (InvalidArgumentException $e) {
				continue;
			}

			// Build the /used endpoint URL on the peer's federation endpoint, which
			// needs no catalogue slug (REQ-FOR-002).
			$baseUrl = self::peerFederationPublicationsUrl(url: $directoryUrl);
			$usedUrl = $baseUrl . '/' . urlencode($uuid) . '/used';

			if (empty($queryParams) === false) {
				$usedUrl .= '?' . http_build_query($queryParams);
			}

			// Store mapping for later source tracking.
			$usedParsedHost = parse_url($directoryUrl, PHP_URL_HOST);
			if (empty($usedParsedHost) === true) {
				$usedParsedHost = $directoryUrl;
			}

			$urlToDirectoryMap[count($promises)] = [
				'url' => $usedUrl,
				'directoryUrl' => $directoryUrl,
				'name' => $usedParsedHost,
			];

			$promises[] = new Promise(
				function ($resolve) use ($client, $usedUrl) {
					$failResult = ['success' => false, 'results' => []];
					try {
						// SSRF hardening (SB1 / wave-12): disable automatic redirect following
						// to prevent a federated peer from pivoting via 302 to internal services.
						$response = $client->get($usedUrl, [RequestOptions::ALLOW_REDIRECTS => false]);

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
		$seenIds = [];
		$sources = [];

		foreach ($allResults as $index => $result) {
			$directoryInfo = $urlToDirectoryMap[$index];

			if ($result['success'] === true && empty($result['results']) === false) {
				$sources[$directoryInfo['name']] = $directoryInfo['url'];

				foreach ($result['results'] as $item) {
					$itemId = ($item['id'] ?? $item['uuid'] ?? uniqid());
					if (isset($seenIds[$itemId]) === false) {
						$combinedResults[] = $item;
						$seenIds[$itemId] = true;
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
	 * @param array $guzzleConfig Optional Guzzle configuration for HTTP requests
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
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	public function getPublication(string $publicationId, array $guzzleConfig = []): ?array {
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
			RequestOptions::TIMEOUT => 5,
			RequestOptions::CONNECT_TIMEOUT => 2,
			RequestOptions::HEADERS => [
				'Accept' => 'application/json',
				'User-Agent' => 'OpenCatalogi-DirectoryService/1.0',
			],
			RequestOptions::HTTP_ERRORS => false,
		];

		$finalGuzzleConfig = array_merge($defaultGuzzleConfig, $guzzleConfig);
		$queryParams = ($finalGuzzleConfig['query_params'] ?? []);
		$queryParams['_aggregate'] = 'false';
		// Prevent circular aggregation.
		$queryParams['_extend'] = [
			'@self.schema',
			'@self.register',
		];
		// Add self-extension.
		$client = new Client($finalGuzzleConfig);
		$promises = [];
		$urlToDirectoryMap = [];

		// Create promises for each directory.
		foreach ($directories as $index => $directoryUrl) {
			// Skip our own directory.
			if ($directoryUrl === $ourDirectoryUrl) {
				continue;
			}

			// Skip local/unsafe URLs — assertSafeOutboundUrl performs DNS resolution.
			try {
				$this->assertSafeOutboundUrl(url: $directoryUrl);
			} catch (InvalidArgumentException $e) {
				continue;
			}

			// Build the publication endpoint URL on the peer's federation endpoint.
			// `/api/publications/{id}` is the catalogue-slug route and 404s once
			// the peer renamed its seed catalogue (REQ-FOR-002, issue #1648).
			$baseUrl = self::peerFederationPublicationsUrl(url: $directoryUrl);
			$publicationUrl = $baseUrl . '/' . urlencode($publicationId);

			if (empty($queryParams) === false) {
				$publicationUrl .= '?' . http_build_query($queryParams);
			}

			// Store mapping for later source tracking.
			$pubParsedHost = parse_url($directoryUrl, PHP_URL_HOST);
			if (empty($pubParsedHost) === true) {
				$pubParsedHost = $directoryUrl;
			}

			$urlToDirectoryMap[count($promises)] = [
				'url' => $publicationUrl,
				'directoryUrl' => $directoryUrl,
				'name' => $pubParsedHost,
			];

			$promises[] = new Promise(
				function ($resolve) use ($client, $publicationUrl) {
					try {
						// SSRF hardening (SB1 / wave-12): disable automatic redirect following
						// to prevent a federated peer from pivoting via 302 to internal services.
						$response = $client->get($publicationUrl, [RequestOptions::ALLOW_REDIRECTS => false]);

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
	 * The peer's `/api/federation/publications` URL for a listing URL of any shape.
	 *
	 * A listing's `publications` URL is the federation endpoint on current peers,
	 * but older or hand-registered listings carry the directory URL or the
	 * catalogue-slug URL `/api/publications`. Everything from the first `/api/`
	 * on is replaced, so a peer lookup never depends on a catalogue slug that an
	 * administrator can rename or delete. A URL without `/api/` is kept as is.
	 *
	 * @param string $url The listing's publications (or directory) URL
	 *
	 * @return string The peer's federation publications URL, without a trailing slash
	 *
	 * @spec openspec/changes/federation-open-remote-publication/specs/federation/spec.md#REQ-FOR-002
	 */
	private static function peerFederationPublicationsUrl(string $url): string {
		$base = rtrim((string) strtok($url, '?#'), '/');
		$pos  = strpos($base.'/', '/api/');
		if ($pos === false) {
			return $base;
		}

		return substr($base, 0, $pos).'/api/federation/publications';

	}//end peerFederationPublicationsUrl()

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
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	public function getDirectory(array $requestParams = []): array {
		// Check if OpenRegister service is available.
		if (in_array('openregister', $this->appManager->getInstalledApps()) === false) {
			throw new RuntimeException('OpenRegister service is not available.');
		}

		// Get ObjectService from container.
		$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

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
			$baseConfig['limit'] = (int)$requestParams['limit'];
		}

		if (isset($requestParams['offset']) === true) {
			$baseConfig['offset'] = (int)$requestParams['offset'];
		}

		// Fetch discovered listings.
		// Read the scope keys HERE rather than at the top of the method: the guard
		// on the very next line is what makes them safe, and a read separated from
		// its guard by twenty lines of unrelated setup reads as unguarded — to a
		// reviewer and to hydra gate-50 alike. Same for the catalog pair below.
		$listingSchema = $this->config->getValueString($this->appName, 'listing_schema', '');
		$listingRegister = $this->config->getValueString($this->appName, 'listing_register', '');
		if (empty($listingSchema) === false && empty($listingRegister) === false) {
			$query = [
				'@self' => [
					'schema' => $listingSchema,
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
				// ADR-002: each tenant queries only its own listings; multitenancy must stay enabled.
				$listingResult = $objectService->searchObjects($query);

				// Get our directory URL to identify locally-created listings.
				$ourDirectoryUrl = $this->urlGenerator->getAbsoluteURL(
					$this->urlGenerator->linkToRoute('opencatalogi.directory.index')
				);

				// BFS-discovery opt-in: sync-code passes `?include-federated=1` so peers
				// can transitively learn about each other's known directories. Public
				// consumers (search UI, browsers) get the default filtered view — only
				// catalogs that originate from THIS instance — to preserve the historical
				// "no cross-broadcast" contract.
				$includeFederated = filter_var(
					($requestParams['include-federated'] ?? false),
					FILTER_VALIDATE_BOOLEAN
				);

				$listings = [];
				foreach ($listingResult as $object) {
					$listingData = $object;
					if ($object instanceof \OCP\AppFramework\Db\Entity) {
						$listingData = $object->jsonSerialize();
					}

					$objectData = ($listingData['object'] ?? $listingData);
					$listingDir = ($objectData['directory'] ?? '');

					// Default view: skip listings synced from OTHER directories (their
					// `directory` field points at the peer's own /api/directory URL, not
					// ours) so we don't re-broadcast them. BFS-discovery callers opt in
					// via `include-federated=1` to receive the full transitive set.
					if ($includeFederated === false
						&& empty($listingDir) === false
						&& $listingDir !== $ourDirectoryUrl
					) {
						continue;
					}

					$listings[] = $this->filterListingProperties(listing: $listingData);
				}//end foreach

				$allResults = array_merge($allResults, $listings);
			} catch (\Exception $e) {
				// Removed redundant logging.
			}//end try
		}//end if

		// Fetch local catalogs and convert them to listing format.
		$catalogSchema = $this->config->getValueString($this->appName, 'catalog_schema', 'catalog');
		$catalogRegister = $this->config->getValueString($this->appName, 'catalog_register', '');
		if (empty($catalogSchema) === false && empty($catalogRegister) === false) {
			$query = [
				'@self' => [
					'schema' => $catalogSchema,
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
				// ADR-002: each tenant queries only its own catalogs; multitenancy must stay enabled.
				$catalogResult = $objectService->searchObjects($query);

				// Convert catalog objects to listing format and expand schemas.
				$catalogsAsListings = array_map(
					function ($catalogObject) {
						$listing = $this->convertCatalogToListing(catalogObject: $catalogObject);
						return $this->processSchemaExpansion(data: $listing);
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
			'total' => $total,
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
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	private function convertCatalogToListing($catalogObject): array {
		// Extract catalog data.
		$catalogData = $catalogObject;
		if ($catalogObject instanceof \OCP\AppFramework\Db\Entity) {
			$catalogData = $catalogObject->jsonSerialize();
		}

		$catalog = ($catalogData['object'] ?? $catalogData);

		// Get our directory URL for the listing.
		$directoryUrl = $this->urlGenerator->getAbsoluteURL(
			$this->urlGenerator->linkToRoute('opencatalogi.directory.index')
		);

		// Get our search and publications URLs.
		// Use federation endpoint (not publications.index which requires catalogSlug).
		$searchUrl = $this->urlGenerator->getAbsoluteURL(
			$this->urlGenerator->linkToRoute('opencatalogi.search.index')
		);
		$publicationsUrl = $this->urlGenerator->getAbsoluteURL(
			$this->urlGenerator->linkToRoute('opencatalogi.federation.publications')
		);

		// Create listing object from catalog - only core API fields.
		$catalogId = ($catalog['id'] ?? $catalogData['id'] ?? '');
		$listing = [
			// Required fields for listing.
			'id' => $catalogId,
			'catalog' => $catalogId,
			'catalogusId' => $catalogId,
			'title' => ($catalog['title'] ?? 'Unknown Catalog'),
			'summary' => ($catalog['summary'] ?? $catalog['description'] ?? 'Local catalog'),
			'status' => ($catalog['status'] ?? 'development'),

			// Optional fields from catalog.
			'description' => $catalog['description'] ?? null,
			'organization' => $catalog['organization'] ?? null,
			'schemas' => ($catalog['schemas'] ?? []),

			// Directory-specific fields.
			'directory' => $directoryUrl,
			'search' => $searchUrl,
			'publications' => $publicationsUrl,
			'version' => $this->appManager->getAppInfo('opencatalogi')['version'],

			// Publication date from catalog, default to now for public visibility.
			'published' => ($catalog['published'] ?? (new DateTime())->format('c')),
		];

		// Federation discovery (DCAT-009): advertise the per-catalog DCAT harvest endpoint
		// when the catalog is DCAT-enabled, so remote instances and aggregators learn where
		// to harvest. Disabled catalogs carry no dcatEndpoint. Extends the existing directory
		// machinery with one field — no new broadcast channel or cron (FED-OR-001/002).
		$hasDcat = filter_var(($catalog['hasDcat'] ?? false), FILTER_VALIDATE_BOOLEAN);
		$catalogSlug = ($catalog['slug'] ?? '');
		if ($hasDcat === true && $catalogSlug !== '') {
			$base = rtrim($this->urlGenerator->getBaseUrl(), '/');
			$listing['dcatEndpoint'] = "$base/apps/opencatalogi/api/catalogs/$catalogSlug/dcat";
		}

		// Federation discovery (OOAPI-009): advertise the per-catalog OOAPI 5.0 base
		// endpoint when the catalog has OOAPI publication enabled, so remote instances
		// and the national directory discover where to reach an institution's course
		// catalog. Rides the same listing-projection helper as dcatEndpoint above — no
		// new broadcast channel or cron (FED-OR-001/002).
		$hasOoapi = filter_var(($catalog['hasOoapi'] ?? false), FILTER_VALIDATE_BOOLEAN);
		if ($hasOoapi === true && $catalogSlug !== '') {
			$base = rtrim($this->urlGenerator->getBaseUrl(), '/');
			$listing['ooapiEndpoint'] = "$base/apps/opencatalogi/api/catalogs/$catalogSlug/ooapi/v5";
		}

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
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	private function filterListingProperties(array $listing): array {
		// Extract the actual object data.
		$objectData = ($listing['object'] ?? $listing);

		// List of properties to remove for external API.
		$propertiesToRemove = [
			// Internal/system properties.
			'status',
			'statusCode',
			'lastSync',
			'lastSuccessAt',
			'lastError',
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
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	public function convertCatalogiToListings(array $catalogs): array {
		return array_map(
			function ($catalogObject) {
				$listing = $this->convertCatalogToListing(catalogObject: $catalogObject);
				return $this->processSchemaExpansion(data: $listing);
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
	private function expandSchemas(array $schemaIds): array {
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
	private function processSchemaExpansion(array $data): array {
		// Extract the actual object data.
		$objectData = ($data['object'] ?? $data);

		// Expand schemas if they exist and are an array of IDs.
		if (isset($objectData['schemas']) === true && is_array($objectData['schemas']) === true) {
			$objectData['schemas'] = $this->expandSchemas(schemaIds: $objectData['schemas']);
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
	 * @param array $newFacets The new facets to merge in
	 *
	 * @return array The merged facets
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	private function aggregateFacets(array $existingFacets, array $newFacets): array {
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
						if (is_array($a) === false
							|| is_array($b) === false
							|| isset($a['_id']) === false
							|| isset($b['_id']) === false
						) {
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
			}//end if
		}//end foreach

		return $mergedFacets;
	}//end aggregateFacets()
}//end class
