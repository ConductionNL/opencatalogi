<?php

/**
 * Tests for the listener that depublishes a harvested publication its source ended.
 *
 * Built on the real ObjectUpdatedEvent with an old and a new object, so a
 * wrong accessor fails here and not on every object update in production.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenCatalogi.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/capability-rows-close-gaps/tasks.md
 */

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenCatalogi\Listener\SourceAbsenceListener;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A stand-in for OpenRegister's ObjectService that records each save.
 */
class RecordingObjectService {

	/** @var array<int, array<string, mixed>> */
	public array $saves = [];

	public ?\Throwable $failWith = null;

	public function saveObject(array $object, ObjectSaveOptions $options, bool $_rbac, bool $_multitenancy): array {
		if ($this->failWith !== null) {
			throw $this->failWith;
		}

		$this->saves[] = ['object' => $object, 'register' => $options->target->register, 'schema' => $options->target->schema, 'uuid' => $options->uuid, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
		return $object;
	}
}

/**
 * @covers \OCA\OpenCatalogi\Listener\SourceAbsenceListener
 * @uses \OCA\OpenCatalogi\Service\Publication\SourceAbsence
 */
class SourceAbsenceListenerTest extends TestCase {

	private MockObject&IAppConfig $config;

	private MockObject&LoggerInterface $logger;

	private RecordingObjectService $objects;

	private SourceAbsenceListener $listener;

	protected function setUp(): void {
		parent::setUp();
		$this->config = $this->createMock(IAppConfig::class);
		$this->config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				'publication_register' => '7',
				'publication_schema' => '12',
				default => $default,
			}
		);
		$this->objects = new RecordingObjectService();
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($this->objects);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->listener = new SourceAbsenceListener(config: $this->config, container: $container, logger: $this->logger);
	}

	/**
	 * @param array<string, mixed> $properties
	 */
	private function object(array $properties, string $schema = '12'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('pub-1');
		$entity->setRegister('7');
		$entity->setSchema($schema);
		$entity->setObject($properties);
		return $entity;
	}

	/** markEnded: the publication is saved with its depublication date, outside RBAC. */
	public function testAPublicationItsSourceEndedIsDepublished(): void {
		$old = $this->object(['title' => 'Besluit', 'publicationDate' => '2026-01-01T00:00:00+00:00']);
		$new = $this->object(['title' => 'Besluit', 'publicationDate' => '2026-01-01T00:00:00+00:00', 'absentAtSource' => true, 'sourceEndedAt' => '2026-11-14T03:00:00+00:00']);

		$this->listener->handle(new ObjectUpdatedEvent($new, $old));

		$this->assertSame(
			[[
				'object' => ['id' => 'pub-1', 'title' => 'Besluit', 'publicationDate' => '2026-01-01T00:00:00+00:00', 'absentAtSource' => true, 'sourceEndedAt' => '2026-11-14T03:00:00+00:00', 'depublicationDate' => '2026-11-14T03:00:00+00:00'],
				'register' => '7',
				'schema' => '12',
				'uuid' => 'pub-1',
				'rbac' => false,
				'multitenancy' => false,
			]],
			$this->objects->saves
		);
	}

	/** keepAndFlag: nothing is saved; the publication stays up and is flagged. */
	public function testAFlaggedPublicationIsKept(): void {
		$this->logger->expects($this->once())->method('info')->with($this->stringContains('kept and flagged for an editor'));

		$this->listener->handle(new ObjectUpdatedEvent(
			$this->object(['title' => 'Besluit', 'absentAtSource' => true, 'sourceAbsentSince' => '2026-11-14T03:00:00+00:00']),
			$this->object(['title' => 'Besluit'])
		));

		$this->assertSame([], $this->objects->saves);
	}

	/** The save the listener makes itself carries the old end date and asks for nothing more. */
	public function testItsOwnSaveDoesNotLoop(): void {
		$ended = ['absentAtSource' => true, 'sourceEndedAt' => '2026-11-14T03:00:00+00:00'];

		$this->listener->handle(new ObjectUpdatedEvent($this->object($ended + ['depublicationDate' => '2026-11-14T03:00:00+00:00']), $this->object($ended)));

		$this->assertSame([], $this->objects->saves);
	}

	/** Another schema, another event or an unconfigured app is left alone. */
	public function testOnlyPublicationUpdatesAreHandled(): void {
		$ended = ['absentAtSource' => true, 'sourceEndedAt' => '2026-11-14T03:00:00+00:00'];

		$this->listener->handle(new ObjectUpdatedEvent($this->object($ended, '99'), $this->object([], '99')));
		$this->listener->handle(new ObjectCreatedEvent($this->object($ended)));

		$unconfigured = $this->createMock(IAppConfig::class);
		$unconfigured->method('getValueString')->willReturn('');
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');
		(new SourceAbsenceListener(config: $unconfigured, container: $container, logger: $this->logger))
			->handle(new ObjectUpdatedEvent($this->object($ended), $this->object([])));

		$this->assertSame([], $this->objects->saves);
	}

	/** A failing save is logged, never thrown into the synchronisation's save. */
	public function testAFailingSaveIsLoggedNotThrown(): void {
		$this->objects->failWith = new RuntimeException('locked');
		$this->logger->expects($this->once())->method('error')->with('[SourceAbsenceListener] Could not depublish publication pub-1: locked');

		$this->listener->handle(new ObjectUpdatedEvent(
			$this->object(['absentAtSource' => true, 'sourceEndedAt' => '2026-11-14T03:00:00+00:00']),
			$this->object([])
		));
	}
}
