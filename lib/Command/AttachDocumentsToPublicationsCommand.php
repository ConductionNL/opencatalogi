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
use OCA\OpenCatalogi\Contract\AttachmentMigrationServiceInterface;
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
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Each migration step is its own small method;
 *   the class total counts every method's entry point on top of the same branching.
 */
class AttachDocumentsToPublicationsCommand extends Command {

	/**
	 * Wire the migration service.
	 *
	 * @param AttachmentMigrationServiceInterface $migration The migration rules and lazy OR access.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/attachments-are-files/specs/publication-attachments/spec.md#requirement-an-attachment-is-a-file-on-the-publication-req-att-101
	 */
	public function __construct(
		private readonly AttachmentMigrationServiceInterface $migration,
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

		$documents = $this->readDocuments(objectService: $services['objects'], output: $output);
		if ($documents === null) {
			return Command::FAILURE;
		}

		$tally = ['migrated' => 0, 'files' => 0, 'skipped' => 0, 'failed' => 0];

		foreach ($documents as $document) {
			$outcome = $this->migrateOne(
				document: $document,
				run: ['services' => $services, 'dryRun' => $dryRun, 'keepDocuments' => $keepDocuments, 'output' => $output]
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
				AttachmentMigrationServiceInterface::REGISTER_SLUG,
				AttachmentMigrationServiceInterface::DOCUMENT_SCHEMA_SLUG,
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
	 * @param mixed               $document The document row.
	 * @param array<string,mixed> $run      The run: `services` from openRegisterServices(), `dryRun`, `keepDocuments` and `output`.
	 *
	 * @return array{migrated:int, files:int, skipped:int, failed:int} The tally for this document.
	 *
	 * @spec openspec/changes/attachments-are-files/specs/publication-attachments/spec.md#requirement-an-attachment-is-a-file-on-the-publication-req-att-101
	 */
	private function migrateOne(mixed $document, array $run): array {
		$fields = $this->migration->toFields(row: $document);
		$doc = [
			'uuid' => (string)($fields['uuid'] ?? ''),
			'name' => (string)($fields['title'] ?? ($fields['_name'] ?? 'unnamed')),
			'fields' => $fields,
		];

		if ($doc['uuid'] === '') {
			$run['output']->writeln('  <comment>SKIP a document with no uuid.</comment>');
			return $this->outcome(counts: ['skipped' => 1]);
		}

		$publication = $this->resolvePublication(doc: $doc, run: $run);
		if (is_array($publication) === true) {
			return $publication;
		}

		$doc['publication'] = $publication;
		$doc['metadata'] = $this->migration->fileMetadataFor(document: $fields);

		$files = $this->listFiles(doc: $doc, run: $run);
		if ($files === null) {
			return $this->outcome(counts: ['failed' => 1]);
		}

		$doc['files'] = $files;
		$this->reportDocument(doc: $doc, output: $run['output']);
		if (count($files) === 0) {
			$this->explainMetadataOnly(output: $run['output']);
			return $this->outcome(counts: ['skipped' => 1]);
		}

		if ($run['dryRun'] === true) {
			$run['output']->writeln('  <comment>WOULD MOVE</comment>');
			return $this->outcome(counts: []);
		}

		$moved = $this->moveFiles(doc: $doc, run: $run);
		if ($moved === null) {
			return $this->outcome(counts: ['failed' => 1]);
		}

		if ($run['keepDocuments'] === true) {
			$run['output']->writeln(sprintf('  <info>MOVED %d file(s); document kept.</info>', $moved));
			return $this->outcome(counts: ['migrated' => 1, 'files' => $moved]);
		}

		$doc['moved'] = $moved;

		return $this->removeDocument(doc: $doc, run: $run);
	}//end migrateOne()

	/**
	 * A tally for one document: zero everywhere except the given counts.
	 *
	 * @param array<string,int> $counts The non-zero counts.
	 *
	 * @return array{migrated:int, files:int, skipped:int, failed:int} The tally.
	 *
	 * @spec openspec/changes/attachments-are-files/specs/publication-attachments/spec.md#requirement-an-attachment-is-a-file-on-the-publication-req-att-101
	 */
	private function outcome(array $counts): array {
		return array_merge(['migrated' => 0, 'files' => 0, 'skipped' => 0, 'failed' => 0], $counts);
	}//end outcome()

	/**
	 * Find the publication a document belongs to, reporting why when there is none.
	 *
	 * @param array<string,mixed> $doc The document's uuid, name and fields.
	 * @param array<string,mixed> $run The run, for its services and output.
	 *
	 * @return object|array The publication, or the document's tally when it has none.
	 *
	 * @spec openspec/changes/attachments-are-files/specs/publication-attachments/spec.md#requirement-an-attachment-is-a-file-on-the-publication-req-att-101
	 */
	private function resolvePublication(array $doc, array $run): object|array {
		$output = $run['output'];
		$link = $this->migration->readPublicationLink(link: ($doc['fields']['publication'] ?? null));
		if ($link['id'] === null && $link['slug'] === null) {
			$output->writeln(
				sprintf('<comment>SKIP %s (%s): it names no publication, so there is nowhere to attach it.</comment>', $doc['uuid'], $doc['name'])
			);
			return $this->outcome(counts: ['skipped' => 1]);
		}

		try {
			$publication = $this->migration->findPublication(objectService: $run['services']['objects'], link: $link);
		} catch (Throwable $e) {
			$output->writeln(sprintf('<error>FAILED %s: %s</error>', $doc['uuid'], $e->getMessage()));
			return $this->outcome(counts: ['failed' => 1]);
		}

		if ($publication === null) {
			$output->writeln(
				sprintf(
					'<comment>SKIP %s (%s): its publication (%s) could not be found.</comment>',
					$doc['uuid'],
					$doc['name'],
					($link['id'] ?? $link['slug'])
				)
			);
			return $this->outcome(counts: ['skipped' => 1]);
		}

		return $publication;
	}//end resolvePublication()

	/**
	 * The files a document owns, or null (reported) when they cannot be listed.
	 *
	 * @param array<string,mixed> $doc The document's uuid, name and fields.
	 * @param array<string,mixed> $run The run, for its services and output.
	 *
	 * @return array<int, object>|null The files, or null on failure.
	 *
	 * @spec openspec/changes/attachments-are-files/specs/publication-attachments/spec.md#requirement-an-attachment-is-a-file-on-the-publication-req-att-101
	 */
	private function listFiles(array $doc, array $run): ?array {
		try {
			return $run['services']['files']->getFiles(
				object: $this->migration->documentEntity(objectService: $run['services']['objects'], uuid: $doc['uuid'])
			);
		} catch (Throwable $e) {
			$run['output']->writeln(sprintf('<error>FAILED %s: could not list its files: %s</error>', $doc['uuid'], $e->getMessage()));
			return null;
		}
	}//end listFiles()

	/**
	 * Report which publication a document goes to, how many files it has and the window it carries.
	 *
	 * @param array<string,mixed> $doc    The document, with its publication, metadata and files.
	 * @param OutputInterface     $output Console output stream.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/attachments-are-files/specs/publication-attachments/spec.md#requirement-an-attachment-is-a-file-on-the-publication-req-att-101
	 */
	private function reportDocument(array $doc, OutputInterface $output): void {
		$output->writeln(
			sprintf(
				'<info>%s (%s) -> publication %s, %d file(s)%s</info>',
				$doc['uuid'],
				$doc['name'],
				(string)$doc['publication']->getUuid(),
				count($doc['files']),
				$this->windowNote(metadata: $doc['metadata'])
			)
		);
	}//end reportDocument()

	/**
	 * Explain why a document without files is left in place, and what that blocks.
	 *
	 * @param OutputInterface $output Console output stream.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/attachments-are-files/specs/publication-attachments/spec.md#requirement-an-attachment-is-a-file-on-the-publication-req-att-101
	 */
	private function explainMetadataOnly(OutputInterface $output): void {
		$output->writeln(
			'  <comment>No files. The document carries only metadata, so migrating it would '
			. 'lose that metadata rather than move it. Left in place.</comment>'
		);
		$output->writeln(
			'  <comment>Note this also blocks retirement: openregister:schemas:prune-retired '
			. 'refuses a schema that still owns objects. Decide per document — copy what it '
			. 'says onto the publication and delete it, or accept the loss with --force.</comment>'
		);
	}//end explainMetadataOnly()

	/**
	 * Move each file onto the publication and write the document's metadata onto it.
	 *
	 * Stops at the first file that cannot be moved.
	 *
	 * @param array<string,mixed> $doc The document, with its publication, metadata and files.
	 * @param array<string,mixed> $run The run, for its services and output.
	 *
	 * @return int|null The number of files moved, or null when a move failed.
	 *
	 * @spec openspec/changes/attachments-are-files/specs/publication-attachments/spec.md#requirement-the-documents-metadata-lands-on-the-file-req-att-102
	 */
	private function moveFiles(array $doc, array $run): ?int {
		$services = $run['services'];
		$moved = 0;
		foreach ($doc['files'] as $file) {
			try {
				$movedFile = $services['files']->moveFile(
					sourceObject: $this->migration->documentEntity(objectService: $services['objects'], uuid: $doc['uuid']),
					fileId: (int)$file->getId(),
					targetObject: $doc['publication']
				);
				$this->applyMetadata(fileMapper: $services['fileMapper'], file: $movedFile, metadata: $doc['metadata']);
				$moved++;
			} catch (Throwable $e) {
				$run['output']->writeln(sprintf('  <error>FAILED to move file %d: %s</error>', (int)$file->getId(), $e->getMessage()));
				return null;
			}
		}

		return $moved;
	}//end moveFiles()

	/**
	 * Remove a document whose files have moved, naming the manual step when that fails.
	 *
	 * @param array<string,mixed> $doc The document, with the number of files `moved`.
	 * @param array<string,mixed> $run The run, for its services and output.
	 *
	 * @return array{migrated:int, files:int, skipped:int, failed:int} The tally for this document.
	 *
	 * @spec openspec/changes/attachments-are-files/specs/publication-attachments/spec.md#requirement-an-attachment-is-a-file-on-the-publication-req-att-101
	 */
	private function removeDocument(array $doc, array $run): array {
		$output = $run['output'];
		$moved = $doc['moved'];
		try {
			// The console has no user session, so the default RBAC check runs as
			// Anonymous and refuses the delete. The documents were read with the
			// same flags: a migration that can see a row and not remove it moves
			// its files and then strands it.
			$run['services']['objects']->deleteObject(uuid: $doc['uuid'], _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$output->writeln(
				sprintf('  <error>Files moved but the document could not be removed: %s</error>', $e->getMessage())
			);
			$output->writeln(
				sprintf(
					'  <error>%s now has no files and a re-run will skip it as metadata-only. '
					. 'Remove it by hand: occ openregister:objects:delete %s</error>',
					$doc['uuid'],
					$doc['uuid']
				)
			);
			return $this->outcome(counts: ['files' => $moved, 'failed' => 1]);
		}

		$output->writeln(sprintf('  <info>MIGRATED %d file(s); document removed.</info>', $moved));

		return $this->outcome(counts: ['migrated' => 1, 'files' => $moved]);
	}//end removeDocument()

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
