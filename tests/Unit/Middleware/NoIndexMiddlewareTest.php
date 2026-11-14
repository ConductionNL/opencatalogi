<?php

/**
 * Unit tests for the no-index middleware.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Middleware
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://www.OpenCatalogi.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Middleware;

use OCA\OpenCatalogi\Middleware\NoIndexMiddleware;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Response;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Pins which OpenCatalogi responses carry `X-Robots-Tag: noindex, nofollow` (REQ-WAB-003).
 *
 * @covers \OCA\OpenCatalogi\Middleware\NoIndexMiddleware
 */
class NoIndexMiddlewareTest extends TestCase {

	/**
	 * Run the middleware for a method of the fixture controller.
	 *
	 * @param string      $setting    The stored `search_indexing` value.
	 * @param string      $methodName The answering controller method.
	 * @param object|null $controller The answering controller, the fixture by default.
	 *
	 * @return array<string, string> The response headers.
	 */
	private function headersFor(string $setting, string $methodName, ?object $controller = null): array {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->expects($this->once())
			->method('getValueString')
			->with('opencatalogi', 'search_indexing', 'allowed')
			->willReturn($setting);

		$controller ??= new NoIndexFixtureController();
		$response = (new NoIndexMiddleware(appConfig: $appConfig))
			->afterController(controller: $controller, methodName: $methodName, response: new Response());

		return $this->headersOf(response: $response);
	}//end headersFor()

	/**
	 * The headers the response itself carries, without the core defaults getHeaders() merges in.
	 *
	 * @param Response $response The response.
	 *
	 * @return array<string, string> The explicitly set headers.
	 */
	private function headersOf(Response $response): array {
		return (new ReflectionProperty(Response::class, 'headers'))->getValue($response);
	}//end headersOf()

	/**
	 * An officer method answers noindex on a default install.
	 */
	public function testANonPublicResponseCarriesNoindex(): void {
		$headers = $this->headersFor(setting: 'allowed', methodName: 'officer');

		$this->assertSame('noindex, nofollow', $headers['X-Robots-Tag'] ?? null);
	}//end testANonPublicResponseCarriesNoindex()

	/**
	 * A blocked install marks a public page too, and still lets it answer.
	 */
	public function testBlockedIndexingMarksEveryResponse(): void {
		$attribute = $this->headersFor(setting: 'blocked', methodName: 'publicByAttribute');
		$docblock  = $this->headersFor(setting: 'blocked', methodName: 'publicByDocblock');

		$this->assertSame('noindex, nofollow', $attribute['X-Robots-Tag'] ?? null);
		$this->assertSame('noindex, nofollow', $docblock['X-Robots-Tag'] ?? null);
	}//end testBlockedIndexingMarksEveryResponse()

	/**
	 * A public page, by attribute or by docblock, is untouched on a default install.
	 */
	public function testAPublicResponseIsUntouchedByDefault(): void {
		$attribute = $this->headersFor(setting: 'allowed', methodName: 'publicByAttribute');
		$docblock  = $this->headersFor(setting: 'allowed', methodName: 'publicByDocblock');

		$this->assertArrayNotHasKey('X-Robots-Tag', $attribute);
		$this->assertArrayNotHasKey('X-Robots-Tag', $docblock);
	}//end testAPublicResponseIsUntouchedByDefault()

	/**
	 * A method that cannot be read, or no controller at all, fails closed to noindex.
	 */
	public function testAnUnreadableMethodCarriesNoindex(): void {
		$missing = $this->headersFor(setting: 'allowed', methodName: 'doesNotExist');
		$this->assertSame('noindex, nofollow', $missing['X-Robots-Tag'] ?? null);

		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('allowed');
		$headers = (new NoIndexMiddleware(appConfig: $appConfig))
			->afterController(controller: null, methodName: 'any', response: new Response());
		$headers = $this->headersOf(response: $headers);
		$this->assertSame('noindex, nofollow', $headers['X-Robots-Tag'] ?? null);
	}//end testAnUnreadableMethodCarriesNoindex()

	/**
	 * An unknown setting value is not `blocked`, so a public page stays indexable.
	 */
	public function testAnUnknownSettingLeavesThePublicSideOpen(): void {
		$headers = $this->headersFor(setting: 'something-else', methodName: 'publicByAttribute');

		$this->assertArrayNotHasKey('X-Robots-Tag', $headers);
	}//end testAnUnknownSettingLeavesThePublicSideOpen()
}//end class

/**
 * A controller with one officer method and two public ones.
 */
class NoIndexFixtureController {

	/**
	 * An officer method.
	 *
	 * @return void
	 */
	public function officer(): void {
	}//end officer()

	/**
	 * A public page declared by attribute.
	 *
	 * @return void
	 */
	#[PublicPage]
	public function publicByAttribute(): void {
	}//end publicByAttribute()

	/**
	 * A public page declared in the docblock.
	 *
	 * @PublicPage
	 *
	 * @return void
	 */
	public function publicByDocblock(): void {
	}//end publicByDocblock()
}//end class
