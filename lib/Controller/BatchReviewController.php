<?php

/**
 * OpenCatalogi batch review controller.
 *
 * The review of a publication batch (woo-review-surface): each document as the
 * public will see it, what the reviewer opened, approve and reject, and one
 * document's send-back. Every route is admin-gated like the rest of the batch
 * workflow; the controller holds no policy and delegates to the batch review.
 *
 * @category Controller
 * @package  OCA\OpenCatalogi\Controller
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
 * @spec openspec/changes/woo-review-surface/specs/woo-transparency/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\Contract\BatchReviewInterface;
use OCA\OpenCatalogi\Service\Review\BatchNotInReviewException;
use OCA\OpenCatalogi\Settings\OpenCatalogiAdmin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * The review routes of a publication batch.
 *
 * @spec openspec/changes/woo-review-surface/specs/woo-transparency/spec.md
 */
class BatchReviewController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string               $appName The app name.
	 * @param IRequest             $request The request.
	 * @param BatchReviewInterface $review  The review of a batch: public views, approve, reject and send-back.
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly BatchReviewInterface $review,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * Each document of a batch as the public will see it, for its reviewer.
	 *
	 * Admin-only, the same right as reading the batch. The answer carries the redaction
	 * log of a partly public document, which is internal: this route is never public.
	 *
	 * @param string $batchId The batch uuid.
	 *
	 * @return JSONResponse The documents with their public view, and what the reviewer has not opened.
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/woo-review-surface/specs/woo-transparency/spec.md#requirement-the-reviewer-sees-each-document-as-the-public-will-req-wrv-001
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function publicPreview(string $batchId): JSONResponse {
		try {
			return new JSONResponse($this->review->publicPreview(batchId: $batchId));
		} catch (\Throwable $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: Http::STATUS_NOT_FOUND);
		}

	}//end publicPreview()

	/**
	 * Record that the signed-in reviewer opened the public view of one document.
	 *
	 * Admin-only. Answers 409 when the batch is not in review.
	 *
	 * @param string $batchId The batch uuid.
	 *
	 * @return JSONResponse What the reviewer opened and what is still unseen.
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/woo-review-surface/specs/woo-transparency/spec.md#requirement-the-reviewer-sees-each-document-as-the-public-will-req-wrv-001
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function previewSeen(string $batchId): JSONResponse {
		$documentId = (string)$this->request->getParam('documentId', '');

		return $this->reviewAnswer(move: fn (): array => $this->review->markPreviewSeen(batchId: $batchId, documentId: $documentId));

	}//end previewSeen()

	/**
	 * Approve a batch in review.
	 *
	 * Admin-only. Refused, naming the documents, until the signed-in reviewer
	 * opened every public view; the approval chain on the transition then asks
	 * the reviewers in OpenRegister's task list.
	 *
	 * @param string $batchId The batch uuid.
	 *
	 * @return JSONResponse The batch, or the refusal.
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/woo-review-surface/specs/woo-transparency/spec.md#requirement-the-reviewer-sees-each-document-as-the-public-will-req-wrv-001
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function approve(string $batchId): JSONResponse {
		return $this->reviewAnswer(move: fn (): array => $this->review->approveBatch(batchId: $batchId));

	}//end approve()

	/**
	 * Reject a batch in review: it goes back to its author with the reason.
	 *
	 * Admin-only. The reason is required.
	 *
	 * @param string $batchId The batch uuid.
	 *
	 * @return JSONResponse The batch, or the refusal.
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/woo-review-surface/specs/woo-transparency/spec.md#requirement-a-rejected-batch-or-document-goes-back-to-its-author-with-the-reason-req-wrv-002
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function reject(string $batchId): JSONResponse {
		$reason = (string)$this->request->getParam('reason', '');

		return $this->reviewAnswer(move: fn (): array => $this->review->rejectBatch(batchId: $batchId, reason: $reason));

	}//end reject()

	/**
	 * Send one document of a batch in review back to assessment, with the reason.
	 *
	 * Admin-only. The batch the document belongs to comes in the body
	 * (`batchId`) and must hold the document; 409 when it is not in review.
	 *
	 * @param string $assessmentId The assessment uuid.
	 *
	 * @return JSONResponse The assessment, or the refusal.
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/woo-review-surface/specs/woo-transparency/spec.md#requirement-a-rejected-batch-or-document-goes-back-to-its-author-with-the-reason-req-wrv-002
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function sendBack(string $assessmentId): JSONResponse {
		$batchId = (string)$this->request->getParam('batchId', '');
		$reason = (string)$this->request->getParam('reason', '');

		return $this->reviewAnswer(
			move: fn (): array => $this->review->sendBack(batchId: $batchId, assessmentId: $assessmentId, reason: $reason)
		);

	}//end sendBack()

	/**
	 * The answer to a review move: 200 with its result, 409 outside review, else 400 with the reason.
	 *
	 * @param callable(): array<string, mixed> $move The move.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/woo-review-surface/specs/woo-transparency/spec.md#requirement-a-rejected-batch-or-document-goes-back-to-its-author-with-the-reason-req-wrv-002
	 */
	private function reviewAnswer(callable $move): JSONResponse {
		try {
			return new JSONResponse($move());
		} catch (BatchNotInReviewException $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: Http::STATUS_CONFLICT);
		} catch (\Throwable $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		}

	}//end reviewAnswer()
}//end class
