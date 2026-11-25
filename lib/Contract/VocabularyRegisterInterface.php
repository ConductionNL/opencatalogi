<?php

/**
 * Contract of the vocabulary register as OpenCatalogi's value-list refresh uses it.
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
 * OpenRegister's vocabulary register: what a scheme holds, and an import into it.
 *
 * @spec openspec/changes/woo-value-lists-on-the-concept-register/specs/woo-compliance/spec.md#requirement-each-scheme-is-refreshed-from-its-national-source-daily-req-wvc-002
 */
interface VocabularyRegisterInterface {
	/**
	 * The uris of the concepts of a scheme that are not deprecated.
	 *
	 * @param string $schemeUri The scheme's uri.
	 *
	 * @return array<int, string> The uris; empty for a scheme the register does not hold yet.
	 *
	 * @throws \RuntimeException When OpenRegister's vocabulary cannot be read.
	 *
	 * @spec openspec/changes/woo-value-lists-on-the-concept-register/specs/woo-compliance/spec.md#requirement-each-scheme-is-refreshed-from-its-national-source-daily-req-wvc-002
	 */
	public function activeConceptUris(string $schemeUri): array;

	/**
	 * Import a SKOS JSON-LD document: concepts it lacks are deprecated, never deleted.
	 *
	 * @param array<string, mixed> $jsonLd A `{"@graph": [...]}` document with one scheme node.
	 *
	 * @return array{scheme: string, created: int, updated: int, unchanged: int, deprecated: int} The counts.
	 *
	 * @throws \RuntimeException When OpenRegister's vocabulary cannot be written.
	 *
	 * @spec openspec/changes/woo-value-lists-on-the-concept-register/specs/woo-compliance/spec.md#requirement-each-scheme-is-refreshed-from-its-national-source-daily-req-wvc-002
	 */
	public function import(array $jsonLd): array;

	/**
	 * The concepts of a scheme with their Dutch label, notation and whether they are deprecated.
	 *
	 * @param string $schemeUri The scheme's uri.
	 *
	 * @return array<string, array{label: string, notation: string, deprecated: bool}> Keyed by concept uri; empty for a scheme the register does not hold.
	 *
	 * @throws \RuntimeException When OpenRegister's vocabulary cannot be read.
	 *
	 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-the-administrator-activates-the-organisations-the-installation-publishes-for-req-wvl-005
	 */
	public function concepts(string $schemeUri): array;
}//end interface
