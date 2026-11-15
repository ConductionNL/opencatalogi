<?php

/**
 * RecordWooBatchRejectionActionTest
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Lifecycle
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

namespace OCA\OpenCatalogi\Tests\Unit\Lifecycle;

use OCA\OpenCatalogi\Lifecycle\RecordWooBatchRejectionAction;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A rejection is recorded with reason, reviewer and moment, and the batch goes back to its author.
 *
 * @covers \OCA\OpenCatalogi\Lifecycle\RecordWooBatchRejectionAction
 */
final class RecordWooBatchRejectionActionTest extends TestCase {

	/**
	 * The action for a signed-in reviewer, or nobody.
	 *
	 * @param string|null $uid The reviewer's uid.
	 *
	 * @return RecordWooBatchRejectionAction
	 */
	private function action(?string $uid): RecordWooBatchRejectionAction {
		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);
		return new RecordWooBatchRejectionAction($session);
	}//end action()

	/**
	 * The second rejection is appended with its reason, reviewer and time; the
	 * batch is assigned to its author and the input `reason` is not kept.
	 *
	 * @return void
	 */
	public function testARejectionIsRecordedAndTheAuthorIsAssigned(): void {
		$earlier = ['reason' => 'Bijlage 1 ontbreekt', 'by' => 'bram', 'at' => '2026-11-01T09:00:00+00:00'];
		$batch   = $this->action('bram')->execute(
			['id' => 'batch-1', 'status' => 'in_progress', 'createdBy' => 'anna', 'reason' => ' Bijlage 3 bevat nog een BSN ', 'rejections' => [$earlier]],
			['id' => 'batch-1', 'status' => 'ready_for_review', 'createdBy' => 'anna'],
			[],
			RecordWooBatchRejectionAction::class
		);

		$this->assertArrayNotHasKey('reason', $batch);
		$this->assertSame('anna', $batch['assignee']);
		$this->assertSame('Bijlage 3 bevat nog een BSN', $batch['lastRejectionReason']);
		$this->assertCount(2, $batch['rejections']);
		$this->assertSame($earlier, $batch['rejections'][0]);
		$this->assertSame('Bijlage 3 bevat nog een BSN', $batch['rejections'][1]['reason']);
		$this->assertSame('bram', $batch['rejections'][1]['by']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $batch['rejections'][1]['at']);
		$this->assertSame('in_progress', $batch['status']);
	}//end testARejectionIsRecordedAndTheAuthorIsAssigned()

	/**
	 * Without a session the rejection names the system; the author comes from the previous payload when needed.
	 *
	 * @return void
	 */
	public function testASystemRejectionNamesTheSystem(): void {
		$batch = $this->action(null)->execute(['reason' => 'Termijn verlopen'], ['createdBy' => 'anna'], [], 'x');

		$this->assertSame('system', $batch['rejections'][0]['by']);
		$this->assertSame('anna', $batch['assignee']);
	}//end testASystemRejectionNamesTheSystem()

	/**
	 * A rejection without a reason is refused.
	 *
	 * @return void
	 */
	public function testARejectionWithoutAReasonIsRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('needs a reason');
		$this->action('bram')->execute(['reason' => '   ', 'createdBy' => 'anna'], [], [], 'x');
	}//end testARejectionWithoutAReasonIsRefused()
}//end class
