<?php

/**
 * The setup step that re-imports the register configuration.
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
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * Re-imports the register configuration and reports whether the publishing registers are wired afterwards.
 *
 * @spec openspec/specs/first-time-setup/spec.md
 */
class SetupSettingsStep {
	/**
	 * Constructor.
	 *
	 * @param SettingsServiceInterface $settingsService Settings service (register import).
	 * @param SetupCatalogSteps $catalogSteps Whether the registers are wired.
	 * @param IL10N $l10n Localization.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly SettingsServiceInterface $settingsService,
		private readonly SetupCatalogSteps $catalogSteps,
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

		if ($this->catalogSteps->registersConfigured() === false) {
			return new JSONResponse(
				[
					'success' => false,
					'message' => $this->l10n->t('Publishing registers are still not configured. Is OpenRegister installed and enabled?'),
				]
			);
		}

		return new JSONResponse(['success' => true, 'message' => $this->l10n->t('Publishing prerequisites are configured.')]);
	}//end reloadSettings()
}//end class
