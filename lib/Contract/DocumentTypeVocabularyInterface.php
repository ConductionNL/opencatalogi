<?php

/**
 * Contract of the DiWoo document type and language lists, for callers in other components.
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
 *
 * @spec openspec/changes/diwoo-metadata-on-the-publication/specs/woo-compliance/spec.md#requirement-a-document-type-comes-from-the-national-documentsoorten-list-req-dwp-002
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Contract;

/**
 * The bundled DiWoo documentsoorten and language lists (implemented by TooiVocabularyService).
 * Callers outside Service/ depend on this interface instead of on the class.
 */
interface DocumentTypeVocabularyInterface {
	/**
	 * The member of the documentsoorten list a URI, code or exact label names (case-insensitive).
	 *
	 * @param string|null $value The value given.
	 *
	 * @return array{code: string, label: string, uri: string}|null The member, or null for none.
	 *
	 * @spec openspec/changes/diwoo-metadata-on-the-publication/specs/woo-compliance/spec.md#requirement-a-document-type-comes-from-the-national-documentsoorten-list-req-dwp-002
	 */
	public function resolveDocumentsoort(?string $value): ?array;

	/**
	 * Every member of the documentsoorten list, in XSD order.
	 *
	 * @return array<int, array{code: string, label: string, uri: string}> The members.
	 *
	 * @spec openspec/changes/diwoo-metadata-on-the-publication/specs/woo-compliance/spec.md#requirement-a-document-type-comes-from-the-national-documentsoorten-list-req-dwp-002
	 */
	public function documentsoortList(): array;

	/**
	 * The member of the language list a URI, code or exact label names (case-insensitive).
	 *
	 * @param string|null $value The value given.
	 *
	 * @return array{code: string, label: string, uri: string}|null The member, or null for none.
	 *
	 * @spec openspec/changes/diwoo-metadata-on-the-publication/specs/woo-compliance/spec.md#requirement-a-document-type-comes-from-the-national-documentsoorten-list-req-dwp-002
	 */
	public function resolveLanguage(?string $value): ?array;

	/**
	 * Every member of the language list, in XSD order.
	 *
	 * @return array<int, array{code: string, label: string, uri: string}> The members.
	 *
	 * @spec openspec/changes/diwoo-metadata-on-the-publication/specs/woo-compliance/spec.md#requirement-a-document-type-comes-from-the-national-documentsoorten-list-req-dwp-002
	 */
	public function languageList(): array;
}//end interface
