<?php

/**
 * ReviewNotRequiredGuard
 *
 * The guard OpenRegister runs on the publication's `publishWithoutReview`
 * transition: a draft may skip review only while the admin setting
 * `publication_review_required` is off (publication-lifecycle-on-or
 * REQ-PLC-001). It runs on every path to that transition, the transition route
 * and a list-form save alike.
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
 * @spec openspec/changes/publication-lifecycle-on-or/specs/publications/spec.md#requirement-the-publication-declares-one-lifecycle-with-named-transitions-req-plc-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Lifecycle;

use OCA\OpenCatalogi\Service\Publication\PublicationReviewPolicy;
use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use Throwable;

/**
 * Refuses `publishWithoutReview` while review is required.
 *
 * @spec openspec/changes/publication-lifecycle-on-or/specs/publications/spec.md#requirement-the-publication-declares-one-lifecycle-with-named-transitions-req-plc-001
 */
class ReviewNotRequiredGuard implements LifecycleGuardInterface {

	/**
	 * Constructor.
	 *
	 * @param PublicationReviewPolicy $policy Whether review is required.
	 */
	public function __construct(
		private readonly PublicationReviewPolicy $policy,
	) {
	}//end __construct()

	/**
	 * Allow the move only while review is off; an unreadable setting refuses.
	 *
	 * @param array<string, mixed> $object The publication as it is being saved.
	 * @param string               $action The transition.
	 * @param string               $userId The user running it.
	 *
	 * @return GuardResult
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The interface hands every guard the object and the user; this one decides on the setting alone.
	 * @SuppressWarnings(PHPMD.StaticAccess)          GuardResult, OpenRegister's guard answer, has only the named constructors allow() and deny().
	 *
	 * @spec openspec/changes/publication-lifecycle-on-or/specs/publications/spec.md#requirement-the-publication-declares-one-lifecycle-with-named-transitions-req-plc-001
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		try {
			$required = $this->policy->isReviewRequired();
		} catch (Throwable $e) {
			$required = true;
		}

		if ($required === true) {
			return GuardResult::deny(
				sprintf('Transition "%s" refused: publications have to be reviewed before they are published', $action)
			);
		}

		return GuardResult::allow();
	}//end check()
}//end class
