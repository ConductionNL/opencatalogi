<?php

/**
 * Golden tests for OoapiMappingService::buildResource().
 *
 * Pins the exact resource (keys, values and key order) for the identity copy
 * and the declared mapping: which envelope fields are skipped, which values
 * count as empty, what happens when the object or the mapping writes the id
 * field itself, numeric keys, and nested output paths over existing values.
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
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\OoapiMappingService;
use PHPUnit\Framework\TestCase;

/**
 * Golden tests for OoapiMappingService::buildResource().
 */
class OoapiBuildResourceGoldenTest extends TestCase {

	/**
	 * The identity copy skips the envelope, the catalog and empty values, keeps
	 * falsy non-empty ones, and lets an own property overwrite the id field in
	 * its first position.
	 *
	 * @return void
	 */
	public function testIdentityCopy(): void {
		$object = [
			'name' => 'Course',
			'@self' => ['uuid' => 'u-1'],
			'courseId' => 'own',
			'id' => 'i-1',
			'catalog' => 'c-1',
			'zero' => 0,
			'false' => false,
			'blank' => '',
			'none' => null,
			'list' => [],
			'0' => 'numeric',
			'nested' => ['a' => null],
		];

		$this->assertSame(
			['courseId' => 'own', 'name' => 'Course', 'zero' => 0, 'false' => false, 0 => 'numeric', 'nested' => ['a' => null]],
			(new OoapiMappingService())->buildResource($object, null, 'courseId')
		);

	}//end testIdentityCopy()

	/**
	 * Without any identity the id field is an empty string; an object with only
	 * envelope fields yields just that.
	 *
	 * @return void
	 */
	public function testIdentityCopyWithoutIdentity(): void {
		$this->assertSame(
			['courseId' => ''],
			(new OoapiMappingService())->buildResource(['@self' => [], 'catalog' => 'c'], null, 'courseId')
		);

	}//end testIdentityCopyWithoutIdentity()

	/**
	 * A declared mapping writes each non-empty source value to its output path,
	 * in mapping order, may overwrite the id field, and replaces a scalar on the
	 * way to a nested path.
	 *
	 * @return void
	 */
	public function testDeclaredMapping(): void {
		$object = [
			'id' => 'i-2',
			'code' => 'X1',
			'org' => ['name' => 'Org', 'empty' => ''],
			'alt' => 'ALT',
		];
		$mapping = [
			'primaryCode.code' => 'code',
			'name' => 'org.name',
			'skipped' => 'org.empty',
			'missing' => 'org.missing.deeper',
			'primaryCode' => 'alt',
			'primaryCode.codeType' => 'code',
			'organizationId' => 'alt',
		];

		$this->assertSame(
			['organizationId' => 'ALT', 'primaryCode' => ['codeType' => 'X1'], 'name' => 'Org'],
			(new OoapiMappingService())->buildResource($object, $mapping, 'organizationId')
		);

	}//end testDeclaredMapping()

	/**
	 * An empty mapping array is a declared mapping with nothing to copy.
	 *
	 * @return void
	 */
	public function testEmptyDeclaredMapping(): void {
		$this->assertSame(
			['organizationId' => 'i-3'],
			(new OoapiMappingService())->buildResource(['id' => 'i-3', 'name' => 'N'], [], 'organizationId')
		);

	}//end testEmptyDeclaredMapping()
}//end class
