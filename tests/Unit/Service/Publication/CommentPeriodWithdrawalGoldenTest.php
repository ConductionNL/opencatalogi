<?php

/**
 * Golden test for CommentPeriodWithdrawalService::withdrawClosedPeriods.
 *
 * Every case is recorded once and replayed from the fixture: the counts, each
 * depublication asked for, each write to OpenRegister and each log line.
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

namespace Unit\Service\Publication;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenCatalogi\Service\Publication\CommentPeriodService;
use OCA\OpenCatalogi\Service\Publication\CommentPeriodWithdrawalService;
use OCA\OpenCatalogi\Service\Publication\DepublicationService;
use OCA\OpenCatalogi\Service\Publication\TermRoll;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * An OpenRegister ObjectService stand-in that records what it is asked.
 */
class WithdrawalGoldenObjects {

	/**
	 * @param array<int, mixed>                $rows  What a search answers.
	 * @param array<int, array<string, mixed>> $calls The shared call log.
	 * @param string                           $fail  Which call throws: '', 'search', 'save-depublication', 'save-period'.
	 */
	public function __construct(
		private array $rows,
		private array &$calls,
		private string $fail,
	) {
	}

	public function searchObjectsPaginated(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
		$this->calls[] = ['search' => $query, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
		if ($this->fail === 'search') {
			throw new \RuntimeException('search failed');
		}

		return ['results' => $this->rows];
	}

	public function saveObject(
		array $object,
		?array $extend = [],
		$register = null,
		$schema = null,
		?string $uuid = null,
		bool $_rbac = true,
		bool $_multitenancy = true
	): array {
		$this->calls[] = ['save' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
		if ($uuid === null && $this->fail === 'save-depublication') {
			throw new \RuntimeException('depublication save failed');
		}

		if ($uuid !== null && $this->fail === 'save-period') {
			throw new \RuntimeException('period save failed');
		}

		if ($uuid === null) {
			return ['id' => 'dep-' . count($this->calls), 'object' => $object];
		}

		return $object;
	}
}

/**
 * Golden test for CommentPeriodWithdrawalService::withdrawClosedPeriods.
 *
 * @covers \OCA\OpenCatalogi\Service\Publication\CommentPeriodWithdrawalService
 * @uses   \OCA\OpenCatalogi\Service\Publication\CommentPeriodService
 * @uses   \OCA\OpenCatalogi\Service\Publication\TermRoll
 * @uses   \OCA\OpenCatalogi\Service\Publication\CommentPeriodWithdrawalRun
 */
class CommentPeriodWithdrawalGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/comment-period-withdrawal-golden.json';

	/**
	 * Every call made, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $calls = [];

	/**
	 * The recorded cases.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$closed = ['startDate' => '2026-01-01T00:00:00+00:00', 'endDate' => '2026-02-01T00:00:00+00:00'];
		$open = ['startDate' => '2026-01-01T00:00:00+00:00', 'endDate' => '2026-12-01T00:00:00+00:00'];
		$due = array_merge($closed, ['id' => 'p-due', 'publication' => 'pub-1', 'automaticWithdrawal' => true, 'legalRemedy' => 'zienswijze']);
		$rows = [
			$due,
			array_merge($open, ['id' => 'p-open', 'publication' => 'pub-2', 'automaticWithdrawal' => true]),
			array_merge($closed, ['id' => 'p-done', 'publication' => 'pub-3', 'automaticWithdrawal' => true, 'withdrawnAt' => '2026-02-02T00:00:00+00:00']),
			array_merge($closed, ['id' => 'p-manual', 'publication' => 'pub-4', 'automaticWithdrawal' => false]),
			['id' => 'p-unreadable', 'publication' => 'pub-5', 'automaticWithdrawal' => true, 'startDate' => 'x', 'endDate' => 'y'],
			['id' => 'p-wrapped', 'object' => array_merge($closed, ['publication' => 'pub-6', 'automaticWithdrawal' => true])],
			array_merge($closed, ['publication' => 'pub-7', 'automaticWithdrawal' => true]),
		];
		$config = [
			'publication_register' => ' reg-1 ',
			'comment_period_schema' => ' schema-cp ',
			'depublication_schema' => ' schema-dep ',
			'channel_sources' => '{"national-woo-index":"src","plooi":" ","national":"x","other":"y"}',
		];

		$cases = [
			'withdraws what is due' => [],
			'no OpenRegister' => ['openregister' => false],
			'no register' => ['config' => ['publication_register' => '  ']],
			'no schema' => ['config' => ['comment_period_schema' => '']],
			'search fails' => ['fail' => 'search'],
			'no depublication schema' => ['config' => ['depublication_schema' => '']],
			'channel sources unreadable' => ['config' => ['channel_sources' => 'nope']],
			'no channel sources' => ['config' => ['channel_sources' => '{}']],
			'depublication save fails' => ['fail' => 'save-depublication'],
			'period save fails' => ['fail' => 'save-period'],
			'depublish refuses' => ['depublish' => 'refuse'],
			'nothing to consider' => ['rows' => []],
			'one due only' => ['rows' => [$due]],
		];

		$out = [];
		foreach ($cases as $name => $case) {
			$case['config'] = array_merge($config, ($case['config'] ?? []));
			$case['rows'] = ($case['rows'] ?? $rows);
			$out[$name] = [$case];
		}

		return $out;

	}//end cases()

	/**
	 * Replay a case and compare it to the recording.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testWithdrawalMatchesTheRecording(array $case): void {
		$name = $this->dataName();
		$counts = $this->service(case: $case)->withdrawClosedPeriods(now: new DateTimeImmutable('2026-03-01T12:00:00+01:00'));
		$actual = ['counts' => $counts, 'calls' => $this->calls];

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

	}//end testWithdrawalMatchesTheRecording()

	/**
	 * Without a moment, the pass reads the clock.
	 *
	 * @return void
	 */
	public function testWithoutAMomentThePassUsesNow(): void {
		$case = self::cases()['one due only'][0];
		$counts = $this->service(case: $case)->withdrawClosedPeriods();

		$this->assertSame(['considered' => 1, 'withdrawn' => 1, 'failed' => 0], $counts);
		$period = end($this->calls)['save'];
		$withdrawnAt = new DateTimeImmutable($period['withdrawnAt']);
		$this->assertEqualsWithDelta(time(), $withdrawnAt->getTimestamp(), 5);
		$this->assertSame('+00:00', $withdrawnAt->format('P'));

	}//end testWithoutAMomentThePassUsesNow()

	/**
	 * Build the service over recording fakes.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return CommentPeriodWithdrawalService The service.
	 */
	private function service(array $case): CommentPeriodWithdrawalService {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => (string)($case['config'][$key] ?? $default)
		);

		$objects = new WithdrawalGoldenObjects($case['rows'], $this->calls, (string)($case['fail'] ?? ''));
		$container = $this->createMock(ContainerInterface::class);
		if (($case['openregister'] ?? true) === true) {
			$container->method('get')->willReturn($objects);
		} else {
			$container->method('get')->willThrowException(new \RuntimeException('not installed'));
		}

		$depublications = $this->getMockBuilder(DepublicationService::class)
			->disableOriginalConstructor()
			->onlyMethods(['depublish'])
			->getMock();
		$depublications->method('depublish')->willReturnCallback(
			function (array $publication, string $reason, string $depublishedBy, array $channels, ?DateTimeInterface $now = null) use ($case): array {
				$this->calls[] = ['depublish' => $publication, 'reason' => $reason, 'by' => $depublishedBy, 'channels' => $channels, 'now' => $now?->format(DateTimeInterface::ATOM)];
				if (($case['depublish'] ?? '') === 'refuse') {
					throw new \DomainException('refused');
				}

				return ['publication' => $publication['id'], 'channels' => $channels];
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message) use ($level): void {
					$this->calls[] = ['log' => $level, 'message' => $message];
				}
			);
		}

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);
		$urls = $this->createMock(IURLGenerator::class);
		$periods = new CommentPeriodService(new TermRoll($this->createMock(ContainerInterface::class), $appManager), $config, $urls);

		return new CommentPeriodWithdrawalService($periods, $depublications, $config, $container, $logger);

	}//end service()
}//end class
