<?php

/**
 * Characterization tests for the --apply path of the attach-documents-to-publications command.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
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
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Pins what the command writes and reports when it moves files.
 */
class AttachDocumentsApplyTest extends TestCase {
	private const PUB = '11111111-2222-4333-8444-555555555555';

	/** @var array<int, string> Calls the fakes saw, in order. */
	private array $calls = [];

	/**
	 * Build a command over fakes; each failure flag makes one collaborator throw.
	 *
	 * @param array<int, mixed>   $documents The document rows.
	 * @param array<int, int>     $fileIds   The ids of the files each document owns.
	 * @param array<string, bool> $fail      Which collaborator throws: find, getFiles, moveFile, deleteObject.
	 *
	 * @return CommandTester The tester.
	 */
	private function commandOver(array $documents, array $fileIds, array $fail = []): CommandTester {
		$calls = &$this->calls;

		$entity = static fn (string $uuid): object => new class($uuid) {
			public function __construct(private string $uuid) {
			}

			public function getUuid(): string {
				return $this->uuid;
			}
		};

		$objectService = new class($documents, $fail, $entity, $calls) {
			public function __construct(private array $documents, private array $fail, private $entity, private array &$calls) {
			}

			public function searchObjectsBySlug(string $register, string $schema, array $filters = [], bool $rbac = true, bool $multitenancy = true): array {
				return ($schema === 'document' ? $this->documents : []);
			}

			public function find(int|string $id, ...$rest): object {
				if (($this->fail['find'] ?? false) === true && $id === AttachDocumentsApplyTest::publication()) {
					throw new RuntimeException('find broke');
				}

				return ($this->entity)((string)$id);
			}

			public function deleteObject(string $uuid, ...$rest): bool {
				$this->calls[] = 'delete '.$uuid.' '.json_encode($rest);
				if (($this->fail['deleteObject'] ?? false) === true) {
					throw new RuntimeException('delete broke');
				}

				return true;
			}
		};

		$fileService = new class($fileIds, $fail, $calls) {
			public function __construct(private array $fileIds, private array $fail, private array &$calls) {
			}

			public function getFiles(object $object): array {
				if (($this->fail['getFiles'] ?? false) === true) {
					throw new RuntimeException('list broke');
				}

				return array_map(
					static fn (int $id): object => new class($id) {
						public function __construct(private int $id) {
						}

						public function getId(): int {
							return $this->id;
						}
					},
					$this->fileIds
				);
			}

			public function moveFile(object $sourceObject, int $fileId, object $targetObject): object {
				$this->calls[] = 'move '.$fileId.' from '.$sourceObject->getUuid().' to '.$targetObject->getUuid();
				if (($this->fail['moveFile'] ?? false) === true && $fileId === 8) {
					throw new RuntimeException('move broke');
				}

				return new class($fileId + 100) {
					public function __construct(private int $id) {
					}

					public function getId(): int {
						return $this->id;
					}
				};
			}
		};

		$fileMapper = new class($calls) {
			public function __construct(private array &$calls) {
			}

			public function setDescriptionForFile(int $fileId, string $description): void {
				$this->calls[] = 'description '.$fileId.' '.$description;
			}

			public function setLabelsForFile(int $fileId, array $labels): void {
				$this->calls[] = 'labels '.$fileId.' '.implode(',', $labels);
			}

			public function setPublicationWindowForFile(int $fileId, ?\DateTime $published, ?\DateTime $depublished): void {
				$this->calls[] = 'window '.$fileId.' '.$published?->format('Y-m-d').' '.$depublished?->format('Y-m-d');
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($objectService, $fileService, $fileMapper) {
				if (str_contains($id, 'ObjectService') === true) {
					return $objectService;
				}

				if (str_contains($id, 'FileService') === true) {
					return $fileService;
				}

				return $fileMapper;
			}
		);

		$migration = new AttachmentMigrationService(container: $container, logger: $this->createMock(LoggerInterface::class));

		return new CommandTester(new AttachDocumentsToPublicationsCommand(migration: $migration));

	}//end commandOver()

	/**
	 * The publication uuid every document links to.
	 *
	 * @return string
	 */
	public static function publication(): string {
		return self::PUB;

	}//end publication()

	/**
	 * A document row with metadata to carry onto its files.
	 *
	 * @return array<string, mixed>
	 */
	private function document(): array {
		return [
			'@self' => ['uuid' => 'doc-1'],
			'title' => 'A Document',
			'description' => 'About it',
			'publication' => self::PUB,
			'publicationDate' => '2024-01-01T00:00:00+00:00',
		];

	}//end document()

	public function testApplyMovesEveryFileWritesTheMetadataAndRemovesTheDocument(): void {
		$tester = $this->commandOver(documents: [$this->document()], fileIds: [7, 8]);

		$this->assertSame(Command::SUCCESS, $tester->execute(['--apply' => true]));
		$this->assertSame($this->expectedDisplay(tail: "  MIGRATED 2 file(s); document removed.\nDone. Migrated=1 documents, files moved=2, skipped=0, failed=0\n"), $tester->getDisplay());
		$this->assertSame(
			[
				'move 7 from doc-1 to '.self::PUB,
				'description 107 About it',
				'labels 107 A Document',
				'window 107 2024-01-01 ',
				'move 8 from doc-1 to '.self::PUB,
				'description 108 About it',
				'labels 108 A Document',
				'window 108 2024-01-01 ',
				'delete doc-1 {"_rbac":false,"_multitenancy":false}',
			],
			$this->calls
		);
	}

	public function testKeepDocumentsMovesTheFilesAndLeavesTheDocument(): void {
		$tester = $this->commandOver(documents: [$this->document()], fileIds: [7]);

		$this->assertSame(Command::SUCCESS, $tester->execute(['--apply' => true, '--keep-documents' => true]));
		$this->assertStringContainsString("  MOVED 1 file(s); document kept.\nDone. Migrated=1 documents, files moved=1, skipped=0, failed=0\n", $tester->getDisplay());
		$this->assertNotContains('delete doc-1 {"_rbac":false,"_multitenancy":false}', $this->calls);
	}

	public function testAFailedMoveStopsTheDocumentAndCountsAsFailed(): void {
		$tester = $this->commandOver(documents: [$this->document()], fileIds: [7, 8, 9], fail: ['moveFile' => true]);

		$this->assertSame(Command::FAILURE, $tester->execute(['--apply' => true]));
		$this->assertStringContainsString("  FAILED to move file 8: move broke\nDone. Migrated=0 documents, files moved=0, skipped=0, failed=1\n", $tester->getDisplay());
		$this->assertSame(['move 7 from doc-1 to '.self::PUB, 'description 107 About it', 'labels 107 A Document', 'window 107 2024-01-01 ', 'move 8 from doc-1 to '.self::PUB], $this->calls);
	}

	public function testAFailedRemovalKeepsTheMovedCountAndNamesTheManualStep(): void {
		$tester = $this->commandOver(documents: [$this->document()], fileIds: [7], fail: ['deleteObject' => true]);

		$this->assertSame(Command::FAILURE, $tester->execute(['--apply' => true]));
		$this->assertStringContainsString(
			"  Files moved but the document could not be removed: delete broke\n"
			."  doc-1 now has no files and a re-run will skip it as metadata-only. Remove it by hand: occ openregister:objects:delete doc-1\n"
			."Done. Migrated=0 documents, files moved=1, skipped=0, failed=1\n",
			$tester->getDisplay()
		);
	}

	public function testUnlistableFilesCountAsFailed(): void {
		$tester = $this->commandOver(documents: [$this->document()], fileIds: [7], fail: ['getFiles' => true]);

		$this->assertSame(Command::FAILURE, $tester->execute(['--apply' => true]));
		$this->assertStringContainsString("FAILED doc-1: could not list its files: list broke\nDone. Migrated=0 documents, files moved=0, skipped=0, failed=1\n", $tester->getDisplay());
		$this->assertSame([], $this->calls);
	}

	public function testAPublicationLookupThatThrowsIsReportedAsMissing(): void {
		$tester = $this->commandOver(documents: [$this->document()], fileIds: [7], fail: ['find' => true]);

		$this->assertSame(Command::SUCCESS, $tester->execute(['--apply' => true]));
		$this->assertStringContainsString('SKIP doc-1 (A Document): its publication ('.self::PUB.') could not be found.', $tester->getDisplay());
	}

	public function testTheDryRunReportsWhatItWouldMove(): void {
		$tester = $this->commandOver(documents: [$this->document()], fileIds: [7]);

		$this->assertSame(Command::SUCCESS, $tester->execute([]));
		$this->assertStringContainsString("  WOULD MOVE\nDone. Migrated=0 documents, files moved=0, skipped=0, failed=0 (dry run, nothing written)\n", $tester->getDisplay());
		$this->assertSame([], $this->calls);
	}

	/**
	 * The display of an --apply run over the one document, ending with the given lines.
	 *
	 * @param string $tail The lines after the per-document header.
	 *
	 * @return string
	 */
	private function expectedDisplay(string $tail): string {
		return 'doc-1 (A Document) -> publication '.self::PUB.", 2 file(s), window 2024-01-01T00:00:00+00:00 to open\n".$tail;

	}//end expectedDisplay()
}//end class
