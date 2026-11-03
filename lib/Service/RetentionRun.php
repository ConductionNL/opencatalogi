<?php

/**
 * One run of the daily retention evaluation.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service
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
 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-daily-retention-evaluation-job-ret-005
 */

namespace OCA\OpenCatalogi\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * The object service, publication register and schema, and the moment one
 * {@see RetentionService::evaluate()} run works with.
 *
 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-daily-retention-evaluation-job-ret-005
 */
final class RetentionRun {

	/**
	 * When the run started, in UTC.
	 *
	 * @var DateTimeImmutable
	 */
	public readonly DateTimeImmutable $now;

	/**
	 * The run's date (`Y-m-d`), the idempotency key.
	 *
	 * @var string
	 */
	public readonly string $today;

	/**
	 * Constructor.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $register The publication register id.
	 * @param string $schema The publication schema id.
	 *
	 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-daily-retention-evaluation-job-ret-005
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly string $register,
		public readonly string $schema,
	) {
		$this->now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		$this->today = $this->now->format('Y-m-d');
	}//end __construct()

	/**
	 * Save a publication to the run's register and schema, outside RBAC and multitenancy.
	 *
	 * @param array<string, mixed> $data The publication.
	 *
	 * @return mixed The saved object.
	 *
	 * @spec exclude internal save step of the background retention run, which has no user session;
	 *   it pins the register, schema and RBAC-off flags for the job and is not a route wrapper.
	 */
	public function save(array $data): mixed {
		return $this->objectService->saveObject(
			object: $data,
			register: $this->register,
			schema: $this->schema,
			uuid: (string)($data['id'] ?? $data['uuid'] ?? ''),
			_rbac: false,
			_multitenancy: false,
		);
	}//end save()

	/**
	 * Mark a publication evaluated at this run's moment and save it.
	 *
	 * @param array<string, mixed> $data The publication.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-daily-retention-evaluation-job-ret-005
	 */
	public function stampEvaluated(array $data): void {
		$data['retentionLastEvaluatedAt'] = $this->now->format(DateTimeInterface::ATOM);
		$this->save(data: $data);
	}//end stampEvaluated()

	/**
	 * Whether a publication was already evaluated on this run's day.
	 *
	 * @param array<string, mixed> $data The publication.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-daily-retention-evaluation-job-ret-005
	 */
	public function evaluatedToday(array $data): bool {
		$lastEvaluated = (string)($data['retentionLastEvaluatedAt'] ?? '');
		return $lastEvaluated !== '' && str_starts_with($lastEvaluated, $this->today) === true;
	}//end evaluatedToday()
}//end class
