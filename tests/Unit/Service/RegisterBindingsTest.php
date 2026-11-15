<?php

/**
 * Unit tests for RegisterBindings.
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

use OCA\OpenCatalogi\Service\RegisterBindings;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Configured ids are read from the opencatalogi app, and an empty value is no id.
 *
 * @covers \OCA\OpenCatalogi\Service\RegisterBindings
 */
class RegisterBindingsTest extends TestCase {

	/**
	 * Ids come from the opencatalogi app config; unset and empty answer null.
	 *
	 * @return void
	 */
	public function testConfiguredIdsAreReadFromTheOpencatalogiApp(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default): string => ($app === 'opencatalogi' && $key === 'set_key') ? '42' : $default
		);
		$bindings = new RegisterBindings($config, $this->createMock(ContainerInterface::class));

		$this->assertSame('42', $bindings->configuredId('set_key'));
		$this->assertNull($bindings->configuredId('unset_key'));

	}//end testConfiguredIdsAreReadFromTheOpencatalogiApp()
}//end class
