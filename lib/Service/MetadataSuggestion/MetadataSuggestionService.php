<?php

/**
 * OpenCatalogi Woo metadata suggestions: the rules floor.
 *
 * Proposes a value for a Woo field a publication lacks, from data the instance
 * already holds, with the source and a reason an editor can check. Every value
 * is checked against the TOOI and DiWoo value lists first; a value that does not
 * resolve is not proposed. Nothing is written to the publication here: a
 * suggestion waits for a person to accept it (woo-metadata-suggestions
 * REQ-WMS-001, REQ-WMS-003).
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service
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
 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-missing-woo-metadata-is-proposed-from-data-the-instance-holds-req-wms-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\MetadataSuggestion;

use OCA\OpenCatalogi\Contract\MetadataRuleSuggesterInterface;
use OCA\OpenCatalogi\Contract\InformationCategoryRegistryInterface;
use OCA\OpenCatalogi\Service\TooiVocabularyService;

/**
 * Proposes missing Woo metadata from data the instance holds.
 *
 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-missing-woo-metadata-is-proposed-from-data-the-instance-holds-req-wms-001
 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-hermiq-may-add-suggestions-never-replace-them-req-wms-002
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) One rule per Woo field, each a short method; splitting them would scatter the rule set.
 */
class MetadataSuggestionService implements MetadataRuleSuggesterInterface {

	/**
	 * The source of a suggestion made by these rules.
	 *
	 * @var string
	 */
	public const SOURCE_RULES = 'rules';

	/**
	 * How a record arrived, mapped to the handling type that implies: a record a
	 * synchronisation brought in was received, one a case app handed over was
	 * adopted there.
	 *
	 * @var array<string, string>
	 */
	public const HANDLING_BY_ORIGIN = [
		'synchronisation' => 'ontvangst',
		'case' => 'vaststelling',
	];

	/**
	 * Why the handling type is proposed, by how the record arrived.
	 *
	 * @var array<string, string>
	 */
	private const HANDLING_REASON_BY_ORIGIN = [
		'synchronisation' => 'The record was brought in by a synchronisation, so the document was received.',
		'case' => 'The record was handed over by a case application, so the document was adopted there.',
	];

	/**
	 * Why a Hermiq value is proposed.
	 */
	private const HERMIQ_REASON = 'Hermiq proposed this value from the text of the publication\'s documents.';

	/**
	 * Constructor.
	 *
	 * @param TooiVocabularyService $tooiVocabulary The TOOI and DiWoo value lists.
	 * @param InformationCategoryRegistryInterface $categories The information categories and the schemas each one covers.
	 */
	public function __construct(
		private readonly TooiVocabularyService $tooiVocabulary,
		private readonly InformationCategoryRegistryInterface $categories,
	) {

	}//end __construct()

	/**
	 * The rules floor: one proposal per missing Woo field the instance can fill.
	 *
	 * - `wooCategory`: the one information category whose sitemap covers the
	 *   publication's schema. None, or more than one, proposes nothing.
	 * - `organization`: the catalogue's organisation, when it carries a valid TOOI
	 *   organisatie identifier.
	 * - `soortHandeling`: `ontvangst` for a record a synchronisation brought in,
	 *   `vaststelling` for one a case app handed over.
	 *
	 * A field that already has a value gets no proposal, and neither does a field
	 * and value a suggestion already exists for.
	 *
	 * @param array<string, mixed> $publication The publication as stored.
	 * @param array<string, mixed> $context What the instance knows around the publication:
	 *                                      `schemaSlug`, `catalogOrganization` (`id`, `name`,
	 *                                      `tooiIdentifier`), `origin` and the `existing`
	 *                                      suggestions (`field`, `value`).
	 *
	 * @return array<int, array{field: string, value: string, valueLabel: string, source: string, reason: string}> The proposals.
	 *
	 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-missing-woo-metadata-is-proposed-from-data-the-instance-holds-req-wms-001
	 */
	public function suggestFromRules(array $publication, array $context=[]): array {
		$proposals = [
			$this->categoryProposal(publication: $publication, schemaSlug: ($context['schemaSlug'] ?? null)),
			$this->organisationProposal(publication: $publication, organisation: ($context['catalogOrganization'] ?? null)),
			$this->handlingProposal(publication: $publication, origin: ($context['origin'] ?? null)),
		];

		$existing = [];
		foreach (($context['existing'] ?? []) as $suggestion) {
			$existing[] = ($suggestion['field'] ?? '') . "\0" . ($suggestion['value'] ?? '');
		}

		$out = [];
		foreach ($proposals as $proposal) {
			if ($proposal === null || in_array($proposal['field'] . "\0" . $proposal['value'], $existing, true) === true) {
				continue;
			}

			$out[] = $proposal;
		}

		return $out;
	}//end suggestFromRules()

	/**
	 * The fields Hermiq is asked about: the value-list fields the publication lacks.
	 *
	 * Each carries its allowed values as options, so Hermiq can only pick from
	 * the TOOI and DiWoo lists. Free-text fields are not asked for here.
	 *
	 * @param array<string, mixed> $publication The publication as stored.
	 *
	 * @return array<int, array{key: string, label: string, type: string, options: array<int, string>}> The fields.
	 *
	 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-hermiq-may-add-suggestions-never-replace-them-req-wms-002
	 */
	public function hermiqFields(array $publication): array {
		$fields = [];
		$categories = array_keys($this->categoriesByLabel());
		if ($this->isSet(value: ($publication['wooCategory'] ?? null)) === false && $categories !== []) {
			$fields[] = ['key' => 'wooCategory', 'label' => 'Woo information category', 'type' => 'select', 'options' => $categories];
		}

		if ($this->isSet(value: ($publication['soortHandeling'] ?? null)) === false) {
			$fields[] = [
				'key' => 'soortHandeling',
				'label' => 'Handling type of the document',
				'type' => 'select',
				'options' => array_keys($this->tooiVocabulary->soortHandelingList()),
			];
		}

		return $fields;
	}//end hermiqFields()

	/**
	 * Hermiq's values as proposals, checked against the same value lists as the rules floor.
	 *
	 * A value that does not resolve, a field the publication already has, and a
	 * field and value a suggestion already exists for are dropped. Hermiq only
	 * adds: nothing here changes or removes an existing suggestion.
	 *
	 * @param array<string, mixed> $publication The publication as stored.
	 * @param array<string, mixed> $values Hermiq's values by field key.
	 * @param array<int, array<string, mixed>> $existing The suggestions already recorded (`field`, `value`).
	 *
	 * @return array<int, array{field: string, value: string, valueLabel: string, source: string, reason: string}> The proposals.
	 *
	 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-hermiq-may-add-suggestions-never-replace-them-req-wms-002
	 */
	public function fromHermiq(array $publication, array $values, array $existing=[]): array {
		$asked = array_column($this->hermiqFields(publication: $publication), 'key');
		$taken = array_map(
			fn (array $suggestion): string => ($suggestion['field'] ?? '') . "\0" . ($suggestion['value'] ?? ''),
			$existing
		);

		$out = [];
		foreach ($values as $field => $value) {
			$proposal = $this->askedHermiqProposal(asked: $asked, field: $field, value: $value);
			if ($proposal !== null && in_array($proposal['field'] . "\0" . $proposal['value'], $taken, true) === false) {
				$out[] = $proposal;
			}
		}

		return $out;
	}//end fromHermiq()

	/**
	 * One Hermiq value as a proposal, or null when the field was not asked or the value is no string.
	 *
	 * @param array<int, mixed> $asked The field keys Hermiq was asked for.
	 * @param int|string $field The field.
	 * @param mixed $value The value Hermiq chose.
	 *
	 * @return array{field: string, value: string, valueLabel: string, source: string, reason: string}|null The proposal.
	 *
	 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-hermiq-may-add-suggestions-never-replace-them-req-wms-002
	 */
	private function askedHermiqProposal(array $asked, int|string $field, mixed $value): ?array {
		if (in_array($field, $asked, true) === false || is_string($value) === false) {
			return null;
		}

		return $this->hermiqProposal(field: (string)$field, value: $value);
	}//end askedHermiqProposal()

	/**
	 * One Hermiq value as a proposal, or null when it does not resolve against its value list.
	 *
	 * @param string $field The field.
	 * @param string $value The value Hermiq chose.
	 *
	 * @return array{field: string, value: string, valueLabel: string, source: string, reason: string}|null The proposal.
	 */
	private function hermiqProposal(string $field, string $value): ?array {
		if ($field === 'soortHandeling') {
			return $this->hermiqHandlingProposal(field: $field, value: $value);
		}

		$record = ($this->categoriesByLabel()[$value] ?? null);
		if ($this->resolvedCategory(record: $record) === null) {
			return null;
		}

		$code = (string)$record['code'];

		return $this->proposal(field: $field, value: $code, valueLabel: $value, reason: self::HERMIQ_REASON, source: self::SOURCE_HERMIQ);
	}//end hermiqProposal()

	/**
	 * Hermiq's handling type as a proposal, or null when it does not resolve against the TOOI list.
	 *
	 * @param string $field The field.
	 * @param string $value The value Hermiq chose.
	 *
	 * @return array{field: string, value: string, valueLabel: string, source: string, reason: string}|null The proposal.
	 *
	 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-hermiq-may-add-suggestions-never-replace-them-req-wms-002
	 */
	private function hermiqHandlingProposal(string $field, string $value): ?array {
		$member = $this->tooiVocabulary->resolveSoortHandeling(trim($value));
		if ($member === null || trim($value) === '') {
			return null;
		}

		$label = $member['label'];

		return $this->proposal(field: $field, value: $label, valueLabel: $label, reason: self::HERMIQ_REASON, source: self::SOURCE_HERMIQ);
	}//end hermiqHandlingProposal()

	/**
	 * The TOOI member of a category record, or null without a record or a resolvable URI.
	 *
	 * @param array<string, mixed>|null $record The category record.
	 *
	 * @return array<string, mixed>|null The TOOI member.
	 *
	 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-missing-woo-metadata-is-proposed-from-data-the-instance-holds-req-wms-001
	 */
	private function resolvedCategory(?array $record): ?array {
		if ($record === null) {
			return null;
		}

		return $this->tooiVocabulary->resolveInformatiecategorie((string)($record['tooiUri'] ?? ''));
	}//end resolvedCategory()

	/**
	 * The information categories by label.
	 *
	 * @return array<string, array<string, mixed>> The category records, keyed by label.
	 */
	private function categoriesByLabel(): array {
		$out = [];
		foreach ($this->categories->all() as $code => $record) {
			$label = (string)($record['label'] ?? '');
			if ($label !== '') {
				$out[$label] = $record + ['code' => (string)$code];
			}
		}

		return $out;
	}//end categoriesByLabel()

	/**
	 * Propose the information category whose sitemap covers the schema, when it is the only one.
	 *
	 * @param array<string, mixed> $publication The publication.
	 * @param string|null $schemaSlug The publication's schema slug.
	 *
	 * @return array{field: string, value: string, valueLabel: string, source: string, reason: string}|null The proposal.
	 *
	 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-missing-woo-metadata-is-proposed-from-data-the-instance-holds-req-wms-001
	 */
	private function categoryProposal(array $publication, ?string $schemaSlug): ?array {
		if ($this->isSet(value: ($publication['wooCategory'] ?? null)) === true || $schemaSlug === null || $schemaSlug === '') {
			return null;
		}

		$record = $this->soleCategoryFor(schemaSlug: $schemaSlug);
		$member = $this->resolvedCategory(record: $record);
		if ($member === null) {
			return null;
		}

		$label  = (string)($record['label'] ?? $member['label']);
		$reason = sprintf('The publication is in schema "%s", which only the category "%s" publishes.', $schemaSlug, $label);

		return $this->proposal(field: 'wooCategory', value: (string)$record['code'], valueLabel: $label, reason: $reason);
	}//end categoryProposal()

	/**
	 * The one category whose sitemap covers the schema, or null when none or several do.
	 *
	 * @param string $schemaSlug The schema slug.
	 *
	 * @return array<string, mixed>|null The category record.
	 *
	 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-missing-woo-metadata-is-proposed-from-data-the-instance-holds-req-wms-001
	 */
	private function soleCategoryFor(string $schemaSlug): ?array {
		$matches = [];
		foreach ($this->categories->all() as $code => $record) {
			if (in_array($schemaSlug, $this->categories->schemasFor(code: (string)$code), true) === true) {
				$matches[] = $record;
			}
		}

		if (count($matches) !== 1) {
			return null;
		}

		return $matches[0];
	}//end soleCategoryFor()

	/**
	 * Propose the catalogue's organisation, when it has a valid TOOI identifier.
	 *
	 * @param array<string, mixed> $publication The publication.
	 * @param array<string, mixed>|null $organisation The catalogue's organisation.
	 *
	 * @return array{field: string, value: string, valueLabel: string, source: string, reason: string}|null The proposal.
	 *
	 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-missing-woo-metadata-is-proposed-from-data-the-instance-holds-req-wms-001
	 */
	private function organisationProposal(array $publication, ?array $organisation): ?array {
		if ($this->isSet(value: ($publication['organization'] ?? null)) === true || $organisation === null) {
			return null;
		}

		$id   = (string)($organisation['id'] ?? '');
		$tooi = $this->organisationTooi(organisation: $organisation);
		if ($id === '' || $tooi === null) {
			return null;
		}

		$name = (string)($organisation['name'] ?? $id);

		$reason = sprintf('The catalogue is published by "%s" (%s).', $name, $tooi);

		return $this->proposal(field: 'organization', value: $id, valueLabel: $name, reason: $reason);
	}//end organisationProposal()

	/**
	 * The organisation's resolved TOOI URI, or null when it has no valid identifier.
	 *
	 * @param array<string, mixed> $organisation The organisation.
	 *
	 * @return string|null The TOOI URI.
	 *
	 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-missing-woo-metadata-is-proposed-from-data-the-instance-holds-req-wms-001
	 */
	private function organisationTooi(array $organisation): ?string {
		$tooiIdentifier = null;
		if (isset($organisation['tooiIdentifier']) === true) {
			$tooiIdentifier = (string)$organisation['tooiIdentifier'];
		}

		return $this->tooiVocabulary->resolveOrganisatie($tooiIdentifier);
	}//end organisationTooi()

	/**
	 * Propose the handling type implied by how the record arrived.
	 *
	 * @param array<string, mixed> $publication The publication.
	 * @param string|null $origin How the record arrived: `synchronisation` or `case`.
	 *
	 * @return array{field: string, value: string, valueLabel: string, source: string, reason: string}|null The proposal.
	 *
	 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-missing-woo-metadata-is-proposed-from-data-the-instance-holds-req-wms-001
	 */
	private function handlingProposal(array $publication, ?string $origin): ?array {
		if ($this->isSet(value: ($publication['soortHandeling'] ?? null)) === true || isset(self::HANDLING_BY_ORIGIN[$origin ?? '']) === false) {
			return null;
		}

		$member = $this->tooiVocabulary->resolveSoortHandeling(self::HANDLING_BY_ORIGIN[$origin]);
		if ($member === null) {
			return null;
		}

		$label = $member['label'];

		return $this->proposal(field: 'soortHandeling', value: $label, valueLabel: $label, reason: self::HANDLING_REASON_BY_ORIGIN[$origin]);
	}//end handlingProposal()

	/**
	 * Whether a field holds a value.
	 *
	 * @param mixed $value The field's value.
	 *
	 * @return bool True for anything but null, an empty string or an empty list.
	 */
	private function isSet(mixed $value): bool {
		if (is_string($value) === true) {
			return trim($value) !== '';
		}

		return $value !== null && $value !== [];
	}//end isSet()

	/**
	 * One proposal.
	 *
	 * @param string $field The publication field.
	 * @param string $value The proposed value.
	 * @param string $valueLabel The value as an editor reads it.
	 * @param string $reason Why it is proposed.
	 * @param string $source Who proposed it: SOURCE_RULES or SOURCE_HERMIQ.
	 *
	 * @return array{field: string, value: string, valueLabel: string, source: string, reason: string} The proposal.
	 */
	private function proposal(string $field, string $value, string $valueLabel, string $reason, string $source=self::SOURCE_RULES): array {
		return [
			'field' => $field,
			'value' => $value,
			'valueLabel' => $valueLabel,
			'source' => $source,
			'reason' => $reason,
		];
	}//end proposal()
}//end class
