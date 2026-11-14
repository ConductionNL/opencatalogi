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

use OCA\OpenCatalogi\Contract\OpenRegisterLookupInterface;
use OCA\OpenCatalogi\Contract\PublicationQueryServiceInterface;
use Psr\Container\ContainerInterface;

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
	 * The container id of OpenRegister's file mapper.
	 */
	private const FILE_MAPPER = 'OCA\OpenRegister\Files\Db\FileMapper';

	/**
	 * Constructor.
	 *
	 * @param OpenRegisterLookupInterface $openRegister The guarded OpenRegister services.
	 * @param InstanceSettings $instance The app configuration (catalogue register and schema).
	 * @param PublicationQueryServiceInterface $publications The rule for "public".
	 * @param ContainerInterface $container Resolves OpenRegister's file mapper.
	 */
	public function __construct(
		private readonly OpenRegisterLookupInterface $openRegister,
		private readonly InstanceSettings $instance,
		private readonly PublicationQueryServiceInterface $publications,
		private readonly ContainerInterface $container,
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

		$byUuid = $this->byUuid(publications: $read['publications']);
		if ($byUuid === []) {
			return $this->page(items: [], limit: $limit, scope: $read['scope']);
		}

		$items = $this->unpublishedFileRows(byUuid: $byUuid, catalogs: $read['catalogs']);
		usort($items, static fn (array $left, array $right): int => strcmp((string)$right['updated'], (string)$left['updated']));

		return $this->page(items: $items, limit: $limit, scope: $read['scope']);
	}//end documents()

	/**
	 * The publications keyed by uuid; a publication without one is left out.
	 *
	 * @param list<array<string, mixed>> $publications The publications.
	 *
	 * @return array<string, array<string, mixed>> The publications by uuid.
	 *
	 * @spec openspec/changes/publications-unpublished-overview/specs/dashboard/spec.md#requirement-unpublished-content-dashboard-widgets-dsh-011
	 */
	private function byUuid(array $publications): array {
		$byUuid = [];
		foreach ($publications as $publication) {
			$uuid = (string)($publication['@self']['uuid'] ?? ($publication['id'] ?? ''));
			if ($uuid !== '') {
				$byUuid[$uuid] = $publication;
			}
		}

		return $byUuid;
	}//end byUuid()

	/**
	 * The rows of the files of these publications whose published time is empty or in the future.
	 *
	 * @param array<string, array<string, mixed>> $byUuid The publications by uuid.
	 * @param list<array<string, mixed>> $catalogs The catalogues read.
	 *
	 * @return list<array<string, mixed>> The rows, unsorted.
	 *
	 * @spec openspec/changes/publications-unpublished-overview/specs/dashboard/spec.md#requirement-unpublished-content-dashboard-widgets-dsh-011
	 */
	private function unpublishedFileRows(array $byUuid, array $catalogs): array {
		$items = [];
		foreach ($this->filesOf(uuids: array_keys($byUuid)) as $found) {
			if ($this->isLive(moment: ($found['file']['published'] ?? null)) === false) {
				$items[] = $this->documentRow(file: $found['file'], publication: $byUuid[$found['uuid']], catalogs: $catalogs);
			}
		}

		return $items;
	}//end unpublishedFileRows()

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
		// Only reached after readRecentPublications() resolved the ObjectService,
		// which refuses when OpenRegister is not installed.
		$fileMapper = $this->container->get(self::FILE_MAPPER);

		$owners = $this->ownersOf(fileIdsByUuid: $fileMapper->getFileIdsForObjects($uuids));
		if ($owners === []) {
			return [];
		}

		return $this->ownedFiles(files: $fileMapper->getFilesByIds(array_keys($owners)), owners: $owners, uuids: $uuids);
	}//end filesOf()

	/**
	 * The uuid of the publication owning each file id.
	 *
	 * @param iterable<string, iterable<int|string>> $fileIdsByUuid The file ids per publication uuid.
	 *
	 * @return array<string, string> The owner uuid by file id.
	 *
	 * @spec openspec/changes/publications-unpublished-overview/specs/dashboard/spec.md#requirement-unpublished-content-dashboard-widgets-dsh-011
	 */
	private function ownersOf(iterable $fileIdsByUuid): array {
		$owners = [];
		foreach ($fileIdsByUuid as $uuid => $fileIds) {
			foreach ($fileIds as $fileId) {
				$owners[(string)$fileId] = (string)$uuid;
			}
		}

		return $owners;
	}//end ownersOf()

	/**
	 * The files owned by one of the given publications, each with its owner's uuid.
	 *
	 * @param iterable<int|string, array<string, mixed>> $files The files by id.
	 * @param array<string, string> $owners The owner uuid by file id.
	 * @param list<string> $uuids The publication uuids asked for.
	 *
	 * @return list<array{uuid: string, file: array<string, mixed>}> The files.
	 *
	 * @spec openspec/changes/publications-unpublished-overview/specs/dashboard/spec.md#requirement-unpublished-content-dashboard-widgets-dsh-011
	 */
	private function ownedFiles(iterable $files, array $owners, array $uuids): array {
		$found = [];
		foreach ($files as $fileId => $file) {
			$uuid = ($owners[(string)$fileId] ?? null);
			if ($uuid !== null && in_array($uuid, $uuids, true) === true) {
				$found[] = ['uuid' => $uuid, 'file' => $file];
			}
		}

		return $found;
	}//end ownedFiles()

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
		$register = $this->instance->stringValue('catalog_register', '');
		$schema = $this->instance->stringValue('catalog_schema', '');
		if ($register === '' || $schema === '') {
			return ['catalogs' => [], 'publications' => [], 'scope' => null];
		}

		$objectService = $this->openRegister->installedObjectService();
		$catalogs = $this->search(
			objectService: $objectService,
			query: ['@self' => ['register' => $register, 'schema' => $schema], '_limit' => self::WINDOW]
		)['rows'];

		$registers = $this->collect(catalogs: $catalogs, key: 'registers');
		$schemas = $this->collect(catalogs: $catalogs, key: 'schemas');
		if ($registers === [] || $schemas === []) {
			return ['catalogs' => $catalogs, 'publications' => [], 'scope' => null];
		}

		$query = CallerScope::writeScope(query: ['_limit' => self::WINDOW, '_page' => 1], registers: $registers, schemas: $schemas);
		$query['_order']['updated'] = 'desc';
		$result = $this->search(objectService: $objectService, query: $query);

		return ['catalogs' => $catalogs, 'publications' => $result['rows'], 'scope' => $this->windowScope(total: $result['total'])];
	}//end readRecentPublications()

	/**
	 * One RBAC-on, tenant-free search of live objects, its rows as arrays.
	 *
	 * @param mixed $objectService The OpenRegister ObjectService.
	 * @param array<string, mixed> $query The search query.
	 *
	 * @return array{rows: list<array<string, mixed>>, total: int} The rows and the total the search reports.
	 *
	 * @spec openspec/changes/publications-unpublished-overview/specs/dashboard/spec.md#requirement-unpublished-content-dashboard-widgets-dsh-011
	 */
	private function search(mixed $objectService, array $query): array {
		$found = $objectService->searchObjectsPaginated(query: $query, _rbac: true, _multitenancy: false, deleted: false);
		$rows = array_map(fn (mixed $row): array => $this->toArray(item: $row), ($found['results'] ?? []));

		return ['rows' => $rows, 'total' => (int)($found['total'] ?? count($rows))];
	}//end search()

	/**
	 * The distinct values of one list property across the catalogues, as strings.
	 *
	 * @param list<array<string, mixed>> $catalogs The catalogues.
	 * @param string $key The property, `registers` or `schemas`.
	 *
	 * @return list<string> The distinct values.
	 *
	 * @spec openspec/changes/publications-unpublished-overview/specs/dashboard/spec.md#requirement-unpublished-content-dashboard-widgets-dsh-011
	 */
	private function collect(array $catalogs, string $key): array {
		$values = [];
		foreach ($catalogs as $catalog) {
			$values = array_merge($values, array_map('strval', (array)($catalog[$key] ?? [])));
		}

		return array_values(array_unique($values));
	}//end collect()

	/**
	 * The scope marker for a read of this many matches: set when the window cut it.
	 *
	 * @param int $total The number of matches the search reports.
	 *
	 * @return string|null The marker, or null when the window held every match.
	 *
	 * @spec openspec/changes/publications-unpublished-overview/specs/dashboard/spec.md#requirement-unpublished-content-dashboard-widgets-dsh-011
	 */
	private function windowScope(int $total): ?string {
		if ($total > self::WINDOW) {
			return self::SCOPE_RECENT;
		}

		return null;
	}//end windowScope()

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
			if ($this->holds(catalog: $catalog, register: $register, schema: $schema) === true) {
				return $this->slugOf(catalog: $catalog);
			}
		}

		return null;
	}//end catalogSlugOf()

	/**
	 * Whether a catalogue covers a register and schema.
	 *
	 * @param array<string, mixed> $catalog The catalogue.
	 * @param string $register The register id.
	 * @param string $schema The schema id.
	 *
	 * @return bool True when both are in its scope.
	 *
	 * @spec openspec/changes/publications-unpublished-overview/specs/dashboard/spec.md#requirement-unpublished-content-dashboard-widgets-dsh-011
	 */
	private function holds(array $catalog, string $register, string $schema): bool {
		$registers = array_map('strval', (array)($catalog['registers'] ?? []));
		$schemas = array_map('strval', (array)($catalog['schemas'] ?? []));

		return in_array($register, $registers, true) === true && in_array($schema, $schemas, true) === true;
	}//end holds()

	/**
	 * A catalogue's slug, or null when it has none.
	 *
	 * @param array<string, mixed> $catalog The catalogue.
	 *
	 * @return string|null The slug.
	 *
	 * @spec openspec/changes/publications-unpublished-overview/specs/dashboard/spec.md#requirement-unpublished-content-dashboard-widgets-dsh-011
	 */
	private function slugOf(array $catalog): ?string {
		$slug = ($catalog['slug'] ?? null);
		if (is_string($slug) === false || $slug === '') {
			return null;
		}

		return $slug;
	}//end slugOf()

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
