<?php

/**
 * OpenCatalogi reads over the publication register.
 *
 * @category Controller
 * @package  OCA\OpenCatalogi\Controller
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

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\Service\Catalogue\CatalogueUnreadableException;

/**
 * Resolve a schema in the publication register and read every object of it.
 *
 * Consuming controllers MUST also use ResolvesRegisterConfiguration and
 * ReadsOpenRegisterResults, and expose a `ServiceCatalogueServiceInterface $objects`.
 *
 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md
 */
trait ReadsPublicationRegister {
	/**
	 * Resolve a register and schema pair for one of the publication register's schemas.
	 *
	 * @param string $schemaKey The `<thing>_schema` config key.
	 *
	 * @return array<string, string> The register and schema identifiers.
	 *
	 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md
	 */
	private function configurationFor(string $schemaKey): array {
		return $this->resolveRegisterConfiguration(registerKey: 'publication_register', schemaKey: $schemaKey);
	}//end configurationFor()

	/**
	 * Read every object of one schema, up to the `_limit` filter or 500.
	 *
	 * @param string $schemaKey The `<thing>_schema` config key.
	 * @param array<string, mixed> $filters Extra filters.
	 *
	 * @return array<int, array<string, mixed>> The objects.
	 *
	 * @throws CatalogueUnreadableException When OpenRegister cannot be reached.
	 *
	 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md
	 */
	private function readAll(string $schemaKey, array $filters = []): array {
		$config = $this->configurationFor(schemaKey: $schemaKey);
		$query = $filters;
		$query['@self'] = ['register' => $config['register'], 'schema' => $config['schema']];
		$query['_limit'] = ($filters['_limit'] ?? 500);

		$result = $this->objects->getObjectService()->searchObjectsPaginated($query, _rbac: false, _multitenancy: false);

		$objects = [];
		foreach (($result['results'] ?? []) as $object) {
			$objects[] = $this->asArray(object: $object);
		}

		return $objects;
	}//end readAll()
}//end trait
