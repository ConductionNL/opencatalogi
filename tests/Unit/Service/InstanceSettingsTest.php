<?php

/**
 * Unit tests for InstanceSettings.
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

use OCA\OpenCatalogi\Service\InstanceSettings;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * The base URL loses its trailing slash, and config values are read from the opencatalogi app.
 *
 * @covers \OCA\OpenCatalogi\Service\InstanceSettings
 */
class InstanceSettingsTest extends TestCase {

	/**
	 * Build the settings over a base URL and a config that answers only for opencatalogi.
	 *
	 * @param string $baseUrl The base URL the URL generator returns.
	 *
	 * @return InstanceSettings The settings.
	 */
	private function settings(string $baseUrl): InstanceSettings {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getBaseUrl')->willReturn($baseUrl);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default): string => $app === 'opencatalogi' ? 'set:' . $key : $default
		);
		$config->method('getValueInt')->willReturnCallback(
			static fn (string $app, string $key, int $default): int => $app === 'opencatalogi' ? 42 : $default
		);

		return new InstanceSettings($urls, $config);
	}//end settings()

	/**
	 * The base URL comes back without its trailing slash.
	 *
	 * @return void
	 */
	public function testBaseUrlDropsTheTrailingSlash(): void {
		$this->assertSame('https://host/sub', $this->settings('https://host/sub/')->baseUrl());
		$this->assertSame('https://host', $this->settings('https://host')->baseUrl());

	}//end testBaseUrlDropsTheTrailingSlash()

	/**
	 * String and integer values are read from the opencatalogi app config.
	 *
	 * @return void
	 */
	public function testValuesAreReadFromTheOpencatalogiApp(): void {
		$settings = $this->settings('https://host');

		$this->assertSame('set:dcat_publisher_name', $settings->stringValue('dcat_publisher_name', 'fallback'));
		$this->assertSame(42, $settings->intValue('sitemap_max_per_page', 1000));

	}//end testValuesAreReadFromTheOpencatalogiApp()
}//end class
