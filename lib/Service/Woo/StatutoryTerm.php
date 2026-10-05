<?php

/**
 * The statutory Woo decision term, armed on OpenRegister's term engine.
 *
 * Nothing here computes a date. OpenRegister already rolls a deadline off a
 * non-working day, measures business time against a resolvable working calendar,
 * bounds an extension, holds elapsed time across a suspension, and recomputes a
 * stored deadline when the working calendar changes, writing the reason into
 * history. This class says what the Woo term IS and hands it over.
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

use DateTimeInterface;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Arms, extends, pauses and reads the statutory term of one Woo request.
 *
 * @spec openspec/specs/woo-request-intake/spec.md#requirement-a-statutory-term-is-armed-when-a-request-is-received-req-wri-002
 */
class StatutoryTerm {

	/**
	 * The Woo decision term: four weeks from the day the request was received.
	 *
	 * @var int
	 */
	public const TERM_DAYS = 28;

	/**
	 * The one extension the law allows: two weeks.
	 *
	 * @var int
	 */
	public const EXTENSION_DAYS = 14;

	/**
	 * How many times the term may be extended through the standard path.
	 *
	 * @var int
	 */
	public const EXTENSION_MAX = 1;

	/**
	 * The legal ground the term rests on.
	 *
	 * @var string
	 */
	public const BASIS_TERM = 'Woo art. 4.4 lid 1';

	/**
	 * The legal ground an extension rests on.
	 *
	 * @var string
	 */
	public const BASIS_EXTENSION = 'Woo art. 4.4 lid 2';

	/**
	 * The legal ground a suspension for clarification rests on.
	 *
	 * @var string
	 */
	public const BASIS_CLARIFICATION = 'Woo art. 4.1 lid 4';

	/**
	 * The unit the term is counted in. Calendar days, because the Woo term is
	 * four weeks of calendar time and not twenty working days.
	 *
	 * @var string
	 */
	public const TERM_UNIT = 'calendarDays';

	/**
	 * The subject type the engine binds the term to. A Woo request is an
	 * OpenRegister object, and `object` is one of the three types the engine
	 * accepts; a made-up type is refused at arm time rather than stored.
	 *
	 * @var string
	 */
	public const SUBJECT_TYPE = 'object';

	/**
	 * The anchoring event. Stored so a corrected receipt date re-arms the term
	 * rather than leaving a deadline counted from the wrong day.
	 *
	 * @var string
	 */
	public const ANCHOR_EVENT = 'woo-request-received';

	/**
	 * Marks our timers among everything else the engine holds, so the report
	 * reads Woo terms and not every deadline in the instance.
	 *
	 * @var string
	 */
	public const TERM_KIND = 'woo-request-decision-term';

	/**
	 * Cached OpenRegister FlowTimerService.
	 *
	 * @var object|null
	 */
	private ?object $timerService = null;

	/**
	 * Cached OpenRegister FlowTimerMapper.
	 *
	 * @var object|null
	 */
	private ?object $timerMapper = null;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Server container, for the consumed term engine.
	 * @param LoggerInterface $logger Logger.
	 * @param IAppManager $appManager Answers whether OpenRegister is installed.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly IAppManager $appManager,
	) {

	}//end __construct()

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
	public function termConfig(string $requestUuid, string $reference, DateTimeInterface $receivedAt): array {
		return [
			'subjectType' => self::SUBJECT_TYPE,
			'subjectUuid' => $requestUuid,
			'appId' => 'opencatalogi',
			'title' => 'Woo decision term ' . $reference,
			'purpose' => 'expiry',
			'legalEffect' => 'wettelijk',
			'sla' => [
				'value' => self::TERM_DAYS,
				'unit' => self::TERM_UNIT,
				// A term that ends on a Saturday, a Sunday or a public holiday
				// runs to the next working day. That is the Algemene
				// termijnenwet, and the engine is what knows which day that is.
				'rollToWorkingDay' => 'next',
			],
			'extensionMax' => self::EXTENSION_MAX,
			'anchorEvent' => self::ANCHOR_EVENT,
			'anchorEventAt' => $receivedAt,
			'metadata' => [
				'kind' => self::TERM_KIND,
				'basis' => self::BASIS_TERM,
				'reference' => $reference,
			],
		];

	}//end termConfig()

	/**
	 * Resolve the consumed term engine.
	 *
	 * @return object The engine.
	 *
	 * @throws TermEngineUnavailableException When it cannot be reached.
	 *
	 * @spec exclude pure framework plumbing — resolves the consumed OR term engine.
	 */
	private function engine(): object {
		if ($this->timerService === null) {
			// ADR-083: ask the app manager first, so an instance without
			// OpenRegister refuses by name instead of failing in the container.
			if ($this->appManager->isInstalled('openregister') === false) {
				throw new TermEngineUnavailableException(
					message: 'OpenRegister is not installed, so no Woo term can be armed or read. The date is not '
						. 'computed here instead: a second implementation of the Algemene termijnenwet behind a '
						. 'legal deadline is worse than an honest refusal.'
				);
			}

			try {
				$this->timerService = $this->container->get('OCA\OpenRegister\Service\Flow\Timer\FlowTimerService');
			} catch (\Throwable $e) {
				throw new TermEngineUnavailableException(
					message: 'The statutory term engine is unavailable, so no Woo term can be armed or read. '
						. 'The date is not computed here instead: a second implementation of the Algemene '
						. 'termijnenwet behind a legal deadline is worse than an honest refusal.',
					code: 0,
					previous: $e
				);
			}
		}

		return $this->timerService;

	}//end engine()

	/**
	 * Resolve the consumed term store, which reads a term back by its id or by
	 * the request it hangs on.
	 *
	 * @return object The store.
	 *
	 * @throws TermEngineUnavailableException When it cannot be reached.
	 *
	 * @spec exclude pure framework plumbing — resolves the consumed OR term store.
	 */
	private function store(): object {
		if ($this->timerMapper === null) {
			// No second availability check: every public method resolves the
			// engine before the store, so this method is only reached on an
			// instance where ADR-083's check in engine() already passed. A
			// duplicate guard here would be a branch no run can take.
			try {
				$this->timerMapper = $this->container->get('OCA\OpenRegister\Db\FlowTimerMapper');
			} catch (\Throwable $e) {
				throw new TermEngineUnavailableException(
					message: 'The statutory term store is unavailable, so no Woo term can be read back.',
					code: 0,
					previous: $e
				);
			}
		}

		return $this->timerMapper;

	}//end store()

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
	 * @throws TermEngineUnavailableException When the engine is unavailable.
	 * @throws TermRefusedException When the engine refuses the configuration.
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-a-statutory-term-is-armed-when-a-request-is-received-req-wri-002
	 */
	public function arm(string $requestUuid, string $reference, DateTimeInterface $receivedAt, ?string $actor): array {
		$engine = $this->engine();

		try {
			$timer = $engine->arm(
				$this->termConfig(requestUuid: $requestUuid, reference: $reference, receivedAt: $receivedAt),
				$actor
			);
		} catch (\Throwable $e) {
			throw $this->refusal(operation: 'arm', error: $e);
		}

		return $this->report(timer: $timer, engine: $engine);

	}//end arm()

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
	 * @throws TermEngineUnavailableException When the engine is unavailable.
	 * @throws TermRefusedException When the bound is reached, or the term already closed.
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-the-term-is-extended-once-and-a-second-extension-is-refused-req-wri-003
	 */
	public function extendOnce(string $timerUuid, string $rationale, ?string $actor): array {
		$engine = $this->engine();

		try {
			$timer = $engine->extend(
				$timerUuid,
				self::EXTENSION_DAYS,
				self::TERM_UNIT,
				$rationale,
				$actor
			);
		} catch (\Throwable $e) {
			throw $this->refusal(operation: 'extend', error: $e);
		}

		return $this->report(timer: $timer, engine: $engine);

	}//end extendOnce()

	/**
	 * Suspend the term while clarification is awaited.
	 *
	 * @param string $timerUuid The term.
	 * @param string $reason Why it is suspended.
	 * @param string|null $actor Who suspended it.
	 *
	 * @return array<string, mixed> The suspended term.
	 *
	 * @throws TermEngineUnavailableException When the engine is unavailable.
	 * @throws TermRefusedException When the term is not running.
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-the-term-pauses-while-clarification-is-awaited-req-wri-004
	 */
	public function pause(string $timerUuid, string $reason, ?string $actor): array {
		$engine = $this->engine();

		try {
			$timer = $engine->suspend(
				$timerUuid,
				$reason,
				null,
				$actor,
				self::BASIS_CLARIFICATION
			);
		} catch (\Throwable $e) {
			throw $this->refusal(operation: 'pause', error: $e);
		}

		return $this->report(timer: $timer, engine: $engine);

	}//end pause()

	/**
	 * Resume a suspended term once the clarification arrived.
	 *
	 * @param string $timerUuid The term.
	 * @param string $reason Why it resumes.
	 * @param string|null $actor Who resumed it.
	 *
	 * @return array<string, mixed> The running term.
	 *
	 * @throws TermEngineUnavailableException When the engine is unavailable.
	 * @throws TermRefusedException When the term is not suspended.
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-the-term-pauses-while-clarification-is-awaited-req-wri-004
	 */
	public function resume(string $timerUuid, string $reason, ?string $actor): array {
		$engine = $this->engine();

		try {
			$timer = $engine->resume($timerUuid, $reason, $actor);
		} catch (\Throwable $e) {
			throw $this->refusal(operation: 'resume', error: $e);
		}

		return $this->report(timer: $timer, engine: $engine);

	}//end resume()

	/**
	 * Read a term back.
	 *
	 * @param string $timerUuid The term.
	 *
	 * @return array<string, mixed> The term.
	 *
	 * @throws TermEngineUnavailableException When the engine is unavailable.
	 * @throws TermRefusedException When there is no such term.
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-terms-met-and-missed-are-reported-req-wri-006
	 */
	public function describe(string $timerUuid): array {
		$engine = $this->engine();

		try {
			$timer = $this->store()->findByUuid($timerUuid);
		} catch (TermEngineUnavailableException $e) {
			throw $e;
		} catch (\Throwable $e) {
			throw $this->refusal(operation: 'read', error: $e);
		}

		return $this->report(timer: $timer, engine: $engine);

	}//end describe()

	/**
	 * Every Woo term armed against one request, newest first is not promised:
	 * the engine's order is kept, and a superseded term is included so a
	 * corrected receipt date leaves a trail rather than a gap.
	 *
	 * @param string $requestUuid The request.
	 *
	 * @return array<int, array<string, mixed>> The terms.
	 *
	 * @throws TermEngineUnavailableException When the engine is unavailable.
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-terms-met-and-missed-are-reported-req-wri-006
	 */
	public function forRequest(string $requestUuid): array {
		$engine = $this->engine();
		$timers = $this->store()->findBySubject(self::SUBJECT_TYPE, $requestUuid, []);
		if (is_array($timers) === false) {
			return [];
		}

		$rows = [];
		foreach ($timers as $timer) {
			if (is_object($timer) === false) {
				continue;
			}

			$rows[] = $this->report(timer: $timer, engine: $engine);
		}

		return $rows;

	}//end forRequest()

	/**
	 * Turn a refusal from the engine into one of our two shapes.
	 *
	 * The engine names the constraint it refused on, and that message is the
	 * useful part, so it is carried through rather than replaced with a generic
	 * one.
	 *
	 * @param string $operation What was attempted.
	 * @param \Throwable $error What the engine said.
	 *
	 * @return TermRefusedException The refusal to raise.
	 *
	 * @spec exclude internal helper — shapes an engine refusal for the caller.
	 */
	private function refusal(string $operation, \Throwable $error): TermRefusedException {
		$this->logger->info(
			'[StatutoryTerm] The term engine refused to ' . $operation . ' a Woo term: ' . $error->getMessage()
		);

		return new TermRefusedException(message: $error->getMessage(), code: 0, previous: $error);

	}//end refusal()

	/**
	 * The term as this app reports it: the engine's derivation plus the two
	 * numbers a Woo officer is answerable for, the extension count and its bound.
	 *
	 * @param object $timer The engine's term.
	 * @param object $engine The engine, which derives overdue and remaining.
	 *
	 * @return array<string, mixed> The term.
	 *
	 * @spec exclude internal helper — shapes the engine's term for the API.
	 */
	private function report(object $timer, object $engine): array {
		$described = $engine->describe($timer);
		if (is_array($described) === false) {
			$described = [];
		}

		$extensionCount = 0;
		if (method_exists($timer, 'getExtensionCount') === true) {
			$extensionCount = (int)$timer->getExtensionCount();
		}

		$extensionMax = self::EXTENSION_MAX;
		if (method_exists($timer, 'getExtensionMax') === true) {
			$extensionMax = (int)$timer->getExtensionMax();
		}

		$uuid = '';
		if (method_exists($timer, 'getUuid') === true) {
			$uuid = (string)$timer->getUuid();
		}

		return array_merge(
			$described,
			[
				'timer' => $uuid,
				'dueAt' => ($described['fireAt'] ?? null),
				'basis' => self::BASIS_TERM,
				'extensionCount' => $extensionCount,
				'extensionMax' => $extensionMax,
				'extendable' => ($extensionCount < $extensionMax),
			]
		);

	}//end report()
}//end class
