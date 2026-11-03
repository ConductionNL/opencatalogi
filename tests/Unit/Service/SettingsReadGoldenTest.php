<?php

/**
 * Golden test for SettingsService::getSettings(), autoConfigure() and installOrUpdateOpenRegister().
 *
 * Every case builds the service over doubles for the app config, the app manager
 * and the OpenRegister mappers, and compares the answer (or the thrown message)
 * to the recording in fixtures/settings-read-golden.json.
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
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Golden test for the settings read, auto-configuration and OpenRegister install paths.
 *
 * @covers \OCA\OpenCatalogi\Service\SettingsService
 */
class SettingsReadGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/settings-read-golden.json';

	/**
	 * The app config values the service wrote, in order.
	 *
	 * @var list<array{0: string, 1: string}>
	 */
	private array $written = [];

	/**
	 * The recorded cases: the call and the world the doubles answer from.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$publication = ['id' => 7, 'slug' => 'my-publication', 'schemas' => [['id' => 70, 'title' => 'Catalog'], 'not-a-schema', ['id' => 71, 'title' => 'listing items'], ['id' => 72, 'title' => 'Theme']]];

		return [
			'settings without OpenRegister' => [['call' => 'getSettings', 'apps' => []]],
			'settings with registers' => [['call' => 'getSettings', 'registers' => [['id' => 1, 'slug' => 'publication', 'schemas' => []]]]],
			'settings with stored values' => [['call' => 'getSettings', 'stored' => ['catalog_schema' => '12', 'dcat_instance_title' => 'Mijn catalogus', 'public_api_cache_seconds' => '0']]],
			'settings with a failing config' => [['call' => 'getSettings', 'config' => 'throws']],
			'auto configure without registers' => [['call' => 'autoConfigure', 'registers' => []]],
			'auto configure, publication register second' => [['call' => 'autoConfigure', 'registers' => [['id' => 1, 'slug' => 'other', 'schemas' => [['id' => 9, 'title' => 'catalog']]], $publication]]],
			'auto configure, register as entity' => [['call' => 'autoConfigure', 'registers' => [['entity' => $publication]]]],
			'auto configure, no publication register' => [['call' => 'autoConfigure', 'registers' => [['id' => 1, 'slug' => 'other', 'schemas' => []]]]],
			'auto configure, register without schemas' => [['call' => 'autoConfigure', 'registers' => [['id' => 3, 'slug' => 'publication']]]],
			'auto configure, two publication registers' => [['call' => 'autoConfigure', 'registers' => [['id' => 4, 'slug' => 'publication-a', 'schemas' => []], $publication]]],
			'auto configure without OpenRegister' => [['call' => 'autoConfigure', 'apps' => []]],
			'install, present and new enough' => [['call' => 'installOrUpdateOpenRegister', 'args' => ['0.2.0'], 'installed' => true, 'version' => '0.3.1']],
			'install, enabled on demand' => [['call' => 'installOrUpdateOpenRegister', 'args' => ['0.2.0'], 'installed' => 'after-enable', 'version' => '0.2.0']],
			'install, missing' => [['call' => 'installOrUpdateOpenRegister', 'args' => ['0.2.0'], 'installed' => false, 'enable' => 'throws']],
			'install, too old' => [['call' => 'installOrUpdateOpenRegister', 'args' => ['0.2.0'], 'installed' => true, 'version' => '0.1.9']],
			'publishing options default' => [['call' => 'getPublishingOptions']],
			'publishing options stored' => [['call' => 'getPublishingOptions', 'stored' => ['auto_publish_objects' => 'true', 'auto_publish_attachments' => 'yes', 'use_old_style_publishing_view' => 'true']]],
			'publishing options failing config' => [['call' => 'getPublishingOptions', 'config' => 'throws']],
			'sync options in range' => [['call' => 'getSyncOptions', 'interval' => 7200]],
			'sync options below the minimum' => [['call' => 'getSyncOptions', 'interval' => 5]],
			'sync options above the maximum' => [['call' => 'getSyncOptions', 'interval' => 99999999]],
			'initialize, OpenRegister missing' => [['call' => 'initialize', 'args' => ['0.2.0'], 'installed' => false, 'enable' => 'throws']],
			'initialize, nothing to configure' => [['call' => 'initialize', 'args' => ['0.2.0'], 'version' => '0.3.0', 'registers' => []]],
			'initialize, configured' => [['call' => 'initialize', 'args' => ['0.2.0'], 'version' => '0.3.0', 'registers' => [['id' => 2, 'slug' => 'publication', 'schemas' => [['id' => 20, 'title' => 'catalog']]]]]],
			'initialize, enabled on demand' => [['call' => 'initialize', 'args' => ['0.2.0'], 'installed' => 'after-enable', 'version' => '0.3.0', 'registers' => []]],
			'install, no minimum' => [['call' => 'installOrUpdateOpenRegister', 'args' => [null], 'installed' => true, 'version' => '0.0.1']],
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
	public function testAnswerMatchesTheRecording(array $case): void {
		$name = $this->dataName();
		$enabled = [];
		$service = $this->service(world: $case, enabled: $enabled);

		try {
			$answer = $service->{$case['call']}(...($case['args'] ?? []));
		} catch (\Throwable $e) {
			$answer = ['thrown' => get_class($e), 'message' => $e->getMessage()];
		}

		$actual = ['answer' => $answer, 'enabled' => $enabled, 'written' => $this->written];

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

	}//end testAnswerMatchesTheRecording()

	/**
	 * The service over doubles answering from the case's world.
	 *
	 * @param array<string, mixed> $world   The case.
	 * @param array<int, string>   $enabled The apps enabled through the app manager, filled in.
	 *
	 * @return SettingsService The service.
	 */
	private function service(array $world, array &$enabled): SettingsService {
		$config = $this->createMock(IAppConfig::class);
		$stored = ($world['stored'] ?? []);
		$config->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($stored, $world): string {
				if (($world['config'] ?? null) === 'throws') {
					throw new \RuntimeException('config down');
				}

				return ($stored[$key] ?? $default);
			}
		);

		$config->method('getValueInt')->willReturnCallback(
			static fn (string $app, string $key, int $default = 0): int => ($world['interval'] ?? $default)
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->written[] = [$key, $value];
				return true;
			}
		);

		$registers = [];
		foreach (($world['registers'] ?? []) as $register) {
			$registers[] = $register;
			if (isset($register['entity']) === true) {
				$entity = $this->createMock(Register::class);
				$entity->method('jsonSerialize')->willReturn($register['entity']);
				$registers[array_key_last($registers)] = $entity;
			}
		}

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('findAll')->willReturnCallback(
			function (...$args) use ($registers): array {
				$named = [];
				foreach ((new \ReflectionMethod(RegisterMapper::class, 'findAll'))->getParameters() as $position => $parameter) {
					if (array_key_exists($position, $args) === true && ($parameter->isDefaultValueAvailable() === false || $args[$position] !== $parameter->getDefaultValue())) {
						$named[$parameter->getName()] = $args[$position];
					}
				}

				$this->written[] = ['findAll', $named];
				return $registers;
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id): object => match ($id) {
				'OCA\OpenRegister\Db\RegisterMapper' => $registerMapper,
				default => throw new \RuntimeException('no ' . $id),
			}
		);

		$apps = $this->createMock(IAppManager::class);
		$apps->method('getInstalledApps')->willReturn(($world['apps'] ?? ['openregister']));
		$apps->method('getAppVersion')->willReturn(($world['version'] ?? '0.0.0'));
		$apps->method('isInstalled')->willReturnCallback(
			static function () use ($world, &$enabled): bool {
				$installed = ($world['installed'] ?? true);
				return ($installed === 'after-enable') ? ($enabled !== []) : $installed;
			}
		);
		$apps->method('enableApp')->willReturnCallback(
			static function (string $app) use ($world, &$enabled): void {
				if (($world['enable'] ?? null) === 'throws') {
					throw new \RuntimeException('Could not find path for ' . $app);
				}

				$enabled[] = $app;
			}
		);

		return new SettingsService($config, $container, $apps, $this->createMock(LoggerInterface::class), $this->createMock(RegisterSchemaLinkService::class));

	}//end service()
}//end class
