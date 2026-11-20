<?php

/**
 * Stub for OCA\OpenRegister\Kernel\Contracts\TimerSubjectRef.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Stubs
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * Mirrors the real value object: the subject a flow timer runs for.
 */
final class TimerSubjectRef {

	/**
	 * Constructor.
	 *
	 * @param string $type The subject type.
	 * @param string $uuid The subject's uuid.
	 */
	public function __construct(
		public readonly string $type,
		public readonly string $uuid,
	) {
	}//end __construct()
}//end class
