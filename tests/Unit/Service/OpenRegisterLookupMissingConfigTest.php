<?php

/**
 * Tests for the app-config fallback of OpenRegisterLookup when a register setting is unset.
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

use OCA\OpenCatalogi\Service\OpenRegisterLookup;
use OCA\OpenRegister\Service\Resolver\Exception\MissingConfigException;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * An unset register or schema setting raises MissingConfigException naming that setting.
 *
 * @covers \OCA\OpenCatalogi\Service\OpenRegisterLookup
 */
class OpenRegisterLookupMissingConfigTest extends TestCase {

	/**
	 * The stored settings and the key the exception must name.
	 *
	 * @return array<string, array{0: array<string, string>, 1: string}>
	 */
	public static function unsetSettings(): array {
		return [
			'register unset' => [['notice_schema' => 's-1'], 'notice_register'],
			'schema unset' => [['notice_register' => 'r-1'], 'notice_schema'],
			'both unset' => [[], 'notice_register'],
		];

	}//end unsetSettings()

	/**
	 * The exception carries the app id and the unset key, and its message holds no server path.
	 *
	 * @param array<string, string> $stored The stored app-config values.
	 * @param string                $key    The key the exception must name.
	 *
	 * @return void
	 */
	#[DataProvider('unsetSettings')]
	public function testAnUnsetSettingNamesItsKey(array $stored, string $key): void {
		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $name, string $default = ''): string => ($stored[$name] ?? $default)
		);
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($config): mixed {
				if ($id === IAppConfig::class) {
					return $config;
				}

				throw new RuntimeException('not available: ' . $id);
			}
		);
		$lookup = new OpenRegisterLookup($container, $this->createMock(originalClassName: IAppManager::class));

		try {
			$lookup->registerConfiguration(registerKey: 'notice_register', schemaKey: 'notice_schema');
			$this->fail('No exception for an unset setting.');
		} catch (MissingConfigException $e) {
			$this->assertSame('opencatalogi', $e->getAppId());
			$this->assertSame($key, $e->getConfigKey());
			$this->assertStringContainsString($key, $e->getMessage());
			$this->assertStringNotContainsString('.php', $e->getMessage());
		}

	}//end testAnUnsetSettingNamesItsKey()
}//end class
