<?php

/**
 * BatchReviewControllerTest
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/woo-review-surface/tasks.md#task-1.4
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Controller;

use OCA\OpenCatalogi\Contract\BatchReviewInterface;
use OCA\OpenCatalogi\Controller\BatchReviewController;
use OCA\OpenCatalogi\Service\Review\BatchNotInReviewException;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

/**
 * The review routes reach the batch review behind the admin right, and answer its refusals.
 *
 * @covers \OCA\OpenCatalogi\Controller\BatchReviewController
 */
final class BatchReviewControllerTest extends TestCase {

	private IRequest&MockObject $request;
	private BatchReviewInterface&MockObject $review;
	private BatchReviewController $controller;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->review = $this->createMock(BatchReviewInterface::class);
		$this->controller = new BatchReviewController('opencatalogi', $this->request, $this->review);

	}//end setUp()

	/**
	 * Send-back passes the batch, the assessment and the reason, behind the admin right, as a POST.
	 *
	 * @return void
	 */
	public function testSendBackResetsTheAssessmentWithTheReason(): void {
		$this->request->method('getParam')->willReturnMap([
			['batchId', '', 'batch-1'],
			['reason', '', 'Naam op pagina 4 niet gelakt'],
		]);
		$sent = ['id' => 'doc-1', 'assessment' => 'te_beoordelen', 'sentBackReason' => ['reason' => 'Naam op pagina 4 niet gelakt', 'by' => 'rita', 'at' => '2026-11-16T22:00:00+00:00']];
		$this->review->expects($this->once())
			->method('sendBack')
			->with('batch-1', 'doc-1', 'Naam op pagina 4 niet gelakt')
			->willReturn($sent);

		$response = $this->controller->sendBack(assessmentId: 'doc-1');
		$this->assertSame(200, $response->getStatus());
		$this->assertSame($sent, $response->getData());

		$routes = require __DIR__ . '/../../../appinfo/routes.php';
		$found = array_values(array_filter($routes['routes'], static fn (array $r): bool => $r['name'] === 'batchReview#sendBack' && isset($r['postfix']) === false));
		$this->assertSame([['name' => 'batchReview#sendBack', 'url' => '/api/woo/assessments/{assessmentId}/send-back', 'verb' => 'POST']], $found);

		foreach (['sendBack', 'approve', 'reject', 'previewSeen', 'publicPreview'] as $method) {
			$attributes = (new ReflectionMethod(BatchReviewController::class, $method))->getAttributes(AuthorizedAdminSetting::class);
			$this->assertCount(1, $attributes, $method . ' carries the admin right');
		}

	}//end testSendBackResetsTheAssessmentWithTheReason()

	/**
	 * Outside review a send-back is a 409 with the reason; a missing reason a 400.
	 *
	 * @return void
	 */
	public function testSendBackOutsideReviewIsRefused(): void {
		$this->review->method('sendBack')->willReturnOnConsecutiveCalls(
			$this->throwException(new BatchNotInReviewException('The batch is not in review')),
			$this->throwException(new RuntimeException('Sending a document back needs a reason'))
		);

		$response = $this->controller->sendBack(assessmentId: 'doc-1');
		$this->assertSame(409, $response->getStatus());
		$this->assertSame(['error' => 'The batch is not in review'], $response->getData());

		$response = $this->controller->sendBack(assessmentId: 'doc-1');
		$this->assertSame(400, $response->getStatus());

	}//end testSendBackOutsideReviewIsRefused()

	/**
	 * Approve answers the refusal naming the unseen documents; preview-seen records the posted document;
	 * reject passes the reason; an unknown batch's preview is a 404.
	 *
	 * @return void
	 */
	public function testTheReviewMovesReachTheReview(): void {
		$this->request->method('getParam')->willReturnMap([['documentId', '', 'doc-2'], ['reason', '', 'Bijlage 3 bevat nog een BSN']]);
		$this->review->expects($this->once())->method('markPreviewSeen')->with('batch-1', 'doc-2')->willReturn(['documentId' => 'doc-2', 'seen' => ['doc-2'], 'unseen' => []]);
		$this->review->method('approveBatch')->willThrowException(new RuntimeException('Approve refused: the public view of these documents was not opened: Bijlage.pdf'));
		$this->review->expects($this->once())->method('rejectBatch')->with('batch-1', 'Bijlage 3 bevat nog een BSN')->willReturn(['status' => 'in_progress']);
		$this->review->method('publicPreview')->willThrowException(new RuntimeException('Batch not found: batch-9'));

		$this->assertSame(200, $this->controller->previewSeen(batchId: 'batch-1')->getStatus());
		$response = $this->controller->approve(batchId: 'batch-1');
		$this->assertSame(400, $response->getStatus());
		$this->assertStringContainsString('Bijlage.pdf', $response->getData()['error']);
		$this->assertSame(['status' => 'in_progress'], $this->controller->reject(batchId: 'batch-1')->getData());
		$this->assertSame(404, $this->controller->publicPreview(batchId: 'batch-9')->getStatus());

	}//end testTheReviewMovesReachTheReview()
}//end class
