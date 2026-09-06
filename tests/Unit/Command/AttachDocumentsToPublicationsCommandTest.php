<?php

/**
 * Unit tests for the attach-documents-to-publications command.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Command;

use OCA\OpenCatalogi\Command\AttachDocumentsToPublicationsCommand;
use OCA\OpenCatalogi\Service\AttachmentMigrationService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Drives the command's reporting, and above all its refusals.
 */
class AttachDocumentsToPublicationsCommandTest extends TestCase {

	/**
	 * Build a command whose OpenRegister collaborators are fakes.
	 *
	 * @param array<int, mixed> $documents    The rows the reader returns.
	 * @param array<int, mixed> $files        The files the document owns.
	 * @param bool              $withServices Whether OpenRegister resolves at all.
	 *
	 * @return CommandTester The tester.
	 */
	private function commandOver(array $documents, array $files = [], bool $withServices = true): CommandTester {
		$objectService = new class($documents) {
			/**
			 * @param array<int, mixed> $documents The rows to return.
			 */
			public function __construct(private array $documents) {
			}

			/**
			 * @return array<int, mixed>
			 */
			public function searchObjectsBySlug(
				string $register,
				string $schema,
				array $filters = [],
				bool $rbac = true,
				bool $multitenancy = true
			): array {
				return ($schema === 'document' ? $this->documents : []);
			}

			public function find(int|string $id, ...$rest): object {
				return new class {
					public function getUuid(): string {
						return 'entity';
					}
				};
			}

			public function deleteObject(string $uuid, ...$rest): bool {
				return true;
			}
		};

		$fileService = new class($files) {
			/**
			 * @param array<int, mixed> $files The files to return.
			 */
			public function __construct(private array $files) {
			}

			/**
			 * @return array<int, mixed>
			 */
			public function getFiles(object $object): array {
				return $this->files;
			}
		};

		$fileMapper = new class {
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($objectService, $fileService, $fileMapper, $withServices) {
				if ($withServices === false) {
					throw new \RuntimeException('OpenRegister absent');
				}

				if (str_contains($id, 'ObjectService') === true) {
					return $objectService;
				}

				if (str_contains($id, 'FileService') === true) {
					return $fileService;
				}

				return $fileMapper;
			}
		);

		$migration = new AttachmentMigrationService(
			container: $container,
			logger: $this->createMock(LoggerInterface::class)
		);

		return new CommandTester(new AttachDocumentsToPublicationsCommand(migration: $migration));

	}//end commandOver()

	/**
	 * A document row.
	 *
	 * @param string $uuid The uuid.
	 * @param mixed  $link The publication link.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function document(string $uuid, mixed $link): array {
		return [
			'@self' => ['uuid' => $uuid],
			'title' => 'A Document',
			'publication' => $link,
			'publicationDate' => '2024-01-01T00:00:00+00:00',
		];

	}//end document()

	/**
	 * Without OpenRegister there is nothing to migrate, and the command says so
	 * rather than reporting a clean run over zero rows.
	 *
	 * @return void
	 */
	public function testItFailsLoudlyWhenOpenRegisterIsAbsent(): void {
		$tester = $this->commandOver(documents: [], withServices: false);

		$this->assertSame(Command::FAILURE, $tester->execute([]));
		$this->assertStringContainsString('OpenRegister is not available', $tester->getDisplay());

	}//end testItFailsLoudlyWhenOpenRegisterIsAbsent()

	/**
	 * The default is a dry run.
	 *
	 * @return void
	 */
	public function testItIsADryRunByDefault(): void {
		$tester = $this->commandOver(documents: []);

		$this->assertSame(Command::SUCCESS, $tester->execute([]));
		$this->assertStringContainsString('DRY-RUN', $tester->getDisplay());
		$this->assertStringContainsString('nothing written', $tester->getDisplay());

	}//end testItIsADryRunByDefault()

	/**
	 * A document naming no publication has nowhere to attach to, so it is left
	 * alone rather than attached somewhere arbitrary.
	 *
	 * @return void
	 */
	public function testADocumentWithNoPublicationIsSkipped(): void {
		$tester = $this->commandOver(documents: [$this->document(uuid: 'doc-1', link: null)]);
		$tester->execute([]);

		$display = $tester->getDisplay();
		$this->assertStringContainsString('names no publication', $display);
		$this->assertStringContainsString('skipped=1', $display);

	}//end testADocumentWithNoPublicationIsSkipped()

	/**
	 * A document with no uuid has no idempotency key, so migrating it would
	 * duplicate work on every run.
	 *
	 * @return void
	 */
	public function testADocumentWithNoUuidIsSkipped(): void {
		$tester = $this->commandOver(documents: [['title' => 'Nameless', 'publication' => 'pub-uuid']]);
		$tester->execute([]);

		$this->assertStringContainsString('no uuid', $tester->getDisplay());

	}//end testADocumentWithNoUuidIsSkipped()

	/**
	 * A document whose publication cannot be found is left in place.
	 *
	 * @return void
	 */
	public function testADocumentWhosePublicationIsMissingIsSkipped(): void {
		$tester = $this->commandOver(
			documents: [$this->document(uuid: 'doc-1', link: ['slug' => 'nowhere'])]
		);
		$tester->execute([]);

		$display = $tester->getDisplay();
		$this->assertStringContainsString('could not be found', $display);
		$this->assertStringContainsString('skipped=1', $display);

	}//end testADocumentWhosePublicationIsMissingIsSkipped()

	/**
	 * A document with no files is refused, AND the refusal names the dead end
	 * it creates: the prune command will not remove a schema that still owns
	 * objects, so this document blocks retirement until someone decides what to
	 * do with it. Without that second line the operator is told "left in place"
	 * and left to discover the consequence themselves.
	 *
	 * @return void
	 */
	public function testADocumentWithNoFilesIsRefusedAndTheDeadEndIsNamed(): void {
		$tester = $this->commandOver(
			documents: [$this->document(uuid: 'doc-1', link: '11111111-2222-4333-8444-555555555555')],
			files: []
		);
		$tester->execute([]);

		$display = $tester->getDisplay();
		$this->assertStringContainsString('No files.', $display);
		$this->assertStringContainsString('lose that metadata', $display);
		$this->assertStringContainsString('blocks retirement', $display);
		$this->assertStringContainsString('prune-retired', $display);
		$this->assertStringContainsString('--force', $display);

	}//end testADocumentWithNoFilesIsRefusedAndTheDeadEndIsNamed()

	/**
	 * The publication window is reported, so a dry run shows what would be
	 * carried onto the file before anything moves.
	 *
	 * @return void
	 */
	public function testTheWindowIsReportedInTheDryRun(): void {
		$tester = $this->commandOver(
			documents: [$this->document(uuid: 'doc-1', link: '11111111-2222-4333-8444-555555555555')]
		);
		$tester->execute([]);

		$this->assertStringContainsString('window 2024-01-01', $tester->getDisplay());

	}//end testTheWindowIsReportedInTheDryRun()
}//end class
