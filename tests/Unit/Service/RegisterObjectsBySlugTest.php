<?php

/**
 * Tests for RegisterObjectsBySlug: finding and writing one register's objects by slug.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/council-documents-from-notubiz-and-ibabs/specs/council-documents-harvest/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Service;

use OCA\OpenCatalogi\Service\RegisterObjectsBySlug;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenCatalogi\Service\RegisterObjectsBySlug
 */
class RegisterObjectsBySlugTest extends TestCase {

	/**
	 * A container holding one object service.
	 *
	 * @param ObjectService $objects The object service.
	 *
	 * @return ContainerInterface
	 */
	private function container(ObjectService $objects): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($objects);

		return $container;
	}//end container()

	/**
	 * A found object comes back, looked up in the register and schema asked for, without tenant scoping.
	 *
	 * @return void
	 */
	public function testFindReadsTheRegisterAndSchemaBySlug(): void {
		$entity = new ObjectEntity();
		$entity->setUuid('uuid-1');
		$objects = $this->createMock(ObjectService::class);
		$objects->expects($this->once())->method('find')->willReturnCallback(
			function (int|string $id, ?ObjectFindOptions $options = null, bool $_rbac = true, bool $_multitenancy = true) use ($entity): ObjectEntity {
				$this->assertSame('notubiz', $id);
				$this->assertSame('integriq', $options?->register);
				$this->assertSame('source', $options?->schema);
				$this->assertFalse($_multitenancy);
				return $entity;
			}
		);

		$found = (new RegisterObjectsBySlug(container: $this->container(objects: $objects), register: 'integriq'))->find(schema: 'source', slug: 'notubiz');

		$this->assertSame($entity, $found);
	}//end testFindReadsTheRegisterAndSchemaBySlug()

	/**
	 * A failed read is null, not an exception.
	 *
	 * @return void
	 */
	public function testFindIsNullWhenTheReadFails(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willThrowException(new RuntimeException('gone'));

		$found = (new RegisterObjectsBySlug(container: $this->container(objects: $objects), register: 'integriq'))->find(schema: 'source', slug: 'x');

		$this->assertNull($found);
	}//end testFindIsNullWhenTheReadFails()

	/**
	 * A new slug is created without a uuid; a stored one is updated over its uuid.
	 *
	 * @return void
	 */
	public function testWriteCreatesOrUpdatesBySlug(): void {
		$stored = new ObjectEntity();
		$stored->setUuid('uuid-rule');
		$saved = [];
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(
			static fn (int|string $id): ?ObjectEntity => ($id === 'rule-1' ? $stored : null)
		);
		$objects->expects($this->exactly(2))->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ObjectSaveOptions $options) use (&$saved): ObjectEntity {
				$saved[] = [$object['slug'], $options->target->register, $options->target->schema, $options->uuid];
				return new ObjectEntity();
			}
		);
		$store = new RegisterObjectsBySlug(container: $this->container(objects: $objects), register: 'integriq');

		$this->assertSame('created', $store->write(schema: 'synchronization', object: ['slug' => 'sync-1']));
		$this->assertSame('updated', $store->write(schema: 'rule', object: ['slug' => 'rule-1']));
		$this->assertSame(
			[['sync-1', 'integriq', 'synchronization', null], ['rule-1', 'integriq', 'rule', 'uuid-rule']],
			$saved
		);
	}//end testWriteCreatesOrUpdatesBySlug()
}//end class
