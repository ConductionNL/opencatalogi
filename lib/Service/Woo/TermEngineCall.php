<?php

/**
 * The operation objects OpenRegister's term engine takes for a Woo term.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Woo
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
 * @spec openspec/specs/woo-request-intake/spec.md#requirement-a-statutory-term-is-armed-when-a-request-is-received-req-wri-002
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Woo;

use DateTimeImmutable;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerAct;
use OCA\OpenRegister\Service\Flow\Timer\TimerDecision;
use OCA\OpenRegister\Service\Flow\Timer\TimerExtensionRequest;
use OCA\OpenRegister\Service\Flow\Timer\TimerSuspension;

/**
 * Builds the engine's extension, suspension, decision and act value objects,
 * so StatutoryTerm says what the Woo term is and this class says how the
 * engine is asked.
 *
 * @spec openspec/specs/woo-request-intake/spec.md#requirement-a-statutory-term-is-armed-when-a-request-is-received-req-wri-002
 */
final class TermEngineCall {

	/**
	 * One extension of a term.
	 *
	 * @param string $timerUuid The term.
	 * @param integer $amount The extension amount.
	 * @param string $unit The extension unit.
	 * @param string $rationale Why the term is extended.
	 *
	 * @return TimerExtensionRequest The request.
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-a-statutory-term-is-armed-when-a-request-is-received-req-wri-002
	 */
	public function extension(string $timerUuid, int $amount, string $unit, string $rationale): TimerExtensionRequest {
		return new TimerExtensionRequest(uuid: $timerUuid, amount: $amount, unit: $unit, rationale: $rationale);
	}//end extension()

	/**
	 * One open-ended suspension of a term.
	 *
	 * @param string $timerUuid The term.
	 * @param string $reason Why the term is suspended.
	 * @param string $basis The legal basis of the suspension.
	 *
	 * @return TimerSuspension The suspension.
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-a-statutory-term-is-armed-when-a-request-is-received-req-wri-002
	 */
	public function suspension(string $timerUuid, string $reason, string $basis): TimerSuspension {
		return new TimerSuspension(uuid: $timerUuid, reason: $reason, until: null, basis: $basis);
	}//end suspension()

	/**
	 * A decision on a term, such as its resumption.
	 *
	 * @param string $timerUuid The term.
	 * @param string|null $reason The reason.
	 *
	 * @return TimerDecision The decision.
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-a-statutory-term-is-armed-when-a-request-is-received-req-wri-002
	 */
	public function decision(string $timerUuid, ?string $reason): TimerDecision {
		return new TimerDecision(uuid: $timerUuid, reason: $reason);
	}//end decision()

	/**
	 * Who acts on the term, now.
	 *
	 * @param string|null $actor The acting user, or null for the system.
	 *
	 * @return FlowTimerAct The act.
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-a-statutory-term-is-armed-when-a-request-is-received-req-wri-002
	 */
	public function act(?string $actor): FlowTimerAct {
		return new FlowTimerAct(actor: $actor, now: new DateTimeImmutable());
	}//end act()
}//end class
