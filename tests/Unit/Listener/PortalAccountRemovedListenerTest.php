<?php

/**
 * Tests for the cleanup after a removed portal account, on the real
 * OpenRegister ObjectUpdatedEvent and ObjectEntity classes.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @link https://www.OpenCatalogi.nl
 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-removing-a-portal-account-deletes-that-residents-dossiers-req-ccol-007
 */

declare(strict_types=1);

namespace Unit\Listener;

require_once __DIR__.'/../Service/Portal/FakePortalObjectStore.php';

use OCA\OpenCatalogi\Listener\PortalAccountRemovedListener;
use OCA\OpenCatalogi\Service\Portal\CitizenCollectionService;
use OCA\OpenCatalogi\Service\Portal\PublicationLinker;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Unit\Service\Portal\FakePortalObjectStore;

/**
 * Tests for PortalAccountRemovedListener.
 */
class PortalAccountRemovedListenerTest extends TestCase {

	private FakePortalObjectStore $store;

	private PortalAccountRemovedListener $listener;

	protected function setUp(): void {
		$this->store = new FakePortalObjectStore();
		foreach ([['c1', 'subject-1'], ['c2', 'subject-1'], ['c3', 'subject-2']] as [$id, $owner]) {
			$this->store->objects['collection'][$id] = ['id' => $id, 'owner' => $owner, 'title' => 'x', 'items' => []];
		}

		$this->store->objects['savedSearch']['s1'] = ['id' => 's1', 'owner' => 'subject-1'];

		$maps = new class {
			/**
			 * @return array<string, string>
			 */
			public function getIdToSlugMap(): array {
				return ['7' => 'portaliq', '41' => 'portalAccount', '42' => 'portalMessage'];
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($maps);

		$service = new CitizenCollectionService(store: $this->store, linker: $this->createMock(PublicationLinker::class), logger: new NullLogger());
		$this->listener = new PortalAccountRemovedListener(collections: $service, container: $container, logger: new NullLogger());
	}

	/**
	 * A real OpenRegister entity.
	 *
	 * @param string               $schema The schema id.
	 * @param array<string, mixed> $data   The object.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $schema, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('acc-1');
		$entity->setRegister('7');
		$entity->setSchema($schema);
		$entity->setObject($data);
		return $entity;
	}

	public function testRemovingTheAccountDeletesThatResidentsObjectsOnly(): void {
		$this->listener->handle(new ObjectUpdatedEvent(
			$this->entity('41', ['subjectRef' => 'subject-1', 'status' => 'removed', 'email' => '']),
			$this->entity('41', ['subjectRef' => 'subject-1', 'status' => 'active', 'email' => 'a@example.org'])
		));

		$this->assertSame(['c3'], array_keys($this->store->objects['collection']));
		$this->assertSame([], $this->store->objects['savedSearch']);
	}

	public function testOtherUpdatesChangeNothing(): void {
		$events = [
			new ObjectUpdatedEvent($this->entity('41', ['subjectRef' => 'subject-1', 'status' => 'active']), $this->entity('41', ['subjectRef' => 'subject-1', 'status' => 'pending'])),
			new ObjectUpdatedEvent($this->entity('41', ['subjectRef' => 'subject-1', 'status' => 'removed']), $this->entity('41', ['subjectRef' => 'subject-1', 'status' => 'removed'])),
			new ObjectUpdatedEvent($this->entity('42', ['subjectRef' => 'subject-1', 'status' => 'removed']), $this->entity('42', ['subjectRef' => 'subject-1'])),
			new ObjectUpdatedEvent($this->entity('41', ['subjectRef' => 'subject-1', 'status' => 'removed']), null),
			new Event(),
		];
		foreach ($events as $event) {
			$this->listener->handle($event);
		}

		$this->assertCount(3, $this->store->objects['collection']);
		$this->assertCount(1, $this->store->objects['savedSearch']);
	}

	public function testAFailureNeverReachesTheAccountsSave(): void {
		$store = $this->createMock(FakePortalObjectStore::class);
		$store->method('findByOwner')->willThrowException(new RuntimeException('OpenRegister down'));
		$service = new CitizenCollectionService(store: $store, linker: $this->createMock(PublicationLinker::class), logger: new NullLogger());
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('no mapper'));
		$listener = new PortalAccountRemovedListener(collections: $service, container: $container, logger: new NullLogger());

		$register = new ObjectEntity();
		$register->setRegister('portaliq');
		$register->setSchema('portalAccount');
		$register->setObject(['subjectRef' => 'subject-1', 'status' => 'removed']);
		$old = new ObjectEntity();
		$old->setObject(['subjectRef' => 'subject-1', 'status' => 'active']);

		$listener->handle(new ObjectUpdatedEvent($register, $old));
		$this->addToAssertionCount(1);
	}
}
