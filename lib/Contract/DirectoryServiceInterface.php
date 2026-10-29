<?php

/**
 * Contract of DirectoryService, for callers in other components.
 *
 * @category Contract
 * @package  OCA\OpenCatalogi\Contract
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Contract;

use GuzzleHttp\Exception\GuzzleException;
use InvalidArgumentException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

/**
 * Contract of DirectoryService, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 */
interface DirectoryServiceInterface {
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
	public function getDefaultDirectoryUrl(): string;

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
	 * @spec openspec/specs/dashboard/spec.md
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-oc-conn-003-opencatalogi-reports-what-a-sync-a-broadcast-and-a-readiness-check-met
	 */
	public function doCronSync(): array;

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
	 * @spec openspec/specs/dashboard/spec.md
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 */
	public function getUniqueDirectories(bool $availableOnly = false, bool $defaultOnly = false): array;

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
	public function getKnownDirectoryUrls(): array;

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
	public function syncDirectory(string $directoryUrl): array;

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
	public function syncListing(array $listingData, string $sourceDirectoryUrl): array;

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
	 * @spec openspec/specs/federation/spec.md
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 */
	public function getPublications(array $guzzleConfig = [], bool $includeDefault = false): array;

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
	public function validateOutboundUrl(string $url): void;

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
	public function getUsed(string $uuid, array $guzzleConfig = []): array;

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
	public function getPublication(string $publicationId, array $guzzleConfig = []): ?array;

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
	public function getDirectory(array $requestParams = []): array;

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
	public function convertCatalogiToListings(array $catalogs): array;
}//end interface
