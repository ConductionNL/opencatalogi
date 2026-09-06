<?php

/**
 * OpenCatalogi attach-documents-to-publications command
 *
 * Retires the `document` object by turning each one into a file on the
 * publication it belongs to. See {@see AttachmentMigrationService} for why a
 * document was never a thing in its own right.
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

use DateTime;
use OCA\OpenCatalogi\Service\AttachmentMigrationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Move each document's file onto its publication, then remove the document.
 *
 * @spec openspec/changes/attachments-are-files/specs/publication-attachments/spec.md#requirement-an-attachment-is-a-file-on-the-publication-req-att-101
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class AttachDocumentsToPublicationsCommand extends Command {

	/**
	 * Wire the migration service.
	 *
	 * @param AttachmentMigrationService $migration The migration rules and lazy OR access.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/attachments-are-files/specs/publication-attachments/spec.md#requirement-an-attachment-is-a-file-on-the-publication-req-att-101
	 */
	public function __construct(
		private readonly AttachmentMigrationService $migration,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Define command name, description, and options.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/attachments-are-files/specs/publication-attachments/spec.md#requirement-an-attachment-is-a-file-on-the-publication-req-att-101
	 */
	protected function configure(): void {
		$this->setName(name: 'opencatalogi:documents:attach-to-publications')
			->setDescription(
				'Turn each `document` object into a file on the publication it belongs to, '
				. 'carrying its description, title and publication window onto the file.'
			)
			->addOption(
				'apply',
				null,
				InputOption::VALUE_NONE,
				'Actually move files and remove documents. Without this flag the command reports what it WOULD do.'
			)
			->addOption(
				'keep-documents',
				null,
				InputOption::VALUE_NONE,
				'Move the files but leave the document objects in place. Use for a first pass on real data, '
				. 'so the move can be inspected before anything is removed.'
			);
	}//end configure()

	/**
	 * Read every document and migrate it.
	 *
	 * @param InputInterface  $input  Console input.
	 * @param OutputInterface $output Console output stream.
	 *
	 * @return int Symfony command exit code.
	 *
	 * @spec openspec/changes/attachments-are-files/specs/publication-attachments/spec.md#requirement-an-attachment-is-a-file-on-the-publication-req-att-101
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$dryRun = ((bool)$input->getOption('apply') === false);
		$keepDocuments = (bool)$input->getOption('keep-documents');

		if ($dryRun === true) {
			$output->writeln(
				'<comment>Running in DRY-RUN mode. Nothing is moved and nothing is removed. '
				. 'Re-run with --apply to migrate.</comment>'
			);
		}

		$services = $this->openRegisterServices();
		if ($services === null) {
			$output->writeln('<error>OpenRegister is not available, so nothing can be migrated.</error>');
			return Command::FAILURE;
		}

		$objectService = $services['objects'];
		$fileService = $services['files'];
		$fileMapper = $services['fileMapper'];

		$documents = $this->readDocuments(objectService: $objectService, output: $output);
		if ($documents === null) {
			return Command::FAILURE;
		}

		$tally = ['migrated' => 0, 'files' => 0, 'skipped' => 0, 'failed' => 0];

		foreach ($documents as $document) {
			$outcome = $this->migrateOne(
				document: $document,
				objectService: $objectService,
				fileService: $fileService,
				fileMapper: $fileMapper,
				dryRun: $dryRun,
				keepDocuments: $keepDocuments,
				output: $output
			);

			foreach ($outcome as $key => $value) {
				$tally[$key] += $value;
			}
		}

		$suffix = '';
		if ($dryRun === true) {
			$suffix = ' (dry run, nothing written)';
		}

		$output->writeln(
			sprintf(
				'<info>Done. Migrated=%d documents, files moved=%d, skipped=%d, failed=%d%s</info>',
				$tally['migrated'],
				$tally['files'],
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
	 * Resolve the OpenRegister collaborators, or null when any is unavailable.
	 *
	 * All three are resolved up front rather than per row: a migration that
	 * discovers halfway through that it cannot move files has already removed
	 * documents it cannot put back.
	 *
	 * @return array{objects: object, files: object, fileMapper: object}|null The services, or null.
	 *
	 * @spec openspec/changes/attachments-are-files/specs/publication-attachments/spec.md#requirement-an-attachment-is-a-file-on-the-publication-req-att-101
	 */
	private function openRegisterServices(): ?array {
		$objects = $this->migration->openRegister(id: 'OCA\\OpenRegister\\Service\\ObjectService');
		$files = $this->migration->openRegister(id: 'OCA\\OpenRegister\\Service\\FileService');
		$fileMapper = $this->migration->openRegister(id: 'OCA\\OpenRegister\\Db\\FileMapper');

		if ($objects === null || $files === null || $fileMapper === null) {
			return null;
		}

		return ['objects' => $objects, 'files' => $files, 'fileMapper' => $fileMapper];
	}//end openRegisterServices()

	/**
	 * Read every document, or null when they cannot be read.
	 *
	 * @param object          $objectService The OpenRegister ObjectService.
	 * @param OutputInterface $output        Console output stream.
	 *
	 * @return array<int, mixed>|null The documents, or null on failure.
	 *
	 * @spec openspec/changes/attachments-are-files/specs/publication-attachments/spec.md#requirement-an-attachment-is-a-file-on-the-publication-req-att-101
	 */
	private function readDocuments(object $objectService, OutputInterface $output): ?array {
		try {
			$documents = $objectService->searchObjectsBySlug(
				AttachmentMigrationService::REGISTER_SLUG,
				AttachmentMigrationService::DOCUMENT_SCHEMA_SLUG,
				['_limit' => 5000],
				false,
				false
			);
		} catch (Throwable $e) {
			$output->writeln(sprintf('<error>Could not read documents: %s</error>', $e->getMessage()));
			return null;
		}

		if (is_array($documents) === false) {
			$output->writeln('<error>The object reader returned a count rather than rows.</error>');
			return null;
		}

		return $documents;
	}//end readDocuments()

	/**
	 * Migrate one document.
	 *
	 * @param mixed           $document      The document row.
	 * @param object          $objectService OpenRegister ObjectService.
	 * @param object          $fileService   OpenRegister FileService.
	 * @param object          $fileMapper    OpenRegister FileMapper.
	 * @param bool            $dryRun        Report rather than write.
	 * @param bool            $keepDocuments Leave the document object in place.
	 * @param OutputInterface $output        Console output stream.
	 *
	 * @return array{migrated:int, files:int, skipped:int, failed:int} The tally for this document.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) One document's migration reads
	 *   as one sequence; splitting it would scatter the failure handling.
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) The OpenRegister collaborators
	 *   are resolved once in execute() and passed down rather than re-resolved per row.
	 *
	 * @spec openspec/changes/attachments-are-files/specs/publication-attachments/spec.md#requirement-an-attachment-is-a-file-on-the-publication-req-att-101
	 */
	private function migrateOne(
		mixed $document,
		object $objectService,
		object $fileService,
		object $fileMapper,
		bool $dryRun,
		bool $keepDocuments,
		OutputInterface $output
	): array {
		$none = ['migrated' => 0, 'files' => 0, 'skipped' => 0, 'failed' => 0];

		$fields = $this->migration->toFields(row: $document);
		$uuid = (string)($fields['uuid'] ?? '');
		$name = (string)($fields['title'] ?? ($fields['_name'] ?? 'unnamed'));

		if ($uuid === '') {
			$output->writeln('  <comment>SKIP a document with no uuid.</comment>');
			return array_merge($none, ['skipped' => 1]);
		}

		$link = $this->migration->readPublicationLink(link: ($fields['publication'] ?? null));
		if ($link['id'] === null && $link['slug'] === null) {
			$output->writeln(
				sprintf('<comment>SKIP %s (%s): it names no publication, so there is nowhere to attach it.</comment>', $uuid, $name)
			);
			return array_merge($none, ['skipped' => 1]);
		}

		try {
			$publication = $this->migration->findPublication(objectService: $objectService, link: $link);
		} catch (Throwable $e) {
			$output->writeln(sprintf('<error>FAILED %s: %s</error>', $uuid, $e->getMessage()));
			return array_merge($none, ['failed' => 1]);
		}

		if ($publication === null) {
			$output->writeln(
				sprintf(
					'<comment>SKIP %s (%s): its publication (%s) could not be found.</comment>',
					$uuid,
					$name,
					($link['id'] ?? $link['slug'])
				)
			);
			return array_merge($none, ['skipped' => 1]);
		}

		$metadata = $this->migration->fileMetadataFor(document: $fields);

		try {
			$files = $fileService->getFiles(object: $this->migration->documentEntity(objectService: $objectService, uuid: $uuid));
		} catch (Throwable $e) {
			$output->writeln(sprintf('<error>FAILED %s: could not list its files: %s</error>', $uuid, $e->getMessage()));
			return array_merge($none, ['failed' => 1]);
		}

		$output->writeln(
			sprintf(
				'<info>%s (%s) -> publication %s, %d file(s)%s</info>',
				$uuid,
				$name,
				(string)$publication->getUuid(),
				count($files),
				$this->windowNote(metadata: $metadata)
			)
		);

		if (count($files) === 0) {
			$output->writeln(
				'  <comment>No files. The document carries only metadata, so migrating it would '
				. 'lose that metadata rather than move it. Left in place.</comment>'
			);
			$output->writeln(
				'  <comment>Note this also blocks retirement: openregister:schemas:prune-retired '
				. 'refuses a schema that still owns objects. Decide per document — copy what it '
				. 'says onto the publication and delete it, or accept the loss with --force.</comment>'
			);
			return array_merge($none, ['skipped' => 1]);
		}

		if ($dryRun === true) {
			$output->writeln('  <comment>WOULD MOVE</comment>');
			return $none;
		}

		$moved = 0;
		foreach ($files as $file) {
			try {
				$movedFile = $fileService->moveFile(
					sourceObject: $this->migration->documentEntity(objectService: $objectService, uuid: $uuid),
					fileId: (int)$file->getId(),
					targetObject: $publication
				);
				$this->applyMetadata(fileMapper: $fileMapper, file: $movedFile, metadata: $metadata);
				$moved++;
			} catch (Throwable $e) {
				$output->writeln(sprintf('  <error>FAILED to move file %d: %s</error>', (int)$file->getId(), $e->getMessage()));
				return array_merge($none, ['failed' => 1]);
			}
		}

		if ($keepDocuments === true) {
			$output->writeln(sprintf('  <info>MOVED %d file(s); document kept.</info>', $moved));
			return array_merge($none, ['migrated' => 1, 'files' => $moved]);
		}

		try {
			// The console has no user session, so the default RBAC check runs as
			// Anonymous and refuses the delete. The documents were read with the
			// same flags: a migration that can see a row and not remove it moves
			// its files and then strands it.
			$objectService->deleteObject(uuid: $uuid, _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$output->writeln(
				sprintf('  <error>Files moved but the document could not be removed: %s</error>', $e->getMessage())
			);
			$output->writeln(
				sprintf(
					'  <error>%s now has no files and a re-run will skip it as metadata-only. '
					. 'Remove it by hand: occ openregister:objects:delete %s</error>',
					$uuid,
					$uuid
				)
			);
			return array_merge($none, ['files' => $moved, 'failed' => 1]);
		}

		$output->writeln(sprintf('  <info>MIGRATED %d file(s); document removed.</info>', $moved));

		return array_merge($none, ['migrated' => 1, 'files' => $moved]);
	}//end migrateOne()

	/**
	 * Write the document's metadata onto the moved file.
	 *
	 * @param object               $fileMapper The OpenRegister FileMapper.
	 * @param object               $file       The moved file.
	 * @param array<string, mixed> $metadata   The metadata to write.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/attachments-are-files/specs/publication-attachments/spec.md#requirement-the-documents-metadata-lands-on-the-file-req-att-102
	 */
	private function applyMetadata(object $fileMapper, object $file, array $metadata): void {
		$fileId = (int)$file->getId();

		if ($metadata['description'] !== null) {
			$fileMapper->setDescriptionForFile(fileId: $fileId, description: $metadata['description']);
		}

		if ($metadata['labels'] !== []) {
			$fileMapper->setLabelsForFile(fileId: $fileId, labels: $metadata['labels']);
		}

		$published = null;
		if ($metadata['published'] !== null) {
			$published = new DateTime($metadata['published']);
		}

		$depublished = null;
		if ($metadata['depublished'] !== null) {
			$depublished = new DateTime($metadata['depublished']);
		}

		if ($published !== null || $depublished !== null) {
			$fileMapper->setPublicationWindowForFile(
				fileId: $fileId,
				published: $published,
				depublished: $depublished
			);
		}
	}//end applyMetadata()

	/**
	 * A short note about the window being carried over, for the report.
	 *
	 * @param array<string, mixed> $metadata The file metadata.
	 *
	 * @return string The note, or an empty string.
	 */
	private function windowNote(array $metadata): string {
		if ($metadata['published'] === null && $metadata['depublished'] === null) {
			return '';
		}

		return sprintf(
			', window %s to %s',
			($metadata['published'] ?? 'unset'),
			($metadata['depublished'] ?? 'open')
		);
	}//end windowNote()

}//end class
