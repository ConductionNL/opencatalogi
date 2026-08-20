<?php

/**
 * OpenCatalogi canonical settings route/method contract test.
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
 * @spec openspec/specs/admin-settings/spec.md#requirement-admin-settings-page-loads-and-saves-configuration-set-or-006
 */

declare(strict_types=1);

namespace Unit\AppInfo;

use OCA\OpenCatalogi\Controller\SettingsController;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The canonical AppHost route table ({@see \OCA\OpenRegister\AppHost\Routes})
 * makes `PUT /api/settings` -> `settings#update` the canonical settings write
 * and `POST /api/settings` -> `settings#create` the legacy alias.
 *
 * OpenCatalogi does NOT call `Routes::standard()` — it hand-declares
 * `appinfo/routes.php` — and it ships its own `SettingsController`, so
 * `OCA\OpenRegister\AppHost\Bootstrap::aliasControllerUnlessLeafDefinesIt()`
 * skips the DI alias to the generic controller entirely. That leaves both
 * halves of the contract owed by this leaf app, and they fail differently:
 *
 *   - Route entry missing -> the router never matches the verb and the
 *     request dies with a 405 Method Not Allowed. Measured 2026-08-08 on the
 *     dev instance: `PUT /api/settings` -> 405, while GET and POST -> 200.
 *   - Method missing but routed -> the router matches, the dispatcher
 *     reflects the method, and the request dies with a 500 (and gate-14,
 *     route-reachability, flags the unreachable entry).
 *
 * Both halves are asserted here so neither can land without the other.
 *
 * Every assertion targets the ITEM (each individual method / each individual
 * route entry), never the container (the controller class or the routes array
 * merely existing).
 */
class CanonicalSettingsRouteContractTest extends TestCase {

	/**
	 * The canonical settings dialect: route name => [url, verb].
	 *
	 * `update` is the canonical write; `create` is the legacy alias that must
	 * stay reachable because OpenCatalogi's own admin UI still POSTs to it.
	 *
	 * @var array<string, array{url: string, verb: string}>
	 */
	private const CANONICAL_SETTINGS_ROUTES = [
		'settings#update' => [
			'url' => '/api/settings',
			'verb' => 'PUT',
		],
		'settings#create' => [
			'url' => '/api/settings',
			'verb' => 'POST',
		],
		'settings#index' => [
			'url' => '/api/settings',
			'verb' => 'GET',
		],
	];

	/**
	 * Load `appinfo/routes.php` and return its `routes` array.
	 *
	 * Evaluates the file as PHP rather than grepping it, so a route that is
	 * commented out, unreachable behind a conditional, or in a different array
	 * cannot masquerade as registered.
	 *
	 * @return array<int, array<string, mixed>> The route entries.
	 */
	private function loadRoutes(): array {
		$routes = include __DIR__ . '/../../../appinfo/routes.php';

		$this->assertIsArray($routes, 'appinfo/routes.php must return an array');
		$this->assertArrayHasKey('routes', $routes, 'appinfo/routes.php must expose a "routes" key');

		return $routes['routes'];
	}//end loadRoutes()

	/**
	 * Every canonical settings route MUST be present in `appinfo/routes.php`
	 * with the exact url and verb the AppHost dialect specifies.
	 *
	 * @spec openspec/specs/admin-settings/spec.md#requirement-admin-settings-page-loads-and-saves-configuration-set-or-006
	 *
	 * @return void
	 */
	public function testCanonicalSettingsRoutesAreRegistered(): void {
		$routes = $this->loadRoutes();

		// Index the declared table by "name VERB url" so the assertion below
		// compares the whole triple, not just the presence of a name.
		$declared = [];
		foreach ($routes as $route) {
			$declared[] = $route['name'] . ' ' . strtoupper((string)$route['verb']) . ' ' . $route['url'];
		}

		// Positive control: the scan must actually have seen the route table.
		// Without this, an empty or mis-shaped $routes would make every
		// "missing" assertion below fail loudly rather than a filter silently
		// matching nothing.
		$this->assertGreaterThan(
			0,
			count($declared),
			'Positive control failed: appinfo/routes.php yielded no route entries at all.'
		);

		$inspected = 0;
		foreach (self::CANONICAL_SETTINGS_ROUTES as $name => $spec) {
			$inspected++;
			$expected = $name . ' ' . $spec['verb'] . ' ' . $spec['url'];

			$this->assertContains(
				$expected,
				$declared,
				'Canonical settings route "' . $expected . '" is not declared in appinfo/routes.php. '
				. 'Without it the verb has no route and the request returns 405 Method Not Allowed.'
			);
		}

		// Positive control: the expectation table itself must be non-empty.
		$this->assertSame(
			count(self::CANONICAL_SETTINGS_ROUTES),
			$inspected,
			'Positive control failed: not every canonical route expectation was inspected.'
		);

	}//end testCanonicalSettingsRoutesAreRegistered()

	/**
	 * `PUT /api/settings` specifically MUST resolve to `settings#update`.
	 *
	 * Asserted on its own (rather than only as part of the table above) because
	 * this is the exact entry whose absence produced the measured 405.
	 *
	 * @spec openspec/specs/admin-settings/spec.md#requirement-admin-settings-page-loads-and-saves-configuration-set-or-006
	 *
	 * @return void
	 */
	public function testPutApiSettingsRoutesToSettingsUpdate(): void {
		$matches = [];

		foreach ($this->loadRoutes() as $route) {
			if ($route['url'] === '/api/settings' && strtoupper((string)$route['verb']) === 'PUT') {
				$matches[] = $route['name'];
			}
		}

		$this->assertSame(
			['settings#update'],
			$matches,
			'Expected exactly one PUT /api/settings route targeting settings#update.'
		);

	}//end testPutApiSettingsRoutesToSettingsUpdate()

	/**
	 * Each canonical settings method MUST exist on SettingsController and be
	 * public (i.e. dispatchable by NC's controller dispatcher).
	 *
	 * Asserts the ITEM — each individual method — never merely that the
	 * SettingsController class exists.
	 *
	 * @spec openspec/specs/admin-settings/spec.md#requirement-admin-settings-page-loads-and-saves-configuration-set-or-006
	 *
	 * @return void
	 */
	public function testCanonicalSettingsMethodsExistAndArePublic(): void {
		$inspected = 0;

		foreach (array_keys(self::CANONICAL_SETTINGS_ROUTES) as $name) {
			[, $methodName] = explode('#', $name, 2);

			$this->assertTrue(
				method_exists(SettingsController::class, $methodName),
				'SettingsController::' . $methodName . '() does not exist, but appinfo/routes.php routes "'
				. $name . '" to it. OpenCatalogi ships its own SettingsController, so the OpenRegister '
				. 'AppHost generic is never aliased in and cannot supply this method.'
			);

			$method = new ReflectionMethod(SettingsController::class, $methodName);

			$this->assertTrue(
				$method->isPublic(),
				'SettingsController::' . $methodName . '() must be public to be dispatchable.'
			);
			$this->assertFalse(
				$method->isStatic(),
				'SettingsController::' . $methodName . '() must not be static.'
			);
			$this->assertFalse(
				$method->isAbstract(),
				'SettingsController::' . $methodName . '() must not be abstract.'
			);

			$inspected++;
		}//end foreach

		// Positive control: a loop that matched nothing would otherwise pass
		// silently with zero assertions executed.
		$this->assertGreaterThan(
			0,
			$inspected,
			'Positive control failed: no canonical settings methods were inspected.'
		);
		$this->assertSame(count(self::CANONICAL_SETTINGS_ROUTES), $inspected);

	}//end testCanonicalSettingsMethodsExistAndArePublic()

	/**
	 * `update()` and `create()` MUST carry the same auth posture.
	 *
	 * NC's SecurityMiddleware evaluates the attributes of the *dispatched*
	 * method, so `create()` delegating to `update()` in its body does not carry
	 * `update()`'s posture across — each needs its own. This test is what stops
	 * the new PUT verb from silently becoming more privileged than the POST it
	 * mirrors (net privilege change must be zero).
	 *
	 * @spec openspec/specs/admin-settings/spec.md#requirement-admin-settings-page-loads-and-saves-configuration-set-or-006
	 *
	 * @return void
	 */
	public function testUpdateAndCreateShareTheSameAuthPosture(): void {
		$attributeNamesFor = static function (string $methodName): array {
			$method = new ReflectionMethod(SettingsController::class, $methodName);
			$names = array_map(
				static fn ($attribute): string => $attribute->getName(),
				$method->getAttributes()
			);
			sort($names);

			return $names;
		};

		$updateAttributes = $attributeNamesFor('update');
		$createAttributes = $attributeNamesFor('create');

		// Positive control: the reflection must actually have found attributes.
		// An empty-vs-empty comparison would pass while proving nothing.
		$this->assertNotEmpty(
			$updateAttributes,
			'Positive control failed: SettingsController::update() carries no attributes at all.'
		);

		$this->assertSame(
			$createAttributes,
			$updateAttributes,
			'SettingsController::update() and ::create() must carry identical auth attributes.'
		);

		// The write reaches instance-wide IAppConfig, so it must be admin-gated
		// and auditable via NC's delegated-admin system.
		$this->assertContains(
			\OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting::class,
			$updateAttributes,
			'SettingsController::update() writes instance-wide config and must be admin-gated.'
		);

		// It must NOT be downgraded to a non-admin endpoint.
		$this->assertNotContains(
			\OCP\AppFramework\Http\Attribute\NoAdminRequired::class,
			$updateAttributes,
			'SettingsController::update() must never carry #[NoAdminRequired] — it writes instance-wide config.'
		);

		$updateDoc = (new ReflectionMethod(SettingsController::class, 'update'))->getDocComment();
		$this->assertIsString($updateDoc, 'Positive control failed: update() has no docblock to inspect.');
		$this->assertSame(
			0,
			preg_match('/^\s*\*\s*@NoAdminRequired\b/m', $updateDoc),
			'SettingsController::update() must not carry the @NoAdminRequired docblock tag.'
		);

	}//end testUpdateAndCreateShareTheSameAuthPosture()
}//end class
