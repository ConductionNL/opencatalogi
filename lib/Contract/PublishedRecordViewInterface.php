<?php

/**
 * Contract of PublishedRecordView, for callers in other components.
 *
 * @category Contract
 * @package  OCA\OpenCatalogi\Contract
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

namespace OCA\OpenCatalogi\Contract;

/**
 * Contract of PublishedRecordView, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 */
interface PublishedRecordViewInterface {
	/**
	 * A stored record as an anonymous reader may read it under the configured published collections.
	 *
	 * A record of a type no collection covers is answered unchanged: this
	 * reads only what the published-collection configuration decides. A record
	 * of a covered type that fails the collection's conditions is not public
	 * and answers null. Otherwise only the properties in the collection's
	 * anonymous set are present, beside the record's `id` and `@self`.
	 *
	 * @param array<string, mixed> $record The stored record.
	 *
	 * @return array<string, mixed>|null The anonymous view, or null when the record is not public.
	 *
	 * @throws \OCA\OpenCatalogi\Service\Publication\UnreadableRuleException When the configuration or a condition cannot be read.
	 *
	 * @spec openspec/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-a-record-type-is-readable-without-an-account-with-the-visible-parts-chosen-req-pin-101
	 */
	public function view(array $record): ?array;

	/**
	 * The anonymous views of a list of stored records, leaving out every record that is not public.
	 *
	 * @param array<int, mixed> $records The stored records; an entity is read through its serialised
	 *                                   properties, anything else that is not an array is left out.
	 *
	 * @return array<int, array<string, mixed>> The anonymous views, in the order given.
	 *
	 * @throws \OCA\OpenCatalogi\Service\Publication\UnreadableRuleException When the configuration or a condition cannot be read.
	 *
	 * @spec openspec/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-a-record-type-is-readable-without-an-account-with-the-visible-parts-chosen-req-pin-101
	 */
	public function viewList(array $records): array;

	/**
	 * Search the stored records of every configured published collection in plain words.
	 *
	 * Each collection that names its `register` is read from OpenRegister by
	 * that register and its record type as the schema. The words are matched
	 * against the anonymous views, never against the stored records, so a word
	 * that occurs only in a withheld property cannot return the record. Each
	 * result carries the dossier its record names.
	 *
	 * @param string $terms The words searched for.
	 *
	 * @return array<int, array<string, mixed>> The matching anonymous views.
	 *
	 * @throws \OCA\OpenCatalogi\Service\Publication\UnreadableRuleException When the configuration or a condition cannot be read.
	 *
	 * @spec openspec/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-the-public-searches-published-information-in-plain-words-req-pin-111
	 */
	public function searchStored(string $terms): array;
}//end interface
