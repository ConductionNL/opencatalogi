<?php

/**
 * Contract of PublicationRuleService, for callers in other components.
 *
 * @category Contract
 * @package  OCA\OpenCatalogi\Contract
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
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Contract;


/**
 * Contract of PublicationRuleService, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 */
interface PublicationRuleServiceInterface {
	/**
	 * Whether a rule is usable at all.
	 *
	 * @param array<string, mixed> $rule The publication rule.
	 *
	 * @return array{valid: boolean, errors: array<int, string>}
	 *
	 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-a-record-type-is-readable-without-an-account-with-the-visible-parts-chosen-req-pin-101
	 */
	public function validateRule(array $rule): array;

	/**
	 * Whether a record publishes under a rule.
	 *
	 * A disabled rule publishes nothing, a rule for another type publishes
	 * nothing, and a rule whose conditions cannot be evaluated refuses rather
	 * than answering either way.
	 *
	 * @param array<string, mixed> $record The record, with `@type` or `recordType` naming its type.
	 * @param array<string, mixed> $rule The publication rule.
	 *
	 * @return boolean True when the record is public under this rule.
	 *
	 * @throws \OCA\OpenCatalogi\Service\Publication\UnreadableRuleException When a condition cannot be evaluated.
	 *
	 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-a-record-type-is-readable-without-an-account-with-the-visible-parts-chosen-req-pin-101
	 */
	public function publishes(array $record, array $rule): bool;

	/**
	 * The record as an anonymous reader may read it.
	 *
	 * Everything outside the anonymous permission set is absent, not blanked
	 * and not nulled: a property present with an empty value still tells the
	 * reader the property exists on this record.
	 *
	 * @param array<string, mixed> $record The record.
	 * @param array<string, mixed> $rule The publication rule.
	 *
	 * @return array<string, mixed> The properties the set allows, and no others.
	 *
	 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-a-record-type-is-readable-without-an-account-with-the-visible-parts-chosen-req-pin-101
	 */
	public function projectForAnonymous(array $record, array $rule): array;

	/**
	 * What a rule would publish, and what it would expose, before it is saved.
	 *
	 * A rule that is too wide is the risk this preview exists for. It runs over
	 * a sample of existing records and names both halves: which records become
	 * public, and which properties leave the building.
	 *
	 * @param array<string, mixed> $rule The draft rule.
	 * @param array<int, array<string, mixed>> $sample A sample of existing records.
	 *
	 * @return array{
	 *     valid: boolean,
	 *     errors: array<int, string>,
	 *     wouldPublish: array<int, array<string, mixed>>,
	 *     wouldNotPublish: integer,
	 *     exposedProperties: array<int, string>,
	 *     sampleSize: integer
	 * }
	 *
	 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-a-record-type-is-readable-without-an-account-with-the-visible-parts-chosen-req-pin-101
	 */
	public function preview(array $rule, array $sample): array;

	/**
	 * The anonymous view of a list of records, with anything unpublished removed.
	 *
	 * Used by the public search as well as the public read, so the search runs
	 * over what the anonymous permission set allows and no more. A search that
	 * matched a word occurring only in a withheld property would publish that
	 * property by telling the reader the record contains it.
	 *
	 * @param array<int, array<string, mixed>> $records The records.
	 * @param array<string, array<string, mixed>> $rulesByType The rules, keyed by record type.
	 *
	 * @return array<int, array<string, mixed>> The anonymous views.
	 *
	 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-the-public-searches-published-information-in-plain-words-req-pin-111
	 */
	public function projectList(array $records, array $rulesByType): array;

	/**
	 * Search a list of anonymous views in plain words.
	 *
	 * The search runs over the projections, never over the records, so a word
	 * that occurs only in a withheld property cannot return the record.
	 *
	 * @param array<int, array<string, mixed>> $records The records.
	 * @param array<string, array<string, mixed>> $rulesByType The rules, keyed by record type.
	 * @param string $terms The words searched for.
	 *
	 * @return array<int, array<string, mixed>> The matching anonymous views.
	 *
	 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-the-public-searches-published-information-in-plain-words-req-pin-111
	 */
	public function searchPublic(array $records, array $rulesByType, string $terms): array;

	/**
	 * Index a list of rules by the record type each governs.
	 *
	 * @param array<int, array<string, mixed>> $rules The rules.
	 *
	 * @return array<string, array<string, mixed>> The rules keyed by record type.
	 *
	 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-a-record-type-is-readable-without-an-account-with-the-visible-parts-chosen-req-pin-101
	 */
	public function indexByRecordType(array $rules): array;
}//end interface
