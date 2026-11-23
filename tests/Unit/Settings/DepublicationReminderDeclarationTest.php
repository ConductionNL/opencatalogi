<?php

/**
 * DepublicationReminderDeclarationTest
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
 * @spec openspec/changes/publication-schedule-guards/tasks.md#task-3.2
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Settings;

use DateTimeImmutable;
use OCA\OpenCatalogi\Service\SettingsService;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationTriggerValidator;
use OCA\OpenRegister\Notifications\Service\Notification\ScheduledFilterEvaluator;
use OCA\OpenRegister\Notifications\Service\Notification\ScheduledFilterParser;
use OCA\OpenRegister\Notifications\Service\Notification\ScheduledFilterTarget;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The shipped publication schema, merged with every fragment, reminds the managers a week before
 * an automatic depublication (REQ-PSG-003), and OpenRegister's own parser and evaluator run the rule.
 *
 * @coversNothing
 */
final class DepublicationReminderDeclarationTest extends TestCase {

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
	 * The declared rule.
	 *
	 * @return array<string, mixed>
	 */
	private static function rule(): array {
		return self::publication()['x-openregister-notifications']['depublication-due-soon'];
	}//end rule()

	/**
	 * Skip when OpenRegister's notification classes are not on the autoload path.
	 *
	 * @return void
	 */
	private function requireOpenRegister(): void {
		if (class_exists(ScheduledFilterEvaluator::class) === false || class_exists(NotificationTriggerValidator::class) === false) {
			$this->markTestSkipped(message: 'OpenRegister\'s notification engine is not installed.');
		}
	}//end requireOpenRegister()

	/**
	 * Whether the rule's filter matches a publication depublished at $depublicationDate, judged at $now.
	 *
	 * @param string            $depublicationDate The publication's depublication date.
	 * @param DateTimeImmutable $now               The scan's moment.
	 *
	 * @return bool
	 */
	private static function reminds(string $depublicationDate, DateTimeImmutable $now): bool {
		return (new ScheduledFilterEvaluator())->matchesTarget(
			filter: self::rule()['trigger']['filter'],
			target: new ScheduledFilterTarget(objectData: ['title' => 'Besluit', 'depublicationDate' => $depublicationDate], now: $now)
		);
	}//end matches()

	/**
	 * The rule is declarative, daily, for the managers, deduplicated per date, and named in Dutch and English.
	 *
	 * @return void
	 */
	public function testTheRuleIsDeclaredOnThePublicationSchema(): void {
		$rule = self::rule();

		self::assertSame(expected: 'scheduled', actual: $rule['trigger']['type']);
		self::assertSame(expected: 86400, actual: $rule['trigger']['intervalSec']);
		self::assertSame(expected: ['depublicationDate' => ['operator' => 'withinNext', 'value' => 'P7D']], actual: $rule['trigger']['filter']);
		self::assertSame(expected: ['depublicationDate'], actual: $rule['trigger']['dedupeFields']);
		self::assertSame(expected: ['nc-notification'], actual: $rule['channels']);
		self::assertSame(expected: [['kind' => 'object-acl', 'permission' => 'manage']], actual: $rule['recipients']);
		foreach (['nl', 'en'] as $language) {
			self::assertStringContainsString(needle: '{{title}}', haystack: $rule['subject'][$language]);
			self::assertStringContainsString(needle: '{{depublicationDate}}', haystack: $rule['subject'][$language]);
		}

		self::assertTrue(condition: version_compare(version1: self::publication()['version'], version2: '0.0.12', operator: '>='));
	}//end testTheRuleIsDeclaredOnThePublicationSchema()

	/**
	 * OpenRegister accepts the trigger, and its evaluator matches a publication due in five days.
	 *
	 * @return void
	 */
	public function testTheRuleParsesAndMatchesAPublicationDueInFiveDays(): void {
		$this->requireOpenRegister();
		$now = new DateTimeImmutable('2026-11-21T09:00:00+00:00');

		$validator = new NotificationTriggerValidator(filterParser: new ScheduledFilterParser());

		self::assertSame(expected: [], actual: $validator->validate(name: 'depublication-due-soon', trigger: self::rule()['trigger']));
		self::assertTrue(condition: self::reminds(depublicationDate: '2026-11-26T09:00:00+00:00', now: $now));
	}//end testTheRuleParsesAndMatchesAPublicationDueInFiveDays()

	/**
	 * A publication due in ten days, or already past its date, is not reminded.
	 *
	 * @return void
	 */
	public function testTheRuleDoesNotMatchAPublicationDueInTenDays(): void {
		$this->requireOpenRegister();
		$now = new DateTimeImmutable('2026-11-21T09:00:00+00:00');

		self::assertFalse(condition: self::reminds(depublicationDate: '2026-12-01T09:00:00+00:00', now: $now));
		self::assertFalse(condition: self::reminds(depublicationDate: '2026-11-20T09:00:00+00:00', now: $now));
	}//end testTheRuleDoesNotMatchAPublicationDueInTenDays()
}//end class
