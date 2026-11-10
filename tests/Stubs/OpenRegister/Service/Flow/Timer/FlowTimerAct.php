<?php

/**
 * Stub for OCA\OpenRegister\Service\Flow\Timer\FlowTimerAct.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Stubs
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Timer;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Mirrors the real value object: who acts on a timer, and when.
 */
final class FlowTimerAct {

	/**
	 * Constructor.
	 *
	 * @param string|null $actor The acting user, or null for the system.
	 * @param DateTimeImmutable $now The instant of the act.
	 * @param DateTimeInterface|null $when The event instant the act refers to.
	 */
	public function __construct(
		public readonly ?string $actor,
		public readonly DateTimeImmutable $now,
		public readonly ?DateTimeInterface $when = null,
	) {
	}//end __construct()
}//end class
