<?php

/**
 * Every shipped migration is a Nextcloud migration step (admin-settings task 9.2).
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
 * @spec openspec/changes/admin-settings/tasks.md
 */

declare(strict_types=1);

namespace Unit\Migration;

use OCP\Migration\SimpleMigrationStep;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Pins the shape of every class under lib/Migration.
 */
final class MigrationStepShapeTest extends TestCase {

	/**
	 * The migration classes, by file.
	 *
	 * @return array<string, array{0: class-string}>
	 */
	public static function migrations(): array {
		$out = [];
		foreach (glob(__DIR__ . '/../../../lib/Migration/Version*.php') as $file) {
			$name       = basename($file, '.php');
			$out[$name] = ['OCA\\OpenCatalogi\\Migration\\' . $name];
		}

		return $out;
	}//end migrations()

	/**
	 * The four migrations of the admin-settings change are all there.
	 *
	 * @return void
	 */
	public function testTheFourMigrationsShip(): void {
		self::assertSame(
			expected: [
				'Version6Date20241011085015',
				'Version6Date20241129151236',
				'Version6Date20241208222530',
				'Version6Date20250419123213',
			],
			actual: array_keys(self::migrations())
		);
	}//end testTheFourMigrationsShip()

	/**
	 * A migration extends SimpleMigrationStep and implements a schema hook itself.
	 *
	 * @param class-string $class The migration class.
	 *
	 * @dataProvider migrations
	 *
	 * @return void
	 */
	public function testAMigrationIsASimpleMigrationStepWithASchemaHook(string $class): void {
		self::assertTrue(condition: class_exists(class: $class));

		$reflection = new ReflectionClass(objectOrClass: $class);
		self::assertTrue(condition: $reflection->isSubclassOf(class: SimpleMigrationStep::class));

		$own = array_filter(
			array: ['changeSchema', 'postSchemaChange'],
			callback: static fn (string $method): bool => $reflection->getMethod(name: $method)->getDeclaringClass()->getName() === $class
		);
		self::assertNotEmpty(actual: $own, message: $class . ' implements neither changeSchema() nor postSchemaChange().');
	}//end testAMigrationIsASimpleMigrationStepWithASchemaHook()
}//end class
