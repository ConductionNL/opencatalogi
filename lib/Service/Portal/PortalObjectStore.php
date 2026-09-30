<?php

/**
 * OpenCatalogi Portal Object Store.
 *
 * The one place the Woo citizen journey reads and writes OpenRegister objects
 * of the `publication` register. A resident has no Nextcloud account, so the
 * portal endpoints read and write as the system (`_rbac` and `_multitenancy`
 * off) and check ownership themselves. A publication is the exception: it is
 * read inside OpenRegister's anonymous scope, so what counts as public here is
 * what an anonymous visitor can read.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Portal
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @version GIT: <git_id>
 * @link https://www.OpenCatalogi.nl
 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-a-dossier-belongs-to-one-resident-and-answers-404-to-everyone-else-req-ccol-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Portal;

use OCA\OpenCatalogi\Service\PublicationQueryService;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Reads and writes the journey's objects through OpenRegister.
 *
 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-a-dossier-belongs-to-one-resident-and-answers-404-to-everyone-else-req-ccol-001
 */
class PortalObjectStore {

	/**
	 * The register every journey schema lives in.
	 */
	public const REGISTER = 'publication';

	/**
	 * The publication schema.
	 */
	public const PUBLICATION_SCHEMA = 'publication';

	/**
	 * The most rows one owner query returns.
	 */
	private const OWNER_LIMIT = 1000;

	/**
	 * The OpenRegister ObjectService, resolved once.
	 *
	 * @var object|null
	 */
	private ?object $objectService = null;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface      $container    Resolves OpenRegister.
	 * @param PublicationQueryService $publications The public-ness rule.
	 * @param LoggerInterface         $logger       The logger.
	 * @param IAppManager             $appManager   Whether OpenRegister is installed.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly PublicationQueryService $publications,
		private readonly LoggerInterface $logger,
		private readonly IAppManager $appManager,
	) {

	}//end __construct()

	/**
	 * One object by id, as the system, or null.
	 *
	 * @param string $schema The schema slug.
	 * @param string $id     The object uuid.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-a-dossier-belongs-to-one-resident-and-answers-404-to-everyone-else-req-ccol-001
	 */
	public function find(string $schema, string $id): ?array {
		if ($id === '') {
			return null;
		}

		try {
			$entity = $this->objectService()->find(
				id: $id,
				register: self::REGISTER,
				schema: $schema,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->debug('OpenCatalogi: portal object not found', ['schema' => $schema, 'reason' => $e->getMessage()]);
			return null;
		}

		return $this->toArray(entity: $entity);

	}//end find()

	/**
	 * Save an object as the system and return it as stored.
	 *
	 * @param string               $schema The schema slug.
	 * @param array<string, mixed> $data   The object's own fields.
	 * @param string|null          $id     The uuid of an existing object.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-a-dossier-belongs-to-one-resident-and-answers-404-to-everyone-else-req-ccol-001
	 */
	public function save(string $schema, array $data, ?string $id=null): array {
		unset($data['@self'], $data['id']);
		$entity = $this->objectService()->saveObject(
			object: $data,
			register: self::REGISTER,
			schema: $schema,
			uuid: $id,
			_rbac: false,
			_multitenancy: false
		);

		$saved = $this->toArray(entity: $entity);
		if ($saved === null) {
			throw new RuntimeException('OpenRegister returned nothing for the save');
		}

		return $saved;

	}//end save()

	/**
	 * Delete an object as the system.
	 *
	 * @param string $schema The schema slug.
	 * @param string $id     The uuid.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-removing-a-portal-account-deletes-that-residents-dossiers-req-ccol-007
	 */
	public function delete(string $schema, string $id): bool {
		return (bool)$this->objectService()->deleteObject(
			uuid: $id,
			register: self::REGISTER,
			schema: $schema,
			_rbac: false,
			_multitenancy: false
		);

	}//end delete()

	/**
	 * Every object of a schema whose `owner` is this subject.
	 *
	 * @param string $schema The schema slug.
	 * @param string $owner  The subject reference.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-removing-a-portal-account-deletes-that-residents-dossiers-req-ccol-007
	 */
	public function findByOwner(string $schema, string $owner): array {
		if ($owner === '') {
			return [];
		}

		return $this->findWhere(schema: $schema, filters: ['owner' => $owner], limit: self::OWNER_LIMIT);

	}//end findByOwner()

	/**
	 * Objects of a schema matching plain field filters, as the system.
	 *
	 * @param string               $schema  The schema slug.
	 * @param array<string, mixed> $filters Field filters.
	 * @param int                  $limit   The most rows.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/saved-searches-and-alerts/specs/saved-searches/spec.md#requirement-the-frequency-decides-when-and-how-the-resident-hears-req-ssa-003
	 */
	public function findWhere(string $schema, array $filters, int $limit): array {
		$rows = $this->objectService()->searchObjects(
			query: array_merge(
				$filters,
				[
					'@self' => ['register' => self::REGISTER, 'schema' => $schema],
					'_limit' => $limit,
				]
			),
			_rbac: false,
			_multitenancy: false
		);

		$out = [];
		foreach ((array)$rows as $row) {
			$array = $this->toArray(entity: $row);
			if ($array !== null && ($filters === [] || $this->matches(row: $array, filters: $filters) === true)) {
				$out[] = $array;
			}
		}

		return $out;

	}//end findWhere()

	/**
	 * A publication, only when it is public at this moment, else null.
	 *
	 * The read runs in OpenRegister's anonymous scope, and the date rule is
	 * checked again on the answer, so a signed-in caller's rights never widen it.
	 *
	 * @param string $id The publication uuid.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-a-shared-dossier-shows-only-what-is-public-now-and-a-revoked-link-answers-404-req-ccol-005
	 */
	public function publicPublication(string $id): ?array {
		if ($id === '') {
			return null;
		}

		$service = $this->objectService();
		$read = function () use ($service, $id): mixed {
			try {
				return $service->find(
					id: $id,
					register: self::REGISTER,
					schema: self::PUBLICATION_SCHEMA,
					_rbac: true,
					_multitenancy: false
				);
			} catch (Throwable) {
				return null;
			}
		};

		if (method_exists($service, 'runAsAnonymous') === true) {
			$entity = $service->runAsAnonymous($read);
		} else {
			$entity = $read();
		}

		$publication = $this->toArray(entity: $entity);
		if ($publication === null || $this->publications->isObjectPublic(objectData: $publication) === false) {
			return null;
		}

		return $publication;

	}//end publicPublication()

	/**
	 * Whether a row carries every filter value (a guard on OpenRegister's filter).
	 *
	 * @param array<string, mixed> $row     The row.
	 * @param array<string, mixed> $filters The filters.
	 *
	 * @return bool
	 */
	private function matches(array $row, array $filters): bool {
		foreach ($filters as $field => $value) {
			if (is_scalar($value) === true && ($row[$field] ?? null) !== $value) {
				return false;
			}
		}

		return true;

	}//end matches()

	/**
	 * An OpenRegister entity or array as a plain array with its uuid under `id`.
	 *
	 * @param mixed $entity The entity.
	 *
	 * @return array<string, mixed>|null
	 */
	private function toArray(mixed $entity): ?array {
		if ($entity === null) {
			return null;
		}

		if (is_object($entity) === true && method_exists($entity, 'jsonSerialize') === true) {
			$entity = $entity->jsonSerialize();
		}

		if (is_array($entity) === false) {
			return null;
		}

		$self = ($entity['@self'] ?? []);
		if (is_array($self) === false) {
			$self = [];
		}

		$id = ($self['id'] ?? ($self['uuid'] ?? ($entity['id'] ?? ($entity['uuid'] ?? null))));
		if (is_string($id) === true && $id !== '') {
			$entity['id'] = $id;
		}

		return $entity;

	}//end toArray()

	/**
	 * The OpenRegister ObjectService.
	 *
	 * @return object
	 *
	 * @throws RuntimeException When OpenRegister is not installed.
	 */
	private function objectService(): object {
		if ($this->objectService === null) {
			// ADR-083: OpenRegister is optional for this app, so establish it
			// first. The app id is written out: the checker reads the literal.
			if ($this->appManager->isInstalled('openregister') === false) {
				throw new RuntimeException('OpenRegister is not installed');
			}

			try {
				$this->objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			} catch (Throwable $e) {
				throw new RuntimeException('OpenRegister is not available: '.$e->getMessage());
			}
		}

		return $this->objectService;

	}//end objectService()
}//end class
