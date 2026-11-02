<?php

/**
 * The setup steps that wire the publishing registers and create the first catalog.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Setup
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
 *
 * @spec openspec/specs/first-time-setup/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Setup;

use OCA\OpenCatalogi\Contract\AppIdentity;
use OCA\OpenCatalogi\Contract\SettingsServiceInterface;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IL10N;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Reloads the register configuration, reports whether the registers are wired and a catalog exists,
 * and creates the first catalog with system privileges.
 *
 * @spec openspec/specs/first-time-setup/spec.md
 */
class SetupCatalogSteps {
	/**
	 * The setup contract version. Must match `setup.version` in src/manifest.json.
	 * Bumping it re-gates the wizard for steps that key off completion.
	 *
	 * @var integer
	 */
	public const SETUP_VERSION = 3;

	/**
	 * Required register/schema keys whose presence means publishing is wired.
	 *
	 * @var string[]
	 */
	private const REQUIRED_REGISTER_KEYS = [
		'catalog_register',
		'catalog_schema',
		'publication_register',
		'listing_register',
	];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $config App configuration.
	 * @param ContainerInterface $container Container, to resolve OpenRegister ObjectService.
	 * @param SettingsServiceInterface $settingsService Settings service (register import).
	 * @param IL10N $l10n Localization.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly IAppConfig $config,
		private readonly ContainerInterface $container,
		private readonly SettingsServiceInterface $settingsService,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Re-import the register configuration (config-check remediation).
	 *
	 * @return JSONResponse The result.
	 *
	 * @spec openspec/specs/first-time-setup/spec.md#requirement-setup-server-contract-endpoints-onb-005
	 */
	public function reloadSettings(): JSONResponse {
		try {
			$this->settingsService->loadSettings(force: false);
		} catch (\Exception $e) {
			$this->logger->error('Setup reload-settings failed: ' . $e->getMessage(), ['app' => AppIdentity::APP_ID]);
			return new JSONResponse(['success' => false, 'message' => $e->getMessage()]);
		}

		if ($this->registersConfigured() === false) {
			return new JSONResponse(
				[
					'success' => false,
					'message' => $this->l10n->t('Publishing registers are still not configured. Is OpenRegister installed and enabled?'),
				]
			);
		}

		return new JSONResponse(['success' => true, 'message' => $this->l10n->t('Publishing prerequisites are configured.')]);
	}//end reloadSettings()

	/**
	 * Create the user's first catalog (idempotent — no-op when one exists).
	 *
	 * A catalog scopes what publications are visible in the federated /search
	 * and the local Publications list: `PublicationService::getCatalogFilters()`
	 * unions each catalog's `registers` and `schemas` arrays and only rows in
	 * that scope are returned. A catalog seeded with both arrays empty
	 * therefore renders the entire install unusable — federation returns 0
	 * local rows, and the WOO-527 diagnostic "Catalog is not configured for
	 * publications" fires in every create-publication modal. Seed the arrays
	 * with the same publication_register / publication_schema values the app
	 * is already configured against (WOO-529).
	 *
	 * @return JSONResponse The result.
	 *
	 * @spec openspec/specs/first-time-setup/spec.md#requirement-setup-server-contract-endpoints-onb-005
	 */
	public function createFirstCatalog(): JSONResponse {
		if ($this->catalogExists() === true) {
			// Mark onboarding seen so a later empty state does not re-gate.
			$this->config->setValueString(AppIdentity::APP_ID, 'onboarding_completed_version', (string)self::SETUP_VERSION);
			return new JSONResponse(['success' => true, 'message' => $this->l10n->t('You already have a catalog.')]);
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return new JSONResponse(['success' => false, 'message' => $this->l10n->t('OpenRegister is not available.')]);
		}

		$register = $this->config->getValueString(AppIdentity::APP_ID, 'catalog_register', '');
		$schema = $this->config->getValueString(AppIdentity::APP_ID, 'catalog_schema', '');
		if ($register === '' || $schema === '') {
			return new JSONResponse(['success' => false, 'message' => $this->l10n->t('Configure the catalog register and schema first.')]);
		}

		return $this->saveFirstCatalog(objectService: $objectService, target: ['register' => $register, 'schema' => $schema]);
	}//end createFirstCatalog()

	/**
	 * Store the first catalog in the catalog register and schema, then mark onboarding done.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param array<string, string> $target The catalog `register` and `schema`.
	 *
	 * @return JSONResponse The result.
	 */
	private function saveFirstCatalog(object $objectService, array $target): JSONResponse {
		$catalog = $this->firstCatalogObject();
		try {
			$objectService->saveObject(
				object: $catalog,
				register: $target['register'],
				schema: $target['schema'],
				_rbac: false,
				_multitenancy: false,
			);
		} catch (\Exception $e) {
			$this->logger->error('Setup create-first-catalog failed: ' . $e->getMessage(), ['app' => AppIdentity::APP_ID]);
			return new JSONResponse(['success' => false, 'message' => $e->getMessage()]);
		}

		// Onboarding has produced a catalog; record it so the gate clears for good.
		$this->config->setValueString(AppIdentity::APP_ID, 'onboarding_completed_version', (string)self::SETUP_VERSION);

		return new JSONResponse(['success' => true, 'message' => $this->l10n->t('Your first catalog was created.')]);
	}//end saveFirstCatalog()

	/**
	 * The first catalog: listed for a public default scope, staff-restricted for a private one.
	 *
	 * @return array<string, mixed> The catalog object.
	 */
	private function firstCatalogObject(): array {
		// Resolve the publication register/schema that this catalog should
		// scope. Both values are already required for a working install (the
		// publish flow depends on them), so a missing value here is a config
		// problem that reload-settings is expected to surface — we still fall
		// back to an unscoped catalog rather than aborting, so an admin can
		// recover by attaching the register/schema later.
		$scope = $this->config->getValueString(AppIdentity::APP_ID, 'default_catalog_scope', 'public');

		$catalogObject = [
			'title' => $this->l10n->t('My first catalog'),
			'summary' => $this->l10n->t('Your first OpenCatalogi catalog — add publications to start sharing them openly.'),
			'description' => $this->l10n->t('Created during first-time setup. Rename it and adjust its access to suit your organisation.'),
			// A public default scope makes the catalog discoverable in the federated directory.
			'listed' => ($scope === 'public'),
			'status' => 'development',
		];
		if ($scope === 'private') {
			$catalogObject['staffAccess'] = $this->privateStaffAccess();
		}

		return $this->withPublicationScope(catalogObject: $catalogObject);
	}//end firstCatalogObject()

	/**
	 * Staff access for a private first catalog: the groups named in `default_catalog_groups`.
	 *
	 * "Private (explicitly granted groups only)" restricts the catalogue's staff
	 * access to those groups (comma separated); none named leaves it to
	 * administrators until groups are granted.
	 *
	 * @return array<string, mixed> The staff access.
	 */
	private function privateStaffAccess(): array {
		$configured = $this->config->getValueString(AppIdentity::APP_ID, 'default_catalog_groups', '');
		$groups = array_values(array_filter(array_map('trim', explode(',', $configured))));

		return ['restricted' => true, 'readGroups' => $groups, 'editGroups' => $groups];
	}//end privateStaffAccess()

	/**
	 * A catalog object scoped to the configured publication register and schema, when they are set (WOO-529).
	 *
	 * Each key is read immediately above the check that handles its empty case.
	 *
	 * @param array<string, mixed> $catalogObject The catalog object.
	 *
	 * @return array<string, mixed> The scoped catalog object.
	 */
	private function withPublicationScope(array $catalogObject): array {
		$publicationRegister = $this->config->getValueString(AppIdentity::APP_ID, 'publication_register', '');
		if ($publicationRegister !== '') {
			$catalogObject['registers'] = [$publicationRegister];
		}

		$publicationSchema = $this->config->getValueString(AppIdentity::APP_ID, 'publication_schema', '');
		if ($publicationSchema !== '') {
			$catalogObject['schemas'] = [$publicationSchema];
		}

		return $catalogObject;
	}//end withPublicationScope()

	/**
	 * Whether all required publishing registers/schemas are configured.
	 *
	 * @return boolean True when every required key is non-empty.
	 *
	 * @spec openspec/specs/first-time-setup/spec.md#requirement-setup-server-contract-endpoints-onb-005
	 */
	public function registersConfigured(): bool {
		foreach (self::REQUIRED_REGISTER_KEYS as $key) {
			if ($this->config->getValueString(AppIdentity::APP_ID, $key, '') === '') {
				return false;
			}
		}

		return true;
	}//end registersConfigured()

	/**
	 * Whether at least one catalog exists, or onboarding has already been completed.
	 *
	 * The onboarding-completed fallback keeps the create-catalog gate from
	 * re-triggering on an instance whose catalogs were later removed.
	 *
	 * @return boolean True when a catalog is present or onboarding is done.
	 *
	 * @spec openspec/specs/first-time-setup/spec.md#requirement-setup-server-contract-endpoints-onb-005
	 */
	public function catalogExists(): bool {
		if ($this->config->getValueString(AppIdentity::APP_ID, 'onboarding_completed_version', '') === (string)self::SETUP_VERSION) {
			return true;
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return false;
		}

		$register = $this->config->getValueString(AppIdentity::APP_ID, 'catalog_register', '');
		$schema = $this->config->getValueString(AppIdentity::APP_ID, 'catalog_schema', '');
		if ($register === '' || $schema === '') {
			return false;
		}

		try {
			$catalogs = $objectService->searchObjects(
				['@self' => ['register' => $register, 'schema' => $schema]],
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Exception $e) {
			return false;
		}

		return is_array($catalogs) === true && count($catalogs) > 0;
	}//end catalogExists()

	/**
	 * Resolve the OpenRegister ObjectService from the container.
	 *
	 * @return \OCA\OpenRegister\Service\ObjectService|null The service, or null when unavailable.
	 *
	 * @spec openspec/specs/first-time-setup/spec.md#requirement-setup-server-contract-endpoints-onb-005
	 */
	public function getObjectService(): ?\OCA\OpenRegister\Service\ObjectService {
		try {
			return $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (\Exception $e) {
			return null;
		}

	}//end getObjectService()
}//end class
