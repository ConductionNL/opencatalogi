<?php

/**
 * OpenCatalogi Publication Rule Service.
 *
 * Publication is a standing rule about a record type, not a person picking
 * rows: for this type, these parts are public, to a reader with no account,
 * under these conditions. Records follow the rule and nobody picks, because a
 * picked list goes stale the moment a new record arrives, and going stale here
 * means failing to publish something the law says must be published.
 *
 * The anonymous permission set is enforced here, where the read happens, and
 * not by a renderer that leaves a field out. A field absent from the page and
 * present in the API is the shape of every accidental disclosure.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Publication
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
 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Publication;

use DateTimeImmutable;

/**
 * Decides what publishes and what an anonymous reader may read of it.
 *
 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md
 */
class PublicationRuleService {

	/**
	 * The operators a condition may use.
	 *
	 * An operator outside this list is not silently ignored: an unreadable
	 * condition makes the whole rule refuse, because a rule nobody can evaluate
	 * must not be read as a rule that matches everything, and equally must not
	 * be read as one that matches nothing.
	 *
	 * @var array<int, string>
	 */
	public const OPERATORS = ['equals', 'notEquals', 'exists', 'notEmpty', 'in', 'before', 'after'];

	/**
	 * Whether a rule is usable at all.
	 *
	 * @param array<string, mixed> $rule The publication rule.
	 *
	 * @return array{valid: boolean, errors: array<int, string>}
	 *
	 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-a-record-type-is-readable-without-an-account-with-the-visible-parts-chosen-req-pin-101
	 */
	public function validateRule(array $rule): array {
		$errors = [];

		if (trim((string)($rule['recordType'] ?? '')) === '') {
			$errors[] = 'A publication rule must name the record type it governs.';
		}

		$properties = ($rule['anonymousProperties'] ?? null);
		if (is_array($properties) === false || $properties === []) {
			$errors[] = 'A publication rule must name the properties an anonymous reader may read, even if that is none.';
		}

		foreach (($rule['conditions'] ?? []) as $index => $condition) {
			if (is_array($condition) === false) {
				$errors[] = 'Condition ' . $index . ' cannot be read.';
				continue;
			}

			if (trim((string)($condition['property'] ?? '')) === '') {
				$errors[] = 'Condition ' . $index . ' names no property.';
			}

			$operator = (string)($condition['operator'] ?? '');
			if (in_array($operator, self::OPERATORS, true) === false) {
				$errors[] = 'Condition ' . $index . ' uses an operator this app does not know: "' . $operator . '".';
			}
		}

		return [
			'valid' => ($errors === []),
			'errors' => $errors,
		];

	}//end validateRule()

	/**
	 * Whether one record meets one condition.
	 *
	 * @param array<string, mixed> $record The record.
	 * @param array<string, mixed> $condition The condition.
	 *
	 * @return boolean True when it holds.
	 *
	 * @throws UnreadableRuleException When the condition cannot be evaluated.
	 */
	private function conditionHolds(array $record, array $condition): bool {
		$property = (string)($condition['property'] ?? '');
		$operator = (string)($condition['operator'] ?? '');
		$expected = ($condition['value'] ?? null);
		$actual = ($record[$property] ?? null);

		return match ($operator) {
			'equals' => ($actual === $expected),
			'notEquals' => ($actual !== $expected),
			'exists' => array_key_exists($property, $record),
			'notEmpty' => ($actual !== null && $actual !== '' && $actual !== []),
			'in' => (is_array($expected) === true && in_array($actual, $expected, true) === true),
			'before' => $this->compareDates(actual: $actual, expected: $expected, before: true),
			'after' => $this->compareDates(actual: $actual, expected: $expected, before: false),
			default => throw new UnreadableRuleException(
				message: 'This rule uses an operator this app does not know: "' . $operator . '".'
			),
		};

	}//end conditionHolds()

	/**
	 * Compare two dates, refusing rather than guessing when either will not parse.
	 *
	 * @param mixed $actual The record's value.
	 * @param mixed $expected The condition's value.
	 * @param boolean $before True for before, false for after.
	 *
	 * @return boolean The comparison.
	 *
	 * @throws UnreadableRuleException When either side is not a date.
	 */
	private function compareDates(mixed $actual, mixed $expected, bool $before): bool {
		try {
			$left = new DateTimeImmutable((string)$actual);
			$right = new DateTimeImmutable((string)$expected);
		} catch (\Throwable $e) {
			throw new UnreadableRuleException(
				message: 'This rule compares dates, and one of them cannot be read as a date.',
				code: 0,
				previous: $e
			);
		}

		if ($before === true) {
			return ($left < $right);
		}

		return ($left > $right);

	}//end compareDates()

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
	 * @throws UnreadableRuleException When a condition cannot be evaluated.
	 *
	 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-a-record-type-is-readable-without-an-account-with-the-visible-parts-chosen-req-pin-101
	 */
	public function publishes(array $record, array $rule): bool {
		if ((bool)($rule['enabled'] ?? false) === false) {
			return false;
		}

		$recordType = (string)($record['@type'] ?? ($record['recordType'] ?? ''));
		if ($recordType !== (string)($rule['recordType'] ?? '')) {
			return false;
		}

		foreach (($rule['conditions'] ?? []) as $condition) {
			if (is_array($condition) === false) {
				throw new UnreadableRuleException(message: 'This rule carries a condition that cannot be read.');
			}

			if ($this->conditionHolds(record: $record, condition: $condition) === false) {
				return false;
			}
		}

		return true;

	}//end publishes()

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
	public function projectForAnonymous(array $record, array $rule): array {
		$allowed = ($rule['anonymousProperties'] ?? []);
		if (is_array($allowed) === false) {
			$allowed = [];
		}

		$projected = [];
		foreach ($allowed as $property) {
			$property = (string)$property;
			if (array_key_exists($property, $record) === true) {
				$projected[$property] = $record[$property];
			}
		}

		$publicationText = trim((string)($rule['publicationText'] ?? ''));
		if ($publicationText !== '') {
			$projected['publicationText'] = $publicationText;
		}

		return $projected;

	}//end projectForAnonymous()

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
	 * @return array{valid: boolean, errors: array<int, string>, wouldPublish: array<int, array<string, mixed>>, wouldNotPublish: integer, exposedProperties: array<int, string>, sampleSize: integer}
	 *
	 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-a-record-type-is-readable-without-an-account-with-the-visible-parts-chosen-req-pin-101
	 */
	public function preview(array $rule, array $sample): array {
		$validation = $this->validateRule(rule: $rule);
		if ($validation['valid'] === false) {
			return [
				'valid' => false,
				'errors' => $validation['errors'],
				'wouldPublish' => [],
				'wouldNotPublish' => 0,
				'exposedProperties' => [],
				'sampleSize' => count($sample),
			];
		}

		// The preview answers about the rule as it would be saved, so it is run
		// with the rule enabled even when the draft is not. A preview that
		// always answered "nothing" for a draft rule would be a check that
		// cannot see the thing it judges.
		$asSaved = $rule;
		$asSaved['enabled'] = true;

		$wouldPublish = [];
		$wouldNot = 0;
		$exposed = [];

		foreach ($sample as $record) {
			if ($this->publishes(record: $record, rule: $asSaved) === false) {
				$wouldNot++;
				continue;
			}

			$projected = $this->projectForAnonymous(record: $record, rule: $asSaved);
			$wouldPublish[] = $projected;
			$exposed = array_merge($exposed, array_keys($projected));
		}

		$exposed = array_values(array_unique($exposed));
		sort($exposed);

		return [
			'valid' => true,
			'errors' => [],
			'wouldPublish' => $wouldPublish,
			'wouldNotPublish' => $wouldNot,
			'exposedProperties' => $exposed,
			'sampleSize' => count($sample),
		];

	}//end preview()

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
	public function projectList(array $records, array $rulesByType): array {
		$projected = [];

		foreach ($records as $record) {
			$recordType = (string)($record['@type'] ?? ($record['recordType'] ?? ''));
			$rule = ($rulesByType[$recordType] ?? null);
			if (is_array($rule) === false) {
				continue;
			}

			if ($this->publishes(record: $record, rule: $rule) === false) {
				continue;
			}

			$view = $this->projectForAnonymous(record: $record, rule: $rule);

			// The dossier is named on every result, because a document read out
			// of its dossier is the commonest way a published answer is read
			// wrongly. It is a reference, never the dossier's own contents.
			$dossier = ($record['dossier'] ?? null);
			if ($dossier !== null) {
				$view['dossier'] = $dossier;
			}

			$projected[] = $view;
		}

		return $projected;

	}//end projectList()

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
	public function searchPublic(array $records, array $rulesByType, string $terms): array {
		$needle = trim(mb_strtolower($terms));
		$views = $this->projectList(records: $records, rulesByType: $rulesByType);

		if ($needle === '') {
			return $views;
		}

		return array_values(
			array_filter(
				$views,
				static function (array $view) use ($needle): bool {
					$haystack = mb_strtolower((string)json_encode($view));

					return str_contains($haystack, $needle);
				}
			)
		);

	}//end searchPublic()

	/**
	 * Index a list of rules by the record type each governs.
	 *
	 * @param array<int, array<string, mixed>> $rules The rules.
	 *
	 * @return array<string, array<string, mixed>> The rules keyed by record type.
	 */
	public function indexByRecordType(array $rules): array {
		$indexed = [];
		foreach ($rules as $rule) {
			$recordType = trim((string)($rule['recordType'] ?? ''));
			if ($recordType === '') {
				continue;
			}

			$indexed[$recordType] = $rule;
		}

		return $indexed;

	}//end indexByRecordType()
}//end class
