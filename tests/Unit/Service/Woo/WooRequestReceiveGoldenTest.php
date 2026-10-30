<?php

/**
 * Golden test for WooRequestService::receive.
 *
 * Every case is recorded once and replayed from the fixture: the record, or
 * the refusal and its message. The minted reference is random, so it is
 * recorded by its shape.
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

namespace Unit\Service\Woo;

use DateTimeImmutable;
use OCA\OpenCatalogi\Service\Woo\WooRequestService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Golden test for WooRequestService::receive.
 *
 * @covers \OCA\OpenCatalogi\Service\Woo\WooRequestService
 */
class WooRequestReceiveGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/woo-request-receive-golden.json';

	/**
	 * The recorded cases: input, who received it, and when.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string, 2: string|null}>
	 */
	public static function cases(): array {
		$full = [
			'requestedInformation' => '  Alle besluiten over de brug  ',
			'requesterName' => ' Jan ',
			'requesterEmail' => ' jan@example.org ',
			'requesterPhone' => ' 0612345678 ',
			'requesterAddress' => ' Straat 1 ',
			'channel' => ' email ',
			'status' => 'decided',
			'extra' => 'ignored',
		];

		return [
			'full input' => [$full, 'officer', '2026-03-02T10:00:00+01:00'],
			'minimal input, default channel' => [['requestedInformation' => 'x'], '', '2026-12-31T23:59:59+00:00'],
			'every channel: post' => [['requestedInformation' => 'x', 'channel' => 'post'], 'a', '2026-01-01T00:00:00+00:00'],
			'every channel: counter' => [['requestedInformation' => 'x', 'channel' => 'counter'], 'a', '2026-01-01T00:00:00+00:00'],
			'every channel: phone' => [['requestedInformation' => 'x', 'channel' => 'phone'], 'a', '2026-01-01T00:00:00+00:00'],
			'every channel: web' => [['requestedInformation' => 'x', 'channel' => 'web'], 'a', '2026-01-01T00:00:00+00:00'],
			'unknown channel' => [['requestedInformation' => 'x', 'channel' => 'fax'], 'a', null],
			'channel case matters' => [['requestedInformation' => 'x', 'channel' => 'Email'], 'a', null],
			'blank channel' => [['requestedInformation' => 'x', 'channel' => '  '], 'a', null],
			'nothing asked' => [['requestedInformation' => '   ', 'channel' => 'fax'], 'a', null],
			'no question at all' => [[], 'a', null],
			'numbers as strings' => [['requestedInformation' => 42, 'requesterPhone' => 612345678], 'a', '2026-06-30T12:00:00-05:00'],
		];

	}//end cases()

	/**
	 * Replay a case and compare it to the recording.
	 *
	 * @param array<string, mixed> $input      The intake input.
	 * @param string               $receivedBy Who received it.
	 * @param string|null          $now        When, or null for now.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testReceiveMatchesTheRecording(array $input, string $receivedBy, ?string $now): void {
		$name = $this->dataName();
		try {
			$moment = ($now === null ? null : new DateTimeImmutable($now));
			$record = (new WooRequestService())->receive($input, $receivedBy, $moment);
			$this->assertMatchesRegularExpression('/^WOO-\d{4}-[0-9A-F]{6}$/', $record['reference']);
			$record['reference'] = substr($record['reference'], 0, 9) . '<random>';
			$actual = ['record' => $record, 'keys' => array_keys($record)];
		} catch (\Throwable $e) {
			$actual = ['refused' => get_class($e), 'message' => $e->getMessage()];
		}

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

	}//end testReceiveMatchesTheRecording()

	/**
	 * Without a moment, a request is received now, in UTC.
	 *
	 * @return void
	 */
	public function testWithoutAMomentTheRequestIsReceivedNow(): void {
		$before = time();
		$record = (new WooRequestService())->receive(['requestedInformation' => 'x'], 'a');
		$at = new DateTimeImmutable($record['receivedAt']);

		$this->assertGreaterThanOrEqual($before, $at->getTimestamp());
		$this->assertSame('+00:00', $at->format('P'));
		$this->assertSame('WOO-' . $at->format('Y') . '-', substr($record['reference'], 0, 9));

	}//end testWithoutAMomentTheRequestIsReceivedNow()
}//end class
