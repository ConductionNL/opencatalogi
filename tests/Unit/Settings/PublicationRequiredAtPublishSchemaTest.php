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

use OCA\OpenCatalogi\Service\Publication\RequiredToPublish;
use OCA\OpenCatalogi\Service\SettingsService;
use OCA\OpenRegister\Service\Lifecycle\LifecycleAnnotationValidator;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The required-to-publish state rules (REQ-PRP-001, tasks.md 1.1, design D1): from `in_review` on a publication
 * carries a summary, a description and its organisation; a draft declares no rule. OpenRegister enforces a state's
 * `required` on every save, so the schema also administers the not-supplied reason the upgrade repair steps record
 * for a legacy publication that lacks one of them (design D2), and the repair steps can save it.
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
	 * In review, approved and published require the three fields for everyone; a draft requires nothing.
	 *
	 * @return void
	 */
	public function testFromReviewOnTheThreeFieldsAreRequired(): void {
		$publication = self::publication();
		$states = $publication['x-openregister-lifecycle']['states'];

		self::assertArrayNotHasKey(key: 'draft', array: $states);
		foreach (['in_review', 'approved', 'published'] as $state) {
			self::assertSame(
				expected: [['fields' => RequiredToPublish::FIELDS]],
				actual: $states[$state]['fields']['required'],
				message: $state
			);
		}

		self::assertSame(expected: ['title'], actual: $publication['required']);
		self::assertSame(expected: RequiredToPublish::STATES, actual: array_keys($states));
	}//end testFromReviewOnTheThreeFieldsAreRequired()

	/**
	 * The schema administers the reason the upgrade records, so OpenRegister accepts the record on a system save.
	 *
	 * @return void
	 */
	public function testTheUpgradeReasonIsAdministered(): void {
		$reasons = self::publication()['x-openregister-not-supplied-reasons'];

		self::assertArrayHasKey(key: RequiredToPublish::NOT_SUPPLIED_REASON, array: $reasons);
		self::assertNotSame(expected: '', actual: trim($reasons[RequiredToPublish::NOT_SUPPLIED_REASON]));
	}//end testTheUpgradeReasonIsAdministered()

	/**
	 * The schema version moved past 0.0.17, so an instance that imported the held-back schema imports the rule.
	 *
	 * @return void
	 */
	public function testTheVersionMovedPastTheHeldBackSchema(): void {
		$publication = self::publication();

		self::assertSame(expected: 'draft', actual: $publication['x-openregister-lifecycle']['initial']);
		self::assertTrue(condition: version_compare($publication['version'], '0.0.17', '>'), message: $publication['version']);
	}//end testTheVersionMovedPastTheHeldBackSchema()

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
