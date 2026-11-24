<?php

/**
 * The anonymous boundary at the route table (woo-access-boundary-hardening REQ-WAB-002).
 *
 * Decision (2.2): whether a public route returns a non-public record is decided by
 * OpenRegister's authorization rules, which a unit test can only mock; that half runs over
 * HTTP against the real rules in `tests/e2e/public-boundary.spec.ts` with the CI seed's
 * fixtures. This test holds the half the route table decides: which routes an anonymous
 * caller can reach at all. Every route resolves to a real controller method, and the set of
 * routes whose method carries `#[PublicPage]` (or `@PublicPage`) is exactly the reviewed list
 * in `public-routes.txt`, so a route cannot become public, and a public route cannot be
 * dropped, without that list changing in the same review.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Boundary
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/woo-access-boundary-hardening/tasks.md#task-2.2
 */

declare(strict_types=1);

namespace Unit\Boundary;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Reads appinfo/routes.php and the controllers' public markers.
 *
 * @spec openspec/changes/woo-access-boundary-hardening/tasks.md#task-2.2
 */
class PublicBoundaryTest extends TestCase {

	/**
	 * The reviewed list of public routes, one `VERB url name` per line.
	 */
	private const LIST = __DIR__ . '/public-routes.txt';

	/**
	 * Every route of the table, the versioned aliases included.
	 *
	 * @return array<int, array{name: string, url: string, verb: string}>
	 */
	public static function routes(): array {
		$table = include dirname(__DIR__, 3) . '/appinfo/routes.php';
		$routes = [];
		foreach (['routes', 'ocs'] as $section) {
			foreach (($table[$section] ?? []) as $route) {
				$routes[] = [
					'name' => (string)$route['name'],
					'url' => (string)$route['url'],
					'verb' => strtoupper((string)($route['verb'] ?? 'GET')),
				];
			}
		}

		return $routes;
	}//end routes()

	/**
	 * The controller class and method a route name points at.
	 *
	 * @param string $name The route name, `controller#method`.
	 *
	 * @return array{0: string, 1: string}
	 */
	public static function target(string $name): array {
		[$controller, $method] = explode('#', $name, 2);
		if (str_starts_with($controller, 'OCA\\') === true) {
			return [$controller . 'Controller', $method];
		}

		return ['OCA\\OpenCatalogi\\Controller\\' . ucfirst($controller) . 'Controller', $method];
	}//end target()

	/**
	 * Whether a controller method is reachable without a login.
	 *
	 * @param ReflectionMethod $method The method.
	 *
	 * @return bool
	 */
	public static function isPublic(ReflectionMethod $method): bool {
		foreach ($method->getAttributes() as $attribute) {
			if (str_ends_with($attribute->getName(), '\\PublicPage') === true) {
				return true;
			}
		}

		return preg_match('/@PublicPage\b/', (string)$method->getDocComment()) === 1;
	}//end isPublic()

	/**
	 * The public routes as `VERB url name` lines, sorted, for the routes whose method exists.
	 *
	 * @return array<int, string>
	 */
	public static function publicRoutes(): array {
		$lines = [];
		foreach (self::routes() as $route) {
			[$class, $method] = self::target(name: $route['name']);
			if (method_exists($class, $method) === false) {
				continue;
			}

			if (self::isPublic(method: new ReflectionMethod($class, $method)) === true) {
				$lines[] = $route['verb'] . ' ' . $route['url'] . ' ' . $route['name'];
			}
		}

		$lines = array_values(array_unique($lines));
		sort($lines);

		return $lines;
	}//end publicRoutes()

	/**
	 * The route list comes from the route table and every route reaches a real method.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-access-boundary-hardening/tasks.md#task-2.2
	 */
	public function testTheRouteListIsReadFromTheRouteTable(): void {
		$routes = self::routes();
		$this->assertGreaterThan(expected: 100, actual: count($routes), message: 'the whole route table is read');

		$missing = [];
		foreach ($routes as $route) {
			[$class, $method] = self::target(name: $route['name']);
			if (method_exists($class, $method) === false) {
				$missing[] = $route['name'];
			}
		}

		// A route without its method is a 500 for everyone; GenericDashboard comes from OpenRegister's AppHost.
		$missing = array_values(array_filter($missing, static fn (string $name): bool => str_contains($name, '\\AppHost\\') === false));
		$this->assertSame(expected: [], actual: array_values(array_unique($missing)), message: 'every route reaches a controller method');
	}//end testTheRouteListIsReadFromTheRouteTable()

	/**
	 * Exactly the reviewed routes are open to an anonymous caller; every other route asks for a login.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-access-boundary-hardening/tasks.md#task-2.2
	 */
	public function testEveryNonPublicRouteRefusesAnonymous(): void {
		$lines = array_map('trim', (array)file(self::LIST));
		$reviewed = array_values(
			array_filter($lines, static fn (string $line): bool => $line !== '' && str_starts_with($line, '#') === false)
		);

		$this->assertSame(
			expected: $reviewed,
			actual: self::publicRoutes(),
			message: 'A route became public or stopped being public. Review it against REQ-WAB-002 '
				. 'and update tests/Unit/Boundary/public-routes.txt in the same change.'
		);
	}//end testEveryNonPublicRouteRefusesAnonymous()

	/**
	 * A method without the marker is not public, a method with it is.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-access-boundary-hardening/tasks.md#task-2.2
	 */
	public function testTheMarkerDecidesWhatIsPublic(): void {
		$probe = new class {
			/**
			 * Open.
			 *
			 * @return void
			 */
			#[\OCP\AppFramework\Http\Attribute\PublicPage]
			public function open(): void {
			}//end open()

			/**
			 * Open by annotation.
			 *
			 * @PublicPage
			 *
			 * @return void
			 */
			public function annotated(): void {
			}//end annotated()

			/**
			 * Closed.
			 *
			 * @return void
			 */
			public function closed(): void {
			}//end closed()
		};

		$this->assertTrue(condition: self::isPublic(method: new ReflectionMethod($probe, 'open')));
		$this->assertTrue(condition: self::isPublic(method: new ReflectionMethod($probe, 'annotated')));
		$this->assertFalse(condition: self::isPublic(method: new ReflectionMethod($probe, 'closed')));
	}//end testTheMarkerDecidesWhatIsPublic()
}//end class
