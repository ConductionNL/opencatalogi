<?php

/**
 * Reads case type definitions from an external catalogue.
 *
 * The interface is deliberately narrow, and deliberately holds no transport.
 * Fetching is integriq's gateway; this app composes the ask and reads the
 * answer. An implementation that cannot reach its source raises
 * ExternalCatalogueUnreachableException and never answers an empty list.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Catalogue
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Catalogue;

/**
 * The read side of an external case type catalogue.
 */
interface CaseTypeSourceReader {

	/**
	 * Read one definition from the source.
	 *
	 * @param string $sourceId The registered external catalogue.
	 * @param string $externalId The identifier of the definition at the source.
	 *
	 * @return array<string, mixed> The definition, carrying at least `identifier` and `version`.
	 *
	 * @throws ExternalCatalogueUnreachableException When the source cannot be asked.
	 *
	 * @spec openspec/changes/published-service-and-case-type-catalogue/specs/published-service-and-case-type-catalogue/spec.md#requirement-case-types-are-imported-from-a-published-external-catalogue-and-resynchronised-req-psc-103
	 */
	public function fetchDefinition(string $sourceId, string $externalId): array;

	/**
	 * List the definitions the source offers.
	 *
	 * @param string $sourceId The registered external catalogue.
	 *
	 * @return array<int, array<string, mixed>> The definitions.
	 *
	 * @throws ExternalCatalogueUnreachableException When the source cannot be asked.
	 *
	 * @spec openspec/changes/published-service-and-case-type-catalogue/specs/published-service-and-case-type-catalogue/spec.md#requirement-case-types-are-imported-from-a-published-external-catalogue-and-resynchronised-req-psc-103
	 */
	public function listDefinitions(string $sourceId): array;
}//end interface
