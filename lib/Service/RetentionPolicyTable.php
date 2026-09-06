<?php

/**
 * Translates stored retention defaults into a DMN decision table.
 *
 * The RET-004 category match is delegated to OpenRegister's shared
 * decision-table evaluator (hydra ADR-065, One Engine); this class owns the
 * one remaining app-side step, translating the `retention_defaults`
 * app-config shape into the evaluator's table grammar. It never matches
 * rules itself.
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
 * @spec openspec/changes/retention-defaults-on-shared-decision-tables/specs/publication-retention-lifecycle/spec.md#requirement-per-catalog-retention-defaults-per-woo-information-category-ret-004
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service;

/**
 * Builds the RET-004 decision table for the shared OR DMN evaluator.
 *
 * Pure and stateless; the default-constructible shape mirrors OR's own
 * evaluator so it can be `new`ed as a promoted-parameter default while the
 * container autowires it under DI.
 *
 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-per-catalog-retention-defaults-per-woo-information-category-ret-004
 */
class RetentionPolicyTable {

	/**
	 * Translate one catalog's stored retention defaults into a DMN decision table.
	 *
	 * Grammar (shared-decision-tables): input `category` (string); outputs
	 * `termMonths` (number) and `action` (string); hit policy FIRST. Each
	 * configured category becomes one rule with a quoted-literal input cell
	 * (quoting keeps a category spelled like an operator, or `-`, an exact
	 * match; `"` is escaped as `\"`). A configured `_fallback` becomes the
	 * LAST rule with the `-` catch-all cell, so under FIRST the specific
	 * category always beats the fallback — exactly the old in-app precedence.
	 * Rows that are not arrays are skipped; a missing `termMonths`/`action`
	 * key becomes a null output entry, which the caller skips when filling.
	 *
	 * @param array<string, mixed> $catalogDefaults One catalog's defaults map (category => rule, `_fallback` => rule).
	 *
	 * @return array<string, mixed>|null The decision-table definition, or null when no usable row is configured.
	 *
	 * @spec openspec/changes/retention-defaults-on-shared-decision-tables/specs/publication-retention-lifecycle/spec.md#requirement-per-catalog-retention-defaults-per-woo-information-category-ret-004
	 */
	public function fromDefaults(array $catalogDefaults): ?array {
		$rules = [];
		$fallbackRule = null;
		foreach ($catalogDefaults as $category => $rule) {
			if (is_array($rule) === false) {
				continue;
			}

			$outputEntries = [null, null];
			if (isset($rule['termMonths']) === true) {
				$outputEntries[0] = (int)$rule['termMonths'];
			}

			if (isset($rule['action']) === true) {
				$outputEntries[1] = (string)$rule['action'];
			}

			if ((string)$category === '_fallback') {
				$fallbackRule = ['id' => '_fallback', 'inputEntries' => ['-'], 'outputEntries' => $outputEntries];
				continue;
			}

			$cell = '"' . str_replace('"', '\\"', (string)$category) . '"';
			$rules[] = ['id' => 'cat:' . (string)$category, 'inputEntries' => [$cell], 'outputEntries' => $outputEntries];
		}

		if ($fallbackRule !== null) {
			$rules[] = $fallbackRule;
		}

		if (count($rules) === 0) {
			return null;
		}

		return [
			'hitPolicy' => 'FIRST',
			'inputs' => [['name' => 'category', 'type' => 'string']],
			'outputs' => [
				['name' => 'termMonths', 'type' => 'number'],
				['name' => 'action', 'type' => 'string'],
			],
			'rules' => $rules,
		];
	}//end fromDefaults()
}//end class
