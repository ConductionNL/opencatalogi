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
 * @spec openspec/changes/retrofit-2026-05-25-annotate-opencatalogi/tasks.md#task-47
 * @spec openspec/changes/retrofit-2026-05-25-annotate-opencatalogi/tasks.md#task-59
 */

namespace OCA\OpenCatalogi\Service;

use DateTime;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use RuntimeException;
use InvalidArgumentException;

/**
 * BroadcastService Class.
 *
 * This class provides functionality to broadcast this OpenCatalogi directory to other instances.
 * It allows for broadcasting to a specific URL or to all known directories.
 * The service uses dynamic versioning in User-Agent headers for proper identification.
 *
 * @spec openspec/changes/retrofit-2026-05-25-annotate-opencatalogi/tasks.md#task-47
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class BroadcastService
{

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
     * @param IURLGenerator      $urlGenerator URL generator interface
     * @param ContainerInterface $container    Server container for dependency injection
     * @param IAppManager        $appManager   App manager for checking installed apps
     * @param LoggerInterface    $logger       Logger for recording broadcast activities
     * @param IAppConfig         $config       App configuration (local-federation allowlist)
     */
    public function __construct(
        private readonly IURLGenerator $urlGenerator,
        private readonly ContainerInterface $container,
        private readonly IAppManager $appManager,
        private readonly LoggerInterface $logger,
        private readonly IAppConfig $config,
    ) {
        // Initialize HTTP client; the per-request timeout is operator-tunable.
        $this->client = new Client(
            config: [
                'timeout'         => $this->getRequestTimeout(),
                'connect_timeout' => 10,
                'verify'          => true,
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
    private function getMaxRetries(): int
    {
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
    private function getRequestTimeout(): int
    {
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
     * @throws \RuntimeException                       When OpenRegister service is not available
     * @throws ContainerExceptionInterface             When container access fails
     * @throws NotFoundExceptionInterface              When service is not found in container
     */
    private function getObjectService(): \OCA\OpenRegister\Service\ObjectService
    {
        // Check if OpenRegister app is installed and enabled.
        if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
            try {
                // Attempt to retrieve the ObjectService from the container.
                return $this->container->get('OCA\OpenRegister\Service\ObjectService');
            } catch (ContainerExceptionInterface | NotFoundExceptionInterface $e) {
                // Log the container error for debugging.
                $this->logger->error('Failed to retrieve OpenRegister ObjectService from container: '.$e->getMessage());
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
    private function getAppVersion(): string
    {
        try {
            // Get the app version from the app manager.
            $appInfo = $this->appManager->getAppInfo($this->appName);
            return ($appInfo['version'] ?? 'unknown');
        } catch (\Exception $e) {
            // Log the error and return a fallback version.
            $this->logger->warning('Failed to retrieve app version: '.$e->getMessage());
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
     * @spec openspec/changes/retrofit-2026-05-25-annotate-opencatalogi/tasks.md#task-47
     */
    private function getCurrentDirectoryUrl(): string
    {
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
     * @throws DoesNotExistException              When required data is not found
     * @throws MultipleObjectsReturnedException   When duplicate objects are found
     * @throws ContainerExceptionInterface        When container access fails
     * @throws NotFoundExceptionInterface         When service is not found in container
     *
     * @spec openspec/changes/retrofit-2026-05-25-annotate-opencatalogi/tasks.md#task-59
     */
    private function getDirectoryUrls(): array
    {
        try {
            // Get listing configuration; without it there are no listings to read.
            $listingSchema   = $this->config->getValueString($this->appName, 'listing_schema', '');
            $listingRegister = $this->config->getValueString($this->appName, 'listing_register', '');
            if (empty($listingSchema) === true || empty($listingRegister) === true) {
                return [];
            }//end if

            // Retrieve all listing objects from OpenRegister via the canonical search API.
            $query = [
                '@self' => [
                    'register' => $listingRegister,
                    'schema'   => $listingSchema,
                ],
            ];

            // Directory data is public by design; RBAC is disabled for discovery (see ADR-002).
            $listings = $this->getObjectService()->searchObjects($query, _rbac: false);

            // Extract directory URLs from each listing's object data.
            $directoryUrls = [];
            foreach ($listings as $listing) {
                $listingData = $listing->jsonSerialize();
                $objectData  = ($listingData['object'] ?? $listingData);
                $url         = ($objectData['directory'] ?? null);
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
            $this->logger->error('Failed to retrieve directory URLs: '.$e->getMessage());
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
     * @param string $url          The target URL to broadcast to
     * @param string $directoryUrl The URL of this directory to include in broadcast
     *
     * @return boolean True if broadcast was successful, false otherwise
     *
     * @spec openspec/changes/retrofit-2026-05-25-annotate-opencatalogi/tasks.md#task-59
     */
    private function sendBroadcastRequest(string $url, string $directoryUrl): bool
    {
        $attempt    = 0;
        $startTime  = time();
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

            try {
                // Send POST request with directory URL payload.
                // SSRF hardening (WF4 / wave-12): disable automatic redirect following so that a
                // broadcast target cannot redirect to cloud-metadata. The pre-flight
                // assertSafeOutboundUrl() validates the initial URL; disabling redirects ensures
                // Guzzle never silently follows a 302 to an unvalidated host.
                $response = $this->client->post(
                    uri: $url,
                    options: [
                        'json'    => [
                            'directory' => $directoryUrl,
                            'timestamp' => (new DateTime())->format('c'),
                            'source'    => $this->appName,
                        ],
                        'headers' => [
                            'User-Agent'   => 'OpenCatalogi-Broadcast/'.$this->getAppVersion(),
                            'Content-Type' => 'application/json',
                        ],
                        RequestOptions::ALLOW_REDIRECTS => false,
                    ]
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
                $this->logger->warning("Broadcast attempt {$attempt} to {$url} failed: ".$e->getMessage());

                // If this was the last attempt, log as error and stop.
                if ($attempt === $maxRetries) {
                    $this->logger->error(
                        "All {$attempt} broadcast attempts to {$url} failed. Final error: ".$e->getMessage()
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
     * @throws DoesNotExistException              When required data is not found
     * @throws MultipleObjectsReturnedException   When duplicate objects are found
     * @throws ContainerExceptionInterface        When container access fails
     * @throws NotFoundExceptionInterface         When service is not found in container
     *
     * @spec openspec/changes/retrofit-2026-05-25-annotate-opencatalogi/tasks.md#task-59
     */
    public function broadcast(?string $url=null): array
    {
        // Get the URL of this directory to include in broadcast payload.
        $directoryUrl = $this->getCurrentDirectoryUrl();

        // Initialize results array to track success and failure per URL.
        $results = [];

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
            return $results;
        }

        // Log the start of broadcast operation.
        $this->logger->info('Starting broadcast to '.count($targetUrls).' target(s)');

        // Broadcast to each target URL.
        foreach ($targetUrls as $targetUrl) {
            // Skip broadcasting to self to avoid loops.
            if ($targetUrl === $directoryUrl) {
                $this->logger->debug("Skipping broadcast to self: {$targetUrl}");
                continue;
            }

            // Validate outbound URL before broadcasting — rejects private/internal addresses.
            try {
                $this->assertSafeOutboundUrl($targetUrl);
            } catch (InvalidArgumentException $e) {
                $this->logger->warning("Skipping unsafe broadcast target: {$targetUrl}");
                $results[$targetUrl] = false;
                continue;
            }

            // Attempt to send broadcast request.
            $success = $this->sendBroadcastRequest($targetUrl, $directoryUrl);
            $results[$targetUrl] = $success;
        }

        // Log summary of broadcast operation.
        $successCount = count(array_filter($results));
        $totalCount   = count($results);
        $this->logger->info("Broadcast completed: {$successCount}/{$totalCount} successful");

        return $results;

    }//end broadcast()

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
    private function isAllowlistedFederationHost(string $host): bool
    {
        $allowlist = $this->config->getValueString($this->appName, 'local_federation_hosts', '');
        if ($allowlist === '') {
            return false;
        }

        $allowedHosts = array_filter(array_map('trim', explode(',', strtolower($allowlist))));

        return in_array($host, $allowedHosts, true);

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
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    private function assertSafeOutboundUrl(string $url): void
    {
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
        if ($this->isAllowlistedFederationHost($host) === true) {
            return;
        }

        // Reject obvious local hostnames outright.
        if ($host === 'localhost' || str_ends_with($host, '.local') === true
            || str_ends_with($host, '.localhost') === true
        ) {
            throw new InvalidArgumentException('Broadcast URL host is not allowed');
        }

        // Collect IPs to check: literal IP or DNS-resolved addresses.
        $ipsToCheck = [];
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $ipsToCheck[] = $host;
        }

        if (empty($ipsToCheck) === true) {
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

                if (empty($ipsToCheck) === true) {
                    $resolved = gethostbyname($lookupHost);
                    if ($resolved !== $lookupHost && filter_var($resolved, FILTER_VALIDATE_IP) !== false) {
                        $ipsToCheck[] = $resolved;
                    }
                }
            }//end if
        }//end if

        if (empty($ipsToCheck) === true) {
            throw new InvalidArgumentException('Broadcast URL host could not be resolved');
        }

        foreach ($ipsToCheck as $ipAddress) {
            if ($this->isBlockedIp($ipAddress) === true) {
                throw new InvalidArgumentException('Broadcast URL resolves to a disallowed (internal) address');
            }
        }

    }//end assertSafeOutboundUrl()

    /**
     * Determine whether an IP address falls in a blocked range.
     *
     * Checks private, loopback, link-local, and reserved ranges.
     *
     * @param string $ipAddress The IP address to check.
     *
     * @return boolean True if the IP is blocked, false if it is safe.
     */
    private function isBlockedIp(string $ipAddress): bool
    {
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
            if ($this->ipInRange($ipAddress, $range) === true) {
                return true;
            }
        }

        return false;

    }//end isBlockedIp()

    /**
     * Check if an IP address falls within a CIDR range.
     *
     * @param string $ipAddress The IP address to check.
     * @param string $range     The CIDR range (e.g. 10.0.0.0/8).
     *
     * @return boolean True if the IP is in the range, false otherwise.
     */
    private function ipInRange(string $ipAddress, string $range): bool
    {
        [$subnet, $bits] = explode('/', $range);

        if (filter_var(value: $ipAddress, filter: FILTER_VALIDATE_IP, options: FILTER_FLAG_IPV6) !== false
            && filter_var(value: $subnet, filter: FILTER_VALIDATE_IP, options: FILTER_FLAG_IPV6) !== false
        ) {
            $ipBin     = inet_pton($ipAddress);
            $subnetBin = inet_pton($subnet);
            if ($ipBin === false || $subnetBin === false) {
                return false;
            }

            $mask = str_repeat("\xff", intdiv((int) $bits, 8));
            if (((int) $bits % 8) !== 0) {
                $mask .= chr(0xff & (0xff << (8 - ((int) $bits % 8))));
            }

            $mask = str_pad($mask, 16, "\x00");
            return (($ipBin & $mask) === ($subnetBin & $mask));
        }//end if

        if (filter_var(value: $ipAddress, filter: FILTER_VALIDATE_IP, options: FILTER_FLAG_IPV4) !== false
            && filter_var(value: $subnet, filter: FILTER_VALIDATE_IP, options: FILTER_FLAG_IPV4) !== false
        ) {
            $ipLong     = ip2long($ipAddress);
            $subnetLong = ip2long($subnet);
            $maskLong   = (~0 << (32 - (int) $bits));
            return (($ipLong & $maskLong) === ($subnetLong & $maskLong));
        }

        return false;

    }//end ipInRange()
}//end class
