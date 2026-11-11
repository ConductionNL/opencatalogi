<?php

/**
 * Collects the accepted and refused entries of one withheld-documents call.
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
 * The entries of one call as they are resolved, in order: each position is
 * accepted once, and a later entry for an accepted position is refused as
 * invalid. Built and read by WithheldDocuments only.
 *
 * @spec openspec/changes/woo-decision-shows-what-was-withheld/specs/publications/spec.md#requirement-dossiq-records-the-withheld-documents-through-one-named-method-req-wdw-002
 */
final class WithheldResolution {

	/**
	 * The accepted entries, in order.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $accepted = [];

	/**
	 * The refused entries, in order.
	 *
	 * @var list<array{position: int, code: string, reason: string}>
	 */
	private array $refused = [];

	/**
	 * The positions accepted so far.
	 *
	 * @var array<int, true>
	 */
	private array $positions = [];

	/**
	 * Add one resolved entry: a refusal stays refused, a repeat of an accepted
	 * position is refused as invalid, anything else is accepted.
	 *
	 * @param array{position: int, grounds: list<string>} $entry The reduced entry.
	 * @param array<string, mixed> $resolved `['entry' => ...]` or `['refused' => ...]`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-decision-shows-what-was-withheld/specs/publications/spec.md#requirement-dossiq-records-the-withheld-documents-through-one-named-method-req-wdw-002
	 */
	public function add(array $entry, array $resolved): void {
		if (isset($resolved['refused']) === true) {
			$this->refused[] = $resolved['refused'];
			return;
		}

		if (isset($this->positions[$entry['position']]) === true) {
			$this->refused[] = ['position' => $entry['position'], 'code' => $entry['grounds'][0], 'reason' => WithheldDocuments::REASON_INVALID_ENTRY];
			return;
		}

		$this->positions[$entry['position']] = true;
		$this->accepted[] = $resolved['entry'];
	}//end add()

	/**
	 * The accepted and the refused entries.
	 *
	 * @return array{0: list<array<string, mixed>>, 1: list<array{position: int, code: string, reason: string}>}
	 *
	 * @spec openspec/changes/woo-decision-shows-what-was-withheld/specs/publications/spec.md#requirement-dossiq-records-the-withheld-documents-through-one-named-method-req-wdw-002
	 */
	public function result(): array {
		return [$this->accepted, $this->refused];
	}//end result()
}//end class
