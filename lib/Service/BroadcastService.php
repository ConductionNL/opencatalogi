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
use OCA\OpenCatalogi\Service\Broadcast\BroadcastAttempt;
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
use Psr\Http\Message\ResponseInterface;
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

			// Directory data is public by design; RBAC is disabled for discovery (see ADR-002).
			$listings = $this->getObjectService()->searchObjects(
				['@self' => ['register' => $listingRegister, 'schema' => $listingSchema]],
				_rbac: false
			);

			return $this->uniqueDirectoryUrls(listings: $listings);
		} catch (\Exception $e) {
			// Log the error and re-throw for caller handling.
			$this->logger->error('Failed to retrieve directory URLs: ' . $e->getMessage());
			throw $e;
		}//end try

	}//end getDirectoryUrls()

	/**
	 * The unique, valid directory URLs the listings name, each under the
	 * position of its first listing.
	 *
	 * @param iterable<object> $listings The listing objects.
	 *
	 * @return array<int, string> The directory URLs.
	 */
	private function uniqueDirectoryUrls(iterable $listings): array {
		$directoryUrls = [];
		foreach ($listings as $listing) {
			$listingData = $listing->jsonSerialize();
			$objectData = ($listingData['object'] ?? $listingData);
			$url = ($objectData['directory'] ?? null);
			if (empty($url) === false) {
				$directoryUrls[] = $url;
			}
		}

		// Filter to unique, valid URLs.
		return array_filter(
			array_unique($directoryUrls),
			function ($url) {
				return empty($url) === false && filter_var($url, FILTER_VALIDATE_URL) !== false;
			}
		);
	}//end uniqueDirectoryUrls()

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
		$run = new BroadcastAttempt();
		$run->url = $url;
		$startTime = time();
		$run->maxRetries = $this->getMaxRetries();

		// Retry loop with wall-clock cap to prevent indefinite blocking.
		while ($run->attempt < $run->maxRetries) {
			if ($this->wallTimeSpent(run: $run, startTime: $startTime) === true) {
				return false;
			}

			$run->attempt++;

			try {
				// SSRF hardening (WF4 / wave-12): disable automatic redirect following so that a
				// broadcast target cannot redirect to cloud-metadata. The pre-flight
				// assertSafeOutboundUrl() validates the initial URL; disabling redirects ensures
				// Guzzle never silently follows a 302 to an unvalidated host.
				$response = $this->postDirectory(url: $url, directoryUrl: $directoryUrl);
				if ($this->accepted(run: $run, response: $response) === true) {
					return true;
				}
			} catch (GuzzleException $e) {
				if ($this->mayRetry(run: $run, exception: $e) === false) {
					return false;
				}
			}
		}//end while

		return false;
	}//end sendBroadcastRequest()

	/**
	 * Whether the per-target wall-time budget is spent; logged when it is.
	 *
	 * @param BroadcastAttempt $run The target and its attempts.
	 * @param int $startTime When the first attempt started.
	 *
	 * @return bool
	 */
	private function wallTimeSpent(BroadcastAttempt $run, int $startTime): bool {
		if ((time() - $startTime) < self::MAX_RETRY_WALL_SECONDS) {
			return false;
		}

		$this->logger->warning(
			"[BroadcastService] Wall-time cap reached for {$run->url}; aborting retries after {$run->attempt} attempt(s)"
		);
		return true;
	}//end wallTimeSpent()

	/**
	 * Whether the target accepted the broadcast with a 2xx; either way logged.
	 *
	 * @param BroadcastAttempt $run The target and its attempts.
	 * @param ResponseInterface $response The target's answer.
	 *
	 * @return bool
	 */
	private function accepted(BroadcastAttempt $run, ResponseInterface $response): bool {
		if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
			$this->logger->info("Successfully broadcasted to {$run->url} on attempt {$run->attempt}");
			return true;
		}

		$this->logger->warning(
			"Broadcast to {$run->url} returned status {$response->getStatusCode()} on attempt {$run->attempt}"
		);
		return false;
	}//end accepted()

	/**
	 * Log a failed attempt; after the last one log the final error and stop,
	 * otherwise wait (exponential backoff) and try again.
	 *
	 * @param BroadcastAttempt $run The target and its attempts.
	 * @param GuzzleException $exception Why the attempt failed.
	 *
	 * @return bool True when another attempt follows.
	 */
	private function mayRetry(BroadcastAttempt $run, GuzzleException $exception): bool {
		$this->logger->warning("Broadcast attempt {$run->attempt} to {$run->url} failed: " . $exception->getMessage());

		if ($run->attempt === $run->maxRetries) {
			$this->logger->error(
				"All {$run->attempt} broadcast attempts to {$run->url} failed. Final error: " . $exception->getMessage()
			);
			return false;
		}

		sleep($run->attempt * 2);
		return true;
	}//end mayRetry()

	/**
	 * POST this directory's URL to one broadcast target, without following redirects.
	 *
	 * @param string $url The target URL to broadcast to.
	 * @param string $directoryUrl The URL of this directory to include in the broadcast.
	 *
	 * @return ResponseInterface The target's answer.
	 *
	 * @throws GuzzleException When the request fails.
	 */
	private function postDirectory(string $url, string $directoryUrl): ResponseInterface {
		return $this->client->post(
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
			]
		);
	}//end postDirectory()

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
			$this->refuseLocalBroadcast(directoryUrl: $directoryUrl);
			return $results;
		}

		$targetUrls = $this->broadcastTargets(url: $url);

		// If no target URLs found, log warning and return empty results.
		if (empty($targetUrls) === true) {
			$this->logger->warning('No target URLs found for broadcasting');
			$this->connectionReporter?->reportBroadcast(results: $results);
			return $results;
		}

		return $this->broadcastTo(targetUrls: $targetUrls, directoryUrl: $directoryUrl);
	}//end broadcast()

	/**
	 * Log and report a broadcast refused because this instance advertises a
	 * local address.
	 *
	 * @param string $directoryUrl The address this instance would advertise.
	 *
	 * @return void
	 */
	private function refuseLocalBroadcast(string $directoryUrl): void {
		$this->logger->warning(
			'Refusing to broadcast: this instance advertises a local address (' . $directoryUrl
			. '). Set overwrite.cli.url to a URL peers can resolve, or add the host to '
			. 'the opencatalogi `local_federation_hosts` allowlist for a local rig.'
		);
		$this->connectionReporter?->reportBroadcastFromLocalAddress();
	}//end refuseLocalBroadcast()

	/**
	 * The URLs to broadcast to: the given one, or else every known directory.
	 * The known directories are read either way.
	 *
	 * @param string|null $url The one instance to broadcast to, or null for all.
	 *
	 * @return array<string> The target URLs.
	 *
	 * @throws InvalidArgumentException When the given URL is not a URL.
	 */
	private function broadcastTargets(?string $url): array {
		$targetUrls = $this->getDirectoryUrls();
		if ($url !== null) {
			// Validate the provided URL.
			if (filter_var($url, FILTER_VALIDATE_URL) === false) {
				throw new InvalidArgumentException("Invalid URL provided for broadcast: {$url}");
			}

			$targetUrls = [$url];
		}

		return $targetUrls;
	}//end broadcastTargets()

	/**
	 * Broadcast this directory to every target but itself, then log and
	 * report the outcome.
	 *
	 * @param array<string> $targetUrls The target URLs.
	 * @param string $directoryUrl The URL of this directory.
	 *
	 * @return array<string, bool> Each target URL and whether it accepted the broadcast.
	 */
	private function broadcastTo(array $targetUrls, string $directoryUrl): array {
		$this->logger->info('Starting broadcast to ' . count($targetUrls) . ' target(s)');

		$results = [];
		foreach ($targetUrls as $targetUrl) {
			// Skip broadcasting to self to avoid loops.
			if ($targetUrl === $directoryUrl) {
				$this->logger->debug("Skipping broadcast to self: {$targetUrl}");
				continue;
			}

			$results[$targetUrl] = $this->deliverTo(targetUrl: $targetUrl, directoryUrl: $directoryUrl);
		}

		// Log summary of broadcast operation.
		$successCount = count(array_filter($results));
		$totalCount = count($results);
		$this->logger->info("Broadcast completed: {$successCount}/{$totalCount} successful");
		$this->connectionReporter?->reportBroadcast(results: $results);

		return $results;
	}//end broadcastTo()

	/**
	 * Validate one outbound URL and deliver via the enqueueBroadcast adapter
	 * (SSRF pre-flight guard, then the synchronous retry loop).
	 *
	 * @param string $targetUrl The target URL.
	 * @param string $directoryUrl The URL of this directory.
	 *
	 * @return bool Whether the target accepted the broadcast; false for an unsafe target.
	 */
	private function deliverTo(string $targetUrl, string $directoryUrl): bool {
		try {
			return $this->enqueueBroadcast(url: $targetUrl, directoryUrl: $directoryUrl)->isSuccessful();
		} catch (InvalidArgumentException $e) {
			$this->logger->warning("Skipping unsafe broadcast target: {$targetUrl}");
			return false;
		}
	}//end deliverTo()

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
		$host = $this->outboundHost(url: $url);

		// Dev-only: explicitly allowlisted hosts skip the local/private-address guard so
		// two local instances on a private network can federate. Empty by default, so a
		// production instance keeps the full SSRF protection below.
		if ($this->isAllowlistedFederationHost(host: $host) === true) {
			return;
		}

		$this->assertNotLocalName(host: $host);

		$ipsToCheck = $this->addressesOf(host: $host);
		if (empty($ipsToCheck) === true) {
			throw new InvalidArgumentException('Broadcast URL host could not be resolved');
		}

		foreach ($ipsToCheck as $ipAddress) {
			if ($this->isBlockedIp(ipAddress: $ipAddress) === true) {
				throw new InvalidArgumentException('Broadcast URL resolves to a disallowed (internal) address');
			}
		}

	}//end assertSafeOutboundUrl()

	/**
	 * The lower-cased host of an http or https URL.
	 *
	 * @param string $url The URL to validate.
	 *
	 * @return string The host.
	 *
	 * @throws InvalidArgumentException When the URL has no scheme or host, or another scheme.
	 */
	private function outboundHost(string $url): string {
		$parsed = parse_url($url);
		if ($parsed === false || isset($parsed['scheme']) === false || isset($parsed['host']) === false) {
			throw new InvalidArgumentException('Invalid broadcast URL provided');
		}

		if (in_array(strtolower($parsed['scheme']), ['http', 'https'], true) === false) {
			throw new InvalidArgumentException('Broadcast URL scheme must be http or https');
		}

		return strtolower($parsed['host']);
	}//end outboundHost()

	/**
	 * Reject obvious local hostnames outright.
	 *
	 * @param string $host The lower-cased host.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the host is localhost or under .local or .localhost.
	 */
	private function assertNotLocalName(string $host): void {
		if ($host === 'localhost' || str_ends_with($host, '.local') === true
			|| str_ends_with($host, '.localhost') === true
		) {
			throw new InvalidArgumentException('Broadcast URL host is not allowed');
		}
	}//end assertNotLocalName()

	/**
	 * The IPs to check for a host: the literal IP (bracketed or not), else its
	 * DNS A/AAAA records, else what gethostbyname() resolves it to.
	 *
	 * @param string $host The lower-cased host.
	 *
	 * @return array<int, string> The addresses; none when the host does not resolve.
	 */
	private function addressesOf(string $host): array {
		if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
			return [$host];
		}

		$lookupHost = trim($host, '[]');
		if (filter_var($lookupHost, FILTER_VALIDATE_IP) !== false) {
			return [$lookupHost];
		}

		$ipsToCheck = $this->dnsAddresses(lookupHost: $lookupHost);
		if (empty($ipsToCheck) === false) {
			return $ipsToCheck;
		}

		return $this->resolvedAddress(lookupHost: $lookupHost);
	}//end addressesOf()

	/**
	 * The IPv4 and IPv6 addresses of a host's DNS A/AAAA records, in record order.
	 *
	 * @param string $lookupHost The host to look up.
	 *
	 * @return array<int, string> The addresses.
	 */
	private function dnsAddresses(string $lookupHost): array {
		$ipsToCheck = [];
		$records = dns_get_record($lookupHost, (DNS_A | DNS_AAAA));
		if ($records === false) {
			return $ipsToCheck;
		}

		foreach ($records as $record) {
			if (isset($record['ip']) === true) {
				$ipsToCheck[] = $record['ip'];
			}

			if (isset($record['ipv6']) === true) {
				$ipsToCheck[] = $record['ipv6'];
			}
		}

		return $ipsToCheck;
	}//end dnsAddresses()

	/**
	 * The address gethostbyname() resolves a host to, or none when it does not resolve to an IP.
	 *
	 * @param string $lookupHost The host to look up.
	 *
	 * @return array<int, string> The address, or none.
	 */
	private function resolvedAddress(string $lookupHost): array {
		$resolved = gethostbyname($lookupHost);
		if ($resolved !== $lookupHost && filter_var($resolved, FILTER_VALIDATE_IP) !== false) {
			return [$resolved];
		}

		return [];
	}//end resolvedAddress()

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
		[$subnet] = explode('/', $range);

		if ($this->isIpOfKind(ipAddress: $ipAddress, flag: FILTER_FLAG_IPV6) === true
			&& $this->isIpOfKind(ipAddress: $subnet, flag: FILTER_FLAG_IPV6) === true
		) {
			return $this->ipv6InRange(ipAddress: $ipAddress, range: $range);
		}

		if ($this->isIpOfKind(ipAddress: $ipAddress, flag: FILTER_FLAG_IPV4) === true
			&& $this->isIpOfKind(ipAddress: $subnet, flag: FILTER_FLAG_IPV4) === true
		) {
			return $this->ipv4InRange(ipAddress: $ipAddress, range: $range);
		}

		return false;
	}//end ipInRange()

	/**
	 * Whether a value is an IP address of one family.
	 *
	 * @param string $ipAddress The value.
	 * @param int $flag FILTER_FLAG_IPV4 or FILTER_FLAG_IPV6.
	 *
	 * @return bool
	 */
	private function isIpOfKind(string $ipAddress, int $flag): bool {
		return filter_var(value: $ipAddress, filter: FILTER_VALIDATE_IP, options: $flag) !== false;
	}//end isIpOfKind()

	/**
	 * Whether an IPv6 address falls within an IPv6 CIDR range.
	 *
	 * @param string $ipAddress The IPv6 address.
	 * @param string $range The IPv6 CIDR range.
	 *
	 * @return bool
	 */
	private function ipv6InRange(string $ipAddress, string $range): bool {
		[$subnet, $bits] = explode('/', $range);
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
	}//end ipv6InRange()

	/**
	 * Whether an IPv4 address falls within an IPv4 CIDR range.
	 *
	 * @param string $ipAddress The IPv4 address.
	 * @param string $range The IPv4 CIDR range.
	 *
	 * @return bool
	 */
	private function ipv4InRange(string $ipAddress, string $range): bool {
		[$subnet, $bits] = explode('/', $range);
		$ipLong = ip2long($ipAddress);
		$subnetLong = ip2long($subnet);
		$maskLong = (~0 << (32 - (int)$bits));
		return (($ipLong & $maskLong) === ($subnetLong & $maskLong));
	}//end ipv4InRange()
}//end class
