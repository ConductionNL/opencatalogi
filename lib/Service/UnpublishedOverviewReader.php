<?php

/**
 * Reads the catalogues, recent publications and their files for the unpublished overview.
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
use Psr\Container\ContainerInterface;

/**
 * The bounded OpenRegister reads behind the unpublished overview.
 *
 * Every search runs with OpenRegister RBAC on, so a catalogue the user cannot
 * read never contributes a publication. The read is bounded (ADR-058): when
 * more publications exist than the window, the answer says so with `scope`.
 *
 * @spec openspec/changes/publications-unpublished-overview/specs/dashboard/spec.md
 */
class UnpublishedOverviewReader {

	/**
	 * The number of most recently updated publications read per request.
	 */
	public const WINDOW = 200;

	/**
	 * The scope marker returned when the window cut the read.
	 */
	public const SCOPE_RECENT = 'recent-200';

	/**
	 * The container id of OpenRegister's file mapper.
	 */
	private const FILE_MAPPER = 'OCA\OpenRegister\Files\Db\FileMapper';

	/**
	 * Constructor.
	 *
	 * @param OpenRegisterLookupInterface $openRegister The guarded OpenRegister services.
	 * @param InstanceSettings $instance The app configuration (catalogue register and schema).
	 * @param ContainerInterface $container Resolves OpenRegister's file mapper.
	 */
	public function __construct(
		private readonly OpenRegisterLookupInterface $openRegister,
		private readonly InstanceSettings $instance,
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * Read the catalogues and the most recently updated publications in their scope.
	 *
	 * @return array{catalogs: list<array<string, mixed>>, publications: list<array<string, mixed>>, scope: string|null} The read.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) CallerScope::writeScope() is a pure static function.
	 *
	 * @spec openspec/changes/publications-unpublished-overview/specs/dashboard/spec.md#requirement-unpublished-content-dashboard-widgets-dsh-011
	 */
	public function recentPublications(): array {
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
	}//end recentPublications()

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
	 * The files of the given publications, each with the uuid of its publication.
	 *
	 * @param list<string> $uuids The publication uuids.
	 *
	 * @return list<array{uuid: string, file: array<string, mixed>}> The files.
	 *
	 * @spec openspec/changes/publications-unpublished-overview/specs/dashboard/spec.md#requirement-unpublished-content-dashboard-widgets-dsh-011
	 */
	public function filesOf(array $uuids): array {
		// Only reached after recentPublications() resolved the ObjectService,
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
