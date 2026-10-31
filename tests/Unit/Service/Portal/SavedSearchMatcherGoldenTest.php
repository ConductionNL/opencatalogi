<?php

/**
 * Golden test for SavedSearchMatcher::run.
 *
 * Every world is recorded once and replayed from the fixture: the run's
 * stats, the searches asked, the notices written and every save. A
 * `lastNotifiedAt` is a fresh stamp, so it is recorded by its shape.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Service\Portal;

require_once __DIR__.'/FakePortalObjectStore.php';

use DateTimeImmutable;
use OCA\OpenCatalogi\Service\Portal\PublicationLinker;
use OCA\OpenCatalogi\Service\Portal\PublicSearchRunner;
use OCA\OpenCatalogi\Service\Portal\SavedSearchMatcher;
use OCA\OpenCatalogi\Service\Portal\SavedSearchNoticeWriter;
use OCA\OpenCatalogi\Service\PublicationQueryService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Golden test for SavedSearchMatcher.
 *
 * @covers \OCA\OpenCatalogi\Service\Portal\SavedSearchMatcher
 * @covers \OCA\OpenCatalogi\Service\Portal\SearchWindow
 * @uses \OCA\OpenCatalogi\Service\PublicationQueryService
 * @uses \OCA\OpenCatalogi\Service\SearchQueryTranslator
 */
class SavedSearchMatcherGoldenTest extends TestCase {

	private const FIXTURE = __DIR__.'/fixtures/saved-search-matcher-golden.json';

	/**
	 * What the doubles saw during one run.
	 *
	 * @var array<string, list<mixed>>
	 */
	private array $log = [];

	/**
	 * A saved search.
	 *
	 * @param string               $id        The id.
	 * @param string|null          $frequency The frequency, or null for none.
	 * @param string|null          $lastRunAt The last run, or null for none.
	 * @param array<string, mixed> $filters   The query filters.
	 *
	 * @return array<string, mixed>
	 */
	private static function saved(string $id, ?string $frequency, ?string $lastRunAt, array $filters=[]): array {
		return array_filter([
			'id' => $id,
			'title' => 'Zoekopdracht '.$id,
			'owner' => 'subject-1',
			'query' => ['text' => 'windpark', 'filters' => $filters, 'catalog' => ''],
			'frequency' => $frequency,
			'lastRunAt' => $lastRunAt,
		], static fn ($value): bool => $value !== null);
	}

	/**
	 * A publication row as the search returns it.
	 *
	 * @param string               $id    The id.
	 * @param string|null          $date  The publication date.
	 * @param array<string, mixed> $extra Extra fields.
	 *
	 * @return array<string, mixed>
	 */
	private static function row(string $id, ?string $date, array $extra=[]): array {
		return array_merge(['title' => 'Publicatie '.$id, 'publicationDate' => $date, '@self' => ['id' => $id, 'schema' => 'publication']], $extra);
	}

	/**
	 * The recorded worlds: saved searches, search rows, the run moment, and
	 * whether the notice writer fails.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function worlds(): array {
		$mixed = [
			self::row('late', '2026-09-30T04:00:00+00:00'),
			self::row('p1', '2026-09-29T09:00:00+00:00'),
			self::row('edge-from', '2026-09-29T05:05:00+00:00'),
			self::row('edge-until', '2026-09-30T05:30:00+00:00'),
			self::row('after', '2026-09-30T05:30:01+00:00'),
			self::row('before', '2026-09-28T09:00:00+00:00'),
			self::row('gone', '2026-09-29T10:00:00+00:00', ['depublicationDate' => '2026-09-29T11:00:00+00:00']),
			self::row('doc', '2026-09-29T10:00:00+00:00', ['@self' => ['id' => 'doc', 'schema' => 'document']]),
			self::row('noid', '2026-09-29T10:00:00+00:00', ['@self' => ['schema' => 'publication']]),
			['id' => 'plain-id', 'title' => 'Plain', 'publicationDate' => '2026-09-29T11:00:00+00:00', '@self' => ['schema' => 'publication']],
			self::row('nodate', null),
			self::row('baddate', 'not a date'),
			self::row('p1', '2026-09-29T08:00:00+00:00', ['title' => str_repeat('é', 600)]),
			self::row('local', '2026-09-29T12:00:00+02:00'),
		];

		$many = [];
		for ($i = 1; $i <= 25; $i++) {
			$many[] = self::row(sprintf('m%02d', $i), sprintf('2026-09-29T%02d:00:00+00:00', (30 - $i) % 24));
		}

		return [
			'daily due, mixed rows' => [['saved' => [self::saved('s1', 'daily', '2026-09-29T07:05:00+02:00')], 'rows' => $mixed, 'now' => '2026-09-30T07:30:00+02:00']],
			'daily not due before seven' => [['saved' => [self::saved('s1', 'daily', '2026-09-29T07:05:00+02:00')], 'rows' => $mixed, 'now' => '2026-09-30T06:59:00+02:00']],
			'daily already ran today' => [['saved' => [self::saved('s1', 'daily', '2026-09-30T07:01:00+02:00')], 'rows' => $mixed, 'now' => '2026-09-30T09:00:00+02:00']],
			'no frequency means daily' => [['saved' => [self::saved('s1', null, '2026-09-29T07:05:00+02:00')], 'rows' => $mixed, 'now' => '2026-09-30T07:30:00+02:00']],
			'weekly due on monday' => [['saved' => [self::saved('w', 'weekly', '2026-09-22T08:00:00+02:00')], 'rows' => $mixed, 'now' => '2026-09-30T07:30:00+02:00']],
			'weekly not due' => [['saved' => [self::saved('w', 'weekly', '2026-09-29T08:00:00+02:00')], 'rows' => $mixed, 'now' => '2026-09-30T07:30:00+02:00']],
			'immediate, one notice each' => [['saved' => [self::saved('i', 'immediate', '2026-09-29T07:05:00+02:00')], 'rows' => $mixed, 'now' => '2026-09-30T07:30:00+02:00']],
			'digest of twenty-five' => [['saved' => [self::saved('d', 'daily', '2026-09-28T07:05:00+02:00')], 'rows' => $many, 'now' => '2026-09-30T07:30:00+02:00']],
			'immediate of twenty-five' => [['saved' => [self::saved('i', 'immediate', '2026-09-28T07:05:00+02:00')], 'rows' => $many, 'now' => '2026-09-30T07:30:00+02:00']],
			'nothing found' => [['saved' => [self::saved('s1', 'immediate', '2026-09-29T07:05:00+02:00')], 'rows' => [], 'now' => '2026-09-30T07:30:00+02:00']],
			'saved period narrows' => [[
				'saved' => [self::saved('s1', 'daily', '2026-09-28T07:05:00+02:00', ['periodFrom' => '2026-09-29T08:30:00+00:00', 'periodTo' => '2026-09-29'])],
				'rows' => $mixed,
				'now' => '2026-09-30T07:30:00+02:00',
			]],
			'saved period boundaries' => [[
				'saved' => [self::saved('s1', 'immediate', '2026-09-28T07:05:00+02:00', ['periodFrom' => '2026-09-29T08:30:00+00:00', 'periodTo' => '2026-09-29T00:00:00+00:00'])],
				'rows' => [
					self::row('at-from', '2026-09-29T08:30:00+00:00'),
					self::row('before-from', '2026-09-29T08:29:59+00:00'),
					self::row('at-to', '2026-09-29T23:59:59+00:00'),
					self::row('after-to', '2026-09-30T00:00:00+00:00'),
				],
				'now' => '2026-09-30T07:30:00+02:00',
			]],
			'saved period with bad dates' => [[
				'saved' => [self::saved('s1', 'daily', '2026-09-28T07:05:00+02:00', ['periodFrom' => 'soon', 'periodTo' => '  '])],
				'rows' => $mixed,
				'now' => '2026-09-30T07:30:00+02:00',
			]],
			'baseline, inactive, no id, bad last run' => [[
				'saved' => [
					self::saved('new', 'daily', null),
					array_merge(self::saved('off', 'immediate', '2026-09-29T07:05:00+02:00'), ['active' => false]),
					array_merge(self::saved('x', 'immediate', '2026-09-29T07:05:00+02:00'), ['id' => 42]),
					self::saved('bad', 'immediate', 'yesterday-ish'),
					self::saved('ok', 'immediate', '2026-09-29T07:05:00+02:00'),
				],
				'rows' => [self::row('p1', '2026-09-29T09:00:00+00:00')],
				'now' => '2026-09-30T07:30:00+02:00',
			]],
			'notice writer fails' => [[
				'saved' => [self::saved('s1', 'immediate', '2026-09-29T07:05:00+02:00'), self::saved('s2', 'daily', '2026-09-29T07:05:00+02:00')],
				'rows' => $mixed,
				'now' => '2026-09-30T07:30:00+02:00',
				'failNotices' => true,
			]],
		];
	}

	/**
	 * Replay a world and compare it to the recording.
	 *
	 * @param array<string, mixed> $world The world.
	 *
	 * @return void
	 */
	#[DataProvider('worlds')]
	public function testRunMatchesTheRecording(array $world): void {
		$this->log = ['searches' => [], 'notices' => []];
		$store = new FakePortalObjectStore();
		foreach ($world['saved'] as $index => $saved) {
			$store->objects['savedSearch']['k'.$index] = $saved;
		}

		$matcher = new SavedSearchMatcher(
			store: $store,
			search: $this->search($world['rows']),
			linker: $this->linker(),
			publications: new PublicationQueryService(container: $this->createMock(ContainerInterface::class)),
			notices: $this->notices(($world['failNotices'] ?? false)),
			logger: new NullLogger()
		);

		$stats = $matcher->run(new DateTimeImmutable($world['now']));
		$saves = [];
		foreach ($store->saves as $save) {
			if (isset($save['data']['lastNotifiedAt']) === true) {
				$this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{6}\+00:00$/', $save['data']['lastNotifiedAt']);
				$save['data']['lastNotifiedAt'] = '<stamp>';
			}

			$saves[] = $save;
		}

		$actual = ['stats' => $stats, 'searches' => $this->log['searches'], 'notices' => $this->log['notices'], 'saves' => $saves];

		$recorded = [];
		if (file_exists(self::FIXTURE) === true) {
			$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		$name = (string)$this->dataName();
		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$name] = $actual;
			if (is_dir(dirname(self::FIXTURE)) === false) {
				mkdir(dirname(self::FIXTURE));
			}

			file_put_contents(self::FIXTURE, json_encode($recorded, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))."\n");
		}

		$this->assertArrayHasKey($name, $recorded, 'No recording for '.$name);
		$this->assertSame($recorded[$name], json_decode((string)json_encode($actual), true));
	}

	/**
	 * Two notices of one run never share a stamp, and stamps only grow.
	 *
	 * @return void
	 */
	public function testStampsOfOneRunAreDistinctAndGrow(): void {
		$store = new FakePortalObjectStore();
		$store->objects['savedSearch']['k'] = self::saved('i', 'immediate', '2026-09-28T07:05:00+02:00');
		$matcher = new SavedSearchMatcher(
			store: $store,
			search: $this->search([self::row('a', '2026-09-29T01:00:00+00:00'), self::row('b', '2026-09-29T02:00:00+00:00'), self::row('c', '2026-09-29T03:00:00+00:00')]),
			linker: $this->linker(),
			publications: new PublicationQueryService(container: $this->createMock(ContainerInterface::class)),
			notices: $this->notices(false),
			logger: new NullLogger()
		);
		$matcher->run(new DateTimeImmutable('2026-09-30T07:30:00+02:00'));

		$stamps = array_map(static fn (array $save): string => $save['data']['lastNotifiedAt'], $store->saves);
		$this->assertCount(3, $stamps);
		$this->assertSame($stamps, array_values(array_unique($stamps)));
		$sorted = $stamps;
		sort($sorted);
		$this->assertSame($sorted, $stamps);
	}

	/**
	 * A search that records what it was asked and answers with the rows.
	 *
	 * @param array<int, array<string, mixed>> $rows The rows.
	 *
	 * @return PublicSearchRunner
	 */
	private function search(array $rows): PublicSearchRunner {
		$search = $this->createMock(PublicSearchRunner::class);
		$search->method('search')->willReturnCallback(function (array $params) use ($rows): array {
			$this->log['searches'][] = $params;
			return $rows;
		});
		return $search;
	}

	/**
	 * A linker with a fixed base.
	 *
	 * @return PublicationLinker
	 */
	private function linker(): PublicationLinker {
		$linker = $this->createMock(PublicationLinker::class);
		$linker->method('url')->willReturnCallback(static fn (string $id): string => 'https://example.org/p/'.$id);
		return $linker;
	}

	/**
	 * A notice writer that records each notice, or fails on every one.
	 *
	 * @param bool $fail Whether every write fails.
	 *
	 * @return SavedSearchNoticeWriter
	 */
	private function notices(bool $fail): SavedSearchNoticeWriter {
		$notices = $this->createMock(SavedSearchNoticeWriter::class);
		$notices->method('write')->willReturnCallback(function (array $saved, array $batch, int $count) use ($fail): bool {
			if ($fail === true) {
				throw new RuntimeException('inbox unreachable');
			}

			$this->log['notices'][] = ['saved' => $saved['id'], 'lastRunAt' => $saved['lastRunAt'], 'batch' => $batch, 'count' => $count];
			return true;
		});
		return $notices;
	}
}
