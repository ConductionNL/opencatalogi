<?php

/**
 * Golden test for every outcome WooRequestIntake::receive() answers with.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Service\Woo
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
 * @spec openspec/changes/portal-woo-request-intake/specs/woo-request-intake/spec.md#requirement-a-woo-request-delivered-by-the-portal-arms-its-term-req-wri-008
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Service\Woo;

use OCA\OpenCatalogi\Service\Woo\StatutoryTerm;
use OCA\OpenCatalogi\Service\Woo\TermEngineUnavailableException;
use OCA\OpenCatalogi\Service\Woo\WooRequestIntake;
use OCA\OpenCatalogi\Service\Woo\WooRequestService;
use OCA\OpenCatalogi\Service\Woo\WooRequestStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Every outcome of an intake, whole: each key, in order, with its value.
 *
 * @covers \OCA\OpenCatalogi\Service\Woo\WooRequestIntake
 */
class WooRequestIntakeOutcomeGoldenTest extends TestCase {

	/**
	 * Receive one request over a store and a term engine that behave as told.
	 *
	 * @param array<string, mixed> $answers What the citizen filled in.
	 * @param array<string, mixed> $world How the store and the engine behave.
	 *
	 * @return array<string, string> The outcome, with the minted reference masked.
	 */
	private function receive(array $answers, array $world): array {
		$store = $this->getMockBuilder(WooRequestStore::class)
			->disableOriginalConstructor()
			->onlyMethods(['save'])
			->getMock();
		$store->method('save')->willReturnCallback(
			function (array $record, string $uuid = '') use ($world): array {
				if (($world['store'] ?? 'works') === 'down' || ($uuid !== '' && ($world['store'] ?? '') === 'second-down')) {
					throw new RuntimeException('register down');
				}

				$record['id'] = ($world['id'] ?? 'req-1');
				return $record;
			}
		);
		$terms = $this->getMockBuilder(StatutoryTerm::class)
			->disableOriginalConstructor()
			->onlyMethods(['arm'])
			->getMock();
		$terms->method('arm')->willReturnCallback(
			static function () use ($world): array {
				if (($world['engine'] ?? 'works') === 'down') {
					throw new TermEngineUnavailableException('no engine');
				}

				return ($world['term'] ?? ['timer' => 'timer-1', 'dueAt' => '2026-11-02T09:00:00+00:00']);
			}
		);

		$outcome = (new WooRequestIntake(new WooRequestService(), $store, $terms))->receive(answers: $answers, receivedAt: '2026-10-05T09:00:00+00:00');
		$outcome['reference'] = (string)preg_replace('/^WOO-2026-[0-9A-F]{6}$/', 'WOO-2026-XXXXXX', $outcome['reference']);
		return $outcome;

	}//end receive()

	/**
	 * Each world and the outcome it answers with.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, string>}>
	 */
	public static function worlds(): array {
		$asked = ['requestedInformation' => 'Alle stukken.'];
		return [
			'armed' => [$asked, [], ['outcome' => 'armed', 'requestId' => 'req-1', 'reference' => 'WOO-2026-XXXXXX', 'dueAt' => '2026-11-02T09:00:00+00:00', 'message' => '']],
			'refused' => [[], [], ['outcome' => 'refused', 'requestId' => '', 'reference' => '', 'dueAt' => '', 'message' => 'A Woo request has to say what information is asked for. A request for nothing cannot be decided, and a term counted against it would be counted against nothing.']],
			'unavailable' => [$asked, ['store' => 'down'], ['outcome' => 'unavailable', 'requestId' => '', 'reference' => '', 'dueAt' => '', 'message' => 'register down']],
			'engine down' => [$asked, ['engine' => 'down'], ['outcome' => 'not-armed', 'requestId' => 'req-1', 'reference' => 'WOO-2026-XXXXXX', 'dueAt' => '', 'message' => 'no engine']],
			'second save fails' => [$asked, ['store' => 'second-down'], ['outcome' => 'not-armed', 'requestId' => 'req-1', 'reference' => 'WOO-2026-XXXXXX', 'dueAt' => '', 'message' => 'register down']],
			'no due date' => [$asked, ['term' => ['timer' => 'timer-1']], ['outcome' => 'not-armed', 'requestId' => 'req-1', 'reference' => 'WOO-2026-XXXXXX', 'dueAt' => '', 'message' => 'The term engine armed no due date.']],
			'no id' => [$asked, ['id' => ''], ['outcome' => 'armed', 'requestId' => '', 'reference' => 'WOO-2026-XXXXXX', 'dueAt' => '2026-11-02T09:00:00+00:00', 'message' => '']],
		];

	}//end worlds()

	/**
	 * The outcome of a world, whole.
	 *
	 * @param array<string, mixed> $answers What the citizen filled in.
	 * @param array<string, mixed> $world How the store and the engine behave.
	 * @param array<string, string> $expected The outcome.
	 *
	 * @return void
	 */
	#[DataProvider('worlds')]
	public function testOutcome(array $answers, array $world, array $expected): void {
		$this->assertSame($expected, $this->receive($answers, $world));

	}//end testOutcome()
}//end class
