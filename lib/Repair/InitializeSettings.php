<?php

/**
 * Repair step for initializing OpenCatalogi settings.
 *
 * @category Repair
 * @package  OCA\OpenCatalogi\Repair
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
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Repair;

use OCA\OpenCatalogi\AppInfo\Application;
use OCA\OpenCatalogi\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Repair step that initializes OpenCatalogi settings on install/upgrade.
 *
 * This runs only during app install or upgrade, not on every request.
 * The configuration import is idempotent - running multiple times
 * will not create duplicates.
 */
class InitializeSettings implements IRepairStep {
	/**
	 * Constructor.
	 *
	 * @param IAppManager $appManager The app manager.
	 * @param ContainerInterface $container The container.
	 */
	/**
	 * The app-config key this step exists to produce.
	 *
	 * `loadSettings()` writes one `{type}_register` / `{type}_schema` pair per
	 * object type. If this one is still empty afterwards, the import did not
	 * land, whatever it reported.
	 */
	private const OUTCOME_KEY = 'publication_register';

	/**
	 * Constructor.
	 *
	 * @param IAppManager $appManager The app manager.
	 * @param ContainerInterface $container The container.
	 * @param IAppConfig $config App configuration, for the outcome check.
	 * @param LoggerInterface $logger Logger, so a failed import is visible after the install scrolls past.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly IAppConfig $config,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Get the name of this repair step.
	 *
	 * @return string
	 */
	public function getName(): string {
		return 'Initialize OpenCatalogi settings';
	}//end getName()

	/**
	 * Run the repair step.
	 *
	 * @param IOutput $output The output interface.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function run(IOutput $output): void {
		$output->startProgress(2);

		// Check if OpenRegister is available (required dependency).
		if (in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
			$output->warning('OpenRegister app is not installed - skipping configuration import');
			$output->advance(2);
			$output->finishProgress();
			return;
		}

		$output->info('Loading OpenCatalogi configuration...');
		$output->advance(1);

		try {
			// Get the settings service and load configuration.
			// The import is idempotent.
			$settingsService = $this->container->get(SettingsService::class);
			$result = $settingsService->loadSettings(force: false);

			$registerCount = count($result['registers'] ?? []);
			$schemaCount = count($result['schemas'] ?? []);
			$objectCount = count($result['objects'] ?? []);

			$output->info(
				"Configuration loaded: {$registerCount} registers, {$schemaCount} schemas, {$objectCount} objects"
			);
		} catch (\Exception $e) {
			// Non-fatal: a repair step that throws aborts `occ app:install`, which
			// would trade a misconfigured app for an uninstalled one.
			$output->warning('Failed to load configuration: ' . $e->getMessage());
			$this->logger->error(
				'OpenCatalogi configuration import threw during the install repair step',
				['app' => Application::APP_ID, 'exception' => $e]
			);
		}//end try

		// VERIFY THE OUTCOME. THE STEP RUNNING IS NOT THE OUTCOME.
		//
		// This app shipped a whole release cycle in which the configuration was
		// never imported on a fresh instance, because the step was registered
		// only under <post-migration> and a first install runs neither pre- nor
		// post-migration steps. Nothing noticed, because nothing asked. The
		// <install> registration in appinfo/info.xml is the fix for that specific
		// bug; this check is what makes the NEXT one of its kind loud instead of
		// silent, whatever its cause — a step that does not run, an import that
		// version-skips and returns a well-formed result describing zero work, or
		// the catch above turning a hard failure into a warning that scrolls past
		// during install. All three end in the same place: an app that looks
		// installed and has no registers.
		//
		// Measured 2026-08-27 on a clean instance built from the released
		// opencatalogi 1.0.9: `occ config:list opencatalogi` held only
		// installed_version/types/enabled, no OpenCatalogi register existed, and
		// `/apps/opencatalogi/api/directory` answered `{"results":[],"total":0}` —
		// which reads as "no federation peers" rather than "never configured".
		if ($this->configuredAlready() === false) {
			$output->warning(
				'OpenCatalogi configuration did not land; the app has no register or schema '
				. 'configuration. Run `occ opencatalogi:settings:load --force`, or use '
				. 'Settings -> OpenCatalogi -> Reload configuration.'
			);
			$this->logger->error(
				'OpenCatalogi install completed with no register configuration — '
				. self::OUTCOME_KEY . ' is still empty after the import repair step.',
				['app' => Application::APP_ID]
			);
		}

		$output->advance(1);
		$output->finishProgress();

	}//end run()


	/**
	 * Whether the register configuration this step produces is already present.
	 *
	 * @return boolean True when the app already has its register configuration.
	 *
	 * @spec exclude Outcome probe for the install repair step; reads one config key, no domain behaviour.
	 */
	private function configuredAlready(): bool {
		return $this->config->getValueString(Application::APP_ID, self::OUTCOME_KEY, '') !== '';
	}//end configuredAlready()
}//end class
