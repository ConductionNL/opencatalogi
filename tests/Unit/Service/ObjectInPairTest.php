<?php

/**
 * Unit tests for ObjectInPair.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Service
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

use OCA\OpenCatalogi\Service\ObjectInPair;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

/**
 * A find stays inside the register and schema it was asked for.
 *
 * @spec openspec/specs/opencatalogi-adopt-or-abstractions/spec.md
 */
class ObjectInPairTest extends TestCase {

	/**
	 * The configured pair.
	 *
	 * @var array<string, mixed>
	 */
	private const CONFIG = ['register' => 4, 'schema' => 12];

	/**
	 * An entity in the given register and schema.
	 *
	 * @param string $register The register id.
	 * @param string $schema   The schema id.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $register, string $schema): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setRegister($register);
		$entity->setSchema($schema);
		return $entity;

	}//end entity()

	/**
	 * An entity in the asked pair comes back unchanged; nothing found stays null.
	 *
	 * @return void
	 */
	public function testAnEntityInThePairPasses(): void {
		$entity = $this->entity(register: '4', schema: '12');

		$this->assertSame(expected: $entity, actual: ObjectInPair::require(found: $entity, config: self::CONFIG));
		$this->assertNull(actual: ObjectInPair::require(found: null, config: self::CONFIG));

	}//end testAnEntityInThePairPasses()

	/**
	 * An entity from another schema or register is reported as not found.
	 *
	 * @return void
	 */
	public function testAnEntityOutsideThePairIsNotFound(): void {
		foreach ([['4', '13'], ['5', '12'], ['', '']] as [$register, $schema]) {
			try {
				ObjectInPair::require(found: $this->entity(register: $register, schema: $schema), config: self::CONFIG);
				$this->fail(message: 'an entity in ' . $register . '/' . $schema . ' passed');
			} catch (DoesNotExistException $e) {
				$this->addToAssertionCount(count: 1);
			}
		}

	}//end testAnEntityOutsideThePairIsNotFound()

	/**
	 * A serialised object is checked through its envelope; a scalar is refused.
	 *
	 * @return void
	 */
	public function testSerialisedObjectsAndScalars(): void {
		$inPair = ['@self' => ['register' => '4', 'schema' => '12']];
		$this->assertSame(expected: $inPair, actual: ObjectInPair::require(found: $inPair, config: self::CONFIG));

		$this->expectException(exception: DoesNotExistException::class);
		ObjectInPair::require(found: ['@self' => ['register' => '4', 'schema' => '99']], config: self::CONFIG);

	}//end testSerialisedObjectsAndScalars()

	/**
	 * A value that is neither an entity nor an array is refused.
	 *
	 * @return void
	 */
	public function testAScalarIsRefused(): void {
		$this->expectException(exception: DoesNotExistException::class);
		ObjectInPair::require(found: 'object-1', config: self::CONFIG);

	}//end testAScalarIsRefused()
}//end class
