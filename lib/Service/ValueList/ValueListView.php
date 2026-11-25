<?php

/**
 * OpenCatalogi Value List View.
 *
 * One national value list as this organisation uses it: every entry of the
 * list, read through {@see ValueListsService} (which reads each list through
 * the service that owns it, so a list that moves to OpenRegister's vocabulary
 * register moves here with it), merged with the organisation's choice about
 * that entry from {@see ValueListChoiceStore}.
 *
 * The normative fields (code, URI, the source's label) always come from the
 * list; the choice only adds the organisation's own label, explanation, order
 * and the hidden and active flags. A refresh of the list therefore changes the
 * source label and leaves the organisation's label as it was.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\ValueList
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @version GIT: <git_id>
 * @link https://www.OpenCatalogi.nl
 * @spec openspec/changes/woo-value-list-curation/tasks.md#task-1.1
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\ValueList;

use OCA\OpenCatalogi\Service\ValueListsService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Merges a value list with the organisation's choices about its entries.
 *
 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-the-organisations-own-text-sits-beside-the-normative-entry-req-wvl-001
 */
class ValueListView {

	/**
	 * Constructor.
	 *
	 * @param ValueListsService    $lists   The national lists, each through the service that owns it.
	 * @param ValueListChoiceStore $choices The organisation's choices.
	 * @param ValueListMerge       $merge   Applies the choices to the list and orders it.
	 * @param LoggerInterface      $logger  Records a choice read that failed.
	 *
	 * @spec exclude pure framework plumbing: constructor dependency injection.
	 */
	public function __construct(
		private readonly ValueListsService $lists,
		private readonly ValueListChoiceStore $choices,
		private readonly ValueListMerge $merge,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Every entry of one list with the organisation's choice applied, in the organisation's order.
	 *
	 * Entries with an order come first, lowest first; the rest follow in the
	 * list's own order. When the choices cannot be read, the entries are
	 * answered in source order with no choice applied and `choicesUnavailable`
	 * set, so an error never hides an entry.
	 *
	 * @param string $scheme The list, one of {@see ValueListsService::SCHEMES}.
	 *
	 * @return array{entries: list<array<string, mixed>>, choicesUnavailable: bool} Each entry is
	 *         `{code, uri, sourceLabel, label, explanation, order, hidden, active, status}`.
	 *
	 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-the-organisations-own-text-sits-beside-the-normative-entry-req-wvl-001
	 */
	public function entries(string $scheme): array {
		$source = (array)($this->lists->entries(scheme: $scheme)['results'] ?? []);

		$unavailable = false;
		try {
			$choices = $this->choices->forScheme(scheme: $scheme);
		} catch (Throwable $e) {
			$this->logger->warning(
				'OpenCatalogi could not read the value list choices, so the list is shown in source order: ' . $e->getMessage(),
				['app' => 'opencatalogi', 'scheme' => $scheme]
			);
			$choices = [];
			$unavailable = true;
		}

		return ['entries' => $this->merge->merge(source: $source, choices: $choices), 'choicesUnavailable' => $unavailable];

	}//end entries()

	/**
	 * Save the organisation's choice about one entry of a list.
	 *
	 * @param array<string, mixed> $choice The choice: `scheme`, `entry` and the fields to change.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When the choice cannot be saved; the pre-save guard's refusal included.
	 *
	 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-a-category-explanation-is-served-publicly-req-wvl-003
	 */
	public function save(array $choice): void {
		$this->choices->save(choice: $choice);

	}//end save()

	/**
	 * Save the order of a list, as the entry URIs in the order the administrator set.
	 *
	 * @param string       $scheme  The list.
	 * @param list<string> $entries The entry URIs, first first.
	 *
	 * @return int How many choices were written.
	 *
	 * @throws \RuntimeException When the order cannot be saved.
	 *
	 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-the-administrators-order-drives-every-pick-list-req-wvl-002
	 */
	public function saveOrder(string $scheme, array $entries): int {
		return $this->choices->saveOrder(scheme: $scheme, entries: $entries);

	}//end saveOrder()

}//end class
