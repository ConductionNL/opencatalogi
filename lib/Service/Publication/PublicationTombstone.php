<?php

/**
 * OpenCatalogi Publication Tombstone.
 *
 * What the public side says about a publication it no longer serves. A link to
 * a withdrawn publication answers with the withdrawal (when, the public reason,
 * and the title only when the officer chose to show it) instead of the 404 of a
 * link that never existed. A draft that was never public stays a 404.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Publication
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
 * @spec openspec/changes/publication-withdrawal-aftercare/tasks.md#task-2.2
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Publication;

use OCA\OpenCatalogi\Contract\OpenRegisterLookupInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use Throwable;

/**
 * Finds, as the system, whether a publication the public read missed was withdrawn.
 *
 * @spec openspec/changes/publication-withdrawal-aftercare/specs/publications/spec.md#requirement-a-withdrawn-publication-answers-with-a-tombstone-about-the-withdrawal-req-pwa-002
 */
class PublicationTombstone {

	/**
	 * How many depublications of one publication are read to find the latest.
	 */
	private const DEPUBLICATION_LIMIT = 50;

	/**
	 * Constructor.
	 *
	 * @param OpenRegisterLookupInterface $openRegister The guarded OpenRegister services.
	 *
	 * @spec exclude pure framework plumbing: constructor dependency injection.
	 */
	public function __construct(
		private readonly OpenRegisterLookupInterface $openRegister,
	) {

	}//end __construct()

	/**
	 * The tombstone of a publication in the catalogue's scope, or null when it was never withdrawn.
	 *
	 * A publication counts as withdrawn when a depublication of the whole
	 * publication names it, or when it is archived after it was once released.
	 * The answer carries the id, the moment, the public reason, and the title
	 * only when the depublication says to show it; nothing else of the record.
	 *
	 * @param array<int|string> $registers The catalogue's registers.
	 * @param array<int|string> $schemas The catalogue's schemas.
	 * @param string $id The publication id from the URL.
	 *
	 * @return array{id: string, withdrawnAt: string|null, publicReason: string|null, title?: string}|null The tombstone.
	 *
	 * @spec openspec/changes/publication-withdrawal-aftercare/specs/publications/spec.md#requirement-a-withdrawn-publication-answers-with-a-tombstone-about-the-withdrawal-req-pwa-002
	 */
	public function tombstone(array $registers, array $schemas, string $id): ?array {
		$objectService = $this->openRegister->installedObjectService();
		if (is_object($objectService) === false || trim($id) === '') {
			return null;
		}

		$publication = $this->findAsSystem(objectService: $objectService, registers: $registers, schemas: $schemas, id: $id);
		if ($publication === null) {
			return null;
		}

		$data = (array)($publication->getObject() ?? []);
		$uuid = (string)($publication->getUuid() ?? $id);
		$depublication = $this->latestDepublication(objectService: $objectService, publicationId: $uuid);
		if ($depublication !== null) {
			return self::fromDepublication(id: $uuid, depublication: $depublication, title: (string)($data['title'] ?? ''));
		}

		if (($data['status'] ?? null) === 'archived' && trim((string)($data['firstReleasedAt'] ?? '')) !== '') {
			return ['id' => $uuid, 'withdrawnAt' => self::textOrNull(value: ($data['depublicationDate'] ?? null)), 'publicReason' => null];
		}

		return null;

	}//end tombstone()

	/**
	 * The tombstone a depublication gives: its moment and public reason, the title only when chosen.
	 *
	 * @param string $id The publication id.
	 * @param array<string, mixed> $depublication The depublication.
	 * @param string $title The publication's title.
	 *
	 * @return array{id: string, withdrawnAt: string|null, publicReason: string|null, title?: string} The tombstone.
	 *
	 * @spec openspec/changes/publication-withdrawal-aftercare/specs/publications/spec.md#requirement-a-withdrawn-publication-answers-with-a-tombstone-about-the-withdrawal-req-pwa-002
	 */
	private static function fromDepublication(string $id, array $depublication, string $title): array {
		$tombstone = [
			'id' => $id,
			'withdrawnAt' => self::textOrNull(value: ($depublication['depublishedAt'] ?? null)),
			'publicReason' => self::textOrNull(value: ($depublication['publicReason'] ?? null)),
		];
		if (($depublication['tombstoneShowsTitle'] ?? false) === true && $title !== '') {
			$tombstone['title'] = $title;
		}

		return $tombstone;

	}//end fromDepublication()

	/**
	 * The publication in one of the catalogue's register and schema pairs, read with RBAC off, or null.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param array<int|string> $registers The catalogue's registers.
	 * @param array<int|string> $schemas The catalogue's schemas.
	 * @param string $id The publication id.
	 *
	 * @return object|null The stored publication.
	 *
	 * @spec openspec/changes/publication-withdrawal-aftercare/specs/publications/spec.md#requirement-a-withdrawn-publication-answers-with-a-tombstone-about-the-withdrawal-req-pwa-002
	 */
	private function findAsSystem(object $objectService, array $registers, array $schemas, string $id): ?object {
		foreach (array_filter($registers, 'is_numeric') as $register) {
			foreach (array_filter($schemas, 'is_numeric') as $schema) {
				try {
					$found = $objectService->find(
						id: $id,
						options: new ObjectFindOptions(register: (int)$register, schema: (int)$schema),
						_rbac: false,
						_multitenancy: false
					);
				} catch (Throwable $e) {
					$found = null;
				}

				if (is_object($found) === true) {
					return $found;
				}
			}
		}

		return null;

	}//end findAsSystem()

	/**
	 * The latest depublication of the whole publication, or null.
	 *
	 * A record that names a file withdrew that file only, and a record marked
	 * `republished` put something back; neither withdrew the publication.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param string $publicationId The publication's uuid.
	 *
	 * @return array<string, mixed>|null The depublication.
	 *
	 * @spec openspec/changes/publication-withdrawal-aftercare/specs/publications/spec.md#requirement-a-withdrawn-publication-answers-with-a-tombstone-about-the-withdrawal-req-pwa-002
	 */
	private function latestDepublication(object $objectService, string $publicationId): ?array {
		try {
			$config = $this->openRegister->registerConfiguration(registerKey: 'publication_register', schemaKey: 'depublication_schema');
			$result = $objectService->searchObjectsPaginated(
				query: [
					'_limit' => self::DEPUBLICATION_LIMIT,
					'@self' => ['register' => $config['register'], 'schema' => $config['schema']],
					'publication' => $publicationId,
				],
				_rbac: false,
				_multitenancy: false,
				deleted: false
			);
		} catch (Throwable $e) {
			return null;
		}

		$latest = null;
		foreach ((array)($result['results'] ?? []) as $row) {
			$record = self::recordOf(row: $row);
			if (self::withdrawsThePublication(record: $record, publicationId: $publicationId) === false) {
				continue;
			}

			if ($latest === null || (string)($record['depublishedAt'] ?? '') > (string)($latest['depublishedAt'] ?? '')) {
				$latest = $record;
			}
		}

		return $latest;

	}//end latestDepublication()

	/**
	 * Whether a depublication record took the whole publication down.
	 *
	 * @param array<string, mixed> $record The depublication.
	 * @param string $publicationId The publication's uuid.
	 *
	 * @return bool True when it did.
	 */
	private static function withdrawsThePublication(array $record, string $publicationId): bool {
		return (string)($record['publication'] ?? '') === $publicationId
			&& trim((string)($record['file'] ?? '')) === ''
			&& ($record['republished'] ?? false) !== true;

	}//end withdrawsThePublication()

	/**
	 * A search row as an array, whether OpenRegister answered an entity or an array.
	 *
	 * @param mixed $row The row.
	 *
	 * @return array<string, mixed> The row's data.
	 */
	private static function recordOf(mixed $row): array {
		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			return (array)$row->jsonSerialize();
		}

		return (array)$row;

	}//end recordOf()

	/**
	 * A non-empty string, or null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null The text.
	 */
	private static function textOrNull(mixed $value): ?string {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		return $value;

	}//end textOrNull()
}//end class
