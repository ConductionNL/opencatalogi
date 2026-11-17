<?php

/**
 * PublicationLifecycleDeclarationTest
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/publication-lifecycle-on-or/tasks.md#task-1.1
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Settings;

use OCA\OpenCatalogi\Lifecycle\ReviewNotRequiredGuard;
use OCA\OpenCatalogi\Service\SettingsService;
use OCA\OpenRegister\Service\Lifecycle\LifecycleAnnotationValidator;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The shipped publication schema, merged with every fragment, declares the one lifecycle of REQ-PLC-001.
 *
 * @coversNothing
 */
final class PublicationLifecycleDeclarationTest extends TestCase {

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
	 * Seven named transitions with exactly the from and to states of the table, initial draft, final archived.
	 *
	 * @return void
	 */
	public function testTheLifecycleDeclaresTheSevenTransitions(): void {
		$publication = self::publication();
		$lifecycle = $publication['x-openregister-lifecycle'];

		self::assertSame('status', $lifecycle['field']);
		self::assertSame('draft', $lifecycle['initial']);
		self::assertSame(['archived'], $lifecycle['final']);
		self::assertSame(['draft', 'in_review', 'approved', 'published', 'archived'], $publication['properties']['status']['enum']);

		$table = [];
		foreach ($lifecycle['transitions'] as $action => $transition) {
			$table[$action] = [$transition['from'], $transition['to']];
		}

		self::assertSame(
			[
				'submit' => [['draft'], 'in_review'],
				'approve' => [['in_review'], 'approved'],
				'reject' => [['in_review'], 'draft'],
				'retract' => [['approved'], 'draft'],
				'publish' => [['approved'], 'published'],
				'publishWithoutReview' => [['draft'], 'published'],
				'archive' => [['published'], 'archived'],
			],
			$table
		);
		self::assertSame([['field' => 'reason', 'required' => true]], $lifecycle['transitions']['reject']['inputs']);
	}//end testTheLifecycleDeclaresTheSevenTransitions()

	/**
	 * Both publish moves stamp firstReleasedAt once; skipping review needs the review guard.
	 *
	 * @return void
	 */
	public function testThePublishMovesStampTheFirstReleaseAndSkippingReviewIsGuarded(): void {
		$publication = self::publication();
		$transitions = $publication['x-openregister-lifecycle']['transitions'];

		foreach (['publish', 'publishWithoutReview'] as $action) {
			self::assertSame(
				[
					[
						'action' => 'set-fields',
						'condition' => "@previous.firstReleasedAt == ''",
						'actionParameters' => ['firstReleasedAt' => '@now'],
					],
				],
				$transitions[$action]['actions'],
				$action
			);
		}

		self::assertSame(ReviewNotRequiredGuard::class, $transitions['publishWithoutReview']['requires']);
		self::assertArrayNotHasKey('requires', $transitions['publish']);
		self::assertTrue(class_exists($transitions['publishWithoutReview']['requires']));
	}//end testThePublishMovesStampTheFirstReleaseAndSkippingReviewIsGuarded()

	/**
	 * firstReleasedAt is an immutable date-time; unlisted is a boolean that defaults to false; the version moved.
	 *
	 * @return void
	 */
	public function testTheNewPropertiesAndTheVersionBump(): void {
		$publication = self::publication();

		self::assertSame('date-time', $publication['properties']['firstReleasedAt']['format']);
		self::assertTrue($publication['properties']['firstReleasedAt']['immutable']);
		self::assertSame('boolean', $publication['properties']['unlisted']['type']);
		self::assertFalse($publication['properties']['unlisted']['default']);
		self::assertSame('draft', $publication['properties']['status']['default']);
		self::assertTrue(version_compare($publication['version'], '0.0.9', '>='), $publication['version']);
	}//end testTheNewPropertiesAndTheVersionBump()

	/**
	 * OpenRegister's own annotation validator accepts the shipped declaration.
	 *
	 * @return void
	 */
	public function testTheDeclarationPassesOpenRegistersGraphValidator(): void {
		if (class_exists(LifecycleAnnotationValidator::class) === false) {
			self::markTestSkipped('OpenRegister is not installed beside this app, so its lifecycle validator cannot run.');
		}

		$errors = (new LifecycleAnnotationValidator())->validate(self::publication());

		self::assertSame([], $errors);
	}//end testTheDeclarationPassesOpenRegistersGraphValidator()
}//end class
