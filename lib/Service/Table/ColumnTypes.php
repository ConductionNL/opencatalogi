<?php

/**
 * The columns of a table, guessed from its header and a sample of its rows.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Table
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
 * @spec openspec/changes/open-data-table-query-and-dictionary/specs/open-data-tables/spec.md#requirement-an-editor-publishes-a-csv-attachment-as-a-table-req-odt-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Table;

/**
 * Turns a header and sample rows into schema properties: one per column, with a
 * guessed type, the header as title, an empty description, and facetable when
 * the sample holds few distinct values.
 *
 * @spec openspec/changes/open-data-table-query-and-dictionary/specs/open-data-tables/spec.md#requirement-an-editor-publishes-a-csv-attachment-as-a-table-req-odt-001
 */
final class ColumnTypes {

	/**
	 * How many rows of the file the guess reads.
	 *
	 * @var integer
	 */
	public const SAMPLE_SIZE = 200;

	/**
	 * A column with fewer distinct values than this in the sample is facetable.
	 *
	 * @var integer
	 */
	public const FACET_LIMIT = 50;

	/**
	 * The schema properties of the given columns.
	 *
	 * @param array<int, string> $header The column names, in file order.
	 * @param array<int, array<string, mixed>> $rows The sample rows, keyed by column name.
	 *
	 * @return array<string, array<string, mixed>> The properties, keyed by column name.
	 *
	 * @spec openspec/changes/open-data-table-query-and-dictionary/specs/open-data-tables/spec.md#requirement-an-editor-publishes-a-csv-attachment-as-a-table-req-odt-001
	 */
	public static function properties(array $header, array $rows): array {
		$properties = [];
		foreach ($header as $column) {
			$name = trim((string)$column);
			if ($name === '' || isset($properties[$name]) === true) {
				continue;
			}

			$values = self::filledValues(rows: array_slice($rows, 0, self::SAMPLE_SIZE), column: $name);
			$properties[$name] = array_merge(
				['title' => $name, 'description' => ''],
				self::typeOf(values: $values),
				['facetable' => count(array_unique($values)) < self::FACET_LIMIT]
			);
		}

		return $properties;
	}//end properties()

	/**
	 * The non-empty values of one column, as trimmed strings.
	 *
	 * @param array<int, array<string, mixed>> $rows The sample rows.
	 * @param string $column The column name.
	 *
	 * @return array<int, string> The values.
	 *
	 * @spec openspec/changes/open-data-table-query-and-dictionary/specs/open-data-tables/spec.md#requirement-an-editor-publishes-a-csv-attachment-as-a-table-req-odt-001
	 */
	private static function filledValues(array $rows, string $column): array {
		$values = [];
		foreach ($rows as $row) {
			$value = ($row[$column] ?? null);
			if (is_scalar($value) === false) {
				continue;
			}

			$text = trim((string)$value);
			if ($text !== '') {
				$values[] = $text;
			}
		}

		return $values;
	}//end filledValues()

	/**
	 * The JSON schema type of a column: the narrowest one every value fits.
	 *
	 * Integer before number before boolean before date; a column with no value
	 * at all is text.
	 *
	 * @param array<int, string> $values The non-empty values.
	 *
	 * @return array<string, string> The `type`, and a `format` for dates.
	 *
	 * @spec openspec/changes/open-data-table-query-and-dictionary/specs/open-data-tables/spec.md#requirement-an-editor-publishes-a-csv-attachment-as-a-table-req-odt-001
	 */
	private static function typeOf(array $values): array {
		if ($values === []) {
			return ['type' => 'string'];
		}

		$checks = [
			'integer' => static fn (string $v): bool => preg_match('/^-?\d+$/', $v) === 1,
			'number' => static fn (string $v): bool => is_numeric($v),
			'boolean' => static fn (string $v): bool => in_array(strtolower($v), ['true', 'false'], true),
			'date' => static fn (string $v): bool => self::isDate(value: $v),
		];
		$schemaTypes = [
			'integer' => ['type' => 'integer'],
			'number' => ['type' => 'number'],
			'boolean' => ['type' => 'boolean'],
			'date' => ['type' => 'string', 'format' => 'date'],
		];

		foreach ($checks as $type => $fits) {
			if (count(array_filter($values, $fits)) === count($values)) {
				return $schemaTypes[$type];
			}
		}

		return ['type' => 'string'];
	}//end typeOf()

	/**
	 * Whether a value is a calendar date written as YYYY-MM-DD.
	 *
	 * @param string $value The value.
	 *
	 * @return boolean True for a real date.
	 *
	 * @spec openspec/changes/open-data-table-query-and-dictionary/specs/open-data-tables/spec.md#requirement-an-editor-publishes-a-csv-attachment-as-a-table-req-odt-001
	 */
	private static function isDate(string $value): bool {
		if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1) {
			return false;
		}

		return checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1]);
	}//end isDate()
}//end class
