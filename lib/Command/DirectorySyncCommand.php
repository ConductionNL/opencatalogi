<?php

/**
 * Sync the federation directory now.
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

use DateTimeInterface;
use InvalidArgumentException;
use OCA\OpenCatalogi\Contract\DirectoryServiceInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * `occ opencatalogi:directory:sync [--url=<directory>]`: the hourly directory
 * sync, run now, for every known directory or one (REQ-IMC-001).
 *
 * @spec openspec/changes/integration-maintenance-commands/specs/maintenance-commands/spec.md
 */
class DirectorySyncCommand extends MaintenanceCommand {

	/**
	 * Wire the directory service the background job uses.
	 *
	 * @param DirectoryServiceInterface $directoryService The directory service.
	 *
	 * @spec openspec/changes/integration-maintenance-commands/specs/maintenance-commands/spec.md
	 */
	public function __construct(
		private readonly DirectoryServiceInterface $directoryService,
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
		$this->setName(name: 'opencatalogi:directory:sync')
			->setDescription('Sync every known directory now, or only the one given with --url.');
		$this->addOption(name: 'url', mode: InputOption::VALUE_REQUIRED, description: 'The /api/directory URL of one directory to sync.');
		$this->addOutputOption();
	}//end configure()

	/**
	 * Sync and report.
	 *
	 * @param InputInterface $input Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return int 0 when every directory synced, 1 when one failed, 2 on wrong usage.
	 *
	 * @spec openspec/changes/integration-maintenance-commands/specs/maintenance-commands/spec.md
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$this->wantsJson(input: $input);
		} catch (InvalidArgumentException $e) {
			return $this->wrongUsage(output: $output, message: $e->getMessage());
		}

		$url = $input->getOption('url');
		if ($url !== null) {
			return $this->syncOne(input: $input, output: $output, url: (string)$url);
		}

		$results = $this->directoryService->doCronSync();
		$this->writeResult(input: $input, output: $output, lines: self::allSyncedLines(results: $results), data: $results);

		return self::exitCode(failed: ((int)($results['failed_directories'] ?? 0) > 0));
	}//end execute()

	/**
	 * The lines of a sync of every directory: each failure, then the totals.
	 *
	 * @param array<string, mixed> $results The cron sync's results.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/integration-maintenance-commands/specs/maintenance-commands/spec.md
	 */
	private static function allSyncedLines(array $results): array {
		$lines = [];
		foreach (($results['errors'] ?? []) as $error) {
			$lines[] = 'FAILED ' . ($error['directory'] ?? '') . ': ' . ($error['error'] ?? '');
		}

		$lines[] = sprintf(
			'Synced %d of %d directories, %d failed.',
			($results['synced_directories'] ?? 0),
			($results['total_directories'] ?? 0),
			($results['failed_directories'] ?? 0)
		);

		return $lines;
	}//end allSyncedLines()

	/**
	 * Sync one directory and report each listing added or updated.
	 *
	 * @param InputInterface $input Console input.
	 * @param OutputInterface $output Console output.
	 * @param string $url The directory URL.
	 *
	 * @return int 0 on success, 1 when the sync or a listing failed, 2 for a URL the service refuses.
	 *
	 * @spec openspec/changes/integration-maintenance-commands/specs/maintenance-commands/spec.md
	 */
	private function syncOne(InputInterface $input, OutputInterface $output, string $url): int {
		try {
			$result = $this->directoryService->syncDirectory(directoryUrl: $url);
		} catch (InvalidArgumentException $e) {
			return $this->wrongUsage(output: $output, message: $e->getMessage());
		} catch (Throwable $e) {
			$this->writeResult(
				input: $input,
				output: $output,
				lines: ['FAILED ' . $url . ': ' . $e->getMessage()],
				data: ['directory_url' => $url, 'error' => $e->getMessage()]
			);
			return self::FAILED;
		}

		if (($result['sync_time'] ?? null) instanceof DateTimeInterface) {
			$result['sync_time'] = $result['sync_time']->format(DateTimeInterface::ATOM);
		}

		$this->writeResult(input: $input, output: $output, lines: self::oneSyncedLines(url: $url, result: $result), data: $result);

		return self::exitCode(failed: ((int)($result['listings_failed'] ?? 0) > 0));
	}//end syncOne()

	/**
	 * The lines of a sync of one directory: each listing added or updated, then the totals.
	 *
	 * @param string               $url    The directory URL.
	 * @param array<string, mixed> $result The sync's result.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/integration-maintenance-commands/specs/maintenance-commands/spec.md
	 */
	private static function oneSyncedLines(string $url, array $result): array {
		$lines = [];
		foreach (($result['listing_details'] ?? []) as $detail) {
			$action = (string)($detail['action'] ?? '');
			if (in_array($action, ['created', 'updated'], true) === true) {
				$lines[] = strtoupper($action) . ' ' . (string)($detail['listing_title'] ?? ($detail['listing_id'] ?? ''));
			}
		}

		$lines[] = sprintf(
			'%s: %d created, %d updated, %d unchanged, %d skipped, %d failed.',
			$url,
			($result['listings_created'] ?? 0),
			($result['listings_updated'] ?? 0),
			($result['listings_unchanged'] ?? 0),
			($result['listings_skipped'] ?? 0),
			($result['listings_failed'] ?? 0)
		);

		return $lines;
	}//end oneSyncedLines()

}//end class
