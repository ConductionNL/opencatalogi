<?php

/**
 * OpenCatalogi publication-window check.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service
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
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service;

/**
 * Whether an object is published right now, by its own publication window.
 *
 * @spec openspec/specs/auto-publishing/spec.md#APB-006
 */
trait ChecksPublicationWindow {
	/**
	 * Check if an object data array represents a published object.
	 *
	 * Visibility is governed by the live OpenRegister RBAC model (APB-006): an
	 * object is public when its own `publicationDate` field is set and is at or
	 * before "now", and either carries no `depublicationDate` or one that is
	 * still in the future.
	 *
	 * @param array $objectData The object data to check.
	 *
	 * @return boolean True if the object is published, false otherwise.
	 *
	 * @spec openspec/specs/auto-publishing/spec.md#APB-006
	 */
	private function isObjectPublished(array $objectData): bool {
		$now = time();
		return $this->hasPublicationStarted(date: ($objectData['publicationDate'] ?? null), now: $now) === true
			&& $this->hasPublicationNotEnded(date: ($objectData['depublicationDate'] ?? null), now: $now) === true;
	}//end isObjectPublished()

	/**
	 * Whether a publication date is set, readable and not in the future.
	 *
	 * @param mixed $date The publication date.
	 * @param int $now The current Unix time.
	 *
	 * @return boolean
	 */
	private function hasPublicationStarted(mixed $date, int $now): bool {
		if ($date === null || $date === '') {
			return false;
		}

		$publishedTime = strtotime((string)$date);
		return $publishedTime !== false && $publishedTime <= $now;
	}//end hasPublicationStarted()

	/**
	 * Whether a depublication date is absent, unreadable or still to come.
	 *
	 * @param mixed $date The depublication date.
	 * @param int $now The current Unix time.
	 *
	 * @return boolean
	 */
	private function hasPublicationNotEnded(mixed $date, int $now): bool {
		if ($date === null || $date === '') {
			return true;
		}

		$depublishedTime = strtotime((string)$date);
		return ($depublishedTime === false || $depublishedTime > $now);
	}//end hasPublicationNotEnded()
}//end trait
