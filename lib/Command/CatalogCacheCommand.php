<?php

/**
 * Rebuild or clear the cache of one catalogue or all of them.
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
use OCA\OpenCatalogi\Contract\CatalogiServiceInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * `occ opencatalogi:catalog:cache rebuild|clear <slug>|--all`: the cache work
 * the object events do, run now (REQ-IMC-001).
 *
 * @spec openspec/changes/integration-maintenance-commands/specs/maintenance-commands/spec.md
 */
class CatalogCacheCommand extends MaintenanceCommand {

	/**
	 * Wire the catalogue service the object events use.
	 *
	 * @param CatalogiServiceInterface $catalogiService The catalogue service.
	 *
	 * @spec openspec/changes/integration-maintenance-commands/specs/maintenance-commands/spec.md
	 */
	public function __construct(
		private readonly CatalogiServiceInterface $catalogiService,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Define the name, description, arguments and options.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/integration-maintenance-commands/specs/maintenance-commands/spec.md
	 */
	protected function configure(): void {
		$this->setName(name: 'opencatalogi:catalog:cache')
			->setDescription('Rebuild or clear the cache of one catalogue, or of all of them with --all.');
		$this->addArgument(name: 'action', mode: InputArgument::REQUIRED, description: 'rebuild or clear');
		$this->addArgument(name: 'slug', mode: InputArgument::OPTIONAL, description: 'The catalogue slug. Leave it out with --all.');
		$this->addOption(name: 'all', mode: InputOption::VALUE_NONE, description: 'Every catalogue.');
		$this->addOutputOption();
	}//end configure()

	/**
	 * Rebuild or clear each catalogue's cache and report one line per catalogue.
	 *
	 * @param InputInterface $input Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return int 0 when every catalogue succeeded, 1 when one failed, 2 on wrong usage.
	 *
	 * @spec openspec/changes/integration-maintenance-commands/specs/maintenance-commands/spec.md
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$this->wantsJson(input: $input);
		} catch (InvalidArgumentException $e) {
			return $this->wrongUsage(output: $output, message: $e->getMessage());
		}

		$action = (string)$input->getArgument('action');
		$refusal = self::usageRefusal(action: $action, slug: $input->getArgument('slug'), all: ($input->getOption('all') === true));
		if ($refusal !== null) {
			return $this->wrongUsage(output: $output, message: $refusal);
		}

		$slugs = [(string)$input->getArgument('slug')];
		if ($input->getOption('all') === true) {
			$slugs = $this->catalogiService->getCatalogSlugs();
		}

		$entries = array_map(fn (string $one): array => $this->handle(action: $action, slug: $one), $slugs);
		$failed = count(array_filter($entries, static fn (array $entry): bool => $entry['success'] === false));
		$this->writeResult(input: $input, output: $output, lines: self::entryLines(action: $action, entries: $entries, failed: $failed), data: $entries);

		return self::exitCode(failed: ($failed > 0));
	}//end execute()

	/**
	 * Why the arguments cannot run, or null when they can.
	 *
	 * @param string $action The action argument.
	 * @param mixed  $slug   The slug argument, null when absent.
	 * @param bool   $all    Whether --all was given.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/integration-maintenance-commands/specs/maintenance-commands/spec.md
	 */
	private static function usageRefusal(string $action, mixed $slug, bool $all): ?string {
		if (in_array($action, ['rebuild', 'clear'], true) === false) {
			return 'The action must be rebuild or clear, not ' . $action . '.';
		}

		if (($slug === null) === ($all === false)) {
			return 'Give one catalogue slug or --all, not both and not neither.';
		}

		return null;
	}//end usageRefusal()

	/**
	 * One line per catalogue, then the totals.
	 *
	 * @param string                           $action  The action run.
	 * @param array<int, array<string, mixed>> $entries One entry per catalogue.
	 * @param int                              $failed  How many failed.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/integration-maintenance-commands/specs/maintenance-commands/spec.md
	 */
	private static function entryLines(string $action, array $entries, int $failed): array {
		$lines = array_map(static fn (array $entry): string => self::entryLine(entry: $entry), $entries);
		$verb = 'Cleared';
		if ($action === 'rebuild') {
			$verb = 'Rebuilt';
		}

		$lines[] = sprintf('%s %d catalogues, %d failed.', $verb, count($entries), $failed);

		return $lines;
	}//end entryLines()

	/**
	 * The plain line for one catalogue: its outcome, slug and any error.
	 *
	 * @param array{slug: string, action: string, success: bool, error: string|null} $entry The result.
	 *
	 * @return string The line.
	 *
	 * @spec openspec/changes/integration-maintenance-commands/specs/maintenance-commands/spec.md
	 */
	private static function entryLine(array $entry): string {
		$line = self::outcomePrefix(success: $entry['success']) . $entry['slug'];
		if ($entry['error'] !== null) {
			$line .= ': ' . $entry['error'];
		}

		return $line;
	}//end entryLine()

	/**
	 * Rebuild or clear one catalogue's cache.
	 *
	 * @param string $action rebuild or clear.
	 * @param string $slug The catalogue slug.
	 *
	 * @return array{slug: string, action: string, success: bool, error: string|null} The result.
	 *
	 * @spec openspec/changes/integration-maintenance-commands/specs/maintenance-commands/spec.md
	 */
	private function handle(string $action, string $slug): array {
		try {
			// The warm-up clears the entry before it reloads it.
			if ($action === 'rebuild') {
				$this->catalogiService->warmupCatalogCache(slug: $slug);
			}

			if ($action === 'clear') {
				$this->catalogiService->invalidateCatalogCache(slug: $slug);
			}
		} catch (Throwable $e) {
			return ['slug' => $slug, 'action' => $action, 'success' => false, 'error' => $e->getMessage()];
		}

		return ['slug' => $slug, 'action' => $action, 'success' => true, 'error' => null];
	}//end handle()

}//end class
