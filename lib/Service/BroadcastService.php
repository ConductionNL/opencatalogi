<?php

/**
 * Service for broadcasting this OpenCatalogi directory to other instances.
 *
 * Provides functionality to notify external instances about this directory
 * through HTTP POST requests, either to a specific URL or to all known directories.
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
 * @spec openspec/specs/dashboard/spec.md
 * @spec openspec/specs/dashboard/spec.md
 */

namespace OCA\OpenCatalogi\Service;

use DateTime;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use InvalidArgumentException;
use OCA\OpenCatalogi\Contract\BroadcastServiceInterface;
use OCA\OpenCatalogi\Service\Broadcast\BroadcastResult;
use OCA\OpenCatalogi\Service\Connection\ConnectionReporter;
use OCA\OpenCatalogi\Service\Federation\FederationHostPolicy;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * BroadcastService Class.
 *
 * This class provides functionality to broadcast this OpenCatalogi directory to other instances.
 * It allows for broadcasting to a specific URL or to all known directories.
 * The service uses dynamic versioning in User-Agent headers for proper identification.
 *
 * @spec openspec/specs/dashboard/spec.md
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class BroadcastService implements BroadcastServiceInterface {

	/**
	 * The name of the app.
	 *
	 * @var string The name of the app
	 */
	private string $appName = 'opencatalogi';

	/**
	 * The HTTP client for making requests.
	 *
	 * @var Client The HTTP client for making requests
	 */
	private Client $client;

	/**
	 * Default maximum number of broadcast retries on failure.
	 *
	 * Overridable via the `broadcast_max_retries` app-config key (see
	 * getMaxRetries()). The constant is the shipped default only — operators
	 * tune the live value without a code change (audit Stream 4).
	 *
	 * @var int Default maximum number of broadcast retries on failure
	 */
	private const DEFAULT_MAX_RETRIES = 3;

	/**
	 * App-config key that overrides DEFAULT_MAX_RETRIES.
	 *
	 * @var string
	 */
	private const CONFIG_MAX_RETRIES = 'broadcast_max_retries';

	/**
	 * Default timeout for HTTP requests in seconds.
	 *
	 * Overridable via the `broadcast_request_timeout` app-config key (see
	 * getRequestTimeout()).
	 *
	 * @var int Default timeout for HTTP requests in seconds
	 */
	private const DEFAULT_REQUEST_TIMEOUT = 30;

	/**
	 * App-config key that overrides DEFAULT_REQUEST_TIMEOUT.
	 *
	 * @var string
	 */
	private const CONFIG_REQUEST_TIMEOUT = 'broadcast_request_timeout';

	/**
	 * Maximum wall-clock seconds allowed for all retries to a single URL.
	 *
	 * Caps total blocking time per target so that a slow/hung upstream cannot
	 * stall the entire broadcast loop indefinitely.
	 *
	 * @var int Maximum wall-clock seconds for all retries to a single broadcast target
	 */
	private const MAX_RETRY_WALL_SECONDS = 90;

	/**
	 * The largest broadcast response body accepted, in bytes.
	 *
	 * Only the status code of a broadcast answer is used; the cap keeps a peer
	 * from answering with a body that fills memory or disk.
	 *
	 * @var int
	 */
	private const MAX_RESPONSE_BYTES = 1048576;

	/**
	 * Resolves a host name to its addresses; null uses DNS.
	 *
	 * A test seam only: production never sets it, so the SSRF guard resolves through
	 * dns_get_record()/gethostbyname() as before.
	 *
	 * @var (\Closure(string): list<string>)|null
	 *
	 * @phpstan-ignore property.unusedType (only the unit test assigns it, through reflection)
	 */
	private ?\Closure $hostResolver = null;

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
	 * Constructor for BroadcastService.
	 *
	 * @param IURLGenerator $urlGenerator URL generator interface
	 * @param ContainerInterface $container Server container for dependency injection
	 * @param IAppManager $appManager App manager for checking installed apps
	 * @param LoggerInterface $logger Logger for recording broadcast activities
	 * @param IAppConfig $config App configuration (local-federation allowlist)
	 * @param FederationHostPolicy $hostPolicy Decides whether a federation URL is reachable by a peer
	 * @param ConnectionReporter|null $connectionReporter Tells integriq what a broadcast met, or nothing when absent.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-oc-conn-003-opencatalogi-reports-what-a-sync-a-broadcast-and-a-readiness-check-met
	 */
	public function __construct(
		private readonly IURLGenerator $urlGenerator,
		private readonly ContainerInterface $container,
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
		private readonly IAppConfig $config,
		private readonly FederationHostPolicy $hostPolicy,
		// The integriq connection report (adopt-connection-registry). Nullable
		// and last, so every existing construction site keeps working.
		private readonly ?ConnectionReporter $connectionReporter = null,
	) {
		// Initialize HTTP client; the per-request timeout is operator-tunable.
		$this->client = new Client(
			config: [
				'timeout' => $this->getRequestTimeout(),
				'connect_timeout' => 10,
				'verify' => true,
			]
		);

	}//end __construct()

	/**
	 * Resolve the operator-configured maximum broadcast-retry count.
	 *
	 * Reads `broadcast_max_retries` from IAppConfig, falling back to
	 * DEFAULT_MAX_RETRIES. Values below 1 are clamped to 1 so a broadcast is
	 * always attempted at least once.
	 *
	 * @return int The effective maximum number of retries.
	 *
	 * @spec openspec/specs/opencatalogi-adopt-or-abstractions/spec.md (Requirement: Promote hardcoded constants)
	 */
	private function getMaxRetries(): int {
		$value = $this->config->getValueInt($this->appName, self::CONFIG_MAX_RETRIES, self::DEFAULT_MAX_RETRIES);
		return max(1, $value);
	}//end getMaxRetries()

	/**
	 * Resolve the operator-configured per-request HTTP timeout in seconds.
	 *
	 * Reads `broadcast_request_timeout` from IAppConfig, falling back to
	 * DEFAULT_REQUEST_TIMEOUT. Values below 1 are clamped to 1.
	 *
	 * @return int The effective request timeout in seconds.
	 *
	 * @spec openspec/specs/opencatalogi-adopt-or-abstractions/spec.md (Requirement: Promote hardcoded constants)
	 */
	private function getRequestTimeout(): int {
		$value = $this->config->getValueInt($this->appName, self::CONFIG_REQUEST_TIMEOUT, self::DEFAULT_REQUEST_TIMEOUT);
		return max(1, $value);
	}//end getRequestTimeout()

	/**
	 * Attempts to retrieve the OpenRegister ObjectService from the container.
	 *
	 * This method checks if the OpenRegister app is installed and available,
	 * then attempts to retrieve the ObjectService from the dependency container.
	 *
	 * @return \OCA\OpenRegister\Service\ObjectService The OpenRegister ObjectService if available
	 *
	 * @throws \RuntimeException When OpenRegister service is not available
	 * @throws ContainerExceptionInterface When container access fails
	 * @throws NotFoundExceptionInterface When service is not found in container
	 */
	private function getObjectService(): \OCA\OpenRegister\Service\ObjectService {
		// Check if OpenRegister app is installed and enabled.
		if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
			try {
				// Attempt to retrieve the ObjectService from the container.
				return $this->container->get('OCA\OpenRegister\Service\ObjectService');
			} catch (ContainerExceptionInterface|NotFoundExceptionInterface $e) {
				// Log the container error for debugging.
				$this->logger->error('Failed to retrieve OpenRegister ObjectService from container: ' . $e->getMessage());
				throw $e;
			}
		}

		// Throw exception when OpenRegister is not available.
		throw new RuntimeException(
			'OpenRegister service is not available. Ensure OpenRegister app is installed and enabled.'
		);

	}//end getObjectService()

	/**
	 * Get the current version of the OpenCatalogi app.
	 *
	 * Retrieves the version string from the app manager to use in User-Agent headers
	 * and other version-specific functionality.
	 *
	 * @return string The current app version, defaults to 'unknown' if not available
	 */
	private function getAppVersion(): string {
		try {
			// Get the app version from the app manager.
			$appInfo = $this->appManager->getAppInfo($this->appName);
			return ($appInfo['version'] ?? 'unknown');
		} catch (\Exception $e) {
			// Log the error and return a fallback version.
			$this->logger->warning('Failed to retrieve app version: ' . $e->getMessage());
			return 'unknown';
		}

	}//end getAppVersion()

	/**
	 * Get the current directory URL for this OpenCatalogi instance.
	 *
	 * This method generates the absolute URL for this directory's index endpoint
	 * which will be sent to other instances during broadcast.
	 *
	 * @return string The absolute URL of this directory
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	private function getCurrentDirectoryUrl(): string {
		// Generate the absolute URL for the directory index endpoint.
		return $this->urlGenerator->getAbsoluteURL(
			$this->urlGenerator->linkToRoute('opencatalogi.directory.index')
		);

	}//end getCurrentDirectoryUrl()

	/**
	 * Retrieve all unique directory URLs from existing listings.
	 *
	 * This method fetches all listing objects and extracts unique directory URLs
	 * to determine which external instances should receive broadcast notifications.
	 *
	 * @return array<string> Array of unique directory URLs
	 *
	 * @throws DoesNotExistException When required data is not found
	 * @throws MultipleObjectsReturnedException When duplicate objects are found
	 * @throws ContainerExceptionInterface When container access fails
	 * @throws NotFoundExceptionInterface When service is not found in container
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	private function getDirectoryUrls(): array {
		try {
			// Get listing configuration; without it there are no listings to read.
			$listingSchema = $this->config->getValueString($this->appName, 'listing_schema', '');
			$listingRegister = $this->config->getValueString($this->appName, 'listing_register', '');
			if (empty($listingSchema) === true || empty($listingRegister) === true) {
				return [];
			}//end if

			// Retrieve all listing objects from OpenRegister via the canonical search API.
			$query = [
				'@self' => [
					'register' => $listingRegister,
					'schema' => $listingSchema,
				],
			];

			// Directory data is public by design; RBAC is disabled for discovery (see ADR-002).
			$listings = $this->getObjectService()->searchObjects($query, _rbac: false);

			// Extract directory URLs from each listing's object data.
			$directoryUrls = [];
			foreach ($listings as $listing) {
				$listingData = $listing->jsonSerialize();
				$objectData = ($listingData['object'] ?? $listingData);
				$url = ($objectData['directory'] ?? null);
				if (empty($url) === false) {
					$directoryUrls[] = $url;
				}//end if
			}//end foreach

			// Filter to unique, valid URLs.
			return array_filter(
				array_unique($directoryUrls),
				function ($url) {
					return empty($url) === false && filter_var($url, FILTER_VALIDATE_URL) !== false;
				}
			);
		} catch (\Exception $e) {
			// Log the error and re-throw for caller handling.
			$this->logger->error('Failed to retrieve directory URLs: ' . $e->getMessage());
			throw $e;
		}//end try

	}//end getDirectoryUrls()

	/**
	 * Send broadcast request to a specific URL with retry logic.
	 *
	 * This method handles the actual HTTP POST request with built-in retry logic
	 * for handling temporary network failures or service unavailability.
	 * The User-Agent header includes the current app version for identification.
	 *
	 * @param string $url The target URL to broadcast to
	 * @param string $directoryUrl The URL of this directory to include in broadcast
	 *
	 * @return boolean True if broadcast was successful, false otherwise
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	private function sendBroadcastRequest(string $url, string $directoryUrl): bool {
		$attempt = 0;
		$startTime = time();
		$maxRetries = $this->getMaxRetries();

		// Retry loop with wall-clock cap to prevent indefinite blocking.
		while ($attempt < $maxRetries) {
			// Abort if we have exceeded the per-target wall-time budget.
			if ((time() - $startTime) >= self::MAX_RETRY_WALL_SECONDS) {
				$this->logger->warning(
					"[BroadcastService] Wall-time cap reached for {$url}; aborting retries after {$attempt} attempt(s)"
				);
				return false;
			}

			$attempt++;

			// Re-validate and pin on every attempt (dir-f1): the client must connect to
			// the address the SSRF guard accepted, not resolve the name again, or a
			// low-TTL name can answer public for the check and internal for the POST.
			try {
				$pinned = $this->pinnedOutboundOptions(url: $url);
			} catch (InvalidArgumentException $e) {
				$this->logger->warning("[BroadcastService] Broadcast target {$url} failed the SSRF guard on attempt {$attempt}");
				return false;
			}

			try {
				// Send POST request with directory URL payload.
				// SSRF hardening (WF4 / wave-12): disable automatic redirect following so that a
				// broadcast target cannot redirect to cloud-metadata. The pre-flight
				// assertSafeOutboundUrl() validates the initial URL; disabling redirects ensures
				// Guzzle never silently follows a 302 to an unvalidated host.
				$response = $this->client->post(
					uri: $url,
					options: [
						'json' => [
							'directory' => $directoryUrl,
							'timestamp' => (new DateTime())->format('c'),
							'source' => $this->appName,
						],
						'headers' => [
							'User-Agent' => 'OpenCatalogi-Broadcast/' . $this->getAppVersion(),
							'Content-Type' => 'application/json',
						],
						RequestOptions::ALLOW_REDIRECTS => false,
						// Only the status is read; do not buffer the body.
						RequestOptions::STREAM => true,
					] + $pinned
				);

				// Check if the response indicates success.
				if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
					$this->logger->info("Successfully broadcasted to {$url} on attempt {$attempt}");
					return true;
				}

				// Log non-success status code.
				$this->logger->warning(
					"Broadcast to {$url} returned status {$response->getStatusCode()} on attempt {$attempt}"
				);
			} catch (GuzzleException $e) {
				// Log the attempt failure.
				$this->logger->warning("Broadcast attempt {$attempt} to {$url} failed: " . $e->getMessage());

				// If this was the last attempt, log as error and stop.
				if ($attempt === $maxRetries) {
					$this->logger->error(
						"All {$attempt} broadcast attempts to {$url} failed. Final error: " . $e->getMessage()
					);
					return false;
				}

				// Wait before retrying (exponential backoff).
				sleep($attempt * 2);
			}//end try
		}//end while

		return false;
	}//end sendBroadcastRequest()

	/**
	 * Broadcast this OpenCatalogi directory to one or more instances.
	 *
	 * This method handles broadcasting the current directory information to external
	 * OpenCatalogi instances. It can broadcast to a specific URL or to all known directories.
	 *
	 * @param string|null $url Optional URL of a specific instance to broadcast to.
	 *                         If null, broadcasts to all known directories.
	 *
	 * @return array<string, bool> Associative array of URLs and their broadcast success status
	 *
	 * @throws DoesNotExistException When required data is not found
	 * @throws MultipleObjectsReturnedException When duplicate objects are found
	 * @throws ContainerExceptionInterface When container access fails
	 * @throws NotFoundExceptionInterface When service is not found in container
	 *
	 * The outcome is also reported to integriq's connection registry, at most
	 * once an hour while it stays the same (adopt-connection-registry). The
	 * report never changes the result.
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-oc-conn-003-opencatalogi-reports-what-a-sync-a-broadcast-and-a-readiness-check-met
	 */
	public function broadcast(?string $url = null): array {
		// Get the URL of this directory to include in broadcast payload.
		$directoryUrl = $this->getCurrentDirectoryUrl();

		// Initialize results array to track success and failure per URL.
		$results = [];

		// NEVER ADVERTISE AN ADDRESS NO PEER CAN REACH.
		//
		// The payload of a broadcast is OUR OWN url, and a peer stores it as a
		// listing and syncs it back later. On a development or air-gapped
		// instance `overwrite.cli.url` is typically `http://localhost`, so an
		// unguarded broadcast writes `http://localhost/apps/opencatalogi/api/directory`
		// into a PRODUCTION directory, where it is unresolvable for everyone.
		//
		// DirectoryService already refuses its automatic broadcast for this
		// reason, but the guard lived only there — and the first-time-setup
		// `connect-federation` action calls this method DIRECTLY, bypassing it.
		// Measured 2026-08-27 on a fresh localhost instance: pressing "connect to
		// federation" in the wizard would have POSTed a localhost address to
		// directory.opencatalogi.nl. The SSRF pre-flight does not catch this — it
		// validates the TARGET, and the target (the national directory) is
		// perfectly safe. It is the payload that is unroutable.
		if ($this->hostPolicy->isLocalUrl(url: $directoryUrl) === true) {
			$this->logger->warning(
				'Refusing to broadcast: this instance advertises a local address (' . $directoryUrl
				. '). Set overwrite.cli.url to a URL peers can resolve, or add the host to '
				. 'the opencatalogi `local_federation_hosts` allowlist for a local rig.'
			);
			$this->connectionReporter?->reportBroadcastFromLocalAddress();
			return $results;
		}

		// Determine target URLs for broadcasting.
		$targetUrls = $this->getDirectoryUrls();
		if ($url !== null) {
			// Validate the provided URL.
			if (filter_var($url, FILTER_VALIDATE_URL) === false) {
				throw new InvalidArgumentException("Invalid URL provided for broadcast: {$url}");
			}

			$targetUrls = [$url];
		}

		// If no target URLs found, log warning and return empty results.
		if (empty($targetUrls) === true) {
			$this->logger->warning('No target URLs found for broadcasting');
			$this->connectionReporter?->reportBroadcast(results: $results);
			return $results;
		}

		// Log the start of broadcast operation.
		$this->logger->info('Starting broadcast to ' . count($targetUrls) . ' target(s)');

		// Broadcast to each target URL.
		foreach ($targetUrls as $targetUrl) {
			// Skip broadcasting to self to avoid loops.
			if ($targetUrl === $directoryUrl) {
				$this->logger->debug("Skipping broadcast to self: {$targetUrl}");
				continue;
			}

			// Validate the outbound URL and deliver via the enqueueBroadcast adapter
			// (SSRF pre-flight guard, then the synchronous retry loop).
			try {
				$results[$targetUrl] = $this->enqueueBroadcast(url: $targetUrl, directoryUrl: $directoryUrl)->isSuccessful();
			} catch (InvalidArgumentException $e) {
				$this->logger->warning("Skipping unsafe broadcast target: {$targetUrl}");
				$results[$targetUrl] = false;
			}
		}

		// Log summary of broadcast operation.
		$successCount = count(array_filter($results));
		$totalCount = count($results);
		$this->logger->info("Broadcast completed: {$successCount}/{$totalCount} successful");
		$this->connectionReporter?->reportBroadcast(results: $results);

		return $results;
	}//end broadcast()

	/**
	 * Send a broadcast to a single target and report the outcome as a
	 * {@see BroadcastResult} instead of a bare boolean.
	 *
	 * This is the adapter phase of FED-OR-001 (design.md "Phase 1 — Adapter
	 * method, no behaviour change yet"): the SSRF pre-flight guard
	 * (assertSafeOutboundUrl) already runs before anything is dispatched, but
	 * delivery itself still goes through the synchronous sendBroadcastRequest()
	 * retry loop. {@see BroadcastService::broadcast()} delegates each target to
	 * this method. Wiring this to OR's WebhookService (design.md Phase 2) is
	 * deliberately not done here — it depends on confirming
	 * WebhookService::triggerWebhookForEvent's signature against
	 * OpenRegister's development HEAD, which this change could not verify.
	 *
	 * @param string $url The target URL to broadcast to.
	 * @param string $directoryUrl The URL of this directory to include in the broadcast.
	 *
	 * @return BroadcastResult The outcome of the delivery attempt.
	 *
	 * @throws InvalidArgumentException When the target URL fails the SSRF pre-flight guard.
	 *
	 * @spec openspec/changes/opencatalogi-delegate-broadcast-to-or-webhooks/specs/federation/spec.md#requirement-broadcast-pre-flight-ssrf-guard-fed-or-001
	 */
	public function enqueueBroadcast(string $url, string $directoryUrl): BroadcastResult {
		// Pre-flight SSRF guard runs before any delivery attempt — see spec scenario
		// "pre-flight SSRF guard short-circuits before delegation".
		$this->assertSafeOutboundUrl(url: $url);

		$delivered = $this->sendBroadcastRequest(url: $url, directoryUrl: $directoryUrl);

		$status = BroadcastResult::STATUS_FAILED;
		if ($delivered === true) {
			$status = BroadcastResult::STATUS_DELIVERED;
		}

		return new BroadcastResult(
			url: $url,
			status: $status,
		);
	}//end enqueueBroadcast()

	/**
	 * Check whether a host is on the dev-only local-federation allowlist.
	 *
	 * Mirrors {@see DirectoryService::isAllowlistedFederationHost()}. The
	 * `opencatalogi/local_federation_hosts` app-config key holds a comma-separated
	 * allowlist of hostnames (no port) that are exempted from the SSRF guard so two local
	 * instances on a private docker network can broadcast to each other. Empty by default,
	 * so production keeps the full protection.
	 *
	 * @param string $host The lower-cased hostname (without port) to test.
	 *
	 * @return boolean True when the host is explicitly allowlisted for local federation.
	 */
	private function isAllowlistedFederationHost(string $host): bool {
		return $this->hostPolicy->isAllowlistedFederationHost(host: $host);
	}//end isAllowlistedFederationHost()

	/**
	 * Assert that a URL is safe for outbound HTTP requests.
	 *
	 * Performs DNS resolution and rejects URLs that resolve to private/loopback
	 * address ranges to prevent SSRF attacks.
	 *
	 * @param string $url The URL to validate.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the URL or its resolved host is not safe.
	 */
	private function assertSafeOutboundUrl(string $url): void {
		$this->resolveSafeOutboundUrl(url: $url);

	}//end assertSafeOutboundUrl()

	/**
	 * Validate an outbound URL (SSRF guard) and return the addresses that passed.
	 *
	 * The result lets the caller pin the connection to a checked address, so the
	 * HTTP client does not resolve the name a second time (DNS rebinding).
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
	 */
	private function resolveSafeOutboundUrl(string $url): array {
		$parsed = parse_url($url);
		if ($parsed === false || isset($parsed['scheme']) === false || isset($parsed['host']) === false) {
			throw new InvalidArgumentException('Invalid broadcast URL provided');
		}

		$scheme = strtolower($parsed['scheme']);
		if (in_array($scheme, ['http', 'https'], true) === false) {
			throw new InvalidArgumentException('Broadcast URL scheme must be http or https');
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
			throw new InvalidArgumentException('Broadcast URL host is not allowed');
		}

		// Collect IPs to check: literal IP or DNS-resolved addresses.
		$lookupHost = trim($host, '[]');
		$isLiteral  = filter_var($lookupHost, FILTER_VALIDATE_IP) !== false;
		$ipsToCheck = [$lookupHost];
		if ($isLiteral === false) {
			$ipsToCheck = $this->lookupHostAddresses(host: $lookupHost);
		}

		if (empty($ipsToCheck) === true) {
			throw new InvalidArgumentException('Broadcast URL host could not be resolved');
		}

		foreach ($ipsToCheck as $ipAddress) {
			if ($this->isBlockedIp(ipAddress: $ipAddress) === true) {
				throw new InvalidArgumentException('Broadcast URL resolves to a disallowed (internal) address');
			}
		}

		if ($isLiteral === true) {
			return ['host' => $host, 'ips' => []];
		}

		return ['host' => $host, 'ips' => array_values(array_unique($ipsToCheck))];

	}//end resolveSafeOutboundUrl()

	/**
	 * The addresses a host name resolves to (A and AAAA, then gethostbyname()).
	 *
	 * @param string $host The host name, without brackets.
	 *
	 * @return list<string> The addresses; empty when the name does not resolve.
	 *
	 * @spec exclude DNS lookup for the broadcast SSRF guard; security plumbing.
	 */
	private function lookupHostAddresses(string $host): array {
		if ($this->hostResolver !== null) {
			return ($this->hostResolver)($host);
		}

		$addresses = [];
		$records   = dns_get_record($host, (DNS_A | DNS_AAAA));
		if ($records !== false) {
			foreach ($records as $record) {
				if (isset($record['ip']) === true) {
					$addresses[] = $record['ip'];
				}

				if (isset($record['ipv6']) === true) {
					$addresses[] = $record['ipv6'];
				}
			}
		}

		// Fallback to gethostbyname for A records when dns_get_record is empty.
		if (empty($addresses) === true) {
			$resolved = gethostbyname($host);
			if ($resolved !== $host && filter_var($resolved, FILTER_VALIDATE_IP) !== false) {
				$addresses[] = $resolved;
			}
		}

		return $addresses;

	}//end lookupHostAddresses()

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
			throw new InvalidArgumentException('Broadcast target cannot be pinned to the checked address without cURL');
		}

	}//end assertPinSupported()

	/**
	 * Validate a broadcast target and build the request options that keep the POST on the checked address.
	 *
	 * - CURLOPT_RESOLVE pins host:port to an address the SSRF guard accepted, the
	 *   same pin DirectoryService::safeGet() uses, so a DNS answer that changes
	 *   between check and connect cannot reach an internal address.
	 * - CURLOPT_MAXFILESIZE and an `on_headers` check refuse an announced body over
	 *   MAX_RESPONSE_BYTES.
	 *
	 * @param string $url The broadcast target.
	 *
	 * @return array<string, mixed> Guzzle request options.
	 *
	 * @throws InvalidArgumentException When the URL or its resolved host is not safe.
	 *
	 * @spec exclude SSRF hardening of the existing broadcast POST; no domain behaviour.
	 */
	private function pinnedOutboundOptions(string $url): array {
		$resolved = $this->resolveSafeOutboundUrl(url: $url);

		$curl = [\CURLOPT_MAXFILESIZE => self::MAX_RESPONSE_BYTES];
		if (empty($resolved['ips']) === false) {
			$port = parse_url($url, PHP_URL_PORT);
			if ($port === null || $port === false) {
				$port = 80;
				if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https') {
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
					throw new InvalidArgumentException('Broadcast response body exceeds the size limit');
				}
			},
		];

	}//end pinnedOutboundOptions()

	/**
	 * Determine whether an IP address falls in a blocked range.
	 *
	 * Checks private, loopback, link-local, and reserved ranges.
	 *
	 * @param string $ipAddress The IP address to check.
	 *
	 * @return boolean True if the IP is blocked, false if it is safe.
	 */
	private function isBlockedIp(string $ipAddress): bool {
		$blockedRanges = [
			'10.0.0.0/8',
			'172.16.0.0/12',
			'192.168.0.0/16',
			'127.0.0.0/8',
			'169.254.0.0/16',
			'::1/128',
			'fc00::/7',
			'fe80::/10',
		];

		foreach ($blockedRanges as $range) {
			if ($this->ipInRange(ipAddress: $ipAddress, range: $range) === true) {
				return true;
			}
		}

		return false;
	}//end isBlockedIp()

	/**
	 * Check if an IP address falls within a CIDR range.
	 *
	 * @param string $ipAddress The IP address to check.
	 * @param string $range The CIDR range (e.g. 10.0.0.0/8).
	 *
	 * @return boolean True if the IP is in the range, false otherwise.
	 */
	private function ipInRange(string $ipAddress, string $range): bool {
		[$subnet, $bits] = explode('/', $range);

		if (filter_var(value: $ipAddress, filter: FILTER_VALIDATE_IP, options: FILTER_FLAG_IPV6) !== false
			&& filter_var(value: $subnet, filter: FILTER_VALIDATE_IP, options: FILTER_FLAG_IPV6) !== false
		) {
			$ipBin = inet_pton($ipAddress);
			$subnetBin = inet_pton($subnet);
			if ($ipBin === false || $subnetBin === false) {
				return false;
			}

			$mask = str_repeat("\xff", intdiv((int)$bits, 8));
			if (((int)$bits % 8) !== 0) {
				$mask .= chr(0xff & (0xff << (8 - ((int)$bits % 8))));
			}

			$mask = str_pad($mask, 16, "\x00");
			return (($ipBin & $mask) === ($subnetBin & $mask));
		}//end if

		if (filter_var(value: $ipAddress, filter: FILTER_VALIDATE_IP, options: FILTER_FLAG_IPV4) !== false
			&& filter_var(value: $subnet, filter: FILTER_VALIDATE_IP, options: FILTER_FLAG_IPV4) !== false
		) {
			$ipLong = ip2long($ipAddress);
			$subnetLong = ip2long($subnet);
			$maskLong = (~0 << (32 - (int)$bits));
			return (($ipLong & $maskLong) === ($subnetLong & $maskLong));
		}

		return false;
	}//end ipInRange()
}//end class
