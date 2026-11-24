<?php

/**
 * PublicationRequiredAtPublishSchemaTest
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Settings
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link https://www.OpenCatalogi.nl
 * @spec openspec/changes/publication-required-at-publish/tasks.md#task-1.1
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Settings;

use OCA\OpenCatalogi\Service\SettingsService;
use OCA\OpenRegister\Service\Lifecycle\LifecycleAnnotationValidator;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The required-to-publish state rules are held back (REQ-PRP-001, tasks.md 1.1): OpenRegister enforces a state's
 * `required` on every save, the upgrade repair steps that run after the schema import re-save legacy published
 * publications, and a legacy publication without a summary, description or organisation would be refused there and
 * left without its state or audience, which hides it. The rule ships again once those steps can save such a
 * publication.
 *
 * @coversNothing
 */
final class PublicationRequiredAtPublishSchemaTest extends TestCase {

	/**
	 * The publication schema as it is imported: the base register with every register.d fragment merged.
	 *
	 * @return array<string, mixed>
	 */
	private static function publication(): array {
		$root = dirname(__DIR__, 3) . '/lib/Settings';
		$base = json_decode((string)file_get_contents($root . '/publication_register.json'), true, flags: JSON_THROW_ON_ERROR);
		$merge = new ReflectionMethod(SettingsService::class, 'mergeRegisterFragments');

		return $merge->invoke(null, $base, $root . '/register.d')['components']['schemas']['publication'];
	}//end publication()

	/**
	 * No state of the publication lifecycle requires a field, so an upgrade can re-save every legacy publication.
	 *
	 * @return void
	 */
	public function testNoStateRequiresAFieldWhileTheUpgradePathCannotFillIt(): void {
		$publication = self::publication();

		self::assertArrayNotHasKey(key: 'states', array: $publication['x-openregister-lifecycle']);
		self::assertSame(expected: ['title'], actual: $publication['required']);
	}//end testNoStateRequiresAFieldWhileTheUpgradePathCannotFillIt()

	/**
	 * The schema version moved past 0.0.15, so an instance that imported the rule imports it away again.
	 *
	 * @return void
	 */
	public function testTheVersionMovedPastTheOneThatDeclaredTheRule(): void {
		$publication = self::publication();

		self::assertSame(expected: 'draft', actual: $publication['x-openregister-lifecycle']['initial']);
		self::assertTrue(condition: version_compare($publication['version'], '0.0.15', '>'), message: $publication['version']);
	}//end testTheVersionMovedPastTheOneThatDeclaredTheRule()

	/**
	 * OpenRegister's own annotation validator accepts the declaration.
	 *
	 * @return void
	 */
	public function testTheDeclarationPassesOpenRegistersValidator(): void {
		if (class_exists(LifecycleAnnotationValidator::class) === false) {
			self::markTestSkipped(message: 'OpenRegister is not installed beside this app, so its lifecycle validator cannot run.');
		}

		self::assertSame(expected: [], actual: (new LifecycleAnnotationValidator())->validate(self::publication()));
	}//end testTheDeclarationPassesOpenRegistersValidator()
}//end class
