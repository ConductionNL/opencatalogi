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
use OCP\IAppConfig;
use RuntimeException;

/**
 * The suggestions OpenRegister stores for publications (design D1).
 *
 * A suggestion is an ordinary object, so OpenRegister keeps its audit trail.
 * Writes go in as the system: a suggestion is never a change to the
 * publication, and the person who decides on it is checked where the decision
 * is written (REQ-WMS-003).
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

		$register = $this->registerOrFail();
		$schema = $this->schemaOrFail();

		return $this->asArray(
			object: $this->objects()->saveObject(
				object: $record,
				register: $register,
				schema: $schema,
				_rbac: false,
				_multitenancy: false,
				_unowned: true
			)
		);
	}//end create()

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
