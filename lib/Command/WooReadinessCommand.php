<?php

/**
 * Run the Woo-index readiness check now.
 *
 * @category Command
 * @package  OCA\OpenCatalogi\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenCatalogi.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Command;

use InvalidArgumentException;
use OCA\OpenCatalogi\Contract\HarvesterReadinessServiceInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * `occ opencatalogi:woo:readiness`: the readiness check the settings button runs,
 * persisted the same way, exiting 1 unless the verdict is ready, so it can gate
 * a deployment script (REQ-IMC-003).
 *
 * @spec openspec/changes/integration-maintenance-commands/specs/maintenance-commands/spec.md
 */
class WooReadinessCommand extends MaintenanceCommand {

	/**
	 * Wire the readiness service the settings button uses.
	 *
	 * @param HarvesterReadinessServiceInterface $readiness The readiness service.
	 *
	 * @spec openspec/changes/integration-maintenance-commands/specs/maintenance-commands/spec.md
	 */
	public function __construct(
		private readonly HarvesterReadinessServiceInterface $readiness,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Define the name, description and options.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/integration-maintenance-commands/specs/maintenance-commands/spec.md
	 */
	protected function configure(): void {
		$this->setName(name: 'opencatalogi:woo:readiness')
			->setDescription('Run the Woo-index readiness check, print each check and exit 1 unless it is ready.');
		$this->addOutputOption();
	}//end configure()

	/**
	 * Run the check and report each result.
	 *
	 * @param InputInterface $input Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return int 0 when ready, 1 when not ready, not configured or stopped, 2 on wrong usage.
	 *
	 * @spec openspec/changes/integration-maintenance-commands/specs/maintenance-commands/spec.md
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$this->wantsJson(input: $input);
		} catch (InvalidArgumentException $e) {
			return $this->wrongUsage(output: $output, message: $e->getMessage());
		}

		$report = $this->checkReport(input: $input, output: $output);
		if ($report === null) {
			return self::FAILED;
		}

		$this->writeResult(input: $input, output: $output, lines: self::reportLines(report: $report), data: $report);

		return self::exitCode(failed: (($report['verdict'] ?? null) !== 'ready'));
	}//end execute()

	/**
	 * The check's report, or null after reporting why there is none.
	 *
	 * Like the settings button, the check refuses to run without a
	 * Woo-enabled catalogue: an empty check set would read as ready.
	 *
	 * @param InputInterface  $input  Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return array<string, mixed>|null The report, or null when not configured or stopped.
	 *
	 * @spec openspec/changes/integration-maintenance-commands/specs/maintenance-commands/spec.md
	 */
	private function checkReport(InputInterface $input, OutputInterface $output): ?array {
		if ($this->readiness->hasWooEnabledCatalogs() === false) {
			$this->writeResult(
				input: $input,
				output: $output,
				lines: ['No catalogue is enabled for the Woo index, so there is nothing to check.'],
				data: ['verdict' => 'not-configured']
			);
			return null;
		}

		try {
			return $this->readiness->runCheck();
		} catch (Throwable $e) {
			$this->writeResult(
				input: $input,
				output: $output,
				lines: ['The check stopped: ' . $e->getMessage()],
				data: ['verdict' => 'stopped', 'error' => $e->getMessage()]
			);
			return null;
		}
	}//end checkReport()

	/**
	 * One line per check, then the verdict.
	 *
	 * @param array<string, mixed> $report The check's report.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/integration-maintenance-commands/specs/maintenance-commands/spec.md
	 */
	private static function reportLines(array $report): array {
		$lines = [];
		foreach (($report['checks'] ?? []) as $check) {
			$lines[] = self::checkLine(check: $check);
		}

		$lines[] = 'Verdict: ' . (string)($report['verdict'] ?? 'unknown');

		return $lines;
	}//end reportLines()

	/**
	 * One check as a line: status, id, the catalogue and the reason when given.
	 *
	 * @param mixed $check One check as the report gives it.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/integration-maintenance-commands/specs/maintenance-commands/spec.md
	 */
	private static function checkLine(mixed $check): string {
		$line = strtoupper((string)($check['status'] ?? '')) . ' ' . (string)($check['id'] ?? '');
		if (isset($check['catalogSlug']) === true) {
			$line .= ' [' . $check['catalogSlug'] . ']';
		}

		if (isset($check['reason']) === true) {
			$line .= ': ' . $check['reason'];
		}

		return $line;
	}//end checkLine()

}//end class
