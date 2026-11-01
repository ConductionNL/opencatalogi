<?php

/**
 * Golden test for InitializeSettings::run().
 *
 * Every case runs the step over recording doubles and compares every output,
 * log and lookup call, in order, to the recording in
 * fixtures/initialize-settings-golden.json.
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

namespace Unit\Repair;

use OCA\OpenCatalogi\Contract\SettingsServiceInterface;
use OCA\OpenCatalogi\Repair\InitializeSettings;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Golden test for the install-time settings import.
 *
 * @covers \OCA\OpenCatalogi\Repair\InitializeSettings
 */
class InitializeSettingsGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/initialize-settings-golden.json';

	/**
	 * Everything asked and said, in order.
	 *
	 * @var array<int, mixed>
	 */
	private array $calls = [];

	/**
	 * The recorded worlds.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function worlds(): array {
		$full = ['registers' => ['r1', 'r2'], 'schemas' => ['s1', 's2', 's3'], 'objects' => ['o1']];

		return [
			'openregister missing' => [['apps' => ['opencatalogi']]],
			'imported and configured' => [['result' => $full, 'configured' => '12']],
			'imported, nothing configured' => [['result' => $full, 'configured' => '']],
			'an empty result' => [['result' => [], 'configured' => '12']],
			'the import throws' => [['fail' => 'load', 'configured' => '']],
			'the service is unresolvable' => [['fail' => 'container', 'configured' => '12']],
			'an error, not an exception' => [['fail' => 'error', 'configured' => '12']],
			'a plain exception' => [['fail' => 'plain', 'configured' => '12']],
		];

	}//end worlds()

	/**
	 * Replay a world and compare it to the recording.
	 *
	 * @param array<string, mixed> $world The world.
	 *
	 * @return void
	 */
	#[DataProvider('worlds')]
	public function testRunMatchesTheRecording(array $world): void {
		$name = $this->dataName();
		try {
			$this->step(world: $world)->run($this->recordingOutput());
		} catch (\Throwable $e) {
			$this->calls[] = ['thrown' => get_class($e) . ': ' . $e->getMessage()];
		}

		$recorded = [];
		if (file_exists(self::FIXTURE) === true) {
			$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$name] = $this->calls;
			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $recorded, 'No recording for ' . $name);
		$this->assertSame($recorded[$name], json_decode((string)json_encode($this->calls), true));

	}//end testRunMatchesTheRecording()

	/**
	 * An output that records every call.
	 *
	 * @return IOutput
	 */
	private function recordingOutput(): IOutput {
		$output = $this->createMock(IOutput::class);
		foreach (['info', 'warning', 'startProgress', 'advance', 'finishProgress'] as $method) {
			$output->method($method)->willReturnCallback(
				function (mixed ...$args) use ($method): void {
					$this->calls[] = [$method => $args];
				}
			);
		}

		return $output;

	}//end recordingOutput()

	/**
	 * The step over a world.
	 *
	 * @param array<string, mixed> $world The world.
	 *
	 * @return InitializeSettings
	 */
	private function step(array $world): InitializeSettings {
		$fail = ($world['fail'] ?? '');

		$apps = $this->createMock(IAppManager::class);
		$apps->method('getInstalledApps')->willReturn($world['apps'] ?? ['opencatalogi', 'openregister']);

		$settings = $this->createMock(SettingsServiceInterface::class);
		$settings->method('loadSettings')->willReturnCallback(
			function (bool $force = false) use ($world, $fail): array {
				$this->calls[] = ['loadSettings' => $force];
				if ($fail === 'load') {
					throw new \RuntimeException('import refused');
				}

				if ($fail === 'plain') {
					throw new \Exception('import stopped');
				}

				if ($fail === 'error') {
					throw new \Error('import crashed');
				}

				return ($world['result'] ?? []);
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($settings, $fail): object {
				$this->calls[] = ['container' => $id];
				if ($fail === 'container') {
					throw new \RuntimeException('not bound: ' . $id);
				}

				return $settings;
			}
		);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') use ($world): string {
				$this->calls[] = ['getValueString' => [$app, $key, $default]];
				return ($world['configured'] ?? '');
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context = []): void {
				if (isset($context['exception']) === true) {
					$context['exception'] = get_class($context['exception']) . ': ' . $context['exception']->getMessage();
				}

				$this->calls[] = ['error' => [$message, $context]];
			}
		);

		return new InitializeSettings($apps, $container, $config, $logger);

	}//end step()
}//end class
