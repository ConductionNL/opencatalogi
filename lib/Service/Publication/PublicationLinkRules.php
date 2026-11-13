<?php

/**
 * Rules over a publication's relations and source identifiers, applied before it is saved.
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
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/publication-relations-place-and-source-ids/specs/publications/spec.md#requirement-a-publication-names-its-relation-to-another-publication-req-prs-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Publication;

/**
 * Pure rules: which relation property points a publication at itself, and the
 * source identifiers with the case reference mirrored in.
 *
 * @spec openspec/changes/publication-relations-place-and-source-ids/specs/publications/spec.md#requirement-a-publication-names-its-relation-to-another-publication-req-prs-001
 */
final class PublicationLinkRules {

	/**
	 * The relation properties of a publication, in the order they are checked.
	 */
	public const RELATION_PROPERTIES = ['replaces', 'amends', 'implements', 'relatedTo'];

	/**
	 * The system a mirrored case reference is filed under.
	 */
	public const CASE_SYSTEM = 'case';

	/**
	 * The first relation property that holds the publication's own id, or null.
	 *
	 * An item may be the id itself or an object carrying `id` or `uuid`.
	 *
	 * @param array<string, mixed> $data The publication.
	 * @param string $publicationId The publication's own id; empty for a new one.
	 *
	 * @return string|null The property name.
	 *
	 * @spec openspec/changes/publication-relations-place-and-source-ids/specs/publications/spec.md#requirement-a-publication-names-its-relation-to-another-publication-req-prs-001
	 */
	public function selfRelation(array $data, string $publicationId): ?string {
		if ($publicationId === '') {
			return null;
		}

		foreach (self::RELATION_PROPERTIES as $property) {
			$items = ($data[$property] ?? []);
			if (is_array($items) === false) {
				$items = [$items];
			}

			foreach ($items as $item) {
				if (self::itemId(item: $item) === $publicationId) {
					return $property;
				}
			}
		}

		return null;
	}//end selfRelation()

	/**
	 * The source identifiers with a non-empty case reference mirrored in as a private `case` entry.
	 *
	 * Never removes an entry. Returns null when nothing changes, so a caller
	 * only writes when there is something to write.
	 *
	 * @param array<string, mixed> $data The publication.
	 *
	 * @return array<int, array<string, mixed>>|null The new list, or null when unchanged.
	 *
	 * @spec openspec/changes/publication-relations-place-and-source-ids/specs/publications/spec.md#requirement-a-publication-carries-its-source-identifiers-searchable-req-prs-003
	 */
	public function withMirroredCaseReference(array $data): ?array {
		$reference = ($data['caseReference'] ?? null);
		if (is_string($reference) === false || trim($reference) === '') {
			return null;
		}

		$identifiers = self::identifiers(value: ($data['sourceIdentifiers'] ?? []));
		foreach ($identifiers as $entry) {
			if (($entry['system'] ?? null) === self::CASE_SYSTEM && ($entry['identifier'] ?? null) === $reference) {
				return null;
			}
		}

		$identifiers[] = ['system' => self::CASE_SYSTEM, 'identifier' => $reference, 'public' => false];
		return $identifiers;
	}//end withMirroredCaseReference()

	/**
	 * The stored source identifiers as a list, dropping anything that is not an entry.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return array<int, array<string, mixed>> The entries.
	 */
	private static function identifiers(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		return array_values(array_filter($value, static fn (mixed $entry): bool => is_array($entry)));
	}//end identifiers()

	/**
	 * The id a relation item names.
	 *
	 * @param mixed $item The item: an id, or an object with `id` or `uuid`.
	 *
	 * @return string The id, empty when it names none.
	 */
	private static function itemId(mixed $item): string {
		if (is_array($item) === true) {
			$item = ($item['id'] ?? $item['uuid'] ?? '');
		}

		if (is_string($item) === false && is_int($item) === false) {
			return '';
		}

		return (string)$item;
	}//end itemId()
}//end class
