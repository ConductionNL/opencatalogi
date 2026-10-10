<?php

/**
 * OpenCatalogi ships no pages or menus.
 *
 * Pages and menus are Portaliq's (cms-moves-to-portaliq; Ruben's decision 138
 * of 2026-10-09: remove them now). This test keeps them from growing back:
 * no `page` or `menu` seed object or table configuration in the register
 * files, no page or menu screen or route, no page or menu config key handed
 * to the frontend.
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
 * @spec openspec/changes/archive/2026-10-10-cms-moves-to-portaliq/specs/content-management/spec.md
 */

declare(strict_types=1);

namespace Unit\AppInfo;

use PHPUnit\Framework\TestCase;

/**
 * Reads the register files, routes, manifest and listener as shipped.
 */
class NoCmsInOpenCatalogiTest extends TestCase {

	/**
	 * The repository root.
	 *
	 * @return string
	 */
	private function root(): string {
		return dirname(__DIR__, 3);

	}//end root()

	/**
	 * The register files ship no page or menu object and configure no table for them.
	 *
	 * @return void
	 */
	public function testTheRegisterFilesCarryNoPageOrMenu(): void {
		foreach (['lib/Settings/publication_register.json', 'lib/Settings/opencatalogi_mock_register.json'] as $file) {
			$register = json_decode((string)file_get_contents($this->root() . '/' . $file), true);
			$this->assertIsArray($register, $file);
			$components = $register['components'];

			$this->assertArrayNotHasKey('page', ($components['schemas'] ?? []), $file);
			$this->assertArrayNotHasKey('menu', ($components['schemas'] ?? []), $file);

			foreach (($components['objects'] ?? []) as $object) {
				$this->assertNotContains(($object['@self']['schema'] ?? ''), ['page', 'menu'], $file . ' seeds a ' . ($object['@self']['schema'] ?? '') . ' object');
			}

			foreach (($components['registers'] ?? []) as $slug => $definition) {
				$this->assertNotContains('page', ($definition['schemas'] ?? []), $file . ' ' . $slug);
				$this->assertNotContains('menu', ($definition['schemas'] ?? []), $file . ' ' . $slug);
				$configured = array_keys(($definition['configuration']['schemas'] ?? []));
				$this->assertNotContains('page', $configured, $file . ' configures a page table');
				$this->assertNotContains('menu', $configured, $file . ' configures a menu table');
			}
		}//end foreach

	}//end testTheRegisterFilesCarryNoPageOrMenu()

	/**
	 * No route, screen or frontend config key for pages or menus.
	 *
	 * @return void
	 */
	public function testThereIsNoPageOrMenuRouteOrConfigKey(): void {
		$routes = include $this->root() . '/appinfo/routes.php';
		$names = array_column($routes['routes'], 'name');
		foreach ($names as $name) {
			$this->assertDoesNotMatchRegularExpression('/^(pages|menus)#|#(pages|menus)$/', $name);
		}

		$listener = (string)file_get_contents($this->root() . '/lib/Listener/ProvideManifestConfigStateListener.php');
		foreach (['page_register', 'page_schema', 'menu_register', 'menu_schema'] as $key) {
			$this->assertStringNotContainsString("'" . $key . "'", $listener);
		}

		$layout = json_decode((string)file_get_contents($this->root() . '/src/menu-layout.json'), true);
		$this->assertNotContains('PagesMenu', $layout['removals']);
		$this->assertNotContains('MenusMenu', $layout['removals']);

	}//end testThereIsNoPageOrMenuRouteOrConfigKey()
}//end class
