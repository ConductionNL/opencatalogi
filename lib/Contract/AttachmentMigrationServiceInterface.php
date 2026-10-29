<?php

/**
 * Contract of AttachmentMigrationService, for callers in other components.
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
 * Contract of AttachmentMigrationService, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 */
interface AttachmentMigrationServiceInterface {
	/**
	 * The register the publication and document schemas live in.
	 */
	public const REGISTER_SLUG = 'publication';

	/**
	 * The schema being retired.
	 */
	public const DOCUMENT_SCHEMA_SLUG = 'document';

	/**
	 * The publication uuid a document row points at.
	 *
	 * The link was written three different ways over the schema's life: a bare
	 * uuid string, an object carrying `id`, and an object carrying only `slug`
	 * and `title`. All three are live on real data, so all three resolve here.
	 * A slug is returned as a slug and looked up separately, because a slug is
	 * not a uuid and treating one as the other silently finds nothing.
	 *
	 * @param mixed $link The `publication` property as stored.
	 *
	 * @return array{id: string|null, slug: string|null} What the link identifies.
	 *
	 * @spec openspec/changes/attachments-are-files/specs/publication-attachments/spec.md#requirement-an-attachment-is-a-file-on-the-publication-req-att-101
	 */
	public function readPublicationLink(mixed $link): array;

	/**
	 * The file metadata a document's fields become.
	 *
	 * `summary` and `description` both land in the file's single description
	 * field, joined rather than one overwriting the other: they are two pieces
	 * of prose about the same attachment and dropping either loses text a
	 * publisher wrote.
	 *
	 * @param array<string, mixed> $document The document's fields.
	 *
	 * @return array{description: string|null, labels: array<int, string>, published: string|null, depublished: string|null}
	 *         The metadata to write onto the file.
	 *
	 * @spec openspec/changes/attachments-are-files/specs/publication-attachments/spec.md#requirement-the-documents-metadata-lands-on-the-file-req-att-102
	 */
	public function fileMetadataFor(array $document): array;

	/**
	 * Read a stored date, returning null for anything unusable.
	 *
	 * An unparseable date becomes null rather than "now". A window that starts
	 * at the migration's own runtime would publish every attachment the moment
	 * this command ran, which is the opposite of preserving what was set.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return string|null An ISO-8601 string, or null.
	 *
	 * @spec openspec/changes/attachments-are-files/specs/publication-attachments/spec.md#requirement-the-documents-metadata-lands-on-the-file-req-att-102
	 */
	public function readDate(mixed $value): ?string;

	/**
	 * Load the publication a document points at.
	 *
	 * @param object                                  $objectService The OpenRegister ObjectService.
	 * @param array{id: string|null, slug: string|null} $link        What the document's link identifies.
	 *
	 * @return object|null The publication, or null when it cannot be found.
	 *
	 * @spec openspec/changes/attachments-are-files/specs/publication-attachments/spec.md#requirement-an-attachment-is-a-file-on-the-publication-req-att-101
	 */
	public function findPublication(object $objectService, array $link): ?object;

	/**
	 * The slug a row carries, from wherever it is stored.
	 *
	 * It lives in the `@self` metadata block, and older shapes also carry it at
	 * the top level. Reading only one of the two silently matches nothing.
	 *
	 * @param mixed $row The row.
	 *
	 * @return string The slug, or an empty string.
	 *
	 * @spec openspec/changes/attachments-are-files/specs/publication-attachments/spec.md#requirement-an-attachment-is-a-file-on-the-publication-req-att-101
	 */
	public function slugOf(mixed $row): string;

	/**
	 * Load the document as an entity, which is what the file API takes.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $uuid          The document uuid.
	 *
	 * @return object The document entity.
	 *
	 * @spec openspec/changes/attachments-are-files/specs/publication-attachments/spec.md#requirement-an-attachment-is-a-file-on-the-publication-req-att-101
	 */
	public function documentEntity(object $objectService, string $uuid): object;

	/**
	 * Resolve a search row to an entity.
	 *
	 * @param mixed  $row           The row as returned by the reader.
	 * @param object $objectService The OpenRegister ObjectService.
	 *
	 * @return object|null The entity, or null.
	 *
	 * @spec openspec/changes/attachments-are-files/specs/publication-attachments/spec.md#requirement-an-attachment-is-a-file-on-the-publication-req-att-101
	 */
	public function entityOf(mixed $row, object $objectService): ?object;

	/**
	 * Flatten an object row into a field map with the uuid resolved.
	 *
	 * @param mixed $row The row as the reader returned it.
	 *
	 * @return array<string, mixed> The fields.
	 *
	 * @spec openspec/changes/attachments-are-files/specs/publication-attachments/spec.md#requirement-an-attachment-is-a-file-on-the-publication-req-att-101
	 */
	public function toFields(mixed $row): array;

	/**
	 * Resolve an OpenRegister collaborator, or null when it is unavailable.
	 *
	 * Resolved lazily and behind a guard: a hard constructor dependency on an
	 * OpenRegister class means that removing that class fatals every write in
	 * this app, not just this migration.
	 *
	 * @param string $id The service identifier.
	 *
	 * @return object|null The service, or null.
	 *
	 * @spec openspec/changes/attachments-are-files/specs/publication-attachments/spec.md#requirement-an-attachment-is-a-file-on-the-publication-req-att-101
	 */
	public function openRegister(string $id): ?object;
}//end interface
