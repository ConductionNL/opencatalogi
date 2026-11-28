<?php

/**
 * Where a harvest feed's datasets land: the register and schema of its catalogue.
 *
 * OpenRegister does not know catalogues, so a feed's `targetRegister` and
 * `targetSchema` are worked out here from the catalogue the administrator
 * picks. The schema must be one of the catalogue's schemas; a feed for any
 * other schema is refused, naming it.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Harvest
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
 * @spec openspec/changes/harvest-feed-intake/specs/harvest-feed-intake/spec.md#requirement-a-harvest-feed-is-an-openregister-source-owned-by-opencatalogi-req-hfi-002
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Harvest;

use OCA\OpenCatalogi\Service\OpenRegisterLookup;
use OCP\IAppConfig;
use Throwable;

/**
 * Reads a catalogue's registers and schemas to target a feed.
 *
 * @spec openspec/changes/harvest-feed-intake/specs/harvest-feed-intake/spec.md#requirement-a-harvest-feed-is-an-openregister-source-owned-by-opencatalogi-req-hfi-002
 */
class HarvestFeedCatalogues {

	/**
	 * Constructor.
	 *
	 * @param IAppConfig         $config       Where the catalogue register and schema are configured.
	 * @param OpenRegisterLookup $openRegister OpenRegister's services.
	 */
	public function __construct(
		private readonly IAppConfig $config,
		private readonly OpenRegisterLookup $openRegister,
	) {
	}//end __construct()

	/**
	 * The register and schema a feed for this catalogue and schema writes to.
	 *
	 * @param string     $catalogId The catalogue uuid.
	 * @param string|int $schemaId  The schema the administrator picked.
	 *
	 * @return array{targetRegister: string, targetSchema: string} The target.
	 *
	 * @throws HarvestFeedRefused When the catalogue does not exist, or the schema is not one of its schemas.
	 *
	 * @spec openspec/changes/harvest-feed-intake/tasks.md#task-2.1
	 */
	public function target(string $catalogId, string|int $schemaId): array {
		$catalog = $this->catalogue(catalogId: $catalogId);
		if ($catalog === null) {
			throw new HarvestFeedRefused(status: 422, field: 'targetCatalog', message: 'Catalogue ' . $catalogId . ' does not exist.');
		}

		$schema = (int) $schemaId;
		if ((string) $schema !== (string) $schemaId || in_array($schema, $this->idsOf(value: ($catalog['schemas'] ?? [])), true) === false) {
			throw new HarvestFeedRefused(status: 422, field: 'targetSchema',
				message: 'Schema ' . $schemaId . ' is not one of the schemas of catalogue ' . $this->titleOf(catalog: $catalog, catalogId: $catalogId) . '.'
			);
		}

		$registers = $this->idsOf(value: ($catalog['registers'] ?? []));

		return [
			'targetRegister' => (string) $this->registerHolding(registers: $registers, schema: $schema, catalogId: $catalogId),
			'targetSchema' => (string) $schema,
		];
	}//end target()

	/**
	 * The catalogue's register that holds the schema: its only register, else
	 * the first whose schemas list it.
	 *
	 * @param array<int, int> $registers The catalogue's registers.
	 * @param int             $schema    The schema.
	 * @param string          $catalogId The catalogue, for the refusal.
	 *
	 * @return int The register.
	 *
	 * @throws HarvestFeedRefused When no register of the catalogue holds the schema.
	 */
	private function registerHolding(array $registers, int $schema, string $catalogId): int {
		if (count($registers) === 1) {
			return $registers[0];
		}

		foreach ($registers as $register) {
			if (in_array($schema, $this->schemasOfRegister(register: $register), true) === true) {
				return $register;
			}
		}

		throw new HarvestFeedRefused(status: 422, field: 'targetSchema',
			message: 'No register of catalogue ' . $catalogId . ' holds schema ' . $schema . '.'
		);
	}//end registerHolding()

	/**
	 * The schema ids a register holds, or none when it cannot be read.
	 *
	 * @param int $register The register.
	 *
	 * @return array<int, int> The schema ids.
	 */
	private function schemasOfRegister(int $register): array {
		try {
			$mapper = $this->openRegister->installedService(
				serviceId: 'OCA\OpenRegister\Db\RegisterMapper',
				unavailable: 'OpenRegister is not installed.'
			);
			$schemas = $mapper->find($register)->getSchemas();
		} catch (Throwable) {
			return [];
		}

		return $this->idsOf(value: $schemas);
	}//end schemasOfRegister()

	/**
	 * The positive ids in a list, or in a JSON list.
	 *
	 * @param mixed $value The list.
	 *
	 * @return array<int, int> The ids.
	 */
	private function idsOf(mixed $value): array {
		if (is_string($value) === true) {
			$value = json_decode($value, true);
		}

		if (is_array($value) === false) {
			return [];
		}

		$ids = [];
		foreach ($value as $id) {
			if ((is_int($id) === true || (is_string($id) === true && ctype_digit($id) === true)) && (int) $id > 0) {
				$ids[] = (int) $id;
			}
		}

		return array_values(array_unique($ids));
	}//end idsOf()

	/**
	 * The catalogue with this uuid, or null.
	 *
	 * @param string $catalogId The catalogue uuid.
	 *
	 * @return array<string, mixed>|null The catalogue.
	 *
	 * @throws HarvestFeedRefused When the catalogues cannot be read.
	 */
	private function catalogue(string $catalogId): ?array {
		$register = $this->config->getValueString('opencatalogi', 'catalog_register', '');
		$schema   = $this->config->getValueString('opencatalogi', 'catalog_schema', '');
		if ($register === '' || $schema === '') {
			throw new HarvestFeedRefused(status: 503, message: 'The catalogue register and schema are not configured.');
		}

		try {
			$result = $this->openRegister->installedObjectService()->searchObjectsPaginated(
				query: ['@self' => ['register' => $register, 'schema' => $schema], '_limit' => 1000],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			throw new HarvestFeedRefused(status: 503, message: 'The catalogues could not be read: ' . $e->getMessage());
		}

		foreach (($result['results'] ?? []) as $object) {
			$catalog = $this->asArray(object: $object);
			if (in_array($catalogId, [($catalog['id'] ?? null), ($catalog['uuid'] ?? null), ($catalog['@self']['id'] ?? null)], true) === true) {
				return $catalog;
			}
		}

		return null;
	}//end catalogue()

	/**
	 * An object as an array.
	 *
	 * @param mixed $object The object or array.
	 *
	 * @return array<string, mixed> The array.
	 */
	private function asArray(mixed $object): array {
		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$object = $object->jsonSerialize();
		}

		if (is_array($object) === true) {
			return $object;
		}

		return [];
	}//end asArray()

	/**
	 * A catalogue's title for a message.
	 *
	 * @param array<string, mixed> $catalog   The catalogue.
	 * @param string               $catalogId Its uuid, when it has no title.
	 *
	 * @return string The quoted title.
	 */
	private function titleOf(array $catalog, string $catalogId): string {
		$title = $catalog['title'] ?? null;
		if (is_string($title) === true && $title !== '') {
			return '"' . $title . '"';
		}

		return $catalogId;
	}//end titleOf()
}//end class
