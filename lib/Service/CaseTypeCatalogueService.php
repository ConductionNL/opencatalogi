<?php

/**
 * OpenCatalogi Case Type Catalogue Service.
 *
 * Case types published with their form and their API description, and case
 * types taken from a published external catalogue instead of typed into a
 * blank form. An import records where it came from and what it held at that
 * moment; a resynchronisation shows the difference against the source and
 * flags what was changed locally, and applies nothing until it is accepted.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/published-service-and-case-type-catalogue/specs/published-service-and-case-type-catalogue/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\OpenCatalogi\Contract\CaseTypeCatalogueServiceInterface;
use OCA\OpenCatalogi\Service\Catalogue\CaseTypeSourceReader;
use OCA\OpenCatalogi\Service\Catalogue\ExternalCatalogueUnreachableException;

/**
 * Imports and resynchronises case type definitions.
 *
 * @spec openspec/changes/published-service-and-case-type-catalogue/specs/published-service-and-case-type-catalogue/spec.md
 */
class CaseTypeCatalogueService implements CaseTypeCatalogueServiceInterface {

	/**
	 * The properties an import takes from the source.
	 *
	 * Anything outside this list is local and is never overwritten by a
	 * resynchronisation, accepted or not.
	 *
	 * @var array<int, string>
	 */
	public const SOURCED_PROPERTIES = [
		'identifier',
		'title',
		'description',
		'publicationText',
		'inspectionTermDays',
	];

	/**
	 * Constructor.
	 *
	 * @param CaseTypeSourceReader $sourceReader The reader for the external catalogue.
	 */
	public function __construct(
		private readonly CaseTypeSourceReader $sourceReader,
	) {

	}//end __construct()

	/**
	 * Import one definition from a registered external catalogue.
	 *
	 * The returned definition carries its source, the version imported, the
	 * moment of the import, and the snapshot of what arrived. The snapshot is
	 * what a later resynchronisation compares the local values against, so a
	 * property an administrator edited afterwards can be told apart from one
	 * that never moved.
	 *
	 * @param string $sourceId The registered external catalogue.
	 * @param string $externalId The identifier of the definition at the source.
	 * @param DateTimeInterface|null $now The moment of the import; defaults to now.
	 *
	 * @return array<string, mixed> The local definition to save.
	 *
	 * @throws ExternalCatalogueUnreachableException When the source cannot be asked.
	 *
	 * @spec openspec/changes/published-service-and-case-type-catalogue/specs/published-service-and-case-type-catalogue/spec.md#requirement-case-types-are-imported-from-a-published-external-catalogue-and-resynchronised-req-psc-103
	 */
	public function importDefinition(string $sourceId, string $externalId, ?DateTimeInterface $now = null): array {
		$remote = $this->sourceReader->fetchDefinition(sourceId: $sourceId, externalId: $externalId);

		$moment = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')));
		$snapshot = $this->sourcedValues(definition: $remote);

		$definition = $snapshot;
		$definition['source'] = [
			'sourceId' => $sourceId,
			'externalId' => $externalId,
			'version' => (string)($remote['version'] ?? ''),
			'importedAt' => $moment->format(DateTimeInterface::ATOM),
		];
		$definition['importedSnapshot'] = $snapshot;

		return $definition;
	}//end importDefinition()

	/**
	 * The properties an import takes, read off a definition.
	 *
	 * @param array<string, mixed> $definition The definition.
	 *
	 * @return array<string, mixed> The sourced properties present on it.
	 */
	private function sourcedValues(array $definition): array {
		$values = [];
		foreach (self::SOURCED_PROPERTIES as $property) {
			if (array_key_exists($property, $definition) === true) {
				$values[$property] = $definition[$property];
			}
		}

		return $values;
	}//end sourcedValues()

	/**
	 * The difference between a local definition and its source, before anything applies.
	 *
	 * Every entry names the property, what the local definition holds, what the
	 * source now holds, and whether the local value was changed after the
	 * import. A locally changed property is flagged because accepting the
	 * source's value for it throws away somebody's deliberate edit.
	 *
	 * @param array<string, mixed> $local The local definition, carrying its importedSnapshot.
	 * @param string $sourceId The registered external catalogue.
	 * @param string $externalId The identifier of the definition at the source.
	 *
	 * @return array{sourceVersion: string, changes: array<int, array<string, mixed>>, locallyChanged: array<int, string>}
	 *
	 * @throws ExternalCatalogueUnreachableException When the source cannot be asked.
	 *
	 * @spec openspec/changes/published-service-and-case-type-catalogue/specs/published-service-and-case-type-catalogue/spec.md#requirement-case-types-are-imported-from-a-published-external-catalogue-and-resynchronised-req-psc-103
	 */
	public function diffAgainstSource(array $local, string $sourceId, string $externalId): array {
		$remote = $this->sourceReader->fetchDefinition(sourceId: $sourceId, externalId: $externalId);

		return [
			'sourceVersion' => (string)($remote['version'] ?? ''),
			'changes' => $this->changes(local: $local, remote: $remote),
			'locallyChanged' => $this->locallyChanged(local: $local),
		];

	}//end diffAgainstSource()

	/**
	 * Apply the properties an administrator accepted, and nothing else.
	 *
	 * A property that is not named in `$accepted` keeps its local value, even
	 * when the source changed it. An empty accepted list changes nothing, which
	 * is the state a previewed but unaccepted resynchronisation leaves behind.
	 *
	 * @param array<string, mixed> $local The local definition.
	 * @param array{sourceVersion: string, changes: array<int, array<string, mixed>>, locallyChanged: array<int, string>} $diff The diff.
	 * @param array<int, string> $accepted The properties the administrator accepted.
	 * @param DateTimeInterface|null $now The moment of the resynchronisation.
	 *
	 * @return array<string, mixed> The definition to save.
	 *
	 * @spec openspec/changes/published-service-and-case-type-catalogue/specs/published-service-and-case-type-catalogue/spec.md#requirement-case-types-are-imported-from-a-published-external-catalogue-and-resynchronised-req-psc-103
	 */
	public function applyResync(array $local, array $diff, array $accepted, ?DateTimeInterface $now = null): array {
		$applied = $this->acceptedValues(diff: $diff, accepted: $accepted);
		if ($applied === []) {
			return $local;
		}

		$snapshot = $this->listField(definition: $local, key: 'importedSnapshot');
		foreach ($applied as $property => $value) {
			$local[$property] = $value;
			$snapshot[$property] = $value;
		}

		$local['importedSnapshot'] = $snapshot;

		$source = $this->listField(definition: $local, key: 'source');
		$source['version'] = (string)($diff['sourceVersion'] ?? ($source['version'] ?? ''));
		$source['importedAt'] = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
		$local['source'] = $source;

		return $local;
	}//end applyResync()

	/**
	 * The sourced properties whose local value differs from the source's, in
	 * the order of SOURCED_PROPERTIES.
	 *
	 * @param array<string, mixed> $local The local definition.
	 * @param array<string, mixed> $remote The definition at the source.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function changes(array $local, array $remote): array {
		$edited = $this->locallyChanged(local: $local);
		$changes = [];
		foreach (self::SOURCED_PROPERTIES as $property) {
			$localValue = ($local[$property] ?? null);
			$sourceValue = ($remote[$property] ?? null);
			if ($localValue !== $sourceValue) {
				$changes[] = [
					'property' => $property,
					'localValue' => $localValue,
					'sourceValue' => $sourceValue,
					'locallyChanged' => in_array($property, $edited, true),
				];
			}
		}

		return $changes;
	}//end changes()

	/**
	 * The sourced properties changed locally since the import: those whose
	 * value differs from the imported snapshot.
	 *
	 * @param array<string, mixed> $local The local definition.
	 *
	 * @return array<int, string>
	 */
	private function locallyChanged(array $local): array {
		$snapshot = $this->listField(definition: $local, key: 'importedSnapshot');
		$changed = [];
		foreach (self::SOURCED_PROPERTIES as $property) {
			if (($local[$property] ?? null) !== ($snapshot[$property] ?? null)) {
				$changed[] = $property;
			}
		}

		return $changed;
	}//end locallyChanged()

	/**
	 * The source values of the accepted changes, by property; a later change
	 * of the same property wins.
	 *
	 * @param array<string, mixed> $diff The diff.
	 * @param array<int, string> $accepted The properties the administrator accepted.
	 *
	 * @return array<string, mixed>
	 */
	private function acceptedValues(array $diff, array $accepted): array {
		$values = [];
		foreach (($diff['changes'] ?? []) as $change) {
			$property = (string)($change['property'] ?? '');
			if ($property !== '' && in_array($property, $accepted, true) === true) {
				$values[$property] = $change['sourceValue'];
			}
		}

		return $values;
	}//end acceptedValues()

	/**
	 * A field of a definition that holds a list, or an empty list when it
	 * is absent or not a list.
	 *
	 * @param array<string, mixed> $definition The definition.
	 * @param string $key The field.
	 *
	 * @return array<string, mixed>
	 */
	private function listField(array $definition, string $key): array {
		$value = ($definition[$key] ?? []);
		if (is_array($value) === false) {
			return [];
		}

		return $value;
	}//end listField()

	/**
	 * The links a published case type carries.
	 *
	 * An administrator uses the form, an integrator the API description, and
	 * both are published beside the definition rather than hunted for.
	 *
	 * @param array<string, mixed> $definition The case type definition.
	 *
	 * @return array{form: string|null, apiDescription: string|null, complete: boolean}
	 *
	 * @spec openspec/changes/published-service-and-case-type-catalogue/specs/published-service-and-case-type-catalogue/spec.md#requirement-a-published-case-type-links-to-its-form-and-its-api-description-req-psc-102
	 */
	public function publishedLinks(array $definition): array {
		$form = trim((string)($definition['formUrl'] ?? ''));
		$api = trim((string)($definition['apiDescriptionUrl'] ?? ''));

		$formLink = null;
		if ($form !== '') {
			$formLink = $form;
		}

		$apiLink = null;
		if ($api !== '') {
			$apiLink = $api;
		}

		return [
			'form' => $formLink,
			'apiDescription' => $apiLink,
			'complete' => ($form !== '' && $api !== ''),
		];

	}//end publishedLinks()

	/**
	 * The date an imported definition was last synchronised, for the surface to show.
	 *
	 * @param array<string, mixed> $definition The case type definition.
	 *
	 * @return string|null The ISO 8601 moment, or null when the definition was never imported.
	 *
	 * @spec openspec/changes/published-service-and-case-type-catalogue/specs/published-service-and-case-type-catalogue/spec.md#requirement-case-types-are-imported-from-a-published-external-catalogue-and-resynchronised-req-psc-103
	 */
	public function syncedAt(array $definition): ?string {
		$source = ($definition['source'] ?? null);
		if (is_array($source) === false) {
			return null;
		}

		$importedAt = trim((string)($source['importedAt'] ?? ''));
		if ($importedAt === '') {
			return null;
		}

		return $importedAt;
	}//end syncedAt()
}//end class
