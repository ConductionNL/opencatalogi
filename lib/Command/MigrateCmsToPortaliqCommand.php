<?php

/**
 * OpenCatalogi migrate-cms-to-portaliq command
 *
 * Moves this app's pages and menus onto Portaliq, which is where a portal's
 * content belongs and which already serves `/api/content/pages` and
 * `/api/content/menus` publicly.
 *
 * Dry-run by default. Pass --apply to write.
 *
 * @category Command
 * @package  OCA\OpenCatalogi\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenCatalogi.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Command;

use OCA\OpenCatalogi\Contract\CmsMigrationServiceInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Move pages and menus into a Portaliq portal.
 *
 * @spec openspec/changes/cms-moves-to-portaliq/specs/portal-content/spec.md#requirement-a-page-becomes-a-portal-page-req-cms-101
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class MigrateCmsToPortaliqCommand extends Command {

	/**
	 * Wire the migration rules.
	 *
	 * @param CmsMigrationServiceInterface $migration The mapping rules and lazy OR access.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cms-moves-to-portaliq/specs/portal-content/spec.md#requirement-a-page-becomes-a-portal-page-req-cms-101
	 */
	public function __construct(private readonly CmsMigrationServiceInterface $migration) {
		parent::__construct();
	}//end __construct()

	/**
	 * Define command name, description, and options.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cms-moves-to-portaliq/specs/portal-content/spec.md#requirement-a-page-becomes-a-portal-page-req-cms-101
	 */
	protected function configure(): void {
		$this->setName(name: 'opencatalogi:cms:migrate-to-portaliq')
			->setDescription(
				'Move this app\'s pages and menus into a Portaliq portal. '
				. 'Portaliq is where a portal\'s content belongs, and it already serves it publicly.'
			)
			->addOption(
				'portal',
				null,
				InputOption::VALUE_REQUIRED,
				'The Portaliq portal slug the content belongs to. REQUIRED: a Portaliq page and menu '
				. 'must name a portal, and nothing in the source data says which one.'
			)
			->addOption(
				'apply',
				null,
				InputOption::VALUE_NONE,
				'Actually write. Without this flag the command reports what it WOULD do.'
			);
	}//end configure()

	/**
	 * Read this app's pages and menus and move them.
	 *
	 * @param InputInterface  $input  Console input.
	 * @param OutputInterface $output Console output stream.
	 *
	 * @return int Symfony command exit code.
	 *
	 * @spec openspec/changes/cms-moves-to-portaliq/specs/portal-content/spec.md#requirement-a-page-becomes-a-portal-page-req-cms-101
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$portal = trim((string)$input->getOption('portal'));
		$dryRun = ((bool)$input->getOption('apply') === false);

		if ($portal === '') {
			$output->writeln(
				'<error>--portal is required. A Portaliq page and menu must name a portal, '
				. 'and nothing in this app\'s data says which one these belong to.</error>'
			);
			return Command::FAILURE;
		}

		if ($dryRun === true) {
			$output->writeln(
				'<comment>Running in DRY-RUN mode — nothing is written. Re-run with --apply.</comment>'
			);
		}

		$objectService = $this->migration->openRegister(id: 'OCA\\OpenRegister\\Service\\ObjectService');
		if ($objectService === null) {
			$output->writeln('<error>OpenRegister is not available, so nothing can be migrated.</error>');
			return Command::FAILURE;
		}

		return $this->moveAll(
			run: ['portal' => $portal, 'objectService' => $objectService, 'dryRun' => $dryRun, 'output' => $output]
		);
	}//end execute()

	/**
	 * Move every page, then every menu, and report the tally.
	 *
	 * @param array<string, mixed> $run The run: `portal`, `objectService`, `dryRun` and `output`.
	 *
	 * @return int Symfony command exit code.
	 *
	 * @spec openspec/changes/cms-moves-to-portaliq/specs/portal-content/spec.md#requirement-a-page-becomes-a-portal-page-req-cms-101
	 */
	private function moveAll(array $run): int {
		$tally = ['moved' => 0, 'skipped' => 0, 'failed' => 0];
		foreach (['page', 'menu'] as $kind) {
			$kindTally = $this->moveKind(run: $run + ['kind' => $kind]);
			if ($kindTally === null) {
				return Command::FAILURE;
			}

			foreach ($kindTally as $key => $value) {
				$tally[$key] += $value;
			}
		}

		return $this->reportTally(tally: $tally, run: $run);
	}//end moveAll()

	/**
	 * Move every row of one source schema, or null when its rows cannot be read.
	 *
	 * @param array<string, mixed> $run The run, with the `kind` to move.
	 *
	 * @return array{moved:int, skipped:int, failed:int}|null The tally for this kind, or null.
	 *
	 * @spec openspec/changes/cms-moves-to-portaliq/specs/portal-content/spec.md#requirement-a-page-becomes-a-portal-page-req-cms-101
	 */
	private function moveKind(array $run): ?array {
		$rows = $this->read(objectService: $run['objectService'], schema: $run['kind'], output: $run['output']);
		if ($rows === null) {
			return null;
		}

		$tally = ['moved' => 0, 'skipped' => 0, 'failed' => 0];
		foreach ($rows as $row) {
			foreach ($this->moveOne(row: $row, run: $run) as $key => $value) {
				$tally[$key] += $value;
			}
		}

		return $tally;
	}//end moveKind()

	/**
	 * Print the tally and fail the run when any row failed.
	 *
	 * @param array{moved:int, skipped:int, failed:int} $tally The tally.
	 * @param array<string, mixed> $run The run, for `dryRun` and `output`.
	 *
	 * @return int Symfony command exit code.
	 *
	 * @spec openspec/changes/cms-moves-to-portaliq/specs/portal-content/spec.md#requirement-a-page-becomes-a-portal-page-req-cms-101
	 */
	private function reportTally(array $tally, array $run): int {
		$suffix = '';
		if ($run['dryRun'] === true) {
			$suffix = ' (dry run, nothing written)';
		}

		$run['output']->writeln(
			sprintf('<info>Done. Moved=%d, skipped=%d, failed=%d%s</info>', $tally['moved'], $tally['skipped'], $tally['failed'], $suffix)
		);

		if ($tally['failed'] > 0) {
			return Command::FAILURE;
		}

		return Command::SUCCESS;
	}//end reportTally()

	/**
	 * Read one source schema's rows, or null when they cannot be read.
	 *
	 * @param object          $objectService The OpenRegister ObjectService.
	 * @param string          $schema        The schema slug.
	 * @param OutputInterface $output        Console output stream.
	 *
	 * @return array<int, mixed>|null The rows, or null on failure.
	 *
	 * @spec openspec/changes/cms-moves-to-portaliq/specs/portal-content/spec.md#requirement-a-page-becomes-a-portal-page-req-cms-101
	 */
	private function read(object $objectService, string $schema, OutputInterface $output): ?array {
		try {
			$rows = $objectService->searchObjectsBySlug(
				CmsMigrationServiceInterface::SOURCE_REGISTER,
				$schema,
				['_limit' => 1000],
				false,
				false
			);
		} catch (Throwable $e) {
			$output->writeln(sprintf('<error>Could not read %s rows: %s</error>', $schema, $e->getMessage()));
			return null;
		}

		if (is_array($rows) === false) {
			return [];
		}

		return $rows;
	}//end read()

	/**
	 * Map one source row onto its Portaliq shape.
	 *
	 * @param string               $kind   Either `page` or `menu`.
	 * @param array<string, mixed> $fields The source row's fields.
	 * @param string               $portal The target portal slug.
	 * @param string               $name   The row's title, for the report line.
	 *
	 * @return array{0: array<string, mixed>, 1: string, 2: array<string, mixed>} The
	 *         target object, the report label, and the mapping outcome.
	 *
	 * @spec openspec/changes/cms-moves-to-portaliq/specs/portal-content/spec.md#requirement-a-page-becomes-a-portal-page-req-cms-101
	 */
	private function mapRow(string $kind, array $fields, string $portal, string $name): array {
		if ($kind === 'page') {
			$built = $this->migration->pageFor(page: $fields, portal: $portal);
			return [$built['page'], sprintf('%s -> %s', $name, $built['page']['route']), $built];
		}

		$built = $this->migration->menuFor(menu: $fields, portal: $portal);
		$built['unmapped'] = [];

		return [$built['menu'], $name, $built];
	}//end mapRow()

	/**
	 * Move one page or menu.
	 *
	 * @param mixed $row The source row.
	 * @param array<string, mixed> $run The run: `kind`, `portal`, `objectService`, `dryRun` and `output`.
	 *
	 * @return array{moved:int, skipped:int, failed:int} The tally for this row.
	 *
	 * @spec openspec/changes/cms-moves-to-portaliq/specs/portal-content/spec.md#requirement-a-page-becomes-a-portal-page-req-cms-101
	 */
	private function moveOne(mixed $row, array $run): array {
		$fields = $this->migration->toFields(row: $row);
		$name = (string)($fields['title'] ?? 'untitled');

		[$target, $label, $built] = $this->mapRow(kind: $run['kind'], fields: $fields, portal: $run['portal'], name: $name);

		$run['output']->writeln(sprintf('<info>%s: %s</info>', $run['kind'], $label));

		if ($built['unmapped'] !== []) {
			$run['output']->writeln(
				sprintf(
					'  <error>REFUSED: no Portaliq widget is declared for content block type(s): %s. '
					. 'Guessing one would save a page that renders nothing.</error>',
					implode(', ', $built['unmapped'])
				)
			);
			return ['moved' => 0, 'skipped' => 0, 'failed' => 1];
		}

		$this->warnDropped(dropped: $built['dropped'], output: $run['output']);

		return $this->writeTarget(target: $target, run: $run);
	}//end moveOne()

	/**
	 * Report what Portaliq declares no counterpart for, when anything.
	 *
	 * @param array<int, string> $dropped The dropped content.
	 * @param OutputInterface $output Console output stream.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cms-moves-to-portaliq/specs/portal-content/spec.md#requirement-a-page-becomes-a-portal-page-req-cms-101
	 */
	private function warnDropped(array $dropped, OutputInterface $output): void {
		if ($dropped === []) {
			return;
		}

		$output->writeln(
			sprintf(
				'  <comment>Portaliq declares no counterpart for: %s. Carried in props where '
				. 'possible, but it will not render.</comment>',
				implode(', ', $dropped)
			)
		);
	}//end warnDropped()

	/**
	 * Write one mapped page or menu into Portaliq's register, or report that a dry run would.
	 *
	 * @param array<string, mixed> $target The Portaliq page or menu.
	 * @param array<string, mixed> $run The run: `kind`, `objectService`, `dryRun` and `output`.
	 *
	 * @return array{moved:int, skipped:int, failed:int} The tally for this row.
	 *
	 * @spec openspec/changes/cms-moves-to-portaliq/specs/portal-content/spec.md#requirement-a-page-becomes-a-portal-page-req-cms-101
	 */
	private function writeTarget(array $target, array $run): array {
		if ($run['dryRun'] === true) {
			$run['output']->writeln('  <comment>WOULD MOVE</comment>');
			return ['moved' => 0, 'skipped' => 0, 'failed' => 0];
		}

		try {
			// `occ` has no user session, so the default RBAC check runs as
			// Anonymous and refuses the create. The rows were READ with the same
			// flags: a migration that can see a row and not write it moves
			// nothing and reports eight failures.
			$run['objectService']->saveObject(
				object: $target,
				register: CmsMigrationServiceInterface::TARGET_REGISTER,
				schema: $run['kind'],
				_rbac: false,
				_multitenancy: false,
			);
		} catch (Throwable $e) {
			$run['output']->writeln(sprintf('  <error>FAILED: %s</error>', $e->getMessage()));
			return ['moved' => 0, 'skipped' => 0, 'failed' => 1];
		}

		$run['output']->writeln('  <info>MOVED</info>');

		return ['moved' => 1, 'skipped' => 0, 'failed' => 0];
	}//end writeTarget()
}//end class
