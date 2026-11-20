<?php

/**
 * Unit tests for the standard protocol paths that keep their own URL.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Middleware\Support
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://www.OpenCatalogi.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Middleware\Support;

use OCA\OpenCatalogi\Middleware\Support\StandardProtocolPaths;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Paths are made relative to the app root, and only the standard protocol
 * paths match, whatever prefix the request carried.
 *
 * @covers \OCA\OpenCatalogi\Middleware\Support\StandardProtocolPaths
 */
class StandardProtocolPathsTest extends TestCase {

	/**
	 * Paths and their app-relative form.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function relativePaths(): array {
		return [
			'already relative'      => ['/api/health', '/api/health'],
			'no leading slash'      => ['api/health', '/api/health'],
			'many leading slashes'  => ['///api/health', '/api/health'],
			'app root'              => ['/apps/opencatalogi/api/health', '/api/health'],
			'request path'          => ['/index.php/apps/opencatalogi/api/feeds/x', '/api/feeds/x'],
			'app root without tail' => ['/apps/opencatalogi', '/apps/opencatalogi'],
			'other app'             => ['/apps/openregister/api/health', '/apps/openregister/api/health'],
			'prefix of the app id'  => ['/apps/opencatalogix/api/health', '/apps/opencatalogix/api/health'],
		];
	}//end relativePaths()

	/**
	 * A path is cut after the first `/apps/opencatalogi/` and given one leading slash.
	 *
	 * @param string $path     The path.
	 * @param string $expected The app-relative path.
	 *
	 * @return void
	 */
	#[DataProvider('relativePaths')]
	public function testAppRelative(string $path, string $expected): void {
		$this->assertSame($expected, StandardProtocolPaths::appRelative($path));
	}//end testAppRelative()

	/**
	 * Paths of every standard protocol, and look-alikes that are not one.
	 *
	 * @return array<string,array{0:string,1:bool}>
	 */
	public static function protocolPaths(): array {
		return [
			'sitemap'                => ['/api/woo/sitemaps/1', true],
			'sitemap without family' => ['/api/sitemaps/1', false],
			'robots'                 => ['/api/robots.txt', true],
			'robots look-alike'      => ['/api/robotsxtxt', false],
			'robots with tail'       => ['/api/robots.txt/x', false],
			'dcat root'              => ['/api/dcat', true],
			'dcat below'             => ['/api/dcat/datasets', true],
			'catalog dcat'           => ['/api/catalogs/main/dcat', true],
			'dcat look-alike'        => ['/api/dcatalog', false],
			'ooapi'                  => ['/api/catalogs/main/ooapi/courses', true],
			'ooapi without catalog'  => ['/api/ooapi/courses', false],
			'schema.org'             => ['/api/catalogs/main/schema', true],
			'schema.org with tail'   => ['/api/catalogs/main/schema/x', false],
			'feeds'                  => ['/api/feeds/news', true],
			'federation'             => ['/api/federation/publications', true],
			'health'                 => ['/api/health', true],
			'health with tail'       => ['/api/health/deep', false],
			'metrics'                => ['/api/metrics', true],
			'versioned api'          => ['/api/v1/health', false],
			'ordinary api'           => ['/api/publications', false],
			'page'                   => ['/things', false],
			'request path'           => ['/index.php/apps/opencatalogi/api/health', true],
			'other app'              => ['/apps/openregister/api/health', false],
		];
	}//end protocolPaths()

	/**
	 * Only a standard protocol path matches, after the app root is stripped.
	 *
	 * @param string $path     The path.
	 * @param bool   $expected Whether it is a standard protocol path.
	 *
	 * @return void
	 */
	#[DataProvider('protocolPaths')]
	public function testMatches(string $path, bool $expected): void {
		$this->assertSame($expected, StandardProtocolPaths::matches($path));
	}//end testMatches()

	/**
	 * Every pattern is a valid expression anchored at the start of the path.
	 *
	 * @return void
	 */
	public function testEveryPatternIsAnchoredAtTheStart(): void {
		$this->assertCount(9, StandardProtocolPaths::PATTERNS);
		foreach (StandardProtocolPaths::PATTERNS as $label => $pattern) {
			$this->assertStringStartsWith('#^/api/', $pattern, $label);
			$this->assertNotFalse(@preg_match($pattern, ''), $label);
		}
	}//end testEveryPatternIsAnchoredAtTheStart()
}//end class
