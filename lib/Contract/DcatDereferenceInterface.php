<?php

/**
 * Contract of DcatDereference, for callers in other components.
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

use OCP\AppFramework\Http\Response;

/**
 * Contract of DcatDereference, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 */
interface DcatDereferenceInterface {
	/**
	 * Answer a dataset IRI in a representation the Accept header named.
	 *
	 * @param array<string, mixed> $catalog     The guarded catalogue.
	 * @param string               $catalogSlug The catalogue slug.
	 * @param string               $id          The publication uuid from the IRI.
	 * @param string               $kind        `turtle`, `rdfxml` or `html`.
	 *
	 * @return Response The graph, the redirect, or a 404.
	 *
	 * @spec openspec/changes/dcat-catalogue-completion/specs/dcat-ap-harvest/spec.md#requirement-the-catalogue-is-served-as-linked-data-and-through-sparql-req-dcc-003
	 */
	public function answer(array $catalog, string $catalogSlug, string $id, string $kind): Response;
}//end interface
