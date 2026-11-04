<?php

/**
 * Golden test for the public status page rendering.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Service\Community
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#requirement-a-public-status-page-says-what-is-running-req-pcs-101
 */

declare(strict_types=1);

namespace Unit\Service\Community;

use DateTimeImmutable;
use OCA\OpenCatalogi\Service\Community\StatusPageService;
use PHPUnit\Framework\TestCase;

/**
 * Components of every shape, rendered at a fixed moment with three staleness windows, match the recording.
 *
 * @covers \OCA\OpenCatalogi\Service\Community\StatusPageService
 */
class StatusPageRenderGoldenTest extends TestCase {

	/**
	 * Every component shape and window gives the recorded page.
	 *
	 * @return void
	 */
	public function testTheRenderedPageMatchesTheRecording(): void {
		$components = [
			'not a component',
			['component' => 'Zoeken', 'state' => 'up', 'message' => 'ok', 'stateSetAt' => '2026-11-03T09:00:00+00:00', 'order' => 2],
			['component' => 'Woo-index', 'state' => 'degraded', 'stateSetAt' => '2026-11-01T12:00:00+01:00', 'order' => 1],
			['component' => 'Feed', 'state' => 'down', 'stateSetAt' => '  ', 'order' => '3'],
			['component' => 'Kaart', 'stateSetAt' => 'gisteren'],
			['component' => 'Edge', 'state' => 'up', 'stateSetAt' => '2026-11-02T10:00:00+00:00', 'order' => 1],
			['component' => 'Future', 'state' => 'up', 'stateSetAt' => '2026-11-04T10:00:00+00:00', 'order' => -1],
			['state' => 'up', 'message' => 7, 'order' => 'x'],
		];
		$service = new StatusPageService();
		$now = new DateTimeImmutable('2026-11-03T10:00:00+00:00');
		$actual = [
			'24h' => $service->render($components, 24, $now),
			'48h' => $service->render($components, 48, $now),
			'0h' => $service->render($components, 0, $now),
			'none' => $service->render([], 24, $now),
		];

		$fixture = __DIR__ . '/../fixtures/status-page-render-golden.json';
		$actual = json_decode((string)json_encode($actual), true);
		if (getenv('GOLDEN_RECORD') === '1') {
			file_put_contents($fixture, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertSame(json_decode((string)file_get_contents($fixture), true), $actual);
	}//end testTheRenderedPageMatchesTheRecording()
}//end class
