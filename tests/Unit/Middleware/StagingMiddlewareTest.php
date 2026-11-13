<?php

/**
 * Unit tests for StagingMiddleware (REQ-STG-003).
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Middleware
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/instance-staging-mode
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Middleware;

use OCA\OpenCatalogi\Controller\RobotsController;
use OCA\OpenCatalogi\Middleware\StagingMiddleware;
use OCA\OpenCatalogi\Service\InstanceMode;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

/**
 * Every public route answers an anonymous caller 503 in staging; a signed-in
 * user and production pass through.
 *
 * @covers \OCA\OpenCatalogi\Middleware\StagingMiddleware
 * @uses   \OCA\OpenCatalogi\Service\InstanceMode
 */
class StagingMiddlewareTest extends TestCase {

	/**
	 * The middleware for a mode and a caller.
	 *
	 * @param string $mode     The stored instance mode.
	 * @param bool   $signedIn Whether a user is signed in.
	 *
	 * @return StagingMiddleware
	 */
	private function middleware(string $mode, bool $signedIn): StagingMiddleware {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn($mode);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn === true ? $this->createMock(IUser::class) : null);

		return new StagingMiddleware(new InstanceMode($config), $session);
	}//end middleware()

	/**
	 * Every public controller method named in appinfo/routes.php, as [instance, method].
	 *
	 * @return array<string, array{0: object, 1: string}>
	 */
	private static function publicRoutes(): array {
		$routes = require __DIR__ . '/../../../appinfo/routes.php';
		$public = [];
		foreach ($routes['routes'] as $route) {
			[$controller, $method] = explode('#', (string)$route['name']);
			$class = $controller;
			if (str_contains($controller, '\\') === false) {
				$class = 'OCA\\OpenCatalogi\\Controller\\' . str_replace('_', '', ucwords($controller, '_')) . 'Controller';
			}

			if (class_exists($class) === false || method_exists($class, $method) === false) {
				continue;
			}

			$reflection = new ReflectionMethod($class, $method);
			if ($reflection->getAttributes(PublicPage::class) === [] && str_contains((string)$reflection->getDocComment(), '@PublicPage') === false) {
				continue;
			}

			$public[$class . '::' . $method] = [(new ReflectionClass($class))->newInstanceWithoutConstructor(), $method];
		}

		return $public;
	}//end publicRoutes()

	/**
	 * Every public route but robots.txt answers 503, noindex and `{error: staging}`.
	 *
	 * @return void
	 */
	public function testEveryPublicRouteAnswers503ToAnonymousInStaging(): void {
		$middleware = $this->middleware('staging', false);
		$routes = self::publicRoutes();
		$this->assertGreaterThan(10, count($routes), 'the route table was read');

		foreach ($routes as $name => [$controller, $method]) {
			if ($controller instanceof RobotsController) {
				$middleware->beforeController($controller, $method);
				continue;
			}

			try {
				$middleware->beforeController($controller, $method);
				$this->fail($name . ' answered an anonymous caller in staging');
			} catch (RuntimeException $e) {
				$response = $middleware->afterException($controller, $method, $e);
				$this->assertInstanceOf(JSONResponse::class, $response, $name);
				$this->assertSame(503, $response->getStatus(), $name);
				$this->assertSame(['error' => 'staging'], $response->getData(), $name);
				$this->assertSame('noindex, nofollow', $response->getHeaders()['X-Robots-Tag'], $name);
			}
		}
	}//end testEveryPublicRouteAnswers503ToAnonymousInStaging()

	/**
	 * A signed-in user sees the public view in staging.
	 *
	 * @return void
	 */
	public function testASignedInUserSeesThePublicView(): void {
		$middleware = $this->middleware('staging', true);

		foreach (self::publicRoutes() as [$controller, $method]) {
			$middleware->beforeController($controller, $method);
		}

		$this->addToAssertionCount(count(self::publicRoutes()));
	}//end testASignedInUserSeesThePublicView()

	/**
	 * Production is untouched, and a non-public route is never closed.
	 *
	 * @return void
	 */
	public function testProductionIsUntouched(): void {
		$production = $this->middleware('production', false);
		foreach (self::publicRoutes() as [$controller, $method]) {
			$production->beforeController($controller, $method);
		}

		$staging = $this->middleware('staging', false);
		$staging->beforeController(new \stdClass(), 'missing');
		$this->addToAssertionCount(1);
	}//end testProductionIsUntouched()

	/**
	 * Any other exception is rethrown unchanged.
	 *
	 * @return void
	 */
	public function testOtherExceptionsAreRethrown(): void {
		$failure = new RuntimeException('something else');

		$this->expectExceptionObject($failure);
		$this->middleware('staging', false)->afterException(new \stdClass(), 'x', $failure);
	}//end testOtherExceptionsAreRethrown()
}//end class
