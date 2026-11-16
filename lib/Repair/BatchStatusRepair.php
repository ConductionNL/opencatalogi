<?php

/**
 * BatchStatusRepair
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

use OCA\OpenCatalogi\Contract\TransparencyWorkflowServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
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
class BatchStatusRepair implements IRepairStep {

	/**
	 * The states the declared `wooBatch` lifecycle knows.
	 *
	 * @var array<int, string>
	 */
	public const STATUSES = ['in_progress', 'ready_for_review', 'approved', 'published'];

	/**
	 * The most batches read in one run.
	 *
	 * @var int
	 */
	private const LIMIT = 10000;

	/**
	 * Constructor.
	 *
	 * @param TransparencyWorkflowServiceInterface $woo    Resolves OpenRegister and the batch schema.
	 * @param LoggerInterface $logger For a batch that could not be written.
	 */
	public function __construct(
		private readonly TransparencyWorkflowServiceInterface $woo,
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

		$mapped = $this->mapUnknownStatuses(objectService: $objectService, register: $register, schema: $schema);
		$output->info(sprintf('Woo batch statuses: %d batch(es) set to in_progress.', $mapped));
	}//end run()

	/**
	 * Set every batch whose status is not a lifecycle status to `in_progress`.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param string $register      The register id.
	 * @param string $schema        The batch schema id.
	 *
	 * @return int How many batches were saved.
	 */
	private function mapUnknownStatuses(object $objectService, string $register, string $schema): int {
		$mapped = 0;
		foreach ($this->batches(objectService: $objectService, register: $register, schema: $schema) as $batch) {
			if (in_array((string)($batch['status'] ?? ''), self::STATUSES, true) === true) {
				continue;
			}

			$batch['status'] = 'in_progress';
			if ($this->saveBatch(objectService: $objectService, register: $register, schema: $schema, batch: $batch) === true) {
				$mapped++;
			}
		}

		return $mapped;
	}//end mapUnknownStatuses()

	/**
	 * Save one batch without RBAC; a failure is logged and reported as false.
	 *
	 * @param object               $objectService OpenRegister's ObjectService.
	 * @param string               $register      The register id.
	 * @param string               $schema        The batch schema id.
	 * @param array<string, mixed> $batch         The batch with its new status.
	 *
	 * @return bool Whether it was saved.
	 */
	private function saveBatch(object $objectService, string $register, string $schema, array $batch): bool {
		try {
			$objectService->saveObject(
				object: $batch,
				options: new ObjectSaveOptions(
					target: new ObjectFindTarget(register: $register, schema: $schema),
					uuid: (string)($batch['id'] ?? '')
				),
				_rbac: false,
				_multitenancy: false,
			);
			return true;
		} catch (Throwable $e) {
			$this->logger->warning('[BatchStatusRepair] Could not map the status of batch ' . (string)($batch['id'] ?? '') . ': ' . $e->getMessage());
			return false;
		}
	}//end saveBatch()

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
			$found = $objectService->setRegister($register)->setSchema($schema)->findAll(
				config: ['limit' => self::LIMIT],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('[BatchStatusRepair] Could not read the batches: ' . $e->getMessage());
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
