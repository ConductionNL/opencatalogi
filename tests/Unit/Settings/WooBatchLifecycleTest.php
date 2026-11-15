<?php

/**
 * WooBatchLifecycleTest
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
 * @spec openspec/changes/woo-review-surface/tasks.md#task-1.1
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Settings;

use OCA\OpenCatalogi\Lifecycle\RecordWooBatchRejectionAction;
use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use PHPUnit\Framework\TestCase;

/**
 * The `wooBatch` schema declares its lifecycle, the batchReview approval chain
 * and the notification to the author on a rejection (REQ-WRV-002).
 *
 * @coversNothing
 */
final class WooBatchLifecycleTest extends TestCase {

	/**
	 * The fragment that declares the Woo schemas.
	 */
	private const FRAGMENT = __DIR__ . '/../../../lib/Settings/register.d/fix-woo-capability-provisioning.json';

	/**
	 * The declared `wooBatch` schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function batch(): array {
		$fragment = json_decode((string)file_get_contents(self::FRAGMENT), true, flags: JSON_THROW_ON_ERROR);
		return $fragment['components']['schemas']['wooBatch'];
	}//end batch()

	/**
	 * The four states, the four transitions with the reason required on
	 * reject, the approval chain on approve and the rejection notification.
	 *
	 * @return void
	 */
	public function testTheBatchDeclaresItsLifecycleAndApprovalChain(): void {
		$batch     = self::batch();
		$lifecycle = $batch['configuration']['x-openregister-lifecycle'];

		$this->assertSame('0.3.0', $batch['version']);
		$this->assertSame(['in_progress', 'ready_for_review', 'approved', 'published'], $batch['properties']['status']['enum']);
		$this->assertSame('status', $lifecycle['field']);
		$this->assertSame('in_progress', $lifecycle['initial']);
		$moves = [];
		foreach ($lifecycle['transitions'] as $action => $spec) {
			$moves[$action] = [$spec['from'], $spec['to']];
		}

		$this->assertSame(
			[
				'submit' => [['in_progress'], 'ready_for_review'],
				'approve' => [['ready_for_review'], 'approved'],
				'reject' => [['ready_for_review'], 'in_progress'],
				'publish' => [['approved'], 'published'],
			],
			$moves
		);
		$this->assertSame([['field' => 'reason', 'required' => true]], $lifecycle['transitions']['reject']['inputs']);
		$this->assertSame(
			['batchReview' => ['transition' => 'approve', 'approvers' => [['role' => 'woo-reviewer', 'min' => 1]]]],
			$batch['configuration']['x-openregister-approval-chains']
		);

		$rule = $batch['x-openregister-notifications']['batchRejected'];
		$this->assertSame(['type' => 'transition', 'action' => 'reject'], $rule['trigger']);
		$this->assertSame([['kind' => 'field', 'field' => 'assignee']], $rule['recipients']);
		$this->assertStringContainsString('{{lastRejectionReason}}', $rule['subject']['nl']);
		$this->assertStringContainsString('{{caseReference}}', $rule['subject']['en']);
		foreach (['assignee', 'rejections', 'lastRejectionReason'] as $property) {
			$this->assertArrayHasKey($property, $batch['properties']);
		}
	}//end testTheBatchDeclaresItsLifecycleAndApprovalChain()

	/**
	 * The action the reject transition names is a lifecycle action class that exists.
	 *
	 * @return void
	 */
	public function testTheRejectActionIsALifecycleAction(): void {
		$actions = self::batch()['configuration']['x-openregister-lifecycle']['transitions']['reject']['actions'];

		$this->assertSame([['action' => RecordWooBatchRejectionAction::class]], $actions);
		$this->assertTrue(is_subclass_of(RecordWooBatchRejectionAction::class, LifecycleActionInterface::class));
	}//end testTheRejectActionIsALifecycleAction()
}//end class
