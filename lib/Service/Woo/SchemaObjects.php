<?php

/**
 * The objects of one register and schema, read and written as the system.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Woo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/woo-decision-shows-what-was-withheld/specs/publications/spec.md#requirement-dossiq-records-the-withheld-documents-through-one-named-method-req-wdw-002
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Woo;

/**
 * OpenRegister's ObjectService bound to one register and schema, without
 * RBAC or multitenancy.
 *
 * @spec openspec/changes/woo-decision-shows-what-was-withheld/specs/publications/spec.md#requirement-dossiq-records-the-withheld-documents-through-one-named-method-req-wdw-002
 */
final class SchemaObjects {

	/**
	 * The register.
	 *
	 * @var string
	 */
	private readonly string $register;

	/**
	 * The schema.
	 *
	 * @var string
	 */
	private readonly string $schema;

	/**
	 * Constructor.
	 *
	 * @param object                    $objects OpenRegister's ObjectService.
	 * @param array{0: string, 1: string} $target  The register and the schema.
	 */
	public function __construct(
		private readonly object $objects,
		array $target,
	) {
		[$this->register, $this->schema] = $target;

	}//end __construct()

	/**
	 * Search the schema's objects.
	 *
	 * @param array<string, mixed> $filters The filters, after the register and schema.
	 *
	 * @return mixed What OpenRegister answers.
	 *
	 * @spec openspec/changes/woo-decision-shows-what-was-withheld/specs/publications/spec.md#requirement-dossiq-records-the-withheld-documents-through-one-named-method-req-wdw-002
	 */
	public function search(array $filters): mixed {
		return $this->objects->searchObjectsPaginated(
			query: array_merge(['@self' => ['register' => $this->register, 'schema' => $this->schema]], $filters),
			_rbac: false,
			_multitenancy: false
		);

	}//end search()

	/**
	 * Save a new object.
	 *
	 * @param array<string, mixed> $object The object.
	 *
	 * @return mixed What OpenRegister answers.
	 *
	 * @spec openspec/changes/woo-decision-shows-what-was-withheld/specs/publications/spec.md#requirement-dossiq-records-the-withheld-documents-through-one-named-method-req-wdw-002
	 */
	public function save(array $object): mixed {
		return $this->objects->saveObject(
			object: $object,
			register: $this->register,
			schema: $this->schema,
			_rbac: false,
			_multitenancy: false
		);

	}//end save()

	/**
	 * Delete an object.
	 *
	 * @param string $id The object id.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-decision-shows-what-was-withheld/specs/publications/spec.md#requirement-dossiq-records-the-withheld-documents-through-one-named-method-req-wdw-002
	 */
	public function delete(string $id): void {
		$this->objects->deleteObject(uuid: $id, register: $this->register, schema: $this->schema, _rbac: false, _multitenancy: false);

	}//end delete()
}//end class
