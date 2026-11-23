<?php

/**
 * Reads the frozen marker of a record and words its refusal.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Publication
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
 * @spec openspec/changes/publication-withdrawal-aftercare/tasks.md#task-3.3
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Publication;

use Throwable;

/**
 * Says whether a record is frozen, and in which words a document write on it is refused.
 *
 * OpenRegister refuses a data write on a frozen object itself, and a file
 * write through its own files API. A document write that OpenCatalogi makes
 * through OpenRegister's file service skips that door, so each such path asks
 * this class first. The record may be an object entity (its `frozen` marker
 * read through `getFrozen()`) or an object array (`@self.frozen`, or `frozen`
 * at the top). The sentence is the one of OpenRegister's
 * `ObjectStateWriteException::frozen()`, so a refusal reads the same through
 * every door. No OpenRegister class is needed to read it.
 *
 * @spec openspec/changes/publication-withdrawal-aftercare/specs/publications/spec.md#requirement-a-withdrawn-publication-is-frozen-with-the-withdrawal-reason-req-pwa-003
 */
class FrozenRecord {

	/**
	 * The refusal of a document write on this record, or null when it is not frozen.
	 *
	 * @param mixed $record An object entity or an object array.
	 *
	 * @return string|null The refusal naming who froze it and when, or null.
	 *
	 * @spec openspec/changes/publication-withdrawal-aftercare/tasks.md#task-3.3
	 */
	public function refusal(mixed $record): ?string {
		$marker = $this->marker(record: $record);
		if ($marker === null) {
			return null;
		}

		$because = '';
		$state = $this->field(marker: $marker, key: 'state');
		if ($state !== null) {
			$because = ' on entering the state "' . $state . '"';
		}

		return 'Cannot write to this object: it was frozen by '
			. ($this->field(marker: $marker, key: 'by') ?? 'an unknown user')
			. ' on ' . ($this->field(marker: $marker, key: 'at') ?? 'an unrecorded date')
			. $because . '. Unfreeze it before changing it.';
	}//end refusal()

	/**
	 * The frozen marker of the record, or null when it carries none.
	 *
	 * @param mixed $record An object entity or an object array.
	 *
	 * @return array<string, mixed>|null The marker, or null.
	 *
	 * @spec openspec/changes/publication-withdrawal-aftercare/tasks.md#task-3.3
	 */
	private function marker(mixed $record): ?array {
		$marker = null;
		if (is_array($record) === true) {
			$marker = ($record['@self']['frozen'] ?? $record['frozen'] ?? null);
		}

		if (is_object($record) === true && is_callable([$record, 'getFrozen']) === true) {
			try {
				$marker = $record->getFrozen();
			} catch (Throwable) {
				$marker = null;
			}
		}

		if (is_array($marker) === false || $marker === []) {
			return null;
		}

		return $marker;
	}//end marker()

	/**
	 * One text field of the marker, or null when it is absent or empty.
	 *
	 * @param array<string, mixed> $marker The frozen marker.
	 * @param string               $key    The field.
	 *
	 * @return string|null The value, or null.
	 *
	 * @spec openspec/changes/publication-withdrawal-aftercare/tasks.md#task-3.3
	 */
	private function field(array $marker, string $key): ?string {
		$value = ($marker[$key] ?? null);
		if (is_string($value) === false || $value === '') {
			return null;
		}

		return $value;
	}//end field()
}//end class
