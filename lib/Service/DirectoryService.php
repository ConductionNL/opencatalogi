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
use OCA\OpenCatalogi\Contract\AppIdentity;
use OCA\OpenCatalogi\Contract\DirectoryServiceInterface;
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
use Symfony\Component\Uid\Uuid;

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
class DirectoryService implements DirectoryServiceInterface {

	/**
	 * The name of the app.
	 *
	 * @var string
	 */
	private readonly string $appName;

	/**
	 * The largest outbound response body (directory or peer answer) read into memory.
	 *
	 * A peer or a URL handed to the anonymous sync endpoint could otherwise answer
	 * with a body larger than memory_limit. 10 MiB holds thousands of listings.
	 */
	private const MAX_RESPONSE_BYTES = 10485760;

	/**
	 * The most peers one federated lookup calls.
	 *
	 * Public endpoints (federation listing, used, publication) fan out to every
	 * known directory; the cap keeps one anonymous request from holding a worker
	 * for an unbounded number of outbound calls.
	 */
	private const MAX_PEERS_PER_LOOKUP = 25;

	/**
	 * The longest a federated lookup waits for its peers, in seconds.
	 *
	 * The peers are called concurrently, so this is also the bound on the whole
	 * fan-out of one request.
	 */
	private const MAX_PEER_TIMEOUT = 10.0;

	/**
	 * The longest a federated lookup waits for a peer connection, in seconds.
	 */
	private const MAX_PEER_CONNECT_TIMEOUT = 5.0;

	/**
	 * The longest a federated lookup spends resolving and checking peer addresses, in seconds.
	 *
	 * The SSRF guard resolves every peer name before the concurrent phase, and PHP's
	 * DNS functions take no timeout, so the lookup stops picking peers once this
	 * budget is spent (worst case: the budget plus one resolver timeout).
	 */
	private const MAX_PEER_RESOLVE_SECONDS = 5.0;

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
	 * How far in the future a peer timestamp may lie and still count as "newer", in seconds.
	 *
	 * Covers clock skew between instances; a timestamp beyond it would otherwise
	 * win every later comparison for good.
	 */
	private const MAX_TIMESTAMP_SKEW_SECONDS = 300;

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
	 * The app-config key holding the directories an administrator added (JSON list).
	 *
	 * @var string
	 */
	private const CONFIG_TRUSTED_DIRECTORIES = 'trusted_directory_urls';

	/**
	 * Runs a listing write with the rights the cron sync was given, or null for the caller's own.
	 *
	 * Set by doCronSync() for its own duration only.
	 *
	 * @var (\Closure(callable): mixed)|null
	 */
	private ?\Closure $listingWriteContext = null;

	/**
	 * Test seam for the cURL availability check behind the address pin.
	 *
	 * When null, {@see assertPinSupported()} asks PHP. Only the unit test assigns a
	 * closure, through reflection, to simulate a host without ext-curl.
	 *
	 * @var (\Closure(): bool)|null
	 *
	 * @phpstan-ignore property.unusedType (only the unit test assigns it, through reflection)
	 */
	private ?\Closure $curlAvailable = null;

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
			AppIdentity::DEFAULT_DIRECTORY_URL
		);

	}//end getDefaultDirectoryUrl()

	/**
	 * Execute synchronization during cron job (asynchronous)
	 *
	 * Performs scheduled synchronization of the directories an administrator added
	 * (plus the default directory) asynchronously using React PHP promises.
	 *
	 * Only those directories are crawled (aud-f1). A directory URL learned from peer
	 * data (the `directory` of a synced listing) is stored and still guards the
	 * cross-directory check, but is not crawled here: otherwise any peer could add
	 * crawl targets, and with the cron job's system rights get their listings
	 * written. An administrator trusts a directory by adding it
	 * ({@see trustDirectory()}), or syncs one listing's directory by hand.
	 *
	 * @param (\Closure(callable): mixed)|null $listingWriteContext Runs the listing
	 *        register reads and writes of the sync (and nothing else) with elevated
	 *        rights, e.g. OpenRegister's runAsSystem() for the cron job; null keeps
	 *        the caller's own rights.
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
	public function doCronSync(?\Closure $listingWriteContext = null): array {
		$this->listingWriteContext = $listingWriteContext;
		try {
			return $this->syncTrustedDirectories();
		} finally {
			$this->listingWriteContext = null;
		}

	}//end doCronSync()

	/**
	 * Sync every trusted directory and the default directory, and report the outcome.
	 *
	 * @return array<string, mixed> The synchronization results.
	 *
	 * @psalm-suppress InvalidArgument React Promise resolve callbacks receive arrays
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	private function syncTrustedDirectories(): array {
		$this->retryPendingTrust();
		$defaultDirectory = $this->getDefaultDirectoryUrl();

		// Crawl set: admin-added directories plus the default one, never URLs from peer data.
		$uniqueDirectoryUrls = [];
		foreach ($this->getTrustedDirectoryUrls() as $trustedUrl) {
			if ($this->isSelfInstance(url: $trustedUrl) === false) {
				$uniqueDirectoryUrls[] = $trustedUrl;
			}
		}

		if (in_array($defaultDirectory, $uniqueDirectoryUrls, true) === false) {
			$uniqueDirectoryUrls[] = $defaultDirectory;
		}

		// Cross-directory check set: the directories crawled in this run (pp-f1). A
		// listing that names one of them is synced from that directory itself; a
		// listing for a directory that is not crawled is accepted from the trusted
		// directory that relays it, or it would never be updated again. A relayed
		// listing still cannot take over another listing's UUID (newListingUuid()).
		// Known limitation, tracked separately: any trusted relay can update the
		// listings of a directory this instance does not crawl.
		$this->uniqueDirectories = $uniqueDirectoryUrls;

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
	}//end syncTrustedDirectories()

	/**
	 * Record a directory an administrator added, so the cron sync crawls it (aud-f1).
	 *
	 * Only admin entry points call this (adding a directory, creating a listing
	 * with a directory). The URL must pass the SSRF guard.
	 *
	 * @param string $directoryUrl The directory URL.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the URL is malformed or unsafe.
	 *
	 * @spec openspec/specs/dashboard/spec.md#requirement-synchronize-all-directories-via-cron-every-hour-dir-004
	 */
	public function trustDirectory(string $directoryUrl): void {
		$this->assertSyncableDirectoryUrl(directoryUrl: $directoryUrl);
		$this->assertSafeOutboundUrl(url: $directoryUrl);

		$trusted = $this->getTrustedDirectoryUrls();
		if (in_array($directoryUrl, $trusted, true) === true) {
			return;
		}

		$trusted[] = $directoryUrl;
		$this->config->setValueString($this->appName, self::CONFIG_TRUSTED_DIRECTORIES, (string)json_encode(array_values($trusted)));

	}//end trustDirectory()

	/**
	 * Retry, once, the seeded directories that failed their checks at upgrade time.
	 *
	 * The seed (SeedTrustedDirectories) parks a directory whose name did not
	 * resolve, or that failed the SSRF check, in the pending list instead of
	 * dropping it. The next sync gives each one more try through trustDirectory()
	 * (same checks); the list is cleared first, so each entry is retried once.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/dashboard/spec.md#requirement-synchronize-all-directories-via-cron-every-hour-dir-004
	 */
	private function retryPendingTrust(): void {
		$pending = json_decode($this->config->getValueString($this->appName, self::CONFIG_PENDING_TRUST, '[]'), true);
		if (is_array($pending) === false || $pending === []) {
			return;
		}

		$this->config->setValueString($this->appName, self::CONFIG_PENDING_TRUST, '[]');
		foreach ($pending as $directoryUrl) {
			if (is_string($directoryUrl) === false) {
				continue;
			}

			try {
				$this->trustDirectory(directoryUrl: $directoryUrl);
				$this->logger?->info('[DirectoryService] Pending directory trusted on retry', ['directory' => $directoryUrl]);
			} catch (\Throwable $e) {
				$this->logger?->warning(
					'[DirectoryService] Pending directory failed its checks again and is not crawled; an admin can add it',
					['directory' => $directoryUrl, 'error' => $e->getMessage()]
				);
			}
		}

	}//end retryPendingTrust()

	/**
	 * Stop crawling a directory, on an explicit administrator decision.
	 *
	 * Not called when a listing is deleted: a directory has a listing per catalog,
	 * and deleting one must not stop the crawl of the others. No UI action calls it
	 * yet; administrators edit the `trusted_directory_urls` app setting.
	 *
	 * @param string $directoryUrl The directory URL.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/dashboard/spec.md#requirement-synchronize-all-directories-via-cron-every-hour-dir-004
	 */
	public function untrustDirectory(string $directoryUrl): void {
		$trusted = $this->getTrustedDirectoryUrls();
		$kept    = array_values(array_filter($trusted, static fn (string $url): bool => $url !== $directoryUrl));
		if (count($kept) === count($trusted)) {
			return;
		}

		$this->config->setValueString($this->appName, self::CONFIG_TRUSTED_DIRECTORIES, (string)json_encode($kept));

	}//end untrustDirectory()

	/**
	 * The directories an administrator added; an unreadable value counts as none.
	 *
	 * @return array<string> The trusted directory URLs.
	 *
	 * @spec openspec/specs/dashboard/spec.md#requirement-synchronize-all-directories-via-cron-every-hour-dir-004
	 */
	private function getTrustedDirectoryUrls(): array {
		$decoded = json_decode($this->config->getValueString($this->appName, self::CONFIG_TRUSTED_DIRECTORIES, '[]'), true);
		if (is_array($decoded) === false) {
			return [];
		}

		return array_values(
			array_filter(
				$decoded,
				static fn ($url): bool => is_string($url) === true && filter_var($url, FILTER_VALIDATE_URL) !== false
			)
		);

	}//end getTrustedDirectoryUrls()

	/**
	 * Run a listing register read or write with the rights doCronSync() was given.
	 *
	 * Only listing register operations of a sync go through here: the lookup and
	 * save, and recording a sync failure on the stored listings. Fetching and
	 * parsing peer data, broadcasts and reporting keep the caller's own rights
	 * (the workload identity is scoped to what needs it).
	 *
	 * @param callable $operation The listing register operation.
	 *
	 * @return mixed What the operation returns.
	 *
	 * @spec exclude Scoping helper for the cron sync's system context; no domain behaviour.
	 */
	private function withListingWriteRights(callable $operation): mixed {
		if ($this->listingWriteContext === null) {
			return $operation();
		}

		return ($this->listingWriteContext)($operation);

	}//end withListingWriteRights()

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
	 * method returns DIRECTORY URLs for the sync flow's cross-directory check
	 * (doCronSync, syncDirectory). They are not the cron crawl set: the cron sync
	 * only crawls admin-added directories and the default one (see trustDirectory()).
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

		return $this->directoryUrlsOf(listings: $listings);
	}//end getKnownDirectoryUrls()

	/**
	 * The known directory URLs across every organisation, or an exception when they cannot be read.
	 *
	 * Unlike getKnownDirectoryUrls(), which answers [] on any failure, this tells a
	 * failed lookup from an empty one, for the one-time seed of the trusted
	 * directories (SeedTrustedDirectories). Run it inside OpenRegister's system
	 * context so the organisation filter does not apply in a web-started upgrade.
	 *
	 * @return array<string> Unique peer directory URLs.
	 *
	 * @throws RuntimeException When OpenRegister is unavailable.
	 * @throws \Throwable      When the listing search fails.
	 *
	 * @spec openspec/specs/dashboard/spec.md#requirement-synchronize-all-directories-via-cron-every-hour-dir-004
	 */
	public function readAllKnownDirectoryUrls(): array {
		if (in_array('openregister', $this->appManager->getInstalledApps()) === false) {
			throw new RuntimeException('OpenRegister service is not available.');
		}

		$listingSchema = $this->config->getValueString($this->appName, 'listing_schema', '');
		$listingRegister = $this->config->getValueString($this->appName, 'listing_register', '');
		// No listing register means no stored listings, so the cron crawled nothing
		// besides the default directory (e.g. a fresh install): nothing to grandfather.
		if (empty($listingSchema) === true || empty($listingRegister) === true) {
			return [];
		}

		// The ADR-055 marker must stay lower-case and on the bypass line itself.
		// phpcs:disable Squiz.Commenting.InlineComment.NotCapital
		$listings = $this->container->get('OCA\OpenRegister\Service\ObjectService')->searchObjects(
			query: [
				'@self' => [
					'register' => $listingRegister,
					'schema' => $listingSchema,
				],
			],
			_rbac: false, // rbac-bypass: one-time upgrade seed reads only the directory URLs of every stored listing; nothing is returned to a caller.
			_multitenancy: false // rbac-bypass: same seed, across organisations, so it sees what the cron crawled.
		);
		// phpcs:enable Squiz.Commenting.InlineComment.NotCapital

		return $this->directoryUrlsOf(listings: $listings);
	}//end readAllKnownDirectoryUrls()

	/**
	 * The unique `/api/directory` URLs the listings name, without this instance.
	 *
	 * @param iterable $listings The stored listing entities.
	 *
	 * @return array<string> Unique peer directory URLs.
	 *
	 * @spec openspec/specs/dashboard/spec.md#requirement-synchronize-all-directories-via-cron-every-hour-dir-004
	 */
	private function directoryUrlsOf(iterable $listings): array {
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
	}//end directoryUrlsOf()

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
		$directoryData = json_decode($this->readCappedBody(response: $response), true);
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
			// The lookup and the save run with the sync's listing rights (see doCronSync());
			// the lookup too, so the create-or-update decision sees what the save sees.
			$existingListings = $this->withListingWriteRights(
				operation: fn () => $this->findExistingListings(target: $target, listingData: $listingData)
			);
			$stored = $this->withListingWriteRights(
				operation: fn () => $this->storeListing(listingData: $listingData, existingListings: $existingListings, target: $target)
			);

			return array_merge($result, $stored);
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
			// OpenRegister falls back to the object's `id` (or `@self.id`) when no uuid is
			// passed, so a refused peer UUID must not stay in the object either: the
			// listing gets a fresh UUID, passed explicitly and written as the object's `id`.
			$uuid = $this->newListingUuid(target: $target);
			if ($uuid === null) {
				$uuid = Uuid::v4()->toRfc4122();
				$listingData['id'] = $uuid;
			}

			$this->saveListing(target: $target, listingData: $listingData, uuid: $uuid);
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
	 * The UUID to create a synced listing under: the peer's, unless it is already taken.
	 *
	 * No stored listing matched (catalogusId, directory), so this is a create. The
	 * UUID comes from the peer, and listing UUIDs are public (GET /api/directory):
	 * a peer that sends the UUID of another directory's listing would otherwise turn
	 * this create into an overwrite of that listing (oc-f5). A UUID that already
	 * exists, or that cannot be checked, is not used; storeListing() then generates one.
	 *
	 * @param array $target The ObjectService, listing register/schema and the peer's uuid.
	 *
	 * @return string|null The peer's UUID when it is free, otherwise null (the caller then generates one).
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	private function newListingUuid(array $target): ?string {
		$uuid = ($target['uuid'] ?? null);
		if (is_string($uuid) === false || $uuid === '') {
			return null;
		}

		try {
			// The ADR-055 marker must stay lower-case and on the bypass line itself.
			// phpcs:disable Squiz.Commenting.InlineComment.NotCapital
			$existing = $target['objectService']->find(
				id: $uuid,
				_extend: [],
				register: $target['register'],
				schema: $target['schema'],
				_rbac: false, // rbac-bypass: existence check only, nothing is returned to the caller; a listing the caller cannot read must still count as taken.
				_multitenancy: false // rbac-bypass: same existence check, across organisations.
			);
			// phpcs:enable Squiz.Commenting.InlineComment.NotCapital
		} catch (DoesNotExistException $e) {
			return $uuid;
		} catch (\Throwable $e) {
			// Fail closed: when it cannot be told whether the UUID is free, do not reuse it.
			return null;
		}

		if ($existing === null) {
			return $uuid;
		}

		$this->logger?->warning(
			'[DirectoryService] Synced listing names a UUID that belongs to another listing; created under a new UUID',
			['uuid' => $uuid]
		);

		return null;

	}//end newListingUuid()

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

			// A listing write like the save it records, so it runs with the same rights (pp-f3).
			$this->withListingWriteRights(
				operation: fn () => $this->saveListing(target: $target, listingData: $errorData, uuid: $existingListingData['id'])
			);
		} catch (\Exception $updateException) {
			// A failure to record the error must not mask the sync failure.
		}
	}//end recordListingError()

	/**
	 * Get publications from all available federated catalogs
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
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	public function getPublications(array $guzzleConfig = [], bool $includeDefault = false): array {
		$directories = $this->getUniqueDirectories(availableOnly: true, defaultOnly: $includeDefault);
		if (empty($directories) === true) {
			return [
				'results' => [],
				'sources' => [],
			];
		}

		// Force catalog enrichment on the peer response so the client-side
		// @result-click handler in FederationSearch can build a peer deep-link
		// (`<peer>/#/publications/<catalogSlug>/<id>`). Without this, the
		// peer's _aggregate=false path takes the ultra-fast branch that skips
		// catalog stubs and returns @self.catalogs=null — click-through then
		// silently falls back to the peer app-root (WOO-522 / WOO-533).
		// The directory URLs from getUniqueDirectories() are already publications URLs.
		$lookup = [
			'guzzleConfig' => $guzzleConfig,
			'query' => $this->peerQuery(guzzleConfig: $guzzleConfig, extraQuery: ['_include_catalogs' => 'true']),
			'endpointFor' => static fn (string $directoryUrl): string => $directoryUrl,
			'parse' => $this->parsePeerPublications(...),
		];
		$targets = $this->peerTargets(directories: $directories, lookup: $lookup);
		$answers = $this->fetchFromPeers(targets: $targets, lookup: $lookup);

		return $this->mergePeerPublications(targets: $targets, answers: $answers);

	}//end getPublications()

	/**
	 * Read a peer's publications answer, which is either a search result or a bare list.
	 *
	 * @param mixed $data The decoded peer response
	 *
	 * @return array|null The results, facets and total, or null when the answer is unusable
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	private function parsePeerPublications(mixed $data): ?array {
		if (isset($data['results']) === true && is_array($data['results']) === true) {
			return [
				'results' => $data['results'],
				'facets' => ($data['facets'] ?? []),
				'total' => $data['total'],
			];
		}

		if (is_array($data) === true) {
			return [
				'results' => $data,
				'facets' => [],
				'total' => $data['total'],
			];
		}

		return null;

	}//end parsePeerPublications()

	/**
	 * Combine the peers' publications: deduplicate by id, tag each with its directory, sum totals and merge facets.
	 *
	 * @param array $targets The peer requests, as built by peerTargets()
	 * @param array $answers The parsed answers, indexed like $targets
	 *
	 * @return array The combined results, sources, facets and total
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	private function mergePeerPublications(array $targets, array $answers): array {
		$combined = [
			'results' => [],
			'sources' => [],
			'facets' => [],
			'total' => 0,
		];
		$seenIds  = [];

		foreach ($answers as $index => $answer) {
			if ($answer === null || empty($answer['results']) === true) {
				continue;
			}

			$target = $targets[$index];
			$combined['sources'][$target['name']] = $target['url'];
			$combined['total'] += $answer['total'];

			foreach ($answer['results'] as $item) {
				$itemId = ($item['id'] ?? $item['uuid'] ?? uniqid());
				if (isset($seenIds[$itemId]) === true) {
					continue;
				}

				$combined['results'][] = $this->tagWithDirectory(item: $item, directoryName: $target['name']);
				$seenIds[$itemId]      = true;
			}

			if (empty($answer['facets']) === false) {
				$combined['facets'] = $this->aggregateFacets(existingFacets: $combined['facets'], newFacets: $answer['facets']);
			}
		}//end foreach

		return $combined;

	}//end mergePeerPublications()

	/**
	 * Record on a federated publication which directory it came from, for faceting.
	 *
	 * @param array  $item          The publication
	 * @param string $directoryName The host name of the peer directory
	 *
	 * @return array The publication with `@self.directory` set
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	private function tagWithDirectory(array $item, string $directoryName): array {
		if (isset($item['@self']) === false || is_array($item['@self']) === false) {
			$item['@self'] = [];
		}

		$item['@self']['directory'] = $directoryName;
		return $item;

	}//end tagWithDirectory()

	/**
	 * Detect or generate publication endpoint for a listing
	 *
	 * Uses the listing's own endpoint when it has one, and otherwise derives
	 * one from, in turn, the search URL, the catalog directory, the directory
	 * host and a domain-like title or name.
	 *
	 * @param array $listingData The listing data to process
	 *
	 * @return string|null The publication endpoint URL or null if cannot be determined
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	private function detectPublicationEndpoint(array $listingData): ?string {
		if (empty($listingData['publications']) === false) {
			return $listingData['publications'];
		}

		if (empty($listingData['publication']) === false) {
			return $listingData['publication'];
		}

		return $this->publicationEndpointFromSearch(listingData: $listingData)
			?? $this->publicationEndpointFromCatalogDirectory(listingData: $listingData)
			?? $this->publicationEndpointFromDirectory(listingData: $listingData)
			?? $this->publicationEndpointFromTitle(listingData: $listingData);
	}//end detectPublicationEndpoint()

	/**
	 * The search URL with its `/search` part turned into `/publications`.
	 *
	 * When the URL holds no `/search`, it is rebuilt from its parts with every
	 * `search` path segment replaced; the result counts only when it differs.
	 *
	 * @param array $listingData The listing data.
	 *
	 * @return string|null The derived endpoint, or null when nothing changed.
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	private function publicationEndpointFromSearch(array $listingData): ?string {
		if (empty($listingData['search']) === true) {
			return null;
		}

		$search = $listingData['search'];
		$publicationEndpoint = (string)str_replace('/search', '/publications', (string)$search);
		if ($publicationEndpoint === $search) {
			$publicationEndpoint = $this->rebuildWithPublicationsSegment(url: $search);
		}

		if ($publicationEndpoint !== $search) {
			return $publicationEndpoint;
		}

		return null;
	}//end publicationEndpointFromSearch()

	/**
	 * Rebuild a URL from its parts with every `search` path segment replaced by `publications`.
	 *
	 * @param string $url The URL.
	 *
	 * @return string The rebuilt URL, or the URL itself when it has no path.
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	private function rebuildWithPublicationsSegment(string $url): string {
		$urlParts = parse_url($url);
		if ($urlParts === false || isset($urlParts['path']) === false) {
			return $url;
		}

		$pathSegments = array_map(
			static function ($segment) {
				if ($segment === 'search') {
					return 'publications';
				}

				return $segment;
			},
			explode('/', trim($urlParts['path'], '/'))
		);

		$rebuilt = $this->urlOrigin(urlParts: $urlParts) . '/' . implode('/', $pathSegments);
		if (isset($urlParts['query']) === true) {
			$rebuilt .= '?' . $urlParts['query'];
		}

		return $rebuilt;
	}//end rebuildWithPublicationsSegment()

	/**
	 * The catalog directory endpoint with `/api/directory` turned into `/api/publications`.
	 *
	 * @param array $listingData The listing data.
	 *
	 * @return string|null The derived endpoint, or null when nothing changed.
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	private function publicationEndpointFromCatalogDirectory(array $listingData): ?string {
		if (empty($listingData['catalogDirectory']) === true) {
			return null;
		}

		$catalogDir = (string)$listingData['catalogDirectory'];
		$publicationEndpoint = (string)str_replace('/api/directory', '/api/publications', $catalogDir);
		if ($publicationEndpoint !== $catalogDir) {
			return $publicationEndpoint;
		}

		return null;
	}//end publicationEndpointFromCatalogDirectory()

	/**
	 * The standard publications path on the directory's host.
	 *
	 * A bare hostname is taken as HTTPS; a full URL keeps its scheme and port.
	 *
	 * @param array $listingData The listing data.
	 *
	 * @return string|null The derived endpoint, or null when the directory has no host.
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	private function publicationEndpointFromDirectory(array $listingData): ?string {
		if (empty($listingData['directory']) === true) {
			return null;
		}

		$directory = $listingData['directory'];
		if (strpos($directory, '://') === false) {
			return 'https://' . $directory . '/apps/opencatalogi/api/publications';
		}

		$urlParts = parse_url($directory);
		if ($urlParts !== false && isset($urlParts['host']) === true) {
			return $this->urlOrigin(urlParts: $urlParts) . '/apps/opencatalogi/api/publications';
		}

		return null;
	}//end publicationEndpointFromDirectory()

	/**
	 * The standard publications path on a host named by a domain-like title or name.
	 *
	 * @param array $listingData The listing data.
	 *
	 * @return string|null The derived endpoint, or null when the title is not domain-like.
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	private function publicationEndpointFromTitle(array $listingData): ?string {
		$title = ($listingData['title'] ?? $listingData['name'] ?? '');
		if (empty($title) === true || strpos($title, '.') === false || strpos($title, ' ') !== false) {
			return null;
		}

		$hostname = preg_replace('#[/\\\\].*$#', '', strtolower(trim($title)));
		if (empty($hostname) === false) {
			return 'https://' . $hostname . '/apps/opencatalogi/api/publications';
		}

		return null;
	}//end publicationEndpointFromTitle()

	/**
	 * The `scheme://host[:port]` part of a parsed URL.
	 *
	 * @param array $urlParts The parse_url() result.
	 *
	 * @return string The origin.
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	private function urlOrigin(array $urlParts): string {
		$origin = $urlParts['scheme'] . '://' . $urlParts['host'];
		if (isset($urlParts['port']) === true) {
			$origin .= ':' . $urlParts['port'];
		}

		return $origin;
	}//end urlOrigin()

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

			// A peer timestamp beyond the clock-skew horizon is never "newer";
			// a stored one beyond it does not block later updates either.
			$horizon = (new DateTime())->modify('+'.self::MAX_TIMESTAMP_SKEW_SECONDS.' seconds');
			if ($incomingUpdated !== null && $incomingUpdated > $horizon) {
				return true;
			}

			// If we can't determine timestamps, allow the update to be safe.
			if ($incomingUpdated === null || $existingUpdated === null || $existingUpdated > $horizon) {
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

			// Find all listings from this directory. Lookup and save are listing register
			// operations, so they run with the sync's listing rights (pp-f3).
			$existingListings = $this->withListingWriteRights(
				operation: fn () => $objectService->searchObjects(
					query: [
						'@self' => [
							'register' => $listingRegister,
							'schema' => $listingSchema,
						],
						'directory' => $directoryUrl,
					]
				)
			);

			// Update each listing with error status.
			foreach ($existingListings as $listing) {
				$listingData = $listing->jsonSerialize();
				$errorData = ($listingData['object'] ?? []);

				// Update with error status. lastSuccessAt is left as it was.
				$errorData['statusCode'] = $statusCode;
				$errorData['lastSync']   = (new DateTime())->format('c');
				$errorData['lastError']  = self::sanitizeSyncError(message: $errorMessage);

				$this->withListingWriteRights(
					operation: fn () => $objectService->saveObject(
						object: $errorData,
						extend: [],
						register: $listingRegister,
						schema: $listingSchema,
						uuid: $listingData['id']
					)
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
		$this->resolveSafeOutboundUrl(url: $url);

	}//end assertSafeOutboundUrl()

	/**
	 * Refuse to send a request whose address pin cannot be honoured.
	 *
	 * CURLOPT_RESOLVE is a cURL option. Guzzle's stream handler, which it falls back
	 * to when ext-curl is missing, ignores it silently and resolves the name again,
	 * which would undo the DNS-rebinding protection. Nextcloud requires ext-curl and
	 * builds its clients on CurlHandler, so this is a fail-closed backstop for an
	 * unusual build, not a code path a normal install reaches.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When cURL is not available to pin the address.
	 *
	 * @spec exclude SSRF hardening of an existing outbound request; no domain behaviour.
	 */
	private function assertPinSupported(): void {
		$available = (\function_exists('curl_init') === true && \extension_loaded('curl') === true);
		if ($this->curlAvailable !== null) {
			$available = ($this->curlAvailable)();
		}

		if ($available === false) {
			throw new InvalidArgumentException('Directory target cannot be pinned to the checked address without cURL');
		}

	}//end assertPinSupported()

	/**
	 * Validate an outbound URL (SSRF guard) and return the addresses that passed.
	 *
	 * Same checks as {@see assertSafeOutboundUrl()}; the result lets the caller pin
	 * the connection to a checked address so the HTTP client does not resolve the
	 * name a second time (DNS-rebinding TOCTOU).
	 *
	 * @param string $url The URL to validate.
	 *
	 * @return array{host: string, ips: list<string>} The host and the checked addresses;
	 *                                                 `ips` is empty for an allowlisted
	 *                                                 host or a literal IP (nothing to pin).
	 *
	 * @throws InvalidArgumentException When the URL or its resolved host is not safe.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 *
	 * @spec exclude SSRF input-validation guard for outbound directory fetches; security
	 *       plumbing that hardens an existing fetch, no new domain behavior.
	 */
	private function resolveSafeOutboundUrl(string $url): array {
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
			return ['host' => $host, 'ips' => []];
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

		if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) {
			return ['host' => $host, 'ips' => []];
		}

		return ['host' => $host, 'ips' => array_values(array_unique($ipsToCheck))];

	}//end resolveSafeOutboundUrl()

	/**
	 * Validate an outbound URL and build the request options that keep the fetch on the checked address.
	 *
	 * - CURLOPT_RESOLVE pins host:port to an address the SSRF guard accepted, so a DNS
	 *   answer that changes between check and connect (rebinding) cannot reach an
	 *   internal address.
	 * - CURLOPT_MAXFILESIZE and an `on_headers` check refuse an announced body over
	 *   MAX_RESPONSE_BYTES before it is downloaded; {@see readCappedBody()} bounds
	 *   the rest.
	 *
	 * @param string $url The URL about to be fetched.
	 *
	 * @return array<string, mixed> Guzzle request options.
	 *
	 * @throws InvalidArgumentException When the URL or its resolved host is not safe.
	 *
	 * @spec exclude SSRF hardening of an existing outbound fetch; no domain behavior.
	 */
	private function pinnedOutboundOptions(string $url): array {
		$resolved = $this->resolveSafeOutboundUrl(url: $url);

		$curl = [\CURLOPT_MAXFILESIZE => self::MAX_RESPONSE_BYTES];
		if (empty($resolved['ips']) === false) {
			$scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
			$port   = parse_url($url, PHP_URL_PORT);
			if ($port === null || $port === false) {
				$port = 80;
				if ($scheme === 'https') {
					$port = 443;
				}
			}

			$address = $resolved['ips'][0];
			if (str_contains($address, ':') === true) {
				$address = '['.$address.']';
			}

			$this->assertPinSupported();

			$curl[\CURLOPT_RESOLVE] = [$resolved['host'].':'.$port.':'.$address];
		}

		return [
			RequestOptions::CURL => $curl,
			RequestOptions::ON_HEADERS => static function (mixed $response): void {
				if ($response instanceof \Psr\Http\Message\ResponseInterface === false) {
					return;
				}

				$length = $response->getHeaderLine('Content-Length');
				if ($length !== '' && (int) $length > self::MAX_RESPONSE_BYTES) {
					throw new InvalidArgumentException('Response body exceeds the size limit');
				}
			},
		];

	}//end pinnedOutboundOptions()

	/**
	 * Read a response body, refusing one larger than MAX_RESPONSE_BYTES.
	 *
	 * @param \Psr\Http\Message\ResponseInterface $response The response.
	 *
	 * @return string The body.
	 *
	 * @throws InvalidArgumentException When the body exceeds the limit.
	 *
	 * @spec exclude Memory bound on an existing outbound fetch; no domain behavior.
	 */
	private function readCappedBody(\Psr\Http\Message\ResponseInterface $response): string {
		$body    = $response->getBody();
		$content = '';
		while ($body->eof() === false) {
			$chunk = $body->read(65536);
			if ($chunk === '') {
				break;
			}

			$content .= $chunk;
			if (strlen($content) > self::MAX_RESPONSE_BYTES) {
				throw new InvalidArgumentException('Response body exceeds the size limit');
			}
		}

		return $content;

	}//end readCappedBody()

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
			// Re-validate and pin every hop (the first one included) to the address the
			// SSRF guard accepted, so the client never resolves the name on its own.
			$response = $this->client->get(
				$current,
				[
					RequestOptions::ALLOW_REDIRECTS => false,
					RequestOptions::TIMEOUT => 10,
					RequestOptions::CONNECT_TIMEOUT => 5,
				] + $this->pinnedOutboundOptions(url: $current)
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
	 * @spec openspec/specs/federation/spec.md
	 */
	public function getUsed(string $uuid, array $guzzleConfig = []): array {
		$directories = $this->getUniqueDirectories(availableOnly: true);
		if (empty($directories) === true) {
			return [
				'results' => [],
				'sources' => [],
			];
		}

		// The /used endpoint lives on the peer's federation endpoint, which
		// needs no catalogue slug (REQ-FOR-002).
		$lookup = [
			'guzzleConfig' => $guzzleConfig,
			'query' => $this->peerQuery(guzzleConfig: $guzzleConfig, extraQuery: []),
			'endpointFor' => static fn (string $directoryUrl): string => self::peerFederationPublicationsUrl(url: $directoryUrl).'/'.urlencode($uuid).'/used',
			'parse' => $this->parsePeerResults(...),
		];
		$targets = $this->peerTargets(directories: $directories, lookup: $lookup);
		$answers = $this->fetchFromPeers(targets: $targets, lookup: $lookup);

		return $this->mergePeerResults(targets: $targets, answers: $answers);

	}//end getUsed()

	/**
	 * Read a peer's list answer, which is either a search result or a bare list.
	 *
	 * @param mixed $data The decoded peer response
	 *
	 * @return array|null The results, or null when the answer is unusable
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	private function parsePeerResults(mixed $data): ?array {
		if (isset($data['results']) === true && is_array($data['results']) === true) {
			return $data['results'];
		}

		if (is_array($data) === true) {
			return $data;
		}

		return null;

	}//end parsePeerResults()

	/**
	 * Combine the peers' results, deduplicated by id, with the peers that answered as sources.
	 *
	 * @param array $targets The peer requests, as built by peerTargets()
	 * @param array $answers The parsed answers, indexed like $targets
	 *
	 * @return array The combined results and sources
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	private function mergePeerResults(array $targets, array $answers): array {
		$combinedResults = [];
		$seenIds         = [];
		$sources         = [];
		foreach ($answers as $index => $results) {
			if (empty($results) === true) {
				continue;
			}

			$sources[$targets[$index]['name']] = $targets[$index]['url'];
			foreach ($results as $item) {
				$itemId = ($item['id'] ?? $item['uuid'] ?? uniqid());
				if (isset($seenIds[$itemId]) === false) {
					$combinedResults[] = $item;
					$seenIds[$itemId]  = true;
				}
			}
		}

		return [
			'results' => $combinedResults,
			'sources' => $sources,
		];

	}//end mergePeerResults()

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
	 * @spec openspec/specs/federation/spec.md
	 */
	public function getPublication(string $publicationId, array $guzzleConfig = []): ?array {
		$directories = $this->getUniqueDirectories(availableOnly: true);
		if (empty($directories) === true) {
			return null;
		}

		// `/api/publications/{id}` is the catalogue-slug route and 404s once
		// the peer renamed its seed catalogue (REQ-FOR-002, issue #1648), so
		// the lookup goes to the peer's federation endpoint.
		$lookup = [
			'guzzleConfig' => $guzzleConfig,
			'query' => $this->peerQuery(guzzleConfig: $guzzleConfig, extraQuery: []),
			'endpointFor' => static fn (string $directoryUrl): string => self::peerFederationPublicationsUrl(url: $directoryUrl).'/'.urlencode($publicationId),
			'parse' => static function (mixed $data): ?array {
				if (empty($data) === true) {
					return null;
				}

				return ['data' => $data];
			},
		];
		$targets = $this->peerTargets(directories: $directories, lookup: $lookup);
		$answers = $this->fetchFromPeers(targets: $targets, lookup: $lookup);

		foreach ($answers as $index => $answer) {
			if ($answer !== null) {
				return [
					'result' => $answer['data'],
					'source' => [
						$targets[$index]['name'] => $targets[$index]['url'],
					],
				];
			}
		}

		return null;
	}//end getPublication()

	/**
	 * The query every peer lookup sends: the caller's `query_params`, the extra
	 * parameters, no fan-out on the peer's side and the schema and register extended.
	 *
	 * @param array $guzzleConfig The caller's Guzzle configuration
	 * @param array $extraQuery   Parameters to add after `_aggregate`
	 *
	 * @return array The query parameters
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	private function peerQuery(array $guzzleConfig, array $extraQuery): array {
		$query = ($guzzleConfig['query_params'] ?? []);
		// Prevent circular aggregation: the peer must not fan out to its own
		// peers (which would eventually loop back to us).
		$query['_aggregate'] = 'false';
		foreach ($extraQuery as $key => $value) {
			$query[$key] = $value;
		}

		$query['_extend'] = [
			'@self.schema',
			'@self.register',
		];

		return $query;

	}//end peerQuery()

	/**
	 * The requests to send for a peer lookup, skipping our own directory and any unsafe URL.
	 *
	 * At most MAX_PEERS_PER_LOOKUP candidates are considered, whether or not they
	 * pass the SSRF guard, and candidates stop once MAX_PEER_RESOLVE_SECONDS of
	 * resolving is spent: a URL that resolves slowly or to a blocked address costs a
	 * DNS lookup, so it counts too. Each peer is resolved once, and its target carries
	 * the request options that pin it to the address the SSRF guard accepted.
	 *
	 * @param array $directories The directory URLs to ask
	 * @param array $lookup      The lookup: `query` to send and `endpointFor`, which maps a directory URL to the endpoint to call
	 *
	 * @return array<int, array{url: string, name: string, options: array}> One entry per peer to call
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	private function peerTargets(array $directories, array $lookup): array {
		$ourDirectoryUrl = $this->urlGenerator->getAbsoluteURL(
			$this->urlGenerator->linkToRoute('opencatalogi.directory.index')
		);

		$targets    = [];
		$candidates = 0;
		$deadline   = (microtime(true) + self::MAX_PEER_RESOLVE_SECONDS);
		foreach ($directories as $directoryUrl) {
			if ($directoryUrl === $ourDirectoryUrl) {
				continue;
			}

			// Count the candidate before resolving it (pub-f1): an unsafe or slow name
			// costs a lookup as much as a good one does.
			if ($candidates >= self::MAX_PEERS_PER_LOOKUP || microtime(true) >= $deadline) {
				break;
			}

			$candidates++;

			// Skip local/unsafe URLs. pinnedOutboundOptions() runs the SSRF guard once,
			// on the URL that is fetched, and pins the request to the address it accepted.
			try {
				$endpoint = $lookup['endpointFor']($directoryUrl);
				$options  = $this->pinnedOutboundOptions(url: $endpoint);
			} catch (InvalidArgumentException $e) {
				continue;
			}

			$host = parse_url($directoryUrl, PHP_URL_HOST);
			if (empty($host) === true) {
				$host = $directoryUrl;
			}

			$targets[] = [
				'url' => $endpoint.'?'.http_build_query($lookup['query']),
				'name' => $host,
				'options' => $options,
			];
		}//end foreach

		return $targets;

	}//end peerTargets()

	/**
	 * Call the peers concurrently and parse their answers.
	 *
	 * The requests run in parallel, so one lookup takes about one peer timeout
	 * however many peers answer slowly, instead of the sum of all timeouts.
	 *
	 * @param array $targets The peer requests, as built by peerTargets()
	 * @param array $lookup  The lookup: the caller's `guzzleConfig`, merged over the defaults, and `parse`,
	 *                       which turns a decoded answer into a result or null when unusable
	 *
	 * @return array<int, array|null> The parsed answers, indexed like $targets; null for a failed peer
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	private function fetchFromPeers(array $targets, array $lookup): array {
		$defaultGuzzleConfig = [
			RequestOptions::TIMEOUT => 5,
			RequestOptions::CONNECT_TIMEOUT => 2,
			RequestOptions::HEADERS => [
				'Accept' => 'application/json',
				'User-Agent' => 'OpenCatalogi-DirectoryService/1.0',
			],
			RequestOptions::HTTP_ERRORS => false,
		];
		$clientConfig = array_merge($defaultGuzzleConfig, $lookup['guzzleConfig']);
		// The lookups serve public endpoints that may pass `timeout`/`connect_timeout`
		// from the query; cap them so one request cannot hold a worker for minutes.
		$clientConfig[RequestOptions::TIMEOUT] = min((float) $clientConfig[RequestOptions::TIMEOUT], self::MAX_PEER_TIMEOUT);
		$clientConfig[RequestOptions::CONNECT_TIMEOUT] = min((float) $clientConfig[RequestOptions::CONNECT_TIMEOUT], self::MAX_PEER_CONNECT_TIMEOUT);
		$client = new Client($clientConfig);

		// SSRF hardening (SB1 / wave-12): disable automatic redirect following so
		// that a federated peer cannot pivot to cloud-metadata via a 302 response.
		$promises = [];
		foreach ($targets as $index => $target) {
			$promises[$index] = $client->getAsync(
				$target['url'],
				[RequestOptions::ALLOW_REDIRECTS => false] + ($target['options'] ?? [])
			);
		}

		$settled = \GuzzleHttp\Promise\Utils::settle($promises)->wait();

		$answers = [];
		foreach (array_keys($targets) as $index) {
			$outcome = ($settled[$index] ?? []);
			if (($outcome['state'] ?? '') !== 'fulfilled') {
				$answers[] = null;
				continue;
			}

			$answers[] = $this->parsePeerResponse(response: $outcome['value'], lookup: $lookup);
		}

		return $answers;

	}//end fetchFromPeers()

	/**
	 * Parse one peer's answer.
	 *
	 * @param \Psr\Http\Message\ResponseInterface $response The peer response
	 * @param array                               $lookup   The lookup: `parse`, which turns a decoded answer into a result or null
	 *
	 * @return array|null The parsed answer, or null on a non-2xx status, an oversized body or invalid JSON
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	private function parsePeerResponse(\Psr\Http\Message\ResponseInterface $response, array $lookup): ?array {
		try {
			$statusCode = $response->getStatusCode();
			if ($statusCode < 200 || $statusCode >= 300) {
				return null;
			}

			$data = json_decode($this->readCappedBody(response: $response), true);
			if (json_last_error() !== JSON_ERROR_NONE) {
				return null;
			}

			return $lookup['parse']($data);
		} catch (\Exception $e) {
			return null;
		}//end try

	}//end parsePeerResponse()

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
	 * @spec openspec/specs/dashboard/spec.md
	 */
	public function getDirectory(array $requestParams = []): array {
		// Check if OpenRegister service is available.
		if (in_array('openregister', $this->appManager->getInstalledApps()) === false) {
			throw new RuntimeException('OpenRegister service is not available.');
		}

		// Get ObjectService from container.
		$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

		$allResults = array_merge(
			$this->directoryListings(objectService: $objectService, requestParams: $requestParams),
			$this->directoryCatalogs(objectService: $objectService, requestParams: $requestParams)
		);

		return [
			'results' => $allResults,
			'total' => count($allResults),
		];

	}//end getDirectory()

	/**
	 * The discovered listings for the directory, filtered to our own unless the caller opts in.
	 *
	 * @param object $objectService The OpenRegister ObjectService
	 * @param array  $requestParams The directory request parameters
	 *
	 * @return array The listings, or an empty array when unconfigured or the search fails
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	private function directoryListings(object $objectService, array $requestParams): array {
		// The scope keys are read right before the guard that makes them safe,
		// so the read does not look unguarded (to a reviewer or hydra gate-50).
		$listingSchema = $this->config->getValueString($this->appName, 'listing_schema', '');
		$listingRegister = $this->config->getValueString($this->appName, 'listing_register', '');
		if (empty($listingSchema) === true || empty($listingRegister) === true) {
			return [];
		}

		try {
			// RBAC handles public visibility via conditional published date rule.
			// ADR-002: each tenant queries only its own listings; multitenancy must stay enabled.
			$listingResult = $objectService->searchObjects(
				$this->directoryQuery(scope: ['schema' => $listingSchema, 'register' => $listingRegister], requestParams: $requestParams)
			);

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

				if ($includeFederated === false
					&& $this->isFromOtherDirectory(listingData: $listingData, ourDirectoryUrl: $ourDirectoryUrl) === true
				) {
					continue;
				}

				$listings[] = $this->filterListingProperties(listing: $listingData);
			}

			return $listings;
		} catch (\Exception $e) {
			return [];
		}//end try

	}//end directoryListings()

	/**
	 * Whether a listing was synced from another directory.
	 *
	 * Such a listing's `directory` field points at the peer's own /api/directory
	 * URL, not ours, and the default directory view does not re-broadcast it.
	 *
	 * @param array  $listingData     The listing
	 * @param string $ourDirectoryUrl Our own directory URL
	 *
	 * @return boolean True when the listing names a directory other than ours
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	private function isFromOtherDirectory(array $listingData, string $ourDirectoryUrl): bool {
		$objectData = ($listingData['object'] ?? $listingData);
		$listingDir = ($objectData['directory'] ?? '');

		return empty($listingDir) === false && $listingDir !== $ourDirectoryUrl;

	}//end isFromOtherDirectory()

	/**
	 * Our local catalogs, converted to listings with their schemas expanded.
	 *
	 * @param object $objectService The OpenRegister ObjectService
	 * @param array  $requestParams The directory request parameters
	 *
	 * @return array The catalogs as listings, or an empty array when unconfigured or the search fails
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	private function directoryCatalogs(object $objectService, array $requestParams): array {
		$catalogSchema = $this->config->getValueString($this->appName, 'catalog_schema', 'catalog');
		$catalogRegister = $this->config->getValueString($this->appName, 'catalog_register', '');
		if (empty($catalogSchema) === true || empty($catalogRegister) === true) {
			return [];
		}

		try {
			// RBAC handles public visibility via conditional published date rule.
			// ADR-002: each tenant queries only its own catalogs; multitenancy must stay enabled.
			$query = $this->directoryQuery(scope: ['schema' => $catalogSchema, 'register' => $catalogRegister], requestParams: $requestParams);
			// An unlisted catalog (listed is not true) is reachable by link but not
			// advertised: anonymous callers, and the peers that sync this directory into
			// their own, do not get it. Same rule as /api/search.
			if ($this->isAnonymousCaller() === true) {
				$query['listed'] = true;
			}

			$catalogResult = $objectService->searchObjects($query);

			return $this->convertCatalogiToListings(catalogs: $catalogResult);
		} catch (\Exception $e) {
			return [];
		}

	}//end directoryCatalogs()

	/**
	 * Whether the caller is anonymous. Fails closed: an unresolvable session counts as anonymous.
	 *
	 * @return bool True when no user is signed in.
	 *
	 * @spec exclude Visibility helper for the unlisted-catalog filter; no domain behaviour.
	 */
	private function isAnonymousCaller(): bool {
		try {
			return $this->container->get(\OCP\IUserSession::class)->getUser() === null;
		} catch (\Throwable $e) {
			return true;
		}

	}//end isAnonymousCaller()

	/**
	 * The search query for one part of the directory: its scope, the request's
	 * filters (except schema and register) and its pagination.
	 *
	 * @param array{schema: string, register: string} $scope         The schema and register to search
	 * @param array                                   $requestParams The directory request parameters
	 *
	 * @return array The OpenRegister search query
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	private function directoryQuery(array $scope, array $requestParams): array {
		$query = [
			'@self' => [
				'schema' => $scope['schema'],
				'register' => $scope['register'],
			],
		];

		$filters = array_merge([], ($requestParams['filters'] ?? []));
		foreach ($filters as $key => $value) {
			if (in_array($key, ['schema', 'register']) === false) {
				$query[$key] = $value;
			}
		}

		if (isset($requestParams['limit']) === true) {
			$query['_limit'] = (int)$requestParams['limit'];
		}

		if (isset($requestParams['offset']) === true) {
			$query['_offset'] = (int)$requestParams['offset'];
		}

		return $query;

	}//end directoryQuery()

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
	 * merging counts for the same facet values. A field whose new or existing
	 * value is not a list is left as it was.
	 *
	 * @param array $existingFacets The current aggregated facets
	 * @param array $newFacets The new facets to merge in
	 *
	 * @return array The merged facets
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
			if (is_array($facetValues) === false) {
				continue;
			}

			if (isset($mergedFacets[$field]) === false) {
				$mergedFacets[$field] = $facetValues;
				continue;
			}

			if (is_array($mergedFacets[$field]) === true) {
				$mergedFacets[$field] = $this->sortFacetBuckets(
					buckets: $this->mergeFacetBuckets(buckets: $mergedFacets[$field], incoming: $facetValues)
				);
			}
		}

		return $mergedFacets;
	}//end aggregateFacets()

	/**
	 * Merge one field's incoming facet buckets into its existing buckets.
	 *
	 * An incoming bucket whose `_id` an existing bucket has adds its count to
	 * that bucket; any other bucket with an `_id` is appended. Buckets without
	 * an `_id` are skipped.
	 *
	 * @param array $buckets The existing buckets of the field.
	 * @param array $incoming The incoming buckets of the field.
	 *
	 * @return array The merged buckets.
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	private function mergeFacetBuckets(array $buckets, array $incoming): array {
		$existingValues = $this->facetBucketPositions(buckets: $buckets);
		foreach ($incoming as $facetValue) {
			if (is_array($facetValue) === false || isset($facetValue['_id']) === false) {
				continue;
			}

			$index = ($existingValues[$facetValue['_id']] ?? null);
			if ($index === null || isset($facetValue['count']) === false) {
				$buckets[] = $facetValue;
				continue;
			}

			if (isset($buckets[$index]['count']) === true) {
				$buckets[$index]['count'] += $facetValue['count'];
			}
		}

		return $buckets;
	}//end mergeFacetBuckets()

	/**
	 * The position of each existing facet bucket, by its `_id`.
	 *
	 * @param array $buckets The buckets of one field.
	 *
	 * @return array<int|string, int|string> Bucket key by `_id`.
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	private function facetBucketPositions(array $buckets): array {
		$positions = [];
		foreach ($buckets as $index => $bucket) {
			if (is_array($bucket) === true && isset($bucket['_id']) === true) {
				$positions[$bucket['_id']] = $index;
			}
		}

		return $positions;
	}//end facetBucketPositions()

	/**
	 * Sort facet buckets by count (descending), then by `_id` (ascending).
	 *
	 * Buckets that are not arrays with an `_id` compare equal to everything.
	 *
	 * @param array $buckets The buckets.
	 *
	 * @return array The sorted, reindexed buckets.
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	private function sortFacetBuckets(array $buckets): array {
		if (empty($buckets) === true) {
			return $buckets;
		}

		usort(
			$buckets,
			static function ($a, $b): int {
				if (is_array($a) === false || is_array($b) === false || isset($a['_id'], $b['_id']) === false) {
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

		return $buckets;
	}//end sortFacetBuckets()
}//end class
