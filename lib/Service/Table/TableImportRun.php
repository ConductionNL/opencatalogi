<?php

/**
 * One import of a CSV file into a published table's rows.
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

use OCA\OpenCatalogi\Contract\TableFile;
use OCP\IUser;

/**
 * The file an import reads, who runs it, and the row schema when it is already known.
 *
 * @spec openspec/changes/open-data-table-query-and-dictionary/specs/open-data-tables/spec.md#requirement-an-editor-publishes-a-csv-attachment-as-a-table-req-odt-001
 */
final class TableImportRun {

	/**
	 * Constructor.
	 *
	 * @param TableFile   $file       The CSV file the rows are read from.
	 * @param IUser|null  $user       The user the import runs as.
	 * @param string|null $importedBy Who the table records as importer; null names the user.
	 * @param object|null $schema     The row schema; null reads it from the table record.
	 *
	 * @spec exclude pure framework plumbing: constructor of a value object.
	 */
	public function __construct(
		public readonly TableFile $file,
		public readonly ?IUser $user = null,
		public readonly ?string $importedBy = null,
		public readonly ?object $schema = null,
	) {
	}//end __construct()
}//end class
