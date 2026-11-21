<?php
/**
 * The `sourceIdentifier` filter of the public publication list and search.
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
 * @spec openspec/changes/publication-relations-place-and-source-ids/specs/publications/spec.md#requirement-a-publication-carries-its-source-identifiers-searchable-req-prs-003
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Publication;

/**
 * Turns `sourceIdentifier=<system>:<identifier>` into OpenRegister's
 * `contains` filter on `sourceIdentifiers`, matching public entries only.
 *
 * A public query never carries a filter of the caller's own on
 * `sourceIdentifiers`: one would let an anonymous caller test which
 * publication holds a private identifier. A `sourceIdentifier` that is not
 * `<system>:<identifier>` matches nothing rather than everything.
 *
 * @spec openspec/changes/publication-relations-place-and-source-ids/specs/publications/spec.md#requirement-a-publication-carries-its-source-identifiers-searchable-req-prs-003
 */
final class SourceIdentifierFilter {

	/**
	 * The request parameter.
	 */
	public const PARAM = 'sourceIdentifier';

	/**
	 * The publication property it filters on.
	 */
	public const PROPERTY = 'sourceIdentifiers';

	/**
	 * OpenRegister's operator: the array holds one entry with every given key and value.
	 */
	public const OPERATOR = 'contains';

	/**
	 * The system and identifier a `sourceIdentifier` value names, or null when it names none.
	 *
	 * The value splits at its first colon, so an identifier may hold colons itself.
	 *
	 * @param mixed $value The parameter value.
	 *
	 * @return array{system: string, identifier: string}|null The pair.
	 *
	 * @spec openspec/changes/publication-relations-place-and-source-ids/specs/publications/spec.md#requirement-a-publication-carries-its-source-identifiers-searchable-req-prs-003
	 */
	public static function parse(mixed $value): ?array {
		if (is_string($value) === false || preg_match('/^([^:]*):(.*)\\z/s', $value, $parts) !== 1) {
			return null;
		}

		$pair = ['system' => trim($parts[1]), 'identifier' => trim($parts[2])];
		if (in_array('', $pair, true) === true) {
			return null;
		}

		return $pair;
	}//end parse()

	/**
	 * The query for a public list or search: the caller's own filters on the
	 * property dropped, and the parameter as a match on a public entry.
	 *
	 * @param array<string, mixed> $query  The query built from the request.
	 * @param array<string, mixed> $params The raw request parameters.
	 *
	 * @return array<string, mixed> The query to forward.
	 *
	 * @spec openspec/changes/publication-relations-place-and-source-ids/specs/publications/spec.md#requirement-a-publication-carries-its-source-identifiers-searchable-req-prs-003
	 */
	public static function forPublicQuery(array $query, array $params): array {
		unset($query[self::PARAM], $query[self::PROPERTY]);
		if (array_key_exists(self::PARAM, $params) === false) {
			return $query;
		}

		$pair = self::parse(value: $params[self::PARAM]);
		if ($pair === null) {
			// An empty operand: OpenRegister answers no row for it.
			$query[self::PROPERTY] = [self::OPERATOR => []];
			return $query;
		}

		$query[self::PROPERTY] = [self::OPERATOR => array_merge($pair, ['public' => true])];
		return $query;
	}//end forPublicQuery()

	/**
	 * The query for an internal list: a match on any entry, public or not.
	 *
	 * An internal caller lists under its own read rights, so a private
	 * identifier is a valid match there.
	 *
	 * @param array<string, mixed>                     $query The query built from the request.
	 * @param array{system: string, identifier: string} $pair  The parsed parameter.
	 *
	 * @return array<string, mixed> The query to forward.
	 *
	 * @spec openspec/changes/publication-relations-place-and-source-ids/tasks.md#task-2.3
	 */
	public static function forInternalQuery(array $query, array $pair): array {
		$query[self::PROPERTY] = [self::OPERATOR => ['system' => $pair['system'], 'identifier' => $pair['identifier']]];
		return $query;
	}//end forInternalQuery()

	/**
	 * Whether a publication carries an entry with exactly this system and identifier.
	 *
	 * Repeats the forwarded filter, so a backend that ignores the operator can
	 * never widen an answer.
	 *
	 * @param array<string, mixed>                     $publication The publication as read.
	 * @param array{system: string, identifier: string} $pair        The parsed parameter.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/publication-relations-place-and-source-ids/tasks.md#task-2.3
	 */
	public static function holds(array $publication, array $pair): bool {
		$entries = ($publication[self::PROPERTY] ?? []);
		if (is_array($entries) === false) {
			return false;
		}

		foreach ($entries as $entry) {
			if (is_array($entry) === true
				&& ($entry['system'] ?? null) === $pair['system']
				&& ($entry['identifier'] ?? null) === $pair['identifier']
			) {
				return true;
			}
		}

		return false;
	}//end holds()
}//end class
