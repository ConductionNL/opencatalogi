<?php

/**
 * Unit tests for PublicationRights.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Service\Publication
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenCatalogi.nl
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
 * The update right is asked of OpenRegister's permission handler, fail closed.
 */
class PublicationRightsTest extends TestCase {

	/**
	 * The rights service over the given mapper and handler.
	 *
	 * @param SchemaMapper      $mapper  The schema mapper.
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

	/**
	 * A publication entity of schema 7 owned by the given user.
	 *
	 * @param string $owner The owner.
	 */
	private function publication(string $owner): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setSchema('7');
		$entity->setOwner($owner);
		return $entity;
	}

	/** REQ-PPW-002: the question asked is update, on that schema, for that object and owner. */
	public function testTheUpdateRightIsAskedOfOpenRegister(): void {
		$schema = new Schema();
		$mapper = $this->getMockBuilder(SchemaMapper::class)->disableOriginalConstructor()->onlyMethods(['find'])->getMock();
		$mapper->expects($this->once())->method('find')->with('7')->willReturn($schema);

		$publication = $this->publication('eigenaar');
		$handler = $this->getMockBuilder(PermissionHandler::class)->disableOriginalConstructor()->onlyMethods(['hasPermission'])->getMock();
		$handler->expects($this->once())->method('hasPermission')->with(
			$schema,
			'update',
			null,
			'eigenaar',
			true,
			$publication
		)->willReturn(true);

		$this->assertTrue($this->rights($mapper, $handler)->mayUpdate($publication));
	}

	/** REQ-PPW-002: OpenRegister's no is a no. */
	public function testARefusalIsANo(): void {
		$mapper = $this->getMockBuilder(SchemaMapper::class)->disableOriginalConstructor()->onlyMethods(['find'])->getMock();
		$mapper->method('find')->willReturn(new Schema());
		$handler = $this->getMockBuilder(PermissionHandler::class)->disableOriginalConstructor()->onlyMethods(['hasPermission'])->getMock();
		$handler->method('hasPermission')->willReturn(false);

		$this->assertFalse($this->rights($mapper, $handler)->mayUpdate($this->publication('eigenaar')));
	}

	/** ADR-005: a check that could not be made answers no. */
	public function testACheckThatFailsAnswersNo(): void {
		$mapper = $this->getMockBuilder(SchemaMapper::class)->disableOriginalConstructor()->onlyMethods(['find'])->getMock();
		$mapper->method('find')->willThrowException(new \RuntimeException('schema gone'));
		$handler = $this->getMockBuilder(PermissionHandler::class)->disableOriginalConstructor()->onlyMethods(['hasPermission'])->getMock();
		$handler->expects($this->never())->method('hasPermission');

		$this->assertFalse($this->rights($mapper, $handler)->mayUpdate($this->publication('eigenaar')));
	}
}
