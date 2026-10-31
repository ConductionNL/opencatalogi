<?php

/**
 * OpenCatalogi OpenRegister result reader.
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
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service;

/**
 * Read an object's properties from whatever shape OpenRegister answered with.
 *
 * OpenRegister answers with an entity, with a `['id' => ..., 'object' => [...]]`
 * envelope, or with the properties themselves. Depends on nothing the
 * consuming class has to provide.
 *
 * @spec exclude pure shape adaptation over the consumed OR ObjectService.
 */
trait ReadsObjectProperties {
	/**
	 * The properties, with an envelope's id (or else the properties' own id) as `id`.
	 *
	 * @param mixed $object The result.
	 *
	 * @return array<string, mixed> The properties, or none for a non-array result.
	 *
	 * @spec exclude pure shape adaptation over the consumed OR ObjectService.
	 */
	private function asArray(mixed $object): array {
		$object = $this->serialised(object: $object);
		if (is_array($object) === false) {
			return [];
		}

		if (isset($object['object']) === true && is_array($object['object']) === true) {
			$properties = $object['object'];
			$properties['id'] = ($object['id'] ?? ($properties['id'] ?? null));

			return $properties;
		}

		return $object;
	}//end asArray()

	/**
	 * The properties as a record that always has an `id`: the envelope's id, its @self id or its uuid.
	 *
	 * @param mixed $object The result.
	 *
	 * @return array<string, mixed> The record, or none for a non-array result.
	 *
	 * @spec exclude pure shape adaptation over the consumed OR ObjectService.
	 */
	private function asRecord(mixed $object): array {
		$object = $this->serialised(object: $object);
		if (is_array($object) === false) {
			return [];
		}

		$id = ($object['id'] ?? ($object['@self']['id'] ?? ($object['uuid'] ?? null)));
		if (isset($object['object']) === true && is_array($object['object']) === true) {
			$object = $object['object'];
		}

		$object['id'] = $id;

		return $object;
	}//end asRecord()

	/**
	 * A result as a plain array: an array as it is, an entity's serialised array, anything else empty.
	 *
	 * @param mixed $object The result.
	 *
	 * @return array<string, mixed> The array, without unwrapping an envelope.
	 *
	 * @spec exclude pure shape adaptation over the consumed OR ObjectService.
	 */
	private function plainArray(mixed $object): array {
		$object = $this->serialised(object: $object);
		if (is_array($object) === false) {
			return [];
		}

		return $object;
	}//end plainArray()

	/**
	 * An entity's serialised form; anything else unchanged.
	 *
	 * @param mixed $object The result.
	 *
	 * @return mixed The serialised entity, or the result itself.
	 *
	 * @spec exclude pure shape adaptation over the consumed OR ObjectService.
	 */
	private function serialised(mixed $object): mixed {
		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			return $object->jsonSerialize();
		}

		return $object;
	}//end serialised()
}//end trait
