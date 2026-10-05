<?php

/**
 * Unit tests for StatutoryTerm.
 *
 * Nothing here recomputes a deadline, and the tests are written so they cannot
 * pretend to. What they assert is the handover: that the configuration this app
 * hands the engine declares the Woo term, that the bound on the extension is
 * DECLARED TO the engine rather than counted alongside it, and that a refusal
 * from the engine arrives at the caller as a refusal and not as a success.
 *
 * The engine is faked as a duck-typed object, the same way the consumed
 * ObjectService is faked elsewhere in this suite. The fake reproduces exactly two
 * behaviours of the real one, both read out of
 * `OCA\OpenRegister\Service\Flow\Timer\FlowTimerService`: `extend()` refuses once
 * `extension_count >= extension_max`, and `suspend()` leaves the term with no
 * fire moment.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Service\Woo;

use DateTimeImmutable;
use OCA\OpenCatalogi\Service\Woo\StatutoryTerm;
use OCA\OpenCatalogi\Service\Woo\TermEngineUnavailableException;
use OCA\OpenCatalogi\Service\Woo\TermRefusedException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * A duck-typed stand-in for one of the engine's terms.
 */
class FakeFlowTimer {

	public function __construct(
		private string $uuid,
		private ?string $fireAt,
		private string $state,
		private int $extensionCount,
		private int $extensionMax,
	) {
	}//end __construct()

	public function getUuid(): string {
		return $this->uuid;
	}//end getUuid()

	public function getFireAt(): ?string {
		return $this->fireAt;
	}//end getFireAt()

	public function getState(): string {
		return $this->state;
	}//end getState()

	public function getExtensionCount(): int {
		return $this->extensionCount;
	}//end getExtensionCount()

	public function getExtensionMax(): int {
		return $this->extensionMax;
	}//end getExtensionMax()

	public function withExtension(string $fireAt): self {
		return new self($this->uuid, $fireAt, 'armed', ($this->extensionCount + 1), $this->extensionMax);
	}//end withExtension()

	public function suspended(): self {
		// The real engine sets fire_at to NULL on suspend, which is the whole
		// point of a suspension holding elapsed time rather than a moment.
		return new self($this->uuid, null, 'suspended', $this->extensionCount, $this->extensionMax);
	}//end suspended()

	public function resumed(string $fireAt): self {
		return new self($this->uuid, $fireAt, 'armed', $this->extensionCount, $this->extensionMax);
	}//end resumed()
}//end class

/**
 * A duck-typed stand-in for the engine, reproducing its extension bound and its
 * suspension semantics.
 */
class FakeTermEngine {

	/** @var array<string, FakeFlowTimer> */
	public array $terms = [];

	/** @var array<int, array<string, mixed>> */
	public array $armedConfigs = [];

	/** @var array<int, array<string, mixed>> */
	public array $calls = [];

	public function arm(array $config, ?string $actor): FakeFlowTimer {
		$this->armedConfigs[] = $config;
		$timer = new FakeFlowTimer(
			'timer-1',
			'2026-03-30T09:00:00+00:00',
			'armed',
			0,
			(int)($config['extensionMax'] ?? 1)
		);
		$this->terms[$timer->getUuid()] = $timer;

		return $timer;
	}//end arm()

	public function extend(string $uuid, int $amount, string $unit, string $rationale, ?string $actor): FakeFlowTimer {
		$this->calls[] = ['op' => 'extend', 'amount' => $amount, 'unit' => $unit, 'rationale' => $rationale];
		$timer = $this->terms[$uuid];

		// The real refusal, from FlowTimerService::extend().
		if ($timer->getExtensionCount() >= $timer->getExtensionMax()) {
			throw new \RuntimeException(
				sprintf(
					"Timer '%s' has reached its extension bound of %d; a further extension requires the separately authorized override.",
					$uuid,
					$timer->getExtensionMax()
				)
			);
		}

		$this->terms[$uuid] = $timer->withExtension('2026-04-13T09:00:00+00:00');

		return $this->terms[$uuid];
	}//end extend()

	public function suspend(string $uuid, string $reason, mixed $until, ?string $actor, ?string $basis = null): FakeFlowTimer {
		$this->calls[] = ['op' => 'suspend', 'reason' => $reason, 'basis' => $basis];
		if (trim($reason) === '') {
			throw new \RuntimeException('Suspending a term requires a non-empty reason.');
		}

		$this->terms[$uuid] = $this->terms[$uuid]->suspended();

		return $this->terms[$uuid];
	}//end suspend()

	public function resume(string $uuid, ?string $reason, ?string $actor): FakeFlowTimer {
		$this->calls[] = ['op' => 'resume', 'reason' => $reason];
		if ($this->terms[$uuid]->getState() !== 'suspended') {
			throw new \RuntimeException('Timer cannot be resumed: its state is not suspended.');
		}

		$this->terms[$uuid] = $this->terms[$uuid]->resumed('2026-04-02T09:00:00+00:00');

		return $this->terms[$uuid];
	}//end resume()

	public function describe(object $timer, mixed $now = null): array {
		return [
			'overdue' => false,
			'remaining' => 10.0,
			'unit' => 'calendarDays',
			'overdueBy' => null,
			'fireAt' => $timer->getFireAt(),
			'state' => $timer->getState(),
			'unrolledAt' => null,
			'rolledBy' => null,
		];
	}//end describe()
}//end class

/**
 * A duck-typed stand-in for the engine's store.
 */
class FakeTermStore {

	public function __construct(private FakeTermEngine $engine) {
	}//end __construct()

	public function findByUuid(string $uuid): FakeFlowTimer {
		if (isset($this->engine->terms[$uuid]) === false) {
			throw new \RuntimeException('not found');
		}

		return $this->engine->terms[$uuid];
	}//end findByUuid()

	public function findBySubject(string $subjectType, string $subjectUuid, array $states = []): array {
		return array_values($this->engine->terms);
	}//end findBySubject()
}//end class

/**
 * Unit tests for StatutoryTerm.
 */
class StatutoryTermTest extends TestCase {

	private FakeTermEngine $engine;
	private StatutoryTerm $terms;

	/**
	 * Wire the service onto the fake engine, through the container, exactly as
	 * it resolves it in production.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->engine = new FakeTermEngine();
		$store = new FakeTermStore($this->engine);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($store) {
				if ($id === 'OCA\OpenRegister\Service\Flow\Timer\FlowTimerService') {
					return $this->engine;
				}

				if ($id === 'OCA\OpenRegister\Db\FlowTimerMapper') {
					return $store;
				}

				throw new \RuntimeException('not bound: ' . $id);
			}
		);

		$this->terms = new StatutoryTerm($container, $this->createMock(LoggerInterface::class));

	}//end setUp()

	/**
	 * The configuration handed to the engine IS the Dutch Woo term.
	 *
	 * Every value here is load-bearing and every one of them is a value the
	 * engine refuses or silently reads differently if it is wrong: `calendarDays`
	 * is one of three accepted units, `next` is one of three accepted rolls and an
	 * unknown one is refused rather than read as `none`, `wettelijk` is what makes
	 * a missed term a recorded breach, and `object` is one of the three subject
	 * types the engine accepts at all.
	 *
	 * @return void
	 */
	public function testTheTermConfigurationDeclaresTheDutchWooTerm(): void {
		$config = $this->terms->termConfig(
			requestUuid: 'req-1',
			reference: 'WOO-2026-ABCDEF',
			receivedAt: new DateTimeImmutable('2026-03-02T09:00:00+00:00')
		);

		$this->assertSame('object', $config['subjectType']);
		$this->assertSame('req-1', $config['subjectUuid']);
		$this->assertSame('expiry', $config['purpose']);
		$this->assertSame('wettelijk', $config['legalEffect']);
		$this->assertSame(28, $config['sla']['value'], 'The Woo decision term is four weeks.');
		$this->assertSame('calendarDays', $config['sla']['unit']);
		$this->assertSame('next', $config['sla']['rollToWorkingDay'], 'Algemene termijnenwet: roll off a non-working day.');
		$this->assertSame('woo-request-received', $config['anchorEvent']);
		$this->assertSame('Woo art. 4.4 lid 1', $config['metadata']['basis']);
		$this->assertSame('woo-request-decision-term', $config['metadata']['kind']);

	}//end testTheTermConfigurationDeclaresTheDutchWooTerm()

	/**
	 * The BOUND is declared to the engine, and the engine is what holds it.
	 *
	 * This is the assertion that matters more than the refusal test below: a
	 * count kept in this app can disagree with the engine's, and the engine's is
	 * the one that decides.
	 *
	 * @return void
	 */
	public function testTheExtensionBoundIsDeclaredToTheEngine(): void {
		$this->terms->arm(
			requestUuid: 'req-1',
			reference: 'WOO-2026-ABCDEF',
			receivedAt: new DateTimeImmutable('2026-03-02T09:00:00+00:00'),
			actor: 'alice'
		);

		$this->assertCount(1, $this->engine->armedConfigs);
		$this->assertSame(1, $this->engine->armedConfigs[0]['extensionMax'], 'The engine must be told the bound is one.');

	}//end testTheExtensionBoundIsDeclaredToTheEngine()

	/**
	 * The armed term reports the due date, the count and whether it can still be
	 * extended.
	 *
	 * @return void
	 */
	public function testAnArmedTermReportsItsDueDateAndItsHeadroom(): void {
		$term = $this->terms->arm(
			requestUuid: 'req-1',
			reference: 'WOO-2026-ABCDEF',
			receivedAt: new DateTimeImmutable('2026-03-02T09:00:00+00:00'),
			actor: 'alice'
		);

		$this->assertSame('timer-1', $term['timer']);
		$this->assertSame('2026-03-30T09:00:00+00:00', $term['dueAt']);
		$this->assertSame('armed', $term['state']);
		$this->assertSame(0, $term['extensionCount']);
		$this->assertSame(1, $term['extensionMax']);
		$this->assertTrue($term['extendable']);

	}//end testAnArmedTermReportsItsDueDateAndItsHeadroom()

	/**
	 * The first extension is granted, for the two weeks the law allows.
	 *
	 * @return void
	 */
	public function testTheFirstExtensionIsGrantedForTwoWeeks(): void {
		$this->arm();

		$term = $this->terms->extendOnce(timerUuid: 'timer-1', rationale: 'The set is large.', actor: 'alice');

		$this->assertSame(14, $this->engine->calls[0]['amount'], 'The Woo extension is two weeks.');
		$this->assertSame('calendarDays', $this->engine->calls[0]['unit']);
		$this->assertSame(1, $term['extensionCount']);
		$this->assertFalse($term['extendable'], 'After the one extension the term must report no headroom left.');

	}//end testTheFirstExtensionIsGrantedForTwoWeeks()

	/**
	 * 🔴 The SECOND extension is REFUSED, and arrives as a refusal.
	 *
	 * @return void
	 */
	public function testTheSecondExtensionIsRefused(): void {
		$this->arm();
		$this->terms->extendOnce(timerUuid: 'timer-1', rationale: 'The set is large.', actor: 'alice');

		$this->expectException(TermRefusedException::class);
		$this->expectExceptionMessageMatches('/extension bound of 1/');
		$this->terms->extendOnce(timerUuid: 'timer-1', rationale: 'Still large.', actor: 'alice');

	}//end testTheSecondExtensionIsRefused()

	/**
	 * A refused second extension leaves the term exactly as it was.
	 *
	 * @return void
	 */
	public function testARefusedSecondExtensionChangesNothing(): void {
		$this->arm();
		$this->terms->extendOnce(timerUuid: 'timer-1', rationale: 'The set is large.', actor: 'alice');
		$before = $this->terms->describe(timerUuid: 'timer-1');

		try {
			$this->terms->extendOnce(timerUuid: 'timer-1', rationale: 'Still large.', actor: 'alice');
			$this->fail('The second extension must be refused.');
		} catch (TermRefusedException $e) {
			$after = $this->terms->describe(timerUuid: 'timer-1');
			$this->assertSame($before['dueAt'], $after['dueAt']);
			$this->assertSame(1, $after['extensionCount']);
		}

	}//end testARefusedSecondExtensionChangesNothing()

	/**
	 * A pause suspends the term, cites the clarification ground, and leaves it
	 * with no due moment while it is held.
	 *
	 * @return void
	 */
	public function testAPauseSuspendsTheTermAndLeavesNoDueMoment(): void {
		$this->arm();

		$term = $this->terms->pause(timerUuid: 'timer-1', reason: 'The request is too general.', actor: 'alice');

		$this->assertSame('suspended', $term['state']);
		$this->assertNull($term['dueAt'], 'A suspended term holds elapsed time, so it has no due moment.');
		$this->assertSame('Woo art. 4.1 lid 4', $this->engine->calls[0]['basis']);

	}//end testAPauseSuspendsTheTermAndLeavesNoDueMoment()

	/**
	 * A resume puts the term back on the clock.
	 *
	 * @return void
	 */
	public function testAResumePutsTheTermBackOnTheClock(): void {
		$this->arm();
		$this->terms->pause(timerUuid: 'timer-1', reason: 'The request is too general.', actor: 'alice');

		$term = $this->terms->resume(timerUuid: 'timer-1', reason: 'The requester answered.', actor: 'alice');

		$this->assertSame('armed', $term['state']);
		$this->assertSame('2026-04-02T09:00:00+00:00', $term['dueAt']);

	}//end testAResumePutsTheTermBackOnTheClock()

	/**
	 * Resuming a term that is not suspended is refused.
	 *
	 * @return void
	 */
	public function testResumingARunningTermIsRefused(): void {
		$this->arm();

		$this->expectException(TermRefusedException::class);
		$this->terms->resume(timerUuid: 'timer-1', reason: 'Nothing to resume.', actor: 'alice');

	}//end testResumingARunningTermIsRefused()

	/**
	 * An unavailable engine is told apart from a refusal, because the answers
	 * differ: one is the system working and the other is a term nobody counts.
	 *
	 * @return void
	 */
	public function testAnUnavailableEngineIsNotARefusal(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new \RuntimeException('not installed'));
		$terms = new StatutoryTerm($container, $this->createMock(LoggerInterface::class));

		$this->expectException(TermEngineUnavailableException::class);
		$terms->arm(
			requestUuid: 'req-1',
			reference: 'WOO-2026-ABCDEF',
			receivedAt: new DateTimeImmutable('2026-03-02T09:00:00+00:00'),
			actor: 'alice'
		);

	}//end testAnUnavailableEngineIsNotARefusal()

	/**
	 * Arm a term for the tests that work one.
	 *
	 * @return void
	 */
	private function arm(): void {
		$this->terms->arm(
			requestUuid: 'req-1',
			reference: 'WOO-2026-ABCDEF',
			receivedAt: new DateTimeImmutable('2026-03-02T09:00:00+00:00'),
			actor: 'alice'
		);
		$this->engine->calls = [];

	}//end arm()
}//end class
