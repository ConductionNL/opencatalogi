<?php

/**
 * Golden test for DemoDataService::listChoices() and install().
 *
 * Every case writes one descriptor (or none) into a temporary app directory and
 * compares the choices, the install answer (or its error) and what was imported
 * to the recording in fixtures/demo-data-golden.json.
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

use OCA\OpenCatalogi\Service\DemoDataService;
use OCP\App\IAppManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Golden test for the demo data choices and install.
 *
 * @covers \OCA\OpenCatalogi\Service\DemoDataService
 */
class DemoDataGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/demo-data-golden.json';

	/**
	 * The recorded cases: the descriptor text (null for none) and whether OpenRegister is installed.
	 *
	 * @return array<string, array{0: string|null, 1?: bool}>
	 */
	public static function cases(): array {
		return [
			'no descriptor' => [null],
			'not json' => ['{nope'],
			'json but not a map' => ['"text"'],
			'no components' => ['{"x":1}'],
			'components not a map' => ['{"components":"x"}'],
			'objects not a list' => ['{"components":{"objects":"x"}}'],
			'three objects' => ['{"components":{"objects":[{},{},{}],"registers":{"a":{}}}}'],
			'no objects key' => ['{"components":{"schemas":{"s":{}}}}'],
			'without OpenRegister' => ['{"components":{"objects":[{}]}}', false],
		];

	}//end cases()

	/**
	 * Replay a case and compare it to the recording.
	 *
	 * @param string|null $descriptor The descriptor text, or null for no file.
	 * @param bool $openRegister Whether OpenRegister is installed.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testDemoDataMatchesTheRecording(?string $descriptor, bool $openRegister = true): void {
		$name = $this->dataName();
		$appDir = sys_get_temp_dir() . '/oc-demo-golden-' . bin2hex(random_bytes(4));
		mkdir($appDir . '/lib/Settings', 0777, true);
		if ($descriptor !== null) {
			file_put_contents($appDir . '/lib/Settings/opencatalogi_mock_register.json', $descriptor);
		}

		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppPath')->willReturn($appDir);
		$apps->method('getAppVersion')->willReturn('9.9.9');
		$apps->method('getInstalledApps')->willReturn(($openRegister === true) ? ['openregister'] : []);

		$importer = new class {
			/** @var list<array<string, mixed>> */
			public array $seen = [];

			/**
			 * Record an import.
			 *
			 * @param string $appId The app id.
			 * @param array $data The data.
			 * @param string $version The version.
			 * @param bool $force Whether forced.
			 *
			 * @return array<string, mixed>
			 */
			public function importFromApp(string $appId, array $data, string $version, bool $force): array {
				$this->seen[] = ['appId' => $appId, 'data' => $data, 'version' => $version, 'force' => $force];
				return ['registers' => array_keys($data['components']['registers'] ?? []), 'schemas' => ($data['components']['schemas'] ?? 'x')];
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($importer);

		$service = new DemoDataService($apps, $container, $this->createMock(LoggerInterface::class));
		try {
			$installed = $service->install();
		} catch (\Throwable $e) {
			$installed = ['thrown' => get_class($e), 'message' => str_replace($appDir, '<app>', $e->getMessage())];
		}

		$actual = ['choices' => $service->listChoices(), 'install' => $installed, 'imported' => $importer->seen];

		$recorded = [];
		if (file_exists(self::FIXTURE) === true) {
			$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$name] = $actual;
			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
		}

		$this->assertArrayHasKey($name, $recorded, 'No recording for ' . $name);
		$this->assertSame($recorded[$name], json_decode((string)json_encode($actual), true));

	}//end testDemoDataMatchesTheRecording()
}//end class
