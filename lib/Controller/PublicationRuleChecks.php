<?php

/**
 * Publication rules and decision validation, for the publication rules controller.
 *
 * @category Controller
 * @package  OCA\OpenCatalogi\Controller
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
 * @spec openspec/specs/publication-inspection-and-the-national-indexes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\Contract\DecisionPublicationValidatorInterface;
use OCA\OpenCatalogi\Contract\PublicationRuleServiceInterface;
use OCA\OpenCatalogi\Contract\PublishedRecordViewInterface;

/**
 * Previews a publication rule, searches what the rules make public, and
 * validates a decision for publication.
 *
 * @spec openspec/specs/publication-inspection-and-the-national-indexes/spec.md
 */
class PublicationRuleChecks {

	/**
	 * Constructor.
	 *
	 * @param PublicationRuleServiceInterface $ruleService The publication rules.
	 * @param DecisionPublicationValidatorInterface $decisionValidator Checks a decision before it is published.
	 * @param PublishedRecordViewInterface|null $publishedRecords The stored records the published collections make public.
	 *
	 * @spec exclude pure framework plumbing: constructor dependency injection.
	 */
	public function __construct(
		private readonly PublicationRuleServiceInterface $ruleService,
		private readonly DecisionPublicationValidatorInterface $decisionValidator,
		private readonly ?PublishedRecordViewInterface $publishedRecords = null,
	) {

	}//end __construct()

	/**
	 * Preview a rule over the array entries of a sample.
	 *
	 * @param array $rule The rule.
	 * @param array $sample The sample records; entries that are not arrays are left out.
	 *
	 * @return array The preview.
	 *
	 * @throws \OCA\OpenCatalogi\Service\Publication\UnreadableRuleException When the rule cannot be read.
	 *
	 * @spec openspec/specs/publication-inspection-and-the-national-indexes/spec.md
	 */
	public function preview(array $rule, array $sample): array {
		return $this->ruleService->preview(rule: $rule, sample: array_values(array_filter($sample, 'is_array')));
	}//end preview()

	/**
	 * Search the anonymous views the rules make public.
	 *
	 * @param array{records: array, rules: array, terms: string} $query The records, rules and search terms; non-array entries are left out.
	 *
	 * @return array<int, array<string, mixed>> The matching anonymous views.
	 *
	 * @throws \OCA\OpenCatalogi\Service\Publication\UnreadableRuleException When a rule cannot be evaluated.
	 *
	 * @spec openspec/specs/publication-inspection-and-the-national-indexes/spec.md
	 */
	public function searchPublic(array $query): array {
		return $this->ruleService->searchPublic(
			records: array_values(array_filter($query['records'], 'is_array')),
			rulesByType: $this->ruleService->indexByRecordType(
				rules: array_values(array_filter($query['rules'], 'is_array'))
			),
			terms: $query['terms']
		);

	}//end searchPublic()

	/**
	 * Search posted records under posted rules, or, with no records posted, the stored records.
	 *
	 * The stored records are those of every configured published collection.
	 * Without the stored-record view nothing is stored to search, so that
	 * answer is empty.
	 *
	 * @param array{records: array|null, rules: array, terms: string} $query The records (null for the stored ones), rules and search terms.
	 *
	 * @return array<int, array<string, mixed>> The matching anonymous views.
	 *
	 * @throws \OCA\OpenCatalogi\Service\Publication\UnreadableRuleException When a rule, the configuration or a condition cannot be read.
	 *
	 * @spec openspec/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-the-public-searches-published-information-in-plain-words-req-pin-111
	 */
	public function search(array $query): array {
		if ($query['records'] !== null) {
			return $this->searchPublic(query: $query);
		}

		if ($this->publishedRecords === null) {
			return [];
		}

		return $this->publishedRecords->searchStored(terms: $query['terms']);

	}//end search()

	/**
	 * Validate a decision for publication.
	 *
	 * @param array $decision The decision.
	 * @param array $decisionType The decision type.
	 *
	 * @return array{publishable: boolean, reasons: array<int, string>, responseDate: string|null, publicationText: string|null}
	 *
	 * @spec openspec/specs/publication-inspection-and-the-national-indexes/spec.md
	 */
	public function validateDecision(array $decision, array $decisionType): array {
		return $this->decisionValidator->validate(decision: $decision, decisionType: $decisionType);
	}//end validateDecision()
}//end class
