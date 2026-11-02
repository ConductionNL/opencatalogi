<?php

/**
 * Tests for the app identity constants.
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

use OCA\OpenCatalogi\AppInfo\Application;
use OCA\OpenCatalogi\Contract\AppIdentity;
use PHPUnit\Framework\TestCase;

/**
 * Pins the app identity values and keeps components off the Application class.
 */
class AppIdentityTest extends TestCase {

	/**
	 * The values other apps read through Application stay the same.
	 *
	 * @return void
	 */
	public function testApplicationConstantsKeepTheirValues(): void {
		$this->assertSame('opencatalogi', AppIdentity::APP_ID);
		$this->assertSame(
			'https://directory.opencatalogi.nl/apps/opencatalogi/api/directory',
			AppIdentity::DEFAULT_DIRECTORY_URL
		);
		$this->assertSame(AppIdentity::APP_ID, Application::APP_ID);
		$this->assertSame(AppIdentity::DEFAULT_DIRECTORY_URL, Application::DEFAULT_DIRECTORY_URL);

	}//end testApplicationConstantsKeepTheirValues()

	/**
	 * No class outside lib/AppInfo imports Application.
	 *
	 * Application wires every component, so a component that reads a constant
	 * from it depends on its own wiring and forms a cycle with AppInfo.
	 *
	 * @return void
	 */
	public function testNoComponentImportsApplication(): void {
		$lib = realpath(__DIR__ . '/../../../lib');
		$this->assertNotFalse($lib);

		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($lib, \FilesystemIterator::SKIP_DOTS));
		$checked  = 0;
		$offender = [];
		foreach ($files as $file) {
			$path = $file->getPathname();
			if (str_ends_with($path, '.php') === false || str_starts_with($path, $lib . '/AppInfo/') === true) {
				continue;
			}

			$checked++;
			$source = (string) file_get_contents($path);
			if (preg_match('/^use OCA\\\\OpenCatalogi\\\\AppInfo\\\\Application;/m', $source) === 1
				|| str_contains($source, '\\OCA\\OpenCatalogi\\AppInfo\\Application::') === true
			) {
				$offender[] = substr($path, strlen($lib) + 1);
			}
		}

		$this->assertGreaterThan(100, $checked, 'the walk over lib/ found too few files to mean anything');
		$this->assertSame([], $offender, 'read the app id from AppIdentity, not from Application');

	}//end testNoComponentImportsApplication()
}//end class
