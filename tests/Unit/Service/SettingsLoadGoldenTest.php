<?php

/**
 * Golden test for SettingsService::loadSettings.
 *
 * Every world lays out an app directory, runs the import once and records what
 * OpenRegister was handed (the merged payload, the version and the force flag),
 * what the import result was reconciled with, the config written and the
 * warnings logged, or the error message.
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

use OCA\OpenCatalogi\Service\RegisterSchemaLinkService;
use OCA\OpenCatalogi\Service\SettingsService;
use OCA\OpenRegister\Service\ConfigurationService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden test for SettingsService::loadSettings.
 *
 * @covers \OCA\OpenCatalogi\Service\SettingsService
 * @uses \OCA\OpenCatalogi\Service\CatalogScopeBackfill
 */
class SettingsLoadGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/settings-load-golden.json';

	/**
	 * What the doubles were asked.
	 *
	 * @var array<string, list<mixed>>
	 */
	private array $log = [];

	/**
	 * The temporary root of the current world.
	 *
	 * @var string
	 */
	private string $root = '';

	/**
	 * The recorded worlds.
	 *
	 * Each world names the app path below the temporary root, the files to
	 * write there (null content: a directory), the import answer and flags.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function worlds(): array {
		$monolith = [
			'openapi' => '3.0.0',
			'components' => [
				'registers' => [
					'publication' => ['slug' => 'publication', 'schemas' => ['catalog', 'listing'], 'configuration' => ['schemas' => ['catalog' => ['magicMapping' => false]]]],
					'other' => ['slug' => 'other', 'schemas' => ['theme', 7]],
				],
				'schemas' => ['catalog' => ['title' => 'Catalog'], 'listing' => ['title' => 'Listing'], 'theme' => ['title' => 'Theme']],
			],
			'list' => ['a', 'b'],
			'scalar' => 'base',
		];
		$fragA = [
			'components' => ['schemas' => ['course' => ['title' => 'Course'], 'catalog' => ['extra' => true]]],
			'list' => ['c'],
			'scalar' => 'from-a',
		];
		$fragB = [
			'components' => [
				'schemas' => ['program' => ['title' => 'Program']],
				'registers' => ['publication' => ['configuration' => ['schemas' => ['program' => ['magicMapping' => false]]]]],
			],
			'scalar' => 'from-b',
			'map' => ['k' => 'v'],
		];
		$importResult = [
			'registers' => [['slug' => 'publication', 'id' => 20], ['slug' => 'other', 'id' => 21]],
			'schemas' => [['slug' => 'publication', 'id' => 30], ['slug' => 'catalog', 'uuid' => 'cat-uuid'], ['slug' => 'listing', 'id' => 32], ['id' => 99]],
		];

		return [
			'no file' => [['app' => 'apps-extra/opencatalogi', 'files' => []]],
			'invalid json' => [['app' => 'apps-extra/opencatalogi', 'files' => ['lib/Settings/publication_register.json' => '{invalid']]],
			'empty json object' => [['app' => 'x/y/z', 'files' => ['lib/Settings/publication_register.json' => '{}']]],
			'json scalar' => [['app' => 'x/y/z', 'files' => ['lib/Settings/publication_register.json' => '"text"']]],
			'monolith only, fallback path' => [['app' => 'x/y/z', 'files' => ['lib/Settings/publication_register.json' => json_encode($monolith)], 'result' => $importResult]],
			'apps-extra relative path' => [['app' => 'apps-extra/opencatalogi/sub', 'files' => ['lib/Settings/publication_register.json' => json_encode(['a' => 1])]]],
			'apps relative path' => [['app' => 'apps/opencatalogi/sub', 'files' => ['lib/Settings/publication_register.json' => json_encode(['a' => 1])]]],
			'other relative path' => [['app' => 'lib/opencatalogi/sub', 'files' => ['lib/Settings/publication_register.json' => json_encode(['a' => 1])]]],
			'source kept' => [['app' => 'apps/opencatalogi/sub', 'files' => ['lib/Settings/publication_register.json' => json_encode(['x-openregister' => ['sourceUrl' => 'kept/url', 'sourceType' => 'remote', 'other' => 1]])]]],
			'source type only' => [['app' => 'x/y/z', 'files' => ['lib/Settings/publication_register.json' => json_encode(['x-openregister' => ['sourceType' => 'github']])]]],
			'source url only' => [['app' => 'x/y/z', 'files' => ['lib/Settings/publication_register.json' => json_encode(['x-openregister' => ['sourceUrl' => 'u']])]]],
			'fragments merged in order' => [[
				'app' => 'x/y/z',
				'files' => [
					'lib/Settings/publication_register.json' => json_encode($monolith),
					'lib/Settings/register.d/20-b.json' => json_encode($fragB),
					'lib/Settings/register.d/10-a.json' => json_encode($fragA),
					'lib/Settings/register.d/15-bad.json' => '{nope',
					'lib/Settings/register.d/notes.txt' => '{"scalar":"txt"}',
				],
				'result' => $importResult,
				'force' => true,
			],
			],
			'empty fragment dir' => [['app' => 'x/y/z', 'files' => ['lib/Settings/publication_register.json' => json_encode($monolith), 'lib/Settings/register.d' => null]]],
			'fragment dir is a file' => [['app' => 'x/y/z', 'files' => ['lib/Settings/publication_register.json' => json_encode($monolith), 'lib/Settings/register.d' => '{}']]],
			'orphans without publication register' => [['app' => 'x/y/z', 'files' => ['lib/Settings/publication_register.json' => json_encode(['components' => ['schemas' => ['s' => []], 'registers' => ['other' => ['schemas' => []]]]])]]],
			'publication register not an array' => [['app' => 'x/y/z', 'files' => ['lib/Settings/publication_register.json' => json_encode(['components' => ['schemas' => ['s' => []], 'registers' => ['publication' => 'no']]])]]],
			'no openregister' => [['app' => 'x/y/z', 'files' => ['lib/Settings/publication_register.json' => '{}'], 'openregister' => false]],
			'import fails' => [['app' => 'x/y/z', 'files' => ['lib/Settings/publication_register.json' => '{}'], 'importFails' => true]],
		];

	}//end worlds()

	/**
	 * Run the import in the world and compare with the recording.
	 *
	 * @param array<string, mixed> $world The world.
	 *
	 * @return void
	 */
	#[DataProvider('worlds')]
	public function testLoadMatchesTheRecording(array $world): void {
		$this->log = ['imported' => [], 'reconciled' => [], 'config' => [], 'warnings' => []];
		$this->root = sys_get_temp_dir() . '/oc-settings-golden-' . uniqid();
		$appPath = $this->root . '/' . $world['app'];
		mkdir($appPath, 0777, true);
		foreach ($world['files'] as $rel => $content) {
			$path = $appPath . '/' . $rel;
			if ($content === null) {
				mkdir($path, 0777, true);
				continue;
			}

			if (is_dir(dirname($path)) === false) {
				mkdir(dirname($path), 0777, true);
			}

			file_put_contents($path, $content);
		}

		try {
			$outcome = ['result' => $this->service(world: $world, appPath: $appPath)->loadSettings(force: ($world['force'] ?? false))];
		} catch (\Throwable $e) {
			$message = (string)preg_replace('/, called in .*$/', '', $e->getMessage());
			$outcome = ['error' => get_class($e) . ': ' . str_replace($this->root, '<root>', $message)];
		} finally {
			$this->removeTree(path: $this->root);
		}

		$actual = $outcome + ['log' => $this->log];

		$recorded = [];
		if (file_exists(self::FIXTURE) === true) {
			$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		$name = (string)$this->dataName();
		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$name] = json_decode((string)json_encode($actual), true);
			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $recorded, 'No recording for ' . $name);
		$this->assertSame($recorded[$name], json_decode((string)json_encode($actual), true));

	}//end testLoadMatchesTheRecording()

	/**
	 * Build the service around the world's doubles.
	 *
	 * @param array<string, mixed> $world   The world.
	 * @param string               $appPath The app directory.
	 *
	 * @return SettingsService
	 */
	private function service(array $world, string $appPath): SettingsService {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = '') => $default
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->log['config'][] = [$app, $key, $value];
				return true;
			}
		);

		$importer = $this->createMock(ConfigurationService::class);
		$importer->method('importFromApp')->willReturnCallback(
			function (string $appId, array $data, string $version, bool $force) use ($world): array {
				$this->log['imported'][] = ['appId' => $appId, 'data' => $data, 'version' => $version, 'force' => $force];
				if (($world['importFails'] ?? false) === true) {
					throw new \Exception('import broke');
				}

				return ($world['result'] ?? ['registers' => [], 'schemas' => []]);
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($importer) {
				if ($id === 'OCA\OpenRegister\Service\ConfigurationService') {
					return $importer;
				}

				throw new RuntimeException('not in the container: ' . $id);
			}
		);

		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppPath')->willReturn($appPath);
		$apps->method('getAppVersion')->willReturn('9.8.7');
		$apps->method('getInstalledApps')->willReturn(($world['openregister'] ?? true) === true ? ['openregister'] : ['files']);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->log['warnings'][] = $message;
			}
		);

		$link = $this->createMock(RegisterSchemaLinkService::class);
		$link->method('reconcile')->willReturnCallback(
			function (array $result): void {
				$this->log['reconciled'][] = $result;
			}
		);

		return new SettingsService($config, $container, $apps, $logger, $link);

	}//end service()

	/**
	 * Remove a directory tree.
	 *
	 * @param string $path The directory.
	 *
	 * @return void
	 */
	private function removeTree(string $path): void {
		if (is_dir($path) === false) {
			if (file_exists($path) === true) {
				unlink($path);
			}

			return;
		}

		foreach ((array)scandir($path) as $entry) {
			if ($entry !== '.' && $entry !== '..') {
				$this->removeTree(path: $path . '/' . $entry);
			}
		}

		rmdir($path);

	}//end removeTree()
}//end class
