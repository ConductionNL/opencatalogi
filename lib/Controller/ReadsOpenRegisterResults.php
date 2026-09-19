<?php

/**
 * OpenCatalogi OpenRegister result-shape trait.
 *
 * One copy of the adaptation between what OpenRegister's ObjectService hands
 * back and the plain array the controllers work with. OpenRegister answers
 * with an entity, with a `['id' => ..., 'object' => [...]]` envelope, or with
 * the properties themselves, and a controller that only knew one of those
 * shapes would read the others as empty rather than fail.
 *
 * @category Controller
 * @package  OCA\OpenCatalogi\Controller
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

namespace OCA\OpenCatalogi\Controller;

/**
 * Normalise an OpenRegister result to a plain array.
 *
 * Depends on nothing the consuming controller has to provide.
 */
trait ReadsOpenRegisterResults {
	/**
	 * Read an object's properties, whatever shape OpenRegister returned.
	 *
	 * @param mixed $object The result.
	 *
	 * @return array<string, mixed> The properties.
	 *
	 * @spec exclude pure shape adaptation over the consumed OR ObjectService.
	 */
	private function asArray(mixed $object): array {
		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$object = $object->jsonSerialize();
		}

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
}//end trait
