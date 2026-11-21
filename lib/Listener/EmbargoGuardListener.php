<?php
/**
 * Embargo guard listener
 *
 * Runs the embargo guard on every update of a publication, so a save through
 * OpenCatalogi or OpenRegister's object API that would release a publication
 * under embargo early is refused before it is stored.
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
 * @spec openspec/changes/publication-schedule-guards/specs/publications/spec.md#requirement-an-embargo-refuses-every-write-that-would-release-the-record-early-req-psg-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Listener;

use OCA\OpenCatalogi\Service\Publication\EmbargoGuard;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IUserSession;

/**
 * Refuses an update that moves a publication's embargo earlier.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/publication-schedule-guards/specs/publications/spec.md#requirement-an-embargo-refuses-every-write-that-would-release-the-record-early-req-psg-001
 */
class EmbargoGuardListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param EmbargoGuard $guard       Decides whether the write releases the record early.
	 * @param IUserSession $userSession The writing user.
	 * @param IAppConfig   $config      Names the publication schema.
	 * @param IL10N        $l10n        Translates the refusal.
	 */
	public function __construct(
		private readonly EmbargoGuard $guard,
		private readonly IUserSession $userSession,
		private readonly IAppConfig $config,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * Check a publication that is about to be updated.
	 *
	 * An update without the stored object carries no embargo to keep.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/publication-schedule-guards/tasks.md#task-1.1
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectUpdatingEvent === false || $event->getOldObject() === null) {
			return;
		}

		$schema = $this->config->getValueString('opencatalogi', 'publication_schema', '');
		if ($schema === '' || (string)$event->getNewObject()->getSchema() !== $schema) {
			return;
		}

		$refusal = $this->guard->check(
			old: ($event->getOldObject()->getObject() ?? []),
			new: ($event->getNewObject()->getObject() ?? []),
			caller: $this->userSession->getUser()
		);
		if ($refusal === null) {
			return;
		}

		$event->setErrors(
			array_merge(
				$refusal,
				[
					'message' => $this->l10n->t(
						'This publication is under embargo until %s. Only a user who may lift an embargo can move its publication date earlier.',
						[($refusal['embargoUntil'] ?? '?')]
					),
				]
			)
		);
		$event->stopPropagation();
	}//end handle()
}//end class
