<?php

/**
 * Reads and writes the objects of one register by slug.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/council-documents-from-notubiz-and-ibabs/specs/council-documents-harvest/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service;

use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * The objects of one register, found and written by slug through
 * OpenRegister's object service, resolved from the container on use
 * (ADR-083) so the class stays constructible without OpenRegister.
 *
 * @spec openspec/changes/council-documents-from-notubiz-and-ibabs/specs/council-documents-harvest/spec.md#requirement-an-administrator-sets-up-the-council-documents-harvest-req-cdh-001
 */
final class RegisterObjectsBySlug {

	/**
	 * The object service id in the container.
	 */
	private const OBJECTS = 'OCA\OpenRegister\Service\ObjectService';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves OpenRegister's object service.
	 * @param string             $register  The register slug.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly string $register,
	) {

	}//end __construct()

	/**
	 * An object by slug, or null when it does not exist or cannot be read.
	 *
	 * @param string $schema The schema slug.
	 * @param string $slug   The object slug.
	 *
	 * @return object|null The object entity.
	 *
	 * @spec openspec/changes/council-documents-from-notubiz-and-ibabs/specs/council-documents-harvest/spec.md#requirement-an-administrator-sets-up-the-council-documents-harvest-req-cdh-001
	 */
	public function find(string $schema, string $slug): ?object {
		try {
			$found = $this->container->get(self::OBJECTS)->find(
				id: $slug,
				options: new ObjectFindOptions(register: $this->register, schema: $schema),
				_multitenancy: false
			);
		} catch (Throwable) {
			return null;
		}

		if (is_object($found) === false) {
			return null;
		}

		return $found;
	}//end find()

	/**
	 * Write an object by its slug, over the stored one when it exists.
	 *
	 * @param string               $schema The schema slug.
	 * @param array<string, mixed> $object The object, carrying its `slug`.
	 *
	 * @return string 'created' or 'updated'.
	 *
	 * @spec openspec/changes/council-documents-from-notubiz-and-ibabs/specs/council-documents-harvest/spec.md#requirement-an-administrator-sets-up-the-council-documents-harvest-req-cdh-001
	 */
	public function write(string $schema, array $object): string {
		$existing = $this->find(schema: $schema, slug: (string)$object['slug']);
		$this->container->get(self::OBJECTS)->saveObject(
			object: $object,
			options: new ObjectSaveOptions(target: new ObjectFindTarget(register: $this->register, schema: $schema), uuid: $existing?->getUuid())
		);

		if ($existing === null) {
			return 'created';
		}

		return 'updated';
	}//end write()
}//end class
