<?php

/**
 * Every dedicated CORS preflight route must be the one that answers its URL.
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
 * @spec openspec/specs/cross-origin-api-access/spec.md#requirement-answer-cors-preflight-requests-on-public-api-controllers-cor-001
 */

declare(strict_types=1);

namespace Unit\AppInfo;

use PHPUnit\Framework\TestCase;

/**
 * Nextcloud matches a request with Symfony's UrlMatcher, which walks the
 * routes in the order `appinfo/routes.php` declares them and takes the FIRST
 * one whose path and verb fit. A later route for the same URL is registered,
 * resolves, has a working controller method, and is never reached.
 *
 * 🔴 THAT IS WHAT HAPPENED TO 19 OF THE 29 DEDICATED PREFLIGHTS (#1912). The
 * publications wildcards `OPTIONS /api/{catalogSlug}` and
 * `/api/{catalogSlug}/{id}` were declared above them, so `OPTIONS /api/dcat`,
 * `/api/status`, `/api/publications/search`, `/api/inspections/{id}` and the
 * rest were all answered by `PublicationsController::preflightedCors()` with
 * `PUT, POST, GET, DELETE, PATCH`. Measured on an NC 35 rig on 2026-11-06:
 * `OPTIONS /api/dcat` answered that, not the `GET, OPTIONS` its own controller
 * returns. The `GET` routes already had the wildcards last; the `OPTIONS`
 * block did not.
 *
 * 🔑 Neither the controller unit tests nor RouteNameUniquenessTest could see
 * it: the first call the method directly, the second checks that each route
 * is registered, and both were true. Only the ORDER was wrong. So this test
 * replays the first-match rule over the route file itself.
 *
 * A path parameter matches its `requirements` pattern, or `[^/]+` without one
 * (Symfony's default). Sample values are picked per parameter so they satisfy
 * that pattern; a literal segment such as `/uses` only matches itself.
 */
class RouteShadowingTest extends TestCase {

	/**
	 * Values tried, in order, for a path parameter in a sample URL.
	 *
	 * @var string[]
	 */
	private const SAMPLES = ['x1', '1', 'a'];

	/**
	 * The declared OPTIONS entries, in file order.
	 *
	 * @return array<int, array<string, mixed>> The OPTIONS route entries.
	 */
	private function preflightRoutes(): array {
		$file = include dirname(__DIR__, 3) . '/appinfo/routes.php';
		$this->assertIsArray(actual: $file, message: 'appinfo/routes.php must return an array');

		return array_values(
			array_filter(
				($file['routes'] ?? []),
				static fn (array $entry): bool => ($entry['verb'] ?? 'GET') === 'OPTIONS'
			)
		);

	}//end preflightRoutes()

	/**
	 * The pattern of one path parameter.
	 *
	 * @param array<string, mixed> $route One route entry.
	 * @param string               $name  The parameter name.
	 *
	 * @return string The regular expression fragment, without delimiters.
	 */
	private function requirement(array $route, string $name): string {
		return ($route['requirements'][$name] ?? '[^/]+');

	}//end requirement()

	/**
	 * The anchored regular expression the router matches this entry's URL with.
	 *
	 * @param array<string, mixed> $route One route entry.
	 *
	 * @return string The regular expression.
	 */
	private function pattern(array $route): string {
		$parts = preg_split('/(\{[A-Za-z_]+\})/', $route['url'], -1, PREG_SPLIT_DELIM_CAPTURE);
		$regex = '';
		foreach ($parts as $part) {
			if (preg_match('/^\{([A-Za-z_]+)\}$/', $part, $m) === 1) {
				$regex .= '(' . $this->requirement(route: $route, name: $m[1]) . ')';
				continue;
			}

			$regex .= preg_quote($part, '#');
		}

		return '#^' . $regex . '$#';

	}//end pattern()

	/**
	 * A concrete URL this entry answers, with each parameter filled in.
	 *
	 * @param array<string, mixed> $route One route entry.
	 *
	 * @return string The sample path.
	 */
	private function samplePath(array $route): string {
		return preg_replace_callback(
			'/\{([A-Za-z_]+)\}/',
			function (array $m) use ($route): string {
				foreach (self::SAMPLES as $sample) {
					if (preg_match('#^(?:' . $this->requirement(route: $route, name: $m[1]) . ')$#', $sample) === 1) {
						return $sample;
					}
				}

				$this->fail(message: sprintf("No sample value fits {%s} of %s; extend SAMPLES", $m[1], $route['url']));
			},
			$route['url']
		);

	}//end samplePath()

	/**
	 * The entry the router picks for a path: the first OPTIONS entry that fits.
	 *
	 * @param array<int, array<string, mixed>> $routes The OPTIONS entries in file order.
	 * @param string                           $path   The request path.
	 *
	 * @return array<string, mixed>|null The winning entry, or null if none fits.
	 */
	private function firstMatch(array $routes, string $path): ?array {
		foreach ($routes as $route) {
			if (preg_match($this->pattern(route: $route), $path) === 1) {
				return $route;
			}
		}

		return null;

	}//end firstMatch()

	/**
	 * Every OPTIONS route reaches its own controller method.
	 *
	 * Two entries that name the same `controller#action` answer identically,
	 * so `/api/listings/{id}` answering `/api/listings/sync` is no shadowing:
	 * both are `listings#preflightedCors`. What may not happen is that the
	 * answer comes from another controller.
	 *
	 * @return void
	 */
	public function testEveryPreflightIsAnsweredByItsOwnController(): void {
		$routes = $this->preflightRoutes();
		$this->assertNotEmpty(actual: $routes, message: 'routes.php declares no OPTIONS routes');

		$shadowed = [];
		foreach ($routes as $route) {
			$path = $this->samplePath(route: $route);
			$winner = $this->firstMatch(routes: $routes, path: $path);

			$this->assertNotNull(
				actual: $winner,
				message: sprintf('%s does not even match its own sample path %s', $route['url'], $path)
			);
			if ($winner['name'] !== $route['name']) {
				$shadowed[] = sprintf('  OPTIONS %s (%s) is answered by %s (%s)', $route['url'], $route['name'], $winner['name'], $winner['url']);
			}
		}

		$this->assertSame(
			expected: [],
			actual: $shadowed,
			message: "These preflights never reach their own controller, because an earlier OPTIONS route matches first.\n"
			. implode("\n", $shadowed)
			. "\nDeclare wildcard routes such as /api/{catalogSlug} after every specific route."
		);

	}//end testEveryPreflightIsAnsweredByItsOwnController()

	/**
	 * Name the preflights whose answer differs from the publications one.
	 *
	 * These six were narrowed after the wildcards had already shadowed them,
	 * so the narrowing had no effect until the order was fixed. Naming them
	 * means a regression reports the URL, not just a count.
	 *
	 * @return void
	 */
	public function testTheNarrowedPreflightsReachTheirController(): void {
		$routes = $this->preflightRoutes();
		$expected = [
			'/api/dcat'                => 'dcat#preflightedCors',
			'/api/status'              => 'community#preflightedCors',
			'/api/feeds/x1'            => 'community#preflightedCors',
			'/api/markup/render'       => 'community#preflightedCors',
			'/api/publications/search' => 'publicationRules#preflightedCors',
			'/api/inspections/1'       => 'inspection#preflightedCors',
		];

		foreach ($expected as $path => $name) {
			$winner = $this->firstMatch(routes: $routes, path: $path);
			$this->assertNotNull(actual: $winner, message: sprintf('no OPTIONS route matches %s', $path));
			$this->assertSame(
				expected: $name,
				actual: $winner['name'],
				message: sprintf('OPTIONS %s must be answered by %s', $path, $name)
			);
		}

	}//end testTheNarrowedPreflightsReachTheirController()

}//end class
