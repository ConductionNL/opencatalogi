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

use OCA\OpenCatalogi\BackgroundJob\FinishUpgradeAfterOpenRegister;
use OCA\OpenCatalogi\Contract\AppIdentity;
use OCA\OpenCatalogi\Contract\SettingsServiceInterface;
use OCP\App\IAppManager;
use OCP\BackgroundJob\IJobList;
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

		if ($this->openRegisterWaits(output: $output) === true) {
			$output->advance(2);
			$output->finishProgress();
			return;
		}

		$output->info('Loading OpenCatalogi configuration...');
		$output->advance(1);

		$this->importConfiguration(output: $output);
		$this->verifyOutcome(output: $output);

		$output->advance(1);
		$output->finishProgress();

	}//end run()

	/**
	 * Whether OpenRegister still waits for its own upgrade in this run; if so, finish later.
	 *
	 * `occ upgrade` takes the apps of one type in alphabetical order, so opencatalogi
	 * is upgraded before openregister. Importing then wrote against OpenRegister's
	 * old tables and recorded this app's new version, so every later import skipped:
	 * the schemas this version added never landed and the post-migration steps after
	 * this one failed on them (measured: 6 public publications before an upgrade from
	 * 2.1.0, 0 after). Upgrading OpenRegister from here is no way out: Nextcloud's
	 * repair runner is shared, and a nested upgrade runs this step again without end.
	 * So the import waits, and FinishUpgradeAfterOpenRegister runs this app's
	 * post-migration steps once OpenRegister is current.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return bool True when the import was deferred.
	 *
	 * @spec openspec/changes/publication-lifecycle-on-or/specs/publications/spec.md#requirement-existing-publications-get-a-stored-state-on-upgrade-req-plc-007
	 */
	private function openRegisterWaits(IOutput $output): bool {
		try {
			if ($this->appManager->isUpgradeRequired(appId: 'openregister') === false) {
				return false;
			}

			$this->container->get(IJobList::class)->add(job: FinishUpgradeAfterOpenRegister::class);
		} catch (\Throwable $e) {
			$this->logger->error(
				'OpenCatalogi could not defer its configuration import until OpenRegister is upgraded',
				['app' => AppIdentity::APP_ID, 'exception' => $e]
			);
			return false;
		}

		$output->warning(
			'OpenRegister is upgraded after this app in this run. The configuration import and the '
			. 'publication backfills run from a background job once it is; run cron to finish the upgrade.'
		);
		return true;

	}//end openRegisterWaits()

	/**
	 * Import the app's registers, schemas and objects, reporting a failure without throwing.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 */
	private function importConfiguration(IOutput $output): void {
		try {
			// Get the settings service and load configuration.
			// The import is idempotent.
			$settingsService = $this->container->get(SettingsServiceInterface::class);
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
				['app' => AppIdentity::APP_ID, 'exception' => $e]
			);
		}//end try

	}//end importConfiguration()

	/**
	 * Warn loudly when the import left the app without register configuration.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 */
	private function verifyOutcome(IOutput $output): void {
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
				. 'configuration. Use Settings -> OpenCatalogi -> Reload configuration, or '
				. 'POST /index.php/apps/opencatalogi/api/settings/import with {"force": true}.'
			);
			$this->logger->error(
				'OpenCatalogi install completed with no register configuration — '
				. self::OUTCOME_KEY . ' is still empty after the import repair step.',
				['app' => AppIdentity::APP_ID]
			);
		}

	}//end verifyOutcome()

	/**
	 * Whether the register configuration this step produces is already present.
	 *
	 * @return boolean True when the app already has its register configuration.
	 *
	 * @spec exclude Outcome probe for the install repair step; reads one config key, no domain behaviour.
	 */
	private function configuredAlready(): bool {
		return $this->config->getValueString(AppIdentity::APP_ID, self::OUTCOME_KEY, '') !== '';
	}//end configuredAlready()
}//end class
