<?php

/**
 * The setup steps that load, install or skip the demo dataset.
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
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Loads the dataset the operator picked or posted, installs the demo data, or records that it was skipped.
 *
 * @spec openspec/specs/first-time-setup/spec.md
 */
class SetupDemoDataSteps {
	/**
	 * App-config key recording that the optional demo-data step has been dealt with.
	 *
	 * Records a DECISION, not a state: "installed" and "declined" both set it.
	 * A step that reports itself undone until demo objects exist can never be
	 * completed by an operator who does not want them.
	 *
	 * @var string
	 */
	public const DEMO_DATA_DECIDED_KEY = 'demo_data_decided';

	/**
	 * App-config key holding the dataset the operator picked.
	 *
	 * The wizard's `choice` step writes it through `POST /api/setup/config`, and
	 * the `run-action` step that follows reads it back. Two steps rather than
	 * one because `CnSetupWizard::runAction()` posts to
	 * `/api/setup/action/{action}` with no body: an action cannot carry the
	 * answer, so the answer has to be stored before the action runs.
	 *
	 * @var string
	 */
	public const DATASET_KEY = 'demo_dataset';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $config App configuration.
	 * @param DemoDataServiceInterface $demoDataService Demo dataset import (ADR-111 rule 4).
	 * @param IRequest $request The request, for the dataset a card posts.
	 * @param IL10N $l10n Localization.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly IAppConfig $config,
		private readonly DemoDataServiceInterface $demoDataService,
		private readonly IRequest $request,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Install the dataset a card's Load button posted as `dataset`, or the
	 * stored pick when nothing is posted (ADR-111 rule 4).
	 *
	 * @return JSONResponse The outcome, carrying the counts.
	 *
	 * @spec openspec/changes/wizard-dataset-card-load/specs/first-time-setup/spec.md
	 */
	public function loadDataset(): JSONResponse {
		$picked = $this->config->getValueString(AppIdentity::APP_ID, self::DATASET_KEY, '');

		// The card's Load button names its dataset in the body. An older wizard
		// posts nothing and relies on the pick stored a step earlier. Nothing is
		// stored before the load succeeds: a failed load must leave the step
		// open for an operator who asked for data and got none.
		$posted = $this->request->getParam('dataset');
		if ($posted !== null) {
			$refusal = $this->refuseDataset(value: $posted);
			if ($refusal !== null) {
				return $refusal;
			}

			$picked = (string)$posted;
		}

		// 🔴 NO SILENT DEFAULT. Importing here because the operator clicked Run
		// one step early would plant example objects nobody asked for.
		if ($picked === '') {
			return new JSONResponse(
				['success' => false, 'message' => $this->l10n->t('Pick a dataset first.')],
				Http::STATUS_BAD_REQUEST
			);
		}

		if ($picked === DemoDataServiceInterface::NONE_DATASET) {
			$this->config->setValueString(AppIdentity::APP_ID, self::DATASET_KEY, DemoDataServiceInterface::NONE_DATASET);
			$this->config->setValueString(AppIdentity::APP_ID, self::DEMO_DATA_DECIDED_KEY, 'skipped');

			return new JSONResponse(
				['success' => true, 'message' => $this->l10n->t('No example data was loaded.')]
			);
		}

		return $this->installDemoData(dataset: $picked);

	}//end loadDataset()

	/**
	 * Refuse a posted dataset id no dataset answers to.
	 *
	 * @param mixed $value The posted value.
	 *
	 * @return JSONResponse|null The refusal, or null when the dataset is known.
	 *
	 * @spec openspec/changes/wizard-dataset-card-load/specs/first-time-setup/spec.md
	 */
	private function refuseDataset(mixed $value): ?JSONResponse {
		$named = 'that';
		if (is_scalar($value) === true) {
			$named = (string)$value;
		}

		$known = array_column($this->demoDataService->listChoices(), 'id');
		if (is_scalar($value) === true && in_array($named, $known, true) === true) {
			return null;
		}

		return new JSONResponse(
			['success' => false, 'message' => $this->l10n->t('No dataset is called "%s".', [$named])],
			Http::STATUS_BAD_REQUEST
		);

	}//end refuseDataset()

	/**
	 * Import the dataset this app ships.
	 *
	 * @param string $dataset The dataset id, recorded as the pick once the
	 *                        import succeeded.
	 *
	 * @return JSONResponse `{ success, message }`.
	 *
	 * @spec openspec/changes/wizard-dataset-card-load/specs/first-time-setup/spec.md
	 */
	public function installDemoData(string $dataset): JSONResponse {
		try {
			$imported = $this->demoDataService->install();
		} catch (\Throwable $e) {
			$this->logger->error('Setup install-demo-data failed: ' . $e->getMessage(), ['app' => AppIdentity::APP_ID]);
			return new JSONResponse(['success' => false, 'message' => $e->getMessage()]);
		}

		// The decision is recorded only after the import actually returned.
		// Marking it first would let a failed install present as a finished step.
		// Loading IS choosing the set, so the pick is recorded too.
		$this->config->setValueString(AppIdentity::APP_ID, self::DATASET_KEY, $dataset);
		$this->config->setValueString(AppIdentity::APP_ID, self::DEMO_DATA_DECIDED_KEY, 'installed');

		// 🔴 THE COUNTS, ALWAYS. "Demo data installed" with no numbers cannot be
		// told apart from an import that wrote nothing.
		return new JSONResponse(
			[
				'success' => true,
				'message' => $this->l10n->t(
					'Demo data installed: %1$s objects across %2$s schemas.',
					[$imported['objects'], $imported['schemas']]
				),
				'detail'  => $imported,
			]
		);
	}//end installDemoData()

	/**
	 * Record that the operator declined the demo dataset.
	 *
	 * Its own action so "no thanks" is a decision the wizard can record. Without
	 * it the only way past the step would be to install demo data, which is
	 * wrong on a production instance.
	 *
	 * @return JSONResponse The outcome.
	 *
	 * @spec openspec/specs/first-time-setup/spec.md#requirement-setup-server-contract-endpoints-onb-005
	 */
	public function skipDemoData(): JSONResponse {
		// Skipping IS choosing "None", so both keys are written: an older
		// runbook may read either one.
		$this->config->setValueString(AppIdentity::APP_ID, self::DATASET_KEY, DemoDataServiceInterface::NONE_DATASET);
		$this->config->setValueString(AppIdentity::APP_ID, self::DEMO_DATA_DECIDED_KEY, 'skipped');

		return new JSONResponse(
			[
				'success' => true,
				'message' => $this->l10n->t('Demo data skipped.'),
			]
		);
	}//end skipDemoData()
}//end class
