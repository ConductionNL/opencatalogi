<?php

/**
 * Stub for OCA\OpenRegister\Db\SchemaMapper.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Stubs
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * Minimal stub for SchemaMapper.
 * Method signatures match real SchemaMapper so named-parameter calls work.
 */
class SchemaMapper {

	/**
	 * Find a schema by ID or slug.
	 *
	 * @param string|integer $id The schema ID or slug.
	 * @param array<mixed>|null $_extend Extension config.
	 * @param boolean $_rbac Apply RBAC.
	 * @param boolean $_multitenancy Apply multitenancy.
	 *
	 * @return Schema|null
	 */
	public function find(
		string|int $id,
		?array $_extend = [],
		bool $_rbac = true,
		bool $_multitenancy = true,
	): ?Schema {
		return null;
	}//end find()

	/**
	 * Find multiple schemas by IDs.
	 *
	 * @param array<int> $ids The IDs to find.
	 * @param boolean $_rbac Apply RBAC.
	 * @param boolean $_multitenancy Apply multitenancy.
	 *
	 * @return array<Schema>
	 */
	public function findMultiple(array $ids, bool $_rbac = true, bool $_multitenancy = true): array {
		return [];
	}//end findMultiple()

	/**
	 * Find multiple schemas by IDs in a single optimized query.
	 *
	 * Mirrors the real mapper's signature (one `ids` parameter, no RBAC or
	 * multitenancy flags); SettingsService calls it with named arguments, and a
	 * stub without the method makes createMock() drop it, which turns the call
	 * into a fatal instead of a mockable expectation.
	 *
	 * @param array<int> $ids Array of schema IDs to find.
	 *
	 * @return array<int|string, Schema> Map keyed by schema ID.
	 */
	public function findMultipleOptimized(array $ids): array {
		return [];
	}//end findMultipleOptimized()

	/**
	 * Find all schemas.
	 *
	 * @return array<Schema>
	 */
	public function findAll(): array {
		return [];
	}//end findAll()

}//end class
