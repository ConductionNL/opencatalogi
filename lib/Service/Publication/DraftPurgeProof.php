<?php

/**
 * OpenCatalogi Draft Purge Proof.
 *
 * The proof that a publication was never released: its stored state, its
 * first release stamp, the depublications that name it and its audit trail.
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

use OCA\OpenCatalogi\Exception\DraftPurgeRefusedException;
use OCA\OpenCatalogi\Service\ReadsObjectProperties;

/**
 * Refuses unless every proof holds. A proof that cannot be read refuses too.
 *
 * @spec openspec/changes/publication-lifecycle-on-or-draft-purge/specs/publications/spec.md#requirement-a-draft-that-was-never-released-is-deleted-permanently-with-everything-on-it-req-plc-004
 */
class DraftPurgeProof {
	use ReadsObjectProperties;

	/**
	 * The states a permanent delete accepts.
	 *
	 * @var list<string>
	 */
	private const PURGEABLE_STATES = [PublicationStateService::STATE_DRAFT, PublicationStateService::STATE_IN_REVIEW];

	/**
	 * Where the depublications live; one naming the publication proves an earlier release.
	 *
	 * @var array{0: string, 1: string}
	 */
	private const DEPUBLICATIONS = ['publication_register', 'depublication_schema'];

	/**
	 * Constructor.
	 *
	 * @param DraftPurgeRecords $records The depublications and the audit trail.
	 *
	 * @spec openspec/changes/publication-lifecycle-on-or-draft-purge/specs/publications/spec.md#requirement-a-draft-that-was-never-released-is-deleted-permanently-with-everything-on-it-req-plc-004
	 */
	public function __construct(private readonly DraftPurgeRecords $records) {

	}//end __construct()

	/**
	 * Refuse unless the stored state, the stamp, the depublications and the audit trail all say never released.
	 *
	 * @param array<string, mixed> $record The publication's properties.
	 * @param string $uuid The publication id.
	 *
	 * @return void
	 *
	 * @throws DraftPurgeRefusedException When one of them does not hold or cannot be read.
	 *
	 * @spec openspec/changes/publication-lifecycle-on-or-draft-purge/specs/publications/spec.md#requirement-a-draft-that-was-never-released-is-deleted-permanently-with-everything-on-it-req-plc-004
	 */
	public function neverReleased(array $record, string $uuid): void {
		$state = (string)($record['status'] ?? '');
		if (in_array($state, self::PURGEABLE_STATES, true) === false) {
			throw new DraftPurgeRefusedException(reason: DraftPurgeRefusedException::NOT_A_DRAFT, evidence: $state);
		}

		$released = trim((string)($record['firstReleasedAt'] ?? ''));
		if ($released !== '') {
			throw new DraftPurgeRefusedException(reason: DraftPurgeRefusedException::RELEASED, evidence: $released);
		}

		$depublications = $this->records->naming(keys: self::DEPUBLICATIONS, property: 'publication', value: $uuid);
		if ($depublications === null) {
			throw new DraftPurgeRefusedException(reason: DraftPurgeRefusedException::UNVERIFIABLE, evidence: 'depublication');
		}

		if ($depublications !== []) {
			throw new DraftPurgeRefusedException(reason: DraftPurgeRefusedException::WITHDRAWN, evidence: $depublications[0]['id']);
		}

		$this->trailNeverPublished(uuid: $uuid);

	}//end neverReleased()

	/**
	 * Refuse when the audit trail holds an entry in which the status was published, or cannot be read.
	 *
	 * @param string $uuid The publication id.
	 *
	 * @return void
	 *
	 * @throws DraftPurgeRefusedException When it holds one or cannot be read.
	 */
	private function trailNeverPublished(string $uuid): void {
		$entries = $this->records->trail(uuid: $uuid);
		if ($entries === null) {
			throw new DraftPurgeRefusedException(reason: DraftPurgeRefusedException::UNVERIFIABLE, evidence: 'audit-trail');
		}

		foreach ($entries as $entry) {
			$changed = $this->changedOf(entry: $entry);
			if ($changed === null) {
				throw new DraftPurgeRefusedException(reason: DraftPurgeRefusedException::UNVERIFIABLE, evidence: 'audit-trail');
			}

			if ($this->mentionsPublished(changed: $changed) === true) {
				throw new DraftPurgeRefusedException(reason: DraftPurgeRefusedException::PUBLISHED_IN_TRAIL, evidence: $this->createdOf(entry: $entry));
			}
		}

	}//end trailNeverPublished()

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
		$created = ($this->plainArray(object: $entry)['created'] ?? '');
		if ($created instanceof \DateTimeInterface) {
			return $created->format(\DateTimeInterface::ATOM);
		}

		if (is_string($created) === true) {
			return $created;
		}

		return '';

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
}//end class
