<?php

/**
 * Golden test for `ServiceRegistrar::register()`.
 *
 * Records every registration call in order (alias or factory, name, target)
 * and compares it with the recording, so a binding that goes missing, changes
 * target or moves before the factory it depends on fails here.
 *
 * Re-record (only when the bindings are meant to change) with
 * `GOLDEN_RECORD=1 vendor/bin/phpunit -c phpunit-unit.xml tests/Unit/Service/ServiceRegistrarGoldenTest.php`.
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
 * @covers \OCA\OpenCatalogi\Service\ServiceRegistrar
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Service;

use OCA\OpenCatalogi\Service\ServiceRegistrar;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * Golden test for ServiceRegistrar::register().
 */
class ServiceRegistrarGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../fixtures/service-registrar-golden.json';

	/**
	 * Every registration, in order, matches the recording.
	 *
	 * @return void
	 */
	public function testRegistrationsMatchTheRecording(): void {
		$calls = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerServiceAlias')->willReturnCallback(
			static function (string $alias, string $target) use (&$calls): void {
				$calls[] = ['alias', $alias, $target];
			}
		);
		$context->method('registerService')->willReturnCallback(
			static function (string $name, callable $factory, bool $shared = true) use (&$calls): void {
				$calls[] = ['service', $name, $shared];
			}
		);

		(new ServiceRegistrar(appName: 'opencatalogi'))->register($context);

		if (getenv('GOLDEN_RECORD') === '1') {
			file_put_contents(self::FIXTURE, json_encode($calls, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertFileExists(self::FIXTURE, 'No recording.');
		$this->assertSame(
			(string)file_get_contents(self::FIXTURE),
			json_encode($calls, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
		);

	}//end testRegistrationsMatchTheRecording()
}//end class
