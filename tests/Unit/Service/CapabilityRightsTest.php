<?php

/**
 * Unit tests for CapabilityRights.
 *
 * @category Test
 * @package  Unit\Service
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

namespace Unit\Service;

use OCA\OpenCatalogi\Service\CapabilityRights;
use OCA\OpenCatalogi\Service\ServiceCatalogueService;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * may() asks OpenRegister's permission handler about the catalog schema, fail closed (REQ-WAB-004).
 */
#[CoversClass(CapabilityRights::class)]
class CapabilityRightsTest extends TestCase {

	/**
	 * The service over the given mapper and handler.
	 *
	 * @param SchemaMapper      $mapper  The schema mapper.
	 * @param PermissionHandler $handler The permission handler.
	 *
	 * @return CapabilityRights The service.
	 */
	private function rights(SchemaMapper $mapper, PermissionHandler $handler): CapabilityRights {
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

				throw new \RuntimeException('not available: '.$id);
			}
		);

		return new CapabilityRights($container, $objects, $this->createMock(LoggerInterface::class));

	}//end rights()

	/**
	 * The handler is asked about the catalog schema, the verb and the user, and its answer is returned.
	 *
	 * @return void
	 */
	public function testTheHandlerDecidesOnTheCatalogSchema(): void {
		$schema = new Schema();
		$mapper = $this->createMock(SchemaMapper::class);
		$mapper->expects($this->exactly(2))->method('find')->with('catalog')->willReturn($schema);

		$handler = $this->createMock(PermissionHandler::class);
		$handler->expects($this->exactly(2))->method('hasPermission')
			->with($schema, 'openInspection', 'anna')
			->willReturnOnConsecutiveCalls(true, false);

		$rights = $this->rights($mapper, $handler);

		$this->assertTrue($rights->may('openInspection', 'anna'));
		$this->assertFalse($rights->may('openInspection', 'anna'));

	}//end testTheHandlerDecidesOnTheCatalogSchema()

	/**
	 * An unknown verb and an anonymous caller are refused without asking.
	 *
	 * @return void
	 */
	public function testAnUnknownVerbOrAnonymousIsRefused(): void {
		$mapper  = $this->createMock(SchemaMapper::class);
		$handler = $this->createMock(PermissionHandler::class);
		$handler->expects($this->never())->method('hasPermission');

		$rights = $this->rights($mapper, $handler);

		$this->assertFalse($rights->may('read', 'anna'));
		$this->assertFalse($rights->may('openInspection', null));
		$this->assertFalse($rights->may('openInspection', ''));

	}//end testAnUnknownVerbOrAnonymousIsRefused()

	/**
	 * A catalog schema that cannot be found refuses.
	 *
	 * @return void
	 */
	public function testAnUnreadableSchemaRefuses(): void {
		$mapper = $this->createMock(SchemaMapper::class);
		$mapper->method('find')->willThrowException(new \RuntimeException('gone'));
		$handler = $this->createMock(PermissionHandler::class);
		$handler->expects($this->never())->method('hasPermission');

		$this->assertFalse($this->rights($mapper, $handler)->may('publishWooBatch', 'anna'));

	}//end testAnUnreadableSchemaRefuses()
}//end class
