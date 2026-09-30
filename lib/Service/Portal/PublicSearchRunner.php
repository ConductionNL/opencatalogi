<?php

/**
 * OpenCatalogi Public Search Runner.
 *
 * Runs a search through exactly the path `GET /api/search` uses,
 * PublicationQueryService::assemblePublicSearchResults(), which evaluates the
 * whole read inside OpenRegister's anonymous scope. The saved-search job uses
 * it so a resident is never told about something an anonymous visitor could
 * not have found (hydra `woo-citizen-journey`, C2).
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Portal
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @version GIT: <git_id>
 * @link https://www.OpenCatalogi.nl
 * @spec openspec/changes/saved-searches-and-alerts/specs/saved-searches/spec.md#requirement-matching-finds-only-publications-the-resident-could-have-found-new-since-the-last-run-req-ssa-002
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Portal;

use OCA\OpenCatalogi\Service\PublicationQueryService;
use Psr\Container\ContainerInterface;

/**
 * The public, anonymous search, callable from a background job.
 *
 * @spec openspec/changes/saved-searches-and-alerts/specs/saved-searches/spec.md#requirement-matching-finds-only-publications-the-resident-could-have-found-new-since-the-last-run-req-ssa-002
 */
class PublicSearchRunner {

	/**
	 * Constructor.
	 *
	 * @param PublicationQueryService $queries   The public search.
	 * @param ContainerInterface      $container Resolves OpenRegister.
	 */
	public function __construct(
		private readonly PublicationQueryService $queries,
		private readonly ContainerInterface $container,
	) {

	}//end __construct()

	/**
	 * The result rows of a public search.
	 *
	 * @param array<string, mixed> $params The search parameters, as `/api/search` takes them.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/saved-searches-and-alerts/specs/saved-searches/spec.md#requirement-matching-finds-only-publications-the-resident-could-have-found-new-since-the-last-run-req-ssa-002
	 */
	public function search(array $params): array {
		$result = $this->queries->assemblePublicSearchResults(
			queryParams: $params,
			objectService: $this->container->get('OCA\OpenRegister\Service\ObjectService')
		);

		return array_values(array_filter((array)($result['results'] ?? []), 'is_array'));

	}//end search()
}//end class
