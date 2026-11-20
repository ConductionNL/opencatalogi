<?php

/**
 * Contract of PublishedTableService, for callers in other components.
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
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/open-data-table-query-and-dictionary/specs/open-data-tables/spec.md#requirement-anyone-queries-the-rows-of-a-public-table-req-odt-002
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Contract;

use OCP\IUser;

/**
 * Publishes a CSV attachment as a table and answers its rows and dictionary.
 *
 * @spec openspec/changes/open-data-table-query-and-dictionary/specs/open-data-tables/spec.md#requirement-anyone-queries-the-rows-of-a-public-table-req-odt-002
 */
interface PublishedTableServiceInterface {

	/**
	 * Publish a CSV attachment of a publication as a table.
	 *
	 * @param array<string, mixed> $publication The publication the file belongs to.
	 * @param TableFile $file The CSV on disk and its identity.
	 * @param IUser|null $user The editor.
	 *
	 * @return array<string, mixed> The stored table record.
	 *
	 * @spec openspec/changes/open-data-table-query-and-dictionary/specs/open-data-tables/spec.md#requirement-an-editor-publishes-a-csv-attachment-as-a-table-req-odt-001
	 */
	public function createFromFile(array $publication, TableFile $file, ?IUser $user = null): array;

	/**
	 * Find a table record.
	 *
	 * @param string $tableId The table id.
	 *
	 * @return array<string, mixed>|null The record, or null when there is none.
	 *
	 * @spec openspec/changes/open-data-table-query-and-dictionary/specs/open-data-tables/spec.md#requirement-anyone-queries-the-rows-of-a-public-table-req-odt-002
	 */
	public function findTable(string $tableId): ?array;

	/**
	 * Whether the caller may read a publication.
	 *
	 * @param string $publicationId The publication id.
	 *
	 * @return bool True when the caller can read it.
	 *
	 * @spec openspec/changes/open-data-table-query-and-dictionary/specs/open-data-tables/spec.md#requirement-anyone-queries-the-rows-of-a-public-table-req-odt-002
	 */
	public function canReadPublication(string $publicationId): bool;

	/**
	 * The data dictionary of a table.
	 *
	 * @param array<string, mixed> $table The table record.
	 *
	 * @return array<int, array<string, string>> The columns.
	 *
	 * @spec openspec/changes/open-data-table-query-and-dictionary/specs/open-data-tables/spec.md#requirement-readers-see-what-each-column-means-req-odt-003
	 */
	public function dictionary(array $table): array;

	/**
	 * The query parameters that filter on a column the table does not declare.
	 *
	 * @param array<string, mixed> $query The query parameters.
	 * @param array<string, mixed> $table The table record.
	 *
	 * @return array<int, string> The unknown column names.
	 *
	 * @spec openspec/changes/open-data-table-query-and-dictionary/specs/open-data-tables/spec.md#requirement-anyone-queries-the-rows-of-a-public-table-req-odt-002
	 */
	public function undeclaredFilters(array $query, array $table): array;

	/**
	 * One page of a table's rows.
	 *
	 * @param array<string, mixed> $table The table record.
	 * @param array<string, mixed> $query The query parameters.
	 *
	 * @return array<string, mixed> The rows with total, page and pages.
	 *
	 * @spec openspec/changes/open-data-table-query-and-dictionary/specs/open-data-tables/spec.md#requirement-anyone-queries-the-rows-of-a-public-table-req-odt-002
	 */
	public function rows(array $table, array $query): array;

	/**
	 * The ready tables of a publication, each with its rows and dictionary URLs.
	 *
	 * @param string $publicationId The publication.
	 * @param string $catalogSlug   The catalogue the URLs are under.
	 *
	 * @return array<int, array<string, mixed>> The tables: id, title, file, rowCount, rowsUrl, dictionaryUrl.
	 *
	 * @spec openspec/changes/open-data-table-query-and-dictionary/specs/open-data-tables/spec.md#requirement-readers-see-what-each-column-means-req-odt-003
	 *
	 * @contract-shift internal-only — PublishedTableService, in this app, is its only implementer; no other fleet app references the interface.
	 */
	public function tablesOf(string $publicationId, string $catalogSlug): array;

	/**
	 * Give each file published as a table its `dataDictionaryUrl`, for the DCAT distribution.
	 *
	 * @param array<int, array<string, mixed>> $files         formatFiles-shape file entries.
	 * @param string                           $publicationId The publication the files belong to.
	 * @param string                           $catalogSlug   The catalogue the URLs are under.
	 *
	 * @return array<int, array<string, mixed>> The files, a table's file with its dictionary URL.
	 *
	 * @spec openspec/changes/open-data-table-query-and-dictionary/specs/open-data-tables/spec.md#requirement-readers-see-what-each-column-means-req-odt-003
	 *
	 * @contract-shift internal-only — PublishedTableService, in this app, is its only implementer; no other fleet app references the interface.
	 */
	public function withDictionaryUrls(array $files, string $publicationId, string $catalogSlug): array;

	/**
	 * Publish a CSV attachment of a publication as a table; the caller checked the update right.
	 *
	 * @param object     $publication The publication as OpenRegister returned it.
	 * @param string     $fileId      The attachment's file id.
	 * @param IUser|null $user        The editor.
	 *
	 * @return array<string, mixed> The stored table record.
	 *
	 * @throws \InvalidArgumentException When the publication has no such attachment, or it is not a CSV.
	 *
	 * @spec openspec/changes/open-data-table-query-and-dictionary/specs/open-data-tables/spec.md#requirement-an-editor-publishes-a-csv-attachment-as-a-table-req-odt-001
	 *
	 * @contract-shift internal-only — PublishedTableService, in this app, is its only implementer; no other fleet app references the interface.
	 */
	public function publishAttachment(object $publication, string $fileId, ?IUser $user = null): array;

	/**
	 * Every table of a publication, whatever its status, for its editors.
	 *
	 * @param string $publicationId The publication.
	 *
	 * @return array<int, array<string, mixed>> The table records, each ready one with its `dictionary`.
	 *
	 * @spec openspec/changes/open-data-table-query-and-dictionary/specs/open-data-tables/spec.md#requirement-readers-see-what-each-column-means-req-odt-003
	 *
	 * @contract-shift internal-only — PublishedTableService, in this app, is its only implementer; no other fleet app references the interface.
	 */
	public function tablesFor(string $publicationId): array;

	/**
	 * Write the titles and descriptions an editor gave a table's columns.
	 *
	 * @param array<string, mixed>            $table   The table record.
	 * @param array<int, array<string,mixed>> $columns Each `{name, title?, description?}`.
	 *
	 * @return array<int, array<string, string>> The dictionary after the change.
	 *
	 * @throws \InvalidArgumentException When a column is not one the table declares.
	 *
	 * @spec openspec/changes/open-data-table-query-and-dictionary/specs/open-data-tables/spec.md#requirement-readers-see-what-each-column-means-req-odt-003
	 *
	 * @contract-shift internal-only — PublishedTableService, in this app, is its only implementer; no other fleet app references the interface.
	 */
	public function updateDictionary(array $table, array $columns): array;
}//end interface
