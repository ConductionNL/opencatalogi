<?php

/**
 * The setup config writes and the privileged setup actions.
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
use OCA\OpenCatalogi\Contract\ConnectionReporterInterface;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Writes the whitelisted setup keys and runs the setup actions the wizard posts.
 *
 * @spec openspec/specs/first-time-setup/spec.md
 */
class SetupActions {
	/**
	 * App-config keys the config endpoint is allowed to write. A whitelist keeps
	 * the generic setup-config POST from writing arbitrary configuration.
	 *
	 * @var string[]
	 */
	private const WRITABLE_CONFIG_KEYS = [
		'default_catalog_scope',
		'default_catalog_groups',
		'default_directory_url',
		SetupDemoDataSteps::DATASET_KEY,
	];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $config App configuration.
	 * @param IL10N $l10n Localization.
	 * @param SetupStepRunner $steps The setup steps by action id.
	 * @param ConnectionReporterInterface|null $connectionReporter Asks integriq to look again after a save, or nothing when absent.
	 */
	public function __construct(
		private readonly IAppConfig $config,
		private readonly IL10N $l10n,
		private readonly SetupStepRunner $steps,
		private readonly ?ConnectionReporterInterface $connectionReporter = null,
	) {
	}//end __construct()

	/**
	 * Write the whitelisted setup keys present in the posted parameters.
	 *
	 * Then asks integriq to look again at the saved keys, when it is present.
	 *
	 * @param array<string, mixed> $params The posted parameters.
	 *
	 * @return string[] The keys that were written.
	 *
	 * @spec openspec/specs/first-time-setup/spec.md#requirement-setup-server-contract-endpoints-onb-005
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-oc-conn-002-a-save-asks-integriq-to-look-again
	 */
	public function saveConfig(array $params): array {
		$saved = [];

		foreach (self::WRITABLE_CONFIG_KEYS as $key) {
			if (array_key_exists($key, $params) === false) {
				continue;
			}

			$this->config->setValueString(AppIdentity::APP_ID, $key, (string)$params[$key]);
			$saved[] = $key;
		}

		$this->connectionReporter?->refreshFromSave(savedKeys: $saved);

		return $saved;
	}//end saveConfig()

	/**
	 * Run one setup action and answer with its outcome.
	 *
	 * @param string   $actionId The action id from the manifest.
	 * @param IRequest $request  The request, for the dataset a card posts.
	 *
	 * @return JSONResponse `{ success, message }`, 400 for an unknown action.
	 *
	 * @spec openspec/specs/first-time-setup/spec.md#requirement-setup-server-contract-endpoints-onb-005
	 */
	public function run(string $actionId, IRequest $request): JSONResponse {
		$answer = $this->steps->run(actionId: $actionId, request: $request);
		if ($answer !== null) {
			return $answer;
		}

		switch ($actionId) {
			case 'complete':
				$this->config->setValueString(AppIdentity::APP_ID, 'onboarding_completed_version', (string)SetupCatalogSteps::SETUP_VERSION);
				return new JSONResponse(['success' => true, 'message' => $this->l10n->t('Setup complete.')]);
			default:
				return new JSONResponse(
					['success' => false, 'message' => $this->l10n->t('Unknown setup action.')],
					Http::STATUS_BAD_REQUEST
				);
		}//end switch

	}//end run()
}//end class
