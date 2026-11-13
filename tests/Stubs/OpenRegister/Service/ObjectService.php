<?php

/**
 * Stub for OCA\OpenRegister\Service\ObjectService.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Stubs
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * Minimal stub for ObjectService used by PHPUnit mocks in bare CI.
 * Method signatures match the real ObjectService so named-parameter calls work.
 */
class ObjectService {

	/**
	 * Set register context.
	 *
	 * @param Register|string|integer $register Register identifier.
	 *
	 * @return static
	 */
	public function setRegister(Register|string|int $register): static {
		return $this;
	}//end setRegister()

	/**
	 * Set schema context.
	 *
	 * @param Schema|string|integer $schema Schema identifier.
	 *
	 * @return static
	 */
	public function setSchema(Schema|string|int $schema): static {
		return $this;
	}//end setSchema()

	/**
	 * Find an object by ID or UUID.
	 *
	 * @param integer|string $id Object identifier.
	 * @param array<string,mixed>|null $_extend Extension config.
	 * @param boolean $files Include files.
	 * @param Register|string|integer|null $register Register context.
	 * @param Schema|string|integer|null $schema Schema context.
	 * @param boolean $_rbac Apply RBAC.
	 * @param boolean $_multitenancy Apply multitenancy.
	 * @param boolean $_render Render the object.
	 * @param boolean $_audit Record the read in the audit trail.
	 *
	 * @return ObjectEntity|null
	 */
	public function find(
		int|string $id,
		?array $_extend = [],
		bool $files = false,
		Register|string|int|null $register = null,
		Schema|string|int|null $schema = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
		bool $_render = true,
		bool $_audit = true,
	): ?ObjectEntity {
		return null;
	}//end find()

	/**
	 * Find all objects.
	 *
	 * @param array<string,mixed> $config Query configuration.
	 * @param boolean $_rbac Apply RBAC filter.
	 * @param boolean $_multitenancy Apply multitenancy filter.
	 *
	 * @return array<ObjectEntity>
	 */
	public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
		return [];
	}//end findAll()

	/**
	 * Save an object.
	 *
	 * @param array<string,mixed>|ObjectEntity $object Object data or entity.
	 * @param array<string,mixed>|null $extend Extension data.
	 * @param Register|string|integer|null $register Register context.
	 * @param Schema|string|integer|null $schema Schema context.
	 * @param string|null $uuid Object UUID.
	 * @param boolean $_rbac Apply RBAC.
	 * @param boolean $_multitenancy Apply multitenancy.
	 * @param boolean $silent Silent mode.
	 * @param array<mixed>|null $uploadedFiles Uploaded files.
	 * @param mixed $currentUser Current user.
	 *
	 * @return ObjectEntity
	 */
	public function saveObject(
		array|ObjectEntity $object,
		?array $extend = [],
		Register|string|int|null $register = null,
		Schema|string|int|null $schema = null,
		?string $uuid = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
		bool $silent = false,
		bool $_validation = true,
		?array $uploadedFiles = null,
		mixed $currentUser = null,
		bool $failIfExists = false,
		bool $_unowned = false,
		bool $_dedupOverride = false,
		?string $_owner = null,
	): ObjectEntity {
		return new ObjectEntity();
	}//end saveObject()

	/**
	 * Delete an object by UUID.
	 *
	 * @param \OCA\OpenRegister\Service\Object\ObjectDeletion $deletion The object, its register and schema.
	 * @param boolean $_rbac Apply RBAC.
	 * @param boolean $_multitenancy Apply multitenancy.
	 * @param boolean $_retentionSweep Retention sweep mode.
	 * @param \OCP\IUser|null $currentUser The acting user.
	 * @param boolean $permanent Delete permanently.
	 *
	 * @return boolean
	 */
	public function deleteObject(
		\OCA\OpenRegister\Service\Object\ObjectDeletion $deletion,
		bool $_rbac = true,
		bool $_multitenancy = true,
		bool $_retentionSweep = false,
		?\OCP\IUser $currentUser = null,
		bool $permanent = false,
	): bool {
		return true;
	}//end deleteObject()

	/**
	 * Search objects.
	 *
	 * @param array<string,mixed> $query Search query.
	 * @param boolean $_rbac Apply RBAC filter.
	 * @param boolean $_multitenancy Apply multitenancy filter.
	 * @param array<mixed>|null $ids IDs to restrict search to.
	 * @param string|null $uses Filter by uses relation.
	 * @param array<mixed>|null $views Views to search.
	 * @param boolean $_viewScopeRequired Require a view scope.
	 *
	 * @return array<ObjectEntity>|integer
	 */
	public function searchObjects(
		array $query = [],
		bool $_rbac = true,
		bool $_multitenancy = true,
		?array $ids = null,
		?string $uses = null,
		?array $views = null,
		bool $_viewScopeRequired = false,
	): array|int {
		return [];
	}//end searchObjects()

	/**
	 * Build a search query.
	 *
	 * @param array<string,mixed> $requestParams The request parameters.
	 * @param int|string|array<mixed>|null $register Register filter.
	 * @param int|string|array<mixed>|null $schema Schema filter.
	 * @param array<mixed>|null $ids Object id filter.
	 *
	 * @return array<string,mixed>
	 */
	public function buildSearchQuery(
		array $requestParams,
		int|string|array|null $register = null,
		int|string|array|null $schema = null,
		?array $ids = null,
	): array {
		return [];
	}//end buildSearchQuery()

	/**
	 * Search objects with pagination.
	 *
	 * @param array<string,mixed> $query Search query.
	 * @param boolean $_rbac Apply RBAC filter.
	 * @param boolean $_multitenancy Apply multitenancy filter.
	 * @param boolean $deleted Include deleted objects.
	 * @param array<mixed>|null $ids IDs to restrict to.
	 * @param string|null $uses Filter by uses relation.
	 * @param array<mixed>|null $views Views to search.
	 *
	 * @return array<string,mixed>
	 */
	public function searchObjectsPaginated(
		array $query = [],
		bool $_rbac = true,
		bool $_multitenancy = true,
		bool $deleted = false,
		?array $ids = null,
		?string $uses = null,
		?array $views = null,
	): array {
		return ['results' => [], 'total' => 0];
	}//end searchObjectsPaginated()

	/**
	 * Find objects by relations.
	 *
	 * @param string $search Search value.
	 * @param boolean $partialMatch Allow partial matches.
	 *
	 * @return array<ObjectEntity>
	 */
	public function findByRelations(string $search, bool $partialMatch = true): array {
		return [];
	}//end findByRelations()

	/**
	 * Render an entity with optional extension, depth, filter and RBAC controls.
	 *
	 * @param ObjectEntity $entity Entity to render.
	 * @param array<mixed>|null $_extend Extension paths.
	 * @param integer|null $depth Recursion depth.
	 * @param array<mixed>|null $filter Field filter.
	 * @param array<mixed>|null $fields Fields to include.
	 * @param array<mixed>|null $unset Fields to unset.
	 * @param boolean $_rbac Apply RBAC.
	 * @param boolean $_multitenancy Apply multitenancy.
	 *
	 * @return array<string,mixed>
	 */
	public function renderEntity(
		ObjectEntity $entity,
		?array $_extend = [],
		?int $depth = 0,
		?array $filter = [],
		?array $fields = [],
		?array $unset = [],
		bool $_rbac = true,
		bool $_multitenancy = true,
	): array {
		return $entity->jsonSerialize();
	}//end renderEntity()

	/**
	 * Get objects that this object uses (outgoing relations).
	 *
	 * @param string $objectId Object ID or UUID.
	 * @param array<string,mixed> $query Search query parameters.
	 * @param boolean $_rbac Apply RBAC filters.
	 * @param boolean $_multitenancy Apply multitenancy filters.
	 *
	 * @return array<mixed>
	 */
	public function getObjectUses(
		string $objectId,
		array $query = [],
		bool $_rbac = true,
		bool $_multitenancy = true,
	): array {
		return [];
	}//end getObjectUses()

	/**
	 * Get objects that use this object (incoming relations).
	 *
	 * @param string $objectId Object ID or UUID.
	 * @param array<string,mixed> $query Search query parameters.
	 * @param boolean $_rbac Apply RBAC filters.
	 * @param boolean $_multitenancy Apply multitenancy filters.
	 *
	 * @return array<mixed>
	 */
	public function getObjectUsedBy(
		string $objectId,
		array $query = [],
		bool $_rbac = true,
		bool $_multitenancy = true,
	): array {
		return [];
	}//end getObjectUsedBy()

	/**
	 * The permission handler that answers RBAC questions on one object.
	 *
	 * @return \OCA\OpenRegister\Service\Object\PermissionHandler
	 */
	public function getPermissionHandler(): \OCA\OpenRegister\Service\Object\PermissionHandler {
		// The real handler takes four service bundles; a stub instance needs none of them.
		return (new \ReflectionClass(\OCA\OpenRegister\Service\Object\PermissionHandler::class))->newInstanceWithoutConstructor();
	}//end getPermissionHandler()

}//end class
