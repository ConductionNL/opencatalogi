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

use OCA\OpenCatalogi\Service\CmsMigrationService;
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
	 * @param CmsMigrationService $migration The mapping rules and lazy OR access.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cms-moves-to-portaliq/specs/portal-content/spec.md#requirement-a-page-becomes-a-portal-page-req-cms-101
	 */
	public function __construct(private readonly CmsMigrationService $migration) {
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
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 *
	 * @spec openspec/changes/cms-moves-to-portaliq/specs/portal-content/spec.md#requirement-a-page-becomes-a-portal-page-req-cms-101
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$portal = trim((string)$input->getOption('portal'));
		$dryRun = true;
		if ((bool)$input->getOption('apply') === true) {
			$dryRun = false;
		}

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

		$tally = ['moved' => 0, 'skipped' => 0, 'failed' => 0];

		foreach (['page', 'menu'] as $kind) {
			$rows = $this->read(objectService: $objectService, schema: $kind, output: $output);
			if ($rows === null) {
				return Command::FAILURE;
			}

			foreach ($rows as $row) {
				$outcome = $this->moveOne(
					kind: $kind,
					row: $row,
					portal: $portal,
					objectService: $objectService,
					dryRun: $dryRun,
					output: $output
				);
				foreach ($outcome as $key => $value) {
					$tally[$key] += $value;
				}
			}
		}

		$suffix = '';
		if ($dryRun === true) {
			$suffix = ' (dry run, nothing written)';
		}

		$output->writeln(
			sprintf(
				'<info>Done. Moved=%d, skipped=%d, failed=%d%s</info>',
				$tally['moved'],
				$tally['skipped'],
				$tally['failed'],
				$suffix
			)
		);

		if ($tally['failed'] > 0) {
			return Command::FAILURE;
		}

		return Command::SUCCESS;
	}//end execute()

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
				CmsMigrationService::SOURCE_REGISTER,
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
	 * @param string          $kind          Either `page` or `menu`.
	 * @param mixed           $row           The source row.
	 * @param string          $portal        The target portal slug.
	 * @param object          $objectService The OpenRegister ObjectService.
	 * @param bool            $dryRun        Report rather than write.
	 * @param OutputInterface $output        Console output stream.
	 *
	 * @return array{moved:int, skipped:int, failed:int} The tally for this row.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) The collaborators are resolved
	 *   once in execute() and passed down rather than re-resolved per row.
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 *
	 * @spec openspec/changes/cms-moves-to-portaliq/specs/portal-content/spec.md#requirement-a-page-becomes-a-portal-page-req-cms-101
	 */
	private function moveOne(
		string $kind,
		mixed $row,
		string $portal,
		object $objectService,
		bool $dryRun,
		OutputInterface $output
	): array {
		$none = ['moved' => 0, 'skipped' => 0, 'failed' => 0];
		$fields = $this->migration->toFields(row: $row);
		$name = (string)($fields['title'] ?? 'untitled');

		[$target, $label, $built] = $this->mapRow(kind: $kind, fields: $fields, portal: $portal, name: $name);

		$output->writeln(sprintf('<info>%s: %s</info>', $kind, $label));

		if ($built['unmapped'] !== []) {
			$output->writeln(
				sprintf(
					'  <error>REFUSED: no Portaliq widget is declared for content block type(s): %s. '
					. 'Guessing one would save a page that renders nothing.</error>',
					implode(', ', $built['unmapped'])
				)
			);
			return array_merge($none, ['failed' => 1]);
		}

		if ($built['dropped'] !== []) {
			$output->writeln(
				sprintf(
					'  <comment>Portaliq declares no counterpart for: %s. Carried in props where '
					. 'possible, but it will not render.</comment>',
					implode(', ', $built['dropped'])
				)
			);
		}

		if ($dryRun === true) {
			$output->writeln('  <comment>WOULD MOVE</comment>');
			return $none;
		}

		try {
			// `occ` has no user session, so the default RBAC check runs as
			// Anonymous and refuses the create. The rows were READ with the same
			// flags: a migration that can see a row and not write it moves
			// nothing and reports eight failures.
			$objectService->saveObject(
				object: $target,
				register: CmsMigrationService::TARGET_REGISTER,
				schema: $kind,
				_rbac: false,
				_multitenancy: false,
			);
		} catch (Throwable $e) {
			$output->writeln(sprintf('  <error>FAILED: %s</error>', $e->getMessage()));
			return array_merge($none, ['failed' => 1]);
		}

		$output->writeln('  <info>MOVED</info>');

		return array_merge($none, ['moved' => 1]);
	}//end moveOne()
}//end class
