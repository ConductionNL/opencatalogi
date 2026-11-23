<?php

/**
 * The catalogue-wildcard routes come after every named API route.
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
 * @spec openspec/changes/publications/tasks.md#task-2
 */

declare(strict_types=1);

namespace Unit\AppInfo;

use PHPUnit\Framework\TestCase;

/**
 * `/api/{catalogSlug}` matches any single segment, so a named route such as
 * `/api/themes` declared after it is answered as a catalogue called "themes"
 * (REQ-PUB-012). This pins the order in appinfo/routes.php, for the unversioned
 * routes and for their `/api/v1/` aliases (see ApiPathAliases), per family.
 */
class PublicationsRouteOrderTest extends TestCase {

	/** The route families: the unversioned API and its `/api/v1/` aliases. */
	private const FAMILIES = ['/api/v1/', '/api/'];

	/**
	 * The registered `routes` entries, aliases included, in registration order.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function routes(): array {
		$file = include dirname(__DIR__, 3) . '/appinfo/routes.php';
		$this->assertIsArray($file);

		return array_values($file['routes'] ?? []);
	}

	/**
	 * Whether a URL belongs to a family.
	 *
	 * @param string $url    The route URL.
	 * @param string $family The family prefix.
	 *
	 * @return bool
	 */
	private static function inFamily(string $url, string $family): bool {
		if (str_starts_with($url, $family) === false) {
			return false;
		}

		return $family !== '/api/' || str_starts_with($url, '/api/v1/') === false;
	}

	/**
	 * Whether a URL starts with a family's catalogue wildcard segment.
	 *
	 * @param string $url    The route URL.
	 * @param string $family The family prefix.
	 *
	 * @return bool
	 */
	private static function isCatalogWildcard(string $url, string $family): bool {
		return str_starts_with($url, $family . '{catalogSlug}');
	}

	/**
	 * The positions of a family's routes, and of its first `publications#` wildcard route.
	 *
	 * @param array<int, array<string, mixed>> $routes The routes.
	 * @param string                           $family The family prefix.
	 *
	 * @return array{first: int, members: array<int, int>}
	 */
	private function family(array $routes, string $family): array {
		$first = null;
		$members = [];
		foreach ($routes as $index => $route) {
			$url = (string)$route['url'];
			if (self::inFamily($url, $family) === false) {
				continue;
			}

			$members[] = $index;
			if ($first === null
				&& str_starts_with((string)$route['name'], 'publications#') === true
				&& self::isCatalogWildcard($url, $family) === true
			) {
				$first = $index;
			}
		}

		$this->assertNotNull($first, 'No publications# route on ' . $family . '{catalogSlug} is registered.');

		return ['first' => $first, 'members' => $members];
	}

	/**
	 * No named API route of a family is registered after that family's first catalogue wildcard.
	 *
	 * @return void
	 */
	public function testNoNamedApiRouteFollowsTheCatalogWildcard(): void {
		$routes = $this->routes();

		foreach (self::FAMILIES as $family) {
			$found = $this->family($routes, $family);
			$late = [];
			foreach ($found['members'] as $index) {
				$url = (string)$routes[$index]['url'];
				if ($index > $found['first'] && self::isCatalogWildcard($url, $family) === false) {
					$late[] = $routes[$index]['name'] . ' ' . ($routes[$index]['verb'] ?? 'GET') . ' ' . $url;
				}
			}

			$this->assertSame([], $late, 'Named routes registered after ' . $family . '{catalogSlug} are shadowed by it.');
		}
	}

	/**
	 * Every publications# endpoint, and its preflight, sits on a wildcard and constrains the slug.
	 *
	 * @return void
	 */
	public function testEveryPublicationsEndpointConstrainsTheSlug(): void {
		$seen = [];
		foreach ($this->routes() as $route) {
			if (str_starts_with((string)$route['name'], 'publications#') === false) {
				continue;
			}

			$url = (string)$route['url'];
			$onWildcard = self::isCatalogWildcard($url, '/api/') || self::isCatalogWildcard($url, '/api/v1/');
			$this->assertTrue($onWildcard, $route['name'] . ' ' . $url . ' must sit on {catalogSlug}');
			$this->assertArrayHasKey('catalogSlug', $route['requirements'] ?? [], $route['name'] . ' must constrain catalogSlug');
			$this->assertSame(0, preg_match('#/#', $route['requirements']['catalogSlug']), 'the slug pattern must not cross a segment');
			if (self::inFamily($url, '/api/') === true) {
				$seen[] = $route['name'] . ' ' . ($route['verb'] ?? 'GET');
			}
		}

		sort($seen);
		$this->assertSame(
			[
				'publications#attachments GET',
				'publications#download GET',
				'publications#index GET',
				'publications#preflightedCors OPTIONS',
				'publications#preflightedCors OPTIONS',
				'publications#preflightedCors OPTIONS',
				'publications#preflightedCors OPTIONS',
				'publications#preflightedCors OPTIONS',
				'publications#preflightedCors OPTIONS',
				'publications#show GET',
				'publications#used GET',
				'publications#uses GET',
			],
			$seen
		);
	}

	/**
	 * Within each family the wildcard routes form one block that closes the family.
	 *
	 * @return void
	 */
	public function testTheCatalogWildcardBlockIsTheLastApiBlock(): void {
		$routes = $this->routes();

		foreach (self::FAMILIES as $family) {
			$found = $this->family($routes, $family);
			$last = max($found['members']);
			for ($index = $found['first']; $index <= $last; $index++) {
				$url = (string)$routes[$index]['url'];
				$this->assertTrue(
					self::isCatalogWildcard($url, $family),
					'unexpected route inside the ' . $family . '{catalogSlug} block: ' . $routes[$index]['name'] . ' ' . $url
				);
			}
		}
	}
}
