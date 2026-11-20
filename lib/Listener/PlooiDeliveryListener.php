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
 * @spec openspec/specs/woo-compliance/spec.md#requirement-a-publication-that-turns-public-is-delivered-to-plooi-when-the-catalogue-asks-for-it-req-wnd-003
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
 * @spec openspec/specs/woo-compliance/spec.md#requirement-a-publication-that-turns-public-is-delivered-to-plooi-when-the-catalogue-asks-for-it-req-wnd-003
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
	 * The queue() mode of a first delivery: the job argument carries no mode.
	 *
	 * @var string
	 */
	private const MODE_FIRST = 'first';

	/**
	 * The publication fields a DiWoo document or the PLOOI delivery carries.
	 *
	 * A change to any other field (an internal note, a retention date) does
	 * not change what PLOOI holds, so it queues nothing.
	 *
	 * @var list<string>
	 */
	public const DIWOO_FIELDS = [
		'title',
		'summary',
		'description',
		'publicationDate',
		'organization',
		'category',
		'wooCategory',
		'soortHandeling',
		'tooiCategorieNaam',
		'tooiCategorieUri',
		'tooiIdentifier',
	];

	/**
	 * Queue a delivery when the publication has just become public, an update
	 * when a delivered publication changed what PLOOI holds, and a withdrawal
	 * when a delivered publication stopped being public.
	 *
	 * Whether a catalogue asks for PLOOI is decided in the job, not here, so
	 * this handler reads nothing and writes nothing on the save path.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/woo-compliance/spec.md#requirement-a-publication-that-turns-public-is-delivered-to-plooi-when-the-catalogue-asks-for-it-req-wnd-003
	 * @spec openspec/changes/woo-national-output-assurance/specs/woo-compliance/spec.md#requirement-a-change-to-a-delivered-publication-is-delivered-again-req-wno-001
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectUpdatedEvent === false) {
			return;
		}

		$new = $event->getNewObject();
		$mode = $this->followUp(old: $event->getOldObject(), new: $new);
		if ($mode !== null) {
			$this->queue(entity: $new, mode: $mode);
		}

	}//end handle()

	/**
	 * Which PLOOI job a save asks for: a first delivery, a withdrawal, an update, or none.
	 *
	 * @param ObjectEntity|null $old The publication before the save.
	 * @param ObjectEntity      $new The publication after it.
	 *
	 * @return string|null MODE_FIRST, PlooiDelivery::MODE_WITHDRAW or PlooiDelivery::MODE_UPDATE; null for none.
	 */
	private function followUp(?ObjectEntity $old, ObjectEntity $new): ?string {
		$newPublic = $this->isPublic(entity: $new);
		$oldPublic = ($old !== null && $this->isPublic(entity: $old) === true);

		if ($newPublic === true && $oldPublic === false) {
			return self::MODE_FIRST;
		}

		if ($old === null || $oldPublic === false || $this->isDelivered(entity: $new) === false) {
			return null;
		}

		if ($newPublic === false) {
			return PlooiDelivery::MODE_WITHDRAW;
		}

		if ($this->carriedChange(old: $old, new: $new) === true) {
			return PlooiDelivery::MODE_UPDATE;
		}

		return null;
	}//end followUp()

	/**
	 * Queue one PLOOI delivery job for a publication.
	 *
	 * @param ObjectEntity $entity The publication.
	 * @param string       $mode   The update or withdrawal mode, or MODE_FIRST for a first delivery.
	 *
	 * @return void
	 */
	private function queue(ObjectEntity $entity, string $mode): void {
		$argument = [
			'uuid' => (string)$entity->getUuid(),
			'register' => (string)$entity->getRegister(),
			'schema' => (string)$entity->getSchema(),
		];
		if ($mode !== self::MODE_FIRST) {
			$argument['mode'] = $mode;
		}

		$this->jobList->add(PlooiDelivery::class, $argument);
	}//end queue()

	/**
	 * Whether PLOOI holds the publication: its last delivery succeeded.
	 *
	 * @param ObjectEntity $entity The publication.
	 *
	 * @return bool True when `plooiStatus` is `delivered`.
	 */
	private function isDelivered(ObjectEntity $entity): bool {
		return (string)($entity->jsonSerialize()['plooiStatus'] ?? '') === 'delivered';
	}//end isDelivered()

	/**
	 * Whether an update changed a field PLOOI carries, or the set of public files.
	 *
	 * @param ObjectEntity $old The publication before.
	 * @param ObjectEntity $new The publication after.
	 *
	 * @return bool True when PLOOI must hear of it.
	 */
	private function carriedChange(ObjectEntity $old, ObjectEntity $new): bool {
		$before = $old->jsonSerialize();
		$after = $new->jsonSerialize();
		foreach (self::DIWOO_FIELDS as $field) {
			if (($before[$field] ?? null) !== ($after[$field] ?? null)) {
				return true;
			}
		}

		return self::fileLinks(data: $before) !== self::fileLinks(data: $after);
	}//end carriedChange()

	/**
	 * The download links of a publication's files, sorted, as PLOOI receives them.
	 *
	 * @param array<string, mixed> $data The serialised publication.
	 *
	 * @return list<string> The links.
	 */
	private static function fileLinks(array $data): array {
		$links = [];
		foreach ((array)($data['@self']['files'] ?? []) as $file) {
			if (is_array($file) === true && (string)($file['downloadUrl'] ?? '') !== '') {
				$links[] = (string)$file['downloadUrl'];
			}
		}

		sort($links);
		return $links;
	}//end fileLinks()

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
