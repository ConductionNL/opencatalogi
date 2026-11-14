<?php

/**
 * What the signed-in user can read and is not yet public, for the dashboard widgets.
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
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service;

use OCA\OpenCatalogi\Contract\PublicationQueryServiceInterface;

/**
 * Lists unpublished publications and documents across the catalogues a user can read.
 *
 * Both lists read the most recently updated publications of every catalogue
 * scope with OpenRegister RBAC on, so a catalogue the user cannot read never
 * contributes a row or a count. The read is bounded (ADR-058): when more
 * publications exist than the window, the answer says so with `scope`.
 *
 * @spec openspec/changes/publications-unpublished-overview/specs/dashboard/spec.md
 */
class UnpublishedOverviewService {

	/**
	 * The number of most recently updated publications read per request.
	 */
	public const WINDOW = 200;

	/**
	 * The scope marker returned when the window cut the read.
	 */
	public const SCOPE_RECENT = 'recent-200';

	/**
	 * The list length when the caller asks for none.
	 */
	public const DEFAULT_LIMIT = 10;

	/**
	 * The longest list a caller can ask for.
	 */
	public const MAX_LIMIT = 50;

	/**
	 * Constructor.
	 *
	 * @param OpenRegisterLookup $openRegister The guarded OpenRegister services.
	 * @param InstanceSettings $instance The app configuration (catalogue register and schema).
	 * @param PublicationQueryServiceInterface $publications The rule for "public".
	 */
	public function __construct(
		private readonly OpenRegisterLookup $openRegister,
		private readonly InstanceSettings $instance,
		private readonly PublicationQueryServiceInterface $publications,
	) {
	}//end __construct()

	/**
	 * Clamp a requested list length to 1..MAX_LIMIT.
	 *
	 * @param int|null $limit The requested length, or null for the default.
	 *
	 * @return int The length to use.
	 *
	 * @spec openspec/changes/publications-unpublished-overview/specs/dashboard/spec.md#requirement-unpublished-content-dashboard-widgets-dsh-011
	 */
	public static function clampLimit(?int $limit): int {
		if ($limit === null || $limit < 1) {
			return self::DEFAULT_LIMIT;
		}

		return min($limit, self::MAX_LIMIT);
	}//end clampLimit()

	/**
	 * The publications the user can read that are not yet public and not withdrawn.
	 *
	 * @param int|null $limit The list length, clamped to 1..MAX_LIMIT.
	 *
	 * @return array{items: list<array<string, mixed>>, total: int, scope: string|null} The list.
	 *
	 * @spec openspec/changes/publications-unpublished-overview/specs/dashboard/spec.md#requirement-unpublished-content-dashboard-widgets-dsh-011
	 */
	public function publications(?int $limit): array {
		$read = $this->readRecentPublications();

		$items = [];
		foreach ($read['publications'] as $publication) {
			if ($this->publications->isObjectPublic(objectData: $publication) === true
				|| $this->isLive(moment: ($publication['depublicationDate'] ?? null)) === true
			) {
				continue;
			}

			$items[] = $this->publicationRow(publication: $publication, catalogs: $read['catalogs']);
		}

		return $this->page(items: $items, limit: $limit, scope: $read['scope']);
	}//end publications()

	/**
	 * The files of readable publications whose published time is empty or in the future.
	 *
	 * @param int|null $limit The list length, clamped to 1..MAX_LIMIT.
	 *
	 * @return array{items: list<array<string, mixed>>, total: int, scope: string|null} The list.
	 *
	 * @spec openspec/changes/publications-unpublished-overview/specs/dashboard/spec.md#requirement-unpublished-content-dashboard-widgets-dsh-011
	 */
	public function documents(?int $limit): array {
		$read = $this->readRecentPublications();

		$byUuid = [];
		foreach ($read['publications'] as $publication) {
			$uuid = (string)($publication['@self']['uuid'] ?? ($publication['id'] ?? ''));
			if ($uuid !== '') {
				$byUuid[$uuid] = $publication;
			}
		}

		if ($byUuid === []) {
			return $this->page(items: [], limit: $limit, scope: $read['scope']);
		}

		$items = [];
		foreach ($this->filesOf(uuids: array_keys($byUuid)) as $found) {
			if ($this->isLive(moment: ($found['file']['published'] ?? null)) === false) {
				$items[] = $this->documentRow(file: $found['file'], publication: $byUuid[$found['uuid']], catalogs: $read['catalogs']);
			}
		}

		usort($items, static fn (array $left, array $right): int => strcmp((string)$right['updated'], (string)$left['updated']));

		return $this->page(items: $items, limit: $limit, scope: $read['scope']);
	}//end documents()

	/**
	 * The files of the given publications, each with the uuid of its publication.
	 *
	 * @param list<string> $uuids The publication uuids.
	 *
	 * @return list<array{uuid: string, file: array<string, mixed>}> The files.
	 *
	 * @spec openspec/changes/publications-unpublished-overview/specs/dashboard/spec.md#requirement-unpublished-content-dashboard-widgets-dsh-011
	 */
	private function filesOf(array $uuids): array {
		$fileMapper = $this->openRegister->installedService(
			'OCA\OpenRegister\Files\Db\FileMapper',
			'OpenRegister FileMapper is not available.'
		);

		$owners = [];
		foreach ($fileMapper->getFileIdsForObjects($uuids) as $uuid => $fileIds) {
			foreach ($fileIds as $fileId) {
				$owners[(string)$fileId] = (string)$uuid;
			}
		}

		if ($owners === []) {
			return [];
		}

		$found = [];
		foreach ($fileMapper->getFilesByIds(array_keys($owners)) as $fileId => $file) {
			$uuid = ($owners[(string)$fileId] ?? null);
			if ($uuid !== null && in_array($uuid, $uuids, true) === true) {
				$found[] = ['uuid' => $uuid, 'file' => $file];
			}
		}

		return $found;
	}//end filesOf()

	/**
	 * Read the catalogues and the most recently updated publications in their scope.
	 *
	 * @return array{catalogs: list<array<string, mixed>>, publications: list<array<string, mixed>>, scope: string|null} The read.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) CallerScope::writeScope() is a pure static function.
	 *
	 * @spec openspec/changes/publications-unpublished-overview/specs/dashboard/spec.md#requirement-unpublished-content-dashboard-widgets-dsh-011
	 */
	private function readRecentPublications(): array {
		$empty = ['catalogs' => [], 'publications' => [], 'scope' => null];

		$register = $this->instance->stringValue('catalog_register', '');
		$schema = $this->instance->stringValue('catalog_schema', '');
		if ($register === '' || $schema === '') {
			return $empty;
		}

		$objectService = $this->openRegister->installedObjectService();
		$found = $objectService->searchObjectsPaginated(
			query: ['@self' => ['register' => $register, 'schema' => $schema], '_limit' => self::WINDOW],
			_rbac: true,
			_multitenancy: false,
			deleted: false
		);
		$catalogs = array_map(fn (mixed $row): array => $this->toArray(item: $row), ($found['results'] ?? []));

		$registers = [];
		$schemas = [];
		foreach ($catalogs as $catalog) {
			$registers = array_merge($registers, array_map('strval', (array)($catalog['registers'] ?? [])));
			$schemas = array_merge($schemas, array_map('strval', (array)($catalog['schemas'] ?? [])));
		}

		$registers = array_values(array_unique($registers));
		$schemas = array_values(array_unique($schemas));
		if ($registers === [] || $schemas === []) {
			return ['catalogs' => $catalogs, 'publications' => [], 'scope' => null];
		}

		$query = CallerScope::writeScope(query: ['_limit' => self::WINDOW, '_page' => 1], registers: $registers, schemas: $schemas);
		$query['_order']['updated'] = 'desc';
		$result = $objectService->searchObjectsPaginated(query: $query, _rbac: true, _multitenancy: false, deleted: false);

		$publications = array_map(fn (mixed $row): array => $this->toArray(item: $row), ($result['results'] ?? []));
		$scope = null;
		if ((int)($result['total'] ?? count($publications)) > self::WINDOW) {
			$scope = self::SCOPE_RECENT;
		}

		return ['catalogs' => $catalogs, 'publications' => $publications, 'scope' => $scope];
	}//end readRecentPublications()

	/**
	 * Whether a moment is set and not in the future.
	 *
	 * @param mixed $moment An ISO date, or null.
	 *
	 * @return bool True when it is set and has passed.
	 *
	 * @spec openspec/changes/publications-unpublished-overview/specs/dashboard/spec.md#requirement-unpublished-content-dashboard-widgets-dsh-011
	 */
	private function isLive(mixed $moment): bool {
		if (is_string($moment) === false || $moment === '') {
			return false;
		}

		$time = strtotime($moment);
		return $time !== false && $time <= time();
	}//end isLive()

	/**
	 * The slug of the catalogue whose registers and schemas hold a publication.
	 *
	 * @param array<string, mixed> $publication The publication.
	 * @param list<array<string, mixed>> $catalogs The catalogues read.
	 *
	 * @return string|null The slug, or null when no catalogue holds it.
	 *
	 * @spec openspec/changes/publications-unpublished-overview/specs/dashboard/spec.md#requirement-unpublished-content-dashboard-widgets-dsh-011
	 */
	private function catalogSlugOf(array $publication, array $catalogs): ?string {
		$register = (string)($publication['@self']['register'] ?? '');
		$schema = (string)($publication['@self']['schema'] ?? '');

		foreach ($catalogs as $catalog) {
			$registers = array_map('strval', (array)($catalog['registers'] ?? []));
			$schemas = array_map('strval', (array)($catalog['schemas'] ?? []));
			if (in_array($register, $registers, true) === true && in_array($schema, $schemas, true) === true) {
				$slug = ($catalog['slug'] ?? null);
				if (is_string($slug) === false || $slug === '') {
					return null;
				}

				return $slug;
			}
		}

		return null;
	}//end catalogSlugOf()

	/**
	 * One row of the publications list.
	 *
	 * @param array<string, mixed> $publication The publication.
	 * @param list<array<string, mixed>> $catalogs The catalogues read.
	 *
	 * @return array<string, mixed> The row.
	 *
	 * @spec openspec/changes/publications-unpublished-overview/specs/dashboard/spec.md#requirement-unpublished-content-dashboard-widgets-dsh-011
	 */
	private function publicationRow(array $publication, array $catalogs): array {
		return [
			'title' => (string)($publication['title'] ?? ($publication['@self']['name'] ?? '')),
			'updated' => ($publication['@self']['updated'] ?? null),
			'publicationId' => (string)($publication['@self']['uuid'] ?? ($publication['id'] ?? '')),
			'catalogSlug' => $this->catalogSlugOf(publication: $publication, catalogs: $catalogs),
			'publicationDate' => ($publication['publicationDate'] ?? null),
		];
	}//end publicationRow()

	/**
	 * One row of the documents list.
	 *
	 * @param array<string, mixed> $file The file record.
	 * @param array<string, mixed> $publication The publication holding it.
	 * @param list<array<string, mixed>> $catalogs The catalogues read.
	 *
	 * @return array<string, mixed> The row.
	 *
	 * @spec openspec/changes/publications-unpublished-overview/specs/dashboard/spec.md#requirement-unpublished-content-dashboard-widgets-dsh-011
	 */
	private function documentRow(array $file, array $publication, array $catalogs): array {
		$updated = null;
		if (isset($file['mtime']) === true && is_numeric($file['mtime']) === true) {
			$updated = date('c', (int)$file['mtime']);
		}

		$row = $this->publicationRow(publication: $publication, catalogs: $catalogs);

		return [
			'title' => (string)($file['name'] ?? ''),
			'updated' => $updated,
			'fileId' => (int)($file['fileid'] ?? 0),
			'publicationTitle' => $row['title'],
			'publicationId' => $row['publicationId'],
			'catalogSlug' => $row['catalogSlug'],
			'published' => ($file['published'] ?? null),
		];
	}//end documentRow()

	/**
	 * Cut a list to its length and attach the total and scope.
	 *
	 * @param list<array<string, mixed>> $items The full list, in order.
	 * @param int|null $limit The list length, clamped to 1..MAX_LIMIT.
	 * @param string|null $scope The scope marker.
	 *
	 * @return array{items: list<array<string, mixed>>, total: int, scope: string|null} The answer.
	 *
	 * @spec openspec/changes/publications-unpublished-overview/specs/dashboard/spec.md#requirement-unpublished-content-dashboard-widgets-dsh-011
	 */
	private function page(array $items, ?int $limit, ?string $scope): array {
		return [
			'items' => array_slice($items, 0, self::clampLimit(limit: $limit)),
			'total' => count($items),
			'scope' => $scope,
		];
	}//end page()

	/**
	 * An object row as an array.
	 *
	 * @param mixed $item An array or a JsonSerializable entity.
	 *
	 * @return array<string, mixed> The array.
	 *
	 * @spec exclude Data-shape helper; no independent domain behavior.
	 */
	private function toArray(mixed $item): array {
		if (is_array($item) === true) {
			return $item;
		}

		if (is_object($item) === true && method_exists($item, 'jsonSerialize') === true) {
			return $item->jsonSerialize();
		}

		return [];
	}//end toArray()
}//end class
