<?php

/**
 * PublicationLifecycleTest
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Service\Publication
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/publication-lifecycle-on-or/tasks.md#task-1.2
 * @spec openspec/changes/publication-schedule-guards/tasks.md#task-1.3
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Service\Publication;

use DateTimeImmutable;
use OCA\OpenCatalogi\Lifecycle\ReviewNotRequiredGuard;
use OCA\OpenCatalogi\Listener\EmbargoGuardListener;
use OCA\OpenCatalogi\Service\Publication\EmbargoGuard;
use OCA\OpenCatalogi\Service\Publication\PublicationReviewPolicy;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * `publishWithoutReview` is open only while the admin turned review off, and closed otherwise.
 *
 * @covers \OCA\OpenCatalogi\Lifecycle\ReviewNotRequiredGuard
 * @covers \OCA\OpenCatalogi\Service\Publication\PublicationReviewPolicy
 * @covers \OCA\OpenCatalogi\Listener\EmbargoGuardListener
 * @uses   \OCA\OpenCatalogi\Service\Publication\EmbargoGuard
 */
final class PublicationLifecycleTest extends TestCase {

	private IAppConfig&MockObject $appConfig;
	private ReviewNotRequiredGuard $guard;

	protected function setUp(): void {
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->guard = new ReviewNotRequiredGuard(new PublicationReviewPolicy($this->appConfig));

	}//end setUp()

	/**
	 * The setting as stored, read with its default `true`.
	 *
	 * @param string $stored The stored text.
	 *
	 * @return void
	 */
	private function storeSetting(string $stored): void {
		$this->appConfig->method('getValueString')
			->with('opencatalogi', 'publication_review_required', 'true')
			->willReturn($stored);

	}//end storeSetting()

	/**
	 * Review required: the draft cannot skip it, and the refusal names the action.
	 *
	 * @return void
	 */
	public function testPublishWithoutReviewIsRefusedWhenReviewIsRequired(): void {
		$this->storeSetting('true');

		$result = $this->guard->check(['status' => 'draft'], 'publishWithoutReview', 'editor');

		self::assertFalse($result->isAllowed());
		self::assertStringContainsString('publishWithoutReview', (string)$result->getMessage());
	}//end testPublishWithoutReviewIsRefusedWhenReviewIsRequired()

	/**
	 * Review off: the draft is published directly.
	 *
	 * @return void
	 */
	public function testPublishWithoutReviewIsAllowedWhenReviewIsOff(): void {
		$this->storeSetting('false');

		self::assertTrue($this->guard->check(['status' => 'draft'], 'publishWithoutReview', 'editor')->isAllowed());
	}//end testPublishWithoutReviewIsAllowedWhenReviewIsOff()

	/**
	 * Unset, or anything but the text `false`, keeps review on (fail closed).
	 *
	 * @return void
	 */
	public function testAnyOtherStoredValueKeepsReviewOn(): void {
		$this->storeSetting('no');

		self::assertTrue((new PublicationReviewPolicy($this->appConfig))->isReviewRequired());
		self::assertFalse($this->guard->check([], 'publishWithoutReview', 'editor')->isAllowed());
	}//end testAnyOtherStoredValueKeepsReviewOn()

	/**
	 * A setting that cannot be read refuses rather than opening the shortcut.
	 *
	 * @return void
	 */
	public function testAnUnreadableSettingRefuses(): void {
		$this->appConfig->method('getValueString')->willThrowException(new RuntimeException('config down'));

		self::assertFalse($this->guard->check([], 'publishWithoutReview', 'editor')->isAllowed());
	}//end testAnUnreadableSettingRefuses()

	/**
	 * The embargo listener for an editor outside the lift groups, with publication schema 7.
	 *
	 * The publish transitions carry no lifecycle guard for the embargo: a guard sees only the
	 * stored object, not the transition's inputs, so it cannot tell publish now from a publish
	 * that keeps the scheduled date. The transition's save dispatches ObjectUpdatingEvent with
	 * the inputs merged into the same write, so this listener meets every publish transition.
	 *
	 * @return EmbargoGuardListener
	 */
	private function embargoListener(): EmbargoGuardListener {
		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				'publication_schema' => '7',
				default => $default,
			}
		);
		$groups = $this->createMock(originalClassName: IGroupManager::class);
		$groups->method('getUserGroupIds')->willReturn(['editors']);
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($this->createMock(originalClassName: IUser::class));
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));

		return new EmbargoGuardListener(
			guard: new EmbargoGuard(config: $config, groups: $groups, logger: new NullLogger()),
			userSession: $session,
			config: $config,
			l10n: $l10n
		);
	}//end embargoListener()

	/**
	 * The save a publish transition makes: the status flip and the inputs in one write.
	 *
	 * @param string $from            The stored status.
	 * @param string $stored          The stored publication date.
	 * @param string $publicationDate The publication date the transition writes.
	 *
	 * @return ObjectUpdatingEvent
	 */
	private function transitionSave(string $from, string $stored, string $publicationDate): ObjectUpdatingEvent {
		$old = new ObjectEntity();
		$old->setSchema('7');
		$old->setObject(['title' => 'Besluit', 'status' => $from, 'publicationDate' => $stored]);
		$new = new ObjectEntity();
		$new->setSchema('7');
		$new->setObject(['title' => 'Besluit', 'status' => 'published', 'publicationDate' => $publicationDate]);

		return new ObjectUpdatingEvent($new, $old);
	}//end transitionSave()

	/**
	 * REQ-PSG-001: `publish` and `publishWithoutReview` with today's date are refused while the embargo holds.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/publication-schedule-guards/tasks.md#task-1.3
	 */
	public function testThePublishTransitionIsRefusedUnderEmbargo(): void {
		$embargo = (new DateTimeImmutable(datetime: '+7 days'))->format(format: DATE_ATOM);
		$now = (new DateTimeImmutable())->format(format: DATE_ATOM);

		foreach (['approved' => 'publish', 'draft' => 'publishWithoutReview'] as $from => $action) {
			$event = $this->transitionSave(from: $from, stored: $embargo, publicationDate: $now);
			$this->embargoListener()->handle($event);

			self::assertTrue(condition: $event->isPropagationStopped(), message: $action);
			self::assertSame(expected: 'embargo-holds', actual: $event->getErrors()['code'], message: $action);
			self::assertSame(expected: $embargo, actual: $event->getErrors()['embargoUntil'], message: $action);
		}
	}//end testThePublishTransitionIsRefusedUnderEmbargo()

	/**
	 * REQ-PSG-001: a publish transition that keeps the scheduled date schedules the publication and passes.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/publication-schedule-guards/tasks.md#task-1.3
	 */
	public function testAPublishThatKeepsTheScheduledDatePasses(): void {
		$embargo = (new DateTimeImmutable(datetime: '+7 days'))->format(format: DATE_ATOM);
		$event = $this->transitionSave(from: 'approved', stored: $embargo, publicationDate: $embargo);

		$this->embargoListener()->handle($event);

		self::assertFalse(condition: $event->isPropagationStopped());
		self::assertSame(expected: [], actual: $event->getErrors());
	}//end testAPublishThatKeepsTheScheduledDatePasses()
}//end class
