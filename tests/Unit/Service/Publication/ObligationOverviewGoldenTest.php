<?php

/**
 * Golden test for the publication obligation overview.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Service\Publication
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-one-overview-of-what-must-be-published-fed-from-every-source-req-pin-110
 */

declare(strict_types=1);

namespace Unit\Service\Publication;

use DateTimeImmutable;
use OCA\OpenCatalogi\Service\Publication\ObligationOverviewService;
use PHPUnit\Framework\TestCase;

/**
 * Sources of every kind and obligations of every state, at a fixed moment, match the recording.
 *
 * @covers \OCA\OpenCatalogi\Service\Publication\ObligationOverviewService
 */
class ObligationOverviewGoldenTest extends TestCase {

	/**
	 * The overview matches the recording.
	 *
	 * @return void
	 */
	public function testTheOverviewMatchesTheRecording(): void {
		$sources = [
			['appId' => 'filinq'],
			['appId' => 'dossiq', 'enabled' => true],
			['appId' => 'off', 'enabled' => false],
			['appId' => 'broken'],
			['appId' => 'silent'],
			['enabled' => 'yes'],
			['appId' => 'zero', 'enabled' => 0],
			['appId' => 'scalar'],
		];
		$answers = [
			'filinq' => [
				['id' => 'a', 'dueDate' => '2026-01-01', 'publishedAt' => '2025-12-30'],
				['id' => 'b', 'dueDate' => '2026-01-01'],
				['id' => 'c', 'dueDate' => '2027-01-01'],
				['id' => 'c2', 'dueDate' => '2027-02-01'],
				['id' => 'd', 'dueDate' => 'morgen'],
				'not an obligation',
			],
			'dossiq' => [],
			'broken' => new \RuntimeException('dossiq is down'),
			'' => [['id' => 'e', 'dueDate' => '2026-06-01', 'publishedAt' => '2026-07-01']],
			'zero' => [['id' => 'f']],
			'scalar' => 'not a list',
		];
		$service = new ObligationOverviewService();
		$actual = [
			'full' => $service->assemble($sources, $answers, new DateTimeImmutable('2026-11-03T10:00:00+00:00')),
			'empty' => $service->assemble([], [], new DateTimeImmutable('2026-11-03T10:00:00+00:00')),
		];

		$fixture = __DIR__ . '/../fixtures/obligation-overview-golden.json';
		$actual = json_decode((string)json_encode($actual), true);
		if (getenv('GOLDEN_RECORD') === '1') {
			file_put_contents($fixture, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertSame(json_decode((string)file_get_contents($fixture), true), $actual);
	}//end testTheOverviewMatchesTheRecording()
}//end class
