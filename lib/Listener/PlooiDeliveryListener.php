<?php

/**
 * OpenCatalogi PLOOI delivery listener.
 *
 * Queues a PLOOI delivery when a publication has just become public. It only
 * compares the old and the new publication dates and queues a job; the
 * delivery itself runs in PlooiDelivery, outside the editor's request.
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
 * @spec openspec/changes/woo-national-delivery-repair/specs/woo-compliance/spec.md#requirement-a-publication-that-turns-public-is-delivered-to-plooi-when-the-catalogue-asks-for-it-req-wnd-003
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Listener;

use OCA\OpenCatalogi\BackgroundJob\PlooiDelivery;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Queues the PLOOI delivery of a publication that turned public.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/woo-national-delivery-repair/specs/woo-compliance/spec.md#requirement-a-publication-that-turns-public-is-delivered-to-plooi-when-the-catalogue-asks-for-it-req-wnd-003
 */
class PlooiDeliveryListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param IJobList $jobList The background job list.
	 */
	public function __construct(
		private readonly IJobList $jobList,
	) {

	}//end __construct()

	/**
	 * Queue a delivery when the publication has just become public.
	 *
	 * Whether a catalogue asks for PLOOI is decided in the job, not here, so
	 * this handler reads nothing and writes nothing on the save path.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-national-delivery-repair/specs/woo-compliance/spec.md#requirement-a-publication-that-turns-public-is-delivered-to-plooi-when-the-catalogue-asks-for-it-req-wnd-003
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectUpdatedEvent === false) {
			return;
		}

		$new = $event->getNewObject();
		$old = $event->getOldObject();
		if ($this->isPublic(entity: $new) === false) {
			return;
		}

		if ($old !== null && $this->isPublic(entity: $old) === true) {
			return;
		}

		$this->jobList->add(
			PlooiDelivery::class,
			[
				'uuid' => (string)$new->getUuid(),
				'register' => (string)$new->getRegister(),
				'schema' => (string)$new->getSchema(),
			]
		);

	}//end handle()

	/**
	 * Whether an object is public now: a publication date reached and no depublication date passed.
	 *
	 * @param ObjectEntity $entity The object.
	 *
	 * @return bool True when public.
	 */
	private function isPublic(ObjectEntity $entity): bool {
		$data = $entity->jsonSerialize();
		$published = strtotime((string)($data['publicationDate'] ?? ''));
		if ($published === false || $published > time()) {
			return false;
		}

		$depublished = strtotime((string)($data['depublicationDate'] ?? ''));
		return ($depublished === false || $depublished > time());

	}//end isPublic()
}//end class
