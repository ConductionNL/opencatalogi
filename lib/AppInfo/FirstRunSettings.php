<?php

/**
 * OpenCatalogi first-run settings import.
 *
 * Nextcloud does not run <post-migration> repair steps on `occ app:install` or
 * `app:enable`, so on a clean install InitializeSettings never imports the
 * registers and schemas, and every page fails with "Invalid configuration for
 * object type" until someone runs a repair by hand. boot() calls this once:
 * when OpenRegister is installed and the import has not succeeded yet, it
 * runs the same idempotent loadSettings(force: false) and records success in
 * the `settings_initialized` app-config flag, so later requests skip it.
 *
 * @category AppInfo
 * @package  OCA\OpenCatalogi\AppInfo
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
 * @spec openspec/changes/robust-first-run-setup/specs/first-time-onboarding/spec.md#requirement-setup-wizard-gates-the-shell-on-required-publishing-configuration-onb-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\AppInfo;

use OCA\OpenCatalogi\Contract\AppIdentity;
use OCA\OpenCatalogi\Contract\SettingsServiceInterface;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Imports the configuration once on the first request after a clean install.
 *
 * @spec openspec/changes/robust-first-run-setup/specs/first-time-onboarding/spec.md#requirement-setup-wizard-gates-the-shell-on-required-publishing-configuration-onb-001
 */
class FirstRunSettings {

	/**
	 * The app-config flag set once the import succeeded.
	 *
	 * @var string
	 */
	public const FLAG = 'settings_initialized';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig         $config     App config, for the flag.
	 * @param IAppManager        $appManager Whether OpenRegister is installed.
	 * @param ContainerInterface $container  Resolves the settings service only when it is needed.
	 * @param LoggerInterface    $logger     Records a failed import.
	 */
	public function __construct(
		private readonly IAppConfig $config,
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Import the configuration unless that already succeeded.
	 *
	 * A failure is logged and leaves the flag unset, so the next request tries
	 * again; it never reaches the request that triggered it.
	 *
	 * @return bool True when the import ran and succeeded on this call.
	 *
	 * @spec openspec/changes/robust-first-run-setup/specs/first-time-onboarding/spec.md#requirement-setup-wizard-gates-the-shell-on-required-publishing-configuration-onb-001
	 */
	public function ensureInitialized(): bool {
		if ($this->config->getValueString(AppIdentity::APP_ID, self::FLAG, '') === 'true') {
			return false;
		}

		// Same guard as InitializeSettings: without OpenRegister there is nothing to import into.
		if (in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
			return false;
		}

		try {
			$this->container->get(SettingsServiceInterface::class)->loadSettings(force: false);
			$this->config->setValueString(AppIdentity::APP_ID, self::FLAG, 'true');
		} catch (Throwable $exception) {
			$this->logger->error(
				'OpenCatalogi first-run configuration import failed; it is tried again on the next request',
				['app' => AppIdentity::APP_ID, 'exception' => $exception]
			);
			return false;
		}

		return true;

	}//end ensureInitialized()
}//end class
