<?php

/**
 * Every declared route must survive registration.
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
 * Nextcloud names a route after its controller, its action and its `postfix`,
 * and after nothing else. `OC\AppFramework\Routing\RouteParser::processRoute()`
 * builds `strtolower($appName . '.' . $controller . '.' . $action . $postfix)`,
 * and `RouteCollection::add()` OVERWRITES an entry of the same name.
 *
 * Neither the URL nor the verb is part of that name. So two entries that point
 * at the same controller action and carry no `postfix` are one route, and the
 * last one declared is the one that survives. Nothing warns. `routes.php` still
 * reads as though both are there.
 *
 * 🔴 THIS APP LOST 27 OF 175 ROUTES THAT WAY, all of them CORS preflights.
 * `preflightedCors()` is one method per controller and it was routed up to
 * eight times per controller, so every group collapsed to its last URL.
 * Measured against a live instance on 2026-09-19: `OPTIONS /api/status`,
 * `/api/catalogi`, `/api/listings` and `/api/service-catalogue` all answered
 * 405, while the last URL of each group answered 200. The `GET` beside each of
 * them advertises `Access-Control-Allow-Methods: PUT, POST, GET, DELETE, PATCH`,
 * and every one of those from a browser on another origin needs the preflight
 * that was refused. A simple cross-origin GET still worked, which is why this
 * sat unnoticed.
 *
 * 🔑 The unit tests could not see it. There are fifteen of them calling
 * `preflightedCors()` on a controller directly, and they all passed while
 * two thirds of the routes to that method did not exist. A method with a full
 * test suite and no reachable route answers exactly like one that works.
 *
 * So the assertion here is on the ITEM, the registration key of each entry,
 * not on the file parsing or the array being non-empty.
 */
class RouteNameUniquenessTest extends TestCase {

	/**
	 * Read the declared route entries.
	 *
	 * @return array<string, array<int, array<string, mixed>>> The route file.
	 */
	private function routeFile(): array {
		$routes = include dirname(__DIR__, 3) . '/appinfo/routes.php';
		$this->assertIsArray($routes, 'appinfo/routes.php must return an array');

		return $routes;
	}

	/**
	 * The key Nextcloud registers a route under, minus the app name.
	 *
	 * @param array<string, mixed> $route One entry from the route file.
	 *
	 * @return string The registration key.
	 */
	private function registrationKey(array $route): string {
		return strtolower($route['name'] . ($route['postfix'] ?? ''));
	}

	/**
	 * No two entries may register under the same key.
	 *
	 * @return void
	 */
	public function testEveryDeclaredRouteRegistersUnderItsOwnName(): void {
		$file = $this->routeFile();

		foreach (['routes', 'ocs'] as $section) {
			$entries = ($file[$section] ?? []);
			$seen = [];

			foreach ($entries as $entry) {
				$key = $this->registrationKey($entry);

				$this->assertArrayNotHasKey(
					$key,
					$seen,
					sprintf(
						"Two '%s' entries register as '%s', so Nextcloud keeps only the last one.\n"
						. "  kept:      %s %s\n"
						. "  OVERWRITTEN: %s %s\n"
						. "Give each entry its own 'postfix'.",
						$section,
						$key,
						$entry['verb'] ?? 'GET',
						$entry['url'] ?? '?',
						$seen[$key]['verb'] ?? 'GET',
						$seen[$key]['url'] ?? '?'
					)
				);

				$seen[$key] = $entry;
			}
		}

	}//end testEveryDeclaredRouteRegistersUnderItsOwnName()

	/**
	 * Name the preflights by URL, so a lost one is named and not just counted.
	 *
	 * These are the four that answered 405 on the live instance. Each is the
	 * first entry of a group that shared one `preflightedCors` action.
	 *
	 * @return void
	 */
	public function testThePreflightsThatWereLostAreRoutedAgain(): void {
		$entries = $this->routeFile()['routes'];

		$preflightUrls = [];
		foreach ($entries as $entry) {
			if (($entry['verb'] ?? 'GET') === 'OPTIONS') {
				$preflightUrls[] = $entry['url'];
			}
		}

		$keys = array_map([$this, 'registrationKey'], $entries);
		$this->assertSame(count($keys), count(array_unique($keys)), 'registration keys must stay unique');

		foreach (['/api/service-catalogue', '/api/status', '/api/catalogi', '/api/listings'] as $url) {
			$this->assertContains($url, $preflightUrls, sprintf('%s must keep its CORS preflight route', $url));
		}

	}//end testThePreflightsThatWereLostAreRoutedAgain()

}//end class
