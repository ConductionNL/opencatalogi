<?php

/**
 * A CSV document for spreadsheet use.
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

/**
 * Renders rows as UTF-8 CSV with a BOM, so spreadsheet apps detect the encoding.
 *
 * @spec openspec/specs/woo-transparency/spec.md#requirement-inventarislijst-generation
 */
final class BomCsv {

	/**
	 * Constructor.
	 *
	 * @param array<string, string> $columns Row key => column header, in column order.
	 *
	 * @spec openspec/specs/woo-transparency/spec.md#requirement-inventarislijst-generation
	 */
	public function __construct(
		private readonly array $columns,
	) {
	}//end __construct()

	/**
	 * The CSV document: the BOM, the header row and one line per row; a missing value is empty.
	 *
	 * @param iterable<array<string, mixed>> $rows The rows.
	 *
	 * @return string The CSV.
	 *
	 * @spec openspec/specs/woo-transparency/spec.md#requirement-inventarislijst-generation
	 */
	public function render(iterable $rows): string {
		$handle = fopen('php://temp', 'r+');
		fwrite($handle, "\xEF\xBB\xBF");
		fputcsv($handle, array_values($this->columns));
		foreach ($rows as $row) {
			fputcsv($handle, $this->lineOf(row: $row));
		}

		return $this->drain(handle: $handle);
	}//end render()

	/**
	 * One row as CSV cells, in column order, a missing value as an empty cell.
	 *
	 * @param array<string, mixed>|\ArrayAccess $row The row.
	 *
	 * @return array<int, string> The cells.
	 */
	private function lineOf(mixed $row): array {
		$line = [];
		foreach (array_keys($this->columns) as $key) {
			$line[] = (string)($row[$key] ?? '');
		}

		return $line;
	}//end lineOf()

	/**
	 * Everything written to the stream, which is closed afterwards.
	 *
	 * @param resource $handle The stream.
	 *
	 * @return string The CSV, or an empty string when it cannot be read back.
	 */
	private function drain(mixed $handle): string {
		rewind($handle);
		$csv = stream_get_contents($handle);
		fclose($handle);

		if ($csv === false) {
			return '';
		}

		return $csv;
	}//end drain()
}//end class
