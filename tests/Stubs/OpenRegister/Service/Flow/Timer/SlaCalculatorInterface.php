<?php

/**
 * Stub for OCA\OpenRegister\Service\Flow\Timer\SlaCalculatorInterface.
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

namespace OCA\OpenRegister\Service\Flow\Timer;

/**
 * The roll constants of the real interface; the calculator itself is faked per test.
 */
interface SlaCalculatorInterface {

	public const ROLL_NONE = 'none';

	public const ROLL_NEXT = 'next';

	public const ROLL_PREVIOUS = 'previous';

	public const ROLLS = [self::ROLL_NONE, self::ROLL_NEXT, self::ROLL_PREVIOUS];
}//end interface
