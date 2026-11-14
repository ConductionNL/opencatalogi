<?php

/**
 * OpenCatalogi Settings Service
 *
 * This file contains the service class for handling settings in the OpenCatalogi application.
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
 * @spec openspec/specs/admin-settings/spec.md
 * @spec openspec/specs/admin-settings/spec.md
 * @spec openspec/specs/admin-settings/spec.md
 * @spec openspec/specs/admin-settings/spec.md
 * @spec openspec/specs/admin-settings/spec.md
 * @spec openspec/specs/admin-settings/spec.md
 * @spec openspec/specs/admin-settings/spec.md
 * @spec openspec/specs/admin-settings/spec.md
 * @spec openspec/specs/admin-settings/spec.md
 * @spec openspec/specs/admin-settings/spec.md
 * @spec openspec/specs/admin-settings/spec.md
 * @spec openspec/specs/admin-settings/spec.md
 */

namespace OCA\OpenCatalogi\Service;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OCA\OpenCatalogi\Contract\AppIdentity;
use OCA\OpenCatalogi\Contract\SettingsServiceInterface;
use OCA\OpenCatalogi\Service\Publication\NationalIndexService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Service for handling settings-related operations.
 *
 * Provides functionality for retrieving, saving, and loading settings,
 * as well as managing configuration for different object types.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 *
 * CouplingBetweenObjects is suppressed at 13 (threshold is "under 13"), and
 * this is the one finding in this change I could not close honestly.
 *
 * What was closed: the class also referenced OCA\OpenCatalogi\BackgroundJob\DirectorySync
 * purely to read three interval constants, which took it to 14. Those constants
 * now live here — on the class that publishes them via getSyncOptions() and
 * enforces them in updateSyncOptions() — and DirectorySync aliases them. That
 * is a real reduction, not a re-label: it also removes the same clamp being
 * expressed in two places.
 *
 * What remains is the LoggerInterface this change injects. The two calls it
 * feeds are `warning()` in catch blocks that previously swallowed a failed
 * schema/register slug lookup in silence, so removing it to buy back one point
 * would restore a silent-failure path — the exact defect class this codebase
 * has been chasing. Dropping `Application::APP_ID` for the `$this->appName`
 * string field would also score a point and would make the code worse.
 *
 * Not a baseline entry: phpmd.baseline.xml is scoped to (rule, file) and would
 * licence every future coupling regression in this 1400-line class. The real
 * fix is splitting SettingsService (settings vs version checks vs object-type
 * configuration vs sync options), which is out of scope for this change.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyMethods) Object type configuration is split into small private steps to stay under the unit limits.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */
class SettingsService implements SettingsServiceInterface {

	/**
	 * The OpenRegister register the shared organisation projection lives on.
	 *
	 * `directory` is always available: it carries the core `nc-user` /
	 * `nc-group` projections and is not gated on any app being installed.
	 */
	private const SHARED_ORGANISATION_REGISTER = 'directory';

	/**
	 * The OpenRegister schema slug for the shared organisation.
	 *
	 * `nc-` prefixed by OpenRegister so it cannot collide with a leaf app's own
	 * `organization` schema, which has to keep working until every app has
	 * migrated off it.
	 */
	private const SHARED_ORGANISATION_SCHEMA = 'nc-organisation';

	/**
	 * Object types whose config keys are `<type>_source`, `<type>_schema` and `<type>_register`,
	 * with the schema slug equal to the type.
	 *
	 * 'publication' resolves publication_register/publication_schema for retention
	 * evaluation (RET-005). 'document' was retired: attachments are files on the
	 * publication. 'organization' is absent because its schema is OpenRegister's
	 * `nc-organisation` projection, resolved by resolveSharedOrganisationConfiguration().
	 */
	private const OBJECT_TYPES = [
		'catalog',
		'listing',
		'publication',
		'theme',
		'glossary',
	];

	/**
	 * OOAPI types (OOAPI-003/OOAPI-010): config prefix => schema slug.
	 *
	 * The course/program/offering schemas live in the shared publication register
	 * (a register.d fragment, ADR-037). The OOAPI-010 config prefixes differ from
	 * the slugs, hence the map.
	 */
	private const OOAPI_TYPE_MAP = [
		'ooapi_courses' => 'course',
		'ooapi_programs' => 'program',
		'ooapi_offerings' => 'offering',
	];

	/**
	 * Schemas in the shared publication register whose config keys differ from their
	 * slugs, grouped with the register key a group writes first (or null).
	 *
	 * An unset key makes the matching controller answer 503: an unconfigured
	 * capability is refused, not shown as empty.
	 * - Woo transparency (WOO-PROV-002), including dossiq's withheld documents.
	 * - The published service and case type catalogue.
	 * - Active publication, inspection and the national indexes.
	 * - Woo request intake and the comment period.
	 * - The public and community surface; a notice is its own schema so it never
	 *   enters the sitemap.
	 */
	private const KEYED_SCHEMA_GROUPS = [
		[
			'register' => 'woo_register',
			'schemas' => [
				'woo_batch_schema' => 'wooBatch',
				'woo_assessment_schema' => 'wooAssessment',
				'withheld_document_schema' => 'withheldDocument',
			],
		],
		[
			'register' => 'service_catalogue_register',
			'schemas' => [
				'service_catalogue_entry_schema' => 'serviceCatalogueEntry',
				'case_type_definition_schema' => 'caseTypeDefinition',
				'knowledge_article_schema' => 'knowledgeArticle',
				'article_verdict_schema' => 'articleVerdict',
			],
		],
		[
			'register' => null,
			'schemas' => [
				'publication_rule_schema' => 'publicationRule',
				'inspection_schema' => 'inspection',
				'publication_process_schema' => 'publicationProcess',
				'zienswijze_ask_schema' => 'zienswijzeAsk',
				'depublication_schema' => 'depublication',
				'obligation_source_schema' => 'obligationSource',
			],
		],
		[
			'register' => null,
			'schemas' => [
				'woo_request_schema' => 'wooRequest',
				'comment_period_schema' => 'commentPeriod',
				'published_table_schema' => 'publishedTable',
			],
		],
		[
			'register' => null,
			'schemas' => [
				'service_status_schema' => 'serviceStatus',
				'status_subscription_schema' => 'statusSubscription',
				'instance_banner_schema' => 'instanceBanner',
				'notice_board_schema' => 'noticeBoard',
				'notice_schema' => 'notice',
				'record_vote_schema' => 'recordVote',
				'document_reference_schema' => 'documentReference',
			],
		],
	];

	/**
	 * Seeded catalogues that ship unpublished and are published once their scope resolves.
	 *
	 * @var list<string>
	 */
	private const PUBLISH_WHEN_RESOLVED = ['applicatielandschap'];

	/**
	 * The object types the settings page offers a register and schema selector for.
	 *
	 * The OOAPI course/program/offering types (OOAPI-010) are listed so the generic
	 * Settings.vue schema selector renders them like every other object type.
	 */
	private const SETTINGS_OBJECT_TYPES = [
		'catalog',
		'listing',
		'organization',
		'theme',
		'glossary',
		'usageCounter',
		'ooapi_courses',
		'ooapi_programs',
		'ooapi_offerings',
	];

	/**
	 * The object types whose source, schema and register a caller may store through updateSettings().
	 *
	 * This is SETTINGS_OBJECT_TYPES without `usageCounter`: the settings page shows a
	 * usage counter selector, but its keys are not writable through this endpoint.
	 */
	private const WRITABLE_OBJECT_TYPES = [
		'catalog',
		'listing',
		'organization',
		'theme',
		'glossary',
		'ooapi_courses',
		'ooapi_programs',
		'ooapi_offerings',
	];

	/**
	 * The publishing options, stored as the text 'true' or 'false'.
	 */
	private const PUBLISHING_OPTIONS = [
		'auto_publish_attachments',
		'auto_publish_objects',
		'use_old_style_publishing_view',
	];

	/**
	 * The defaults of the settings that are not per object type, in the order they are reported.
	 *
	 * - Publishing options.
	 * - DCAT-AP-NL harvest publisher defaults (DCAT-005 / DCAT-010): instance-level fallbacks
	 *   that complete mandatory dataset properties; the per-catalog DCAT toggle (hasDcat)
	 *   lives on the catalog object.
	 * - OOAPI 5.0 consumers (OOAPI-008/OOAPI-010, design.md D3): empty lets any authenticated
	 *   Nextcloud user read an OOAPI-enabled catalog's feed; a comma-separated list of
	 *   usernames restricts it to those accounts. The allowlist is instance-wide.
	 * - Woo-index registration status (WOO-HR-003, design D3): operational config, not a publication.
	 * - National channel sources (REQ-WND-001): a JSON map from channel to integriq source slug.
	 * - Public API cache time in seconds (REQ-PAC-003); 0 switches the public cache headers off.
	 */
	private const SETTING_DEFAULTS = [
		'auto_publish_attachments' => 'false',
		'auto_publish_objects' => 'false',
		'use_old_style_publishing_view' => 'false',
		'dcat_instance_title' => 'OpenCatalogi',
		'dcat_publisher_name' => '',
		'dcat_publisher_uri' => '',
		'dcat_default_license' => 'http://creativecommons.org/publicdomain/zero/1.0/',
		'dcat_contact_point' => '',
		'ooapi_consumers' => '',
		'woo_index_registration_status' => 'not_registered',
		'woo_index_registration_url' => '',
		'woo_index_registration_at' => '',
		'woo_index_registration_answer' => '',
		'channel_sources' => '{}',
		'public_api_cache_seconds' => '60',
	];

	/**
	 * The instance settings a caller may write through updateSettings(), in the order they are written.
	 *
	 * Each has a default in SETTING_DEFAULTS, which also drives what getSettings()
	 * reports; a default added only for display is not writable until it is listed here.
	 */
	private const WRITABLE_SETTINGS = [
		'auto_publish_attachments',
		'auto_publish_objects',
		'use_old_style_publishing_view',
		'dcat_instance_title',
		'dcat_publisher_name',
		'dcat_publisher_uri',
		'dcat_default_license',
		'dcat_contact_point',
		'ooapi_consumers',
		'woo_index_registration_status',
		'woo_index_registration_url',
		'woo_index_registration_at',
		'woo_index_registration_answer',
		'channel_sources',
		'public_api_cache_seconds',
	];

	/**
	 * App config key listing the seeded catalogues already published automatically.
	 */
	private const SEEDS_PUBLISHED_KEY = 'catalog_seeds_published';

	/**
	 * This property holds the name of the application, which is used for identification and configuration purposes.
	 *
	 * @var string $appName The name of the app.
	 */
	private string $appName;

	/**
	 * This constant represents the unique identifier for the
	 * OpenRegister application, used to check its installation
	 * and status.
	 *
	 * @var string $openRegisterAppId The ID of the OpenRegister app.
	 */
	private const OPENREGISTER_APP_ID = 'openregister';

	/**
	 * SettingsService constructor.
	 *
	 * @param IAppConfig $config App configuration interface.
	 * @param ContainerInterface $container Container for dependency injection.
	 * @param IAppManager $appManager App manager interface.
	 * @param LoggerInterface $logger PSR logger for defensive fallback warnings.
	 * @param RegisterSchemaLinkService $registerSchemaLink Repairs register-to-schema linkage after an import.
	 */
	public function __construct(
		private readonly IAppConfig $config,
		private readonly ContainerInterface $container,
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
		private readonly RegisterSchemaLinkService $registerSchemaLink,
	) {
		// Indulge in setting the application name for identification and configuration purposes.
		$this->appName = 'opencatalogi';

	}//end __construct()

	/**
	 * Checks if OpenRegister is installed and meets version requirements.
	 *
	 * @param string|null $minVersion Minimum required version (e.g. '1.0.0').
	 *
	 * @return boolean True if OpenRegister is installed and meets version requirements.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function isOpenRegisterInstalled(?string $minVersion = self::MIN_OPENREGISTER_VERSION): bool {
		if ($this->appManager->isInstalled(self::OPENREGISTER_APP_ID) === false) {
			return false;
		}

		if ($minVersion === null) {
			return true;
		}

		$currentVersion = $this->appManager->getAppVersion(self::OPENREGISTER_APP_ID);
		return version_compare(version1: $currentVersion, version2: $minVersion, operator: '>=');
	}//end isOpenRegisterInstalled()

	/**
	 * Checks if OpenRegister is enabled.
	 *
	 * @return boolean True if OpenRegister is enabled.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function isOpenRegisterEnabled(): bool {
		/*
		 * @psalm-suppress UndefinedInterfaceMethod
		 */

		return $this->appManager->isEnabledForUser(self::OPENREGISTER_APP_ID) === true;
	}//end isOpenRegisterEnabled()

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
	public function installOrUpdateOpenRegister(?string $minVersion = self::MIN_OPENREGISTER_VERSION): bool {
		if ($this->isOpenRegisterInstalled(minVersion: null) === false) {
			try {
				// Throws when the app is not on disk or was never installed.
				$this->appManager->enableApp(self::OPENREGISTER_APP_ID);
			} catch (\Exception $e) {
				throw new RuntimeException(
					'Failed to install/update OpenRegister: OpenRegister is not installed on this server and cannot be '
					. 'installed by OpenCatalogi. Install it from the Nextcloud App Store (or `occ app:install openregister`). '
					. 'Reason: ' . $e->getMessage()
				);
			}
		}

		if ($this->isOpenRegisterInstalled(minVersion: $minVersion) === false) {
			throw $this->outdatedOpenRegister(minVersion: $minVersion);
		}

		return true;
	}//end installOrUpdateOpenRegister()

	/**
	 * The error for an OpenRegister older than the required version.
	 *
	 * @param string|null $minVersion The required version.
	 *
	 * @return RuntimeException The error, naming both versions.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	private function outdatedOpenRegister(?string $minVersion): RuntimeException {
		return new RuntimeException(
			sprintf(
				'Failed to install/update OpenRegister: version %s is older than the required %s. '
				. 'Update it from the Nextcloud App Store (or `occ app:update openregister`).',
				$this->appManager->getAppVersion(self::OPENREGISTER_APP_ID),
				(string)$minVersion
			)
		);

	}//end outdatedOpenRegister()

	/**
	 * Attempts to auto-configure registers and schemas.
	 *
	 * @return array The updated configuration.
	 * @throws \RuntimeException If auto-configuration fails.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function autoConfigure(): array {
		try {
			$registers = $this->getRegisterMapper()->findAll();
			if (empty($registers) === true) {
				return [];
			}

			$objectTypes = $this->getSettings()['objectTypes'];
			$register = $this->publicationRegister(registers: $registers);
			$configuration = [];
			foreach ($objectTypes as $type) {
				$configuration += $this->autoConfiguration(type: $type, register: $register);
			}

			return $configuration;
		} catch (\Exception $e) {
			throw new RuntimeException('Failed to auto-configure: ' . $e->getMessage());
		}

	}//end autoConfigure()

	/**
	 * The first register whose slug contains `publication`, as an array.
	 *
	 * @param array<int, mixed> $registers The registers, as arrays or entities.
	 *
	 * @return array<string, mixed>|null The register, or null when none matches.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	private function publicationRegister(array $registers): ?array {
		foreach ($registers as $register) {
			$registerData = $register;
			if (is_array($register) === false) {
				$registerData = $register->jsonSerialize();
			}

			if (stripos($registerData['slug'], 'publication') !== false) {
				return $registerData;
			}
		}

		return null;
	}//end publicationRegister()

	/**
	 * The register and schema an object type is auto-configured with.
	 *
	 * The schema is the register's first schema whose title contains the type.
	 *
	 * @param string $type The object type.
	 * @param array<string, mixed>|null $register The publication register, or null when there is none.
	 *
	 * @return array<string, mixed> The `<type>_register` and `<type>_schema` keys found.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	private function autoConfiguration(string $type, ?array $register): array {
		if ($register === null) {
			return [];
		}

		$configuration = ["{$type}_register" => $register['id']];
		$schema = $this->schemaTitled(schemas: ($register['schemas'] ?? null), type: $type);
		if ($schema !== null) {
			$configuration["{$type}_schema"] = $schema['id'];
		}

		return $configuration;
	}//end autoConfiguration()

	/**
	 * The first schema whose title contains the type, skipping entries that are not arrays.
	 *
	 * @param mixed $schemas The register's schemas.
	 * @param string $type The object type.
	 *
	 * @return array<string, mixed>|null The schema, or null when none matches.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	private function schemaTitled(mixed $schemas, string $type): ?array {
		if (empty($schemas) === true) {
			return null;
		}

		foreach ($schemas as $schema) {
			if (is_array($schema) === true && stripos($schema['title'], $type) !== false) {
				return $schema;
			}
		}

		return null;
	}//end schemaTitled()

	/**
	 * Initializes the app with all required components.
	 *
	 * @param string|null $minORVersion Minimum required OpenRegister version.
	 *
	 * @return array The initialization results.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function initialize(?string $minORVersion = self::MIN_OPENREGISTER_VERSION): array {
		$results = ['openRegister' => false, 'autoConfigured' => false, 'settingsLoaded' => false, 'errors' => []];

		try {
			if ($this->isOpenRegisterInstalled(minVersion: $minORVersion) === false) {
				$this->installOrUpdateOpenRegister(minVersion: $minORVersion);
			}

			$results['openRegister'] = true;
			$results['autoConfigured'] = $this->applyAutoConfiguration();

			// Load settings from file only if needed.
			$results['settingsLoaded'] = true;
			if ($this->shouldLoadSettings() === true) {
				$this->loadSettings();
			}
		} catch (\Exception $e) {
			$results['errors'][] = $e->getMessage();
		}

		return $results;
	}//end initialize()

	/**
	 * Store the auto-configured registers and schemas, when auto-configuration found any.
	 *
	 * @return bool True when a configuration was stored.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	private function applyAutoConfiguration(): bool {
		$configuration = $this->autoConfigure();
		if (empty($configuration) === true) {
			return false;
		}

		$this->updateSettings(data: $configuration);
		return true;
	}//end applyAutoConfiguration()

	/**
	 * Attempts to retrieve the OpenRegister service from the container.
	 *
	 * @return \OCA\OpenRegister\Service\ObjectService|null The OpenRegister service if available, null otherwise.
	 * @throws \RuntimeException If the service is not available.
	 *
	 * @spec exclude Lazy dependency-injection accessor — resolves the OpenRegister
	 *       ObjectService from the container; pure framework plumbing, no domain behavior.
	 */
	public function getObjectService(): ?\OCA\OpenRegister\Service\ObjectService {
		if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
			return $this->container->get('OCA\OpenRegister\Service\ObjectService');
		}

		throw new RuntimeException('OpenRegister service is not available.');
	}//end getObjectService()

	/**
	 * Attempts to retrieve the RegisterMapper from the container.
	 *
	 * @return \OCA\OpenRegister\Db\RegisterMapper|null The RegisterMapper if available, null otherwise.
	 * @throws \RuntimeException If the service is not available.
	 *
	 * @spec exclude Lazy dependency-injection accessor — resolves the OpenRegister
	 *       RegisterMapper from the container; pure framework plumbing, no domain behavior.
	 */
	public function getRegisterMapper(): ?\OCA\OpenRegister\Db\RegisterMapper {
		if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
			return $this->container->get('OCA\OpenRegister\Db\RegisterMapper');
		}

		throw new RuntimeException('RegisterMapper is not available.');
	}//end getRegisterMapper()

	/**
	 * Attempts to retrieve the Schema mapper from the container.
	 *
	 * @return \OCA\OpenRegister\Db\SchemaMapper|null The Schema mapper if available, null otherwise.
	 * @throws \RuntimeException If the mapper is not available.
	 *
	 * @spec exclude Lazy dependency-injection accessor — resolves the OpenRegister
	 *       SchemaMapper from the container; pure framework plumbing, no domain behavior.
	 */
	public function getSchemaMapper(): ?\OCA\OpenRegister\Db\SchemaMapper {
		if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
			return $this->container->get('OCA\OpenRegister\Db\SchemaMapper');
		}

		throw new RuntimeException('SchemaMapper is not available.');
	}//end getSchemaMapper()

	/**
	 * Attempts to retrieve the Configuration service from the container.
	 *
	 * @return \OCA\OpenRegister\Service\ConfigurationService|null The Configuration service if available, null otherwise.
	 * @throws \RuntimeException If the service is not available.
	 *
	 * @spec exclude Lazy dependency-injection accessor — resolves the OpenRegister
	 *       ConfigurationService from the container; pure framework plumbing, no domain behavior.
	 */
	public function getConfigurationService(): ?\OCA\OpenRegister\Service\ConfigurationService {
		if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
			return $this->container->get('OCA\OpenRegister\Service\ConfigurationService');
		}

		throw new RuntimeException('Configuration service is not available.');
	}//end getConfigurationService()

	/**
	 * Retrieve the current settings.
	 *
	 * @return array The current settings configuration.
	 * @throws \RuntimeException If settings retrieval fails.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function getSettings(): array {
		$data = ['objectTypes' => self::SETTINGS_OBJECT_TYPES] + $this->registerAvailability();

		try {
			foreach ($this->settingDefaults() as $key => $defaultValue) {
				$data['configuration'][$key] = $this->config->getValueString($this->appName, $key, $defaultValue);
			}

			return $data;
		} catch (\Exception $e) {
			throw new RuntimeException('Failed to retrieve settings: ' . $e->getMessage());
		}

	}//end getSettings()

	/**
	 * Whether OpenRegister is available, and its registers with their full schemas.
	 *
	 * @return array{openRegisters: bool, availableRegisters: array} The availability and the registers.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	private function registerAvailability(): array {
		$availability = ['openRegisters' => false, 'availableRegisters' => []];

		try {
			$registerMapper = $this->getRegisterMapper();
			if ($registerMapper !== null) {
				$availability['openRegisters'] = true;
				$registers = $registerMapper->findAll(_rbac: false, _multitenancy: false);
				$availability['availableRegisters'] = $this->enrichRegistersWithSchemas(registers: $registers);
			}
		} catch (\RuntimeException $e) {
			// OpenRegister is not available: report the defaults.
		}

		return $availability;
	}//end registerAvailability()

	/**
	 * The default of every setting: an OpenRegister source and an empty schema and register
	 * per object type, then the instance settings.
	 *
	 * @return array<string, string> The defaults by config key.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	private function settingDefaults(): array {
		$defaults = [];
		foreach (self::SETTINGS_OBJECT_TYPES as $type) {
			$defaults["{$type}_source"] = 'openregister';
			$defaults["{$type}_schema"] = '';
			$defaults["{$type}_register"] = '';
		}

		return $defaults + self::SETTING_DEFAULTS;
	}//end settingDefaults()

	/**
	 * Enrich registers with full schema objects instead of just schema IDs.
	 *
	 * This method takes an array of register entities and replaces their schema ID arrays
	 * with full schema objects containing id, title, and other metadata.
	 *
	 * @param array $registers Array of register entities from OpenRegister.
	 *
	 * @return array Array of registers with enriched schema data.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	private function enrichRegistersWithSchemas(array $registers): array {
		try {
			$schemaMapper = $this->getSchemaMapper();
			if ($schemaMapper === null) {
				return $registers;
			}

			$registerArrays = array_map(fn ($register) => self::entityArray(entity: $register), $registers);

			$schemaMap = self::referencedSchemas(schemaMapper: $schemaMapper, registerArrays: $registerArrays);

			return array_map(fn (array $register) => self::withFullSchemas(registerArray: $register, schemaMap: $schemaMap), $registerArrays);
		} catch (\Exception $e) {
			return $registers;
		}//end try

	}//end enrichRegistersWithSchemas()

	/**
	 * An entity as an array: its jsonSerialize() form, or the array cast.
	 *
	 * @param mixed $entity The entity, object or array.
	 *
	 * @return array The array form.
	 *
	 * @spec exclude Internal shape step of enrichRegistersWithSchemas().
	 */
	private static function entityArray(mixed $entity): array {
		if (is_object($entity) === true && method_exists($entity, 'jsonSerialize') === true) {
			return $entity->jsonSerialize();
		}

		return (array)$entity;
	}//end entityArray()

	/**
	 * The schemas the registers reference, by id, from one batched query.
	 *
	 * A find() per ID was 2,814 queries for 348 registers on every settings
	 * request. The batched lookup reads the table directly, matching the
	 * _rbac: false / _multitenancy: false posture of the per-ID find().
	 *
	 * @param \OCA\OpenRegister\Db\SchemaMapper $schemaMapper The schema mapper.
	 * @param array<int, array> $registerArrays The registers in array form.
	 *
	 * @return array The resolved schemas by id.
	 *
	 * @spec exclude Internal lookup step of enrichRegistersWithSchemas().
	 */
	private static function referencedSchemas(\OCA\OpenRegister\Db\SchemaMapper $schemaMapper, array $registerArrays): array {
		$idsToResolve = self::referencedSchemaIds(registerArrays: $registerArrays);
		if (empty($idsToResolve) === true) {
			return [];
		}

		return $schemaMapper->findMultipleOptimized(ids: $idsToResolve);
	}//end referencedSchemas()

	/**
	 * The distinct numeric schema ids the registers reference, in first-seen order.
	 *
	 * @param array<int, array> $registerArrays The registers in array form.
	 *
	 * @return array<int, int> The schema ids.
	 *
	 * @spec exclude Internal collection step of enrichRegistersWithSchemas().
	 */
	private static function referencedSchemaIds(array $registerArrays): array {
		$idsToResolve = [];
		foreach ($registerArrays as $registerArray) {
			$schemaIds = $registerArray['schemas'] ?? [];
			if (is_array($schemaIds) === false) {
				continue;
			}

			foreach (array_filter($schemaIds, 'is_numeric') as $schemaId) {
				$idsToResolve[(int)$schemaId] = true;
			}
		}

		return array_keys($idsToResolve);
	}//end referencedSchemaIds()

	/**
	 * A register with its schema ids replaced by the resolved schemas.
	 *
	 * A register without a schema list keeps it as it is.
	 *
	 * @param array $registerArray The register in array form.
	 * @param array $schemaMap The resolved schemas by id.
	 *
	 * @return array The register.
	 *
	 * @spec exclude Internal enrichment step of enrichRegistersWithSchemas().
	 */
	private static function withFullSchemas(array $registerArray, array $schemaMap): array {
		$schemaIds = $registerArray['schemas'] ?? [];
		if (is_array($schemaIds) === false || empty($schemaIds) === true) {
			return $registerArray;
		}

		$registerArray['schemas'] = self::resolvedSchemas(schemaIds: $schemaIds, schemaMap: $schemaMap);
		return $registerArray;
	}//end withFullSchemas()

	/**
	 * Schema ids replaced by their resolved schemas.
	 *
	 * A non-numeric entry is kept as it is; an id with no row is dropped.
	 *
	 * @param array $schemaIds The schema ids.
	 * @param array $schemaMap The resolved schemas by id.
	 *
	 * @return array The schemas.
	 *
	 * @spec exclude Internal enrichment step of enrichRegistersWithSchemas().
	 */
	private static function resolvedSchemas(array $schemaIds, array $schemaMap): array {
		$fullSchemas = [];
		foreach ($schemaIds as $schemaId) {
			if (is_numeric($schemaId) === false) {
				$fullSchemas[] = $schemaId;
			} elseif (isset($schemaMap[(int)$schemaId]) === true) {
				$fullSchemas[] = self::entityArray(entity: $schemaMap[(int)$schemaId]);
			}
		}

		return $fullSchemas;
	}//end resolvedSchemas()

	/**
	 * Keep only the known national channels that name a source slug.
	 *
	 * @param mixed $value The submitted map, as an array or as a JSON string.
	 *
	 * @return string The map as JSON.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md#requirement-a-hand-over-to-a-national-channel-calls-the-gateway-with-a-real-source-req-wnd-001
	 */
	private function normaliseChannelSources(mixed $value): string {
		if (is_string($value) === true) {
			$value = json_decode($value, true);
		}

		$sources = [];
		foreach (NationalIndexService::CONFIGURABLE_CHANNELS as $channel) {
			$slug = '';
			if (is_array($value) === true && is_string($value[$channel] ?? null) === true) {
				$slug = trim($value[$channel]);
			}

			if ($slug !== '') {
				$sources[$channel] = $slug;
			}
		}

		return (string)json_encode((object)$sources);
	}//end normaliseChannelSources()

	/**
	 * Update the settings configuration.
	 *
	 * Only the keys writableSettingKeys() lists are accepted: source, schema and
	 * register per writable object type, and WRITABLE_SETTINGS. Any other key in
	 * $data is silently ignored, preventing arbitrary app-config key injection.
	 * A list or object value for an accepted key (except the channel sources map)
	 * rejects the whole payload before anything is written.
	 *
	 * @param array $data The settings data to update.
	 *
	 * @return array The updated settings configuration.
	 * @throws InvalidArgumentException If an accepted key carries a list or an object.
	 * @throws \RuntimeException If settings update fails.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function updateSettings(array $data): array {
		$this->assertSingleValues(data: $data, keys: $this->writableSettingKeys());
		try {
			$updated = [];
			foreach ($this->writableSettingKeys() as $key) {
				if (array_key_exists($key, $data) === true) {
					$value = $this->storableSetting(key: $key, value: $data[$key]);
					$this->config->setValueString($this->appName, $key, (string)$value);
					// Retrieve the persisted value to confirm the change.
					$updated[$key] = $this->config->getValueString($this->appName, $key);
				}
			}

			return $updated;
		} catch (\Exception $e) {
			throw new RuntimeException('Failed to update settings: ' . $e->getMessage());
		}

	}//end updateSettings()

	/**
	 * Reject a payload that gives an accepted key a list or an object, which would be stored as the text "Array".
	 *
	 * The channel sources setting is the one map a caller may send.
	 *
	 * @param array $data The settings data to update.
	 * @param array<int,string> $keys The accepted keys.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException Naming the first key with such a value.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	private function assertSingleValues(array $data, array $keys): void {
		foreach (array_intersect_key($data, array_flip(array_diff($keys, ['channel_sources']))) as $key => $value) {
			if (is_array($value) === true || is_object($value) === true) {
				throw new InvalidArgumentException('Setting ' . $key . ' takes a single value, not a list or an object');
			}
		}

	}//end assertSingleValues()

	/**
	 * The settings a caller may store, in the order they are written: source, schema and
	 * register per writable object type, then WRITABLE_SETTINGS.
	 *
	 * @return array<int, string> The allowed config keys.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	private function writableSettingKeys(): array {
		$keys = [];
		foreach (self::WRITABLE_OBJECT_TYPES as $type) {
			array_push($keys, "{$type}_source", "{$type}_schema", "{$type}_register");
		}

		return array_merge($keys, self::WRITABLE_SETTINGS);
	}//end writableSettingKeys()

	/**
	 * The value to store for a setting: channel sources normalised, the cache time clamped.
	 *
	 * @param string $key The config key.
	 * @param mixed $value The value the caller sent.
	 *
	 * @return mixed The value to store.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	private function storableSetting(string $key, mixed $value): mixed {
		if ($key === 'channel_sources') {
			return $this->normaliseChannelSources(value: $value);
		}

		if ($key === 'public_api_cache_seconds') {
			return $this->publicApiCacheSeconds(value: $value);
		}

		return $value;
	}//end storableSetting()

	/**
	 * The public API cache time to store.
	 *
	 * A negative or unreadable time switches caching off rather than storing something
	 * the cache headers cannot use; more than an hour is stored as an hour, the ceiling
	 * the headers apply too.
	 *
	 * @param mixed $value The time the caller sent.
	 *
	 * @return int The seconds to store.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	private function publicApiCacheSeconds(mixed $value): int {
		if (is_numeric($value) === false) {
			return 0;
		}

		return min(self::PUBLIC_API_CACHE_MAX_SECONDS, max(0, (int)$value));
	}//end publicApiCacheSeconds()

	/**
	 * Get the current publishing options.
	 *
	 * @return array The current publishing options configuration.
	 * @throws \RuntimeException If publishing options retrieval fails.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function getPublishingOptions(): array {
		try {
			$publishingOptions = [];
			foreach (self::PUBLISHING_OPTIONS as $option) {
				// Stored as the text 'true' or 'false'; anything else reads as false.
				$publishingOptions[$option] = $this->config->getValueString($this->appName, $option, 'false') === 'true';
			}

			return $publishingOptions;
		} catch (\Exception $e) {
			throw new RuntimeException('Failed to retrieve publishing options: ' . $e->getMessage());
		}

	}//end getPublishingOptions()

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
	public function updatePublishingOptions(array $options): array {
		try {
			$updatedOptions = [];
			foreach (self::PUBLISHING_OPTIONS as $option) {
				if (isset($options[$option]) === true) {
					$this->config->setValueString($this->appName, $option, $this->storedFlag(value: $options[$option]));
					// Retrieve and convert back to boolean for the response.
					$updatedOptions[$option] = $this->config->getValueString($this->appName, $option) === 'true';
				}
			}

			return $updatedOptions;
		} catch (\Exception $e) {
			throw new RuntimeException('Failed to update publishing options: ' . $e->getMessage());
		}

	}//end updatePublishingOptions()

	/**
	 * A publishing option as stored: 'true' for boolean true or the text 'true', otherwise 'false'.
	 *
	 * @param mixed $value The value the caller sent.
	 *
	 * @return string 'true' or 'false'.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	private function storedFlag(mixed $value): string {
		if ($value === true || $value === 'true') {
			return 'true';
		}

		return 'false';
	}//end storedFlag()

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
	public function getSyncOptions(): array {
		try {
			$configured = (int)$this->config->getValueInt($this->appName, 'sync_interval_seconds', self::DEFAULT_INTERVAL_SECONDS);

			// Clamp on read so a stale out-of-range value still renders sanely in the UI.
			$interval = max(self::MIN_INTERVAL_SECONDS, min(self::MAX_INTERVAL_SECONDS, $configured));

			return [
				'sync_interval_seconds' => $interval,
				'min_interval_seconds' => self::MIN_INTERVAL_SECONDS,
				'max_interval_seconds' => self::MAX_INTERVAL_SECONDS,
				'default_interval_seconds' => self::DEFAULT_INTERVAL_SECONDS,
			];
		} catch (\Exception $e) {
			throw new RuntimeException('Failed to retrieve sync options: ' . $e->getMessage());
		}//end try

	}//end getSyncOptions()

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
	public function updateSyncOptions(array $options): array {
		try {
			if (array_key_exists('sync_interval_seconds', $options) === true) {
				$requested = (int)$options['sync_interval_seconds'];
				$clamped = max(
					self::MIN_INTERVAL_SECONDS,
					min(self::MAX_INTERVAL_SECONDS, $requested)
				);

				$this->config->setValueInt($this->appName, 'sync_interval_seconds', $clamped);
			}

			return $this->getSyncOptions();
		} catch (\Exception $e) {
			throw new RuntimeException('Failed to update sync options: ' . $e->getMessage());
		}//end try

	}//end updateSyncOptions()

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
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function loadSettings(bool $force = false): array {
		try {
			$result = $this->importConfiguration(
				data: $this->readRegisterConfiguration(appPath: $this->appManager->getAppPath(AppIdentity::APP_ID)),
				force: $force
			);

			// Update app configuration with imported schema and register IDs.
			$this->updateObjectTypeConfiguration(importResult: $result);

			// OpenRegister's importRegister() version-gates the register update on
			// `components.registers.publication.version` — unchanged since 0.1.0 — so a
			// non-forced import silently skips it and schemas added by a newer fragment
			// never get attached to the already-existing register. Reconcile additively.
			$this->registerSchemaLink->reconcile($result);

			// WOO-529: the seeded default catalog gets its `registers`/`schemas`
			// only now that importFromApp() has resolved the numeric IDs.
			$this->backfillCatalogScopes();

			return $result;
		} catch (\Exception $e) {
			throw new RuntimeException('Failed to load settings: ' . $e->getMessage());
		}//end try

	}//end loadSettings()

	/**
	 * Import the configuration payload into OpenRegister for this app.
	 *
	 * OpenRegister hashes the merged configuration and skips an import whose
	 * hash is unchanged, so the version passed is the app's own version.
	 *
	 * @param array<mixed> $data The configuration payload.
	 * @param boolean $force Whether to import regardless of version checks.
	 *
	 * @return array The import result.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 *
	 * @spec exclude Internal import step of loadSettings().
	 */
	private function importConfiguration(array $data, bool $force): array {
		return $this->getConfigurationService()->importFromApp(
			appId: AppIdentity::APP_ID,
			data: $data,
			version: $this->appManager->getAppVersion(AppIdentity::APP_ID),
			force: $force
		);

	}//end importConfiguration()

	/**
	 * Read publication_register.json with its register.d fragments merged in.
	 *
	 * Schemas no register declares are attached to the publication register, and
	 * the file location is recorded as the payload's source for the cron job.
	 *
	 * @param string $appPath The absolute app directory.
	 *
	 * @return array<mixed> The configuration payload to import.
	 * @throws \RuntimeException If the file is missing, unreadable or not valid JSON.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	private function readRegisterConfiguration(string $appPath): array {
		$absoluteFilePath = $appPath . '/lib/Settings/publication_register.json';

		$data = self::mergeRegisterFragments(
			data: self::readConfigurationFile(path: $absoluteFilePath),
			fragmentDir: $appPath . '/lib/Settings/register.d'
		);

		// ADR-037 guard: a fragment that declares a schema but forgets to list it
		// under `components.registers.publication.schemas` gets the schema created
		// and then orphaned — no register holds it, so no magic table is provisioned
		// and the app's `{type}_register`/`{type}_schema` pair points at a register
		// without that schema (how OOAPI course/program/offering broke). This
		// pipeline only ever provisions one register, so attach the strays.
		$data = self::attachOrphanSchemasToPublicationRegister(data: $data);

		return self::withSourceTracking(
			data: $data,
			sourceUrl: self::relativeSourcePath(appPath: $appPath, absoluteFilePath: $absoluteFilePath)
		);

	}//end readRegisterConfiguration()

	/**
	 * Read and decode a JSON configuration file.
	 *
	 * @param string $path The absolute file path.
	 *
	 * @return mixed The decoded JSON.
	 * @throws \RuntimeException If the file is missing, unreadable or not valid JSON.
	 *
	 * @spec exclude Internal read step of readRegisterConfiguration().
	 */
	private static function readConfigurationFile(string $path): mixed {
		if (file_exists($path) === false) {
			throw new RuntimeException("Configuration file not found: {$path}");
		}

		$jsonContent = file_get_contents($path);
		if ($jsonContent === false) {
			throw new RuntimeException("Failed to read configuration file: {$path}");
		}

		$data = json_decode($jsonContent, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			throw new RuntimeException('Invalid JSON in configuration file: ' . json_last_error_msg());
		}

		return $data;
	}//end readConfigurationFile()

	/**
	 * Merge the modular register fragments onto the base config (ADR-037).
	 *
	 * Each OpenSpec change drops its own fragment file instead of editing the
	 * monolith, so concurrent builds touch disjoint files. Fragments merge in
	 * file name order; an unreadable or malformed fragment is skipped.
	 *
	 * @param mixed $data The base config.
	 * @param string $fragmentDir The fragment directory.
	 *
	 * @return mixed The merged config.
	 *
	 * @spec exclude Internal merge step of readRegisterConfiguration().
	 */
	private static function mergeRegisterFragments(mixed $data, string $fragmentDir): mixed {
		foreach (self::fragmentFiles(directory: $fragmentDir) as $fragmentFile) {
			$fragmentContent = file_get_contents($fragmentFile);
			if ($fragmentContent === false) {
				continue;
			}

			$fragmentData = json_decode($fragmentContent, true);
			if (json_last_error() !== JSON_ERROR_NONE) {
				continue;
			}

			$data = self::deepMergeConfig(base: $data, overlay: $fragmentData);
		}

		return $data;
	}//end mergeRegisterFragments()

	/**
	 * The JSON fragment files of a directory, sorted by name.
	 *
	 * @param string $directory The fragment directory.
	 *
	 * @return array<int, string> The file paths, none when the directory is absent.
	 *
	 * @spec exclude Internal listing step of mergeRegisterFragments().
	 */
	private static function fragmentFiles(string $directory): array {
		if (is_dir($directory) === false) {
			return [];
		}

		$fragmentFiles = glob($directory . '/*.json');
		sort($fragmentFiles);
		return $fragmentFiles;
	}//end fragmentFiles()

	/**
	 * The configuration file's path relative to the Nextcloud root.
	 *
	 * The root is taken as three levels above the app directory; when that
	 * does not give an `apps-extra/` or `apps/` path, the standard one is used.
	 *
	 * @param string $appPath The absolute app directory.
	 * @param string $absoluteFilePath The absolute configuration file path.
	 *
	 * @return string The relative path.
	 *
	 * @spec exclude Internal path step of readRegisterConfiguration().
	 */
	private static function relativeSourcePath(string $appPath, string $absoluteFilePath): string {
		$nextcloudRoot = dirname(dirname(dirname($appPath)));
		$relativeFilePath = str_replace($nextcloudRoot . '/', '', $absoluteFilePath);

		if (str_starts_with($relativeFilePath, 'apps-extra/') === false
			&& str_starts_with($relativeFilePath, 'apps/') === false
		) {
			return 'apps-extra/opencatalogi/lib/Settings/publication_register.json';
		}

		return $relativeFilePath;
	}//end relativeSourcePath()

	/**
	 * Set the payload's source url and type where it does not declare them.
	 *
	 * The cron job reads them to track the file location.
	 *
	 * @param array<mixed> $data The configuration payload.
	 * @param string $sourceUrl The relative file path.
	 *
	 * @return array<mixed> The payload with its source set.
	 *
	 * @spec exclude Internal source step of readRegisterConfiguration().
	 */
	private static function withSourceTracking(array $data, string $sourceUrl): array {
		if (isset($data['x-openregister']) === false) {
			$data['x-openregister'] = [];
		}

		if (isset($data['x-openregister']['sourceUrl']) === false) {
			$data['x-openregister']['sourceUrl'] = $sourceUrl;
		}

		if (isset($data['x-openregister']['sourceType']) === false) {
			$data['x-openregister']['sourceType'] = 'local';
		}

		return $data;
	}//end withSourceTracking()

	/**
	 * Deep-merge a register fragment onto the base config (ADR-037).
	 *
	 * Associative arrays (OpenAPI objects like `components.schemas`, `paths`) are
	 * merged by key union (recursing on shared keys); list arrays are concatenated;
	 * scalars in the fragment overwrite the base. Disjoint fragments never collide.
	 *
	 * @param array<mixed> $base The accumulated config.
	 * @param array<mixed> $overlay The fragment to merge in.
	 *
	 * @return array<mixed> The merged config.
	 */
	private static function deepMergeConfig(array $base, array $overlay): array {
		foreach ($overlay as $key => $value) {
			$base[$key] = self::mergedConfigValue(base: ($base[$key] ?? null), overlay: $value);
		}

		return $base;
	}//end deepMergeConfig()

	/**
	 * One key of a deep merge: two lists concatenate, two arrays merge, anything else takes the overlay.
	 *
	 * @param mixed $base The base value, null when the key is new.
	 * @param mixed $overlay The fragment's value.
	 *
	 * @return mixed The merged value.
	 */
	private static function mergedConfigValue(mixed $base, mixed $overlay): mixed {
		if (is_array($overlay) === false || is_array($base) === false) {
			return $overlay;
		}

		if (self::isConfigList(value: $base) === true && self::isConfigList(value: $overlay) === true) {
			return array_merge($base, $overlay);
		}

		return self::deepMergeConfig(base: $base, overlay: $overlay);
	}//end mergedConfigValue()

	/**
	 * Whether a config array is a list (empty, or keyed 0..n-1 in order).
	 *
	 * @param array<mixed> $value The array.
	 *
	 * @return bool True for a list.
	 */
	private static function isConfigList(array $value): bool {
		return $value === [] || array_keys($value) === range(0, (count($value) - 1));
	}//end isConfigList()

	/**
	 * Attach every unreferenced `components.schemas` slug to the publication register.
	 *
	 * Runs on the fully merged payload (monolith + every register.d fragment). A slug
	 * that no register in the payload declares is added to the publication register's
	 * schema list, plus a magic-mapping/auto-create-table entry so its physical table is
	 * provisioned like every other schema in that register.
	 *
	 * Idempotent, and never reorders or drops an existing declaration.
	 *
	 * @param array<mixed> $data The merged configuration payload.
	 *
	 * @return array<mixed> The payload with stray schemas attached.
	 */
	private static function attachOrphanSchemasToPublicationRegister(array $data): array {
		// The publication register must already be declared (it lives in the monolith);
		// without it there is nothing to attach to.
		$schemas = ($data['components']['schemas'] ?? null);
		$register = ($data['components']['registers']['publication'] ?? null);
		if (is_array($schemas) === false || $schemas === [] || is_array($register) === false) {
			return $data;
		}

		// Every slug any register in this payload already declares.
		$declared = self::collectDeclaredSchemaSlugs(registers: ($data['components']['registers'] ?? []));
		foreach (self::orphanSlugs(schemas: $schemas, declared: $declared) as $slug) {
			$register = self::withOrphanSchema(register: $register, slug: $slug);
		}

		$data['components']['registers']['publication'] = $register;
		return $data;
	}//end attachOrphanSchemasToPublicationRegister()

	/**
	 * The string schema slugs no register declares, in `components.schemas` order.
	 *
	 * @param array<mixed> $schemas The `components.schemas` map.
	 * @param array<string, bool> $declared The declared slugs, keyed by slug.
	 *
	 * @return array<int, string> The orphan slugs.
	 */
	private static function orphanSlugs(array $schemas, array $declared): array {
		$orphans = [];
		foreach (array_keys($schemas) as $slug) {
			if (is_string($slug) === true && isset($declared[$slug]) === false) {
				$orphans[] = $slug;
			}
		}

		return $orphans;
	}//end orphanSlugs()

	/**
	 * Add one schema slug to the publication register, with the magic-mapping default.
	 *
	 * @param array<mixed> $register The publication register.
	 * @param string $slug The schema slug no register declares.
	 *
	 * @return array<mixed> The register.
	 */
	private static function withOrphanSchema(array $register, string $slug): array {
		$register['schemas'][] = $slug;

		// Only supply the magic-mapping default when the fragment said nothing —
		// an explicit `magicMapping: false` stays untouched.
		if (isset($register['configuration']['schemas'][$slug]) === false) {
			$register['configuration']['schemas'][$slug] = [
				'magicMapping' => true,
				'autoCreateTable' => true,
				'comment' => 'Auto-attached: declared in components.schemas without a register reference.',
			];
		}

		return $register;
	}//end withOrphanSchema()

	/**
	 * Collect every schema slug that any register in the payload already declares.
	 *
	 * @param array<mixed> $registers The `components.registers` map from the payload.
	 *
	 * @return array<string, boolean> Declared slugs, keyed by slug for O(1) lookup.
	 *
	 * @spec exclude Internal collection step of attachOrphanSchemasToPublicationRegister().
	 */
	private static function collectDeclaredSchemaSlugs(array $registers): array {
		$declared = [];
		foreach ($registers as $registerData) {
			foreach (self::declaredSlugsOf(register: $registerData) as $declaredSlug) {
				$declared[$declaredSlug] = true;
			}
		}

		return $declared;
	}//end collectDeclaredSchemaSlugs()

	/**
	 * The string schema slugs one register declares.
	 *
	 * @param mixed $register One entry of `components.registers`.
	 *
	 * @return array<int|string, string> The slugs, or none when the register has no schema list.
	 *
	 * @spec exclude Internal collection step of attachOrphanSchemasToPublicationRegister().
	 */
	private static function declaredSlugsOf(mixed $register): array {
		if (is_array($register) === false || is_array($register['schemas'] ?? null) === false) {
			return [];
		}

		return array_filter($register['schemas'], 'is_string');
	}//end declaredSlugsOf()

	/**
	 * Update the app configuration with imported schema and register IDs.
	 *
	 * After importing the configuration from OpenRegister, this method updates
	 * the app configuration with the actual schema and register IDs so that
	 * the frontend can construct correct API URLs.
	 *
	 * @param array $importResult The result from the importFromApp call containing registers and schemas.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 * @spec openspec/changes/fix-woo-capability-provisioning/specs/woo-transparency/spec.md#requirement-woo-config-keys-are-auto-configured-on-install-and-repair-woo-prov-002
	 */
	private function updateObjectTypeConfiguration(array $importResult): void {
		$schemaMap = $this->fillMissingSchemaIds(schemaMap: $this->mapImportedSchemaIds(schemas: ($importResult['schemas'] ?? [])));
		$registerId = $this->findImportedPublicationRegisterId(registers: ($importResult['registers'] ?? []));
		if ($registerId === null) {
			$registerId = $this->lookUpPublicationRegisterId();
		}

		foreach (self::OBJECT_TYPES as $type) {
			$this->writeObjectTypeConfiguration(prefix: $type, schemaId: ($schemaMap[$type] ?? null), registerId: $registerId);
		}

		foreach (self::OOAPI_TYPE_MAP as $configPrefix => $schemaSlug) {
			$this->writeObjectTypeConfiguration(prefix: $configPrefix, schemaId: ($schemaMap[$schemaSlug] ?? null), registerId: $registerId);
		}

		foreach (self::KEYED_SCHEMA_GROUPS as $group) {
			$this->writeKeyedSchemaGroup(group: $group, schemaMap: $schemaMap, registerId: $registerId);
		}

		// The organisation is OpenRegister's, so its keys come from there.
		$this->resolveSharedOrganisationConfiguration();
	}//end updateObjectTypeConfiguration()

	/**
	 * Map schema slugs to ids from an import result, in the entity or the array shape.
	 *
	 * An entity counts only with both slug and id; an array row needs a slug and
	 * falls back from id to uuid.
	 *
	 * @param iterable $schemas The imported schemas.
	 *
	 * @return array<string, mixed> Schema id by slug.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	private function mapImportedSchemaIds(iterable $schemas): array {
		$schemaMap = [];
		foreach ($schemas as $schema) {
			$data = $this->importedEntityData(entity: $schema);
			if ($data === null || isset($data['slug']) === false) {
				continue;
			}

			if (is_object($schema) === true && isset($data['id']) === false) {
				continue;
			}

			$schemaMap[$data['slug']] = ($data['id'] ?? $data['uuid'] ?? null);
		}

		return $schemaMap;
	}//end mapImportedSchemaIds()

	/**
	 * The id of the `publication` register in an import result, or null.
	 *
	 * @param iterable $registers The imported registers.
	 *
	 * @return mixed
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	private function findImportedPublicationRegisterId(iterable $registers) {
		foreach ($registers as $register) {
			$data = $this->importedEntityData(entity: $register);
			if (isset($data['slug']) === true && $data['slug'] === 'publication') {
				return ($data['id'] ?? $data['uuid'] ?? null);
			}
		}

		return null;
	}//end findImportedPublicationRegisterId()

	/**
	 * An imported register or schema as an array: entities through jsonSerialize(), arrays as they are.
	 *
	 * @param mixed $entity The imported row.
	 *
	 * @return array|null Null when the row has neither shape.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	private function importedEntityData($entity): ?array {
		if (is_object($entity) === true) {
			if (method_exists($entity, 'jsonSerialize') === false) {
				return null;
			}

			$entity = $entity->jsonSerialize();
		}

		if (is_array($entity) === false) {
			return null;
		}

		return $entity;
	}//end importedEntityData()

	/**
	 * Look up, scoped to this app, every expected schema slug the import result did not carry.
	 *
	 * `importFromApp(force: false)` skips an unchanged app version and then returns no
	 * schemas, so keys cleared by hand since the last import (a setup wizard reset)
	 * would stay unset although the schemas exist. A failing lookup is logged: the
	 * missing configuration surfaces through registersConfigured().
	 *
	 * @param array $schemaMap Schema id by slug from the import result.
	 *
	 * @return array Schema id by slug, completed where the lookup found one.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	private function fillMissingSchemaIds(array $schemaMap): array {
		$missingSlugs = array_diff($this->expectedSchemaSlugs(), array_keys($schemaMap));
		if (empty($missingSlugs) === true) {
			return $schemaMap;
		}

		try {
			$schemaMapper = $this->container->get(\OCA\OpenRegister\Db\SchemaMapper::class);
			foreach ($missingSlugs as $slug) {
				$found = $schemaMapper->findByApplicationAndSlug($slug, AppIdentity::APP_ID);
				if ($found !== null) {
					$schemaMap[$slug] = $found->getId();
				}
			}
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Schema slug fallback lookup failed: ' . $e->getMessage(),
				['app' => AppIdentity::APP_ID]
			);
		}

		return $schemaMap;
	}//end fillMissingSchemaIds()

	/**
	 * Every schema slug this app configures, in configuration order.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	private function expectedSchemaSlugs(): array {
		$slugs = array_merge(self::OBJECT_TYPES, array_values(self::OOAPI_TYPE_MAP));
		foreach (self::KEYED_SCHEMA_GROUPS as $group) {
			$slugs = array_merge($slugs, array_values($group['schemas']));
		}

		return $slugs;
	}//end expectedSchemaSlugs()

	/**
	 * Look up the `publication` register by slug when the import result did not carry it.
	 *
	 * @return mixed The register id, or null when the lookup fails (logged).
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	private function lookUpPublicationRegisterId() {
		try {
			// The find() call accepts a slug and returns the entity, unlike findIdsBySlugs().
			$registerMapper = $this->container->get(\OCA\OpenRegister\Db\RegisterMapper::class);
			return $registerMapper->find('publication')->getId();
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Register slug fallback lookup failed: ' . $e->getMessage(),
				['app' => AppIdentity::APP_ID]
			);
			return null;
		}
	}//end lookUpPublicationRegisterId()

	/**
	 * Write `<prefix>_source`, and `<prefix>_schema` and `<prefix>_register` when known.
	 *
	 * @param string $prefix The config key prefix.
	 * @param mixed $schemaId The schema id, or null.
	 * @param mixed $registerId The register id, or null.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	private function writeObjectTypeConfiguration(string $prefix, $schemaId, $registerId): void {
		$this->config->setValueString($this->appName, "{$prefix}_source", 'openregister');

		if ($schemaId !== null) {
			$this->config->setValueString($this->appName, "{$prefix}_schema", (string)$schemaId);
		}

		if ($registerId !== null) {
			$this->config->setValueString($this->appName, "{$prefix}_register", (string)$registerId);
		}
	}//end writeObjectTypeConfiguration()

	/**
	 * Write a group's register key (when it has one and the id is known) and each known schema id.
	 *
	 * A value is never overwritten with an empty one.
	 *
	 * @param array $group The group: `register` (key or null) and `schemas` (key => slug).
	 * @param array $schemaMap Schema id by slug.
	 * @param mixed $registerId The register id, or null.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	private function writeKeyedSchemaGroup(array $group, array $schemaMap, $registerId): void {
		if ($group['register'] !== null && $registerId !== null) {
			$this->config->setValueString($this->appName, $group['register'], (string)$registerId);
		}

		foreach ($group['schemas'] as $configKey => $schemaSlug) {
			if (isset($schemaMap[$schemaSlug]) === true) {
				$this->config->setValueString($this->appName, $configKey, (string)$schemaMap[$schemaSlug]);
			}
		}
	}//end writeKeyedSchemaGroup()

	/**
	 * Point the `organization_*` keys at OpenRegister's shared organisation.
	 *
	 * The organisation used to be a schema this app shipped, which is why the
	 * slug collided with stackiq's copy: a schema slug is global per
	 * organisation. It is OpenRegister's `nc-organisation` projection now, so it
	 * cannot be resolved from this app's own import result the way every other
	 * object type is.
	 *
	 * The three keys keep their names on purpose. `objectStore.getCollection()`
	 * resolves a type through `<type>_source` / `_schema` / `_register` and cares
	 * about nothing else, so pointing those at OpenRegister's directory register
	 * moves the whole frontend onto the shared organisation without a single
	 * change to a Vue file.
	 *
	 * Failure is soft. OpenRegister may be absent, or an older version may not
	 * carry the projection yet. Neither is a reason to fail an import, and a
	 * missing key surfaces as a picker with nothing to offer rather than as a
	 * broken install.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/publications-reference-the-shared-organisation/specs/shared-organisation/spec.md#requirement-the-organisation-comes-from-openregister-req-sho-101
	 */
	private function resolveSharedOrganisationConfiguration(): void {
		try {
			$registerMapper = $this->getRegisterMapper();
			$schemaMapper = $this->getSchemaMapper();
			$register = $registerMapper?->find(self::SHARED_ORGANISATION_REGISTER);
			$schema = $schemaMapper?->find(self::SHARED_ORGANISATION_SCHEMA);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'OpenCatalogi: the shared organisation is unavailable, leaving organization_* unset',
				['error' => $e->getMessage()]
			);
			return;
		}

		if ($register === null || $schema === null) {
			return;
		}

		$this->config->setValueString($this->appName, 'organization_source', 'openregister');
		$this->config->setValueString($this->appName, 'organization_register', (string)$register->getId());
		$this->config->setValueString($this->appName, 'organization_schema', (string)$schema->getId());
	}//end resolveSharedOrganisationConfiguration()

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
	public function backfillCatalogScopes(): void {
		try {
			$run = $this->catalogScopeBackfillRun();
			if ($run === null) {
				return;
			}

			foreach ($this->catalogsToBackfill(run: $run) as $catalog) {
				$catalogData = $this->catalogArray(catalog: $catalog);
				if ($catalogData !== null) {
					$this->backfillCatalogScope(run: $run, catalogData: $catalogData);
				}
			}

			// What a later register event can still complete (stackiq installed
			// after OpenCatalogi). CatalogScopePendingListener reads this.
			$this->config->setValueString(
				$this->appName,
				self::CATALOG_SCOPE_PENDING_KEY,
				(string)json_encode(array_values(array_unique($run->pending)))
			);
		} catch (\Exception) {
			// Never let backfill failure sink the settings import — the wizard
			// still needs to declare success so the app is at least usable.
			return;
		}//end try

	}//end backfillCatalogScopes()

	/**
	 * Start a backfill run, or null when there is nothing to backfill against.
	 *
	 * A config gap is surfaced by the setup wizard's reload-settings step.
	 *
	 * @return CatalogScopeBackfill|null
	 *
	 * @spec openspec/changes/publish-from-stackiq/specs/publish-from-stackiq/spec.md#requirement-req-pfs-004-installing-stackiq-after-opencatalogi-completes-the-scope
	 */
	private function catalogScopeBackfillRun(): ?CatalogScopeBackfill {
		$run = new CatalogScopeBackfill(config: $this->config, appName: $this->appName);
		if ($run->isConfigured() === false
			|| in_array('openregister', $this->appManager->getInstalledApps(), true) === false
		) {
			return null;
		}

		$run->objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		return $run;
	}//end catalogScopeBackfillRun()

	/**
	 * Every catalogue, published or not.
	 *
	 * This runs from the import and from register events, often without a user
	 * session, and an unpublished seeded catalogue still needs its scope resolved.
	 *
	 * @param CatalogScopeBackfill $run The backfill run.
	 *
	 * @return array<int, mixed>
	 *
	 * @spec openspec/changes/publish-from-stackiq/specs/publish-from-stackiq/spec.md#requirement-req-pfs-004-installing-stackiq-after-opencatalogi-completes-the-scope
	 */
	private function catalogsToBackfill(CatalogScopeBackfill $run): array {
		$catalogs = $run->objectService->searchObjects(
			query: $run->catalogQuery(),
			_rbac: false,
			_multitenancy: false
		);
		if (is_array($catalogs) === false) {
			return [];
		}

		return $catalogs;
	}//end catalogsToBackfill()

	/**
	 * A catalogue as an array, or null when it is neither an array nor serialises to one.
	 *
	 * @param mixed $catalog The catalogue as the search returned it.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec exclude Type-narrowing plumbing.
	 */
	private function catalogArray(mixed $catalog): ?array {
		if (is_object($catalog) === true && method_exists($catalog, 'jsonSerialize') === true) {
			$catalog = $catalog->jsonSerialize();
		}

		if (is_array($catalog) === false) {
			return null;
		}

		return $catalog;
	}//end catalogArray()

	/**
	 * Resolve one catalogue's scope slugs, patch a missing scope, and save it.
	 *
	 * A catalogue whose scope is already set, holds no slugs and is not due its
	 * one automatic publication is left alone. A failed save is skipped: the
	 * admin can retro-fit it via the catalog edit view.
	 *
	 * @param CatalogScopeBackfill $run The backfill run.
	 * @param array<string, mixed> $catalogData The catalogue as stored.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/publish-from-stackiq/specs/publish-from-stackiq/spec.md#requirement-req-pfs-004-installing-stackiq-after-opencatalogi-completes-the-scope
	 */
	private function backfillCatalogScope(CatalogScopeBackfill $run, array $catalogData): void {
		$scope = $this->resolvedCatalogScope(run: $run, catalogData: $catalogData);
		$patch = $this->catalogScopePatch(run: $run, scope: $scope);
		if ($patch === null) {
			return;
		}

		try {
			$run->objectService->saveObject(
				object: $patch['object'],
				register: $run->catalogRegister,
				schema: $run->catalogSchema,
				uuid: $patch['uuid'],
				_rbac: false,
				_multitenancy: false,
			);
			if ($patch['publishedSlug'] !== null) {
				$this->markSeedPublished(slug: $patch['publishedSlug']);
			}
		} catch (\Exception) {
			return;
		}

	}//end backfillCatalogScope()

	/**
	 * One catalogue with the slugs in its scope resolved to ids where they can be.
	 *
	 * A seeded catalogue names its scope by slug (the ids only exist after the
	 * import). Schema slugs resolve only inside the catalogue's own registers: a
	 * bare slug like `module` can name another app's schema. What cannot resolve
	 * yet is added to the run's pending list.
	 *
	 * @param CatalogScopeBackfill $run The backfill run.
	 * @param array<string, mixed> $catalogData The catalogue as stored.
	 *
	 * @return array{data: array<string, mixed>, registers: mixed, schemas: mixed, changed: bool}
	 *
	 * @spec openspec/changes/publish-from-stackiq/specs/publish-from-stackiq/spec.md#requirement-req-pfs-002-a-schema-slug-resolves-only-inside-the-catalogues-own-registers
	 */
	private function resolvedCatalogScope(CatalogScopeBackfill $run, array $catalogData): array {
		$scope = [
			'data' => $catalogData,
			'registers' => ($catalogData['registers'] ?? null),
			'schemas' => ($catalogData['schemas'] ?? null),
			'changed' => false,
		];
		if (is_array($scope['registers']) === false || is_array($scope['schemas']) === false) {
			return $scope;
		}

		$resolved = $this->resolveScopeSlugs(registers: $scope['registers'], schemas: $scope['schemas']);
		$run->pending = array_merge($run->pending, $resolved['pending']);
		$scope['registers'] = $resolved['registers'];
		$scope['schemas'] = $resolved['schemas'];
		$scope['changed'] = $resolved['changed'];
		if ($resolved['changed'] === true) {
			$scope['data']['registers'] = $resolved['registers'];
			$scope['data']['schemas'] = $resolved['schemas'];
		}

		return $scope;
	}//end resolvedCatalogScope()

	/**
	 * The object to save for one catalogue, or null when it needs no save.
	 *
	 * OpenRegister's saveObject re-validates against the full schema, so the
	 * patch is the whole current object (minus the server-owned `@self`) with
	 * an empty scope filled from the publication register and schema. A seeded
	 * catalogue due its one automatic publication gets a `published` date.
	 *
	 * @param CatalogScopeBackfill $run The backfill run.
	 * @param array{data: array<string, mixed>, registers: mixed, schemas: mixed, changed: bool} $scope The resolved catalogue.
	 *
	 * @return array{object: array<string, mixed>, uuid: string, publishedSlug: string|null}|null
	 *
	 * @spec openspec/changes/publish-from-stackiq/specs/publish-from-stackiq/spec.md#requirement-req-pfs-001-opencatalogi-seeds-an-unpublished-applicatielandschap-catalogue-over-stackiq
	 */
	private function catalogScopePatch(CatalogScopeBackfill $run, array $scope): ?array {
		$changes = $this->scopeFills(run: $run, scope: $scope) + $this->publicationStamp(scope: $scope);
		$catalogId = ($scope['data']['@self']['id'] ?? ($scope['data']['id'] ?? null));
		if (($changes === [] && $scope['changed'] === false) || $catalogId === null) {
			return null;
		}

		$merged = $scope['data'];
		unset($merged['@self']);

		return [
			'object' => $this->withIsoDateTimes(object: array_replace($merged, $changes)),
			'uuid' => (string)$catalogId,
			'publishedSlug' => $this->publishedSlugOf(catalog: $scope['data'], changes: $changes),
		];
	}//end catalogScopePatch()

	/**
	 * The publication register and schema for each empty scope list of a catalogue.
	 *
	 * @param CatalogScopeBackfill $run The backfill run.
	 * @param array{data: array<string, mixed>, registers: mixed, schemas: mixed, changed: bool} $scope The resolved catalogue.
	 *
	 * @return array<string, array<int, mixed>> The `registers` and `schemas` fills, each only when empty.
	 *
	 * @spec openspec/changes/publish-from-stackiq/specs/publish-from-stackiq/spec.md#requirement-req-pfs-001-opencatalogi-seeds-an-unpublished-applicatielandschap-catalogue-over-stackiq
	 */
	private function scopeFills(CatalogScopeBackfill $run, array $scope): array {
		$fills = [];
		if ($this->isEmptyScopeList(value: $scope['registers']) === true) {
			$fills['registers'] = [$run->publicationRegister];
		}

		if ($this->isEmptyScopeList(value: $scope['schemas']) === true) {
			$fills['schemas'] = [$run->publicationSchema];
		}

		return $fills;
	}//end scopeFills()

	/**
	 * The `published` date of a seeded catalogue due its one automatic publication, or nothing.
	 *
	 * @param array{data: array<string, mixed>, registers: mixed, schemas: mixed, changed: bool} $scope The resolved catalogue.
	 *
	 * @return array<string, string> The `published` field, or an empty array.
	 *
	 * @spec openspec/changes/publish-from-stackiq/specs/publish-from-stackiq/spec.md#requirement-req-pfs-001-opencatalogi-seeds-an-unpublished-applicatielandschap-catalogue-over-stackiq
	 */
	private function publicationStamp(array $scope): array {
		if ($this->shouldPublishResolvedSeed(scope: $scope) === false) {
			return [];
		}

		return ['published' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM)];
	}//end publicationStamp()

	/**
	 * The slug of a catalogue the patch publishes, or null.
	 *
	 * @param array<string, mixed> $catalog The catalogue as stored.
	 * @param array<string, mixed> $changes The fields the patch sets.
	 *
	 * @return string|null The slug when the patch sets `published`.
	 *
	 * @spec openspec/changes/publish-from-stackiq/specs/publish-from-stackiq/spec.md#requirement-req-pfs-001-opencatalogi-seeds-an-unpublished-applicatielandschap-catalogue-over-stackiq
	 */
	private function publishedSlugOf(array $catalog, array $changes): ?string {
		if (isset($changes['published']) === false) {
			return null;
		}

		return (string)$catalog['slug'];
	}//end publishedSlugOf()

	/**
	 * Whether a scope list is missing or empty.
	 *
	 * @param mixed $value The registers or schemas of a scope.
	 *
	 * @return bool
	 *
	 * @spec exclude Type-narrowing plumbing.
	 */
	private function isEmptyScopeList(mixed $value): bool {
		return $value === null || (is_array($value) === true && count($value) === 0);
	}//end isEmptyScopeList()

	/**
	 * The object with every SQL-style "YYYY-MM-DD HH:MM:SS" string as full ISO 8601.
	 *
	 * Persisted date-time fields can come back in that shape, which fails the
	 * schema's ISO 8601 format validator on re-save. Other values are kept.
	 *
	 * @param array<string, mixed> $object The object to save.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/publish-from-stackiq/specs/publish-from-stackiq/spec.md#requirement-req-pfs-004-installing-stackiq-after-opencatalogi-completes-the-scope
	 */
	private function withIsoDateTimes(array $object): array {
		foreach ($object as $key => $value) {
			if (is_string($value) === true
				&& preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value) === 1
			) {
				$object[$key] = str_replace(' ', 'T', $value) . '+00:00';
			}
		}

		return $object;
	}//end withIsoDateTimes()

	/**
	 * Whether a seeded catalogue is due its one automatic publication.
	 *
	 * True only for a slug in PUBLISH_WHEN_RESOLVED that has no `published`
	 * date, whose scope holds ids only, and that was never published this way
	 * before. The last condition keeps an administrator's later unpublish.
	 *
	 * @param array{data: array<string, mixed>, registers: mixed, schemas: mixed, changed: bool} $scope The resolved catalogue.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/publish-from-stackiq/specs/publish-from-stackiq/spec.md#requirement-req-pfs-001-opencatalogi-seeds-an-unpublished-applicatielandschap-catalogue-over-stackiq
	 */
	private function shouldPublishResolvedSeed(array $scope): bool {
		$slug = ($scope['data']['slug'] ?? null);
		if (in_array($slug, self::PUBLISH_WHEN_RESOLVED, true) === false
			|| empty($scope['data']['published']) === false
			|| $this->isIdList(entries: $scope['registers']) === false
			|| $this->isIdList(entries: $scope['schemas']) === false
		) {
			return false;
		}

		return in_array($slug, $this->publishedSeeds(), true) === false;
	}//end shouldPublishResolvedSeed()

	/**
	 * Whether a scope list is a non-empty list of ids only.
	 *
	 * @param mixed $entries The registers or schemas of a scope.
	 *
	 * @return bool False for a missing or empty list, or one that still holds a slug.
	 *
	 * @spec openspec/changes/publish-from-stackiq/specs/publish-from-stackiq/spec.md#requirement-req-pfs-001-opencatalogi-seeds-an-unpublished-applicatielandschap-catalogue-over-stackiq
	 */
	private function isIdList(mixed $entries): bool {
		return is_array($entries) === true && $entries !== []
			&& (new CatalogScopeSlugResolver())->hasSlug(entries: $entries) === false;
	}//end isIdList()

	/**
	 * The seeded catalogues already published automatically once.
	 *
	 * @return list<string>
	 */
	private function publishedSeeds(): array {
		$done = json_decode($this->config->getValueString($this->appName, self::SEEDS_PUBLISHED_KEY, '[]'), true);
		if (is_array($done) === false) {
			return [];
		}

		return array_values(array_filter($done, 'is_string'));
	}//end publishedSeeds()

	/**
	 * Remember that a seeded catalogue had its one automatic publication.
	 *
	 * @param string $slug The catalogue slug.
	 *
	 * @return void
	 */
	private function markSeedPublished(string $slug): void {
		$done = $this->publishedSeeds();
		$done[] = $slug;
		$this->config->setValueString(
			$this->appName,
			self::SEEDS_PUBLISHED_KEY,
			(string)json_encode(array_values(array_unique($done)))
		);
	}//end markSeedPublished()

	/**
	 * Resolve the slugs in one catalogue scope against OpenRegister.
	 *
	 * Registers resolve by slug. Schemas resolve only among the schemas those
	 * registers list (see CatalogScopeSlugResolver::resolveScope()). Lookups
	 * bypass RBAC and multitenancy: this runs from the import and from register
	 * events, where there is often no user session.
	 *
	 * @param array<int, mixed> $registers The scope's registers as stored.
	 * @param array<int, mixed> $schemas The scope's schemas as stored.
	 *
	 * @return array{registers: array<int, mixed>, schemas: array<int, mixed>, changed: bool, pending: list<string>}
	 *
	 * @spec openspec/changes/publish-from-stackiq/specs/publish-from-stackiq/spec.md#requirement-req-pfs-002-a-schema-slug-resolves-only-inside-the-catalogues-own-registers
	 */
	private function resolveScopeSlugs(array $registers, array $schemas): array {
		$registerMapper = $this->getRegisterMapper();
		$schemaMapper = $this->getSchemaMapper();

		return (new CatalogScopeSlugResolver())->resolveScope(
			registers: $registers,
			schemas: $schemas,
			lookups: new CatalogScopeLookups(
				findRegisterId: static fn (string $slug) => $registerMapper?->find($slug, _rbac: false, _multitenancy: false)?->getId(),
				schemaIdsOfRegister: static fn (int $registerId): array => (
					$registerMapper?->find($registerId, _rbac: false, _multitenancy: false)?->getSchemas() ?? []
				),
				schemaSlugOf: static fn (int $schemaId): ?string => $schemaMapper?->find($schemaId, _rbac: false, _multitenancy: false)?->getSlug()
			)
		);
	}//end resolveScopeSlugs()

	/**
	 * Check if settings should be loaded based on version comparison.
	 *
	 * This method compares the current app version with the stored configuration
	 * version to determine if a settings import is needed.
	 *
	 * @return boolean True if settings should be loaded, false otherwise.
	 * @throws \RuntimeException If version checking fails.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	private function shouldLoadSettings(): bool {
		try {
			// Get the current app version.
			$currentAppVersion = $this->appManager->getAppVersion(AppIdentity::APP_ID);

			// Get the configuration service. to check stored version.
			$configurationService = $this->getConfigurationService();
			$storedVersion = $configurationService->getConfiguredAppVersion(AppIdentity::APP_ID);

			// If no stored version exists, we need to load settings.
			if ($storedVersion === null) {
				return true;
			}

			// Compare versions using semantic versioning.
			// Load settings if current version is newer than stored version.
			return version_compare(version1: $currentAppVersion, version2: $storedVersion, operator: '>');
		} catch (\Exception $e) {
			// If we can't determine versions, err on the side of loading settings.
			return true;
		}//end try

	}//end shouldLoadSettings()

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
	public function getVersionInfo(): array {
		try {
			// Get the current app version.
			$currentAppVersion = $this->appManager->getAppVersion(AppIdentity::APP_ID);

			// Get the configuration service. to check stored version.
			$configurationService = $this->getConfigurationService();
			$storedConfigVersion = $configurationService->getConfiguredAppVersion(AppIdentity::APP_ID);

			// Determine if versions match.
			$versionsMatch = $storedConfigVersion !== null
						   && version_compare(version1: $currentAppVersion, version2: $storedConfigVersion, operator: '=');

			return [
				'appName' => 'OpenCatalogi',
				'appVersion' => $currentAppVersion,
				'configuredVersion' => $storedConfigVersion,
				'versionsMatch' => $versionsMatch,
				'needsUpdate' => $storedConfigVersion === null
							   || version_compare(version1: $currentAppVersion, version2: $storedConfigVersion, operator: '>'),
			];
		} catch (\Exception $e) {
			throw new RuntimeException('Failed to get version information: ' . $e->getMessage());
		}//end try

	}//end getVersionInfo()

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
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function manualImport(bool $forceImport = false): array {
		try {
			// Get version info first.
			$versionInfo = $this->getVersionInfo();

			// Check if import is needed (unless forced).
			if ($forceImport === false && $versionInfo['versionsMatch'] === true) {
				return [
					'success' => false,
					'message' => 'Configuration is already up to date. Use force import if you want to reimport.',
					'versionInfo' => $versionInfo,
				];
			}

			// Perform the import.
			$importResult = $this->loadSettings(force: $forceImport);

			// Get updated version info.
			$updatedVersionInfo = $this->getVersionInfo();

			return [
				'success' => true,
				'message' => 'Configuration imported successfully.',
				'importResult' => $importResult,
				'versionInfo' => $updatedVersionInfo,
			];
		} catch (\Exception $e) {
			return [
				'success' => false,
				'message' => 'Import failed: ' . $e->getMessage(),
				'error' => $e->getMessage(),
			];
		}//end try

	}//end manualImport()
}//end class
