<?php

/**
 * The first-time-setup status the wizard reads.
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
use OCA\OpenCatalogi\Contract\DemoDataServiceInterface;
use OCA\OpenCatalogi\Contract\DirectoryServiceInterface;
use OCP\IAppConfig;

/**
 * Derives the status of every setup step from real app-config and object state.
 *
 * @spec openspec/specs/first-time-setup/spec.md
 */
class SetupStatusReport {
	/**
	 * Constructor.
	 *
	 * @param IAppConfig $config App configuration.
	 * @param DemoDataServiceInterface $demoDataService Demo dataset import, for the dataset choices.
	 * @param DirectoryServiceInterface $directoryService Directory service, for the default directory URL.
	 * @param SetupCatalogSteps $catalogSteps Register and catalog state.
	 */
	public function __construct(
		private readonly IAppConfig $config,
		private readonly DemoDataServiceInterface $demoDataService,
		private readonly DirectoryServiceInterface $directoryService,
		private readonly SetupCatalogSteps $catalogSteps,
	) {
	}//end __construct()

	/**
	 * The first-time-setup status: `{ version, completed, registersReady, datasets, steps }`.
	 *
	 * Each step's `done` is computed from real state. It exposes only booleans
	 * and the dataset choices, no sensitive values.
	 *
	 * @return array<string, mixed> The status payload.
	 *
	 * @spec openspec/specs/first-time-setup/spec.md#requirement-setup-server-contract-endpoints-onb-005
	 * @spec openspec/changes/wizard-dataset-card-load/specs/first-time-setup/spec.md
	 */
	public function payload(): array {
		$registersWired = $this->catalogSteps->registersConfigured();
		$scopeChosen = ($this->config->getValueString(AppIdentity::APP_ID, 'default_catalog_scope', '') !== '');
		$catalogReady = $this->catalogSteps->catalogExists();
		$federationDone = $this->defaultDirectorySubscribed();
		// The sync-all-directories step is idempotent maintenance work — it
		// just re-hits every registered peer. Rather than track "last synced
		// at" in a separate config key (and then have to invalidate it on
		// schedule), treat the step as done as soon as connect-federation is,
		// because that guarantees at least one sync happened. The wizard
		// never gates completion on this step, so the false-then-true window
		// is only cosmetic.
		$syncDone = $federationDone;

		// DEALT WITH, not "demo objects exist". An operator who declines demo
		// data has finished the step; re-offering it on every visit would make
		// "no thanks" impossible to express.
		$demoDecided = ($this->config->getValueString(AppIdentity::APP_ID, SetupDemoDataSteps::DEMO_DATA_DECIDED_KEY, '') !== '');
		$pickedDataset = $this->config->getValueString(AppIdentity::APP_ID, SetupDemoDataSteps::DATASET_KEY, '');

		// Completed = every gating (required) step is satisfied. The optional
		// federation step never blocks completion. The register check is not a
		// wizard step any more: the admin settings page re-imports the
		// configuration (wizard-dataset-card-load), and `registersReady`
		// reports its state as information.
		$completed = ($scopeChosen === true && $catalogReady === true);

		// Exactly the ids of `manifest.setup.steps`: a step the server never
		// reports stays open and reopens the wizard.
		$steps = [
			'welcome' => ['done' => true],
			// A pick without a load still counts: a wizard that predates
			// `loadAction` can only record the pick.
			'demo-data' => ['done' => ($demoDecided === true || $pickedDataset !== '')],
			'catalog-scope' => ['done' => $scopeChosen],
			'create-catalog' => ['done' => $catalogReady],
			'connect-federation' => ['done' => $federationDone],
			'sync-all-directories' => ['done' => $syncDone],
			'done' => ['done' => $completed],
		];

		return [
			'version' => SetupCatalogSteps::SETUP_VERSION,
			'completed' => $completed,
			'registersReady' => $registersWired,
			// The choice step reads its options from here: it declares
			// `optionsSource: datasets` and no options of its own, so a
			// dataset missing from this list is a dataset nobody can pick.
			'datasets' => $this->demoDataService->listChoices(),
			'steps' => $steps,
		];

	}//end payload()

	/**
	 * Whether the default national directory is already a known listing.
	 *
	 * @return boolean True when a listing references the default directory URL.
	 */
	private function defaultDirectorySubscribed(): bool {
		$objectService = $this->catalogSteps->getObjectService();
		if ($objectService === null) {
			return false;
		}

		$register = $this->config->getValueString(AppIdentity::APP_ID, 'listing_register', '');
		$schema = $this->config->getValueString(AppIdentity::APP_ID, 'listing_schema', '');
		if ($register === '' || $schema === '') {
			return false;
		}

		$defaultUrl = $this->directoryService->getDefaultDirectoryUrl();

		try {
			$listings = $objectService->searchObjects(
				['@self' => ['register' => $register, 'schema' => $schema]],
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Exception $e) {
			return false;
		}

		if (is_array($listings) === false) {
			return false;
		}

		foreach ($listings as $listing) {
			$data = $listing->jsonSerialize();
			$obj = ($data['object'] ?? $data);
			if (($obj['directory'] ?? null) === $defaultUrl) {
				return true;
			}
		}

		return false;
	}//end defaultDirectorySubscribed()
}//end class
