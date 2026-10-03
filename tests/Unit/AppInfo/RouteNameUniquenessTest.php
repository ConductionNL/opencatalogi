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

	/**
	 * Every declared route must point at a method that exists and is public.
	 *
	 * The other half of the same failure. A route whose action no longer
	 * exists on the named controller is a ReflectionException 500 at request
	 * time, and nothing before that says so: `routes.php` parses, the class
	 * loads, the unit tests of the method that DID move stay green in their
	 * new home, and only a real request finds it.
	 *
	 * This is what a controller split needs. Moving an action from
	 * `CommunityController` to `NoticeBoardController` renames its route from
	 * `community#banners` to `noticeBoard#banners`, and the entry in
	 * `routes.php` has to move with it or the URL answers 500 instead of
	 * answering at all.
	 *
	 * The class name is built the way `OC\AppFramework\Routing\RouteParser`
	 * builds it: the controller segment, underscores to word boundaries,
	 * ucwords, plus `Controller`.
	 *
	 * @return void
	 */
	public function testEveryDeclaredRoutePointsAtAMethodThatExists(): void {
		$file = $this->routeFile();

		foreach (['routes', 'ocs'] as $section) {
			foreach (($file[$section] ?? []) as $entry) {
				[$controller, $action] = explode('#', $entry['name']);

				// A controller segment carrying a namespace is a container
				// ALIAS, not an autoloadable class: the dashboard entries name
				// OCA\OpenCatalogi\AppHost\Controller\GenericDashboardController,
				// which Application.php registers as a factory over
				// OpenRegister's class and which has no file in this repo.
				// Reflection cannot see it, so it is out of scope here.
				if (str_contains($controller, '\\') === true) {
					continue;
				}

				$class = 'OCA\\OpenCatalogi\\Controller\\'
					. str_replace(' ', '', ucwords(str_replace('_', ' ', $controller)))
					. 'Controller';

				$this->assertTrue(
					class_exists($class),
					sprintf("Route '%s' names a controller class that does not exist: %s", $entry['name'], $class)
				);
				$this->assertTrue(
					method_exists($class, $action),
					sprintf("Route '%s' points at %s::%s(), which does not exist (500 at request time)", $entry['name'], $class, $action)
				);
				$this->assertTrue(
					(new \ReflectionMethod($class, $action))->isPublic(),
					sprintf("Route '%s' points at %s::%s(), which is not public", $entry['name'], $class, $action)
				);
			}
		}

	}//end testEveryDeclaredRoutePointsAtAMethodThatExists()

}//end class
