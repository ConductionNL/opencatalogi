<?php

/**
 * Golden test for RetentionService::recordHumanDecision.
 *
 * Every case is recorded once and replayed from the fixture: the saved
 * publication or the refusal, and every OpenRegister call. Moments relative to
 * the run are written as `<now>` or `<now+NM>` so the recording is stable.
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

namespace Unit\Service;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenCatalogi\Service\RetentionService;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Golden test for RetentionService::recordHumanDecision.
 *
 * @covers \OCA\OpenCatalogi\Service\RetentionService
 * @uses   \OCA\OpenCatalogi\Service\ReadsObjectProperties
 * @uses   \OCA\OpenCatalogi\Service\RetentionPolicyTable
 */
class RetentionDecisionGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/retention-decision-golden.json';

	/**
	 * Every OpenRegister call made, in order.
	 *
	 * @var array<int, mixed>
	 */
	private array $log = [];

	/**
	 * The recorded cases.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$pub = ['id' => 'p1', 'title' => 'T', 'retentionCategory' => 'besluit', 'retentionExpiresAt' => '2030-01-31T00:00:00+00:00'];

		return [
			'extend from the stored expiry' => [['decision' => 'extend', 'months' => 1, 'object' => $pub]],
			'extend with no stored expiry' => [['decision' => 'extend', 'months' => 12, 'object' => ['id' => 'p2']]],
			'extend with an unreadable expiry' => [['decision' => 'extend', 'months' => 6, 'object' => ['uuid' => 'p3', 'retentionExpiresAt' => 'not a date']]],
			'extend by no months' => [['decision' => 'extend', 'months' => 0, 'object' => $pub]],
			'extend by negative months' => [['decision' => 'extend', 'months' => -2, 'object' => $pub]],
			'depublish' => [['decision' => 'depublish', 'object' => $pub]],
			'archive' => [['decision' => 'archive', 'object' => $pub]],
			'dispose with a decision log' => [['decision' => 'dispose', 'object' => array_merge($pub, ['retentionDecisionLog' => [['decision' => 'extend']]])]],
			'dispose with a broken decision log' => [['decision' => 'dispose', 'object' => array_merge($pub, ['retentionDecisionLog' => 'x'])]],
			'unknown decision' => [['decision' => 'shred', 'object' => $pub]],
			'blank rationale' => [['decision' => 'archive', 'rationale' => " \t", 'object' => $pub]],
			'no register configured' => [['decision' => 'archive', 'register' => '', 'object' => $pub]],
			'no schema configured' => [['decision' => 'archive', 'schema' => '', 'object' => $pub]],
			'no OpenRegister' => [['decision' => 'archive', 'openregister' => false, 'object' => $pub]],
			'publication not found' => [['decision' => 'archive', 'object' => null]],
			'object entity shape' => [['decision' => 'depublish', 'object' => ['id' => 'p9', 'object' => ['title' => 'Inner']]]],
			'no user' => [['decision' => 'archive', 'user' => false, 'object' => $pub]],
		];

	}//end cases()

	/**
	 * Replay a case and compare it to the recording.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testDecisionMatchesTheRecording(array $case): void {
		$name = $this->dataName();
		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		try {
			$outcome = ['saved' => $this->service(case: $case)->recordHumanDecision('p1', $case['decision'], ($case['rationale'] ?? 'Legal hold'), ($case['months'] ?? 0))];
		} catch (\Throwable $e) {
			$outcome = ['refused' => get_class($e), 'message' => $e->getMessage()];
		}

		$actual = $this->relative(value: ['outcome' => $outcome, 'calls' => $this->log], now: $now);

		$recorded = [];
		if (file_exists(self::FIXTURE) === true) {
			$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$name] = $actual;
			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $recorded, 'No recording for ' . $name);
		$this->assertSame($recorded[$name], $actual);

	}//end testDecisionMatchesTheRecording()

	/**
	 * Replace moments relative to the run with `<now>` or `<now+NM>`.
	 *
	 * @param mixed             $value The value.
	 * @param DateTimeImmutable $now   The moment the case started.
	 *
	 * @return mixed The value.
	 */
	private function relative(mixed $value, DateTimeImmutable $now): mixed {
		if (is_array($value) === true) {
			return array_map(fn ($item) => $this->relative(value: $item, now: $now), $value);
		}

		if (is_string($value) === false || preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d$/', $value) !== 1) {
			return $value;
		}

		$at = (new DateTimeImmutable($value))->getTimestamp();
		for ($months = 0; $months <= 24; $months++) {
			$expected = $now->add(new DateInterval('P' . $months . 'M'))->getTimestamp();
			if (abs($at - $expected) <= 5) {
				return ($months === 0 ? '<now>' : '<now+' . $months . 'M>');
			}
		}

		return $value;

	}//end relative()

	/**
	 * Build the service over a recording ObjectService.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return RetentionService The service.
	 */
	private function service(array $case): RetentionService {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				'publication_register' => ($case['register'] ?? 'pub-reg'),
				'publication_schema' => ($case['schema'] ?? 'pub-sch'),
				default => $default,
			}
		);

		$log = &$this->log;
		$objects = new class($log, $case['object']) {
			public function __construct(
				private array &$log,
				private ?array $object,
			) {
			}

			public function find(string $id): array {
				$this->log[] = ['find' => $id];
				if ($this->object === null) {
					throw new \RuntimeException('missing');
				}

				return $this->object;
			}

			public function saveObject(array $object, ?string $register = null, ?string $schema = null, string $uuid = '', bool $_rbac = true, bool $_multitenancy = true): array {
				$this->log[] = ['save' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
				return array_merge($object, ['saved' => true]);
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		if (($case['openregister'] ?? true) === true) {
			$container->method('get')->willReturn($objects);
		} else {
			$container->method('get')->willThrowException(new \RuntimeException('not installed'));
		}

		$session = $this->createMock(IUserSession::class);
		if (($case['user'] ?? true) === true) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('officer1');
			$session->method('getUser')->willReturn($user);
		}

		return new RetentionService($config, $container, $session, $this->createMock(LoggerInterface::class));

	}//end service()
}//end class
