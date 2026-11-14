<?php

/**
 * The CSV a table is published from.
 *
 * @category Contract
 * @package  OCA\OpenCatalogi\Contract
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

namespace OCA\OpenCatalogi\Contract;

/**
 * A CSV attachment: where it is on disk, its file id and its name.
 *
 * @spec openspec/changes/open-data-table-query-and-dictionary/specs/open-data-tables/spec.md#requirement-an-editor-publishes-a-csv-attachment-as-a-table-req-odt-001
 */
final class TableFile {

	/**
	 * Constructor.
	 *
	 * @param string $path The file on disk.
	 * @param string $id The attachment's file id.
	 * @param string $name The attachment's name.
	 */
	public function __construct(
		public readonly string $path,
		public readonly string $id,
		public readonly string $name,
	) {
	}//end __construct()
}//end class
