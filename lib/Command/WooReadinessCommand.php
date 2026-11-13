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

		// Like the settings button, refuse to run without a Woo-enabled catalogue:
		// an empty check set would read as ready.
		if ($this->readiness->hasWooEnabledCatalogs() === false) {
			$this->writeResult(
				input: $input,
				output: $output,
				lines: ['No catalogue is enabled for the Woo index, so there is nothing to check.'],
				data: ['verdict' => 'not-configured']
			);
			return self::FAILED;
		}

		try {
			$report = $this->readiness->runCheck();
		} catch (Throwable $e) {
			$this->writeResult(
				input: $input,
				output: $output,
				lines: ['The check stopped: ' . $e->getMessage()],
				data: ['verdict' => 'stopped', 'error' => $e->getMessage()]
			);
			return self::FAILED;
		}

		$lines = [];
		foreach (($report['checks'] ?? []) as $check) {
			$line = strtoupper((string)($check['status'] ?? '')) . ' ' . (string)($check['id'] ?? '');
			if (isset($check['catalogSlug']) === true) {
				$line .= ' [' . $check['catalogSlug'] . ']';
			}

			if (isset($check['reason']) === true) {
				$line .= ': ' . $check['reason'];
			}

			$lines[] = $line;
		}

		$lines[] = 'Verdict: ' . (string)($report['verdict'] ?? 'unknown');
		$this->writeResult(input: $input, output: $output, lines: $lines, data: $report);

		return self::exitCode(failed: (($report['verdict'] ?? null) !== 'ready'));
	}//end execute()

}//end class
