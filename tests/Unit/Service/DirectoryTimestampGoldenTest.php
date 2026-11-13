<?php

/**
 * Golden test for DirectoryService::extractTimestamp.
 *
 * Every world hands a listing payload to the timestamp extraction and records
 * the timestamp it found (or that it found none, or what it threw).
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

use DateTime;
use DateTimeImmutable;
use OCA\OpenCatalogi\Service\BroadcastService;
use OCA\OpenCatalogi\Service\DirectoryService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Golden test for DirectoryService::extractTimestamp.
 *
 * @covers \OCA\OpenCatalogi\Service\DirectoryService
 */
class DirectoryTimestampGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/directory-timestamp-golden.json';

	/**
	 * The recorded worlds.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function worlds(): array {
		return [
			'empty' => [[]],
			'updated' => [['updated' => '2026-03-01T10:00:00+00:00', 'created' => '2020-01-01']],
			'updated wins over @self' => [['updated' => '2026-03-01', '@self' => ['updated' => '2025-01-01']]],
			'@self updated' => [['@self' => ['updated' => '2025-05-05T05:05:05+02:00', 'created' => '2020-01-01'], 'created' => '2021-01-01']],
			'created after a bad updated' => [['updated' => 'not a date', 'created' => '2021-02-02T00:00:00+00:00']],
			'@self created' => [['@self' => ['created' => '2019-09-09']]],
			'lastSync' => [['lastSync' => '2024-12-31T23:59:59+00:00']],
			'serialised DateTime' => [['updated' => ['date' => '2023-03-03 03:03:03.000000', 'timezone_type' => 3, 'timezone' => 'UTC']]],
			'array without date' => [['updated' => ['when' => '2023-03-03'], 'created' => '2022-02-02']],
			'integer value skipped' => [['updated' => 1700000000, 'lastSync' => '2022-01-01']],
			'null values skipped' => [['updated' => null, '@self' => ['updated' => null], 'created' => '2018-08-08']],
			'@self is a string' => [['@self' => 'flat', 'lastSync' => '2017-07-07']],
			'@self is a list' => [['@self' => ['x'], 'lastSync' => '2017-07-07']],
			'literal dotted key ignored' => [['@self.updated' => '2010-10-10', 'lastSync' => '2016-06-06']],
			'empty string' => [['updated' => '', 'created' => '2015-05-05']],
			'all invalid' => [['updated' => 'nope', '@self' => ['updated' => 'nope', 'created' => 'nope'], 'created' => 'nope', 'lastSync' => 'nope']],
			'serialised date invalid' => [['updated' => ['date' => 'garbage'], 'lastSync' => '2014-04-04']],
			'serialised date not text' => [['updated' => ['date' => 12]]],
			'immutable skipped' => [['updated' => 'immutable', 'lastSync' => '2013-03-03']],
			'DateTime instance' => [['updated' => 'instance', 'created' => '2012-02-02']],
			'created wins over @self created' => [['created' => '2008-08-08', '@self' => ['created' => '2007-07-07']]],
			'@self with a date key only' => [['@self' => ['date' => '2009-09-09'], 'lastSync' => '2006-06-06']],
			'false is a value' => [['updated' => false, 'created' => '2011-01-01']],
		];

	}//end worlds()

	/**
	 * Extract the timestamp in the world and compare with the recording.
	 *
	 * @param array<string, mixed> $data The listing payload.
	 *
	 * @return void
	 */
	#[DataProvider('worlds')]
	public function testTimestampMatchesTheRecording(array $data): void {
		$instance = new DateTime('2001-01-01T01:01:01+00:00');
		if (($data['updated'] ?? null) === 'immutable') {
			$data['updated'] = new DateTimeImmutable('2002-02-02');
		}

		if (($data['updated'] ?? null) === 'instance') {
			$data['updated'] = $instance;
		}

		$method = new \ReflectionMethod(DirectoryService::class, 'extractTimestamp');
		try {
			$result = $method->invoke($this->service(), $data);
			$actual = ['result' => null];
			if ($result !== null) {
				$actual = ['result' => $result->format('Y-m-d\TH:i:s.uP'), 'class' => get_class($result), 'same' => $result === $instance];
				if (abs($result->getTimestamp() - time()) < 60) {
					$actual['result'] = '(now)';
				}
			}
		} catch (\Throwable $e) {
			$actual = ['exception' => get_class($e)];
		}

		$recorded = [];
		if (file_exists(self::FIXTURE) === true) {
			$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		$name = (string)$this->dataName();
		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$name] = $actual;
			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $recorded, 'No recording for ' . $name);
		$this->assertSame($recorded[$name], $actual);

	}//end testTimestampMatchesTheRecording()

	/**
	 * Build the service with inert collaborators.
	 *
	 * @return DirectoryService
	 */
	private function service(): DirectoryService {
		return new DirectoryService(
			$this->createMock(IURLGenerator::class),
			$this->createMock(IAppConfig::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(IAppManager::class),
			$this->createMock(BroadcastService::class),
			$this->createMock(IRequest::class),
		);

	}//end service()
}//end class
