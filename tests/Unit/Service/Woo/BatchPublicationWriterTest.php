<?php

/**
 * Unit tests for BatchPublicationWriter writing the official title into the attached copies.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/published-file-carries-its-facts/specs/woo-compliance/spec.md#requirement-the-published-file-carries-its-official-title-req-pff-002
 */

declare(strict_types=1);

namespace Unit\Service\Woo;

use OCA\OpenCatalogi\Service\Portal\PublicationLinker;
use OCA\OpenCatalogi\Service\Publication\EmbeddedTitleWriter;
use OCA\OpenCatalogi\Service\Publication\OfficeTitleUpdate;
use OCA\OpenCatalogi\Service\Publication\PdfTitleUpdate;
use OCA\OpenCatalogi\Service\Publication\PublishTransition;
use OCA\OpenCatalogi\Service\Woo\BatchPublicationWriter;
use OCP\App\IAppManager;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Double of OpenRegister's ObjectService: saving a publication answers its uuid.
 */
class TitleFakeObjectService {

	/** @var array<int, array<string, mixed>> */
	public array $saved = [];

	public function find(string $id, mixed ...$rest): mixed {
		return ['id' => $id, 'title' => 'Eerder gemaakte publicatie', 'status' => 'published'];
	}//end find()

	public function saveObject(array $object, mixed ...$rest): mixed {
		$this->saved[] = $object;
		return ['id' => 'pub-1'] + $object;
	}//end saveObject()
}//end class

/**
 * Double of OpenRegister's FileService: `addFile()` stores a copy of the
 * uploaded bytes and answers it, `publishFile()` is recorded in the same log.
 */
class TitleFakeFileService {

	/** @var array<int, string> The calls, in order. */
	public array $log = [];

	/** @var array<int, string> The bytes of each copy, by file id. */
	public array $copies = [];

	public function __construct(
		private TestCase $test,
	) {
	}//end __construct()

	public function addFile(mixed $objectEntity, string $fileName, mixed $content, mixed ...$rest): File {
		$id = (200 + count($this->copies));
		$this->copies[$id] = (string)stream_get_contents($content);
		$this->log[] = 'addFile ' . $fileName;
		$copy = $this->test->getMockBuilder(File::class)->getMock();
		$copy->method('getId')->willReturn($id);
		$copy->method('getName')->willReturn($fileName);
		$copy->method('getContent')->willReturnCallback(fn (): string => $this->copies[$id]);
		$copy->method('putContent')->willReturnCallback(
			function (string $data) use ($id, $fileName): void {
				$this->log[] = 'putContent ' . $fileName;
				$this->copies[$id] = $data;
			}
		);

		return $copy;
	}//end addFile()

	public function publishFile(mixed $object, string|int $file): object {
		$this->log[] = 'publishFile ' . $file;
		return new \stdClass();
	}//end publishFile()
}//end class

/**
 * @covers \OCA\OpenCatalogi\Service\Woo\BatchPublicationWriter
 * @uses \OCA\OpenCatalogi\Service\Publication\EmbeddedTitleWriter
 * @uses \OCA\OpenCatalogi\Service\Publication\PdfTitleUpdate
 * @uses \OCA\OpenCatalogi\Service\Publication\PdfSyntax
 * @uses \OCA\OpenCatalogi\Service\Publication\OfficeTitleUpdate
 * @uses \OCA\OpenCatalogi\Service\Publication\TitleNotWritten
 * @uses \OCA\OpenCatalogi\Service\Publication\EmbeddedTitle
 */
class BatchPublicationWriterTest extends TestCase {

	private TitleFakeObjectService $objects;

	private TitleFakeFileService $files;

	/** @var array<string, File> The officer's documents by path. */
	private array $documents = [];

	/** @var array<string, int> How often each original was written to. */
	private array $originalWrites = [];

	protected function setUp(): void {
		$this->objects = new TitleFakeObjectService();
		$this->files = new TitleFakeFileService($this);
		$plain = (string)file_get_contents(__DIR__ . '/../../../fixtures/pdf/plain.pdf');
		$signed = (string)file_get_contents(__DIR__ . '/../../../fixtures/pdf/signed.pdf');
		$this->documents = [
			'Woo/besluit.pdf' => $this->document('besluit.pdf', $plain),
			'Woo/ondertekend.pdf' => $this->document('ondertekend.pdf', $signed),
		];

	}//end setUp()

	/**
	 * An officer's document.
	 *
	 * @param string $name  The file name.
	 * @param string $bytes The content.
	 *
	 * @return File
	 */
	private function document(string $name, string $bytes): File {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getContent')->willReturn($bytes);
		$file->method('fopen')->willReturnCallback(
			static function () use ($bytes) {
				$stream = fopen('php://memory', 'r+');
				fwrite($stream, $bytes);
				rewind($stream);
				return $stream;
			}
		);
		$file->method('putContent')->willReturnCallback(
			function () use ($name): void {
				$this->originalWrites[$name] = (($this->originalWrites[$name] ?? 0) + 1);
			}
		);

		return $file;
	}//end document()

	/**
	 * The writer under test, with the title writer.
	 *
	 * @param bool $withTitles Whether the title writer is there.
	 *
	 * @return BatchPublicationWriter
	 */
	/** @var array<int, array{0: string, 1: string, 2: string, 3: bool}> Every publish the writer ran: id, stored state, date, as system. */
	private array $publishes = [];

	/**
	 * A PublishTransition double recording each publish.
	 *
	 * @return PublishTransition
	 */
	private function publisher(): PublishTransition {
		$publisher = $this->createMock(PublishTransition::class);
		foreach (['publish' => false, 'publishAsSystem' => true] as $method => $asSystem) {
			$publisher->method($method)->willReturnCallback(
				function (string $id, string $stored, string $publicationDate) use ($asSystem): object {
					$this->publishes[] = [$id, $stored, $publicationDate, $asSystem];
					return new \stdClass();
				}
			);
		}

		return $publisher;
	}//end publisher()

	/**
	 * REQ-PLC-003: a batch creates its publication approved, without a date, and publishes it through `publish` as the system.
	 *
	 * @return void
	 */
	public function testABatchCreatesAnApprovedPublicationAndPublishesIt(): void {
		$result = $this->writer()->publication(batch: ['title' => 'Besluit', 'caseReference' => 'Z-9'], now: '2026-11-17T10:00:00+00:00');

		$this->assertSame('pub-1', $result['id']);
		$this->assertCount(1, $this->objects->saved);
		$this->assertSame('approved', $this->objects->saved[0]['status']);
		$this->assertArrayNotHasKey('publicationDate', $this->objects->saved[0]);
		$this->assertSame([['pub-1', 'approved', '2026-11-17T10:00:00+00:00', true]], $this->publishes);
	}//end testABatchCreatesAnApprovedPublicationAndPublishesIt()

	private function writer(bool $withTitles = true): BatchPublicationWriter {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id): object => match ($id) {
				'OCA\OpenRegister\Service\ObjectService' => $this->objects,
				'OCA\OpenRegister\Service\FileService' => $this->files,
				PublishTransition::class => $this->publisher(),
				default => throw new RuntimeException('not registered: ' . $id),
			}
		);
		$folder = $this->createMock(Folder::class);
		$folder->method('get')->willReturnCallback(
			fn (string $path): File => ($this->documents[$path] ?? throw new NotFoundException($path))
		);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->with('alice')->willReturn($folder);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, mixed $parameters = []): string => vsprintf($text, (array)$parameters)
		);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturn(true);
		$titles = null;
		if ($withTitles === true) {
			$titles = new EmbeddedTitleWriter(new PdfTitleUpdate(), new OfficeTitleUpdate(), $this->createMock(LoggerInterface::class));
		}

		return new BatchPublicationWriter($root, $container, $this->createMock(PublicationLinker::class), $l10n, $apps, $titles);
	}//end writer()

	/**
	 * The batch, with these documents.
	 *
	 * @param array<int, string> $documents Document references.
	 *
	 * @return array{0: array<string, mixed>, 1: array<int, array<string, string>>}
	 */
	private static function batch(array $documents): array {
		$listings = array_map(static fn (string $document): array => ['title' => basename($document), 'document' => $document], $documents);
		return [['title' => 'Besluit parkeerregulering binnenstad 2027', 'caseReference' => 'WOO-1', 'createdBy' => 'alice'], $listings];
	}//end batch()

	public function testAttachWritesTheTitleAfterTheGate(): void {
		[$batch, $listings] = self::batch(['Woo/besluit.pdf', 'Woo/ondertekend.pdf']);
		$result = $this->writer()->publishDocuments(batch: $batch, listings: $listings, now: '2026-11-15T10:00:00+00:00', keepProgress: static fn (): null => null);

		$this->assertSame(
			['addFile besluit.pdf', 'putContent besluit.pdf', 'publishFile 200', 'addFile ondertekend.pdf', 'publishFile 201'],
			$this->files->log,
			'the title is written into the copy before that copy is published'
		);
		$plain = $this->documents['Woo/besluit.pdf']->getContent();
		$this->assertStringStartsWith($plain, $this->files->copies[200]);
		$this->assertStringContainsString('<dc:identifier>pub-1</dc:identifier>', $this->files->copies[200]);
		$this->assertStringContainsString(
			'<rdf:li xml:lang="x-default">Besluit parkeerregulering binnenstad 2027</rdf:li>',
			$this->files->copies[200]
		);
		$this->assertSame([], $this->originalWrites, 'the officer\'s own documents are never written to');

		$this->assertSame(['besluit.pdf', 'ondertekend.pdf'], array_keys($result['embeddedTitles']));
		$this->assertSame(
			['written', hash('sha256', $plain), hash('sha256', $this->files->copies[200])],
			[$result['embeddedTitles']['besluit.pdf']['status'], $result['embeddedTitles']['besluit.pdf']['hashBefore'], $result['embeddedTitles']['besluit.pdf']['hashAfter']]
		);
		$this->assertSame(['skipped', 'signed'], [$result['embeddedTitles']['ondertekend.pdf']['status'], $result['embeddedTitles']['ondertekend.pdf']['reason']]);
		$this->assertSame($this->documents['Woo/ondertekend.pdf']->getContent(), $this->files->copies[201]);
	}//end testAttachWritesTheTitleAfterTheGate()

	public function testAGateRefusalWritesNothing(): void {
		[$batch, $listings] = self::batch(['Woo/besluit.pdf', 'Woo/kwijt.pdf']);

		try {
			$this->writer()->publishDocuments(batch: $batch, listings: $listings, now: '2026-11-15T10:00:00+00:00', keepProgress: static fn (): null => null);
			$this->fail('A batch with a missing document was published.');
		} catch (RuntimeException $e) {
			$this->assertSame('Publishing is blocked: these documents cannot be found: kwijt.pdf', $e->getMessage());
		}

		$this->assertSame([], $this->files->log);
		$this->assertSame([], $this->objects->saved);
		$this->assertSame([], $this->originalWrites);
	}//end testAGateRefusalWritesNothing()

	public function testAContinuedPublishKeepsTheEarlierOutcomesAndAFailureStoresThem(): void {
		[$batch, $listings] = self::batch(['Woo/besluit.pdf', 'Woo/ondertekend.pdf']);
		$earlier = ['status' => 'written', 'hashBefore' => 'a', 'hashAfter' => 'b'];
		$batch['wooPublication'] = ['publication' => 'pub-0', 'attached' => ['besluit.pdf'], 'embeddedTitles' => ['besluit.pdf' => $earlier]];

		$result = $this->writer()->publishDocuments(batch: $batch, listings: $listings, now: '2026-11-15T10:00:00+00:00', keepProgress: static fn (): null => null);
		$this->assertSame(['addFile ondertekend.pdf', 'publishFile 200'], $this->files->log);
		$this->assertSame($earlier, $result['embeddedTitles']['besluit.pdf']);
		$this->assertSame('skipped', $result['embeddedTitles']['ondertekend.pdf']['status']);

		$this->files = new class($this) extends TitleFakeFileService {
			public function publishFile(mixed $object, string|int $file): object {
				throw new RuntimeException('share refused');
			}//end publishFile()
		};
		$kept = null;
		$batch['wooPublication']['attached'] = [];
		try {
			$this->writer()->publishDocuments(batch: $batch, listings: $listings, now: '2026-11-15T10:00:00+00:00', keepProgress: static function (array $progress) use (&$kept): void {
				$kept = $progress;
			});
			$this->fail('A refused share was not reported.');
		} catch (RuntimeException $e) {
			$this->assertSame('Publishing stopped at besluit.pdf. Publish again to continue.', $e->getMessage());
		}

		$this->assertSame(['besluit.pdf' => $earlier], $kept['wooPublication']['embeddedTitles']);
	}//end testAContinuedPublishKeepsTheEarlierOutcomesAndAFailureStoresThem()

	public function testAnEarlierPublicationGivesItsOwnTitleAndNoWriterRecordsNothing(): void {
		[$batch, $listings] = self::batch(['Woo/besluit.pdf']);
		$batch['wooPublication'] = ['publication' => 'pub-0'];

		$result = $this->writer()->publishDocuments(batch: $batch, listings: $listings, now: '2026-11-15T10:00:00+00:00', keepProgress: static fn (): null => null);
		$this->assertStringContainsString('<rdf:li xml:lang="x-default">Eerder gemaakte publicatie</rdf:li>', $this->files->copies[200]);
		$this->assertStringContainsString('<dc:identifier>pub-0</dc:identifier>', $this->files->copies[200]);
		$this->assertSame('written', $result['embeddedTitles']['besluit.pdf']['status']);

		$this->files = new TitleFakeFileService($this);
		$result = $this->writer(withTitles: false)->publishDocuments(batch: $batch, listings: $listings, now: '2026-11-15T10:00:00+00:00', keepProgress: static fn (): null => null);
		$this->assertSame([], $result['embeddedTitles']);
		$this->assertSame(['addFile besluit.pdf', 'publishFile 200'], $this->files->log);
	}//end testAnEarlierPublicationGivesItsOwnTitleAndNoWriterRecordsNothing()
}//end class
