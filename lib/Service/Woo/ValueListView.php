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
 * @package  OCA\OpenCatalogi\Service\Woo
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

namespace OCA\OpenCatalogi\Service\Woo;

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
	 * @param LoggerInterface      $logger  Records a choice read that failed.
	 *
	 * @spec exclude pure framework plumbing: constructor dependency injection.
	 */
	public function __construct(
		private readonly ValueListsService $lists,
		private readonly ValueListChoiceStore $choices,
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

		$entries = [];
		foreach (array_values($source) as $row) {
			$uri = (string)($row['uri'] ?? '');
			$entries[] = self::merged(row: (array)$row, choice: ($choices[$uri] ?? null));
		}

		return ['entries' => self::ordered(entries: $entries), 'choicesUnavailable' => $unavailable];

	}//end entries()

	/**
	 * One source entry with its choice applied.
	 *
	 * @param array<string, mixed>      $row    The source entry.
	 * @param array<string, mixed>|null $choice The choice (`order`, `localLabel`, `explanation`, `hidden`, `active`), or null.
	 *
	 * @return array<string, mixed> The entry: `{code, uri, sourceLabel, label, explanation, order, hidden, active, status}`.
	 */
	private static function merged(array $row, ?array $choice): array {
		$sourceLabel = (string)($row['label'] ?? '');
		$label = $sourceLabel;
		if ($choice !== null && $choice['localLabel'] !== '') {
			$label = $choice['localLabel'];
		}

		return [
			'code' => (string)($row['code'] ?? ''),
			'uri' => (string)($row['uri'] ?? ''),
			'sourceLabel' => $sourceLabel,
			'label' => $label,
			'explanation' => ($choice['explanation'] ?? ''),
			'order' => ($choice['order'] ?? null),
			'hidden' => ($choice['hidden'] ?? false),
			'active' => ($choice['active'] ?? false),
			'status' => (string)($row['status'] ?? 'active'),
		];

	}//end merged()

	/**
	 * The entries by order, then by source order; an entry without an order follows the ordered ones.
	 *
	 * @param list<array<string, mixed>> $entries The merged entries, in source order.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function ordered(array $entries): array {
		$keyed = [];
		foreach ($entries as $position => $entry) {
			$keyed[] = ['entry' => $entry, 'position' => $position];
		}

		usort(
			$keyed,
			static function (array $left, array $right): int {
				$a = $left['entry']['order'];
				$b = $right['entry']['order'];
				if ($a !== $b) {
					if ($a === null) {
						return 1;
					}

					if ($b === null) {
						return -1;
					}

					return $a <=> $b;
				}

				return $left['position'] <=> $right['position'];
			}
		);

		return array_column($keyed, 'entry');

	}//end ordered()
}//end class
