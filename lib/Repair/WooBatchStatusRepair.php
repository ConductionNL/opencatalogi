<?php

/**
 * WooBatchStatusRepair
 *
 * Maps the stored `status` of every Woo batch onto the states the declared
 * `wooBatch` lifecycle knows (woo-review-surface REQ-WRV-002). A value the
 * lifecycle does not know, or none, becomes `in_progress`, the initial state,
 * so the batch goes through review again instead of standing in a state no
 * transition leaves.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Repair
 * @package  OCA\OpenCatalogi\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/woo-review-surface/tasks.md#task-1.1
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Repair;

use OCA\OpenCatalogi\Service\WooService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Puts every Woo batch in a state of the declared lifecycle.
 *
 * Idempotent: a batch already in a known state is not written, so a second
 * run writes nothing. Without OpenRegister or the configured batch schema
 * there is nothing to map and the step says so.
 *
 * @spec openspec/changes/woo-review-surface/tasks.md#task-1.1
 */
class WooBatchStatusRepair implements IRepairStep {

	/**
	 * The most batches read in one run.
	 *
	 * @var int
	 */
	private const LIMIT = 10000;

	/**
	 * Constructor.
	 *
	 * @param WooService      $woo    Resolves OpenRegister and the batch schema.
	 * @param LoggerInterface $logger For a batch that could not be written.
	 */
	public function __construct(
		private readonly WooService $woo,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/woo-review-surface/tasks.md#task-1.1
	 */
	public function getName(): string {
		return 'Map stored Woo batch statuses onto the batch lifecycle';
	}//end getName()

	/**
	 * Map every batch with an unknown status to `in_progress`.
	 *
	 * @param IOutput $output The repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-review-surface/tasks.md#task-1.1
	 */
	public function run(IOutput $output): void {
		$objectService = $this->woo->getObjectService();
		$register      = $this->woo->getRegister();
		$schema        = $this->woo->getBatchSchema();
		if ($objectService === null || $register === null || $schema === null) {
			$output->info('Woo batch statuses: OpenRegister or the batch schema is not configured, nothing to map.');
			return;
		}

		$mapped = 0;
		foreach ($this->batches(objectService: $objectService, register: $register, schema: $schema) as $batch) {
			if (in_array((string)($batch['status'] ?? ''), WooService::BATCH_STATUSES, true) === true) {
				continue;
			}

			$batch['status'] = 'in_progress';
			try {
				$objectService->saveObject(
					object: $batch,
					register: $register,
					schema: $schema,
					uuid: (string)($batch['id'] ?? ''),
					_rbac: false,
					_multitenancy: false,
				);
				$mapped++;
			} catch (Throwable $e) {
				$this->logger->warning('[WooBatchStatusRepair] Could not map the status of batch ' . (string)($batch['id'] ?? '') . ': ' . $e->getMessage());
			}
		}

		$output->info(sprintf('Woo batch statuses: %d batch(es) set to in_progress.', $mapped));
	}//end run()

	/**
	 * Every batch in the register, as plain arrays with their id.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param string $register      The register id.
	 * @param string $schema        The batch schema id.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function batches(object $objectService, string $register, string $schema): array {
		try {
			$found = $objectService->setRegister($register)->setSchema($schema)->findAll(['limit' => self::LIMIT, '_rbac' => false]);
		} catch (Throwable $e) {
			$this->logger->warning('[WooBatchStatusRepair] Could not read the batches: ' . $e->getMessage());
			return [];
		}

		$batches = [];
		foreach ($found as $candidate) {
			if (is_object($candidate) === true && method_exists($candidate, 'jsonSerialize') === true) {
				$candidate = $candidate->jsonSerialize();
			}

			if (is_array($candidate) === true) {
				$candidate['id'] = (string)($candidate['id'] ?? ($candidate['@self']['id'] ?? ''));
				$batches[]       = $candidate;
			}
		}

		return $batches;
	}//end batches()
}//end class
