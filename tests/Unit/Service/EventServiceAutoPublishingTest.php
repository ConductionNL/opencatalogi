<?php

/**
 * The auto-publishing scenarios of the auto-publishing change, end to end through EventService.
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
 * @spec openspec/changes/auto-publishing/specs/auto-publishing/spec.md
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\EventService;
use OCA\OpenCatalogi\Service\SettingsService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Files\Db\FileMapper;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenCatalogi\Service\EventService
 */
final class EventServiceAutoPublishingTest extends TestCase {

	/**
	 * The paths createShareLink() was asked for, in order.
	 *
	 * @var array<int, string>
	 */
	private array $sharedPaths = [];

	/**
	 * The service over OpenRegister doubles: the object service finds the object,
	 * the file mapper lists the given files, and the file service shares them.
	 *
	 * @param array<int, array<string, mixed>> $files   The files the mapper lists.
	 * @param array<string, mixed>             $options The publishing options.
	 * @param ObjectService|null               $objects An object service to use instead.
	 *
	 * @return EventService
	 */
	private function service(array $files, array $options, ?ObjectService $objects = null): EventService {
		$fileService = $this->createMock(FileService::class);
		$fileService->method('createShareLink')->willReturnCallback(
			function (string $path): string {
				$this->sharedPaths[] = $path;
				return 'https://example.org/s/' . count($this->sharedPaths);
			}
		);

		$entity = new ObjectEntity();
		$fileMapper = $this->createMock(FileMapper::class);
		$fileMapper->expects($this->atMost(1))->method('getFilesForObject')->with($entity)->willReturn($files);

		if ($objects === null) {
			$objects = $this->objectServiceFinding(entity: $entity);
		}

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

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getPublishingOptions')->willReturn($options);
		$settings->method('getSettings')->willReturn(['configuration' => ['catalog_register' => '1', 'catalog_schema' => '2']]);

		return new EventService($container, $apps, $settings, $this->createMock(LoggerInterface::class));
	}//end service()

	/**
	 * An object service that only finds the object: file data never comes from it (APB-010).
	 *
	 * @param ObjectEntity $entity The object it finds.
	 *
	 * @return ObjectService&MockObject
	 */
	private function objectServiceFinding(ObjectEntity $entity): ObjectService&MockObject {
		$objects = $this->createMock(ObjectService::class);
		$objects->expects($this->once())->method('find')->with('obj-uuid')->willReturn($entity);
		return $objects;
	}//end objectServiceFinding()

	/**
	 * A published object of the catalog's register and schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function publishedObject(): array {
		return [
			'title' => 'Besluit',
			'publicationDate' => '2024-01-15T10:00:00+00:00',
			'@self' => ['id' => 'obj-uuid', 'uuid' => 'obj-uuid', 'register' => 3, 'schema' => 7],
		];
	}//end publishedObject()

	/**
	 * APB-004: the FileMapper path is passed to the file service with the /OpenRegister/ prefix.
	 *
	 * @return void
	 */
	public function testTheShareLinkPathGetsTheOpenRegisterPrefix(): void {
		$service = $this->service(
			files: [['name' => 'document.pdf', 'path' => 'files/object-uuid/document.pdf', 'share_token' => null]],
			options: ['auto_publish_objects' => false, 'auto_publish_attachments' => true]
		);

		$result = $service->handleObjectUpdateEvents([self::publishedObject()]);

		$this->assertSame(['/OpenRegister/files/object-uuid/document.pdf'], $this->sharedPaths);
		$this->assertSame(1, $result['attachmentsPublished']);
	}//end testTheShareLinkPathGetsTheOpenRegisterPrefix()

	/**
	 * APB-017: an update of a published object shares both unshared files it has.
	 *
	 * @return void
	 */
	public function testAnUpdateSharesTheRealFileList(): void {
		$service = $this->service(
			files: [
				['name' => 'a.pdf', 'path' => 'files/obj-uuid/a.pdf', 'share_token' => null],
				['name' => 'b.pdf', 'path' => 'files/obj-uuid/b.pdf', 'share_token' => ''],
			],
			options: ['auto_publish_objects' => false, 'auto_publish_attachments' => true]
		);

		$object = self::publishedObject();
		// What the update listener hands over: no file list of its own.
		$object['@self']['files'] = [];

		$result = $service->handleObjectUpdateEvents([$object]);

		$this->assertSame(2, $result['attachmentsPublished']);
		$this->assertSame(['/OpenRegister/files/obj-uuid/a.pdf', '/OpenRegister/files/obj-uuid/b.pdf'], $this->sharedPaths);
		$this->assertSame(['attachments_processed'], $result['details'][0]['actions']);
	}//end testAnUpdateSharesTheRealFileList()

	/**
	 * APB-011: a file that already has a share token is skipped.
	 *
	 * @return void
	 */
	public function testAlreadySharedFilesAreSkipped(): void {
		$service = $this->service(
			files: [
				['name' => 'a.pdf', 'path' => 'files/obj-uuid/a.pdf', 'share_token' => 'abc123'],
				['name' => 'b.pdf', 'path' => 'files/obj-uuid/b.pdf', 'share_token' => null],
			],
			options: ['auto_publish_objects' => false, 'auto_publish_attachments' => true]
		);

		$result = $service->handleObjectUpdateEvents([self::publishedObject()]);

		$this->assertSame(['/OpenRegister/files/obj-uuid/b.pdf'], $this->sharedPaths);
		$this->assertSame(1, $result['attachmentsPublished']);
	}//end testAlreadySharedFilesAreSkipped()

	/**
	 * APB-012: one object with one unshared attachment yields the full structured result.
	 *
	 * @return void
	 */
	public function testTheResultIsStructured(): void {
		$service = $this->service(
			files: [['name' => 'a.pdf', 'path' => 'files/obj-uuid/a.pdf', 'share_token' => null]],
			options: ['auto_publish_objects' => false, 'auto_publish_attachments' => true]
		);

		$this->assertSame(
			[
				'processed' => 1,
				'published' => 0,
				'attachmentsPublished' => 1,
				'errors' => [],
				'details' => [['objectId' => 'obj-uuid', 'actions' => ['attachments_processed'], 'errors' => []]],
			],
			$service->handleObjectUpdateEvents([self::publishedObject()])
		);
	}//end testTheResultIsStructured()

	/**
	 * APB-014: a failing publish is recorded, and the result keeps its structure.
	 *
	 * @return void
	 */
	public function testAFailingPublishIsRecordedInTheResult(): void {
		$catalog = new class {
			/**
			 * The catalog's data.
			 *
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return ['registers' => [3], 'schemas' => [7]];
			}//end jsonSerialize()
		};

		$objects = $this->createMock(ObjectService::class);
		$objects->method('searchObjects')->willReturn([$catalog]);
		$objects->expects($this->once())->method('saveObject')->willThrowException(new RuntimeException('database is down'));

		$service = $this->service(files: [], options: ['auto_publish_objects' => true, 'auto_publish_attachments' => false], objects: $objects);

		$object = self::publishedObject();
		unset($object['publicationDate']);
		$result = $service->handleObjectCreateEvents([$object]);

		$this->assertSame(1, $result['processed']);
		$this->assertSame(0, $result['published']);
		$this->assertSame(0, $result['attachmentsPublished']);
		$this->assertSame([], $result['errors']);
		$this->assertSame(['Failed to auto-publish object: database is down'], $result['details'][0]['errors']);
		$this->assertSame([], $this->sharedPaths);
	}//end testAFailingPublishIsRecordedInTheResult()
}//end class
