<?php

/**
 * A capability rights double that grants or refuses every right.
 *
 * @category Test
 * @package  Unit\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenCatalogi.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Support;

use OCA\OpenCatalogi\Service\CapabilityRights;

/**
 * Answers every may() with one fixed verdict and records the verbs asked.
 *
 * Controller tests that are about what a gated method does once the right is
 * held construct the controller with this, so the guard lets them through.
 */
class AllowingCapabilityRights extends CapabilityRights {

	/**
	 * The verbs asked, with the user.
	 *
	 * @var array<int, array{0: string, 1: string|null}>
	 */
	public array $asked = [];

	/**
	 * Constructor.
	 *
	 * @param bool $verdict The answer to every question.
	 */
	public function __construct(private readonly bool $verdict=true) {

	}//end __construct()

	/**
	 * The fixed verdict.
	 *
	 * @param string      $verb   The right.
	 * @param string|null $userId The user.
	 *
	 * @return bool The verdict.
	 */
	public function may(string $verb, ?string $userId): bool {
		$this->asked[] = [$verb, $userId];
		return $this->verdict;

	}//end may()
}//end class
