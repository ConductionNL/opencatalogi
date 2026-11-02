<?php

/**
 * Resolves the configured catalog scope and the catalog search behind it.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service
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
 * @spec openspec/specs/catalogs/spec.md
 */

namespace OCA\OpenCatalogi\Service;

use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Reads the configured catalog register and schema, builds the catalog search
 * over them and collects the registers and schemas the found catalogs list.
 *
 * @spec openspec/specs/catalogs/spec.md
 */
class CatalogScopeResolver {

	/**
	 * The app whose config holds the catalog register and schema.
	 */
	private const APP_NAME = 'opencatalogi';

	/**
	 * Constructor for CatalogScopeResolver.
	 *
	 * @param IAppConfig      $config App configuration holding the catalog register and schema
	 * @param LoggerInterface $logger Logger for the refusal of an unconfigured scope
	 */
	public function __construct(
		private readonly IAppConfig $config,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The configured catalog schema and register, or null when either is empty.
	 *
	 * FAIL CLOSED. getCatalogBySlug() already refuses when either key is empty,
	 * and so does this lookup.
	 *
	 * An empty key does NOT fall through to "no scope" harmlessly. It is put
	 * into the query verbatim, and `??` only falls through on null, so
	 * MagicMapper receives '' and casts it: `find((int) '')` is `find(0)`,
	 * which resolves nothing, leaves $register/$schema null, and so SKIPS the
	 * `if ($register !== null && $schema !== null)` scoped branch entirely,
	 * falling through to the cross-table path. Combined with the `_rbac: false`
	 * catalog search, an unconfigured instance would run an UNSCOPED,
	 * RBAC-DISABLED sweep of every object on the instance in order to derive a
	 * catalog scope. That is the same degradation shape as #828
	 * (GET /api/listings): an unresolved scope becoming NO scope rather than
	 * a refusal.
	 *
	 * Null leads to an empty scope rather than an exception, because that is
	 * already the contract callers handle:
	 * PublicationService::setObjectServiceContext() checks
	 * `empty($allowedRegisters) || empty($allowedSchemas)` and returns
	 * without querying, explicitly to "refuse to do a platform-wide scan".
	 *
	 * The two reads sit directly above their guard on purpose, so a reviewer
	 * and hydra gate-50 read the site as guarded.
	 *
	 * @return array{schema: string, register: string}|null The scope, or null after logging the refusal.
	 *
	 * @spec openspec/specs/catalogs/spec.md
	 */
	public function configuredScope(): ?array {
		$scope = [
			'schema' => $this->config->getValueString(self::APP_NAME, 'catalog_schema', ''),
			'register' => $this->config->getValueString(self::APP_NAME, 'catalog_register', ''),
		];
		if ($scope['schema'] === '' || $scope['register'] === '') {
			$this->logger->error(
				'Catalog schema or register not configured — refusing to derive a catalog scope from an unscoped search',
				$scope
			);
			return null;
		}

		return $scope;

	}//end configuredScope()

	/**
	 * The catalog search over a configured scope.
	 *
	 * A UUID is matched on `@self.uuid`; any other id (such as a slug) on the
	 * catalog's own `slug` field.
	 *
	 * @param array{schema: string, register: string} $scope     The configured catalog schema and register.
	 * @param string|int|null                         $catalogId The catalog to narrow to, or null for all.
	 *
	 * @return array The query.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) Uuid::isValid() is a pure check on the id.
	 *
	 * @spec openspec/specs/catalogs/spec.md
	 */
	public function searchQuery(array $scope, null|string|int $catalogId): array {
		$query = [
			'@self' => [
				'register' => $scope['register'],
				'schema' => $scope['schema'],
			],
		];
		if ($catalogId === null) {
			return $query;
		}

		if (Uuid::isValid((string)$catalogId) === true) {
			$query['@self']['uuid'] = $catalogId;
			return $query;
		}

		$query['slug'] = (string)$catalogId;
		return $query;

	}//end searchQuery()

	/**
	 * Collect the unique values of one array-valued property across catalogs.
	 *
	 * @param array<int,mixed> $catalogs The catalog objects returned by the search.
	 * @param string           $property The array-valued property to collect ('registers' | 'schemas').
	 *
	 * @return array<int,mixed> The de-duplicated union of that property's values.
	 *
	 * @spec openspec/specs/catalogs/spec.md
	 */
	public function collectUnique(array $catalogs, string $property): array {
		$collected = [];

		foreach ($catalogs as $catalog) {
			$catalog = $catalog->jsonSerialize();
			if (isset($catalog[$property]) === true && is_array($catalog[$property]) === true) {
				$collected = array_merge($collected, $catalog[$property]);
			}
		}

		return array_unique($collected);

	}//end collectUnique()
}//end class
