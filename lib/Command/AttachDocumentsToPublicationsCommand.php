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
use OCA\OpenRegister\Service\File\FileTransfer;
use OCA\OpenRegister\Service\Object\ObjectDeletion;
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
	 * The command's flags and what each one does.
	 *
	 * @var array<string, string>
	 */
	private const OPTIONS = [
		'apply' => 'Actually move files and remove documents. Without this flag the command reports what it WOULD do.',
		'keep-documents' => 'Move the files but leave the document objects in place. Use for a first pass on real data, '
			. 'so the move can be inspected before anything is removed.',
	];

	/**
	 * The end of the closing line: a dry run says that nothing was written.
	 *
	 * @var array<int, string>
	 */
	private const DONE_SUFFIX = [0 => '', 1 => ' (dry run, nothing written)'];

	/**
	 * Every line the command writes, as a vsprintf format.
	 *
	 * @var array<string, string>
	 */
	private const MESSAGES = [
		'dryRun' => '<comment>Running in DRY-RUN mode. Nothing is moved and nothing is removed. Re-run with --apply to migrate.</comment>',
		'noOpenRegister' => '<error>OpenRegister is not available, so nothing can be migrated.</error>',
		'readFailed' => '<error>Could not read documents: %s</error>',
		'countNotRows' => '<error>The object reader returned a count rather than rows.</error>',
		'done' => '<info>Done. Migrated=%d documents, files moved=%d, skipped=%d, failed=%d%s</info>',
		'noUuid' => '  <comment>SKIP a document with no uuid.</comment>',
		'noPublication' => '<comment>SKIP %s (%s): it names no publication, so there is nowhere to attach it.</comment>',
		'failed' => '<error>FAILED %s: %s</error>',
		'publicationMissing' => '<comment>SKIP %s (%s): its publication (%s) could not be found.</comment>',
		'listFailed' => '<error>FAILED %s: could not list its files: %s</error>',
		'report' => '<info>%s (%s) -> publication %s, %d file(s)%s</info>',
		'metadataOnly' => '  <comment>No files. The document carries only metadata, so migrating it would '
			. 'lose that metadata rather than move it. Left in place.</comment>',
		'blocksRetirement' => '  <comment>Note this also blocks retirement: openregister:schemas:prune-retired '
			. 'refuses a schema that still owns objects. Decide per document — copy what it '
			. 'says onto the publication and delete it, or accept the loss with --force.</comment>',
		'wouldMove' => '  <comment>WOULD MOVE</comment>',
		'movedKept' => '  <info>MOVED %d file(s); document kept.</info>',
		'moveFailed' => '  <error>FAILED to move file %d: %s</error>',
		'removeFailed' => '  <error>Files moved but the document could not be removed: %s</error>',
		'removeByHand' => '  <error>%s now has no files and a re-run will skip it as metadata-only. '
			. 'Remove it by hand: occ openregister:objects:delete %s</error>',
		'migrated' => '  <info>MIGRATED %d file(s); document removed.</info>',
	];

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
			);
		foreach (self::OPTIONS as $name => $description) {
			$this->addOption(name: $name, mode: InputOption::VALUE_NONE, description: $description);
		}
	}//end configure()

	/**
	 * Read every document and migrate it.
	 *
	 * @param InputInterface $input Console input.
	 * @param OutputInterface $output Console output stream.
	 *
	 * @return int Symfony command exit code.
	 *
	 * @spec openspec/changes/attachments-are-files/specs/publication-attachments/spec.md#requirement-an-attachment-is-a-file-on-the-publication-req-att-101
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$run = ['dryRun' => ((bool)$input->getOption('apply') === false), 'output' => $output];
		$run['keepDocuments'] = (bool)$input->getOption('keep-documents');
		if ($run['dryRun'] === true) {
			self::say(output: $output, line: ['dryRun']);
		}

		$run['services'] = $this->openRegisterServices();
		$documents = $this->documentsToMigrate(run: $run);
		if ($documents === null) {
			return Command::FAILURE;
		}

		return $this->migrateAll(documents: $documents, run: $run);
	}//end execute()

	/**
	 * The document rows to migrate, or null (reported) when OpenRegister is missing or the read fails.
	 *
	 * @param array<string,mixed> $run The run, with the OpenRegister services (null when absent) and output.
	 *
	 * @return array|null
	 */
	private function documentsToMigrate(array $run): ?array {
		if ($run['services'] === null) {
			self::say(output: $run['output'], line: ['noOpenRegister']);
			return null;
		}

		return $this->readDocuments(objectService: $run['services']['objects'], output: $run['output']);
	}//end documentsToMigrate()

	/**
	 * Migrate every document, report the tally, and fail when any document failed.
	 *
	 * @param array<int, mixed> $documents The document rows.
	 * @param array<string,mixed> $run The run: `services`, `dryRun`, `keepDocuments` and `output`.
	 *
	 * @return int Symfony command exit code.
	 *
	 * @spec openspec/changes/attachments-are-files/specs/publication-attachments/spec.md#requirement-an-attachment-is-a-file-on-the-publication-req-att-101
	 */
	private function migrateAll(array $documents, array $run): int {
		$tally = $this->outcome(counts: []);
		foreach ($documents as $document) {
			foreach ($this->migrateOne(document: $document, run: $run) as $key => $value) {
				$tally[$key] += $value;
			}
		}

		self::say(output: $run['output'], line: ['done', ...array_values($tally), self::DONE_SUFFIX[(int)$run['dryRun']]]);

		if ($tally['failed'] > 0) {
			return Command::FAILURE;
		}

		return Command::SUCCESS;
	}//end migrateAll()

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
		$fileMapper = $this->migration->openRegister(id: 'OCA\\OpenRegister\\Files\\Db\\FileMapper');

		if ($objects === null || $files === null || $fileMapper === null) {
			return null;
		}

		return ['objects' => $objects, 'files' => $files, 'fileMapper' => $fileMapper];
	}//end openRegisterServices()

	/**
	 * Read every document, or null when they cannot be read.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param OutputInterface $output Console output stream.
	 *
	 * @return array<int, mixed>|null The documents, or null on failure.
	 *
	 * @spec openspec/changes/attachments-are-files/specs/publication-attachments/spec.md#requirement-an-attachment-is-a-file-on-the-publication-req-att-101
	 */
	private function readDocuments(object $objectService, OutputInterface $output): ?array {
		try {
			$register = AttachmentMigrationServiceInterface::REGISTER_SLUG;
			$schema = AttachmentMigrationServiceInterface::DOCUMENT_SCHEMA_SLUG;
			$documents = $objectService->searchObjectsBySlug($register, $schema, ['_limit' => 5000], false, false);
		} catch (Throwable $e) {
			self::say(output: $output, line: ['readFailed', $e->getMessage()]);
			return null;
		}

		if (is_array($documents) === false) {
			self::say(output: $output, line: ['countNotRows']);
			return null;
		}

		return $documents;
	}//end readDocuments()

	/**
	 * Migrate one document.
	 *
	 * @param mixed $document The document row.
	 * @param array<string,mixed> $run The run: `services` from openRegisterServices(), `dryRun`, `keepDocuments` and `output`.
	 *
	 * @return array{migrated:int, files:int, skipped:int, failed:int} The tally for this document.
	 *
	 * @spec openspec/changes/attachments-are-files/specs/publication-attachments/spec.md#requirement-an-attachment-is-a-file-on-the-publication-req-att-101
	 */
	private function migrateOne(mixed $document, array $run): array {
		$doc = $this->documentOf(document: $document);
		if ($doc['uuid'] === '') {
			self::say(output: $run['output'], line: ['noUuid']);
			return $this->outcome(counts: ['skipped' => 1]);
		}

		$publication = $this->resolvePublication(doc: $doc, run: $run);
		if (is_array($publication) === true) {
			return $publication;
		}

		$doc['publication'] = $publication;
		$doc['metadata'] = $this->migration->fileMetadataFor(document: $doc['fields']);

		return $this->moveDocument(doc: $doc, run: $run);
	}//end migrateOne()

	/**
	 * A document row as its uuid, display name and fields.
	 *
	 * @param mixed $document The document row.
	 *
	 * @return array{uuid: string, name: string, fields: array<string, mixed>}
	 */
	private function documentOf(mixed $document): array {
		$fields = $this->migration->toFields(row: $document);
		return [
			'uuid' => (string)($fields['uuid'] ?? ''),
			'name' => (string)($fields['title'] ?? ($fields['_name'] ?? 'unnamed')),
			'fields' => $fields,
		];
	}//end documentOf()

	/**
	 * Report a document with its files, then move them and remove the document as the run allows.
	 *
	 * @param array<string,mixed> $doc The document's uuid, name, fields, publication, metadata and files.
	 * @param array<string,mixed> $run The run: `services`, `dryRun`, `keepDocuments` and `output`.
	 *
	 * @return array{migrated:int, files:int, skipped:int, failed:int} The tally for this document.
	 *
	 * @spec openspec/changes/attachments-are-files/specs/publication-attachments/spec.md#requirement-an-attachment-is-a-file-on-the-publication-req-att-101
	 */
	private function moveDocument(array $doc, array $run): array {
		$doc['files'] = $this->listFiles(doc: $doc, run: $run);
		if ($doc['files'] === null) {
			return $this->outcome(counts: ['failed' => 1]);
		}

		$this->reportDocument(doc: $doc, output: $run['output']);
		if (count($doc['files']) === 0) {
			self::say(output: $run['output'], line: ['metadataOnly']);
			self::say(output: $run['output'], line: ['blocksRetirement']);
			return $this->outcome(counts: ['skipped' => 1]);
		}

		return $this->applyMove(doc: $doc, run: $run);
	}//end moveDocument()

	/**
	 * Move the document's files, then remove the document unless it is to be kept; a dry run only says so.
	 *
	 * @param array<string,mixed> $doc The document, with its publication, metadata and files.
	 * @param array<string,mixed> $run The run: `services`, `dryRun`, `keepDocuments` and `output`.
	 *
	 * @return array{migrated:int, files:int, skipped:int, failed:int} The tally for this document.
	 */
	private function applyMove(array $doc, array $run): array {
		if ($run['dryRun'] === true) {
			self::say(output: $run['output'], line: ['wouldMove']);
			return $this->outcome(counts: []);
		}

		$doc['moved'] = $this->moveFiles(doc: $doc, run: $run);
		if ($doc['moved'] === null) {
			return $this->outcome(counts: ['failed' => 1]);
		}

		if ($run['keepDocuments'] === true) {
			self::say(output: $run['output'], line: ['movedKept', $doc['moved']]);
			return $this->outcome(counts: ['migrated' => 1, 'files' => $doc['moved']]);
		}

		return $this->removeDocument(doc: $doc, run: $run);
	}//end applyMove()

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
		$link = $this->migration->readPublicationLink(link: ($doc['fields']['publication'] ?? null));
		if ($link['id'] === null && $link['slug'] === null) {
			self::say(output: $run['output'], line: ['noPublication', $doc['uuid'], $doc['name']]);
			return $this->outcome(counts: ['skipped' => 1]);
		}

		return $this->findPublication(doc: $doc + ['link' => $link], run: $run);
	}//end resolvePublication()

	/**
	 * The publication a document links to, or the document's tally (reported) when the lookup fails or finds nothing.
	 *
	 * @param array<string,mixed> $doc The document's uuid, name and publication link.
	 * @param array<string,mixed> $run The run, for its services and output.
	 *
	 * @return object|array
	 */
	private function findPublication(array $doc, array $run): object|array {
		try {
			$publication = $this->migration->findPublication(objectService: $run['services']['objects'], link: $doc['link']);
		} catch (Throwable $e) {
			self::say(output: $run['output'], line: ['failed', $doc['uuid'], $e->getMessage()]);
			return $this->outcome(counts: ['failed' => 1]);
		}

		if ($publication === null) {
			$named = ($doc['link']['id'] ?? $doc['link']['slug']);
			self::say(output: $run['output'], line: ['publicationMissing', $doc['uuid'], $doc['name'], $named]);
			return $this->outcome(counts: ['skipped' => 1]);
		}

		return $publication;
	}//end findPublication()

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
			self::say(output: $run['output'], line: ['listFailed', $doc['uuid'], $e->getMessage()]);
			return null;
		}
	}//end listFiles()

	/**
	 * Report which publication a document goes to, how many files it has and the window it carries.
	 *
	 * @param array<string,mixed> $doc The document, with its publication, metadata and files.
	 * @param OutputInterface $output Console output stream.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/attachments-are-files/specs/publication-attachments/spec.md#requirement-an-attachment-is-a-file-on-the-publication-req-att-101
	 */
	private function reportDocument(array $doc, OutputInterface $output): void {
		$publication = (string)$doc['publication']->getUuid();
		$window = $this->windowNote(metadata: $doc['metadata']);
		self::say(output: $output, line: ['report', $doc['uuid'], $doc['name'], $publication, count($doc['files']), $window]);
	}//end reportDocument()

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
		$moved = 0;
		foreach ($doc['files'] as $file) {
			try {
				$this->moveFile(file: $file, doc: $doc + ['services' => $run['services']]);
				$moved++;
			} catch (Throwable $e) {
				self::say(output: $run['output'], line: ['moveFailed', (int)$file->getId(), $e->getMessage()]);
				return null;
			}
		}

		return $moved;
	}//end moveFiles()

	/**
	 * Move one file from the document onto its publication and carry the metadata over.
	 *
	 * @param object $file The file.
	 * @param array<string,mixed> $doc The document, with its publication, metadata and the run's services.
	 *
	 * @return void
	 */
	private function moveFile(object $file, array $doc): void {
		$services = $doc['services'];
		$movedFile = $services['files']->moveAttachedFile(
			transfer: new FileTransfer(
				source: $this->migration->documentEntity(objectService: $services['objects'], uuid: $doc['uuid']),
				fileId: (int)$file->getId(),
				target: $doc['publication']
			)
		);
		$this->applyMetadata(file: $movedFile, target: ['fileMapper' => $services['fileMapper'], 'metadata' => $doc['metadata']]);
	}//end moveFile()

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
			$run['services']['objects']->deleteObject(deletion: new ObjectDeletion(uuid: $doc['uuid']), _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			self::say(output: $output, line: ['removeFailed', $e->getMessage()]);
			self::say(output: $output, line: ['removeByHand', $doc['uuid'], $doc['uuid']]);
			return $this->outcome(counts: ['files' => $moved, 'failed' => 1]);
		}

		self::say(output: $output, line: ['migrated', $moved]);

		return $this->outcome(counts: ['migrated' => 1, 'files' => $moved]);
	}//end removeDocument()

	/**
	 * Carry a document's description, labels and publication window onto the moved file.
	 *
	 * @param object $file The moved file.
	 * @param array $target The OpenRegister FileMapper and the document's file metadata.
	 *
	 * @return void
	 */
	private function applyMetadata(object $file, array $target): void {
		$fileId = (int)$file->getId();
		$fileMapper = $target['fileMapper'];
		$metadata = $target['metadata'];

		if ($metadata['description'] !== null) {
			$fileMapper->setDescriptionForFile(fileId: $fileId, description: $metadata['description']);
		}

		if ($metadata['labels'] !== []) {
			$fileMapper->setLabelsForFile(fileId: $fileId, labels: $metadata['labels']);
		}

		$window = self::windowDates(metadata: $metadata);
		if ($window !== null) {
			$fileMapper->setPublicationWindowForFile(fileId: $fileId, published: $window['published'], depublished: $window['depublished']);
		}
	}//end applyMetadata()

	/**
	 * The publication window as dates, or null when neither end is set.
	 *
	 * @param array $metadata The document's file metadata.
	 *
	 * @return array{published: DateTime|null, depublished: DateTime|null}|null
	 */
	private static function windowDates(array $metadata): ?array {
		if ($metadata['published'] === null && $metadata['depublished'] === null) {
			return null;
		}

		return [
			'published' => self::dateOrNull(value: $metadata['published']),
			'depublished' => self::dateOrNull(value: $metadata['depublished']),
		];
	}//end windowDates()

	/**
	 * A date value as a DateTime, or null when unset.
	 *
	 * @param string|null $value The date.
	 *
	 * @return DateTime|null
	 */
	private static function dateOrNull(?string $value): ?DateTime {
		if ($value === null) {
			return null;
		}

		return new DateTime($value);
	}//end dateOrNull()

	/**
	 * Write one line from the message table.
	 *
	 * @param OutputInterface $output The console output.
	 * @param array $line The message key, then its arguments.
	 *
	 * @return void
	 */
	private static function say(OutputInterface $output, array $line): void {
		$output->writeln(vsprintf(self::MESSAGES[array_shift($line)], $line));
	}//end say()

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
