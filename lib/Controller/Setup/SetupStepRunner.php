<?php

/**
 * Dispatches a setup action id to the step that runs it.
 *
 * @category Controller
 * @package  OCA\OpenCatalogi\Controller\Setup
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

namespace OCA\OpenCatalogi\Controller\Setup;

use OCA\OpenCatalogi\Contract\DemoDataServiceInterface;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Hands each setup action id to the demo-data, settings, catalog or federation step that runs it.
 *
 * @spec openspec/specs/first-time-setup/spec.md
 */
class SetupStepRunner {
	/**
	 * Constructor.
	 *
	 * @param SetupDemoDataSteps $demoSteps The demo-data steps.
	 * @param SetupSettingsStep $settingsStep The register re-import step.
	 * @param SetupCatalogSteps $catalogSteps The first-catalog step.
	 * @param SetupFederationSteps $federationSteps The federation steps.
	 */
	public function __construct(
		private readonly SetupDemoDataSteps $demoSteps,
		private readonly SetupSettingsStep $settingsStep,
		private readonly SetupCatalogSteps $catalogSteps,
		private readonly SetupFederationSteps $federationSteps,
	) {
	}//end __construct()

	/**
	 * Run the setup step behind an action id, or answer null for an id that is no step.
	 *
	 * @param string $actionId The action id from the manifest.
	 * @param IRequest $request The request, for the dataset a card posts.
	 *
	 * @return JSONResponse|null The step's `{ success, message }` answer.
	 *
	 * @spec openspec/specs/first-time-setup/spec.md#requirement-setup-server-contract-endpoints-onb-005
	 */
	public function run(string $actionId, IRequest $request): ?JSONResponse {
		// `install-demo-data` is the id the step used before it asked WHICH
		// dataset, and it still means "import the one this app ships". Kept
		// so an older manifest or a runbook that posts it keeps working.
		return match ($actionId) {
			'load-demo-data' => $this->demoSteps->loadDataset(request: $request),
			'install-demo-data' => $this->demoSteps->installDemoData(dataset: DemoDataServiceInterface::DEMO_DATASET),
			'skip-demo-data' => $this->demoSteps->skipDemoData(),
			'reload-settings' => $this->settingsStep->reloadSettings(),
			'create-first-catalog' => $this->catalogSteps->createFirstCatalog(),
			'connect-federation' => $this->federationSteps->connectFederation(),
			'sync-all-directories' => $this->federationSteps->syncAllDirectories(),
			default => null,
		};

	}//end run()

}//end class
