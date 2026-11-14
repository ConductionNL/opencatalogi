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

namespace OCA\OpenCatalogi\Service;

use OCA\OpenCatalogi\Contract\InformationCategoryRegistryInterface;

/**
 * Proposes missing Woo metadata from data the instance holds.
 */
class WooMetadataSuggestionService {

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

		$matches = [];
		foreach ($this->categories->all() as $code => $record) {
			if (in_array($schemaSlug, $this->categories->schemasFor(code: (string)$code), true) === true) {
				$matches[] = $record;
			}
		}

		if (count($matches) !== 1) {
			return null;
		}

		$record = $matches[0];
		$member = $this->tooiVocabulary->resolveInformatiecategorie((string)($record['tooiUri'] ?? ''));
		if ($member === null) {
			return null;
		}

		return $this->proposal(
			field: 'wooCategory',
			value: (string)$record['code'],
			valueLabel: (string)($record['label'] ?? $member['label']),
			reason: sprintf(
				'The publication is in schema "%s", which only the category "%s" publishes.',
				$schemaSlug,
				(string)($record['label'] ?? $member['label'])
			)
		);
	}//end categoryProposal()

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

		$id = (string)($organisation['id'] ?? '');
		$tooiIdentifier = null;
		if (isset($organisation['tooiIdentifier']) === true) {
			$tooiIdentifier = (string)$organisation['tooiIdentifier'];
		}

		$tooi = $this->tooiVocabulary->resolveOrganisatie($tooiIdentifier);
		if ($id === '' || $tooi === null) {
			return null;
		}

		$name = (string)($organisation['name'] ?? $id);

		return $this->proposal(
			field: 'organization',
			value: $id,
			valueLabel: $name,
			reason: sprintf('The catalogue is published by "%s" (%s).', $name, $tooi)
		);
	}//end organisationProposal()

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

		$reason = 'The record was brought in by a synchronisation, so the document was received.';
		if ($origin === 'case') {
			$reason = 'The record was handed over by a case application, so the document was adopted there.';
		}

		return $this->proposal(field: 'soortHandeling', value: $member['label'], valueLabel: $member['label'], reason: $reason);
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
	 * One rules proposal.
	 *
	 * @param string $field The publication field.
	 * @param string $value The proposed value.
	 * @param string $valueLabel The value as an editor reads it.
	 * @param string $reason Why it is proposed.
	 *
	 * @return array{field: string, value: string, valueLabel: string, source: string, reason: string} The proposal.
	 */
	private function proposal(string $field, string $value, string $valueLabel, string $reason): array {
		return [
			'field' => $field,
			'value' => $value,
			'valueLabel' => $valueLabel,
			'source' => self::SOURCE_RULES,
			'reason' => $reason,
		];
	}//end proposal()
}//end class
