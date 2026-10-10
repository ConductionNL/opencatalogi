<?php

/**
 * The OpenRegister reads behind the public `withheld` list.
 *
 * Three reads, all on the server's behalf because the rows they read are
 * admin-only: the `withheldDocument` rows dossiq recorded for a publication
 * (REQ-WDW-002), the assessments of the `wooBatch` a publication was made from
 * (REQ-PDP-004), and the catalogues that hold a publication's register and
 * schema. Only `WithheldFromPublication` calls these, and it returns a fixed subset of
 * keys; nothing read here reaches a response as it is.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Withheld
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-decision-shows-what-was-withheld/specs/publications/spec.md#requirement-the-public-read-of-a-woo-decision-names-what-was-withheld-and-why-req-wdw-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Withheld;

use OCA\OpenCatalogi\Service\WooService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Throwable;

/**
 * Reads the stored withheld entries, a publication's batch assessments and the catalogues holding it.
 *
 * @spec openspec/changes/woo-decision-shows-what-was-withheld/specs/publications/spec.md#requirement-the-public-read-of-a-woo-decision-names-what-was-withheld-and-why-req-wdw-003
 */
class WithheldFromPublicationStore {

	/**
	 * Constructor.
	 *
	 * @param IAppConfig         $config     Holds the register and schema ids.
	 * @param ContainerInterface $container  Resolves OpenRegister's ObjectService.
	 * @param IAppManager        $appManager Says whether OpenRegister is there.
	 */
	public function __construct(
		private readonly IAppConfig $config,
		private readonly ContainerInterface $container,
		private readonly IAppManager $appManager,
	) {
	}//end __construct()

	/**
	 * The `withheldDocument` rows of one publication.
	 *
	 * @param string $publicationId The publication.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @throws RuntimeException When OpenRegister or the configuration is not there.
	 *
	 * @spec openspec/changes/woo-decision-shows-what-was-withheld/specs/publications/spec.md#requirement-the-public-read-of-a-woo-decision-names-what-was-withheld-and-why-req-wdw-003
	 */
	public function storedEntries(string $publicationId): array {
		$register = $this->configured(key: 'publication_register');
		$schema = $this->configured(key: 'withheld_document_schema');

		return array_values(
			array_filter(
				$this->search(register: $register, schema: $schema, filters: ['publication' => $publicationId]),
				static fn (array $row): bool => (string)($row['publication'] ?? '') === $publicationId
			)
		);
	}//end storedEntries()

	/**
	 * The assessments of the batch a publication was made from, in the batch's document order.
	 *
	 * An empty list when no batch names the publication or the Woo register is not configured:
	 * a publication from dossiq has no batch.
	 *
	 * @param string $publicationId The publication.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @throws RuntimeException When OpenRegister is not there.
	 *
	 * @spec openspec/changes/publication-detail-for-the-portal/specs/publications/spec.md#requirement-a-catalogue-may-show-that-documents-were-withheld-and-why-req-pdp-004
	 */
	public function batchAssessments(string $publicationId): array {
		$register = $this->optional(key: WooService::CONFIG_BATCH_REGISTER);
		$schema = $this->optional(key: WooService::CONFIG_BATCH_SCHEMA);
		if ($register === '' || $schema === '') {
			return [];
		}

		foreach ($this->search(register: $register, schema: $schema, filters: []) as $batch) {
			if ((string)($batch['wooPublication']['publication'] ?? '') !== $publicationId) {
				continue;
			}

			$assessments = [];
			foreach (array_values((array)($batch['documents'] ?? [])) as $ref) {
				$assessments[] = $this->findQuietly(id: (string)$ref);
			}

			return $assessments;
		}

		return [];
	}//end batchAssessments()

	/**
	 * The catalogues whose registers and schemas hold the given register and schema.
	 *
	 * @param string $register The publication's register.
	 * @param string $schema   The publication's schema.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @throws RuntimeException When OpenRegister or the catalogue configuration is not there.
	 *
	 * @spec openspec/changes/woo-decision-shows-what-was-withheld/specs/publications/spec.md#requirement-the-public-read-of-a-woo-decision-names-what-was-withheld-and-why-req-wdw-003
	 */
	public function catalogsHolding(string $register, string $schema): array {
		$catalogs = $this->search(
			register: $this->configured(key: 'catalog_register'),
			schema: $this->configured(key: 'catalog_schema'),
			filters: []
		);

		return array_values(
			array_filter(
				$catalogs,
				fn (array $catalog): bool => in_array($register, $this->ids(raw: $catalog['registers'] ?? []), true) === true
					&& in_array($schema, $this->ids(raw: $catalog['schemas'] ?? []), true) === true
			)
		);
	}//end catalogsHolding()

	/**
	 * Search one register and schema on the server's behalf.
	 *
	 * @param string               $register The register.
	 * @param string               $schema   The schema.
	 * @param array<string, mixed> $filters  Property filters.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @throws RuntimeException When OpenRegister is not there.
	 */
	private function search(string $register, string $schema, array $filters): array {
		$result = $this->objects()->searchObjectsPaginated(
			query: array_merge(['@self' => ['register' => $register, 'schema' => $schema], '_limit' => 1000], $filters),
			_rbac: false,
			_multitenancy: false
		);

		return array_map(fn (mixed $row): array => $this->asArray(object: $row), array_values((array)($result['results'] ?? [])));
	}//end search()

	/**
	 * One object by id, or an empty array when it cannot be read.
	 *
	 * @param string $id The object id.
	 *
	 * @return array<string, mixed>
	 */
	private function findQuietly(string $id): array {
		try {
			return $this->asArray(object: $this->objects()->find(id: $id, _rbac: false, _multitenancy: false));
		} catch (Throwable $e) {
			return [];
		}
	}//end findQuietly()

	/**
	 * OpenRegister's ObjectService, or a refusal naming why it is not there.
	 *
	 * @return object
	 *
	 * @throws RuntimeException When OpenRegister is not available.
	 */
	private function objects(): object {
		if ($this->appManager->isInstalled('openregister') === false) {
			throw new RuntimeException('OpenRegister is not available, so the withheld documents cannot be read.');
		}

		$objects = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		if (is_object($objects) === false) {
			throw new RuntimeException('OpenRegister is not available, so the withheld documents cannot be read.');
		}

		return $objects;
	}//end objects()

	/**
	 * A configured id, or a refusal naming the key.
	 *
	 * @param string $key The app config key.
	 *
	 * @return string
	 *
	 * @throws RuntimeException When the key is empty.
	 */
	private function configured(string $key): string {
		$value = $this->optional(key: $key);
		if ($value === '') {
			throw new RuntimeException('Register configuration is not set for ' . $key . '.');
		}

		return $value;
	}//end configured()

	/**
	 * A configured id, or an empty string.
	 *
	 * @param string $key The app config key.
	 *
	 * @return string
	 */
	private function optional(string $key): string {
		return trim($this->config->getValueString('opencatalogi', $key, ''));
	}//end optional()

	/**
	 * A list of ids from a catalogue field, as strings.
	 *
	 * @param mixed $raw The field: a list, or a JSON string of one.
	 *
	 * @return list<string>
	 */
	private function ids(mixed $raw): array {
		if (is_string($raw) === true) {
			$raw = json_decode($raw, true);
		}

		if (is_array($raw) === false) {
			return [];
		}

		return array_values(array_map(static fn (mixed $id): string => (string)$id, $raw));
	}//end ids()

	/**
	 * An OpenRegister object as a flat array with its id.
	 *
	 * @param mixed $object The object.
	 *
	 * @return array<string, mixed>
	 */
	private function asArray(mixed $object): array {
		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$object = $object->jsonSerialize();
		}

		if (is_array($object) === false) {
			return [];
		}

		$id = ($object['id'] ?? ($object['@self']['id'] ?? ($object['uuid'] ?? null)));
		if (isset($object['object']) === true && is_array($object['object']) === true) {
			$object = $object['object'];
		}

		$object['id'] = $id;

		return $object;
	}//end asArray()
}//end class
