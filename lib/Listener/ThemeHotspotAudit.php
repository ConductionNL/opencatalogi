<?php

/**
 * OpenCatalogi Theme Hotspot Audit.
 *
 * After a subject is saved, a change of its archive hotspot marking is written
 * to OpenRegister's audit trail with the user and the reason. Written after the
 * save, so a save another listener refuses leaves no entry.
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

use OCA\OpenCatalogi\Contract\OpenRegisterServicesInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Audits a saved change of a subject's archive hotspot marking.
 *
 * @spec openspec/changes/theme-archive-hotspot/specs/publication-retention-lifecycle/spec.md#requirement-an-administrator-marks-a-subject-as-an-archive-hotspot-req-tha-001
 *
 * @template-implements IEventListener<Event>
 */
class ThemeHotspotAudit implements IEventListener {

	/**
	 * The audit actions of a marking and an unmarking.
	 */
	public const MARKED = 'theme.archiveHotspot.marked';

	public const UNMARKED = 'theme.archiveHotspot.unmarked';

	/**
	 * Constructor.
	 *
	 * @param ThemeHotspotChange $change Says whether the save changed the marking.
	 * @param IUserSession $session The user who saved.
	 * @param OpenRegisterServicesInterface $openRegister OpenRegister's audit trail.
	 * @param LoggerInterface $logger Says when the entry could not be written.
	 *
	 * @spec exclude pure framework plumbing: constructor dependency injection.
	 */
	public function __construct(
		private readonly ThemeHotspotChange $change,
		private readonly IUserSession $session,
		private readonly OpenRegisterServicesInterface $openRegister,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Write the audit entry of a saved marking change.
	 *
	 * @param Event $event The OpenRegister post-save event.
	 *
	 * @return void
	 *
	 * @psalm-suppress InvalidArgument OpenRegister events extend OCP Event.
	 *
	 * @spec openspec/changes/theme-archive-hotspot/specs/publication-retention-lifecycle/spec.md#requirement-an-administrator-marks-a-subject-as-an-archive-hotspot-req-tha-001
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectUpdatedEvent === true) {
			$this->audit(written: $event->getNewObject(), stored: $event->getOldObject());
			return;
		}

		if ($event instanceof ObjectCreatedEvent === true) {
			$this->audit(written: $event->getObject(), stored: null);
		}

	}//end handle()

	/**
	 * Audit the marking or unmarking a save made; a failed write is logged, the save stands.
	 *
	 * @param ObjectEntity $written The subject as saved.
	 * @param ObjectEntity|null $stored The subject as stored before, null on create.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/theme-archive-hotspot/specs/publication-retention-lifecycle/spec.md#requirement-an-administrator-marks-a-subject-as-an-archive-hotspot-req-tha-001
	 */
	private function audit(ObjectEntity $written, ?ObjectEntity $stored): void {
		if ($this->change->changes(written: $written, stored: $stored) === false) {
			return;
		}

		$action = self::UNMARKED;
		if ($this->change->isMarked(entity: $written) === true) {
			$action = self::MARKED;
		}

		$context = [
			'user' => $this->session->getUser()?->getUID(),
			'reason' => trim((string)(($written->getObject() ?? [])['archiveHotspotReason'] ?? '')),
		];
		try {
			$mapper = $this->openRegister->installedService('OCA\OpenRegister\Db\AuditTrailMapper', 'OpenRegister is not available.');
			$mapper->createAuditTrail(old: $stored, new: $written, action: $action, cascadeContext: $context);
		} catch (Throwable $e) {
			$this->logger->error('[ThemeHotspotAudit] The archive hotspot change was saved but not audited: ' . $e->getMessage());
		}

	}//end audit()
}//end class
