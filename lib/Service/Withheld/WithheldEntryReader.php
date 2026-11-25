<?php

/**
 * Reads one withheld-document entry down to its position and ground codes.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Withheld
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

namespace OCA\OpenCatalogi\Service\Withheld;

/**
 * Reduces a raw entry to exactly `{position, grounds}`.
 *
 * Every other key, a title first of all, is dropped here, before anything is
 * read or stored, so the recording path cannot leak what was withheld.
 *
 * @spec openspec/changes/woo-decision-shows-what-was-withheld/specs/publications/spec.md#requirement-dossiq-records-the-withheld-documents-through-one-named-method-req-wdw-002
 */
final class WithheldEntryReader {

	/**
	 * Reduce an entry to `{position, grounds}`; a title or anything else is dropped here.
	 *
	 * @param mixed $entry The raw entry.
	 *
	 * @return array{position: int, grounds: list<string>}
	 *
	 * @spec openspec/changes/woo-decision-shows-what-was-withheld/specs/publications/spec.md#requirement-dossiq-records-the-withheld-documents-through-one-named-method-req-wdw-002
	 */
	public function reduce(mixed $entry): array {
		if (is_array($entry) === false) {
			return ['position' => 0, 'grounds' => []];
		}

		return [
			'position' => $this->position(position: ($entry['position'] ?? null)),
			'grounds'  => $this->groundCodes(codes: ($entry['grounds'] ?? [])),
		];
	}//end reduce()

	/**
	 * The trimmed, non-blank text codes of an entry's grounds.
	 *
	 * @param mixed $codes The entry's grounds.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/woo-decision-shows-what-was-withheld/specs/publications/spec.md#requirement-dossiq-records-the-withheld-documents-through-one-named-method-req-wdw-002
	 */
	private function groundCodes(mixed $codes): array {
		$grounds = [];
		foreach ((array)$codes as $code) {
			if (is_string($code) === true && trim($code) !== '') {
				$grounds[] = trim($code);
			}
		}

		return $grounds;
	}//end groundCodes()

	/**
	 * An entry's position as a number, or 0 when it is not numeric.
	 *
	 * @param mixed $position The entry's position.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/woo-decision-shows-what-was-withheld/specs/publications/spec.md#requirement-dossiq-records-the-withheld-documents-through-one-named-method-req-wdw-002
	 */
	private function position(mixed $position): int {
		if (is_numeric($position) === true) {
			return (int)$position;
		}

		return 0;
	}//end position()
}//end class
