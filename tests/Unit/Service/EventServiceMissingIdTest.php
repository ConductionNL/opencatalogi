<?php

/**
 * An object without an id in its @self envelope is skipped, not fatal for the batch.
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
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\EventService;
use OCA\OpenCatalogi\Service\SettingsService;
use OCA\OpenRegister\Files\Db\FileMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The attachment step of an update event over a batch with an id-less object.
 *
 * @covers \OCA\OpenCatalogi\Service\EventService
 * @uses   \OCA\OpenCatalogi\Service\ChecksPublicationWindow
 */
class EventServiceMissingIdTest extends TestCase {

	/**
	 * The id-less object is skipped with a warning; the next object is still processed.
	 *
	 * @return void
	 */
	public function testAnObjectWithoutAnIdIsSkippedAndLogged(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getPublishingOptions')->willReturn(['auto_publish_objects' => false, 'auto_publish_attachments' => true]);
		$logged = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			static function (string $message, array $context = []) use (&$logged): void {
				$logged[] = [$message, $context];
			}
		);
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturn(new ObjectEntity());
		$files = $this->createMock(FileMapper::class);
		$files->method('getFilesForObject')->willReturn([]);
		$fileService = $this->createMock(FileService::class);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => match ($id) {
				'OCA\OpenRegister\Files\Db\FileMapper' => $files,
				'OCA\OpenRegister\Service\FileService' => $fileService,
				default => $objects,
			}
		);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getInstalledApps')->willReturn(['opencatalogi', 'openregister']);

		$service = new EventService($container, $apps, $settings, $logger);
		$live = '2020-01-01T00:00:00+00:00';
		$result = $service->handleObjectUpdateEvents([
			['title' => 'no id', 'publicationDate' => $live, '@self' => ['register' => 5]],
			['title' => 'has id', 'publicationDate' => $live, '@self' => ['id' => 9]],
		]);

		$this->assertSame(2, $result['processed']);
		$this->assertSame(['attachments_processed'], $result['details'][1]['actions']);
		$this->assertSame([], $result['details'][0]['actions']);
		$this->assertSame([['OpenCatalogi: skipped the attachments of an object without an id', ['object' => 'unknown']]], $logged);

	}//end testAnObjectWithoutAnIdIsSkippedAndLogged()
}//end class
