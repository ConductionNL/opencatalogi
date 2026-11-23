<?php

/**
 * OpenCatalogi Publication Tombstone.
 *
 * What the public side says about a publication it no longer serves. A link to
 * a withdrawn publication answers with the withdrawal (when, the public reason,
 * and the title only when the officer chose to show it) instead of the 404 of a
 * link that never existed. A draft that was never public stays a 404.
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
 *
 * @spec openspec/changes/publication-withdrawal-aftercare/tasks.md#task-2.2
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Controller;

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
	 * @param CatalogSlugQuery $query The catalogue's registers and schemas and the id from the URL.
	 *
	 * @return array{id: string, withdrawnAt: string|null, publicReason: string|null, title?: string}|null The tombstone.
	 *
	 * @spec openspec/changes/publication-withdrawal-aftercare/specs/publications/spec.md#requirement-a-withdrawn-publication-answers-with-a-tombstone-about-the-withdrawal-req-pwa-002
	 */
	public function tombstone(CatalogSlugQuery $query): ?array {
		$objectService = $this->openRegister->installedObjectService();
		if (is_object($objectService) === false || trim($query->slug) === '') {
			return null;
		}

		$publication = $this->findAsSystem(objectService: $objectService, query: $query);
		if ($publication === null) {
			return null;
		}

		$data = (array)($publication->getObject() ?? []);
		$identity = ['id' => (string)($publication->getUuid() ?? $query->slug), 'title' => (string)($data['title'] ?? '')];
		$depublication = $this->latestDepublication(objectService: $objectService, publicationId: $identity['id']);
		if ($depublication !== null) {
			return self::fromDepublication(depublication: $depublication, identity: $identity);
		}

		if (($data['status'] ?? null) === 'archived' && trim((string)($data['firstReleasedAt'] ?? '')) !== '') {
			return ['id' => $identity['id'], 'withdrawnAt' => self::textOrNull(value: ($data['depublicationDate'] ?? null)), 'publicReason' => null];
		}

		return null;

	}//end tombstone()

	/**
	 * The tombstone a depublication gives: its moment and public reason, the title only when chosen.
	 *
	 * @param array<string, mixed> $depublication The depublication.
	 * @param array{id: string, title: string} $identity The publication's id and title.
	 *
	 * @return array{id: string, withdrawnAt: string|null, publicReason: string|null, title?: string} The tombstone.
	 *
	 * @spec openspec/changes/publication-withdrawal-aftercare/specs/publications/spec.md#requirement-a-withdrawn-publication-answers-with-a-tombstone-about-the-withdrawal-req-pwa-002
	 */
	private static function fromDepublication(array $depublication, array $identity): array {
		$tombstone = [
			'id' => $identity['id'],
			'withdrawnAt' => self::textOrNull(value: ($depublication['depublishedAt'] ?? null)),
			'publicReason' => self::textOrNull(value: ($depublication['publicReason'] ?? null)),
		];
		if (($depublication['tombstoneShowsTitle'] ?? false) === true && $identity['title'] !== '') {
			$tombstone['title'] = $identity['title'];
		}

		return $tombstone;

	}//end fromDepublication()

	/**
	 * The publication in one of the catalogue's register and schema pairs, read with RBAC off, or null.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param CatalogSlugQuery $query The catalogue's registers and schemas and the id.
	 *
	 * @return object|null The stored publication.
	 *
	 * @spec openspec/changes/publication-withdrawal-aftercare/specs/publications/spec.md#requirement-a-withdrawn-publication-answers-with-a-tombstone-about-the-withdrawal-req-pwa-002
	 */
	private function findAsSystem(object $objectService, CatalogSlugQuery $query): ?object {
		foreach (self::pairsOf(query: $query) as $options) {
			$found = self::attempt(read: static fn (): mixed => $objectService->find(id: $query->slug, options: $options, _rbac: false, _multitenancy: false));
			if (is_object($found) === true) {
				return $found;
			}
		}

		return null;

	}//end findAsSystem()

	/**
	 * The find options of every numeric register and schema pair of the catalogue.
	 *
	 * @param CatalogSlugQuery $query The catalogue's registers and schemas.
	 *
	 * @return array<int, ObjectFindOptions> The options, one per pair.
	 */
	private static function pairsOf(CatalogSlugQuery $query): array {
		$pairs = [];
		foreach (array_filter($query->registers, 'is_numeric') as $register) {
			foreach (array_filter($query->schemas, 'is_numeric') as $schema) {
				$pairs[] = new ObjectFindOptions(register: (int)$register, schema: (int)$schema);
			}
		}

		return $pairs;

	}//end pairsOf()

	/**
	 * Run one OpenRegister read; a read that throws answers null, so the caller keeps the 404.
	 *
	 * @param callable $read The read.
	 *
	 * @return mixed What the read answered, or null.
	 */
	private static function attempt(callable $read): mixed {
		try {
			return $read();
		} catch (Throwable $e) {
			return null;
		}

	}//end attempt()

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
		$result = self::attempt(
			read: fn (): mixed => $objectService->searchObjectsPaginated(
				query: [
					'_limit' => self::DEPUBLICATION_LIMIT,
					'@self' => $this->openRegister->registerConfiguration(registerKey: 'publication_register', schemaKey: 'depublication_schema'),
					'publication' => $publicationId,
				],
				_rbac: false,
				_multitenancy: false,
				deleted: false
			)
		);
		if (is_array($result) === false) {
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
