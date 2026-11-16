<?php

/**
 * Unit tests for PublicationDocumentText: the extracted text of a
 * publication's files, joined in order and capped, and empty when it cannot
 * be read (woo-metadata-suggestions REQ-WMS-002).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-hermiq-may-add-suggestions-never-replace-them-req-wms-002
 */

declare(strict_types=1);

namespace Unit\Service\Woo;

use OCA\OpenCatalogi\Service\Woo\PublicationDocumentText;
use OCP\App\IAppManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for PublicationDocumentText.
 */
#[CoversClass(PublicationDocumentText::class)]
final class PublicationDocumentTextTest extends TestCase {

	/**
	 * A file node double with an id.
	 *
	 * @param int $id The file id.
	 *
	 * @return object The file.
	 */
	private function file(int $id): object {
		return new class($id) {
			public function __construct(private int $id) {
			}

			public function getId(): int {
				return $this->id;
			}
		};
	}//end file()

	/**
	 * A chunk store double with text per file id; a file id of 99 throws.
	 *
	 * @param array<int, array<int, string>> $text Chunks per file id.
	 *
	 * @return object The store.
	 */
	private function chunks(array $text): object {
		return new class($text) {
			/** @var array<int, array{0: string, 1: int}> */
			public array $asked = [];

			public function __construct(private array $text) {
			}

			public function findBySource(string $sourceType, int $sourceId): array {
				$this->asked[] = [$sourceType, $sourceId];
				if ($sourceId === 99) {
					throw new \RuntimeException('broken');
				}

				return array_map(
					static fn (string $content): object => new class($content) {
						public function __construct(private string $content) {
						}

						public function getTextContent(): ?string {
							return $this->content;
						}
					},
					$this->text[$sourceId] ?? []
				);
			}
		};
	}//end chunks()

	/**
	 * The reader over the given files and chunk store.
	 *
	 * @param bool $installed Whether OpenRegister is installed.
	 * @param array<int, object>|\Throwable $files The files, or what the file reader throws.
	 * @param object $chunks The chunk store.
	 *
	 * @return PublicationDocumentText
	 */
	private function reader(bool $installed, array|\Throwable $files, object $chunks): PublicationDocumentText {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturnCallback(static fn (string $app): bool => $app === 'openregister' && $installed);
		$reader = new class($files) {
			/** @var array<int, string> */
			public array $asked = [];

			public function __construct(private array|\Throwable $files) {
			}

			public function getObjectFiles(string $objectUuid): array {
				$this->asked[] = $objectUuid;
				if ($this->files instanceof \Throwable) {
					throw $this->files;
				}

				return $this->files;
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => match ($id) {
				PublicationDocumentText::FILE_READER => $reader,
				PublicationDocumentText::CHUNKS => $chunks,
			}
		);

		return new PublicationDocumentText(appManager: $apps, container: $container, logger: $this->createMock(LoggerInterface::class));
	}//end reader()

	public function testTheTextOfEveryFileIsJoinedInFileOrder(): void {
		$chunks = $this->chunks([1 => ['Eerste deel.', ' ', 'Tweede deel.'], 2 => [], 3 => ['Bijlage.']]);
		$reader = $this->reader(installed: true, files: [$this->file(1), $this->file(2), $this->file(99), $this->file(3)], chunks: $chunks);

		$this->assertSame("Eerste deel.\nTweede deel.\n\nBijlage.", $reader->forPublication(publicationId: 'pub-1'));
		$this->assertSame([['file', 1], ['file', 2], ['file', 99], ['file', 3]], $chunks->asked);
	}

	public function testTheTextIsCappedAtTheMaximumLength(): void {
		$long = str_repeat('é', PublicationDocumentText::MAX_LENGTH + 10);
		$reader = $this->reader(installed: true, files: [$this->file(1)], chunks: $this->chunks([1 => [$long]]));

		$this->assertSame(PublicationDocumentText::MAX_LENGTH, mb_strlen($reader->forPublication(publicationId: 'pub-1')));
	}

	public function testNoOpenRegisterOrUnreadableFilesGiveNoText(): void {
		$chunks = $this->chunks([1 => ['tekst']]);

		$this->assertSame('', $this->reader(installed: false, files: [$this->file(1)], chunks: $chunks)->forPublication(publicationId: 'pub-1'));
		$this->assertSame('', $this->reader(installed: true, files: new \RuntimeException('gone'), chunks: $chunks)->forPublication(publicationId: 'pub-1'));
		$this->assertSame([], $chunks->asked);
	}
}//end class
