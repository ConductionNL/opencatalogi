<?php

/**
 * OpenCatalogi metadata suggestion store
 *
 * Reads and writes `metadataSuggestion` objects in the publication register.
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

namespace OCA\OpenCatalogi\Service\Woo;

use OCA\OpenCatalogi\Service\OpenRegisterLookup;
use OCA\OpenCatalogi\Service\ReadsObjectProperties;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\SaveActor;
use OCP\IAppConfig;
use RuntimeException;
use Throwable;

/**
 * The suggestions OpenRegister stores for publications (design D1).
 *
 * A suggestion is an ordinary object, so OpenRegister keeps its audit trail.
 * Writes go in as the system: a suggestion is never a change to the
 * publication, and the person who decides on it is checked where the decision
 * is written (REQ-WMS-003).
 *
 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-missing-woo-metadata-is-proposed-from-data-the-instance-holds-req-wms-001
 */
class MetadataSuggestionStore {
	use ReadsObjectProperties;

	/**
	 * A suggestion waiting for a person.
	 *
	 * @var string
	 */
	public const STATUS_PENDING = 'pending';

	/**
	 * A suggestion a person accepted: its value is on the publication.
	 *
	 * @var string
	 */
	public const STATUS_ACCEPTED = 'accepted';

	/**
	 * A suggestion a person rejected: the publication is unchanged.
	 *
	 * @var string
	 */
	public const STATUS_REJECTED = 'rejected';

	/**
	 * The most suggestions read for one publication.
	 *
	 * @var int
	 */
	private const PAGE = 100;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $config Holds the register and schema identifiers.
	 * @param OpenRegisterLookup $openRegister Whether OpenRegister is installed, and its ObjectService.
	 */
	public function __construct(
		private readonly IAppConfig $config,
		private readonly OpenRegisterLookup $openRegister,
	) {

	}//end __construct()

	/**
	 * Whether the suggestion schema is configured, so suggestions can be stored at all.
	 *
	 * @return bool True when both the register and the schema are set.
	 *
	 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-missing-woo-metadata-is-proposed-from-data-the-instance-holds-req-wms-001
	 */
	public function isConfigured(): bool {
		return $this->registerId() !== '' && $this->schemaId() !== '';
	}//end isConfigured()

	/**
	 * The configured suggestion schema id, empty when unset.
	 *
	 * @return string The schema id.
	 *
	 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-missing-woo-metadata-is-proposed-from-data-the-instance-holds-req-wms-001
	 */
	public function schemaId(): string {
		return trim($this->config->getValueString('opencatalogi', 'metadata_suggestion_schema', ''));
	}//end schemaId()

	/**
	 * Every suggestion recorded for a publication.
	 *
	 * @param string $publicationId The publication's uuid.
	 *
	 * @return array<int, array<string, mixed>> The suggestions.
	 *
	 * @throws RuntimeException When the store is not configured or OpenRegister is unavailable.
	 *
	 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-missing-woo-metadata-is-proposed-from-data-the-instance-holds-req-wms-001
	 */
	public function forPublication(string $publicationId): array {
		$result = $this->objects()->searchObjectsPaginated(
			query: [
				'@self' => ['register' => $this->registerOrFail(), 'schema' => $this->schemaOrFail()],
				'publication' => $publicationId,
				'_limit' => self::PAGE,
			],
			_rbac: false,
			_multitenancy: false
		);

		$suggestions = [];
		foreach ((array)($result['results'] ?? []) as $row) {
			$suggestions[] = $this->asArray(object: $row);
		}

		return $suggestions;
	}//end forPublication()

	/**
	 * Store one proposal as a pending suggestion on a publication.
	 *
	 * @param array<string, mixed> $proposal The proposal: field, value, valueLabel, source, reason.
	 * @param string $publicationId The publication's uuid.
	 *
	 * @return array<string, mixed> The stored suggestion.
	 *
	 * @throws RuntimeException When the store is not configured or the write fails.
	 *
	 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-missing-woo-metadata-is-proposed-from-data-the-instance-holds-req-wms-001
	 */
	public function create(array $proposal, string $publicationId): array {
		$record = [
			'publication' => $publicationId,
			'field' => (string)($proposal['field'] ?? ''),
			'value' => (string)($proposal['value'] ?? ''),
			'valueLabel' => (string)($proposal['valueLabel'] ?? ''),
			'source' => (string)($proposal['source'] ?? ''),
			'reason' => (string)($proposal['reason'] ?? ''),
			'status' => self::STATUS_PENDING,
		];

		return $this->write(record: $record, uuid: null);
	}//end create()

	/**
	 * One suggestion by its uuid, read as the system.
	 *
	 * The caller decides who may see or change it, through the publication it
	 * belongs to (REQ-WMS-003).
	 *
	 * @param string $id The suggestion's uuid.
	 *
	 * @return array<string, mixed>|null The suggestion, or null when there is none with that uuid.
	 *
	 * @throws RuntimeException When the store is not configured or OpenRegister is unavailable.
	 *
	 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-nothing-is-written-until-a-person-accepts-req-wms-003
	 */
	public function find(string $id): ?array {
		$register = $this->registerOrFail();
		$schema = $this->schemaOrFail();
		$objects = $this->objects();

		try {
			$found = $objects->find(
				id: $id,
				options: new ObjectFindOptions(register: $register, schema: $schema),
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			if (str_ends_with(get_class($e), 'DoesNotExistException') === true) {
				return null;
			}

			throw $e;
		}

		if ($found === null) {
			return null;
		}

		$suggestion = $this->asArray(object: $found);
		if ($suggestion === []) {
			return null;
		}

		$suggestion['id'] = (string)($suggestion['id'] ?? $id);

		return $suggestion;
	}//end find()

	/**
	 * Record a person's decision on a suggestion: who decided, when, and which way.
	 *
	 * @param array<string, mixed> $suggestion The suggestion as find() returned it.
	 * @param string $status STATUS_ACCEPTED or STATUS_REJECTED.
	 * @param string $decidedBy The deciding user's uid.
	 * @param string $decidedAt When, as an ISO 8601 date-time.
	 *
	 * @return array<string, mixed> The stored suggestion.
	 *
	 * @throws RuntimeException When the status is not a decision, or the store is not configured, or the write fails.
	 *
	 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-nothing-is-written-until-a-person-accepts-req-wms-003
	 */
	public function decide(array $suggestion, string $status, string $decidedBy, string $decidedAt): array {
		if ($status !== self::STATUS_ACCEPTED && $status !== self::STATUS_REJECTED) {
			throw new RuntimeException(message: 'A suggestion is decided as accepted or rejected, not "' . $status . '".');
		}

		$uuid = (string)($suggestion['id'] ?? '');
		$record = $suggestion;
		unset($record['id'], $record['@self']);
		$record['status'] = $status;
		$record['decidedBy'] = $decidedBy;
		$record['decidedAt'] = $decidedAt;

		return $this->write(record: $record, uuid: $uuid);
	}//end decide()

	/**
	 * Save a suggestion record as the system, new or by uuid.
	 *
	 * @param array<string, mixed> $record The record.
	 * @param string|null $uuid The suggestion to update, null for a new one.
	 *
	 * @return array<string, mixed> The stored suggestion.
	 *
	 * @throws RuntimeException When the store is not configured or the write fails.
	 */
	private function write(array $record, ?string $uuid): array {
		$register = $this->registerOrFail();
		$schema = $this->schemaOrFail();

		return $this->asArray(
			object: $this->objects()->saveObject(
				object: $record,
				options: new ObjectSaveOptions(
					target: new ObjectFindTarget(register: $register, schema: $schema),
					uuid: $uuid,
					actor: new SaveActor(unowned: true)
				),
				_rbac: false,
				_multitenancy: false
			)
		);
	}//end write()

	/**
	 * The configured publication register id, empty when unset.
	 *
	 * @return string The register id.
	 */
	private function registerId(): string {
		return trim($this->config->getValueString('opencatalogi', 'publication_register', ''));
	}//end registerId()

	/**
	 * The register id, refusing when unset.
	 *
	 * @return string The register id.
	 *
	 * @throws RuntimeException When it is unset.
	 */
	private function registerOrFail(): string {
		$register = $this->registerId();
		if ($register === '') {
			throw new RuntimeException(message: 'Register configuration is not set for publication_register.');
		}

		return $register;
	}//end registerOrFail()

	/**
	 * The schema id, refusing when unset.
	 *
	 * @return string The schema id.
	 *
	 * @throws RuntimeException When it is unset.
	 */
	private function schemaOrFail(): string {
		$schema = $this->schemaId();
		if ($schema === '') {
			throw new RuntimeException(message: 'Register configuration is not set for metadata_suggestion_schema.');
		}

		return $schema;
	}//end schemaOrFail()

	/**
	 * The OpenRegister ObjectService.
	 *
	 * @return object The service.
	 *
	 * @throws RuntimeException When OpenRegister is not installed.
	 */
	private function objects(): object {
		if ($this->openRegister->isInstalled() === false) {
			throw new RuntimeException(message: 'OpenRegister is not installed, so metadata suggestions cannot be stored.');
		}

		return $this->openRegister->objectService();
	}//end objects()
}//end class
