<?php

/**
 * Golden test for CaseTypeCatalogueService::diffAgainstSource and ::applyResync.
 *
 * Every case is recorded once and replayed from the fixture: the diff, or the
 * definition to save after a resynchronisation, including its key order.
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

use DateTimeImmutable;
use OCA\OpenCatalogi\Service\CaseTypeCatalogueService;
use OCA\OpenCatalogi\Service\Catalogue\CaseTypeSourceReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Golden test for the case type resynchronisation.
 *
 * @covers \OCA\OpenCatalogi\Service\CaseTypeCatalogueService
 */
class CaseTypeResyncServiceGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/case-type-resync-service-golden.json';

	/**
	 * A local definition, imported and then edited.
	 *
	 * @return array<string, mixed>
	 */
	private static function local(): array {
		return [
			'identifier' => 'vergunning',
			'title' => 'Vergunning (lokaal)',
			'description' => 'Oud',
			'inspectionTermDays' => 42,
			'own' => 'local field',
			'source' => ['sourceId' => 'vng', 'externalId' => 'ext-1', 'version' => '1.0', 'importedAt' => '2026-01-01T00:00:00+00:00'],
			'importedSnapshot' => ['identifier' => 'vergunning', 'title' => 'Vergunning', 'description' => 'Oud', 'inspectionTermDays' => '42'],
		];
	}

	/**
	 * The diff cases: the local definition and what the source answers.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
	 */
	public static function diffCases(): array {
		$remote = [
			'identifier' => 'vergunning',
			'title' => 'Vergunning',
			'description' => 'Nieuw',
			'publicationText' => 'Tekst',
			'inspectionTermDays' => 42,
			'version' => 2,
			'other' => 'ignored',
		];

		return [
			'mixed changes' => [self::local(), $remote],
			'identical' => [['identifier' => 'a', 'importedSnapshot' => ['identifier' => 'a']], ['identifier' => 'a']],
			'no snapshot' => [['identifier' => 'a', 'title' => 't'], ['identifier' => 'b']],
			'snapshot not a list' => [['identifier' => 'a', 'importedSnapshot' => 'x'], ['identifier' => 'a']],
			'source empty' => [self::local(), []],
			'local empty' => [[], $remote],
			'null versus absent' => [['title' => null, 'importedSnapshot' => ['title' => null]], ['title' => null, 'version' => null]],
			'loose equal is a change' => [['inspectionTermDays' => '42', 'importedSnapshot' => ['inspectionTermDays' => '42']], ['inspectionTermDays' => 42]],
		];
	}

	/**
	 * The apply cases: the local definition, the diff, what was accepted, and when.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<int, string>}>
	 */
	public static function applyCases(): array {
		$diff = [
			'sourceVersion' => '2.0',
			'changes' => [
				['property' => 'title', 'localValue' => 'Vergunning (lokaal)', 'sourceValue' => 'Vergunning', 'locallyChanged' => true],
				['property' => 'description', 'localValue' => 'Oud', 'sourceValue' => 'Nieuw', 'locallyChanged' => false],
				['property' => 'publicationText', 'localValue' => null, 'sourceValue' => 'Tekst', 'locallyChanged' => false],
			],
			'locallyChanged' => ['title'],
		];

		return [
			'accept one' => [self::local(), $diff, ['description']],
			'accept all' => [self::local(), $diff, ['title', 'description', 'publicationText']],
			'accept none' => [self::local(), $diff, []],
			'accept unknown only' => [self::local(), $diff, ['own']],
			'accept one, no snapshot or source' => [['title' => 'x'], $diff, ['title']],
			'snapshot and source not lists' => [['title' => 'x', 'importedSnapshot' => 'y', 'source' => 'z'], $diff, ['title']],
			'no source version in diff' => [self::local(), ['changes' => $diff['changes']], ['title']],
			'no source version anywhere' => [['title' => 'x'], ['changes' => $diff['changes']], ['title']],
			'no changes key' => [self::local(), ['sourceVersion' => '3'], ['title']],
			'change without property' => [self::local(), ['changes' => [['sourceValue' => 'v'], ['property' => '', 'sourceValue' => 'w']]], ['']],
			'duplicate change, last wins' => [self::local(), ['changes' => [['property' => 'title', 'sourceValue' => 'a'], ['property' => 'title', 'sourceValue' => 'b']]], ['title']],
			'numeric property' => [self::local(), ['changes' => [['property' => 3, 'sourceValue' => 'drie']]], ['3']],
			'accepted is strict' => [self::local(), ['changes' => [['property' => '3', 'sourceValue' => 'drie']]], [3]],
			'source value null' => [self::local(), ['changes' => [['property' => 'title', 'sourceValue' => null]]], ['title']],
			'change to the snapshot itself' => [self::local(), ['changes' => [['property' => 'importedSnapshot', 'sourceValue' => 'flat']]], ['importedSnapshot']],
		];
	}

	/**
	 * Replay a diff and compare it to the recording.
	 *
	 * @param array<string, mixed> $local  The local definition.
	 * @param array<string, mixed> $remote What the source answers.
	 *
	 * @return void
	 */
	#[DataProvider('diffCases')]
	public function testDiffMatchesTheRecording(array $local, array $remote): void {
		$reader = $this->createMock(CaseTypeSourceReader::class);
		$reader->expects($this->once())->method('fetchDefinition')->with('vng', 'ext-1')->willReturn($remote);

		$diff = (new CaseTypeCatalogueService($reader))->diffAgainstSource($local, 'vng', 'ext-1');
		$this->assertRecorded('diff, ' . $this->dataName(), ['result' => $diff]);
	}

	/**
	 * Replay a resynchronisation and compare it to the recording.
	 *
	 * @param array<string, mixed> $local    The local definition.
	 * @param array<string, mixed> $diff     The diff.
	 * @param array<int, mixed>    $accepted What was accepted.
	 *
	 * @return void
	 */
	#[DataProvider('applyCases')]
	public function testApplyMatchesTheRecording(array $local, array $diff, array $accepted): void {
		$service = new CaseTypeCatalogueService($this->createMock(CaseTypeSourceReader::class));
		$result = $service->applyResync($local, $diff, $accepted, new DateTimeImmutable('2026-09-18T12:00:00+02:00'));
		$this->assertRecorded('apply, ' . $this->dataName(), ['result' => $result, 'keys' => array_keys($result)]);
	}

	/**
	 * Without a moment, a resynchronisation is stamped now, in UTC.
	 *
	 * @return void
	 */
	public function testApplyWithoutAMomentStampsNowInUtc(): void {
		$service = new CaseTypeCatalogueService($this->createMock(CaseTypeSourceReader::class));
		$before = time();
		$result = $service->applyResync(['title' => 'x'], ['changes' => [['property' => 'title', 'sourceValue' => 'y']]], ['title']);
		$at = new DateTimeImmutable($result['source']['importedAt']);
		$this->assertStringEndsWith('+00:00', $result['source']['importedAt']);
		$this->assertGreaterThanOrEqual($before, $at->getTimestamp());
		$this->assertLessThanOrEqual(time(), $at->getTimestamp());
	}

	/**
	 * Compare an outcome to its recording, or record it.
	 *
	 * @param string               $name   The case name.
	 * @param array<string, mixed> $actual The outcome.
	 *
	 * @return void
	 */
	private function assertRecorded(string $name, array $actual): void {
		$recorded = [];
		if (file_exists(self::FIXTURE) === true) {
			$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$name] = $actual;
			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $recorded, 'No recording for ' . $name);
		$this->assertSame($recorded[$name], json_decode((string)json_encode($actual), true));
	}
}
