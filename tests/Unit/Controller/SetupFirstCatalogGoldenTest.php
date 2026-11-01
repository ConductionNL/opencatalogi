<?php

/**
 * Golden test for the create-first-catalog setup action.
 *
 * Every case runs the action over recording doubles and compares the answer,
 * every config read and write and every object call, in order, to the
 * recording in fixtures/setup-first-catalog-golden.json.
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

namespace Unit\Controller;

use OCA\OpenCatalogi\Contract\BroadcastServiceInterface;
use OCA\OpenCatalogi\Contract\DemoDataServiceInterface;
use OCA\OpenCatalogi\Contract\DirectoryServiceInterface;
use OCA\OpenCatalogi\Contract\SettingsServiceInterface;
use OCA\OpenCatalogi\Controller\SetupController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden test for the create-first-catalog setup action.
 *
 * @covers \OCA\OpenCatalogi\Controller\SetupController
 */
class SetupFirstCatalogGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/setup-first-catalog-golden.json';

	/**
	 * Everything asked, in order.
	 *
	 * @var array<int, mixed>
	 */
	private array $log = [];

	/**
	 * The recorded cases: config values, what the object store answers, and what fails.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$base = ['catalog_register' => '1', 'catalog_schema' => '2', 'publication_register' => '5', 'publication_schema' => '6'];

		return [
			'public default' => [['config' => $base]],
			'private with groups' => [['config' => $base + ['default_catalog_scope' => 'private', 'default_catalog_groups' => ' a , ,b ']]],
			'private without groups' => [['config' => $base + ['default_catalog_scope' => 'private']]],
			'internal scope' => [['config' => $base + ['default_catalog_scope' => 'internal']]],
			'no publication register' => [['config' => ['publication_register' => ''] + $base]],
			'no publication schema' => [['config' => ['publication_schema' => ''] + $base]],
			'already completed' => [['config' => $base + ['onboarding_completed_version' => '3']]],
			'older completion' => [['config' => $base + ['onboarding_completed_version' => '2']]],
			'a catalog exists' => [['config' => $base, 'catalogs' => [['id' => 1]]]],
			'search answers no list' => [['config' => $base, 'catalogs' => 3]],
			'search throws' => [['config' => $base, 'fail' => 'search']],
			'openregister missing' => [['config' => $base, 'openregister' => false]],
			'no catalog register' => [['config' => ['catalog_register' => ''] + $base]],
			'no catalog schema' => [['config' => ['catalog_schema' => ''] + $base]],
			'save throws' => [['config' => $base, 'fail' => 'save']],
		];

	}//end cases()

	/**
	 * Replay a case and compare it to the recording.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testCreateFirstCatalogMatchesTheRecording(array $case): void {
		$name = $this->dataName();
		$response = $this->controller(case: $case)->action('create-first-catalog');
		$actual = ['status' => $response->getStatus(), 'data' => $response->getData(), 'log' => $this->log];

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

	}//end testCreateFirstCatalogMatchesTheRecording()

	/**
	 * The controller over recording doubles for one case.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return SetupController The controller.
	 */
	private function controller(array $case): SetupController {
		$values = $case['config'];
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') use (&$values): string {
				$this->log[] = ['read' => $key];
				return ($values[$key] ?? $default);
			}
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value) use (&$values): bool {
				$this->log[] = ['write' => [$key, $value]];
				$values[$key] = $value;
				return true;
			}
		);

		$objects = $this->createMock(ObjectService::class);
		$objects->method('searchObjects')->willReturnCallback(
			function (...$args) use ($case): mixed {
				$this->log[] = ['search' => $args];
				if (($case['fail'] ?? null) === 'search') {
					throw new RuntimeException('search down');
				}

				return ($case['catalogs'] ?? []);
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (...$args) use ($case): mixed {
				$this->log[] = ['save' => $args];
				if (($case['fail'] ?? null) === 'save') {
					throw new RuntimeException('save broke');
				}

				return new ObjectEntity();
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($case, $objects): mixed {
				$this->log[] = ['get' => $id];
				if (($case['openregister'] ?? true) === false) {
					throw new RuntimeException('not installed');
				}

				return $objects;
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->log[] = ['error' => $message, 'context' => $context];
			}
		);

		return new SetupController(
			'opencatalogi',
			$this->createMock(IRequest::class),
			$config,
			$this->createMock(SettingsServiceInterface::class),
			$this->createMock(DemoDataServiceInterface::class),
			$this->createMock(DirectoryServiceInterface::class),
			$this->createMock(BroadcastServiceInterface::class),
			$container,
			$l10n,
			$logger,
			$this->createMock(IUserSession::class)
		);

	}//end controller()
}//end class
