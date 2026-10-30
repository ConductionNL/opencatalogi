<?php

/**
 * Golden test for CommentPeriodService::open.
 *
 * Every case is recorded once against the service and replayed from the
 * fixture, so a change to how a period is opened shows up as a changed field,
 * a changed refusal or a changed message.
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
use OCA\OpenCatalogi\Contract\CommentPeriodRequest;
use OCA\OpenCatalogi\Service\Publication\CommentPeriodService;
use OCA\OpenCatalogi\Service\Publication\TermRoll;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * A term engine calculator that adds days and rolls a weekend end to Monday.
 */
class GoldenSlaCalculator {

	public function add(\DateTimeInterface $from, float $value, string $unit, ?object $calendar): DateTimeImmutable {
		return DateTimeImmutable::createFromInterface($from)->modify(sprintf('%+d days', (int)$value));
	}//end add()

	public function roll(\DateTimeInterface $moment, string $roll, ?object $calendar): array {
		$at = DateTimeImmutable::createFromInterface($moment);
		$day = (int)$at->format('N');
		if ($day < 6) {
			return ['at' => $at, 'unrolledAt' => null, 'rolledBy' => null];
		}

		return ['at' => $at->modify('+' . (8 - $day) . ' days'), 'unrolledAt' => $at, 'rolledBy' => 'weekend'];
	}//end roll()
}//end class

/**
 * A term engine calculator whose end never moves past the start.
 */
class GoldenStillCalculator extends GoldenSlaCalculator {

	public function add(\DateTimeInterface $from, float $value, string $unit, ?object $calendar): DateTimeImmutable {
		return DateTimeImmutable::createFromInterface($from);
	}//end add()
}//end class

/**
 * A term engine calendar service that resolves to an empty calendar.
 */
class GoldenCalendarService {

	public function resolve(?string $slug, ?string $organisation): object {
		return new \stdClass();
	}//end resolve()
}//end class

/**
 * Golden test for CommentPeriodService::open.
 *
 * @covers \OCA\OpenCatalogi\Service\Publication\CommentPeriodService
 * @uses   \OCA\OpenCatalogi\Service\Publication\TermRoll
 * @uses   \OCA\OpenCatalogi\Contract\CommentPeriodRequest
 * @uses   \OCA\OpenCatalogi\Service\Publication\TermRollUnavailableException
 */
class CommentPeriodOpenGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/comment-period-open-golden.json';

	/**
	 * The recorded cases: open() arguments plus the service setup.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$base = [
			'publication' => ['id' => 'pub-1'],
			'termDays' => 14,
			'legalRemedy' => 'zienswijze',
			'announcementUrl' => 'https://officielebekendmakingen.nl/stcrt-2026-1',
			'automaticWithdrawal' => false,
			'openedBy' => 'alice',
			'start' => '2026-03-02T10:00:00+00:00',
		];

		$cases = [
			'weekday end' => [],
			'weekend end rolls' => ['termDays' => 5, 'legalRemedy' => 'bezwaar', 'automaticWithdrawal' => true],
			'sunday end rolls' => ['termDays' => 6],
			'one day' => ['termDays' => 1, 'openedBy' => ''],
			'offset start' => ['start' => '2026-03-02T23:30:00+02:00'],
			'publication id is trimmed' => ['publication' => ['id' => '  pub 2/x  ', 'title' => 'T']],
			'numeric publication id' => ['publication' => ['id' => 42]],
			'no publication id' => ['publication' => ['title' => 'T']],
			'blank publication id' => ['publication' => ['id' => '   ']],
			'unknown remedy' => ['legalRemedy' => 'beroep'],
			'remedy is case sensitive' => ['legalRemedy' => 'Bezwaar'],
			'no days' => ['termDays' => 0],
			'negative days' => ['termDays' => -3],
			'no announcement' => ['announcementUrl' => '   '],
			'http announcement' => ['announcementUrl' => 'http://officielebekendmakingen.nl/x'],
			'relative announcement' => ['announcementUrl' => '/stcrt-2026-1'],
			'internal announcement' => ['announcementUrl' => 'https://intranet.example.org/x'],
			'lookalike announcement host' => ['announcementUrl' => 'https://evilofficielebekendmakingen.nl/x'],
			'subdomain announcement' => ['announcementUrl' => ' https://zoek.officielebekendmakingen.nl/gmb-1 '],
			'uppercase announcement host' => ['announcementUrl' => 'HTTPS://OfficieleBekendmakingen.NL/x'],
			'invalid remedy before no days' => ['legalRemedy' => 'x', 'termDays' => 0],
			'no days before bad announcement' => ['termDays' => 0, 'announcementUrl' => ''],
			'no publication before bad remedy' => ['publication' => [], 'legalRemedy' => 'x'],
			'no engine' => ['engine' => false],
			'end not after start' => ['engine' => 'still'],
			'no engine but invalid input' => ['engine' => false, 'termDays' => 0],
			'configured form template' => ['template' => 'https://forms.example.org/{remedy}?id={id}'],
			'template without remedy placeholder' => ['template' => 'https://forms.example.org/form'],
		];

		$out = [];
		foreach ($cases as $name => $override) {
			$out[$name] = [array_merge($base, $override)];
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
	public function testOpenMatchesTheRecording(array $case): void {
		$name = $this->dataName();
		$actual = $this->outcome($case);

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

	}//end testOpenMatchesTheRecording()

	/**
	 * A period opened without a start starts now.
	 *
	 * @return void
	 */
	public function testAPeriodWithoutAStartStartsNow(): void {
		$before = new DateTimeImmutable('now');
		$period = $this->open(service: $this->service(case: ['engine' => true, 'template' => '']), case: [
			'publication' => ['id' => 'pub-1'],
			'termDays' => 3,
			'legalRemedy' => 'bezwaar',
			'announcementUrl' => 'https://officielebekendmakingen.nl/x',
			'automaticWithdrawal' => true,
			'openedBy' => 'bob',
			'start' => null,
		]);

		$start = new DateTimeImmutable($period['startDate']);
		$this->assertGreaterThanOrEqual($before->getTimestamp(), $start->getTimestamp());
		$this->assertSame('+00:00', $start->format('P'));
		$this->assertSame('bob', $period['openedBy']);

	}//end testAPeriodWithoutAStartStartsNow()

	/**
	 * Run one case: the period, or the refusal's class and message.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<string, mixed> The outcome.
	 */
	private function outcome(array $case): array {
		try {
			return ['period' => $this->open(service: $this->service(case: $case), case: $case)];
		} catch (\Throwable $e) {
			return ['refused' => get_class($e), 'message' => $e->getMessage()];
		}

	}//end outcome()

	/**
	 * Call open() with the case's arguments.
	 *
	 * @param CommentPeriodService $service The service.
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<string, mixed> The period.
	 */
	private function open(CommentPeriodService $service, array $case): array {
		$start = null;
		if ($case['start'] !== null) {
			$start = new DateTimeImmutable($case['start']);
		}

		return $service->open(
			request: new CommentPeriodRequest(
				publication: $case['publication'],
				termDays: $case['termDays'],
				legalRemedy: $case['legalRemedy'],
				announcementUrl: $case['announcementUrl'],
				automaticWithdrawal: $case['automaticWithdrawal'],
				openedBy: $case['openedBy']
			),
			start: $start
		);

	}//end open()

	/**
	 * Build the service for a case.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return CommentPeriodService The service.
	 */
	private function service(array $case): CommentPeriodService {
		$container = $this->createMock(ContainerInterface::class);
		$engine = ($case['engine'] ?? true);
		if ($engine !== false) {
			$container->method('get')->willReturnCallback(
				static function (string $id) use ($engine): object {
					if ($id === 'OCA\OpenRegister\Service\Flow\Timer\SlaCalculator') {
						return $engine === 'still' ? new GoldenStillCalculator() : new GoldenSlaCalculator();
					}

					return new GoldenCalendarService();
				}
			);
		} else {
			$container->method('get')->willThrowException(new \RuntimeException('not installed'));
		}

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn($case['template'] ?? '');

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(
			static fn (string $path): string => 'https://example.org' . $path
		);

		return new CommentPeriodService(new TermRoll($container, $appManager), $config, $urls);

	}//end service()
}//end class
