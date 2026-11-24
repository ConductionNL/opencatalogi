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
 * The shipped publication schema demands summary, description and organisation from in_review on (REQ-PRP-001).
 *
 * @coversNothing
 */
final class PublicationRequiredAtPublishSchemaTest extends TestCase {

	/**
	 * The fields every state past draft requires.
	 *
	 * @var array<int, string>
	 */
	private const REQUIRED = ['summary', 'description', 'organization'];

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
	 * A draft declares no rule; in_review, approved and published require the three fields, for everyone.
	 *
	 * @return void
	 */
	public function testTheFieldsAndTheStates(): void {
		$publication = self::publication();
		$states = $publication['x-openregister-lifecycle']['states'];

		self::assertArrayNotHasKey(key: 'draft', array: $states, message: 'a draft declares no rule, so it may stay incomplete');
		foreach (['in_review', 'approved', 'published'] as $state) {
			self::assertSame(expected: [['fields' => self::REQUIRED]], actual: $states[$state]['fields']['required'], message: $state);
			self::assertArrayNotHasKey(key: 'groups', array: $states[$state]['fields']['required'][0], message: $state);
		}

		self::assertArrayNotHasKey(key: 'archived', array: $states);
		self::assertSame(expected: ['title'], actual: $publication['required']);
		foreach (self::REQUIRED as $field) {
			self::assertArrayHasKey(key: $field, array: $publication['properties'], message: $field);
		}
	}//end testTheFieldsAndTheStates()

	/**
	 * The lifecycle keeps its transitions next to the states, and the schema version moved past 0.0.14.
	 *
	 * @return void
	 */
	public function testTheTransitionsStayAndTheVersionIsBumped(): void {
		$publication = self::publication();

		self::assertSame(expected: 'draft', actual: $publication['x-openregister-lifecycle']['initial']);
		self::assertArrayHasKey(key: 'publish', array: $publication['x-openregister-lifecycle']['transitions']);
		self::assertTrue(condition: version_compare($publication['version'], '0.0.14', '>'), message: $publication['version']);
	}//end testTheTransitionsStayAndTheVersionIsBumped()

	/**
	 * OpenRegister's own annotation validator accepts the declaration with its states.
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
