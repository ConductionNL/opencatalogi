<?php
/**
 * The register and schema ids a catalogue scopes the public search to.
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
 *
 * @spec openspec/specs/search/spec.md
 */

namespace OCA\OpenCatalogi\Service;

/**
 * Reads the register and schema ids off a catalogue's metadata, failing closed
 * when the catalogue names registers or schemas of which none is an id.
 * Used by {@see PublicationQueryService::resolveCatalogScope()}.
 *
 * @spec openspec/specs/search/spec.md
 */
final class CatalogScopeIds {

	/**
	 * The register and schema ids one catalogue scopes the public search to.
	 *
	 * Fails closed: a catalogue that names registers (or schemas) of which none is an
	 * id gets an empty scope. Left as an empty register list it would add neither
	 * `_register` nor `_registers`, and the catalogue's schemas would be searched in
	 * every register that holds them; an empty schema list already ends in the empty
	 * envelope.
	 *
	 * @param array $catalog The catalogue's jsonSerialize()d array.
	 *
	 * @return array{registers: int[], schemas: int[]}
	 *
	 * @spec openspec/specs/search/spec.md
	 */
	public static function forCatalog(array $catalog): array {
		$registers = self::normalizeIds(value: ($catalog['registers'] ?? []));
		$schemas   = self::normalizeIds(value: ($catalog['schemas'] ?? []));

		if (($registers === [] && self::namesReferences(value: ($catalog['registers'] ?? [])) === true)
			|| ($schemas === [] && self::namesReferences(value: ($catalog['schemas'] ?? [])) === true)
		) {
			return ['registers' => [], 'schemas' => []];
		}

		return ['registers' => $registers, 'schemas' => $schemas];

	}//end forCatalog()

	/**
	 * Resolve an id-array from either a JSON-string or a native array. Every element
	 * is cast to int (schema/register ids in OC/OR are integers).
	 *
	 * An element that is not a positive id is left out. Cast with `intval()` a
	 * word became 0, and one catalogue naming a register that way (the example
	 * data does) scoped the whole public search to register 0, which
	 * OpenRegister refuses: every `/api/search` answered 500. Leaving it out
	 * narrows the scope while at least one id is left. When none is left the
	 * list is empty, and an empty register list means no register scope at all:
	 * {@see forCatalog()} turns that case into an empty scope for the catalogue.
	 *
	 * @param mixed $value Raw value from catalog metadata.
	 *
	 * @return int[]
	 *
	 * @spec openspec/specs/search/spec.md
	 */
	public static function normalizeIds(mixed $value): array {
		if (is_string($value) === true) {
			$decoded = json_decode($value, true);
			$value   = [];
			if (is_array($decoded) === true) {
				$value = $decoded;
			}
		}
		if (is_array($value) === false) {
			return [];
		}

		// An int, or a string of digits; whatever else casts to 0 and drops out.
		$digits = array_filter(
			$value,
			static fn (mixed $id): bool => is_int($id) === true || (is_string($id) === true && ctype_digit($id) === true)
		);

		return array_values(array_filter(array_map('intval', $digits), static fn (int $id): bool => $id > 0));

	}//end normalizeIds()

	/**
	 * Whether a catalogue's register or schema field names anything, id or not.
	 *
	 * @param mixed $value Raw value from catalog metadata: a list, or a JSON string of one.
	 *
	 * @return bool
	 */
	private static function namesReferences(mixed $value): bool {
		if (is_string($value) === true) {
			if (trim($value) === '') {
				return false;
			}

			$decoded = json_decode($value, true);
			if (is_array($decoded) === false) {
				// A word, not a list: it names something, and no id.
				return true;
			}

			$value = $decoded;
		}

		return is_array($value) === true && $value !== [];

	}//end namesReferences()
}//end class
