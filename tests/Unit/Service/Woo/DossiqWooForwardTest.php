<?php

/**
 * Tests for DossiqWooForward: the hand-over of a new Woo request to dossiq.
 *
 * @category Test
 * @package  Unit\Service\Woo
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @version GIT: <git_id>
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Service\Woo;

use OCA\OpenCatalogi\Contract\PortalContributionProviderInterface;
use OCA\OpenCatalogi\Service\Woo\DossiqWooForward;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Pins the exact call on dossiq's `WooRequestIntake::receive()` (method, named
 * arguments, origin), that its answer passes through unchanged, and that a
 * throwing call or a malformed answer is `unavailable`.
 *
 * The double is shaped from dossiq's documented signature
 * `receive(array $answers, string $receivedAt = '', string $origin = 'portal-form'): array`
 * (REQ-WTO-001); when dossiq's class is autoloadable its real signature is checked too.
 *
 * @covers \OCA\OpenCatalogi\Service\Woo\DossiqWooForward
 */
class DossiqWooForwardTest extends TestCase {

	private const ARMED = [
		'outcome' => 'armed',
		'requestId' => 'case-uuid-1',
		'reference' => 'WOO-2026-000123',
		'dueAt' => '2026-12-28',
		'message' => 'Received.',
		'caseUrl' => 'https://nc.example/index.php/apps/dossiq/cases/case-uuid-1',
	];

	/**
	 * What the intake double was called with.
	 *
	 * @var array<int, array{answers: array, receivedAt: string, origin: string}>
	 */
	private array $calls = [];

	/**
	 * A portal request goes to dossiq with origin portal-form, the answers and the moment.
	 *
	 * @return void
	 */
	public function testAPortalRequestIsForwardedWithPortalFormOrigin(): void {
		$answers = ['requestedInformation' => 'Alle adviezen over de Stationsweg 2025', 'requesterName' => 'J. de Vries', 'requesterEmail' => 'j@example.nl'];

		$answer = $this->forward(intake: $this->intake(answer: self::ARMED))
			->forward(answers: $answers, receivedAt: '2026-11-27T10:15:00+01:00', origin: DossiqWooForward::ORIGIN_PORTAL_FORM);

		$this->assertSame([['answers' => $answers, 'receivedAt' => '2026-11-27T10:15:00+01:00', 'origin' => 'portal-form']], $this->calls);
		$this->assertSame(self::ARMED, $answer);
	}//end testAPortalRequestIsForwardedWithPortalFormOrigin()

	/**
	 * An officer's request goes to dossiq with origin opencatalogi.
	 *
	 * @return void
	 */
	public function testAnOfficerRequestIsForwardedWithOpencatalogiOrigin(): void {
		$this->forward(intake: $this->intake(answer: self::ARMED))
			->forward(answers: ['requestedInformation' => 'x'], receivedAt: '', origin: DossiqWooForward::ORIGIN_OPENCATALOGI);

		$this->assertSame('opencatalogi', $this->calls[0]['origin']);
		$this->assertSame('', $this->calls[0]['receivedAt']);
	}//end testAnOfficerRequestIsForwardedWithOpencatalogiOrigin()

	/**
	 * A throwing call, or an intake that does not resolve, is unavailable and logged.
	 *
	 * @return void
	 */
	public function testAThrowingForwardAnswersUnavailableAndStoresNothing(): void {
		$throwing = new class {
			/**
			 * Throws.
			 *
			 * @param array  $answers    Answers.
			 * @param string $receivedAt Moment.
			 * @param string $origin     Origin.
			 *
			 * @return array
			 */
			public function receive(array $answers, string $receivedAt = '', string $origin = 'portal-form'): array {
				throw new RuntimeException('dossiq down');
			}
		};
		foreach (['throws' => $throwing, 'does not resolve' => null] as $name => $intake) {
			$logger = $this->createMock(LoggerInterface::class);
			$logger->expects(self::once())->method('error');

			$answer = $this->forward(intake: $intake, logger: $logger)->forward(answers: [], receivedAt: '', origin: 'portal-form');

			$this->assertSame('unavailable', $answer['outcome'], $name);
			$this->assertSame('', $answer['requestId'], $name);
			$this->assertSame('', $answer['reference'], $name);
			$this->assertSame('', $answer['dueAt'], $name);
			$this->assertStringContainsString('send it again', $answer['message'], $name);
		}
	}//end testAThrowingForwardAnswersUnavailableAndStoresNothing()

	/**
	 * An answer missing one of the five keys, or with a non-string in one, is unavailable.
	 *
	 * @return void
	 */
	public function testAnAnswerMissingAKeyIsUnavailable(): void {
		$cases = [];
		foreach (DossiqWooForward::ANSWER_KEYS as $key) {
			$missing = self::ARMED;
			unset($missing[$key]);
			$cases['without ' . $key] = $missing;
			$cases[$key . ' not a string'] = array_replace(self::ARMED, [$key => 7]);
		}

		$cases['not an array'] = 'armed';
		foreach ($cases as $name => $bad) {
			$answer = $this->forward(intake: $this->intake(answer: $bad))->forward(answers: [], receivedAt: '', origin: 'portal-form');
			$this->assertSame('unavailable', $answer['outcome'], $name);
			$this->assertSame('', $answer['reference'], $name);
		}
	}//end testAnAnswerMissingAKeyIsUnavailable()

	/**
	 * dossiq's answer passes through unchanged, and a missing caseUrl reads as empty.
	 *
	 * @return void
	 */
	public function testTheAnswerIsPassedThroughUnchanged(): void {
		$refused = ['outcome' => 'refused', 'requestId' => '', 'reference' => '', 'dueAt' => '', 'message' => 'Say what you ask for.'];

		$this->assertSame(self::ARMED, $this->forward(intake: $this->intake(answer: self::ARMED + ['extra' => 'x']))->forward(answers: [], receivedAt: '', origin: 'opencatalogi'));
		$this->assertSame($refused + ['caseUrl' => ''], $this->forward(intake: $this->intake(answer: $refused))->forward(answers: [], receivedAt: '', origin: 'opencatalogi'));
	}//end testTheAnswerIsPassedThroughUnchanged()

	/**
	 * The five keys are those of OpenCatalogi's receiveWooRequest() contract and of dossiq's answer.
	 *
	 * @return void
	 */
	public function testTheFiveKeysMatchDossiqsContract(): void {
		$ours = self::docblockReturnKeys(class: PortalContributionProviderInterface::class, method: 'receiveWooRequest');
		$this->assertSame(DossiqWooForward::ANSWER_KEYS, $ours);

		// dossiq REQ-WTO-001: the five keys plus caseUrl.
		$dossiq = ['outcome', 'requestId', 'reference', 'dueAt', 'message', 'caseUrl'];
		if (class_exists(DossiqWooForward::INTAKE_CLASS) === true) {
			$dossiq = self::docblockReturnKeys(class: DossiqWooForward::INTAKE_CLASS, method: 'receive');
			$parameters = array_map(static fn ($p): string => $p->getName(), (new ReflectionMethod(DossiqWooForward::INTAKE_CLASS, 'receive'))->getParameters());
			$this->assertSame(['answers', 'receivedAt', 'origin'], $parameters);
		}

		$this->assertSame(DossiqWooForward::ANSWER_KEYS, array_slice($dossiq, 0, 5));
	}//end testTheFiveKeysMatchDossiqsContract()

	/**
	 * Availability follows whether dossiq is enabled for anyone.
	 *
	 * @return void
	 */
	public function testAvailabilityFollowsDossiq(): void {
		foreach ([true, false] as $enabled) {
			$apps = $this->createMock(IAppManager::class);
			$apps->expects(self::once())->method('isEnabledForAnyone')->with('dossiq')->willReturn($enabled);
			$forward = new DossiqWooForward(appManager: $apps, container: $this->createMock(ContainerInterface::class), logger: $this->createMock(LoggerInterface::class));
			$this->assertSame($enabled, $forward->isAvailable());
		}
	}//end testAvailabilityFollowsDossiq()

	/**
	 * The keys of an `@return array{...}` docblock.
	 *
	 * @param string $class  The class.
	 * @param string $method The method.
	 *
	 * @return array<int, string>
	 */
	private static function docblockReturnKeys(string $class, string $method): array {
		$doc = (string)(new ReflectionMethod($class, $method))->getDocComment();
		preg_match('/@return array\{([^}]*)\}/', $doc, $match);
		preg_match_all('/(\w+)\s*:/', ($match[1] ?? ''), $keys);
		return $keys[1];
	}//end docblockReturnKeys()

	/**
	 * An intake double that records its named arguments and answers the given value.
	 *
	 * @param mixed $answer What receive() returns.
	 *
	 * @return object
	 */
	private function intake(mixed $answer): object {
		$test = $this;
		return new class($answer, $test) {
			/**
			 * Constructor.
			 *
			 * @param mixed                  $answer The answer.
			 * @param DossiqWooForwardTest   $test   The recording test.
			 */
			public function __construct(private mixed $answer, private DossiqWooForwardTest $test) {
			}

			/**
			 * Records the call.
			 *
			 * @param array  $answers    Answers.
			 * @param string $receivedAt Moment.
			 * @param string $origin     Origin.
			 *
			 * @return mixed
			 */
			public function receive(array $answers, string $receivedAt = '', string $origin = 'portal-form'): mixed {
				$this->test->record(call: ['answers' => $answers, 'receivedAt' => $receivedAt, 'origin' => $origin]);
				return $this->answer;
			}
		};
	}//end intake()

	/**
	 * Record a call of the intake double.
	 *
	 * @param array{answers: array, receivedAt: string, origin: string} $call The call.
	 *
	 * @return void
	 */
	public function record(array $call): void {
		$this->calls[] = $call;
	}//end record()

	/**
	 * The forward over a container holding the given intake (null: does not resolve).
	 *
	 * @param object|null          $intake The intake.
	 * @param LoggerInterface|null $logger The logger.
	 *
	 * @return DossiqWooForward
	 */
	private function forward(?object $intake, ?LoggerInterface $logger = null): DossiqWooForward {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($intake): object {
				if ($id !== DossiqWooForward::INTAKE_CLASS || $intake === null) {
					throw new RuntimeException('not bound: ' . $id);
				}

				return $intake;
			}
		);

		return new DossiqWooForward(
			appManager: $this->createMock(IAppManager::class),
			container: $container,
			logger: ($logger ?? $this->createMock(LoggerInterface::class))
		);
	}//end forward()
}//end class
