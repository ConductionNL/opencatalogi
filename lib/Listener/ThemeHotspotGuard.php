<?php

/**
 * OpenCatalogi Theme Hotspot Guard.
 *
 * An administrator marks a subject as an archive hotspot: every publication
 * filed under it is kept permanently. Before a subject is saved, a change of
 * the marking is refused for anyone but an administrator and without a reason;
 * marking stamps the moment and sets the subject's OpenRegister appraisal to
 * permanent, keeping the appraisal it had, and unmarking puts that back.
 *
 * @category Listener
 * @package  OCA\OpenCatalogi\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/theme-archive-hotspot/tasks.md#task-1.2
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Listener;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUserSession;

/**
 * Guards and applies the archive hotspot marking of a subject before it is saved.
 *
 * @spec openspec/changes/theme-archive-hotspot/specs/publication-retention-lifecycle/spec.md#requirement-an-administrator-marks-a-subject-as-an-archive-hotspot-req-tha-001
 *
 * @template-implements IEventListener<Event>
 */
class ThemeHotspotGuard implements IEventListener {

	/**
	 * The appraisal a hotspot subject carries: keep permanently.
	 */
	public const PERMANENT = 'blijvend_bewaren';

	/**
	 * Where the retention keeps the appraisal the subject had before it was marked.
	 */
	public const PREVIOUS = 'hotspotPreviousAppraisal';

	/**
	 * Constructor.
	 *
	 * @param ThemeHotspotChange $change Says whether the save changes the marking.
	 * @param IUserSession $session The user who saves.
	 * @param IGroupManager $groups Says whether that user is an administrator.
	 * @param IL10N $l10n Localisation.
	 *
	 * @spec exclude pure framework plumbing: constructor dependency injection.
	 */
	public function __construct(
		private readonly ThemeHotspotChange $change,
		private readonly IUserSession $session,
		private readonly IGroupManager $groups,
		private readonly IL10N $l10n,
	) {

	}//end __construct()

	/**
	 * Refuse a marking change by a non-administrator or without a reason; otherwise apply it.
	 *
	 * @param Event $event The OpenRegister pre-save event.
	 *
	 * @return void
	 *
	 * @psalm-suppress InvalidArgument OpenRegister events extend OCP Event.
	 *
	 * @spec openspec/changes/theme-archive-hotspot/specs/publication-retention-lifecycle/spec.md#requirement-an-administrator-marks-a-subject-as-an-archive-hotspot-req-tha-001
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectUpdatingEvent === true) {
			$this->guard(event: $event, pair: [$event->getNewObject(), $event->getOldObject()]);
			return;
		}

		if ($event instanceof ObjectCreatingEvent === true) {
			$this->guard(event: $event, pair: [$event->getObject(), null]);
		}

	}//end handle()

	/**
	 * Refuse or apply the marking change the save makes.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The pre-save event.
	 * @param array{0: ObjectEntity, 1: ObjectEntity|null} $pair The object as written and as stored before, null on create.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/theme-archive-hotspot/specs/publication-retention-lifecycle/spec.md#requirement-an-administrator-marks-a-subject-as-an-archive-hotspot-req-tha-001
	 */
	private function guard(ObjectCreatingEvent|ObjectUpdatingEvent $event, array $pair): void {
		[$written, $stored] = $pair;
		if ($this->change->changes(written: $written, stored: $stored) === false) {
			return;
		}

		$refusal = $this->refusal(data: ($written->getObject() ?? []));
		if ($refusal !== null) {
			$event->setErrors($refusal);
			$event->stopPropagation();
			return;
		}

		$marked = $this->change->isMarked(entity: $written);
		$written->setRetention(self::appraised(retention: ($written->getRetention() ?? []), marked: $marked));
		$since = null;
		if ($marked === true) {
			$since = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
		}

		$event->setModifiedData(array_merge($event->getModifiedData(), ['archiveHotspotSince' => $since]));

	}//end guard()

	/**
	 * Why the marking change may not be saved, or null when it may.
	 *
	 * @param array<string, mixed> $data The subject as written.
	 *
	 * @return array{message: string, property: string}|null The refusal.
	 */
	private function refusal(array $data): ?array {
		$user = $this->session->getUser();
		if ($user === null || $this->groups->isAdmin($user->getUID()) === false) {
			return ['message' => $this->l10n->t('Only an administrator can mark or unmark an archive hotspot.'), 'property' => 'archiveHotspot'];
		}

		if (trim((string)($data['archiveHotspotReason'] ?? '')) === '') {
			return ['message' => $this->l10n->t('Give the reason for the archive hotspot.'), 'property' => 'archiveHotspotReason'];
		}

		return null;

	}//end refusal()

	/**
	 * The retention after the marking change: permanent with the old appraisal kept, or the old appraisal back.
	 *
	 * @param array<string, mixed> $retention The subject's retention.
	 * @param bool $marked Whether the save marks the subject.
	 *
	 * @return array<string, mixed> The retention to store.
	 *
	 * @spec openspec/changes/theme-archive-hotspot/specs/publication-retention-lifecycle/spec.md#requirement-an-administrator-marks-a-subject-as-an-archive-hotspot-req-tha-001
	 */
	private static function appraised(array $retention, bool $marked): array {
		if ($marked === true) {
			if (array_key_exists(self::PREVIOUS, $retention) === false) {
				$retention[self::PREVIOUS] = ($retention['archiefnominatie'] ?? null);
			}

			$retention['archiefnominatie'] = self::PERMANENT;
			return $retention;
		}

		$previous = ($retention[self::PREVIOUS] ?? null);
		unset($retention[self::PREVIOUS], $retention['archiefnominatie']);
		if ($previous !== null) {
			$retention['archiefnominatie'] = $previous;
		}

		return $retention;

	}//end appraised()
}//end class
