<?php

/**
 * OpenCatalogi Draft Purge Service.
 *
 * Deletes a draft that was never released permanently, together with its
 * files, document references and the side records that point at it, and
 * leaves one summary audit entry. Anything that was ever released is refused.
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

use OCA\OpenCatalogi\Contract\DraftPurgeServiceInterface;
use OCA\OpenCatalogi\Contract\OpenRegisterServicesInterface;
use OCA\OpenCatalogi\Exception\DraftPurgeFailedException;
use OCA\OpenCatalogi\Exception\DraftPurgeRefusedException;
use OCA\OpenCatalogi\Service\ReadsObjectProperties;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectDeletion;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCP\IAppConfig;
use Throwable;

/**
 * Prove, list, remove, then log once.
 *
 * The proofs and the list are read before anything is removed, so a refusal
 * or a read failure leaves everything in place. The publication is removed
 * last, so a failure part way keeps it.
 *
 * @spec openspec/changes/publication-lifecycle-on-or-draft-purge/specs/publications/spec.md#requirement-a-draft-that-was-never-released-is-deleted-permanently-with-everything-on-it-req-plc-004
 */
class DraftPurgeService implements DraftPurgeServiceInterface {
	use ReadsObjectProperties;

	/**
	 * The audit action of the one summary entry.
	 */
	public const ACTION = 'publication.draft.purged';

	/**
	 * The states a permanent delete accepts.
	 *
	 * @var list<string>
	 */
	private const PURGEABLE_STATES = [PublicationStateService::STATE_DRAFT, PublicationStateService::STATE_IN_REVIEW];

	/**
	 * The most side records of one kind read for one publication. Reaching it
	 * means the list may be incomplete, which refuses the delete.
	 */
	private const LIMIT = 500;

	/**
	 * The side records that name the publication in their `publication` property,
	 * keyed by kind, with their register and schema config keys.
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	private const SIDE_RECORDS = [
		'documentReference' => ['publication_register', 'document_reference_schema'],
		'publicationProcess' => ['publication_register', 'publication_process_schema'],
		'metadataSuggestion' => ['publication_register', 'metadata_suggestion_schema'],
	];

	/**
	 * Where the assessments of a document live; they name the file, not the publication.
	 *
	 * @var array{0: string, 1: string}
	 */
	private const ASSESSMENTS = ['woo_register', 'woo_assessment_schema'];

	/**
	 * Where the depublications live; one naming the publication proves an earlier release.
	 *
	 * @var array{0: string, 1: string}
	 */
	private const DEPUBLICATIONS = ['publication_register', 'depublication_schema'];

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
	 * Delete a never released draft permanently with everything on it.
	 *
	 * @param object $publication The publication as OpenRegister found it.
	 *
	 * @return array<int, array{kind: string, id: string, name: string}> Everything removed, the publication last.
	 *
	 * @throws DraftPurgeRefusedException When a proof does not hold; nothing was removed.
	 * @throws DraftPurgeFailedException When a removal failed part way.
	 *
	 * @spec openspec/changes/publication-lifecycle-on-or-draft-purge/specs/publications/spec.md#requirement-a-draft-that-was-never-released-is-deleted-permanently-with-everything-on-it-req-plc-004
	 */
	public function purge(object $publication): array {
		$record = $this->asRecord(object: $publication);
		$uuid = trim((string)($record['id'] ?? ''));
		if ($uuid === '') {
			throw new DraftPurgeRefusedException(reason: DraftPurgeRefusedException::UNVERIFIABLE, evidence: 'id');
		}

		$this->proveNeverReleased(record: $record, uuid: $uuid);
		$plan = $this->plan(publication: $publication, record: $record, uuid: $uuid);
		$removed = $this->remove(publication: $publication, plan: $plan);
		$this->log(publication: $publication, record: $record, removed: $removed);

		return $removed;

	}//end purge()

	/**
	 * Refuse unless the stored state, the stamp, the depublications and the audit trail all say never released.
	 *
	 * @param array<string, mixed> $record The publication's properties.
	 * @param string $uuid The publication id.
	 *
	 * @return void
	 *
	 * @throws DraftPurgeRefusedException When one of them does not hold or cannot be read.
	 */
	private function proveNeverReleased(array $record, string $uuid): void {
		$state = (string)($record['status'] ?? '');
		if (in_array($state, self::PURGEABLE_STATES, true) === false) {
			throw new DraftPurgeRefusedException(reason: DraftPurgeRefusedException::NOT_A_DRAFT, evidence: $state);
		}

		$released = trim((string)($record['firstReleasedAt'] ?? ''));
		if ($released !== '') {
			throw new DraftPurgeRefusedException(reason: DraftPurgeRefusedException::RELEASED, evidence: $released);
		}

		$depublications = $this->recordsNaming(keys: self::DEPUBLICATIONS, property: 'publication', value: $uuid);
		if ($depublications === null) {
			throw new DraftPurgeRefusedException(reason: DraftPurgeRefusedException::UNVERIFIABLE, evidence: 'depublication');
		}

		if ($depublications !== []) {
			throw new DraftPurgeRefusedException(reason: DraftPurgeRefusedException::WITHDRAWN, evidence: $depublications[0]['id']);
		}

		$this->proveTrailNeverPublished(uuid: $uuid);

	}//end proveNeverReleased()

	/**
	 * Refuse when the audit trail holds an entry in which the status was published, or cannot be read.
	 *
	 * @param string $uuid The publication id.
	 *
	 * @return void
	 *
	 * @throws DraftPurgeRefusedException When it holds one or cannot be read.
	 */
	private function proveTrailNeverPublished(string $uuid): void {
		try {
			$entries = $this->openRegister->installedService(
				'OCA\OpenRegister\Db\AuditTrailMapper',
				'OpenRegister audit trail is not available.'
			)->findByObjectUntil(objectUuid: $uuid);
		} catch (Throwable $e) {
			throw new DraftPurgeRefusedException(reason: DraftPurgeRefusedException::UNVERIFIABLE, evidence: 'audit-trail');
		}

		foreach ((array)$entries as $entry) {
			$changed = $this->changedOf(entry: $entry);
			if ($changed === null) {
				throw new DraftPurgeRefusedException(reason: DraftPurgeRefusedException::UNVERIFIABLE, evidence: 'audit-trail');
			}

			if ($this->mentionsPublished(changed: $changed) === true) {
				throw new DraftPurgeRefusedException(reason: DraftPurgeRefusedException::PUBLISHED_IN_TRAIL, evidence: $this->createdOf(entry: $entry));
			}
		}

	}//end proveTrailNeverPublished()

	/**
	 * The `changed` payload of an audit entry, or null when it cannot be read.
	 *
	 * @param mixed $entry An AuditTrail entity or its serialised form.
	 *
	 * @return array<mixed>|null
	 */
	private function changedOf(mixed $entry): ?array {
		if (is_array($entry) === true) {
			return (array)($entry['changed'] ?? []);
		}

		if (is_object($entry) === true && is_callable([$entry, 'getChanged']) === true) {
			return (array)($entry->getChanged() ?? []);
		}

		return null;

	}//end changedOf()

	/**
	 * When an audit entry was written, for the refusal.
	 *
	 * @param mixed $entry An AuditTrail entity or its serialised form.
	 *
	 * @return string The moment, empty when unknown.
	 */
	private function createdOf(mixed $entry): string {
		$created = $this->plainArray(object: $entry)['created'] ?? '';
		if ($created instanceof \DateTimeInterface) {
			return $created->format(\DateTimeInterface::ATOM);
		}

		return is_string($created) === true ? $created : '';

	}//end createdOf()

	/**
	 * Whether a `changed` payload records the status `published`, on either side of a change.
	 *
	 * @param array<mixed> $changed The payload.
	 *
	 * @return bool
	 */
	private function mentionsPublished(array $changed): bool {
		foreach ($changed as $key => $value) {
			if ($key === 'status' && $this->isPublishedValue(value: $value) === true) {
				return true;
			}

			if (is_array($value) === true && $this->mentionsPublished(changed: $value) === true) {
				return true;
			}
		}

		return false;

	}//end mentionsPublished()

	/**
	 * Whether a status value, or an old/new pair, holds `published`.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 */
	private function isPublishedValue(mixed $value): bool {
		if (is_array($value) === true) {
			return ($value['old'] ?? null) === 'published' || ($value['new'] ?? null) === 'published';
		}

		return $value === 'published';

	}//end isPublishedValue()

	/**
	 * Everything that will be removed, the publication last.
	 *
	 * @param object $publication The publication.
	 * @param array<string, mixed> $record Its properties.
	 * @param string $uuid Its id.
	 *
	 * @return array<int, array{kind: string, id: string, name: string, target?: array{register: string, schema: string}}>
	 *
	 * @throws DraftPurgeRefusedException When any of it cannot be listed.
	 */
	private function plan(object $publication, array $record, string $uuid): array {
		$files = $this->filesOf(publication: $publication);
		$plan = [];
		foreach (self::SIDE_RECORDS as $kind => $keys) {
			$plan = array_merge($plan, $this->listed(kind: $kind, rows: $this->recordsNaming(keys: $keys, property: 'publication', value: $uuid)));
		}

		foreach ($files as $file) {
			$plan = array_merge(
				$plan,
				$this->listed(kind: 'wooAssessment', rows: $this->recordsNaming(keys: self::ASSESSMENTS, property: 'documentReference', value: $file['id']))
			);
		}

		$target = $this->target(keys: ['publication_register', 'publication_schema']) ?? ['register' => '', 'schema' => ''];
		$plan = array_merge($plan, $files);
		$plan[] = ['kind' => 'publication', 'id' => $uuid, 'name' => (string)($record['title'] ?? ''), 'target' => $target];

		return $plan;

	}//end plan()

	/**
	 * Side records of one kind as plan items, refusing when they could not be read.
	 *
	 * @param string $kind The kind.
	 * @param array<int, array{id: string, name: string, target: array{register: string, schema: string}}>|null $rows The records, null when unreadable.
	 *
	 * @return array<int, array{kind: string, id: string, name: string, target: array{register: string, schema: string}}>
	 *
	 * @throws DraftPurgeRefusedException When they could not be read.
	 */
	private function listed(string $kind, ?array $rows): array {
		if ($rows === null) {
			throw new DraftPurgeRefusedException(reason: DraftPurgeRefusedException::UNVERIFIABLE, evidence: $kind);
		}

		return array_map(static fn (array $row): array => ['kind' => $kind] + $row, $rows);

	}//end listed()

	/**
	 * The files attached to the publication.
	 *
	 * @param object $publication The publication.
	 *
	 * @return array<int, array{kind: string, id: string, name: string}>
	 *
	 * @throws DraftPurgeRefusedException When they cannot be listed.
	 */
	private function filesOf(object $publication): array {
		try {
			$nodes = $this->fileService()->getFiles(object: $publication);
		} catch (Throwable $e) {
			throw new DraftPurgeRefusedException(reason: DraftPurgeRefusedException::UNVERIFIABLE, evidence: 'files');
		}

		$files = [];
		foreach ((array)$nodes as $node) {
			$files[] = ['kind' => 'file', 'id' => (string)$node->getId(), 'name' => (string)$node->getName()];
		}

		return $files;

	}//end filesOf()

	/**
	 * The records of a configured schema whose property holds the value.
	 *
	 * @param array{0: string, 1: string} $keys The register and schema config keys.
	 * @param string $property The property that points.
	 * @param string $value What it points at.
	 *
	 * @return array<int, array{id: string, name: string, target: array{register: string, schema: string}}>|null
	 *         The records, an empty list when the schema is not configured, null when they could not all be read.
	 */
	private function recordsNaming(array $keys, string $property, string $value): ?array {
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

	}//end recordsNaming()

	/**
	 * The configured register and schema, or null when either is not configured.
	 *
	 * @param array{0: string, 1: string} $keys The register and schema config keys.
	 *
	 * @return array{register: string, schema: string}|null
	 */
	private function target(array $keys): ?array {
		$register = trim($this->config->getValueString('opencatalogi', $keys[0], ''));
		$schema = trim($this->config->getValueString('opencatalogi', $keys[1], ''));
		if ($register === '' || $schema === '') {
			return null;
		}

		return ['register' => $register, 'schema' => $schema];

	}//end target()

	/**
	 * Remove each planned item in order, stopping at the first that fails.
	 *
	 * @param object $publication The publication.
	 * @param array<int, array{kind: string, id: string, name: string, target?: array{register: string, schema: string}}> $plan The plan.
	 *
	 * @return array<int, array{kind: string, id: string, name: string}> What was removed.
	 *
	 * @throws DraftPurgeFailedException When one removal fails.
	 */
	private function remove(object $publication, array $plan): array {
		$removed = [];
		foreach ($plan as $item) {
			$summary = ['kind' => $item['kind'], 'id' => $item['id'], 'name' => $item['name']];
			if ($this->removeOne(publication: $publication, item: $item) === false) {
				throw new DraftPurgeFailedException(removed: $removed, failedOn: $summary);
			}

			$removed[] = $summary;
		}

		return $removed;

	}//end remove()

	/**
	 * Remove one file or one object permanently.
	 *
	 * @param object $publication The publication the file hangs on.
	 * @param array{kind: string, id: string, name: string, target?: array{register: string, schema: string}} $item The item.
	 *
	 * @return bool Whether it is gone.
	 */
	private function removeOne(object $publication, array $item): bool {
		try {
			if ($item['kind'] === 'file') {
				return (bool)$this->fileService()->deleteFile(file: (int)$item['id'], object: $publication);
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

	}//end removeOne()

	/**
	 * Write the one summary audit entry.
	 *
	 * @param object $publication The publication, as it was.
	 * @param array<string, mixed> $record Its properties.
	 * @param array<int, array{kind: string, id: string, name: string}> $removed What was removed.
	 *
	 * @return void
	 *
	 * @throws DraftPurgeFailedException When the entry cannot be written; everything is already gone.
	 */
	private function log(object $publication, array $record, array $removed): void {
		try {
			$this->openRegister->installedService(
				'OCA\OpenRegister\Db\AuditTrailMapper',
				'OpenRegister audit trail is not available.'
			)->createAuditTrail(
				old: $publication,
				new: null,
				action: self::ACTION,
				cascadeContext: [
					'publication' => ['id' => (string)($record['id'] ?? ''), 'title' => (string)($record['title'] ?? '')],
					'removed' => $removed,
				]
			);
		} catch (Throwable $e) {
			throw new DraftPurgeFailedException(
				removed: $removed,
				failedOn: ['kind' => 'audit', 'id' => self::ACTION, 'name' => ''],
				publicationKept: false
			);
		}

	}//end log()

	/**
	 * OpenRegister's FileService.
	 *
	 * @return mixed The service.
	 *
	 * @throws \RuntimeException When OpenRegister is not installed.
	 */
	private function fileService(): mixed {
		return $this->openRegister->installedService('OCA\OpenRegister\Service\FileService', 'OpenRegister FileService is not available.');
	}//end fileService()
}//end class
