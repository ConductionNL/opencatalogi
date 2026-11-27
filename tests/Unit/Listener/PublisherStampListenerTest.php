<?php

/**
 * Unit tests for PublisherStampListener.
 *
 * Driven by OpenRegister's real FileAddedEvent.
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
 * @spec openspec/changes/publications-name-their-responsible-organisation/tasks.md#task-2.2d
 */

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenCatalogi\Contract\PublisherFileStampInterface;
use OCA\OpenCatalogi\Listener\PublisherStampListener;
use OCA\OpenRegister\Event\FileAddedEvent;
use OCP\EventDispatcher\Event;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PublisherStampListener.
 *
 * @covers \OCA\OpenCatalogi\Listener\PublisherStampListener
 */
class PublisherStampListenerTest extends TestCase {

	/**
	 * What the stamp double was asked.
	 *
	 * @var array<int, array{0: string, 1: int, 2: string, 3: string}>
	 */
	private array $stamped = [];

	/**
	 * Skip without OpenRegister's event class.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		if (class_exists(class: FileAddedEvent::class) === false) {
			$this->markTestSkipped(message: 'OpenRegister\'s FileAddedEvent is not on the autoload path.');
		}
	}//end setUp()

	/**
	 * The listener under test, with the publication in register 5, schema 7.
	 *
	 * @return PublisherStampListener
	 */
	private function listener(): PublisherStampListener {
		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				'publication_register' => '5',
				'publication_schema' => '7',
				default => $default,
			}
		);
		$stamp = $this->createMock(originalClassName: PublisherFileStampInterface::class);
		$stamp->method('stampUploaded')->willReturnCallback(
			function (string $publicationId, int $fileId, string $register, string $schema): void {
				$this->stamped[] = [$publicationId, $fileId, $register, $schema];
			}
		);

		return new PublisherStampListener($config, $stamp);
	}//end listener()

	/**
	 * OpenRegister's event for a file added to an object.
	 *
	 * @param string     $uuid     The object.
	 * @param int        $fileId   The file.
	 * @param int|string $register The register id.
	 * @param int|string $schema   The schema id.
	 *
	 * @return FileAddedEvent
	 */
	private static function added(string $uuid = 'pub-1', int $fileId = 42, int|string $register = 5, int|string $schema = 7): FileAddedEvent {
		return new FileAddedEvent(objectUuid: $uuid, fileId: $fileId, data: ['fileName' => 'besluit.pdf', 'register' => $register, 'schema' => $schema]);
	}//end added()

	/**
	 * A file added to a publication is handed to the stamp.
	 *
	 * @spec openspec/changes/publications-name-their-responsible-organisation/tasks.md#task-2.2d
	 *
	 * @return void
	 */
	public function testAFileAddedToAPublicationIsStamped(): void {
		$this->listener()->handle(self::added());

		$this->assertSame(expected: [['pub-1', 42, '5', '7']], actual: $this->stamped);
		$this->assertSame(expected: PublisherStampListener::FILE_ADDED_EVENT, actual: FileAddedEvent::class);
	}//end testAFileAddedToAPublicationIsStamped()

	/**
	 * A file on another object, an event without object or file, or another event, is left alone.
	 *
	 * @spec openspec/changes/publications-name-their-responsible-organisation/tasks.md#task-2.2d
	 *
	 * @return void
	 */
	public function testAFileOnAnotherObjectIsLeftAlone(): void {
		$listener = $this->listener();
		$listener->handle(self::added(schema: 8));
		$listener->handle(self::added(register: 6));
		$listener->handle(self::added(schema: ''));
		$listener->handle(self::added(uuid: ''));
		$listener->handle(self::added(fileId: 0));
		$listener->handle(new Event());

		$this->assertSame(expected: [], actual: $this->stamped);
	}//end testAFileOnAnotherObjectIsLeftAlone()
}//end class
