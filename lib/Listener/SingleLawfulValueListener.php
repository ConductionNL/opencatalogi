<?php

/**
 * OpenCatalogi single lawful value listener
 *
 * Fills, before a publication is first written, each empty field its officer
 * has exactly one lawful value for.
 *
 * @category Listener
 * @package  OCA\OpenCatalogi\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Listener;

use OCA\OpenCatalogi\Contract\SingleLawfulValueInterface;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Runs SingleLawfulValue on OpenRegister's pre-save create event (REQ-WMS-004).
 *
 * Only what it fills goes back to OpenRegister, as modified data merged into the
 * save. Any failure leaves the object as it was and the save goes on.
 *
 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-a-field-with-one-lawful-value-for-this-officer-fills-itself-req-wms-004
 *
 * @template-implements IEventListener<Event>
 */
class SingleLawfulValueListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param SingleLawfulValueInterface $lawfulValue Computes the fills.
	 * @param IUserSession $userSession The officer creating the object.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly SingleLawfulValueInterface $lawfulValue,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Fill the one-value fields of a publication being created.
	 *
	 * @param Event $event The OpenRegister pre-save event.
	 *
	 * @return void
	 *
	 * @psalm-suppress InvalidArgument OpenRegister events extend OCP Event.
	 *
	 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-a-field-with-one-lawful-value-for-this-officer-fills-itself-req-wms-004
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === false) {
			return;
		}

		$officer = $this->userSession->getUser();
		if ($officer === null) {
			return;
		}

		try {
			$filled = $this->filledFields(event: $event, officer: $officer);
			if ($filled !== []) {
				$event->setModifiedData(array_merge($event->getModifiedData(), $filled));
			}
		} catch (\Throwable $e) {
			// Nothing is filled and the save goes on (REQ-WMS-004: fail closed).
			$this->logger->warning('[SingleLawfulValueListener] nothing filled: ' . $e->getMessage());
		}//end try

	}//end handle()

	/**
	 * The fields the single-lawful-value rule fills on the object being created.
	 *
	 * @param ObjectCreatingEvent $event The OpenRegister pre-save event.
	 * @param IUser $officer The officer creating the object.
	 *
	 * @return array<string, mixed> The filled fields and their values.
	 *
	 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-a-field-with-one-lawful-value-for-this-officer-fills-itself-req-wms-004
	 */
	private function filledFields(ObjectCreatingEvent $event, IUser $officer): array {
		$entity = $event->getObject();
		$before = array_merge(($entity->getObject() ?? []), $event->getModifiedData());
		$before['@self'] = ['register' => (string)$entity->getRegister(), 'schema' => (string)$entity->getSchema()];
		$after = $this->lawfulValue->fill(publication: $before, officer: $officer);

		$filled = [];
		foreach (['organization', 'wooCategory', 'filledByRule'] as $field) {
			if (($after[$field] ?? null) !== ($before[$field] ?? null)) {
				$filled[$field] = $after[$field];
			}
		}

		return $filled;
	}//end filledFields()
}//end class
