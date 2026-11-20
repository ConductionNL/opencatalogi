<?php

/**
 * Contract of DocumentReferences, for callers in other components.
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
 * Contract of DocumentReferences, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 *
 * @spec openspec/changes/integration-publish-by-reference/specs/publication-document-references/spec.md#requirement-broken-references-are-shown-before-readers-find-them-req-pbr-003
 */
interface DocumentReferencesInterface {

	/**
	 * The app config key that names the document reference schema.
	 */
	public const SCHEMA_KEY = 'document_reference_schema';

	/**
	 * Every reference inside its window, up to a cap, for the daily check.
	 *
	 * @param int $cap The most references to return.
	 *
	 * @return list<array<string, mixed>> The references, with `id`.
	 *
	 * @spec openspec/changes/integration-publish-by-reference/specs/publication-document-references/spec.md#requirement-broken-references-are-shown-before-readers-find-them-req-pbr-003
	 */
	public function inWindow(int $cap): array;

	/**
	 * Write the result of a check onto a reference.
	 *
	 * @param array<string, mixed> $reference The reference as read, with `id`.
	 * @param bool                 $reachable Whether the source answered.
	 * @param int                  $status    The HTTP status, 0 without an answer.
	 * @param string               $checkedAt When it was checked (ISO 8601).
	 *
	 * @return bool Whether it was written.
	 *
	 * @spec openspec/changes/integration-publish-by-reference/specs/publication-document-references/spec.md#requirement-broken-references-are-shown-before-readers-find-them-req-pbr-003
	 */
	public function recordCheck(array $reference, bool $reachable, int $status, string $checkedAt): bool;
}//end interface
