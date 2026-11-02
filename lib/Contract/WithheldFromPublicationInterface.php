<?php

/**
 * Contract of WithheldFromPublication, for callers in other components.
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
 * Contract of WithheldFromPublication, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 */
interface WithheldFromPublicationInterface {
	/**
	 * The response with `withheld` added when the catalogue opted in, unchanged otherwise.
	 *
	 * @param array<string, mixed> $response      The rendered publication.
	 * @param array<string, mixed> $catalog       The catalogue it was read through.
	 * @param string               $publicationId The publication.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/publication-detail-for-the-portal/specs/publications/spec.md#requirement-a-catalogue-may-show-that-documents-were-withheld-and-why-req-pdp-004
	 */
	public function addForCatalog(array $response, array $catalog, string $publicationId): array;

	/**
	 * The response with `withheld` added when every catalogue holding the publication opted in.
	 *
	 * For a read without a catalogue (the federation endpoint). The catalogue is resolved from the
	 * publication's register and schema; none found means unresolved, and so no key.
	 *
	 * @param array<string, mixed> $response The rendered publication, with `@self`.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/woo-decision-shows-what-was-withheld/specs/publications/spec.md#requirement-the-public-read-of-a-woo-decision-names-what-was-withheld-and-why-req-wdw-003
	 */
	public function addForPublication(array $response): array;

	/**
	 * The merged list, ordered by position.
	 *
	 * @param string $publicationId The publication.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/changes/woo-decision-shows-what-was-withheld/specs/publications/spec.md#requirement-the-public-read-of-a-woo-decision-names-what-was-withheld-and-why-req-wdw-003
	 */
	public function entries(string $publicationId): array;
}//end interface
