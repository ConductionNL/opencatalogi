<?php

/**
 * OpenCatalogi metadata suggestion decisions
 *
 * Lists a publication's suggestions, asks Hermiq for more, and writes a
 * person's accept or reject, each behind the publication's own rights.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Woo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\MetadataSuggestion;

use DateTimeZone;
use OCA\OpenCatalogi\Contract\MetadataSuggestionDecisionsInterface;
use OCA\OpenCatalogi\Exception\MetadataSuggestionRefusedException;
use OCA\OpenCatalogi\Contract\OpenRegisterLookupInterface;
use OCA\OpenCatalogi\Service\Publication\PublicationRights;
use OCA\OpenCatalogi\Service\ReadsObjectProperties;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCP\AppFramework\Utility\ITimeFactory;
use Throwable;

/**
 * A person decides on a suggestion (design D3, D4).
 *
 * The publication is read with OpenRegister's RBAC on, so a publication the
 * caller cannot read answers "not found". Asking Hermiq, accepting and
 * rejecting also need the update right on that publication, asked of
 * OpenRegister and failing closed. Accepting writes the value with RBAC on
 * before the suggestion is marked, so a refused write leaves both unchanged.
 *
 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-nothing-is-written-until-a-person-accepts-req-wms-003
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) A decision reads the publication, checks the rights
 *   and writes the record and the field through OpenRegister value objects, in one place.
 */
class MetadataSuggestionDecisions implements MetadataSuggestionDecisionsInterface {
	use ReadsObjectProperties;

	/**
	 * The publication fields a suggestion may fill, as the suggestion schema declares them.
	 *
	 * @var array<int, string>
	 */
	public const FIELDS = ['wooCategory', 'soortHandeling', 'organization', 'title', 'summary'];

	/**
	 * Constructor.
	 *
	 * @param MetadataSuggestionStore $store The stored suggestions.
	 * @param PublicationRights $rights The update right on one publication.
	 * @param OpenRegisterLookupInterface $openRegister OpenRegister's ObjectService and the publication register.
	 * @param MetadataSuggestionService $suggestions The fields Hermiq is asked about, and its answers checked.
	 * @param HermiqMetadataClient $hermiq Hermiq, when installed.
	 * @param PublicationDocumentText $documents The text of the publication's documents.
	 * @param ITimeFactory $time The clock a decision is dated by.
	 */
	public function __construct(
		private readonly MetadataSuggestionStore $store,
		private readonly PublicationRights $rights,
		private readonly OpenRegisterLookupInterface $openRegister,
		private readonly MetadataSuggestionService $suggestions,
		private readonly HermiqMetadataClient $hermiq,
		private readonly PublicationDocumentText $documents,
		private readonly ITimeFactory $time,
	) {

	}//end __construct()

	/**
	 * A publication's suggestions, whether Hermiq can be asked, and whether the caller may decide.
	 *
	 * @param string $publicationId The publication's uuid.
	 *
	 * @return array{suggestions: array<int, array<string, mixed>>, hermiq: bool, canDecide: bool} The overview.
	 *
	 * @throws MetadataSuggestionRefusedException 404 when the caller cannot read the publication, 503 when suggestions are not set up.
	 *
	 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-hermiq-may-add-suggestions-never-replace-them-req-wms-002
	 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-nothing-is-written-until-a-person-accepts-req-wms-003
	 */
	public function overview(string $publicationId): array {
		$this->requireConfigured();
		$publication = $this->readablePublication(id: $publicationId);
		$canDecide = $this->rights->mayUpdate(publication: $publication);

		return [
			'suggestions' => $this->store->forPublication(publicationId: $publicationId),
			'hermiq' => $canDecide === true && $this->hermiq->isAvailable() === true,
			'canDecide' => $canDecide,
		];
	}//end overview()

	/**
	 * Ask Hermiq for suggestions on the publication's missing value-list fields.
	 *
	 * Hermiq's values are checked against the same value lists as the rules
	 * floor and only added: no existing suggestion is changed.
	 *
	 * @param string $publicationId The publication's uuid.
	 * @param string $userId The editor who asked.
	 *
	 * @return array<int, array<string, mixed>> The suggestions stored for Hermiq's values.
	 *
	 * @throws MetadataSuggestionRefusedException 404, 403, 409 `hermiq-unavailable`, or 503.
	 *
	 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-hermiq-may-add-suggestions-never-replace-them-req-wms-002
	 */
	public function askHermiq(string $publicationId, string $userId): array {
		$this->requireConfigured();
		$publication = $this->updatablePublication(id: $publicationId);
		if ($this->hermiq->isAvailable() === false) {
			throw new MetadataSuggestionRefusedException(error: 'hermiq-unavailable', status: 409);
		}

		$properties = $this->asArray(object: $publication);
		$fields = $this->suggestions->hermiqFields(publication: $properties);
		if ($fields === []) {
			return [];
		}

		$values = $this->hermiq->propose(
			text: $this->documents->forPublication(publicationId: $publicationId),
			fields: $fields,
			userId: $userId
		);
		if ($values === null) {
			throw new MetadataSuggestionRefusedException(error: 'hermiq-unavailable', status: 409);
		}

		$created = [];
		$proposals = $this->suggestions->fromHermiq(
			publication: $properties,
			values: $values,
			existing: $this->store->forPublication(publicationId: $publicationId)
		);
		foreach ($proposals as $proposal) {
			$created[] = $this->store->create(proposal: $proposal, publicationId: $publicationId);
		}

		return $created;
	}//end askHermiq()

	/**
	 * Accept a suggestion: write its value to the publication, then record who accepted it and when.
	 *
	 * @param string $id The suggestion's uuid.
	 * @param string $userId The person accepting.
	 *
	 * @return array<string, mixed> The accepted suggestion.
	 *
	 * @throws MetadataSuggestionRefusedException 404, 403, 409 when already decided, or 503.
	 *
	 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-nothing-is-written-until-a-person-accepts-req-wms-003
	 */
	public function accept(string $id, string $userId): array {
		[$suggestion, $publication] = $this->decidable(id: $id);

		$field = (string)($suggestion['field'] ?? '');
		if (in_array($field, self::FIELDS, true) === false) {
			throw new MetadataSuggestionRefusedException(error: 'unknown-field', status: 409);
		}

		$record = $this->asArray(object: $publication);
		unset($record['id'], $record['@self']);
		$record[$field] = (string)($suggestion['value'] ?? '');
		$this->savePublication(id: (string)$suggestion['publication'], record: $record);

		return $this->store->decide(
			suggestion: $suggestion,
			status: MetadataSuggestionStore::STATUS_ACCEPTED,
			decidedBy: $userId,
			decidedAt: $this->now()
		);
	}//end accept()

	/**
	 * Reject a suggestion: record who rejected it and when; the publication is left as it is.
	 *
	 * @param string $id The suggestion's uuid.
	 * @param string $userId The person rejecting.
	 *
	 * @return array<string, mixed> The rejected suggestion.
	 *
	 * @throws MetadataSuggestionRefusedException 404, 403, 409 when already decided, or 503.
	 *
	 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-nothing-is-written-until-a-person-accepts-req-wms-003
	 */
	public function reject(string $id, string $userId): array {
		[$suggestion] = $this->decidable(id: $id);

		return $this->store->decide(
			suggestion: $suggestion,
			status: MetadataSuggestionStore::STATUS_REJECTED,
			decidedBy: $userId,
			decidedAt: $this->now()
		);
	}//end reject()

	/**
	 * The pending suggestion and its publication, when the caller may update that publication.
	 *
	 * @param string $id The suggestion's uuid.
	 *
	 * @return array{0: array<string, mixed>, 1: object} The suggestion and the publication.
	 *
	 * @throws MetadataSuggestionRefusedException 404 for an unknown suggestion or unreadable publication,
	 *                                            403 without the update right, 409 when already decided.
	 */
	private function decidable(string $id): array {
		$this->requireConfigured();
		$suggestion = $this->store->find(id: $id);
		if ($suggestion === null || (string)($suggestion['publication'] ?? '') === '') {
			throw new MetadataSuggestionRefusedException(error: 'not-found', status: 404);
		}

		$publication = $this->updatablePublication(id: (string)$suggestion['publication']);

		if (($suggestion['status'] ?? '') !== MetadataSuggestionStore::STATUS_PENDING) {
			throw new MetadataSuggestionRefusedException(error: 'already-decided', status: 409);
		}

		return [$suggestion, $publication];
	}//end decidable()

	/**
	 * The publication, when the caller may read and update it.
	 *
	 * @param string $id The publication's uuid.
	 *
	 * @return object The publication.
	 *
	 * @throws MetadataSuggestionRefusedException 404 when it cannot be read, 403 without the update right.
	 */
	private function updatablePublication(string $id): object {
		$publication = $this->readablePublication(id: $id);
		if ($this->rights->mayUpdate(publication: $publication) === false) {
			throw new MetadataSuggestionRefusedException(error: 'forbidden', status: 403);
		}

		return $publication;
	}//end updatablePublication()

	/**
	 * The publication read with RBAC on.
	 *
	 * Not there and not readable both answer 404, so an id nobody may read does
	 * not confirm that it exists.
	 *
	 * @param string $id The publication's uuid.
	 *
	 * @return object The publication.
	 *
	 * @throws MetadataSuggestionRefusedException 404 when it cannot be read.
	 */
	private function readablePublication(string $id): object {
		try {
			$configuration = $this->openRegister->registerConfiguration(
				registerKey: 'publication_register',
				schemaKey: 'publication_schema'
			);
			$publication = $this->openRegister->objectService()->find(
				id: $id,
				options: new ObjectFindOptions(register: $configuration['register'], schema: $configuration['schema']),
				_rbac: true
			);
		} catch (Throwable $e) {
			$missing = str_ends_with(get_class($e), 'DoesNotExistException');
			if ($this->isRefusal(e: $e) === true || $missing === true) {
				throw new MetadataSuggestionRefusedException(error: 'not-found', status: 404);
			}

			throw new MetadataSuggestionRefusedException(error: 'not-configured', status: 503);
		}

		if (is_object($publication) === false) {
			throw new MetadataSuggestionRefusedException(error: 'not-found', status: 404);
		}

		return $publication;
	}//end readablePublication()

	/**
	 * Save the publication with RBAC on, so OpenRegister refuses a caller who may not update it.
	 *
	 * @param string $id The publication's uuid.
	 * @param array<string, mixed> $record Its properties with the accepted value.
	 *
	 * @return void
	 *
	 * @throws MetadataSuggestionRefusedException 403 when OpenRegister refuses the write, 400 when it rejects the value.
	 */
	private function savePublication(string $id, array $record): void {
		try {
			$configuration = $this->openRegister->registerConfiguration(
				registerKey: 'publication_register',
				schemaKey: 'publication_schema'
			);
			$this->openRegister->objectService()->saveObject(
				object: $record,
				options: new ObjectSaveOptions(
					target: new ObjectFindTarget(register: $configuration['register'], schema: $configuration['schema']),
					uuid: $id
				),
				_rbac: true
			);
		} catch (Throwable $e) {
			if ($this->isRefusal(e: $e) === true) {
				throw new MetadataSuggestionRefusedException(error: 'forbidden', status: 403);
			}

			throw new MetadataSuggestionRefusedException(error: 'not-saved', status: 400);
		}
	}//end savePublication()

	/**
	 * Refuse when suggestions are not set up on this instance.
	 *
	 * @return void
	 *
	 * @throws MetadataSuggestionRefusedException 503 when the suggestion schema or OpenRegister is missing.
	 */
	private function requireConfigured(): void {
		if ($this->openRegister->isInstalled() === false || $this->store->isConfigured() === false) {
			throw new MetadataSuggestionRefusedException(error: 'not-configured', status: 503);
		}
	}//end requireConfigured()

	/**
	 * Whether OpenRegister refused the caller.
	 *
	 * @param Throwable $e What OpenRegister threw.
	 *
	 * @return bool True for a permission refusal.
	 */
	private function isRefusal(Throwable $e): bool {
		return str_ends_with(get_class($e), 'NotAuthorizedException') === true;
	}//end isRefusal()

	/**
	 * Now, as an ISO 8601 date-time in UTC.
	 *
	 * @return string The date-time.
	 */
	private function now(): string {
		return $this->time->now()->setTimezone(new DateTimeZone('UTC'))->format('c');
	}//end now()
}//end class
