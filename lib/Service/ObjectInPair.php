<?php

/**
 * Keeps a find inside the register and schema it was asked for.
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

use OCP\AppFramework\Db\DoesNotExistException;

/**
 * OpenRegister's find() can fall back to a lookup by uuid across every schema,
 * so a caller that names a register and schema checks the object it got back.
 * An array without an `@self` envelope carries no pair to check and passes.
 *
 * @spec openspec/specs/opencatalogi-adopt-or-abstractions/spec.md
 */
final class ObjectInPair {

	/**
	 * The found object when it lives in the given register and schema.
	 *
	 * @param mixed                $found  What find() returned.
	 * @param array<string, mixed> $config The `register` and `schema` ids asked for.
	 *
	 * @return mixed The object, or null when find() found nothing.
	 *
	 * @throws DoesNotExistException When the object lives in another register or schema.
	 *
	 * @spec openspec/specs/opencatalogi-adopt-or-abstractions/spec.md
	 */
	public static function require(mixed $found, array $config): mixed {
		if ($found === null) {
			return null;
		}

		$pair = self::pairOf(found: $found);
		if ($pair === null) {
			return $found;
		}

		if ($pair !== [(string)($config['register'] ?? ''), (string)($config['schema'] ?? '')]) {
			throw new DoesNotExistException('No object with this id in the register and schema asked for.');
		}

		return $found;

	}//end require()

	/**
	 * The register and schema an object reports: an entity through its getters,
	 * a serialised object through its `@self` envelope.
	 *
	 * @param mixed $found What find() returned.
	 *
	 * @return array{0: string, 1: string}|null The pair, or null for an array without an envelope.
	 *
	 * @throws DoesNotExistException When the value is neither an entity nor an array.
	 */
	private static function pairOf(mixed $found): ?array {
		if (is_object($found) === true && method_exists($found, 'getSchema') === true && method_exists($found, 'getRegister') === true) {
			return [(string)$found->getRegister(), (string)$found->getSchema()];
		}

		if (is_array($found) === false) {
			throw new DoesNotExistException('No object with this id in the register and schema asked for.');
		}

		$self = ($found['@self'] ?? null);
		if (is_array($self) === false || (isset($self['register']) === false && isset($self['schema']) === false)) {
			return null;
		}

		return [(string)($self['register'] ?? ''), (string)($self['schema'] ?? '')];

	}//end pairOf()
}//end class
