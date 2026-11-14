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

use OCA\OpenCatalogi\Service\Woo\StatutoryTerm;
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
	 * Constructor.
	 *
	 * @param IAppManager $appManager Whether dossiq is enabled.
	 * @param ContainerInterface $container Reaches dossiq's import and OpenRegister's timers.
	 *
	 * @spec exclude pure framework plumbing: constructor dependency injection.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
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

		try {
			$answer = $this->container->get(self::IMPORT_CLASS)->run(caseType: self::CASE_TYPE, importKey: self::IMPORT_KEY);
		} catch (Throwable $e) {
			$output->warning('Dossiq could not import the Woo requests: ' . $e->getMessage());
			return;
		}

		$import = self::importOf(answer: $answer);
		if ($import === null) {
			$output->warning('Dossiq answered the Woo request import in an unexpected shape; no timer was cancelled.');
			return;
		}

		$cancelled = $this->cancelTimers(rows: array_merge($import['migrated'], $import['duplicates']));
		$output->info(
			sprintf(
				'Woo requests handed to dossiq: %d moved, %d already moved, %d not moved; %d timers cancelled.',
				count($import['migrated']),
				(int)($import['alreadyImported'] ?? 0),
				count($import['failed']),
				$cancelled
			)
		);
	}//end run()

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

			$requestId = $row['requestId'];

			$cancelled += (int)$timers->cancelForSubject(
				subjectType: StatutoryTerm::SUBJECT_TYPE,
				subjectUuid: $requestId,
				reason: 'Handed over to dossiq case ' . (string)($row['caseId'] ?? ''),
				actor: null
			);
		}

		return $cancelled;
	}//end cancelTimers()
}//end class
