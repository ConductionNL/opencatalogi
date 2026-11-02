<?php

/**
 * Tests that every Contract interface is bound and that Contract stays a leaf.
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
 */

declare(strict_types=1);

namespace Unit\Contract;

use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Every interface in lib/Contract is bound to a class that implements it.
 *
 * Callers in other components type against these interfaces, so the container
 * can only build them when a registrar binds the interface. A missing binding
 * fails at request time, not at install time; this test makes it fail here.
 */
class ContractBindingsTest extends TestCase {

	private const LIB = __DIR__ . '/../../../lib';

	/**
	 * Collect the aliases all component registrars bind.
	 *
	 * @return array<string, string> Alias => target class.
	 */
	private function boundAliases(): array {
		$aliases = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerServiceAlias')->willReturnCallback(
			static function (string $alias, string $target) use (&$aliases): void {
				$aliases[$alias] = $target;
			}
		);

		$files = glob(self::LIB . '/*/*Registrar.php');
		$this->assertNotEmpty($files);
		foreach ($files as $file) {
			$component = basename(dirname($file));
			$class     = 'OCA\\OpenCatalogi\\' . $component . '\\' . basename($file, '.php');
			$ctor      = (new ReflectionClass($class))->getConstructor();
			$registrar = $ctor !== null && $ctor->getNumberOfParameters() > 0 ? new $class('opencatalogi') : new $class();
			$registrar->register($context);
		}

		return $aliases;

	}//end boundAliases()

	/**
	 * The interfaces in lib/Contract.
	 *
	 * @return array<int, string> Fully qualified interface names.
	 */
	private function contractInterfaces(): array {
		$names = [];
		foreach (glob(self::LIB . '/Contract/*Interface.php') as $file) {
			$names[] = 'OCA\\OpenCatalogi\\Contract\\' . basename($file, '.php');
		}

		$this->assertNotEmpty($names, 'no interfaces in lib/Contract: this test no longer watches anything');

		return $names;

	}//end contractInterfaces()

	/**
	 * Each interface is bound, and its target implements it.
	 *
	 * @return void
	 */
	public function testEveryContractInterfaceIsBoundToAnImplementation(): void {
		$aliases = $this->boundAliases();

		foreach ($this->contractInterfaces() as $interface) {
			$this->assertTrue(interface_exists($interface), $interface . ' is not an interface');
			$this->assertArrayHasKey($interface, $aliases, $interface . ' is not bound by any component registrar');
			$this->assertContains(
				$interface,
				class_implements($aliases[$interface]),
				$aliases[$interface] . ' does not implement ' . $interface
			);
		}

	}//end testEveryContractInterfaceIsBoundToAnImplementation()

	/**
	 * Contract imports nothing from the components that implement it.
	 *
	 * Contract is the leaf every component may depend on. An import from a
	 * component back into Contract makes a cycle between the two.
	 *
	 * @return void
	 */
	public function testContractDependsOnNoComponent(): void {
		$allowed = ['Contract', 'Exception', 'Http', 'Event'];
		$files   = glob(self::LIB . '/Contract/*.php');
		$this->assertNotEmpty($files, 'lib/Contract is empty: this test no longer watches anything');
		foreach ($files as $file) {
			$matches = [];
			preg_match_all('/^use OCA\\\\OpenCatalogi\\\\(\w+)\\\\/m', (string) file_get_contents($file), $matches);
			foreach ($matches[1] as $component) {
				$this->assertContains($component, $allowed, basename($file) . ' imports from ' . $component);
			}
		}

	}//end testContractDependsOnNoComponent()
}//end class
