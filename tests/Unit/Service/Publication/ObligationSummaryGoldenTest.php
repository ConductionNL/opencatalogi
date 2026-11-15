<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for the summary ObligationReadService::read() adds to the
 * assembled overview: due this week (up to and including seven days from
 * now), published this month (from the first of the month), the per-source
 * rows with their outstanding, late and next due date, and the order late,
 * due, unknown, published, then by due date. The moment is fixed.
 *
 * Re-record (only when behaviour is meant to change) with
 * `GOLDEN_RECORD=1 vendor/bin/phpunit -c phpunit-unit.xml tests/Unit/Service/Publication/ObligationSummaryGoldenTest.php`.
 */

declare(strict_types=1);

namespace Unit\Service\Publication;

use DateTimeImmutable;
use OCA\OpenCatalogi\Event\ObligationsRequestedEvent;
use OCA\OpenCatalogi\Service\Publication\ObligationOverviewService;
use OCA\OpenCatalogi\Service\Publication\ObligationReadService;
use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Golden test for the obligation overview's summary.
 */
class ObligationSummaryGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/obligation-summary-golden.json';

	/**
	 * The sources and what each answers.
	 *
	 * @return array<string, array{0: array<int, array<string, mixed>>, 1: array<string, list<array<string, mixed>>>}>
	 */
	public static function cases(): array {
		$sources = [
			['appId' => 'dossiq', 'title' => 'Woo-besluiten'],
			['appId' => 'decos', 'enabled' => true],
			['appId' => 'retired', 'title' => 'Uit', 'enabled' => false],
		];

		return [
			'week and month edges' => [
				$sources,
				[
					'dossiq' => [
						['title' => 'due at the week end', 'dueDate' => '2026-10-15T09:00:00+00:00', 'publishedAt' => ''],
						['title' => 'due just after', 'dueDate' => '2026-10-15T09:00:01+00:00', 'publishedAt' => ''],
						['title' => 'late', 'dueDate' => '2026-10-01T00:00:00+00:00', 'publishedAt' => ''],
						['title' => 'published at the month start', 'dueDate' => '2026-10-01T00:00:00+00:00', 'publishedAt' => '2026-10-01T00:00:00+00:00'],
						['title' => 'published last month', 'dueDate' => '2026-09-01T00:00:00+00:00', 'publishedAt' => '2026-09-30T23:59:59+00:00'],
					],
					'decos' => [
						['title' => 'no due date', 'dueDate' => '', 'publishedAt' => ''],
						['title' => 'unreadable due date', 'dueDate' => 'soon', 'publishedAt' => ''],
						['title' => 'later', 'dueDate' => '2026-11-20T00:00:00+00:00', 'publishedAt' => ''],
						['title' => 'sooner', 'dueDate' => '2026-10-20T00:00:00+00:00', 'publishedAt' => ''],
						['title' => 'unreadable publication', 'dueDate' => '2026-10-02T00:00:00+00:00', 'publishedAt' => 'yesterday-ish'],
					],
				],
			],
			'nothing outstanding' => [
				$sources,
				[
					'dossiq' => [
						['title' => 'published', 'dueDate' => '2026-10-01T00:00:00+00:00', 'publishedAt' => '2026-10-03T00:00:00+00:00'],
					],
					'decos' => [],
				],
			],
			'no sources' => [[], []],
		];

	}//end cases()

	/**
	 * Read the overview and compare it with the recording.
	 *
	 * @param array<int, array<string, mixed>> $sources The registered sources.
	 * @param array<string, list<array<string, mixed>>> $answers What each source answers.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testTheSummaryMatchesTheRecording(array $sources, array $answers): void {
		$name = $this->dataName();
		$actual = $this->service(sources: $sources, answers: $answers)->read(now: new DateTimeImmutable('2026-10-08T09:00:00+00:00'));

		$recorded = [];
		if (is_file(self::FIXTURE) === true) {
			$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$name] = $actual;
			ksort($recorded);
			if (is_dir(dirname(self::FIXTURE)) === false) {
				mkdir(dirname(self::FIXTURE), 0777, true);
			}

			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $recorded, 'No recording for this case.');
		$this->assertSame(
			json_encode($recorded[$name], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
			json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
		);

	}//end testTheSummaryMatchesTheRecording()

	/**
	 * The service over the given sources and answers.
	 *
	 * @param array<int, array<string, mixed>> $sources The registered sources.
	 * @param array<string, list<array<string, mixed>>> $answers What each source answers.
	 *
	 * @return ObligationReadService
	 */
	private function service(array $sources, array $answers): ObligationReadService {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('7');

		$objectService = new class($sources) {
			/**
			 * @param array<int, array<string, mixed>> $sources The sources.
			 */
			public function __construct(
				private array $sources,
			) {
			}

			/**
			 * @param array<string, mixed> $query The query.
			 * @param bool $_rbac Whether RBAC applies.
			 * @param bool $_multitenancy Whether multitenancy applies.
			 *
			 * @return array<string, mixed>
			 */
			public function searchObjectsPaginated(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
				return ['results' => $this->sources];
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (ObligationsRequestedEvent $event) use ($answers): void {
				if (isset($answers[$event->getAppId()]) === true) {
					$event->setObligations($answers[$event->getAppId()]);
				}
			}
		);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);

		return new ObligationReadService($dispatcher, new ObligationOverviewService(), $config, $container, $appManager);
	}//end service()
}//end class
