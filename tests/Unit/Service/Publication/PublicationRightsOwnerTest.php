<?php

/**
 * Unit tests for PublicationRights: a publication without a string owner.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Service\Publication
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenCatalogi.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service\Publication;

use OCA\OpenCatalogi\Service\Publication\PublicationRights;
use OCA\OpenCatalogi\Service\ServiceCatalogueService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * A publication whose owner is not a string is asked about with no owner.
 *
 * @covers \OCA\OpenCatalogi\Service\Publication\PublicationRights
 */
class PublicationRightsOwnerTest extends TestCase {

	/**
	 * The rights service over the given mapper and handler.
	 *
	 * @param SchemaMapper $mapper The schema mapper.
	 * @param PermissionHandler $handler The permission handler.
	 */
	private function rights(SchemaMapper $mapper, PermissionHandler $handler): PublicationRights {
		$objectService = $this->getMockBuilder(ObjectService::class)
			->disableOriginalConstructor()
			->onlyMethods(['getPermissionHandler'])
			->getMock();
		$objectService->method('getPermissionHandler')->willReturn($handler);

		$objects = $this->getMockBuilder(ServiceCatalogueService::class)
			->disableOriginalConstructor()
			->onlyMethods(['getObjectService'])
			->getMock();
		$objects->method('getObjectService')->willReturn($objectService);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($mapper) {
				if ($id === 'OCA\OpenRegister\Db\SchemaMapper') {
					return $mapper;
				}

				throw new \RuntimeException('not available: ' . $id);
			}
		);

		return new PublicationRights($container, $objects, $this->createMock(LoggerInterface::class));
	}

	/** REQ-PPW-002: an object without an owner is asked about with owner null, not an empty string. */
	public function testAPublicationWithoutAnOwnerIsAskedAboutWithNone(): void {
		$schema = new Schema();
		$mapper = $this->getMockBuilder(SchemaMapper::class)->disableOriginalConstructor()->onlyMethods(['find'])->getMock();
		$mapper->expects($this->once())->method('find')->with('7')->willReturn($schema);

		$publication = new ObjectEntity();
		$publication->setSchema('7');

		$handler = $this->getMockBuilder(PermissionHandler::class)->disableOriginalConstructor()->onlyMethods(['hasPermission'])->getMock();
		$handler->expects($this->once())->method('hasPermission')->with(
			$schema,
			'update',
			$this->identicalTo(null),
			$this->identicalTo(null),
			true,
			$this->identicalTo($publication)
		)->willReturn(true);

		$this->assertTrue($this->rights($mapper, $handler)->mayUpdate($publication));
	}
}
