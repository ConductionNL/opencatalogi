<?php

/**
 * OpenCatalogi Draft Purge Records.
 *
 * The OpenRegister reads and writes a permanent delete of a draft needs: the
 * publication, the records that point at it, its files, its audit trail, the
 * permanent removals and the one summary audit entry.
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
 * @spec openspec/changes/publication-lifecycle-on-or-draft-purge/specs/publications/spec.md#requirement-a-draft-that-was-never-released-is-deleted-permanently-with-everything-on-it-req-plc-004
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Publication;

use OCA\OpenCatalogi\Contract\OpenRegisterServicesInterface;
use OCA\OpenCatalogi\Service\ReadsObjectProperties;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectDeletion;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCP\IAppConfig;
use Throwable;

/**
 * Every read answers null when it cannot be completed, so the caller can refuse.
 *
 * @spec openspec/changes/publication-lifecycle-on-or-draft-purge/specs/publications/spec.md#requirement-a-draft-that-was-never-released-is-deleted-permanently-with-everything-on-it-req-plc-004
 */
class DraftPurgeRecords {
	use ReadsObjectProperties;

	/**
	 * The most records of one kind read for one publication. Reaching it means
	 * the list may be incomplete, which reads as unreadable.
	 */
	public const LIMIT = 500;

	/**
	 * OpenRegister's audit trail mapper.
	 */
	private const AUDIT_MAPPER = 'OCA\OpenRegister\Db\AuditTrailMapper';

	/**
	 * OpenRegister's file service.
	 */
	private const FILE_SERVICE = 'OCA\OpenRegister\Service\FileService';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $config Where the registers and schemas are configured.
	 * @param OpenRegisterServicesInterface $openRegister OpenRegister's object, file and audit services.
	 *
	 * @spec openspec/changes/publication-lifecycle-on-or-draft-purge/specs/publications/spec.md#requirement-a-draft-that-was-never-released-is-deleted-permanently-with-everything-on-it-req-plc-004
	 */
	public function __construct(
		private readonly IAppConfig $config,
		private readonly OpenRegisterServicesInterface $openRegister,
	) {

	}//end __construct()

	/**
	 * Find the publication with the caller's rights.
	 *
	 * @param string $id The publication id.
	 *
	 * @return object|null The publication, or null when it is not there or the caller cannot read it.
	 *
	 * @throws Throwable When the register cannot be reached.
	 *
	 * @spec openspec/changes/publication-lifecycle-on-or-draft-purge/specs/publications/spec.md#requirement-a-draft-that-was-never-released-is-deleted-permanently-with-everything-on-it-req-plc-004
	 */
	public function publication(string $id): ?object {
		$configuration = $this->openRegister->registerConfiguration(registerKey: 'publication_register', schemaKey: 'publication_schema');

		try {
			return $this->openRegister->installedObjectService()->find(
				id: $id,
				options: new ObjectFindOptions(register: $configuration['register'], schema: $configuration['schema']),
				_rbac: true
			);
		} catch (Throwable $e) {
			// Not there, or not readable by this caller: both read as absent.
			if (str_ends_with(get_class($e), 'DoesNotExistException') === true || str_ends_with(get_class($e), 'NotAuthorizedException') === true) {
				return null;
			}

			throw $e;
		}

	}//end publication()

	/**
	 * The configured register and schema, or null when either is not configured.
	 *
	 * @param array{0: string, 1: string} $keys The register and schema config keys.
	 *
	 * @return array{register: string, schema: string}|null
	 *
	 * @spec openspec/changes/publication-lifecycle-on-or-draft-purge/specs/publications/spec.md#requirement-a-draft-that-was-never-released-is-deleted-permanently-with-everything-on-it-req-plc-004
	 */
	public function target(array $keys): ?array {
		$register = trim($this->config->getValueString('opencatalogi', $keys[0], ''));
		$schema = trim($this->config->getValueString('opencatalogi', $keys[1], ''));
		if ($register === '' || $schema === '') {
			return null;
		}

		return ['register' => $register, 'schema' => $schema];

	}//end target()

	/**
	 * The records of a configured schema whose property holds the value.
	 *
	 * @param array{0: string, 1: string} $keys The register and schema config keys.
	 * @param string $property The property that points.
	 * @param string $value What it points at.
	 *
	 * @return array<int, array{id: string, name: string, target: array{register: string, schema: string}}>|null
	 *         The records, an empty list when the schema is not configured, null when they could not all be read.
	 *
	 * @spec openspec/changes/publication-lifecycle-on-or-draft-purge/specs/publications/spec.md#requirement-a-draft-that-was-never-released-is-deleted-permanently-with-everything-on-it-req-plc-004
	 */
	public function naming(array $keys, string $property, string $value): ?array {
		$target = $this->target(keys: $keys);
		if ($target === null) {
			return [];
		}

		try {
			$result = $this->openRegister->installedObjectService()->searchObjectsPaginated(
				query: ['@self' => $target, $property => $value, '_limit' => self::LIMIT],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			return null;
		}

		$rows = array_values((array)($result['results'] ?? []));
		if (count($rows) >= self::LIMIT) {
			return null;
		}

		$records = [];
		foreach ($rows as $row) {
			$properties = $this->asRecord(object: $row);
			$records[] = ['id' => (string)($properties['id'] ?? ''), 'name' => (string)($properties['title'] ?? ''), 'target' => $target];
		}

		return $records;

	}//end naming()

	/**
	 * The files attached to the publication.
	 *
	 * @param object $publication The publication.
	 *
	 * @return array<int, array{kind: string, id: string, name: string}>|null The files, null when they cannot be listed.
	 *
	 * @spec openspec/changes/publication-lifecycle-on-or-draft-purge/specs/publications/spec.md#requirement-a-draft-that-was-never-released-is-deleted-permanently-with-everything-on-it-req-plc-004
	 */
	public function files(object $publication): ?array {
		try {
			$nodes = $this->service(id: self::FILE_SERVICE)->getFiles(object: $publication);
		} catch (Throwable $e) {
			return null;
		}

		$files = [];
		foreach ((array)$nodes as $node) {
			$files[] = ['kind' => 'file', 'id' => (string)$node->getId(), 'name' => (string)$node->getName()];
		}

		return $files;

	}//end files()

	/**
	 * Every audit trail entry of the object.
	 *
	 * @param string $uuid The object id.
	 *
	 * @return array<int, mixed>|null The entries, null when the trail cannot be read.
	 *
	 * @spec openspec/changes/publication-lifecycle-on-or-draft-purge/specs/publications/spec.md#requirement-a-draft-that-was-never-released-is-deleted-permanently-with-everything-on-it-req-plc-004
	 */
	public function trail(string $uuid): ?array {
		try {
			return array_values((array)$this->service(id: self::AUDIT_MAPPER)->findByObjectUntil(objectUuid: $uuid));
		} catch (Throwable $e) {
			return null;
		}

	}//end trail()

	/**
	 * Remove one file or one object permanently.
	 *
	 * @param object $publication The publication the file hangs on.
	 * @param array{kind: string, id: string, name: string, target?: array{register: string, schema: string}} $item The item.
	 *
	 * @return bool Whether it is gone.
	 *
	 * @spec openspec/changes/publication-lifecycle-on-or-draft-purge/specs/publications/spec.md#requirement-a-draft-that-was-never-released-is-deleted-permanently-with-everything-on-it-req-plc-004
	 */
	public function remove(object $publication, array $item): bool {
		try {
			if ($item['kind'] === 'file') {
				return (bool)$this->service(id: self::FILE_SERVICE)->deleteFile(file: (int)$item['id'], object: $publication);
			}

			$target = ($item['target'] ?? ['register' => '', 'schema' => '']);

			return (bool)$this->openRegister->installedObjectService()->deleteObject(
				deletion: new ObjectDeletion(
					uuid: $item['id'],
					target: new ObjectFindTarget(register: $target['register'], schema: $target['schema']),
					permanent: true
				),
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			return false;
		}

	}//end remove()

	/**
	 * Write one audit entry about the publication.
	 *
	 * @param object $publication The publication, as it was.
	 * @param string $action The action.
	 * @param array<string, mixed> $context What the entry records.
	 *
	 * @return void
	 *
	 * @throws Throwable When the entry cannot be written.
	 *
	 * @spec openspec/changes/publication-lifecycle-on-or-draft-purge/specs/publications/spec.md#requirement-a-draft-that-was-never-released-is-deleted-permanently-with-everything-on-it-req-plc-004
	 */
	public function log(object $publication, string $action, array $context): void {
		$this->service(id: self::AUDIT_MAPPER)->createAuditTrail(old: $publication, new: null, action: $action, cascadeContext: $context);
	}//end log()

	/**
	 * An OpenRegister service, when OpenRegister is installed.
	 *
	 * @param string $id The service id.
	 *
	 * @return mixed The service.
	 *
	 * @throws \RuntimeException When OpenRegister is not installed.
	 */
	private function service(string $id): mixed {
		return $this->openRegister->installedService($id, 'OpenRegister is not available.');
	}//end service()
}//end class
