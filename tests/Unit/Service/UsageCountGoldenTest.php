<?php

/**
 * Golden test for `UsageCounterService::increment()`.
 *
 * Runs increment() over a scripted OpenRegister and compares the answer, every
 * search and save it made, and every log line with what was recorded: the
 * refusals (empty id, unknown kind, crawler, no OpenRegister, no counter
 * register or schema), the first count of the day, the bump of an existing
 * counter in each stored shape, and the swallowed failures.
 *
 * Re-record (only when behaviour is meant to change) with
 * `GOLDEN_RECORD=1 vendor/bin/phpunit -c phpunit-unit.xml tests/Unit/Service/UsageCountGoldenTest.php`.
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
 *
 * @spec openspec/specs/publication-usage-analytics/spec.md
 *
 * @covers \OCA\OpenCatalogi\Service\UsageCounterService
 * @uses \OCA\OpenCatalogi\Service\UsageCount
 * @uses \OCA\OpenCatalogi\Contract\PublicationUsage
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Service;

use OCA\OpenCatalogi\Contract\PublicationUsage;
use OCA\OpenCatalogi\Service\UsageCounterService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden test for UsageCounterService::increment().
 */
class UsageCountGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../fixtures/usage-count-golden.json';

	private const BROWSER = 'Mozilla/5.0 (X11; Linux x86_64) Firefox/131.0';

	/**
	 * The call and the OpenRegister state of each case.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$view = ['pub-1', 'view', self::BROWSER, 'woo'];
		return [
			'empty publication id' => [['args' => ['', 'view', self::BROWSER, 'woo']]],
			'unknown kind' => [['args' => ['pub-1', 'like', self::BROWSER, null]]],
			'crawler' => [['args' => ['pub-1', 'view', 'Googlebot/2.1', 'woo']]],
			'no user agent' => [['args' => ['pub-1', 'download', null, null]]],
			'openregister not installed' => [['args' => $view, 'apps' => ['opencatalogi']]],
			'no counter register' => [['args' => $view, 'config' => ['usageCounter_register' => '']]],
			'no counter schema' => [['args' => $view, 'config' => ['usageCounter_schema' => '']]],
			'first view with catalog' => [['args' => $view, 'search' => []]],
			'first download without catalog' => [['args' => ['pub-2', 'download', self::BROWSER, null], 'search' => []]],
			'search answers a count' => [['args' => $view, 'search' => 3]],
			'existing counter with id' => [[
				'args' => $view,
				'search' => [['id' => 'c-1', 'publication' => 'pub-1', 'date' => '<today>', 'kind' => 'view', 'count' => 9]],
			]],
			'existing counter with uuid only' => [[
				'args' => $view,
				'search' => [['uuid' => 'u-1', 'count' => '4'], ['id' => 'second']],
			]],
			'existing counter without id or count' => [[
				'args' => $view,
				'search' => [['publication' => 'pub-1']],
			]],
			'existing counter as entity' => [[
				'args' => $view,
				'search' => [['entity' => ['id' => 'c-e', 'count' => 2, 'kind' => 'view']]],
			]],
			'existing counter as plain object' => [[
				'args' => $view,
				'search' => [['plain' => ['id' => 'c-p', 'count' => 5]]],
			]],
			'search fails' => [['args' => $view, 'searchFails' => true]],
			'save fails' => [['args' => $view, 'search' => [], 'saveFails' => true]],
			'container fails' => [['args' => $view, 'containerFails' => true]],
		];

	}//end cases()

	/**
	 * Run one case and compare it with the recording.
	 *
	 * @param array<string, mixed> $case The case input.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testIncrementMatchesTheRecording(array $case): void {
		$name = $this->dataName();
		$actual = $this->increment(case: $case);

		$recorded = [];
		if (is_file(self::FIXTURE) === true) {
			$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$name] = $actual;
			ksort($recorded);
			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $recorded, 'No recording for this case.');
		$this->assertSame(
			json_encode($recorded[$name], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
			json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
		);

	}//end testIncrementMatchesTheRecording()

	/**
	 * Build the service over scripted collaborators and call increment().
	 *
	 * @param array<string, mixed> $case The case input.
	 *
	 * @return array<string, mixed> The answer, the OpenRegister calls and the log.
	 */
	private function increment(array $case): array {
		$calls = [];
		$today = gmdate('Y-m-d');
		$values = array_merge(
			['usageCounter_register' => 'reg-9', 'usageCounter_schema' => 'schema-4'],
			($case['config'] ?? [])
		);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($values[$key] ?? $default)
		);

		$apps = $this->createMock(IAppManager::class);
		$apps->method('getInstalledApps')->willReturn(($case['apps'] ?? ['openregister', 'opencatalogi']));

		$search = [];
		if (array_key_exists('search', $case) === true && is_array($case['search']) === false) {
			$search = $case['search'];
		}

		foreach ((is_array($case['search'] ?? null) === true ? $case['search'] : []) as $row) {
			if (isset($row['entity']) === true) {
				$entity = $this->getMockBuilder(ObjectEntity::class)
					->disableOriginalConstructor()
					->onlyMethods(['jsonSerialize'])
					->getMock();
				$entity->method('jsonSerialize')->willReturn($row['entity']);
				$row = $entity;
			} else if (isset($row['plain']) === true) {
				$row = (object)$row['plain'];
			} else if (($row['date'] ?? null) === '<today>') {
				$row['date'] = $today;
			}

			$search[] = $row;
		}

		$normalise = static function (mixed $value) use ($today): mixed {
			return json_decode(str_replace($today, '<today>', (string)json_encode($value)), true);
		};

		$objects = $this->createMock(ObjectService::class);
		$objects->method('searchObjects')->willReturnCallback(
			static function (array $query = [], bool $_rbac = true, bool $_multitenancy = true) use (&$calls, $search, $case, $normalise) {
				$calls[] = ['searchObjects', $normalise($query), $_rbac, $_multitenancy];
				if (($case['searchFails'] ?? false) === true) {
					throw new RuntimeException('search down');
				}

				return $search;
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			static function (
				array|ObjectEntity $object,
				?array $extend = [],
				mixed $register = null,
				mixed $schema = null,
				?string $uuid = null,
				bool $_rbac = true,
				bool $_multitenancy = true
			) use (&$calls, $case, $normalise): ObjectEntity {
				$calls[] = ['saveObject', $normalise($object), $extend, $register, $schema, $uuid, $_rbac, $_multitenancy];
				if (($case['saveFails'] ?? false) === true) {
					throw new RuntimeException('save down');
				}

				return new ObjectEntity();
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($objects, $case): object {
				if (($case['containerFails'] ?? false) === true) {
					throw new RuntimeException('container down');
				}

				return $objects;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning', 'error', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				static function (string $message, array $context = []) use (&$calls, $level): void {
					$calls[] = [$level, $message, $context];
				}
			);
		}

		$service = new UsageCounterService($config, $apps, $container, $logger);
		[$publicationId, $kind, $userAgent, $catalog] = $case['args'];
		$answer = $service->increment(new PublicationUsage($publicationId, $kind, $catalog), $userAgent);

		return ['answer' => $answer, 'calls' => $calls];

	}//end increment()
}//end class
