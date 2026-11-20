<?php

/**
 * Compiles a feed's per-reason conflict overrides into OpenRegister's `conflictRules`.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Harvest
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
 * @spec openspec/changes/harvest-conflict-policies/specs/harvest-conflict-policies/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Harvest;

/**
 * Turns "Different per situation" into a decision table, and reads one back.
 *
 * The table has the `flow-decision-tables` shape: hit policy `FIRST`, one input
 * `conflictReason`, one output `strategy`, one row per overridden reason. A
 * reason left on "Same as the default" gets no row, and no override at all
 * writes no table, so OpenRegister falls back to the feed's `conflictStrategy`.
 * A table this compiler did not write (another input than `conflictReason`) is
 * foreign: it is shown as "Set in OpenRegister" and kept as it is.
 *
 * @spec openspec/changes/harvest-conflict-policies/specs/harvest-conflict-policies/spec.md
 *
 * @SuppressWarnings(PHPMD.StaticAccess) ConflictPolicy is a constant mapping.
 */
final class ConflictRulesCompiler {

	/**
	 * The select value for a reason that follows the feed's default.
	 *
	 * @var string
	 */
	public const SAME_AS_DEFAULT = 'default';

	/**
	 * The `conflictRules` to store for the chosen overrides.
	 *
	 * @param array<string, string> $overrides Reason => choice, or SAME_AS_DEFAULT.
	 * @param mixed                 $existing  The table the feed carries now.
	 *
	 * @return array<string, mixed>|null The table, or null for none.
	 *
	 * @spec openspec/changes/harvest-conflict-policies/specs/harvest-conflict-policies/spec.md
	 */
	public static function compile(array $overrides, mixed $existing = null): ?array {
		if (self::isForeign(table: $existing) === true) {
			return $existing;
		}

		$rules = [];
		foreach (ConflictPolicy::OVERRIDABLE_REASONS as $reason) {
			$choice = ($overrides[$reason] ?? self::SAME_AS_DEFAULT);
			if ($choice === self::SAME_AS_DEFAULT) {
				continue;
			}

			$rules[] = [
				'id' => $reason,
				'inputEntries' => ['"' . $reason . '"'],
				'outputEntries' => [ConflictPolicy::strategyFor(choice: $choice)],
			];
		}

		if ($rules === []) {
			return null;
		}

		return [
			'hitPolicy' => 'FIRST',
			'inputs' => [['name' => 'conflictReason', 'type' => 'string']],
			'outputs' => [['name' => 'strategy', 'type' => 'string']],
			'rules' => $rules,
		];
	}//end compile()

	/**
	 * The modal's selects for a stored table.
	 *
	 * @param mixed $table The stored `conflictRules`.
	 *
	 * @return array{foreign: bool, overrides: array<string, string>} Whether the
	 *         table is someone else's, and each reason's choice.
	 *
	 * @spec openspec/changes/harvest-conflict-policies/specs/harvest-conflict-policies/spec.md
	 */
	public static function read(mixed $table): array {
		$overrides = array_fill_keys(ConflictPolicy::OVERRIDABLE_REASONS, self::SAME_AS_DEFAULT);
		if (self::isForeign(table: $table) === true) {
			return ['foreign' => true, 'overrides' => $overrides];
		}

		foreach (self::rulesOf(table: $table) as $rule) {
			$reason = self::literal(cell: ($rule['inputEntries'][0] ?? null));
			if (array_key_exists($reason, $overrides) === true) {
				$overrides[$reason] = ConflictPolicy::choiceFor(strategy: self::literal(cell: ($rule['outputEntries'][0] ?? null)));
			}
		}

		return ['foreign' => false, 'overrides' => $overrides];
	}//end read()

	/**
	 * Whether a stored table was written by someone other than this compiler.
	 *
	 * @param mixed $table The stored `conflictRules`.
	 *
	 * @return bool True for a table with inputs other than exactly `conflictReason`.
	 */
	private static function isForeign(mixed $table): bool {
		if (is_array($table) === false || $table === []) {
			return false;
		}

		$inputs = [];
		foreach ((array)($table['inputs'] ?? []) as $input) {
			$inputs[] = $input;
			if (is_array($input) === true) {
				$inputs[array_key_last($inputs)] = ($input['name'] ?? null);
			}
		}

		return $inputs !== ['conflictReason'];
	}//end isForeign()

	/**
	 * The rule rows of a table, or none.
	 *
	 * @param mixed $table The table.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private static function rulesOf(mixed $table): array {
		if (is_array($table) === false || is_array($table['rules'] ?? null) === false) {
			return [];
		}

		return array_values(array_filter($table['rules'], 'is_array'));
	}//end rulesOf()

	/**
	 * A cell's string literal without its quotes.
	 *
	 * @param mixed $cell The cell.
	 *
	 * @return string The literal, or '' for a cell that is not a string.
	 */
	private static function literal(mixed $cell): string {
		if (is_string($cell) === false) {
			return '';
		}

		return trim(trim($cell), '"');
	}//end literal()
}//end class
