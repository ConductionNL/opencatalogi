<?php

/**
 * Golden test for ConnectionObservations.
 *
 * Every case asks one observation for one input and compares the state and the
 * message to the recording in fixtures/connection-observations-golden.json.
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

namespace Unit\Service\Connection;

use OCA\OpenCatalogi\Service\Connection\ConnectionObservations;
use PHPUnit\Framework\TestCase;

/**
 * Golden test for the connection observations.
 *
 * @covers \OCA\OpenCatalogi\Service\Connection\ConnectionObservations
 */
class ConnectionObservationsGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/connection-observations-golden.json';

	/**
	 * Every observation over its recorded inputs matches the recording.
	 *
	 * @return void
	 */
	public function testObservationsMatchTheRecording(): void {
		$observations = new ConnectionObservations();
		$sync = [
			'none' => [],
			'one ok' => ['total_directories' => 1, 'synced_directories' => 1],
			'three ok' => ['total_directories' => 3, 'synced_directories' => 3, 'failed_directories' => 0],
			'all failed' => ['total_directories' => 2, 'failed_directories' => 2, 'errors' => [['directory' => 'https://dir.example.org/api']]],
			'some failed' => ['total_directories' => 3, 'failed_directories' => 1, 'synced_directories' => 2, 'errors' => ['k' => ['directory' => ' https://b.example.org ']]],
			'failed without errors' => ['total_directories' => '2', 'failed_directories' => '1', 'synced_directories' => '1'],
			'failed with a bad url' => ['total_directories' => 1, 'failed_directories' => 1, 'errors' => [['directory' => 'not a url']]],
		];
		$broadcast = [
			'none' => [],
			'one ok' => ['https://peer.example.org/x' => true],
			'one ok, no host' => ['peer' => true],
			'two ok' => ['https://a.example.org' => true, 'https://b.example.org' => true],
			'one refused' => ['https://a.example.org' => false],
			'two refused' => ['https://a.example.org' => 'no', 'https://b.example.org' => false],
			'one of three refused' => ['https://a.example.org' => true, 'https://b.example.org' => 1, 'https://c.example.org' => true],
		];
		$readiness = [
			'empty' => [],
			'checks not a list' => ['checks' => 'x'],
			'all failed' => ['checks' => [['id' => 'a', 'status' => 'fail'], ['id' => 'b', 'status' => 'fail']]],
			'some failed' => ['checks' => [['id' => 'a', 'status' => 'pass'], ['id' => 'b', 'status' => 'fail'], ['id' => 'c', 'status' => 'skipped'], 'junk']],
			'registered' => ['checks' => [['id' => 'a', 'status' => 'pass'], ['id' => 'registration', 'status' => 'pass']]],
			'registration failing does not count' => ['checks' => [['id' => 'registration', 'status' => 'fail', 'reason' => 'url-mismatch']]],
			'pending' => ['checks' => [['id' => 'registration', 'status' => 'skipped', 'reason' => 'registration-pending']]],
			'not registered' => ['checks' => [['id' => 'registration', 'status' => 'skipped', 'reason' => 'not-registered']]],
			'no registration check' => ['checks' => [['id' => 'a', 'status' => 'pass']]],
		];

		$actual = ['directorySync' => [], 'broadcast' => [], 'wooReadiness' => []];
		foreach ($sync as $label => $input) {
			$actual['directorySync'][$label] = $observations->directorySync($input);
		}

		foreach ($broadcast as $label => $input) {
			$actual['broadcast'][$label] = $observations->broadcast($input);
		}

		foreach ($readiness as $label => $input) {
			$actual['wooReadiness'][$label] = $observations->wooReadiness($input);
		}

		$actual['fixed'] = [$observations->broadcastFromLocalAddress(), $observations->wooNotConfigured(), $observations->wooCheckStopped()];

		if (getenv('GOLDEN_RECORD') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertSame(json_decode((string)file_get_contents(self::FIXTURE), true), $actual);

	}//end testObservationsMatchTheRecording()
}//end class
