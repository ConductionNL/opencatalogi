<?php

/**
 * Shared output handling of the maintenance commands.
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
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Base of the maintenance commands: plain lines by default, the result as JSON
 * with `--output=json`, and the exit codes 0 success, 1 failure, 2 wrong usage
 * (REQ-IMC-001).
 *
 * @spec openspec/changes/integration-maintenance-commands/specs/maintenance-commands/spec.md
 */
abstract class MaintenanceCommand extends Command {

	/**
	 * Exit code when an item failed or a check did not pass.
	 */
	public const FAILED = 1;

	/**
	 * Exit code for wrong usage.
	 */
	public const WRONG_USAGE = 2;

	/**
	 * Add the `--output` option.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/integration-maintenance-commands/specs/maintenance-commands/spec.md
	 */
	protected function addOutputOption(): void {
		$this->addOption(
			name: 'output',
			mode: InputOption::VALUE_REQUIRED,
			description: 'Output format: plain (one line per item) or json.',
			default: 'plain'
		);
	}//end addOutputOption()

	/**
	 * Whether the caller asked for JSON.
	 *
	 * @param InputInterface $input Console input.
	 *
	 * @return bool True for `--output=json`.
	 *
	 * @throws InvalidArgumentException When the format is neither plain nor json.
	 *
	 * @spec openspec/changes/integration-maintenance-commands/specs/maintenance-commands/spec.md
	 */
	protected function wantsJson(InputInterface $input): bool {
		$format = (string)$input->getOption('output');
		if (in_array($format, ['plain', 'json'], true) === false) {
			throw new InvalidArgumentException('--output must be plain or json, not ' . $format . '.');
		}

		return $format === 'json';
	}//end wantsJson()

	/**
	 * Write the result: the lines, or the data as JSON.
	 *
	 * @param InputInterface $input Console input.
	 * @param OutputInterface $output Console output.
	 * @param array<int, string> $lines The plain lines.
	 * @param mixed $data The data for `--output=json`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/integration-maintenance-commands/specs/maintenance-commands/spec.md
	 */
	protected function writeResult(InputInterface $input, OutputInterface $output, array $lines, mixed $data): void {
		if ($this->wantsJson(input: $input) === true) {
			$output->writeln((string)json_encode($data, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), OutputInterface::OUTPUT_RAW);
			return;
		}

		foreach ($lines as $line) {
			$output->writeln($line, OutputInterface::OUTPUT_RAW);
		}
	}//end writeResult()

	/**
	 * The exit code: 1 when something failed, else 0.
	 *
	 * @param bool $failed Whether an item failed or a check did not pass.
	 *
	 * @return int The exit code.
	 *
	 * @spec openspec/changes/integration-maintenance-commands/specs/maintenance-commands/spec.md
	 */
	protected static function exitCode(bool $failed): int {
		if ($failed === true) {
			return self::FAILED;
		}

		return self::SUCCESS;
	}//end exitCode()

	/**
	 * A plain-line prefix for an outcome: `OK ` or `FAILED `.
	 *
	 * @param bool $success Whether the item succeeded.
	 *
	 * @return string The prefix.
	 *
	 * @spec openspec/changes/integration-maintenance-commands/specs/maintenance-commands/spec.md
	 */
	protected static function outcomePrefix(bool $success): string {
		if ($success === true) {
			return 'OK ';
		}

		return 'FAILED ';
	}//end outcomePrefix()

	/**
	 * Refuse a wrong usage with exit code 2, before any work.
	 *
	 * @param OutputInterface $output Console output.
	 * @param string $message Why.
	 *
	 * @return int The exit code 2.
	 *
	 * @spec openspec/changes/integration-maintenance-commands/specs/maintenance-commands/spec.md
	 */
	protected function wrongUsage(OutputInterface $output, string $message): int {
		$output->writeln('Error: ' . $message, OutputInterface::OUTPUT_RAW);
		return self::WRONG_USAGE;
	}//end wrongUsage()

}//end class
