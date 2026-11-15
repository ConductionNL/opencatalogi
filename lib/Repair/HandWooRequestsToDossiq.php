<?php

/**
 * OpenCatalogi HandWooRequestsToDossiq Repair Step
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Hands the Woo requests OpenCatalogi stored to dossiq, and stops their own
 * statutory term here once dossiq holds them.
 *
 * Dossiq's generic record import (`CaseRecordImportInterface::run()`, import
 * `opencatalogi-woo-requests` of case type `woo-verzoek`) writes one case per
 * request, carries the term onto the case and stamps the request with
 * `migratedTo` and `migratedAt`. It is idempotent: a request it moved before
 * answers as already imported. Every request it moves in this run, or joins to
 * the case of an earlier one with the same number, has its OpenCatalogi timer
 * cancelled, so the reminders do not run twice. A request it could not move
 * keeps its timer, and so does every request when dossiq answers in a shape
 * this step does not know.
 *
 * Dossiq also runs the import on its own upgrade, so a request may already
 * carry the `migratedTo` stamp before this step sees it; such a request
 * answers as already imported, and the step stops its timer by the stamp.
 * The step stores what is left (`woo_requests_unmigrated`,
 * `woo_requests_failed`) and makes the request schema read-only once nothing is.
 *
 * @category Repair
 * @package  OCA\OpenCatalogi\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/woo-request-intake-hands-over-to-dossiq/specs/woo-request-intake/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Repair;

use DateTimeImmutable;
use OCA\OpenCatalogi\Contract\InformationRequestStoreInterface;
use OCA\OpenCatalogi\Service\Woo\WooHandoverState;
use OCP\App\IAppManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Runs dossiq's import of OpenCatalogi's Woo requests and cancels the moved requests' own timers.
 *
 * @spec openspec/changes/woo-request-intake-hands-over-to-dossiq/specs/woo-request-intake/spec.md
 */
class HandWooRequestsToDossiq implements IRepairStep {

	/**
	 * Dossiq's record import contract.
	 */
	public const IMPORT_CLASS = 'OCA\Dossiq\Kernel\Contracts\CaseRecordImportInterface';

	/**
	 * The case type that declares the import.
	 */
	public const CASE_TYPE = 'woo-verzoek';

	/**
	 * The import's key in that case type.
	 */
	public const IMPORT_KEY = 'opencatalogi-woo-requests';

	/**
	 * OpenRegister's timer service.
	 */
	public const TIMER_CLASS = 'OCA\OpenRegister\Service\Flow\Timer\FlowTimerService';

	/**
	 * The subject type the statutory term's timers are stored under (the same as StatutoryTerm::SUBJECT_TYPE).
	 */
	public const TIMER_SUBJECT_TYPE = 'object';

	/**
	 * Constructor.
	 *
	 * @param IAppManager $appManager Whether dossiq is enabled.
	 * @param ContainerInterface $container Reaches dossiq's import, OpenRegister's timers and the stored requests.
	 * @param WooHandoverState|null $state Keeps what the hand-over left; null keeps nothing.
	 *
	 * @spec exclude pure framework plumbing: constructor dependency injection.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly ?WooHandoverState $state = null,
	) {
	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/woo-request-intake-hands-over-to-dossiq/specs/woo-request-intake/spec.md
	 */
	public function getName(): string {
		return 'OpenCatalogi: hand the stored Woo requests to dossiq';
	}//end getName()

	/**
	 * Run the import and cancel the moved requests' timers; never abort the upgrade.
	 *
	 * @param IOutput $output Where the step reports.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-request-intake-hands-over-to-dossiq/specs/woo-request-intake/spec.md
	 */
	public function run(IOutput $output): void {
		if ($this->appManager->isEnabledForAnyone('dossiq') === false) {
			$output->info('Dossiq is not enabled: the Woo requests stay in OpenCatalogi.');
			return;
		}

		$import = $this->runImport(output: $output);
		if ($import === null) {
			return;
		}

		$moved = array_merge($import['migrated'], $import['duplicates']);
		$cancelled = $this->cancelTimers(rows: $moved);
		$cancelled += $this->cancelStampedTimers(skip: self::requestIds(rows: $moved), output: $output);
		$this->state?->record(
			unmigrated: (int)($import['unmigrated'] ?? count($import['failed'])),
			failed: count($import['failed']),
			checkedAt: (new DateTimeImmutable())->format(DATE_ATOM)
		);
		$output->info(self::summary(import: $import, cancelled: $cancelled));
	}//end run()

	/**
	 * Cancel the timer of every stored request dossiq stamped before this run.
	 *
	 * Dossiq runs the same import on its own upgrade, and a request it moved
	 * there answers here as already imported. Its stamp names the case.
	 * Cancelling an already cancelled timer cancels nothing, so this is safe to
	 * run again.
	 *
	 * @param array<int, string> $skip The requests this run already handled.
	 * @param IOutput $output Where the step reports a failed read.
	 *
	 * @return int How many timers were cancelled.
	 *
	 * @spec openspec/changes/woo-request-intake-hands-over-to-dossiq/tasks.md#task-3.2
	 */
	private function cancelStampedTimers(array $skip, IOutput $output): int {
		try {
			$requests = $this->container->get(InformationRequestStoreInterface::class)->all();
		} catch (Throwable $e) {
			$output->warning('The stored Woo requests could not be read to stop the timers of requests dossiq moved earlier: ' . $e->getMessage());
			return 0;
		}

		$stamped = [];
		foreach ($requests as $request) {
			$requestId = (string)($request['id'] ?? $request['uuid'] ?? '');
			$caseId = (string)($request['migratedTo'] ?? '');
			if ($requestId === '' || $caseId === '' || in_array($requestId, $skip, true) === true) {
				continue;
			}

			$stamped[] = ['requestId' => $requestId, 'caseId' => $caseId];
		}

		return $this->cancelTimers(rows: $stamped);
	}//end cancelStampedTimers()

	/**
	 * The request ids of moved rows.
	 *
	 * @param array<int, mixed> $rows The rows.
	 *
	 * @return array<int, string> The ids.
	 */
	private static function requestIds(array $rows): array {
		$ids = [];
		foreach ($rows as $row) {
			if (is_array($row) === true && is_string($row['requestId'] ?? null) === true) {
				$ids[] = $row['requestId'];
			}
		}

		return $ids;
	}//end requestIds()

	/**
	 * The line the step reports after the import.
	 *
	 * @param array<string, mixed> $import The import's answer.
	 * @param int $cancelled How many timers were cancelled.
	 *
	 * @return string The summary.
	 *
	 * @spec openspec/changes/woo-request-intake-hands-over-to-dossiq/specs/woo-request-intake/spec.md
	 */
	private static function summary(array $import, int $cancelled): string {
		return sprintf(
			'Woo requests handed to dossiq: %d moved, %d already moved, %d not moved; %d timers cancelled.',
			count($import['migrated']),
			(int)($import['alreadyImported'] ?? 0),
			count($import['failed']),
			$cancelled
		);
	}//end summary()

	/**
	 * Run dossiq's import, or report why it gave no usable answer.
	 *
	 * @param IOutput $output Where the step reports.
	 *
	 * @return array<string, mixed>|null The import's answer, or null when there is nothing to cancel.
	 *
	 * @spec openspec/changes/woo-request-intake-hands-over-to-dossiq/specs/woo-request-intake/spec.md
	 */
	private function runImport(IOutput $output): ?array {
		try {
			$answer = $this->container->get(self::IMPORT_CLASS)->run(caseType: self::CASE_TYPE, importKey: self::IMPORT_KEY);
		} catch (Throwable $e) {
			$output->warning('Dossiq could not import the Woo requests: ' . $e->getMessage());
			return null;
		}

		$import = self::importOf(answer: $answer);
		if ($import === null) {
			$output->warning('Dossiq answered the Woo request import in an unexpected shape; no timer was cancelled.');
		}

		return $import;
	}//end runImport()

	/**
	 * The Woo request import's answer, or null when dossiq answered something else.
	 *
	 * @param mixed $answer What dossiq's import returned.
	 *
	 * @return array<string, mixed>|null The import's answer, with `migrated`, `failed` and `duplicates` as arrays.
	 *
	 * @spec openspec/changes/woo-request-intake-hands-over-to-dossiq/specs/woo-request-intake/spec.md
	 */
	private static function importOf(mixed $answer): ?array {
		if (is_array($answer) === false || is_array($answer['imports'] ?? null) === false) {
			return null;
		}

		foreach ($answer['imports'] as $import) {
			if (is_array($import) === true && ($import['key'] ?? null) === self::IMPORT_KEY
				&& is_array($import['migrated'] ?? null) === true && is_array($import['failed'] ?? null) === true
			) {
				$import['duplicates'] = (array)($import['duplicates'] ?? []);
				return $import;
			}
		}

		return null;
	}//end importOf()

	/**
	 * Cancel the OpenCatalogi timer of every moved request.
	 *
	 * @param array<int, mixed> $rows The moved rows, each with `requestId` and `caseId`.
	 *
	 * @return int How many timers were cancelled.
	 *
	 * @spec openspec/changes/woo-request-intake-hands-over-to-dossiq/specs/woo-request-intake/spec.md
	 */
	private function cancelTimers(array $rows): int {
		$timers = $this->container->get(self::TIMER_CLASS);
		$cancelled = 0;
		foreach ($rows as $row) {
			if (is_array($row) === false || is_string($row['requestId'] ?? null) === false || $row['requestId'] === '') {
				continue;
			}

			$cancelled += $this->cancelTimer(timers: $timers, row: $row);
		}

		return $cancelled;
	}//end cancelTimers()

	/**
	 * Cancel the OpenCatalogi timer of one moved request.
	 *
	 * @param object $timers OpenRegister's timer service.
	 * @param array<string, mixed> $row The moved row, with a non-empty string `requestId`.
	 *
	 * @return int How many timers were cancelled.
	 *
	 * @spec openspec/changes/woo-request-intake-hands-over-to-dossiq/specs/woo-request-intake/spec.md
	 */
	private function cancelTimer(object $timers, array $row): int {
		return (int)$timers->cancelForSubject(
			subjectType: self::TIMER_SUBJECT_TYPE,
			subjectUuid: $row['requestId'],
			reason: 'Handed over to dossiq case ' . (string)($row['caseId'] ?? ''),
			actor: null
		);
	}//end cancelTimer()
}//end class
