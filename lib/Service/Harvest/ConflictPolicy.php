<?php

/**
 * The conflict policies a harvest feed offers, and the OpenRegister strategy each stores.
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

use InvalidArgumentException;

/**
 * Maps the feed modal's four policy choices onto OpenRegister's `conflictStrategy`.
 *
 * OpenRegister owns what a strategy does on a collision (detection, states and
 * resolution); OpenCatalogi only names the choice in its own words and stores
 * the strategy. `newest-wins` is deliberately not offered: a DCAT `dct:modified`
 * and a local `updated` come from two clocks.
 *
 * @spec openspec/changes/harvest-conflict-policies/specs/harvest-conflict-policies/spec.md
 */
final class ConflictPolicy {

	/**
	 * The default choice: a collision waits for a person.
	 *
	 * @var string
	 */
	public const DEFAULT_CHOICE = 'manual-review';

	/**
	 * Each choice and the OpenRegister strategy it stores, in the modal's order.
	 *
	 * @var array<string, string>
	 */
	public const STRATEGIES = [
		'manual-review' => 'manual',
		'overlay' => 'source-wins',
		'shadow-local' => 'local-wins',
		'reject-on-conflict' => 'reject',
	];

	/**
	 * The collision reasons a feed may override. `local-deleted` is not among
	 * them: OpenRegister always queues a locally deleted publication.
	 *
	 * @var array<int, string>
	 */
	public const OVERRIDABLE_REASONS = ['local-edited', 'pre-existing-claim'];

	/**
	 * The OpenRegister strategy a choice stores.
	 *
	 * @param string|null $choice The modal's choice; null or empty is the default.
	 *
	 * @return string The `conflictStrategy` value.
	 *
	 * @throws InvalidArgumentException When the choice is not one of the four.
	 *
	 * @spec openspec/changes/harvest-conflict-policies/specs/harvest-conflict-policies/spec.md
	 */
	public static function strategyFor(?string $choice): string {
		if ($choice === null || $choice === '') {
			return self::STRATEGIES[self::DEFAULT_CHOICE];
		}

		if (isset(self::STRATEGIES[$choice]) === false) {
			throw new InvalidArgumentException('Unknown conflict policy: ' . $choice);
		}

		return self::STRATEGIES[$choice];
	}//end strategyFor()

	/**
	 * The modal's choice for a stored strategy.
	 *
	 * A feed saved without a strategy, or with one the modal does not offer
	 * (such as `newest-wins` set in OpenRegister), reads as "Ask a person".
	 *
	 * @param string|null $strategy The stored `conflictStrategy`.
	 *
	 * @return string The choice.
	 *
	 * @spec openspec/changes/harvest-conflict-policies/specs/harvest-conflict-policies/spec.md
	 */
	public static function choiceFor(?string $strategy): string {
		$choice = array_search($strategy, self::STRATEGIES, true);
		if ($choice === false) {
			return self::DEFAULT_CHOICE;
		}

		return $choice;
	}//end choiceFor()
}//end class
