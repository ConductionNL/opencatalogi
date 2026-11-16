<?php

/**
 * Contract of PublicDocuments, for callers in other components.
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

use OCP\AppFramework\Http\JSONResponse;

/**
 * Contract of PublicDocuments, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 */
interface PublicDocumentsInterface {
	/**
	 * An attachment list with each document's id, metadata and links added.
	 *
	 * @param JSONResponse $response      The attachment list as the publication service answered it.
	 * @param string|null  $catalogSlug   The catalogue it was read through, or null to find it.
	 * @param string       $publicationId The publication.
	 *
	 * @return JSONResponse The list, unchanged when it is not a local answer.
	 *
	 * @spec openspec/changes/publication-detail-for-the-portal/specs/publications/spec.md#requirement-a-document-has-a-stable-id-and-its-own-metadata-req-pdp-002
	 */
	public function describe(JSONResponse $response, ?string $catalogSlug, string $publicationId): JSONResponse;

	/**
	 * One public document of a publicly readable publication.
	 *
	 * @param string $catalogSlug   The catalogue.
	 * @param string $publicationId The publication.
	 * @param string $documentId    The Nextcloud file id.
	 *
	 * @return array{publication: array<string, mixed>, document: array<string, mixed>, slug: string}|null The document, or null
	 *         when it is not a public attachment of a live publication.
	 *
	 * @spec openspec/changes/publication-detail-for-the-portal/specs/publications/spec.md#requirement-a-document-has-a-stable-id-and-its-own-metadata-req-pdp-002
	 */
	public function find(string $catalogSlug, string $publicationId, string $documentId): ?array;

	/**
	 * A found document's metadata with the publication it belongs to.
	 *
	 * @param array{publication: array<string, mixed>, document: array<string, mixed>, slug: string} $found What find() answered.
	 *
	 * @return array<string, mixed> The public keys of the document and `publication: {id, title, url}`.
	 *
	 * @spec openspec/changes/publication-detail-for-the-portal/specs/publications/spec.md#requirement-a-document-has-a-stable-id-and-its-own-metadata-req-pdp-002
	 */
	public function metadata(array $found): array;

	/**
	 * A found document as one `diwoo:Document`, ready for XMLResponse.
	 *
	 * @param array{publication: array<string, mixed>, document: array<string, mixed>, slug: string} $found What find() answered.
	 *
	 * @return array<string, mixed> The XML data, rooted at `diwoo:Document`.
	 *
	 * @spec openspec/changes/publication-detail-for-the-portal/specs/publications/spec.md#requirement-a-document-has-a-stable-id-and-its-own-metadata-req-pdp-002
	 */
	public function diwoo(array $found): array;
}//end interface
