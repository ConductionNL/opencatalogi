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
use OCA\OpenCatalogi\Exception\DraftPurgeFailedException;
use OCA\OpenCatalogi\Exception\DraftPurgeRefusedException;
use OCA\OpenCatalogi\Service\ReadsObjectProperties;
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
	 * Constructor.
	 *
	 * @param DraftPurgeRecords $records The OpenRegister reads and writes.
	 * @param DraftPurgeProof $proof The proof that the publication was never released.
	 *
	 * @spec openspec/changes/publication-lifecycle-on-or-draft-purge/specs/publications/spec.md#requirement-a-draft-that-was-never-released-is-deleted-permanently-with-everything-on-it-req-plc-004
	 */
	public function __construct(
		private readonly DraftPurgeRecords $records,
		private readonly DraftPurgeProof $proof,
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
	public function find(string $id): ?object {
		return $this->records->publication(id: $id);
	}//end find()

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

		$this->proof->neverReleased(record: $record, uuid: $uuid);
		$plan = $this->plan(publication: $publication, record: $record, uuid: $uuid);
		$removed = $this->remove(publication: $publication, plan: $plan);
		$this->log(publication: $publication, record: $record, removed: $removed);

		return $removed;

	}//end purge()

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
		$files = $this->records->files(publication: $publication);
		if ($files === null) {
			throw new DraftPurgeRefusedException(reason: DraftPurgeRefusedException::UNVERIFIABLE, evidence: 'files');
		}

		$plan = [];
		foreach (self::SIDE_RECORDS as $kind => $keys) {
			$plan = array_merge($plan, $this->listed(kind: $kind, rows: $this->records->naming(keys: $keys, property: 'publication', value: $uuid)));
		}

		foreach ($files as $file) {
			$assessments = $this->records->naming(keys: self::ASSESSMENTS, property: 'documentReference', value: $file['id']);
			$plan = array_merge($plan, $this->listed(kind: 'wooAssessment', rows: $assessments));
		}

		$target = ($this->records->target(keys: ['publication_register', 'publication_schema']) ?? ['register' => '', 'schema' => '']);
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
			if ($this->records->remove(publication: $publication, item: $item) === false) {
				throw new DraftPurgeFailedException(removed: $removed, failedOn: $summary);
			}

			$removed[] = $summary;
		}

		return $removed;

	}//end remove()

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
			$this->records->log(
				publication: $publication,
				action: self::ACTION,
				context: [
					'publication' => ['id' => (string)($record['id'] ?? ''), 'title' => (string)($record['title'] ?? '')],
					'removed' => $removed,
				]
			);
		} catch (Throwable $e) {
			throw new DraftPurgeFailedException(
				removed: $removed,
				failedOn: ['kind' => DraftPurgeFailedException::AUDIT, 'id' => self::ACTION, 'name' => '']
			);
		}

	}//end log()
}//end class
