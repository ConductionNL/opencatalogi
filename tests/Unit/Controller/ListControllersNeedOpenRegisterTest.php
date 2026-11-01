<?php

/**
 * The list controllers refuse to read without OpenRegister.
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
 * @spec openspec/specs/catalogs/spec.md
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Controller\CatalogiController;
use OCA\OpenCatalogi\Controller\GlossaryController;
use OCA\OpenCatalogi\Controller\ThemesController;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use RuntimeException;

/**
 * Without OpenRegister the list controllers' object service lookup throws.
 */
class ListControllersNeedOpenRegisterTest extends TestCase {

	/**
	 * getObjectService() throws a RuntimeException when OpenRegister is not installed.
	 *
	 * @return void
	 */
	public function testObjectServiceRequiresOpenRegister(): void {
		foreach ([CatalogiController::class, GlossaryController::class, ThemesController::class] as $class) {
			$appManager = $this->createMock(IAppManager::class);
			$appManager->method('getInstalledApps')->willReturn(['files']);
			$args = [];
			foreach ((new ReflectionClass($class))->getConstructor()->getParameters() as $param) {
				$type = $param->getType();
				$name = ($type instanceof ReflectionNamedType ? $type->getName() : null);
				$args[] = match (true) {
					$param->getName() === 'appName' => 'opencatalogi',
					$name === IAppManager::class => $appManager,
					$param->isDefaultValueAvailable() === true => $param->getDefaultValue(),
					default => $this->createMock($name),
				};
			}

			$controller = new $class(...$args);
			try {
				(new ReflectionMethod($controller, 'getObjectService'))->invoke($controller);
				$this->fail($class . ' resolved an object service without OpenRegister');
			} catch (RuntimeException $e) {
				$this->assertSame('OpenRegister service is not available.', $e->getMessage(), $class);
			}
		}
	}//end testObjectServiceRequiresOpenRegister()
}//end class
