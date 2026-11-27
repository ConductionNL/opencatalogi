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

use OCA\OpenCatalogi\Listener\PublisherStampListener;
use OCA\OpenCatalogi\Service\Publication\PublisherFileStamp;
use OCA\OpenRegister\Event\FileAddedEvent;
use OCP\EventDispatcher\Event;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for PublisherStampListener.
 *
 * @covers \OCA\OpenCatalogi\Listener\PublisherStampListener
 */
class PublisherStampListenerTest extends TestCase {

	/**
	 * The stamp double.
	 *
	 * @var PublisherFileStamp&MockObject
	 */
	private PublisherFileStamp $stamp;

	/**
	 * What the container was asked for.
	 *
	 * @var array<int, string>
	 */
	private array $resolved = [];

	/**
	 * The object service double's answer, or the exception it throws.
	 *
	 * @var mixed
	 */
	private mixed $found = ['id' => 'pub-1', 'organization' => 'org-1'];

	/**
	 * The warnings logged.
	 *
	 * @var array<int, string>
	 */
	private array $warnings = [];

	/**
	 * The object service double.
	 *
	 * @var object
	 */
	private object $objects;

	/**
	 * The file service double.
	 *
	 * @var object
	 */
	private object $files;

	/**
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		if (class_exists(class: FileAddedEvent::class) === false) {
			$this->markTestSkipped(message: 'OpenRegister\'s FileAddedEvent is not on the autoload path.');
		}

		$this->stamp = $this->createMock(originalClassName: PublisherFileStamp::class);
		$found = &$this->found;
		$this->objects = new class ($found) {

			/**
			 * What find() was asked.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $asked = [];

			/**
			 * Constructor.
			 *
			 * @param mixed $found The answer, or a Throwable to throw.
			 */
			public function __construct(private mixed &$found) {
			}//end __construct()

			/**
			 * Mirrors OpenRegister ObjectService::find().
			 *
			 * @param string $id            The uuid.
			 * @param mixed  $options       The find options.
			 * @param bool   $_rbac         Whether RBAC applies.
			 * @param bool   $_multitenancy Whether multitenancy applies.
			 *
			 * @return mixed
			 */
			public function find(string $id, mixed $options, bool $_rbac = true, bool $_multitenancy = true): mixed {
				$this->asked[] = ['id' => $id, 'register' => $options->register, 'schema' => $options->schema, 'rbac' => $_rbac];
				if ($this->found instanceof \Throwable) {
					throw $this->found;
				}

				return $this->found;
			}//end find()
		};
		$this->files = new \stdClass();
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
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id): object {
				$this->resolved[] = $id;
				return match ($id) {
					'OCA\OpenRegister\Service\ObjectService' => $this->objects,
					'OCA\OpenRegister\Service\FileService' => $this->files,
				};
			}
		);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->warnings[] = strtr($message, ['{file}' => (string)$context['file'], '{id}' => $context['id'], '{error}' => $context['error']]);
			}
		);

		return new PublisherStampListener($config, $this->stamp, $container, $logger);
	}//end listener()

	/**
	 * OpenRegister's event for a file added to an object in a register and schema.
	 *
	 * @param int|string $register The register id.
	 * @param int|string $schema   The schema id.
	 *
	 * @return FileAddedEvent
	 */
	private static function added(int|string $register = 5, int|string $schema = 7): FileAddedEvent {
		return new FileAddedEvent(objectUuid: 'pub-1', fileId: 42, data: ['fileName' => 'besluit.pdf', 'register' => $register, 'schema' => $schema]);
	}//end added()

	/**
	 * A file added to a publication is stamped, and the RSIN stored on the publication.
	 *
	 * @spec openspec/changes/publications-name-their-responsible-organisation/tasks.md#task-2.2d
	 *
	 * @return void
	 */
	public function testAFileAddedToAPublicationIsStamped(): void {
		$labels = ['rsin:002220647', 'publisherName:Gemeente Zuiddrecht'];
		$this->stamp->expects($this->once())->method('stamp')
			->with($this->files, $this->found, 42)
			->willReturn($labels);
		$this->stamp->expects($this->once())->method('record')
			->with($this->objects, 'pub-1', $labels, '5', '7');

		$this->listener()->handle(self::added());

		$this->assertSame(expected: [['id' => 'pub-1', 'register' => '5', 'schema' => '7', 'rbac' => false]], actual: $this->objects->asked);
		$this->assertSame(expected: PublisherStampListener::FILE_ADDED_EVENT, actual: FileAddedEvent::class);
	}//end testAFileAddedToAPublicationIsStamped()

	/**
	 * Without labels written, nothing is stored on the publication.
	 *
	 * @spec openspec/changes/publications-name-their-responsible-organisation/tasks.md#task-2.2d
	 *
	 * @return void
	 */
	public function testWithoutLabelsNothingIsRecorded(): void {
		$this->stamp->method('stamp')->willReturn(null);
		$this->stamp->expects($this->never())->method('record');

		$this->listener()->handle(self::added());
	}//end testWithoutLabelsNothingIsRecorded()

	/**
	 * A file on another object, or another event, is left alone.
	 *
	 * @spec openspec/changes/publications-name-their-responsible-organisation/tasks.md#task-2.2d
	 *
	 * @return void
	 */
	public function testAFileOnAnotherObjectIsLeftAlone(): void {
		$this->stamp->expects($this->never())->method('stamp');

		$this->listener()->handle(self::added(schema: 8));
		$this->listener()->handle(self::added(register: 6));
		$this->listener()->handle(new FileAddedEvent(objectUuid: '', fileId: 42, data: ['register' => 5, 'schema' => 7]));
		$this->listener()->handle(new FileAddedEvent(objectUuid: 'pub-1', fileId: 0, data: ['register' => 5, 'schema' => 7]));
		$this->listener()->handle(new Event());

		$this->assertSame(expected: [], actual: $this->resolved);
	}//end testAFileOnAnotherObjectIsLeftAlone()

	/**
	 * A failure is logged and never undoes the upload.
	 *
	 * @spec openspec/changes/publications-name-their-responsible-organisation/tasks.md#task-2.2d
	 *
	 * @return void
	 */
	public function testAFailureIsLoggedAndDoesNotThrow(): void {
		$this->found = new \RuntimeException('database gone');
		$this->stamp->expects($this->never())->method('stamp');

		$this->listener()->handle(self::added());

		$this->assertSame(
			expected: ['[PublisherStampListener] The publisher was not stamped on file 42 of publication pub-1: database gone'],
			actual: $this->warnings
		);
	}//end testAFailureIsLoggedAndDoesNotThrow()
}//end class
