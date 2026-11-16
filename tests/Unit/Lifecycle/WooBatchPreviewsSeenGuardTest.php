<?php

/**
 * WooBatchPreviewsSeenGuardTest
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
 * @spec openspec/changes/woo-review-surface/tasks.md#task-2.1
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Lifecycle;

use OCA\OpenCatalogi\Contract\TransparencyWorkflowServiceInterface;
use OCA\OpenCatalogi\Contract\WooBatchReviewInterface;
use OCA\OpenCatalogi\Lifecycle\WooBatchPreviewsSeenGuard;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The `approve` guard refuses until the approving user opened every public view, and fails closed.
 *
 * @covers \OCA\OpenCatalogi\Lifecycle\WooBatchPreviewsSeenGuard
 */
final class WooBatchPreviewsSeenGuardTest extends TestCase {

	private WooBatchReviewInterface&MockObject $review;
	private TransparencyWorkflowServiceInterface&MockObject $woo;
	private WooBatchPreviewsSeenGuard $guard;

	protected function setUp(): void {
		$this->review = $this->createMock(WooBatchReviewInterface::class);
		$this->woo = $this->createMock(TransparencyWorkflowServiceInterface::class);
		$this->guard = new WooBatchPreviewsSeenGuard($this->review, $this->woo);

	}//end setUp()

	/**
	 * Every view opened: allowed.
	 *
	 * @return void
	 */
	public function testEveryPreviewSeenAllowsApprove(): void {
		$batch = ['id' => 'batch-1', 'documents' => ['doc-1']];
		$this->review->expects($this->once())->method('unseenPreviews')->with($batch, 'rita')->willReturn([]);

		$this->assertTrue($this->guard->check($batch, 'approve', 'rita')->isAllowed());

	}//end testEveryPreviewSeenAllowsApprove()

	/**
	 * An unseen view refuses, naming the document.
	 *
	 * @return void
	 */
	public function testAnUnseenPreviewRefusesNamingTheDocument(): void {
		$this->review->method('unseenPreviews')->willReturn([['documentId' => 'doc-2', 'title' => 'Bijlage.pdf'], ['documentId' => 'doc-3', 'title' => '']]);

		$result = $this->guard->check(['id' => 'batch-1', 'documents' => ['doc-2', 'doc-3']], 'approve', 'rita');

		$this->assertFalse($result->isAllowed());
		$this->assertStringContainsString('Bijlage.pdf, doc-3', (string)$result->getMessage());

	}//end testAnUnseenPreviewRefusesNamingTheDocument()

	/**
	 * A payload without its documents is judged on the stored batch.
	 *
	 * @return void
	 */
	public function testAPartialPayloadIsJudgedOnTheStoredBatch(): void {
		$stored = ['id' => 'batch-1', 'documents' => ['doc-1'], 'previewsSeen' => ['rita' => ['doc-1']]];
		$this->woo->expects($this->once())->method('getBatch')->with('batch-1')->willReturn($stored);
		$this->review->expects($this->once())->method('unseenPreviews')->with($stored, 'rita')->willReturn([]);

		$this->assertTrue($this->guard->check(['id' => 'batch-1', 'status' => 'approved'], 'approve', 'rita')->isAllowed());

	}//end testAPartialPayloadIsJudgedOnTheStoredBatch()

	/**
	 * Nobody signed in, or nothing readable: refused.
	 *
	 * @return void
	 */
	public function testItFailsClosed(): void {
		$this->assertFalse($this->guard->check(['id' => 'batch-1', 'documents' => []], 'approve', '')->isAllowed());

		$this->review->method('unseenPreviews')->willThrowException(new RuntimeException('OpenRegister down'));
		$result = $this->guard->check(['id' => 'batch-1', 'documents' => ['doc-1']], 'approve', 'rita');
		$this->assertFalse($result->isAllowed());
		$this->assertStringContainsString('could not be read', (string)$result->getMessage());

	}//end testItFailsClosed()
}//end class
