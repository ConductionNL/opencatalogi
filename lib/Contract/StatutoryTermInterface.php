<?php

/**
 * Contract of StatutoryTerm, for callers in other components.
 *
 * @category Contract
 * @package  OCA\OpenCatalogi\Contract
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

namespace OCA\OpenCatalogi\Contract;

use DateTimeInterface;

/**
 * Contract of StatutoryTerm, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 */
interface StatutoryTermInterface {
	/**
	 * The term configuration, as the engine takes it.
	 *
	 * Pure, and public, so the shape of the Woo term can be asserted without an
	 * engine: a term that arms against the wrong anchor or without a bound looks
	 * exactly like a correct one from the outside.
	 *
	 * @param string $requestUuid The request the term belongs to.
	 * @param string $reference The reference the requester quotes.
	 * @param DateTimeInterface $receivedAt The moment the request arrived.
	 *
	 * @return array<string, mixed> The engine configuration.
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-a-statutory-term-is-armed-when-a-request-is-received-req-wri-002
	 */
	public function termConfig(string $requestUuid, string $reference, DateTimeInterface $receivedAt): array;

	/**
	 * Arm the statutory term of a request.
	 *
	 * @param string $requestUuid The request.
	 * @param string $reference The reference the requester quotes.
	 * @param DateTimeInterface $receivedAt When the request arrived.
	 * @param string|null $actor Who registered it.
	 *
	 * @return array<string, mixed> The armed term, as {@see describe()} reports it.
	 *
	 * @throws \OCA\OpenCatalogi\Service\Woo\TermEngineUnavailableException When the engine is unavailable.
	 * @throws \OCA\OpenCatalogi\Service\Woo\TermRefusedException When the engine refuses the configuration.
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-a-statutory-term-is-armed-when-a-request-is-received-req-wri-002
	 */
	public function arm(string $requestUuid, string $reference, DateTimeInterface $receivedAt, ?string $actor): array;

	/**
	 * Extend the term by the two weeks the law allows, once.
	 *
	 * The bound is the engine's, declared as `extensionMax` at arm time, so the
	 * second extension is refused by the thing that holds the count rather than
	 * by a counter kept alongside it that can disagree with it.
	 *
	 * @param string $timerUuid The term.
	 * @param string $rationale Why the term is extended.
	 * @param string|null $actor Who granted it.
	 *
	 * @return array<string, mixed> The extended term.
	 *
	 * @throws \OCA\OpenCatalogi\Service\Woo\TermEngineUnavailableException When the engine is unavailable.
	 * @throws \OCA\OpenCatalogi\Service\Woo\TermRefusedException When the bound is reached, or the term already closed.
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-the-term-is-extended-once-and-a-second-extension-is-refused-req-wri-003
	 */
	public function extendOnce(string $timerUuid, string $rationale, ?string $actor): array;

	/**
	 * Suspend the term while clarification is awaited.
	 *
	 * @param string $timerUuid The term.
	 * @param string $reason Why it is suspended.
	 * @param string|null $actor Who suspended it.
	 *
	 * @return array<string, mixed> The suspended term.
	 *
	 * @throws \OCA\OpenCatalogi\Service\Woo\TermEngineUnavailableException When the engine is unavailable.
	 * @throws \OCA\OpenCatalogi\Service\Woo\TermRefusedException When the term is not running.
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-the-term-pauses-while-clarification-is-awaited-req-wri-004
	 */
	public function pause(string $timerUuid, string $reason, ?string $actor): array;

	/**
	 * Resume a suspended term once the clarification arrived.
	 *
	 * @param string $timerUuid The term.
	 * @param string $reason Why it resumes.
	 * @param string|null $actor Who resumed it.
	 *
	 * @return array<string, mixed> The running term.
	 *
	 * @throws \OCA\OpenCatalogi\Service\Woo\TermEngineUnavailableException When the engine is unavailable.
	 * @throws \OCA\OpenCatalogi\Service\Woo\TermRefusedException When the term is not suspended.
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-the-term-pauses-while-clarification-is-awaited-req-wri-004
	 */
	public function resume(string $timerUuid, string $reason, ?string $actor): array;

	/**
	 * Read a term back.
	 *
	 * @param string $timerUuid The term.
	 *
	 * @return array<string, mixed> The term.
	 *
	 * @throws \OCA\OpenCatalogi\Service\Woo\TermEngineUnavailableException When the engine is unavailable.
	 * @throws \OCA\OpenCatalogi\Service\Woo\TermRefusedException When there is no such term.
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-terms-met-and-missed-are-reported-req-wri-006
	 */
	public function describe(string $timerUuid): array;

	/**
	 * Every Woo term armed against one request, newest first is not promised:
	 * the engine's order is kept, and a superseded term is included so a
	 * corrected receipt date leaves a trail rather than a gap.
	 *
	 * @param string $requestUuid The request.
	 *
	 * @return array<int, array<string, mixed>> The terms.
	 *
	 * @throws \OCA\OpenCatalogi\Service\Woo\TermEngineUnavailableException When the engine is unavailable.
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-terms-met-and-missed-are-reported-req-wri-006
	 */
	public function forRequest(string $requestUuid): array;
}//end interface
