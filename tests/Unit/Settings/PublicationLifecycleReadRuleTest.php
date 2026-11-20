<?php

/**
 * PublicationLifecycleReadRuleTest
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
 * @spec openspec/changes/publication-lifecycle-on-or/tasks.md#task-3.1
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Settings;

use OCA\OpenCatalogi\Service\SettingsService;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\ConditionUserResolvers;
use OCA\OpenRegister\Service\OperatorEvaluator;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * The shipped public read rule of the publication, evaluated by OpenRegister's own condition matcher.
 *
 * @coversNothing
 */
final class PublicationLifecycleReadRuleTest extends TestCase {

	/**
	 * Whether any public read rule of the shipped publication schema admits the publication.
	 *
	 * @param array<string, mixed> $publication The stored publication.
	 *
	 * @return bool
	 */
	private function isPublic(array $publication): bool {
		if (class_exists(ConditionMatcher::class) === false) {
			self::markTestSkipped('OpenRegister is not installed beside this app, so its condition matcher cannot run.');
		}

		$schema = SettingsService::shippedSchema(dirname(__DIR__, 3) . '/lib/Settings', 'publication');
		$logger = new NullLogger();
		$matcher = new ConditionMatcher(
			new ConditionUserResolvers($this->createMock(IUserSession::class)),
			$this->createMock(ContainerInterface::class),
			new OperatorEvaluator($logger),
			$logger
		);

		foreach ($schema['authorization']['read'] as $rule) {
			if (is_array($rule) === true && ($rule['group'] ?? null) === 'public'
				&& $matcher->objectMatchesConditions($publication, $rule['match']) === true
			) {
				return true;
			}
		}

		return false;
	}//end isPublic()

	/**
	 * REQ-PLC-003: a stored draft with a past publication date stays private.
	 *
	 * @return void
	 */
	public function testADraftWithAPastPublicationDateIsNotPublic(): void {
		self::assertFalse($this->isPublic(['status' => 'draft', 'publicationDate' => '2020-01-01T00:00:00+00:00']));
	}//end testADraftWithAPastPublicationDateIsNotPublic()

	/**
	 * Only `published` with a past date is public; review, approval, archive and an empty status are not.
	 *
	 * @return void
	 */
	public function testOnlyThePublishedStateWithItsDatesIsPublic(): void {
		$past = '2020-01-01T00:00:00+00:00';

		self::assertTrue($this->isPublic(['status' => 'published', 'publicationDate' => $past]), 'published, past date');
		self::assertTrue($this->isPublic(['status' => 'published', 'publicationDate' => $past, 'depublicationDate' => '2099-01-01T00:00:00+00:00']), 'published, withdrawal ahead');
		self::assertFalse($this->isPublic(['status' => 'published', 'publicationDate' => $past, 'depublicationDate' => '2021-01-01T00:00:00+00:00']), 'withdrawn');
		self::assertFalse($this->isPublic(['status' => 'published', 'publicationDate' => '2099-01-01T00:00:00+00:00']), 'scheduled');
		foreach (['in_review', 'approved', 'archived', ''] as $status) {
			self::assertFalse($this->isPublic(['status' => $status, 'publicationDate' => $past]), 'status ' . $status);
		}

		self::assertFalse($this->isPublic(['publicationDate' => $past]), 'no stored status');
	}//end testOnlyThePublishedStateWithItsDatesIsPublic()
}//end class
