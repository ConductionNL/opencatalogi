<?php

/**
 * OpenCatalogi Publication Version Service.
 *
 * Keeps the earlier public version of a corrected publication. When an update
 * changes what the public read of a public publication returns, the old public
 * projection is stored as a `publicationVersion` object and each public file
 * is copied onto it, so a reader who cited the earlier version still finds it.
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
 * @spec openspec/changes/publication-withdrawal-aftercare/tasks.md#task-1.2
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Publication;

use DateTimeInterface;
use OCA\OpenCatalogi\Contract\PublicationStateServiceInterface;
use OCA\OpenCatalogi\Contract\PublicationVersionServiceInterface;
use OCA\OpenCatalogi\Contract\PublishedRecordViewInterface;
use OCA\OpenCatalogi\Service\OpenRegisterLookup;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Stores the old public projection of a publication before a correction replaces it.
 *
 * @spec openspec/changes/publication-withdrawal-aftercare/specs/publications/spec.md#requirement-a-corrected-publication-keeps-its-earlier-public-versions-reachable-req-pwa-001
 */
class PublicationVersionService implements PublicationVersionServiceInterface {

	/**
	 * The OpenRegister file service that lists and copies a publication's files.
	 */
	public const FILE_SERVICE = 'OCA\OpenRegister\Service\FileService';

	/**
	 * Constructor.
	 *
	 * @param PublicationStateServiceInterface $states Says whether a publication is public.
	 * @param PublishedRecordViewInterface $view The anonymous view of a rendered record.
	 * @param OpenRegisterLookup $openRegister Resolves OpenRegister's services and the version schema.
	 * @param LoggerInterface $logger Logs a version store that cannot be read.
	 *
	 * @spec exclude pure framework plumbing: constructor dependency injection.
	 */
	public function __construct(
		private readonly PublicationStateServiceInterface $states,
		private readonly PublishedRecordViewInterface $view,
		private readonly OpenRegisterLookup $openRegister,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Whether an update replaces a public version that a reader could have cited.
	 *
	 * The old publication must be public, and the update must leave it public:
	 * a withdrawal has its own record, the tombstone, and must never be refused
	 * because a version could not be kept. Then the public projection or the set
	 * of public files has to differ.
	 *
	 * @param ObjectEntity $old The publication as stored.
	 * @param ObjectEntity $new The publication as it would be written.
	 *
	 * @return bool True when the old version has to be kept.
	 *
	 * @spec openspec/changes/publication-withdrawal-aftercare/specs/publications/spec.md#requirement-a-corrected-publication-keeps-its-earlier-public-versions-reachable-req-pwa-001
	 */
	public function replacesPublicVersion(ObjectEntity $old, ObjectEntity $new): bool {
		$before = $this->publicFields(publication: $old);
		$after  = $this->publicFields(publication: $new);
		if ($before === null || $after === null) {
			return false;
		}

		if (self::sameContent(left: $before, right: $after) === false) {
			return true;
		}

		return $this->publicFileIds(publication: $old) !== $this->publicFileIds(publication: $new);

	}//end replacesPublicVersion()

	/**
	 * Store the old public version of a publication with copies of its public files.
	 *
	 * @param ObjectEntity $old The publication as stored before the correction.
	 * @param DateTimeInterface|null $now The moment the version is superseded, now when omitted.
	 *
	 * @return array<string, mixed>|null The stored version, or null when the publication is not public.
	 *
	 * @throws RuntimeException When the version schema is not configured or the version cannot be stored.
	 *
	 * @spec openspec/changes/publication-withdrawal-aftercare/specs/publications/spec.md#requirement-a-corrected-publication-keeps-its-earlier-public-versions-reachable-req-pwa-001
	 */
	public function snapshot(ObjectEntity $old, ?DateTimeInterface $now = null): ?array {
		$fields = $this->publicFields(publication: $old);
		if ($fields === null) {
			return null;
		}

		$target = $this->versionTarget();
		$objects = $this->openRegister->installedObjectService();
		$publication = (string)$old->getUuid();
		$stored = $this->versionsOf(publication: $publication);
		$version = [
			'publication' => $publication,
			'version' => ((int)(end($stored)['version'] ?? 0) + 1),
			'supersededAt' => ($now?->format(DateTimeInterface::ATOM) ?? date(DateTimeInterface::ATOM)),
			'fields' => $fields,
			'documents' => [],
		];

		$saved = $objects->saveObject(object: $version, options: new ObjectSaveOptions(target: $target), _rbac: false, _multitenancy: false);
		$version['documents'] = $this->copyPublicFiles(source: $old, target: $saved);
		if ($version['documents'] === []) {
			return $saved->jsonSerialize();
		}

		$stored = $objects->saveObject(
			object: $version,
			options: new ObjectSaveOptions(target: $target, uuid: (string)$saved->getUuid()),
			_rbac: false,
			_multitenancy: false
		);

		return $stored->jsonSerialize();

	}//end snapshot()

	/**
	 * The stored versions of a publication, oldest first.
	 *
	 * Versions carry no public read rule, so they are read as the system; the
	 * caller decides first whether the publication itself is public.
	 *
	 * @param string $publication The publication uuid.
	 *
	 * @return list<array<string, mixed>> The versions, each with its `id`.
	 *
	 * @throws RuntimeException When the version schema is not configured.
	 *
	 * @spec openspec/changes/publication-withdrawal-aftercare/specs/publications/spec.md#requirement-a-corrected-publication-keeps-its-earlier-public-versions-reachable-req-pwa-001
	 */
	public function versionsOf(string $publication): array {
		try {
			return $this->storedVersions(publication: $publication);
		} catch (Throwable $e) {
			$this->logger->error('[PublicationVersionService] The versions of publication "' . $publication . '" could not be read: ' . $e->getMessage());
			throw $e;
		}

	}//end versionsOf()

	/**
	 * The stored versions of a publication, oldest first, as versionsOf() answers them.
	 *
	 * @param string $publication The publication uuid.
	 *
	 * @return list<array<string, mixed>> The versions.
	 *
	 * @spec openspec/changes/publication-withdrawal-aftercare/specs/publications/spec.md#requirement-a-corrected-publication-keeps-its-earlier-public-versions-reachable-req-pwa-001
	 */
	private function storedVersions(string $publication): array {
		$target = $this->versionTarget();
		$found = $this->openRegister->installedObjectService()->searchObjects(
			query: ['@self' => ['register' => $target->register, 'schema' => $target->schema], 'publication' => $publication],
			_rbac: false,
			_multitenancy: false
		);
		if (is_iterable($found) === false) {
			return [];
		}

		$versions = [];
		foreach ($found as $item) {
			$row = $this->versionRow(item: $item);
			// The search filters on the publication; the check keeps a loose match out.
			if ((string)($row['publication'] ?? '') === $publication && (int)($row['version'] ?? 0) > 0) {
				$versions[(int)$row['version']] = $row;
			}
		}

		ksort($versions);

		return array_values($versions);

	}//end storedVersions()

	/**
	 * One stored document of a version, or null when the version does not list it.
	 *
	 * @param array<string, mixed> $version A version from versionsOf().
	 * @param int $fileId The id of the copy kept on the version.
	 *
	 * @return object|null The file node.
	 *
	 * @spec openspec/changes/publication-withdrawal-aftercare/specs/publications/spec.md#requirement-a-corrected-publication-keeps-its-earlier-public-versions-reachable-req-pwa-001
	 */
	public function documentOf(array $version, int $fileId): ?object {
		$listed = array_map(
			static fn (array $document): int => (int)($document['fileId'] ?? 0),
			array_filter((array)($version['documents'] ?? []), 'is_array')
		);
		$uuid = (string)($version['id'] ?? '');
		if ($fileId <= 0 || $uuid === '' || in_array($fileId, $listed, true) === false) {
			return null;
		}

		try {
			return $this->fileService()->getFile(object: $uuid, file: $fileId);
		} catch (Throwable $e) {
			$this->logger->error('[PublicationVersionService] Document ' . $fileId . ' of version "' . $uuid . '" could not be read: ' . $e->getMessage());
			throw $e;
		}

	}//end documentOf()

	/**
	 * The uuid of a record the public read answered, when it is public now; otherwise null.
	 *
	 * A signed-in caller may read a draft or a withdrawn record through the
	 * public read, so a record that carries its status must also be public now.
	 *
	 * @param array<string, mixed> $record The record the public read answered.
	 *
	 * @return string|null The uuid.
	 *
	 * @spec openspec/changes/publication-withdrawal-aftercare/specs/publications/spec.md#requirement-a-corrected-publication-keeps-its-earlier-public-versions-reachable-req-pwa-001
	 */
	public function publicUuidOf(array $record): ?string {
		if (array_key_exists('status', $record) === true
			&& $this->states->stateOf(publication: $record) !== PublicationStateServiceInterface::STATE_PUBLIC
		) {
			return null;
		}

		$uuid = (string)($record['id'] ?? ($record['@self']['id'] ?? ''));
		if ($uuid === '') {
			return null;
		}

		return $uuid;

	}//end publicUuidOf()

	/**
	 * A searched version as an array with its `id`.
	 *
	 * @param mixed $item An ObjectEntity or an array.
	 *
	 * @return array<string, mixed> The version.
	 *
	 * @spec openspec/changes/publication-withdrawal-aftercare/specs/publications/spec.md#requirement-a-corrected-publication-keeps-its-earlier-public-versions-reachable-req-pwa-001
	 */
	private function versionRow(mixed $item): array {
		if ($item instanceof ObjectEntity) {
			return ($item->getObject() ?? []) + ['id' => (string)$item->getUuid()];
		}

		return (array)$item;

	}//end versionRow()

	/**
	 * The fields the public read returns for a publication, or null when it is not public.
	 *
	 * @param ObjectEntity $publication The publication.
	 *
	 * @return array<string, mixed>|null The public projection without its metadata.
	 *
	 * @spec openspec/changes/publication-withdrawal-aftercare/specs/publications/spec.md#requirement-a-corrected-publication-keeps-its-earlier-public-versions-reachable-req-pwa-001
	 */
	private function publicFields(ObjectEntity $publication): ?array {
		$properties = ($publication->getObject() ?? []);
		if ($this->states->stateOf(publication: $properties) !== PublicationStateServiceInterface::STATE_PUBLIC) {
			return null;
		}

		$view = $this->view->view(record: $publication->jsonSerialize());
		if ($view === null) {
			return null;
		}

		unset($view['@self']);

		return $view;

	}//end publicFields()

	/**
	 * The ids of a publication's public (shared) files, sorted.
	 *
	 * @param ObjectEntity $publication The publication.
	 *
	 * @return list<int> The file ids.
	 *
	 * @spec openspec/changes/publication-withdrawal-aftercare/specs/publications/spec.md#requirement-a-corrected-publication-keeps-its-earlier-public-versions-reachable-req-pwa-001
	 */
	private function publicFileIds(ObjectEntity $publication): array {
		$ids = array_map(static fn (object $file): int => (int)$file->getId(), $this->publicFiles(publication: $publication));
		sort($ids);

		return $ids;

	}//end publicFileIds()

	/**
	 * A publication's public (shared) files.
	 *
	 * @param ObjectEntity $publication The publication.
	 *
	 * @return list<object> The files, as Nextcloud nodes.
	 *
	 * @spec openspec/changes/publication-withdrawal-aftercare/specs/publications/spec.md#requirement-a-corrected-publication-keeps-its-earlier-public-versions-reachable-req-pwa-001
	 */
	private function publicFiles(ObjectEntity $publication): array {
		$files = $this->fileService()->getFiles(object: $publication, sharedFilesOnly: true);

		return array_values(array_filter($files, 'is_object'));

	}//end publicFiles()

	/**
	 * Copy each public file of the publication onto the version.
	 *
	 * @param ObjectEntity $source The publication.
	 * @param ObjectEntity $target The stored version.
	 *
	 * @return list<array{title: string, format: string, fileId: int}> The copied documents.
	 *
	 * @spec openspec/changes/publication-withdrawal-aftercare/specs/publications/spec.md#requirement-a-corrected-publication-keeps-its-earlier-public-versions-reachable-req-pwa-001
	 */
	private function copyPublicFiles(ObjectEntity $source, ObjectEntity $target): array {
		$documents = [];
		$files = $this->fileService();
		foreach ($this->publicFiles(publication: $source) as $file) {
			$copy = $files->copyFile(sourceObject: $source, fileId: (int)$file->getId(), targetObject: $target);
			$documents[] = [
				'title' => (string)$file->getName(),
				'format' => (string)$file->getMimetype(),
				'fileId' => (int)$copy->getId(),
			];
		}

		return $documents;

	}//end copyPublicFiles()

	/**
	 * Whether two values hold the same content, the key order of maps aside.
	 *
	 * @param mixed $left One value.
	 * @param mixed $right The other value.
	 *
	 * @return bool True when they are equal.
	 *
	 * @spec openspec/changes/publication-withdrawal-aftercare/specs/publications/spec.md#requirement-a-corrected-publication-keeps-its-earlier-public-versions-reachable-req-pwa-001
	 */
	private static function sameContent(mixed $left, mixed $right): bool {
		if (is_array($left) === false || is_array($right) === false) {
			return $left === $right;
		}

		$keys = array_keys($left);
		if (array_is_list($left) !== array_is_list($right) || count($keys) !== count($right)) {
			return false;
		}

		foreach ($keys as $key) {
			if (array_key_exists($key, $right) === false || self::sameContent(left: $left[$key], right: $right[$key]) === false) {
				return false;
			}
		}

		return true;

	}//end sameContent()

	/**
	 * The register and schema the versions are stored in.
	 *
	 * @return ObjectFindTarget The target.
	 *
	 * @throws RuntimeException When the version schema is not configured.
	 *
	 * @spec openspec/changes/publication-withdrawal-aftercare/specs/publications/spec.md#requirement-a-corrected-publication-keeps-its-earlier-public-versions-reachable-req-pwa-001
	 */
	private function versionTarget(): ObjectFindTarget {
		$config = $this->openRegister->registerConfiguration(registerKey: 'publication_register', schemaKey: 'publication_version_schema');
		$register = (string)($config['register'] ?? '');
		$schema = (string)($config['schema'] ?? '');
		if ($register === '' || $schema === '') {
			throw new RuntimeException('The publicationVersion schema is not configured.');
		}

		return new ObjectFindTarget(register: $register, schema: $schema);

	}//end versionTarget()

	/**
	 * OpenRegister's FileService.
	 *
	 * @return object The file service.
	 *
	 * @spec openspec/changes/publication-withdrawal-aftercare/specs/publications/spec.md#requirement-a-corrected-publication-keeps-its-earlier-public-versions-reachable-req-pwa-001
	 */
	private function fileService(): object {
		return $this->openRegister->installedService(self::FILE_SERVICE, 'OpenRegister FileService is not available.');

	}//end fileService()
}//end class
