<?php

/**
 * PreviewsSeenGuard
 *
 * The guard OpenRegister runs on the `approve` transition of a Woo batch: the
 * approving user must have opened the public view of every document the batch
 * publishes (woo-review-surface REQ-WRV-001). It runs on every path to
 * `approve`, the review page and the approval chain's own advance alike.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Lifecycle
 * @package  OCA\OpenCatalogi\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/woo-review-surface/specs/woo-transparency/spec.md#requirement-the-reviewer-sees-each-document-as-the-public-will-req-wrv-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Lifecycle;

use OCA\OpenCatalogi\Contract\TransparencyWorkflowServiceInterface;
use OCA\OpenCatalogi\Contract\BatchReviewInterface;
use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use Throwable;

/**
 * Refuses `approve` until the approving user opened every public view.
 *
 * @spec openspec/changes/woo-review-surface/specs/woo-transparency/spec.md#requirement-the-reviewer-sees-each-document-as-the-public-will-req-wrv-001
 */
class PreviewsSeenGuard implements LifecycleGuardInterface {

	/**
	 * Constructor.
	 *
	 * @param BatchReviewInterface              $review What a reviewer opened.
	 * @param TransparencyWorkflowServiceInterface $woo    Reads the stored batch when the payload lacks its documents.
	 */
	public function __construct(
		private readonly BatchReviewInterface $review,
		private readonly TransparencyWorkflowServiceInterface $woo,
	) {
	}//end __construct()

	/**
	 * Allow `approve` only when the user opened every public view; anything unreadable refuses.
	 *
	 * @param array<string, mixed> $object The batch as it is being saved.
	 * @param string               $action The transition.
	 * @param string               $userId The approving user.
	 *
	 * @return GuardResult
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) GuardResult, OpenRegister's guard answer, has only the named constructors allow() and deny().
	 *
	 * @spec openspec/changes/woo-review-surface/specs/woo-transparency/spec.md#requirement-the-reviewer-sees-each-document-as-the-public-will-req-wrv-001
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		if ($userId === '') {
			return GuardResult::deny('Approve refused: nobody is signed in to have seen the public views');
		}

		try {
			$batch = $object;
			$id = (string)($object['id'] ?? ($object['uuid'] ?? ''));
			if (array_key_exists('documents', $object) === false && $id !== '') {
				$batch = $this->woo->getBatch(batchId: $id);
			}

			$unseen = $this->review->unseenPreviews(batch: $batch, userId: $userId);
		} catch (Throwable $e) {
			return GuardResult::deny('Approve refused: the public views opened could not be read');
		}

		if ($unseen !== []) {
			$names = [];
			foreach ($unseen as $doc) {
				$names[] = $doc['documentId'];
				if ($doc['title'] !== '') {
					$names[(count($names) - 1)] = $doc['title'];
				}
			}

			return GuardResult::deny('Approve refused: the public view of these documents was not opened: ' . implode(', ', $names));
		}

		return GuardResult::allow();
	}//end check()
}//end class
