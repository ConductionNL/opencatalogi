<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Tests for CatalogAccessListener: a staff access change by a non-administrator
 * is refused before the write, and every catalogue save recomputes the
 * schemas it covered and covers.
 */

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenCatalogi\Listener\CatalogAccessListener;
use OCA\OpenCatalogi\Service\CatalogAccessService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CatalogAccessListener.
 */
class CatalogAccessListenerTest extends TestCase {

	private CatalogAccessService|MockObject $access;

	private IUserSession|MockObject $session;

	private IGroupManager|MockObject $groups;

	private CatalogAccessListener $listener;

	protected function setUp(): void {
		$this->access = $this->getMockBuilder(CatalogAccessService::class)
			->disableOriginalConstructor()
			->onlyMethods(['recompute', 'restrictedSchemaIds'])
			->getMock();
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ['catalog_register' => '19', 'catalog_schema' => '172'][$key] ?? $default
		);
		$this->session = $this->createMock(IUserSession::class);
		$this->groups = $this->createMock(IGroupManager::class);
		$this->listener = new CatalogAccessListener($this->access, $config, $this->session, $this->groups);

	}//end setUp()

	/**
	 * A catalogue entity.
	 *
	 * @param array<string, mixed> $data The catalogue.
	 * @param string               $schema Its schema id.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function catalog(array $data, string $schema='172'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setRegister('19');
		$entity->setSchema($schema);
		$entity->setObject($data);
		return $entity;

	}//end catalog()

	/**
	 * Sign in as a user who is or is not an administrator.
	 *
	 * @param bool $admin Whether the user is an administrator.
	 *
	 * @return void
	 */
	private function signIn(bool $admin): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('pieter');
		$this->session->method('getUser')->willReturn($user);
		$this->groups->method('isAdmin')->with('pieter')->willReturn($admin);

	}//end signIn()

	public function testANonAdministratorCannotRestrictACatalogue(): void {
		$this->signIn(admin: false);
		$event = new ObjectUpdatingEvent(
			$this->catalog(['title' => 'Raadsstukken', 'staffAccess' => ['restricted' => true, 'editGroups' => ['griffie']]]),
			$this->catalog(['title' => 'Raadsstukken'])
		);

		$this->listener->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame(CatalogAccessListener::REFUSAL, $event->getErrors()['message']);

	}//end testANonAdministratorCannotRestrictACatalogue()

	public function testANonAdministratorCannotCreateARestrictedCatalogue(): void {
		$this->signIn(admin: false);
		$event = new ObjectCreatingEvent($this->catalog(['title' => 'Geheim', 'staffAccess' => ['restricted' => true]]));

		$this->listener->handle($event);

		$this->assertTrue($event->isPropagationStopped());

	}//end testANonAdministratorCannotCreateARestrictedCatalogue()

	public function testANonAdministratorMayEditTheRestOfACatalogue(): void {
		$this->signIn(admin: false);
		$access = ['restricted' => true, 'editGroups' => ['griffie', 'woo-team']];
		$event = new ObjectUpdatingEvent(
			$this->catalog(['title' => 'Nieuwe titel', 'staffAccess' => ['editGroups' => ['woo-team', 'griffie'], 'restricted' => true]]),
			$this->catalog(['title' => 'Oude titel', 'staffAccess' => $access])
		);

		$this->listener->handle($event);

		$this->assertFalse($event->isPropagationStopped());

	}//end testANonAdministratorMayEditTheRestOfACatalogue()

	public function testAnAdministratorMayRestrictACatalogue(): void {
		$this->signIn(admin: true);
		$event = new ObjectUpdatingEvent(
			$this->catalog(['staffAccess' => ['restricted' => true, 'editGroups' => ['griffie']]]),
			$this->catalog([])
		);

		$this->listener->handle($event);

		$this->assertFalse($event->isPropagationStopped());

	}//end testAnAdministratorMayRestrictACatalogue()

	public function testAnotherSchemasObjectIsNotGuarded(): void {
		$this->signIn(admin: false);
		$event = new ObjectCreatingEvent($this->catalog(['staffAccess' => ['restricted' => true]], schema: '173'));

		$this->listener->handle($event);

		$this->assertFalse($event->isPropagationStopped());

	}//end testAnotherSchemasObjectIsNotGuarded()

	public function testANonAdministratorCannotCreateAnOpenCatalogueOnARestrictedSchema(): void {
		$this->signIn(admin: false);
		$this->access->method('restrictedSchemaIds')->willReturn([173]);
		$event = new ObjectCreatingEvent($this->catalog(['title' => 'Alles open', 'schemas' => [173]]));

		$this->listener->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame(CatalogAccessListener::REFUSAL, $event->getErrors()['message']);

	}//end testANonAdministratorCannotCreateAnOpenCatalogueOnARestrictedSchema()

	public function testANonAdministratorCannotRemoveASchemaFromARestrictedCatalogue(): void {
		$this->signIn(admin: false);
		$this->access->method('restrictedSchemaIds')->willReturn([173]);
		$access = ['restricted' => true, 'editGroups' => ['griffie']];
		$event = new ObjectUpdatingEvent(
			$this->catalog(['title' => 'Raadsstukken', 'schemas' => [], 'staffAccess' => $access]),
			$this->catalog(['title' => 'Raadsstukken', 'schemas' => [173], 'staffAccess' => $access])
		);

		$this->listener->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame(CatalogAccessListener::REFUSAL, $event->getErrors()['message']);

	}//end testANonAdministratorCannotRemoveASchemaFromARestrictedCatalogue()

	public function testANonAdministratorCannotChangeTheFiltersOfACatalogueOnARestrictedSchema(): void {
		$this->signIn(admin: false);
		$this->access->method('restrictedSchemaIds')->willReturn([173]);
		$event = new ObjectUpdatingEvent(
			$this->catalog(['schemas' => [173], 'filters' => ['kind' => 'alles']]),
			$this->catalog(['schemas' => [173], 'filters' => ['kind' => 'overig']])
		);

		$this->listener->handle($event);

		$this->assertTrue($event->isPropagationStopped());

	}//end testANonAdministratorCannotChangeTheFiltersOfACatalogueOnARestrictedSchema()

	public function testANonAdministratorCannotDeleteACatalogueOnARestrictedSchema(): void {
		$this->signIn(admin: false);
		$this->access->method('restrictedSchemaIds')->willReturn([173]);
		$event = new ObjectDeletingEvent($this->catalog(['title' => 'Overig', 'schemas' => [173], 'filters' => ['kind' => 'overig']]));

		$this->listener->handle($event);

		$this->assertTrue($event->isPropagationStopped());

	}//end testANonAdministratorCannotDeleteACatalogueOnARestrictedSchema()

	public function testANonAdministratorCannotDeleteARestrictedCatalogue(): void {
		$this->signIn(admin: false);
		$this->access->method('restrictedSchemaIds')->willReturn([]);
		$event = new ObjectDeletingEvent($this->catalog(['title' => 'Geheim', 'staffAccess' => ['restricted' => true]]));

		$this->listener->handle($event);

		$this->assertTrue($event->isPropagationStopped());

	}//end testANonAdministratorCannotDeleteARestrictedCatalogue()

	public function testANonAdministratorMayScopeACatalogueOnUnrestrictedSchemas(): void {
		$this->signIn(admin: false);
		$this->access->method('restrictedSchemaIds')->willReturn([999]);
		$event = new ObjectUpdatingEvent(
			$this->catalog(['schemas' => [173, 184], 'filters' => ['kind' => 'raad']]),
			$this->catalog(['schemas' => [173]])
		);

		$this->listener->handle($event);

		$this->assertFalse($event->isPropagationStopped());

	}//end testANonAdministratorMayScopeACatalogueOnUnrestrictedSchemas()

	public function testAScopeChangeIsRefusedWhenTheCataloguesCannotBeRead(): void {
		$this->signIn(admin: false);
		$this->access->method('restrictedSchemaIds')->willReturn(null);
		$event = new ObjectCreatingEvent($this->catalog(['schemas' => [173]]));

		$this->listener->handle($event);

		$this->assertTrue($event->isPropagationStopped());

	}//end testAScopeChangeIsRefusedWhenTheCataloguesCannotBeRead()

	public function testAnAdministratorMayChangeTheScopeOfACatalogueOnARestrictedSchema(): void {
		$this->signIn(admin: true);
		$this->access->method('restrictedSchemaIds')->willReturn([173]);
		$event = new ObjectCreatingEvent($this->catalog(['schemas' => [173]]));

		$this->listener->handle($event);

		$this->assertFalse($event->isPropagationStopped());

	}//end testAnAdministratorMayChangeTheScopeOfACatalogueOnARestrictedSchema()

	public function testASavedCatalogueRecomputesItsOldAndNewSchemas(): void {
		$recomputed = [];
		$this->access->method('recompute')->willReturnCallback(
			function (int $schemaId) use (&$recomputed): bool {
				$recomputed[] = $schemaId;
				return true;
			}
		);

		$this->listener->handle(
			new ObjectUpdatedEvent($this->catalog(['schemas' => [173, 184]]), $this->catalog(['schemas' => [173, 190]]))
		);

		sort($recomputed);
		$this->assertSame([173, 184, 190], $recomputed);

	}//end testASavedCatalogueRecomputesItsOldAndNewSchemas()

	public function testADeletedCatalogueRecomputesItsSchemas(): void {
		$this->access->expects($this->once())->method('recompute')->with(173);

		$this->listener->handle(new ObjectDeletedEvent($this->catalog(['schemas' => ['173']])));

	}//end testADeletedCatalogueRecomputesItsSchemas()
}//end class
