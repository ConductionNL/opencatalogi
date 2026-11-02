<?php

/**
 * Contract of SettingsService, for callers in other components.
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

use RuntimeException;

/**
 * Contract of SettingsService, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 */
interface SettingsServiceInterface {
	/**
	 * This constant defines the minimum version of the OpenRegister
	 * application that is required for compatibility and
	 * functionality.
	 *
	 * @var string $minORVersion The minimum required version of OpenRegister.
	 */
	public const MIN_OPENREGISTER_VERSION = '0.1.7';

	/**
	 * Minimum allowed federation sync interval in seconds (15 minutes).
	 *
	 * The interval bounds live here rather than on the cron job because this is
	 * the class that PUBLISHES them (getSyncOptions) and ENFORCES them
	 * (updateSyncOptions clamps a submitted value against them). Holding them on
	 * DirectorySync meant a service referenced a cron class purely to read three
	 * numbers — which pushed this class over PHPMD's coupling threshold — and
	 * left the same clamp expressed in two places. DirectorySync now aliases
	 * these, so `self::MIN_INTERVAL_SECONDS` still resolves.
	 *
	 * @var integer
	 */
	public const MIN_INTERVAL_SECONDS = 900;

	/**
	 * Maximum allowed federation sync interval in seconds (24 hours).
	 *
	 * @var integer
	 */
	public const MAX_INTERVAL_SECONDS = 86400;

	/**
	 * Default federation sync interval in seconds (1 hour).
	 *
	 * @var integer
	 */
	public const DEFAULT_INTERVAL_SECONDS = 3600;

	/**
	 * The longest time, in seconds, a public API answer may be cached (REQ-PAC-003).
	 *
	 * The settings save clamps to it and AnswersCacheably caps the headers at it,
	 * also for a value set with occ.
	 *
	 * @var integer
	 */
	public const PUBLIC_API_CACHE_MAX_SECONDS = 3600;

	/**
	 * App config key holding what a register event can still complete in a catalogue scope.
	 *
	 * A JSON list of register slugs not resolved yet, plus the ids of resolved
	 * registers whose catalogue still names a schema by slug. Written by
	 * backfillCatalogScopes(), read by CatalogScopePendingListener.
	 */
	public const CATALOG_SCOPE_PENDING_KEY = 'catalog_scope_pending';

	/**
	 * Checks if OpenRegister is installed and meets version requirements.
	 *
	 * @param string|null $minVersion Minimum required version (e.g. '1.0.0').
	 *
	 * @return boolean True if OpenRegister is installed and meets version requirements.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function isOpenRegisterInstalled(?string $minVersion = self::MIN_OPENREGISTER_VERSION): bool;

	/**
	 * Checks if OpenRegister is enabled.
	 *
	 * @return boolean True if OpenRegister is enabled.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function isOpenRegisterEnabled(): bool;

	/**
	 * Attempts to make a compatible OpenRegister available.
	 *
	 * Uses only the public app manager. An OpenRegister that is installed but
	 * disabled is enabled with IAppManager::enableApp(). Downloading/installing
	 * an app from the App Store or upgrading an installed one has no public API,
	 * so a missing or too-old OpenRegister yields a RuntimeException that tells
	 * the administrator what to do instead.
	 *
	 * This used to call \OC_App::installApp(), \OC_App::enable() and
	 * \OC_App::updateApp(). Those are private API and installApp()/enable() do
	 * not exist on Nextcloud 34 or 35, so the call ended in an \Error that the
	 * `catch (\Exception)` below could not catch.
	 *
	 * @param string|null $minVersion Minimum required version.
	 *
	 * @return boolean True when a compatible OpenRegister is enabled.
	 * @throws \RuntimeException If OpenRegister is missing, too old or cannot be enabled.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function installOrUpdateOpenRegister(?string $minVersion = self::MIN_OPENREGISTER_VERSION): bool;

	/**
	 * Attempts to auto-configure registers and schemas.
	 *
	 * @return array The updated configuration.
	 * @throws \RuntimeException If auto-configuration fails.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function autoConfigure(): array;

	/**
	 * Initializes the app with all required components.
	 *
	 * @param string|null $minORVersion Minimum required OpenRegister version.
	 *
	 * @return array The initialization results.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function initialize(?string $minORVersion = self::MIN_OPENREGISTER_VERSION): array;

	/**
	 * Attempts to retrieve the OpenRegister service from the container.
	 *
	 * @return \OCA\OpenRegister\Service\ObjectService|null The OpenRegister service if available, null otherwise.
	 * @throws \RuntimeException If the service is not available.
	 *
	 * @spec exclude Lazy dependency-injection accessor — resolves the OpenRegister
	 *       ObjectService from the container; pure framework plumbing, no domain behavior.
	 */
	public function getObjectService(): ?\OCA\OpenRegister\Service\ObjectService;

	/**
	 * Attempts to retrieve the RegisterMapper from the container.
	 *
	 * @return \OCA\OpenRegister\Db\RegisterMapper|null The RegisterMapper if available, null otherwise.
	 * @throws \RuntimeException If the service is not available.
	 *
	 * @spec exclude Lazy dependency-injection accessor — resolves the OpenRegister
	 *       RegisterMapper from the container; pure framework plumbing, no domain behavior.
	 */
	public function getRegisterMapper(): ?\OCA\OpenRegister\Db\RegisterMapper;

	/**
	 * Attempts to retrieve the Schema mapper from the container.
	 *
	 * @return \OCA\OpenRegister\Db\SchemaMapper|null The Schema mapper if available, null otherwise.
	 * @throws \RuntimeException If the mapper is not available.
	 *
	 * @spec exclude Lazy dependency-injection accessor — resolves the OpenRegister
	 *       SchemaMapper from the container; pure framework plumbing, no domain behavior.
	 */
	public function getSchemaMapper(): ?\OCA\OpenRegister\Db\SchemaMapper;

	/**
	 * Attempts to retrieve the Configuration service from the container.
	 *
	 * @return \OCA\OpenRegister\Service\ConfigurationService|null The Configuration service if available, null otherwise.
	 * @throws \RuntimeException If the service is not available.
	 *
	 * @spec exclude Lazy dependency-injection accessor — resolves the OpenRegister
	 *       ConfigurationService from the container; pure framework plumbing, no domain behavior.
	 */
	public function getConfigurationService(): ?\OCA\OpenRegister\Service\ConfigurationService;

	/**
	 * Retrieve the current settings.
	 *
	 * @return array The current settings configuration.
	 * @throws \RuntimeException If settings retrieval fails.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function getSettings(): array;

	/**
	 * Update the settings configuration.
	 *
	 * Only keys that belong to the canonical settings allowlist (the same set
	 * enumerated in getSettings()) are accepted.  Any unrecognised key in $data
	 * is silently ignored, preventing arbitrary app-config key injection.
	 *
	 * @param array $data The settings data to update.
	 *
	 * @return array The updated settings configuration.
	 * @throws \RuntimeException If settings update fails.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function updateSettings(array $data): array;

	/**
	 * Get the current publishing options.
	 *
	 * @return array The current publishing options configuration.
	 * @throws \RuntimeException If publishing options retrieval fails.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function getPublishingOptions(): array;

	/**
	 * Update the publishing options configuration.
	 *
	 * @param array $options The publishing options data to update.
	 *
	 * @return array The updated publishing options configuration.
	 * @throws \RuntimeException If publishing options update fails.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function updatePublishingOptions(array $options): array;

	/**
	 * Get the current federation sync options.
	 *
	 * Returns the configured sync interval and the min/max/default bounds so the
	 * frontend can render its input constraints from a single source of truth
	 * (the MIN/MAX/DEFAULT_INTERVAL_SECONDS constants on this class).
	 *
	 * @return array<string, int> The current sync options: sync_interval_seconds + min/max/default bounds.
	 * @throws \RuntimeException If sync options retrieval fails.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function getSyncOptions(): array;

	/**
	 * Update the federation sync options configuration.
	 *
	 * The interval is clamped to [MIN_INTERVAL_SECONDS, MAX_INTERVAL_SECONDS]
	 * before it is stored, so a malformed input can never disable the guard.
	 *
	 * @param array $options The sync options data to update. Only `sync_interval_seconds` is honoured.
	 *
	 * @return array The persisted sync options (post-clamp).
	 * @throws \RuntimeException If sync options update fails.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function updateSyncOptions(array $options): array;

	/**
	 * Load settings from the publication_register.json file.
	 *
	 * This method supports both old and new versions of OpenRegister:
	 * - New version (>= 0.2.10): Uses importFromFilePath method.
	 * - Old version (< 0.2.10): Uses importFromApp method with manual file reading.
	 *
	 * @param boolean $force Whether to force the import regardless of version checks.
	 *
	 * @return array The loaded settings configuration.
	 * @throws \RuntimeException If settings loading fails.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 */
	public function loadSettings(bool $force = false): array;

	/**
	 * Ensure every local catalog has a non-empty registers/schemas scope.
	 *
	 * `PublicationService::getCatalogFilters()` derives its visibility scope by
	 * iterating every local catalog and unioning their `registers` + `schemas`
	 * arrays. A catalog with both arrays unset contributes nothing, and with
	 * only one such catalog the entire union is empty — no publications are
	 * ever visible to /search or the create-publication modal (surfaces as the
	 * WOO-527 "not configured" empty state).
	 *
	 * The default "Publications" catalog seeded via publication_register.json
	 * (see WOO-529) exhibits this because the numeric register/schema IDs are
	 * only resolved AFTER importFromApp() completes, so the seed cannot
	 * hard-code them. This method runs after updateObjectTypeConfiguration()
	 * has stored the resolved IDs, walks the catalog collection, and patches
	 * any catalog missing a scope with `[publication_register]` / `[publication_schema]`.
	 *
	 * Idempotent — a catalog that already has a non-empty array is skipped so
	 * admin-configured multi-register catalogs are never touched.
	 *
	 * Public because CatalogScopePendingListener runs it again when a register a
	 * seeded catalogue names by slug appears later (stackiq installed after
	 * OpenCatalogi).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/publish-from-stackiq/specs/publish-from-stackiq/spec.md#requirement-req-pfs-004-installing-stackiq-after-opencatalogi-completes-the-scope
	 */
	public function backfillCatalogScopes(): void;

	/**
	 * Get version information for the app and configuration.
	 *
	 * This method returns version information including the current app version
	 * and the stored configuration version in OpenRegister.
	 *
	 * @return array Version information with app and configuration versions.
	 * @throws \RuntimeException If version retrieval fails.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function getVersionInfo(): array;

	/**
	 * Manually trigger configuration import from JSON.
	 *
	 * This method allows system administrators to manually trigger the import
	 * process, bypassing version checks.
	 *
	 * @param boolean $forceImport Whether to force import regardless of version.
	 *
	 * @return array The import results with success/error information.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 */
	public function manualImport(bool $forceImport = false): array;
}//end interface
