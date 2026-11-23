<?php

/**
 * OpenCatalogi Theme Hotspot Listener.
 *
 * An administrator marks a subject as an archive hotspot: every publication
 * filed under it is kept permanently. Before a subject is saved, a change of
 * the marking is refused for anyone but an administrator and without a reason;
 * marking stamps the moment and sets the subject's OpenRegister appraisal to
 * permanent, keeping the appraisal it had, and unmarking puts that back. After
 * the save the change is written to the audit trail with the user and reason.
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

use DateTimeInterface;
use OCA\OpenCatalogi\Contract\OpenRegisterServicesInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Guards, applies and audits the archive hotspot marking of a subject.
 *
 * @spec openspec/changes/theme-archive-hotspot/specs/publication-retention-lifecycle/spec.md#requirement-an-administrator-marks-a-subject-as-an-archive-hotspot-req-tha-001
 *
 * @template-implements IEventListener<Event>
 */
class ThemeHotspotListener implements IEventListener {

	/**
	 * The appraisal a hotspot subject carries: keep permanently.
	 */
	public const PERMANENT = 'blijvend_bewaren';

	/**
	 * Where the retention keeps the appraisal the subject had before it was marked.
	 */
	public const PREVIOUS = 'hotspotPreviousAppraisal';

	/**
	 * The audit actions of a marking and an unmarking.
	 */
	public const MARKED = 'theme.archiveHotspot.marked';

	public const UNMARKED = 'theme.archiveHotspot.unmarked';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $config Names the theme schema.
	 * @param IUserSession $session The user who saves.
	 * @param IGroupManager $groups Says whether that user is an administrator.
	 * @param ITimeFactory $time The moment of marking.
	 * @param OpenRegisterServicesInterface $openRegister OpenRegister's audit trail.
	 * @param IL10N $l10n Localisation.
	 * @param LoggerInterface $logger Says when the audit entry could not be written.
	 *
	 * @spec exclude pure framework plumbing: constructor dependency injection.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Each is one framework service the marking needs.
	 */
	public function __construct(
		private readonly IAppConfig $config,
		private readonly IUserSession $session,
		private readonly IGroupManager $groups,
		private readonly ITimeFactory $time,
		private readonly OpenRegisterServicesInterface $openRegister,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Guard and apply a marking before the save, audit it after.
	 *
	 * @param Event $event The OpenRegister object event.
	 *
	 * @return void
	 *
	 * @psalm-suppress InvalidArgument OpenRegister events extend OCP Event.
	 *
	 * @spec openspec/changes/theme-archive-hotspot/specs/publication-retention-lifecycle/spec.md#requirement-an-administrator-marks-a-subject-as-an-archive-hotspot-req-tha-001
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === true || $event instanceof ObjectUpdatingEvent === true) {
			$this->beforeSave(event: $event);
			return;
		}

		if ($event instanceof ObjectCreatedEvent === true || $event instanceof ObjectUpdatedEvent === true) {
			$this->afterSave(event: $event);
		}

	}//end handle()

	/**
	 * Refuse a marking change by a non-administrator or without a reason; otherwise apply it.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The pre-save event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/theme-archive-hotspot/specs/publication-retention-lifecycle/spec.md#requirement-an-administrator-marks-a-subject-as-an-archive-hotspot-req-tha-001
	 */
	private function beforeSave(ObjectCreatingEvent|ObjectUpdatingEvent $event): void {
		$pair = $this->pairOf(event: $event);
		if ($this->isTheme(entity: $pair[0]) === false || self::changes(pair: $pair) === false) {
			return;
		}

		$refusal = $this->refusal(data: ($pair[0]->getObject() ?? []));
		if ($refusal !== null) {
			$event->setErrors($refusal);
			$event->stopPropagation();
			return;
		}

		$marked = self::isMarked(entity: $pair[0]);
		$pair[0]->setRetention(self::appraised(retention: ($pair[0]->getRetention() ?? []), marked: $marked));
		$since = null;
		if ($marked === true) {
			$since = $this->time->getDateTime()->format(DateTimeInterface::ATOM);
		}

		$event->setModifiedData(array_merge($event->getModifiedData(), ['archiveHotspotSince' => $since]));

	}//end beforeSave()

	/**
	 * Write the audit entry of a saved marking change.
	 *
	 * @param ObjectCreatedEvent|ObjectUpdatedEvent $event The post-save event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/theme-archive-hotspot/specs/publication-retention-lifecycle/spec.md#requirement-an-administrator-marks-a-subject-as-an-archive-hotspot-req-tha-001
	 */
	private function afterSave(ObjectCreatedEvent|ObjectUpdatedEvent $event): void {
		$pair = $this->pairOf(event: $event);
		if ($this->isTheme(entity: $pair[0]) === false || self::changes(pair: $pair) === false) {
			return;
		}

		$action = self::UNMARKED;
		if (self::isMarked(entity: $pair[0]) === true) {
			$action = self::MARKED;
		}

		try {
			$this->openRegister->installedService('OCA\OpenRegister\Db\AuditTrailMapper', 'OpenRegister is not available.')->createAuditTrail(
				old: $pair[1],
				new: $pair[0],
				action: $action,
				cascadeContext: [
					'user' => $this->session->getUser()?->getUID(),
					'reason' => trim((string)(($pair[0]->getObject() ?? [])['archiveHotspotReason'] ?? '')),
				]
			);
		} catch (Throwable $e) {
			$this->logger->error('[ThemeHotspotListener] The archive hotspot change was saved but not audited: ' . $e->getMessage());
		}

	}//end afterSave()

	/**
	 * The object as written and as stored before, the latter null on create.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent|ObjectCreatedEvent|ObjectUpdatedEvent $event The event.
	 *
	 * @return array{0: ObjectEntity, 1: ObjectEntity|null} The pair.
	 */
	private function pairOf(ObjectCreatingEvent|ObjectUpdatingEvent|ObjectCreatedEvent|ObjectUpdatedEvent $event): array {
		if ($event instanceof ObjectUpdatingEvent === true || $event instanceof ObjectUpdatedEvent === true) {
			return [$event->getNewObject(), $event->getOldObject()];
		}

		return [$event->getObject(), null];

	}//end pairOf()

	/**
	 * Whether the object is in the configured theme schema.
	 *
	 * @param ObjectEntity $entity The object.
	 *
	 * @return bool True for a subject.
	 */
	private function isTheme(ObjectEntity $entity): bool {
		$schema = (string)$entity->getSchema();
		return $schema !== '' && $schema === trim($this->config->getValueString('opencatalogi', 'theme_schema', ''));

	}//end isTheme()

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
	 * Whether the save changes the marking.
	 *
	 * @param array{0: ObjectEntity, 1: ObjectEntity|null} $pair The object as written and as stored.
	 *
	 * @return bool True when it changes.
	 */
	private static function changes(array $pair): bool {
		$before = false;
		if ($pair[1] !== null) {
			$before = self::isMarked(entity: $pair[1]);
		}

		return self::isMarked(entity: $pair[0]) !== $before;

	}//end changes()

	/**
	 * Whether a subject is marked as an archive hotspot.
	 *
	 * @param ObjectEntity $entity The subject.
	 *
	 * @return bool True when marked.
	 */
	private static function isMarked(ObjectEntity $entity): bool {
		return (($entity->getObject() ?? [])['archiveHotspot'] ?? false) === true;

	}//end isMarked()

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
