<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pins how the statistics endpoints read their from/to/granularity window.
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Contract\CatalogiServiceInterface;
use OCA\OpenCatalogi\Contract\UsageCounterServiceInterface;
use OCA\OpenCatalogi\Controller\StatsController;
use OCP\App\IAppManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for StatsController's window reading.
 */
class StatsReadRangeTest extends TestCase {

	/**
	 * The request parameters and the window they give.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array{0: ?string, 1: ?string, 2: string}}>
	 */
	public static function windows(): array {
		return [
			'nothing' => [[], [null, null, 'day']],
			'a full window' => [['from' => '2026-01-01', 'to' => '2026-02-01', 'granularity' => 'week'], ['2026-01-01', '2026-02-01', 'week']],
			'month' => [['granularity' => 'month'], [null, null, 'month']],
			'an unknown granularity' => [['granularity' => 'year'], [null, null, 'day']],
			'granularity in capitals' => [['granularity' => 'WEEK'], [null, null, 'day']],
			'empty dates' => [['from' => '', 'to' => ''], [null, null, 'day']],
			'dates that are no text' => [['from' => 20260101, 'to' => ['x']], [null, null, 'day']],
			'a granularity that is a number' => [['granularity' => 7], [null, null, 'day']],
		];

	}//end windows()

	/**
	 * Each parameter is read in turn and normalised.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 * @param array{0: ?string, 1: ?string, 2: string} $expected The window.
	 *
	 * @return void
	 */
	#[DataProvider('windows')]
	public function testTheWindowIsReadAndNormalised(array $params, array $expected): void {
		$read = [];
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) use (&$read, $params): mixed {
				$read[] = $key;
				return ($params[$key] ?? $default);
			}
		);

		$controller = new StatsController(
			'opencatalogi',
			$request,
			$this->createMock(UsageCounterServiceInterface::class),
			$this->createMock(CatalogiServiceInterface::class),
			$this->createMock(IAppManager::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(IL10N::class),
			$this->createMock(IUserSession::class),
			$this->createMock(LoggerInterface::class)
		);

		$this->assertSame($expected, (new \ReflectionMethod($controller, 'readRange'))->invoke($controller));
		$this->assertSame(['from', 'to', 'granularity'], $read);
	}//end testTheWindowIsReadAndNormalised()
}//end class
