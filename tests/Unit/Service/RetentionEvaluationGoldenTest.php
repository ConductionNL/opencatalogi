<?php

/**
 * Golden test for the daily retention evaluation.
 *
 * Runs `RetentionService::evaluate()` over a scripted OpenRegister object
 * service and compares the counts, the search, every saved object and every
 * log line with what was recorded. Dates are given relative to the run and
 * recorded relative to it, so the recording does not age.
 *
 * Re-record (only when behaviour is meant to change) with
 * `GOLDEN_RECORD=1 vendor/bin/phpunit tests/Unit/Service/RetentionEvaluationGoldenTest.php`.
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
 *
 * @spec openspec/specs/publication-retention-lifecycle/spec.md#requirement-daily-retention-evaluation-job-ret-005
 *
 * @covers \OCA\OpenCatalogi\Service\RetentionService
 * @uses \OCA\OpenCatalogi\Service\RetentionRun
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\RetentionService;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;

/**
 * A scripted OpenRegister ObjectService that records searches and saves.
 */
class GoldenRetentionObjectService {

	/** @var array<int, array<string, mixed>> Every call, in order. */
	public array $calls = [];

	/**
	 * @param array<int, mixed>|string $objects The search results, or 'throw'.
	 */
	public function __construct(
		private array|string $objects,
	) {
	}

	public function searchObjectsPaginated(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
		$this->calls[] = ['search' => $query, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
		if ($this->objects === 'throw') {
			throw new \RuntimeException('search down');
		}

		return ['results' => $this->objects];
	}

	public function saveObject(array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true) {
		$this->calls[] = ['save' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
		return $object;
	}
}

/**
 * A logger that keeps every line.
 */
class GoldenRetentionLogger extends AbstractLogger {

	/** @var array<int, string> */
	public array $lines = [];

	public function log($level, \Stringable|string $message, array $context = []): void {
		$this->lines[] = $level . ': ' . $message;
	}
}

/**
 * A publication row returned as an entity.
 */
class GoldenRetentionEntity implements \JsonSerializable {

	/**
	 * @param mixed $data The serialised row.
	 */
	public function __construct(
		private mixed $data,
	) {
	}

	public function jsonSerialize(): mixed {
		return $this->data;
	}
}

/**
 * Golden test for RetentionService::evaluate().
 */
class RetentionEvaluationGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../fixtures/retention-evaluation-golden.json';

	/**
	 * Each case: settings and the rows the search returns. `{now+N}` in a date is
	 * replaced with the run's date plus N days.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		return [
			'unconfigured' => [['store' => ['publication_register' => '', 'publication_schema' => 'sch'], 'objects' => []]],
			'search fails' => [['objects' => 'throw']],
			'every branch' => [[
				'store' => ['retention_warning_window_days' => '10'],
				'objects' => [
					['id' => 'no-expiry', 'title' => 'x'],
					['id' => 'empty-expiry', 'retentionExpiresAt' => ''],
					['id' => 'done-today', 'retentionExpiresAt' => '{now-5}', 'retentionAction' => 'depublish', 'retentionLastEvaluatedAt' => '{now+0}'],
					['id' => 'done-yesterday', 'retentionExpiresAt' => '{now-5}', 'retentionAction' => 'depublish', 'retentionLastEvaluatedAt' => '{now-1}'],
					['id' => 'bad-date', 'retentionExpiresAt' => 'not a date'],
					['id' => 'soon', 'retentionExpiresAt' => '{now+3}', 'retentionAction' => 'archive'],
					['uuid' => 'archive-uuid', 'retentionExpiresAt' => '{now-2}', 'retentionAction' => 'archive', 'retentionCategory' => 'besluiten', 'retentionDecisionLog' => 'broken'],
					['id' => 'review', 'retentionExpiresAt' => '{now-2}', 'retentionAction' => 'review'],
					['id' => 'unknown-action', 'retentionExpiresAt' => '{now-2}', 'retentionAction' => 'shred'],
					['id' => 'no-action', 'retentionExpiresAt' => '{now-30}'],
					['entity' => ['id' => 'entity', 'retentionExpiresAt' => '{now-1}', 'retentionAction' => 'depublish', 'retentionDecisionLog' => [['decision' => 'earlier']]]],
					['entity' => 'not an array'],
					'a string row',
				],
			]],
		];

	}//end cases()

	/**
	 * Evaluate one case and compare it with the recording.
	 *
	 * @param array<string, mixed> $case The case input.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testEvaluationMatchesTheRecording(array $case): void {
		$name = $this->dataName();
		$actual = $this->relativeDates(value: $this->evaluate(case: $case));

		$recorded = [];
		if (is_file(self::FIXTURE) === true) {
			$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$name] = $actual;
			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $recorded, 'No recording for this case.');
		$this->assertSame(
			json_encode($recorded[$name], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
			json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
		);

	}//end testEvaluationMatchesTheRecording()

	/**
	 * Run the evaluation for one case.
	 *
	 * @param array<string, mixed> $case The case input.
	 *
	 * @return array<string, mixed> The counts, the calls and the log lines.
	 */
	private function evaluate(array $case): array {
		$store = array_merge(['publication_register' => 'pub-reg', 'publication_schema' => 'pub-sch'], ($case['store'] ?? []));
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = '') => ($store[$key] ?? $default)
		);

		$objects = $case['objects'];
		if (is_array($objects) === true) {
			$objects = array_map(fn ($row) => $this->row(row: $row), $objects);
		}

		$objectService = new GoldenRetentionObjectService($objects);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('officer1');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$logger = new GoldenRetentionLogger();
		$counts = (new RetentionService($config, $container, $userSession, $logger))->evaluate();

		return ['counts' => $counts, 'calls' => $objectService->calls, 'log' => $logger->lines];
	}//end evaluate()

	/**
	 * Fill in the relative dates of a scripted row and wrap entity rows.
	 *
	 * @param mixed $row The scripted row.
	 *
	 * @return mixed The row as the search returns it.
	 */
	private function row(mixed $row): mixed {
		if (is_array($row) === false) {
			return $row;
		}

		array_walk_recursive(
			$row,
			static function (&$value): void {
				if (is_string($value) === true && preg_match('/^\{now([+-]\d+)\}$/', $value, $match) === 1) {
					$value = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify($match[1] . ' days')->format(\DateTimeInterface::ATOM);
				}
			}
		);

		if (array_key_exists('entity', $row) === true) {
			return new GoldenRetentionEntity($row['entity']);
		}

		return $row;
	}//end row()

	/**
	 * Replace every ATOM timestamp with its distance in days from today.
	 *
	 * @param mixed $value The recorded value.
	 *
	 * @return mixed The value with `<today+N>` in place of each timestamp.
	 */
	private function relativeDates(mixed $value): mixed {
		if (is_array($value) === true) {
			return array_map(fn ($item) => $this->relativeDates(value: $item), $value);
		}

		if (is_string($value) === true && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $value) === 1) {
			$today = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
			$day = new \DateTimeImmutable(substr($value, 0, 10), new \DateTimeZone('UTC'));
			return '<today' . sprintf('%+d', (int)$today->diff($day)->format('%r%a')) . '>';
		}

		return $value;
	}//end relativeDates()
}//end class
