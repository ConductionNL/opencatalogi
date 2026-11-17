<?php

/**
 * OpenCatalogi Catalogue Feed Reader.
 *
 * Reads the records a catalogue's Atom feed carries: what an anonymous reader
 * may read under the schemas' own read rules, inside the catalogue, for every
 * caller. The feed is public, so a signed-in administrator who opens it sees
 * the same entries as a ketenpartner without an account (decision 138).
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Community
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
 * @spec openspec/specs/public-and-community-surface/spec.md#requirement-a-catalogues-activity-is-published-as-a-feed-req-pcs-105
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Community;

use OCA\OpenCatalogi\Contract\CatalogueFeedReaderInterface;
use OCA\OpenCatalogi\Contract\PublicationQueryServiceInterface;
use Psr\Log\LoggerInterface;

/**
 * Reads a catalogue's feed records as an anonymous reader.
 *
 * @spec openspec/specs/public-and-community-surface/spec.md#requirement-a-catalogues-activity-is-published-as-a-feed-req-pcs-105
 */
class CatalogueFeedReader implements CatalogueFeedReaderInterface {

	/**
	 * Constructor.
	 *
	 * @param PublicationQueryServiceInterface $queryService The read-rule guard and the catalogue query.
	 * @param LoggerInterface                  $logger       The logger.
	 */
	public function __construct(
		private readonly PublicationQueryServiceInterface $queryService,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The newest records of one catalogue that an anonymous reader may read.
	 *
	 * The read runs inside OpenRegister's anonymous scope, so the session is
	 * anonymous for the read-rule guard and for the read itself, and only the
	 * `public` read rules decide. An OpenRegister without that scope cannot
	 * read as an anonymous reader for a signed-in caller, so that caller gets
	 * an empty feed rather than one with their own drafts in it.
	 *
	 * @param array<string, mixed> $catalog       The catalogue (keys: schemas, registers).
	 * @param int                  $limit         How many records, newest first.
	 * @param object               $objectService OpenRegister ObjectService.
	 *
	 * @return array<string, mixed> The OpenRegister result envelope (`results`, `total`).
	 *
	 * @spec openspec/specs/public-and-community-surface/spec.md#requirement-a-catalogues-activity-is-published-as-a-feed-req-pcs-105
	 */
	public function read(array $catalog, int $limit, object $objectService): array {
		$operation = fn (): array => $this->readInScope(catalog: $catalog, limit: $limit, objectService: $objectService);

		if (method_exists($objectService, 'runAsAnonymous') === true) {
			return $objectService->runAsAnonymous($operation);
		}

		if ($this->queryService->isAnonymous() === true) {
			return $operation();
		}

		$this->logger->error(
			'This OpenRegister has no ObjectService::runAsAnonymous(), so the catalogue feed cannot read as an '
			.'anonymous reader for a signed-in caller and answers empty. Upgrade OpenRegister to restore it.'
		);

		return ['results' => [], 'total' => 0];

	}//end read()

	/**
	 * The read itself, run as an anonymous reader.
	 *
	 * @param array<string, mixed> $catalog       The catalogue.
	 * @param int                  $limit         How many records, newest first.
	 * @param object               $objectService OpenRegister ObjectService.
	 *
	 * @return array<string, mixed> The OpenRegister result envelope.
	 *
	 * @spec openspec/specs/public-and-community-surface/spec.md#requirement-a-catalogues-activity-is-published-as-a-feed-req-pcs-105
	 */
	private function readInScope(array $catalog, int $limit, object $objectService): array {
		$catalog = $this->queryService->applyCatalogReadRuleGuard(catalog: $catalog);

		// An empty `_schemas` would let OpenRegister search every table.
		if (($catalog['schemas'] ?? []) === []) {
			return ['results' => [], 'total' => 0];
		}

		$query = $this->queryService->buildCatalogSearchQuery(
			catalog: $catalog,
			queryParams: ['_limit' => $limit, '_order' => ['@self.updated' => 'desc']],
			objectService: $objectService
		);

		return $objectService->searchObjectsPaginated(
			query: $query,
			_rbac: true,
			_multitenancy: false
		);

	}//end readInScope()
}//end class
