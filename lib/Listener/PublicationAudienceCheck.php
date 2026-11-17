<?php

/**
 * OpenCatalogi PublicationAudienceCheck listener.
 *
 * Refuses, before OpenRegister stores it, a publication that is limited to
 * signed-in readers and carries a Woo information category: a Woo publication
 * is public by law (publications-for-signed-in-readers, REQ-PSR-004). The rule
 * itself is {@see PublicationValidationService::refusal()}.
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
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/publications-for-signed-in-readers/specs/publications/spec.md#requirement-a-woo-publication-stays-public-req-psr-004
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Listener;

use OCA\OpenCatalogi\Service\Publication\PublicationValidationService;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IL10N;

/**
 * Refuses a Woo publication for signed-in readers only.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/publications-for-signed-in-readers/specs/publications/spec.md#requirement-a-woo-publication-stays-public-req-psr-004
 */
class PublicationAudienceCheck implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param PublicationValidationService $validation The publication's own rules.
	 * @param IL10N                        $l10n       Translates the refusal.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly PublicationValidationService $validation,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * Check a publication that is about to be created or updated.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/publications-for-signed-in-readers/specs/publications/spec.md#requirement-a-woo-publication-stays-public-req-psr-004
	 */
	public function handle(Event $event): void {
		$object = null;
		if ($event instanceof ObjectCreatingEvent === true) {
			$object = $event->getObject();
		}

		if ($event instanceof ObjectUpdatingEvent === true) {
			$object = $event->getNewObject();
		}

		if ($object === null) {
			return;
		}

		$refusal = $this->validation->refusal(publication: ($object->getObject() ?? []));
		if ($refusal === null) {
			return;
		}

		$event->setErrors(
			[
				'message' => $this->l10n->t($refusal['message']),
				'property' => $refusal['property'],
			]
		);
		$event->stopPropagation();
	}//end handle()
}//end class
