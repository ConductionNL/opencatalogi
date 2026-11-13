<?php

/**
 * Run the retention evaluation now, reporting first.
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
use OCA\OpenCatalogi\Contract\RetentionServiceInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ opencatalogi:retention:evaluate [--apply]`: the daily retention evaluation.
 * Without `--apply` it lists what each publication would get and changes nothing;
 * with it, it acts exactly as the daily job does (REQ-IMC-002).
 *
 * @spec openspec/changes/integration-maintenance-commands/specs/maintenance-commands/spec.md
 */
class RetentionEvaluateCommand extends MaintenanceCommand {

	/**
	 * Wire the retention service the daily job uses.
	 *
	 * @param RetentionServiceInterface $retentionService The retention service.
	 *
	 * @spec openspec/changes/integration-maintenance-commands/specs/maintenance-commands/spec.md
	 */
	public function __construct(
		private readonly RetentionServiceInterface $retentionService,
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
		$this->setName(name: 'opencatalogi:retention:evaluate')
			->setDescription('List what the retention evaluation would do to each publication. Pass --apply to do it.');
		$this->addOption(name: 'apply', mode: InputOption::VALUE_NONE, description: 'Act as the daily job does. Without it nothing changes.');
		$this->addOutputOption();
	}//end configure()

	/**
	 * Evaluate, as a dry run unless `--apply` is given, and report.
	 *
	 * @param InputInterface $input Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return int 0, or 2 on wrong usage.
	 *
	 * @spec openspec/changes/integration-maintenance-commands/specs/maintenance-commands/spec.md
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$this->wantsJson(input: $input);
		} catch (InvalidArgumentException $e) {
			return $this->wrongUsage(output: $output, message: $e->getMessage());
		}

		$apply = ($input->getOption('apply') === true);
		$result = $this->retentionService->evaluate(dryRun: ($apply === false));

		$lines = [];
		foreach (($result['planned'] ?? []) as $item) {
			$lines[] = sprintf('%s %s (%s)', $item['action'], $item['id'], $item['title']);
		}

		$mode = 'Dry run, nothing changed';
		if ($apply === true) {
			$mode = 'Applied';
		}

		$lines[] = sprintf(
			'%s: %d expiring soon, %d for review, %d depublished, %d archived.',
			$mode,
			($result['expiringSoon'] ?? 0),
			($result['reviewRequired'] ?? 0),
			($result['depublished'] ?? 0),
			($result['archived'] ?? 0)
		);
		$this->writeResult(input: $input, output: $output, lines: $lines, data: $result + ['applied' => $apply]);

		return self::SUCCESS;
	}//end execute()

}//end class
