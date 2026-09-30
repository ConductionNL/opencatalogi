<?php

/**
 * An in-memory PortalObjectStore for the journey's service tests.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @link https://www.OpenCatalogi.nl
 * @spec exclude test double
 */

declare(strict_types=1);

namespace Unit\Service\Portal;

use OCA\OpenCatalogi\Service\Portal\PortalObjectStore;
use RuntimeException;

/**
 * Keeps objects per schema in arrays; publications are public by their dates.
 */
class FakePortalObjectStore extends PortalObjectStore {

	/**
	 * Objects by schema and id.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	public array $objects = [];

	/**
	 * Saves that should throw, by schema.
	 *
	 * @var array<string, bool>
	 */
	public array $failSaves = [];

	/**
	 * Every save, in order.
	 *
	 * @var array<int, array{schema: string, data: array<string, mixed>}>
	 */
	public array $saves = [];

	private int $sequence = 0;

	/**
	 * No parent construction: nothing here touches OpenRegister.
	 */
	public function __construct() {
	}

	public function find(string $schema, string $id): ?array {
		return ($this->objects[$schema][$id] ?? null);
	}

	public function save(string $schema, array $data, ?string $id=null): array {
		if (($this->failSaves[$schema] ?? false) === true) {
			throw new RuntimeException('save failed');
		}

		unset($data['@self'], $data['id']);
		if ($id === null) {
			$this->sequence++;
			$id = sprintf('00000000-0000-4000-8000-%012d', $this->sequence);
		}

		$data['id'] = $id;
		$this->objects[$schema][$id] = $data;
		$this->saves[] = ['schema' => $schema, 'data' => $data];
		return $data;
	}

	public function delete(string $schema, string $id): bool {
		$exists = isset($this->objects[$schema][$id]);
		unset($this->objects[$schema][$id]);
		return $exists;
	}

	public function findByOwner(string $schema, string $owner): array {
		return $this->findWhere(schema: $schema, filters: ['owner' => $owner], limit: 1000);
	}

	public function findWhere(string $schema, array $filters, int $limit): array {
		$out = [];
		foreach (($this->objects[$schema] ?? []) as $row) {
			foreach ($filters as $field => $value) {
				if (($row[$field] ?? null) !== $value) {
					continue 2;
				}
			}

			$out[] = $row;
		}

		return array_slice($out, 0, $limit);
	}

	public function publicPublication(string $id): ?array {
		$publication = ($this->objects['publication'][$id] ?? null);
		if ($publication === null) {
			return null;
		}

		$from = strtotime((string)($publication['publicationDate'] ?? ''));
		$until = ($publication['depublicationDate'] ?? null);
		if ($from === false || $from > time()) {
			return null;
		}

		if ($until !== null && strtotime((string)$until) <= time()) {
			return null;
		}

		return $publication;
	}

	/**
	 * Put a publication in the store.
	 *
	 * @param string      $id    The uuid.
	 * @param string      $title The title.
	 * @param string      $from  The publication date.
	 * @param string|null $until The depublication date.
	 *
	 * @return void
	 */
	public function publication(string $id, string $title, string $from='-1 day', ?string $until=null): void {
		$this->objects['publication'][$id] = [
			'id' => $id,
			'title' => $title,
			'publicationDate' => gmdate('c', (int)strtotime($from)),
			'depublicationDate' => ($until === null ? null : gmdate('c', (int)strtotime($until))),
		];
	}
}
