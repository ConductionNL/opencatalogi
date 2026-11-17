<?php

/**
 * Publishes a publication through its lifecycle (REQ-PLC-003).
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Publication
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
 * @spec openspec/changes/publication-lifecycle-on-or/specs/publications/spec.md#requirement-a-publication-is-public-only-in-the-published-state-and-every-publish-path-moves-the-lifecycle-req-plc-003
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Publication;

use Psr\Container\ContainerInterface;

/**
 * Runs the named transition that publishes a publication, with its publication date in the same call.
 *
 * `publish` from `approved`; `publishWithoutReview` from `draft` (or an empty state) while review is not
 * required. Any other state is refused before anything is written. OpenRegister's engine checks the move,
 * the caller's rights and the guard again, and whatever it refuses propagates unchanged.
 *
 * @spec openspec/changes/publication-lifecycle-on-or/specs/publications/spec.md#requirement-a-publication-is-public-only-in-the-published-state-and-every-publish-path-moves-the-lifecycle-req-plc-003
 */
class PublishTransition {

	/**
	 * OpenRegister's lifecycle engine.
	 */
	public const ENGINE = 'OCA\OpenRegister\Service\Lifecycle\TransitionEngine';

	/**
	 * The transition from `approved`.
	 */
	public const PUBLISH = 'publish';

	/**
	 * The transition from `draft` while review is off.
	 */
	public const PUBLISH_WITHOUT_REVIEW = 'publishWithoutReview';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface      $container Reaches OpenRegister's lifecycle engine.
	 * @param PublicationReviewPolicy $review    Whether review is required.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly PublicationReviewPolicy $review,
	) {
	}//end __construct()

	/**
	 * The stored state, with an empty one read as `draft`.
	 *
	 * @param string $stored The stored `status`.
	 *
	 * @return string The state.
	 *
	 * @spec openspec/changes/publication-lifecycle-on-or/specs/publications/spec.md#requirement-a-publication-is-public-only-in-the-published-state-and-every-publish-path-moves-the-lifecycle-req-plc-003
	 */
	public static function storedState(string $stored): string {
		$stored = trim($stored);
		if ($stored === '') {
			return 'draft';
		}

		return $stored;
	}//end storedState()

	/**
	 * The transition that publishes from a stored state, or null when none may.
	 *
	 * @param string $stored The stored `status`.
	 *
	 * @return string|null `publish`, `publishWithoutReview` or null.
	 *
	 * @spec openspec/changes/publication-lifecycle-on-or/specs/publications/spec.md#requirement-a-publication-is-public-only-in-the-published-state-and-every-publish-path-moves-the-lifecycle-req-plc-003
	 */
	public function actionFor(string $stored): ?string {
		$state = self::storedState(stored: $stored);
		if ($state === 'approved') {
			return self::PUBLISH;
		}

		if ($state === 'draft' && $this->review->isReviewRequired() === false) {
			return self::PUBLISH_WITHOUT_REVIEW;
		}

		return null;
	}//end actionFor()

	/**
	 * Publish now: run the transition with the publication date, clearing a depublication date.
	 *
	 * @param string $id              The publication uuid.
	 * @param string $stored          The stored `status`.
	 * @param string $publicationDate The publication date to write (ATOM).
	 *
	 * @return object The publication as the engine answers it.
	 *
	 * @throws PublishRefusedException When no publish transition is allowed from the stored state.
	 * @throws \Throwable              Whatever the engine refuses (move, rights, guard, inputs).
	 *
	 * @spec openspec/changes/publication-lifecycle-on-or/specs/publications/spec.md#requirement-a-publication-is-public-only-in-the-published-state-and-every-publish-path-moves-the-lifecycle-req-plc-003
	 */
	public function publish(string $id, string $stored, string $publicationDate): object {
		$action = $this->actionFor(stored: $stored);
		if ($action === null) {
			throw new PublishRefusedException(state: self::storedState(stored: $stored));
		}

		$engine = $this->container->get(self::ENGINE);

		return $engine->transition($id, $action, ['publicationDate' => $publicationDate, 'depublicationDate' => null]);
	}//end publish()
}//end class
