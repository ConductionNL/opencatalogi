<?php

/**
 * Every attachment publish path queues the reading job (task 2.1).
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @link https://www.OpenCatalogi.nl
 * @spec openspec/changes/scanned-attachments-become-searchable/specs/search/spec.md#requirement-a-published-scan-is-read-by-ocr-so-search-finds-its-words-req-socr-001
 */

declare(strict_types=1);

namespace Unit\Service\AttachmentReading;

use OCA\OpenCatalogi\Service\AttachmentReading\AttachmentReadingQueue;
use OCA\OpenCatalogi\Service\EventService;
use OCA\OpenCatalogi\Service\Portal\PublicationLinker;
use OCA\OpenCatalogi\Service\SettingsService;
use OCA\OpenCatalogi\Service\Woo\BatchPublicationWriter;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Files\Db\FileMapper;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * Double of OpenRegister's FileService for the Woo batch attach.
 */
class CallersFakeFileService {

	/** @var list<mixed> */
	public array $published = [];

	public function addFile(mixed ...$arguments): object {
		return new class {
			public function getId(): int {
				return 501;
			}
		};
	}//end addFile()

	public function publishFile(mixed $object, int $file): void {
		$this->published[] = $file;
	}//end publishFile()
}//end class

/**
 * Tests that each publish path has the reading job as its consequence.
 *
 * @covers \OCA\OpenCatalogi\Service\EventService
 * @covers \OCA\OpenCatalogi\Service\Woo\BatchPublicationWriter
 */
class AttachmentReadingCallersTest extends TestCase {

	/** @var list<array<string, mixed>> */
	private array $queued = [];

	/**
	 * A queue double that records what was queued.
	 *
	 * @return AttachmentReadingQueue
	 */
	private function queue(): AttachmentReadingQueue {
		$queue = $this->createMock(AttachmentReadingQueue::class);
		$queue->method('queue')->willReturnCallback(
			function (int $fileId, string $publicationId): bool {
				$this->queued[] = ['fileId' => $fileId, 'publication' => $publicationId, 'force' => false];
				return true;
			}
		);

		return $queue;
	}//end queue()

	public function testAutoPublishingAnAttachmentQueuesItsReading(): void {
		$files = [
			['fileid' => 70, 'name' => 'scan.pdf', 'path' => 'files/scan.pdf', 'share_token' => ''],
			['fileid' => 71, 'name' => 'shared.pdf', 'path' => 'files/shared.pdf', 'share_token' => 'already'],
			['fileid' => 72, 'name' => 'broken.pdf', 'path' => 'files/broken.pdf', 'share_token' => ''],
		];

		$fileService = $this->createMock(FileService::class);
		$fileService->method('createShareLink')->willReturnCallback(
			static fn (string $path): string => str_contains($path, 'broken') ? '' : 'https://example.com/s/abc'
		);
		$fileMapper = $this->createMock(FileMapper::class);
		$fileMapper->method('getFilesForObject')->willReturn($files);
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturn(new ObjectEntity());

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => match ($id) {
				'OCA\OpenRegister\Service\FileService' => $fileService,
				'OCA\OpenRegister\Files\Db\FileMapper' => $fileMapper,
				default => $objects,
			}
		);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getInstalledApps')->willReturn(['openregister']);

		$service = new EventService($container, $apps, $this->createMock(SettingsService::class), new NullLogger(), $this->queue());
		$publish = new ReflectionMethod(EventService::class, 'publishObjectAttachments');
		$result = $publish->invoke($service, ['@self' => ['uuid' => 'pub-uuid', 'id' => 9]]);

		$this->assertSame(1, $result['published']);
		$this->assertSame(
			[['fileId' => 70, 'publication' => 'pub-uuid', 'force' => false]],
			$this->queued,
			'only the file this path shared is queued: not the one already shared, not the one that failed'
		);
	}//end testAutoPublishingAnAttachmentQueuesItsReading()

	public function testAttachingAWooDocumentQueuesTheReadingOfThePublishedCopy(): void {
		$fileService = new CallersFakeFileService();
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => $fileService
		);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturn(true);

		$writer = new BatchPublicationWriter(
			$this->createMock(IRootFolder::class),
			$container,
			$this->createMock(PublicationLinker::class),
			$this->createMock(IL10N::class),
			$apps,
			$this->queue()
		);

		$document = $this->createMock(File::class);
		$document->method('getName')->willReturn('besluit-gelakt.pdf');
		$document->method('fopen')->willReturn(fopen('php://memory', 'r'));

		$writer->attach(publication: ['id' => 'pub-woo', 'entity' => 'pub-woo'], file: $document);

		$this->assertSame([501], $fileService->published);
		$this->assertSame([['fileId' => 501, 'publication' => 'pub-woo', 'force' => false]], $this->queued, 'the published copy is read, never the source file');
	}//end testAttachingAWooDocumentQueuesTheReadingOfThePublishedCopy()

	public function testWithoutAQueueThePublishStillSucceeds(): void {
		$fileService = new CallersFakeFileService();
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($fileService);

		$writer = new BatchPublicationWriter(
			$this->createMock(IRootFolder::class),
			$container,
			$this->createMock(PublicationLinker::class),
			$this->createMock(IL10N::class)
		);
		$document = $this->createMock(File::class);
		$document->method('getName')->willReturn('besluit.pdf');
		$document->method('fopen')->willReturn(fopen('php://memory', 'r'));

		$writer->attach(publication: ['id' => 'pub-woo', 'entity' => 'pub-woo'], file: $document);

		$this->assertSame([501], $fileService->published);
	}//end testWithoutAQueueThePublishStillSucceeds()
}//end class
