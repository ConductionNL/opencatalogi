<?php

/**
 * Announce this directory to the known directories now.
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
use OCA\OpenCatalogi\Contract\BroadcastServiceInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ opencatalogi:directory:broadcast`: the four-hourly broadcast, run now
 * (REQ-IMC-001).
 *
 * @spec openspec/changes/integration-maintenance-commands/specs/maintenance-commands/spec.md
 */
class DirectoryBroadcastCommand extends MaintenanceCommand {

	/**
	 * Wire the broadcast service the background job uses.
	 *
	 * @param BroadcastServiceInterface $broadcastService The broadcast service.
	 *
	 * @spec openspec/changes/integration-maintenance-commands/specs/maintenance-commands/spec.md
	 */
	public function __construct(
		private readonly BroadcastServiceInterface $broadcastService,
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
		$this->setName(name: 'opencatalogi:directory:broadcast')
			->setDescription('Announce this directory to every known directory now.');
		$this->addOutputOption();
	}//end configure()

	/**
	 * Broadcast and report each target.
	 *
	 * @param InputInterface $input Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return int 0 when every target accepted, 1 when one did not, 2 on wrong usage.
	 *
	 * @spec openspec/changes/integration-maintenance-commands/specs/maintenance-commands/spec.md
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$this->wantsJson(input: $input);
		} catch (InvalidArgumentException $e) {
			return $this->wrongUsage(output: $output, message: $e->getMessage());
		}

		$results = $this->broadcastService->broadcast(url: null);
		$lines = [];
		$failed = 0;
		foreach ($results as $url => $success) {
			if ($success !== true) {
				$failed++;
			}

			$lines[] = self::outcomePrefix(success: ($success === true)) . $url;
		}

		$lines[] = sprintf('Broadcast to %d directories, %d failed.', count($results), $failed);
		$this->writeResult(input: $input, output: $output, lines: $lines, data: $results);

		return self::exitCode(failed: ($failed > 0));
	}//end execute()

}//end class
