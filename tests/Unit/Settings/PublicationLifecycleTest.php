<?php

/**
 * PublicationLifecycleTest
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/publication-lifecycle-on-or/tasks.md#task-1.4
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Settings;

use OCA\OpenCatalogi\Service\SettingsService;
use PHPUnit\Framework\TestCase;

/**
 * The moves the shipped lifecycle allows from each state, read the way OpenRegister reads them:
 * a named transition runs only from a state in its `from`, and a list-form `status` edit is
 * accepted only when some transition goes from the stored state to the new one (REQ-PLC-001).
 *
 * The live answers of OpenRegister's transition route for the same two refusals are in the
 * pull request that ticked task 1.4.
 *
 * @coversNothing
 */
final class PublicationLifecycleTest extends TestCase {

	/**
	 * The transitions of the shipped publication lifecycle.
	 *
	 * @return array<string, array{from: array<int, string>, to: string}>
	 */
	private static function transitions(): array {
		$schema = SettingsService::shippedSchema(dirname(__DIR__, 3) . '/lib/Settings', 'publication');
		return $schema['x-openregister-lifecycle']['transitions'];
	}//end transitions()

	/**
	 * The named transitions allowed from a state.
	 *
	 * @param string $state The stored state.
	 *
	 * @return array<int, string> The action names, sorted.
	 */
	private static function actionsFrom(string $state): array {
		$actions = [];
		foreach (self::transitions() as $name => $transition) {
			if (in_array($state, $transition['from'], true) === true) {
				$actions[] = $name;
			}
		}

		sort($actions);
		return $actions;
	}//end actionsFrom()

	/**
	 * Whether some transition moves the stored state to the new one, as a list-form edit needs.
	 *
	 * @param string $from The stored state.
	 * @param string $to   The state the edit writes.
	 *
	 * @return bool
	 */
	private static function someTransitionMoves(string $from, string $to): bool {
		foreach (self::transitions() as $transition) {
			if ($transition['to'] === $to && in_array($from, $transition['from'], true) === true) {
				return true;
			}
		}

		return false;
	}//end someTransitionMoves()

	/**
	 * Publishing a draft by the `publish` name is not a move from draft.
	 *
	 * @return void
	 */
	public function testAnIllegalMoveIsRefusedByName(): void {
		self::assertNotContains('publish', self::actionsFrom('draft'));
		self::assertContains('publish', self::actionsFrom('approved'));
	}//end testAnIllegalMoveIsRefusedByName()

	/**
	 * A list-form edit from in review straight to published skips the approval, and no transition does that.
	 *
	 * @return void
	 */
	public function testAListFormEditThatSkipsReviewIsRefused(): void {
		self::assertFalse(self::someTransitionMoves('in_review', 'published'));
		self::assertTrue(self::someTransitionMoves('in_review', 'approved'));
	}//end testAListFormEditThatSkipsReviewIsRefused()

	/**
	 * The moves offered per state follow the declared table.
	 *
	 * @return void
	 */
	public function testAvailableActionsFollowTheState(): void {
		self::assertSame(['publishWithoutReview', 'submit'], self::actionsFrom('draft'));
		self::assertSame(['approve', 'reject'], self::actionsFrom('in_review'));
		self::assertContains('publish', self::actionsFrom('approved'));
		self::assertContains('retract', self::actionsFrom('approved'));
		self::assertNotContains('submit', self::actionsFrom('published'));
	}//end testAvailableActionsFollowTheState()
}//end class
