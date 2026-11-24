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
		if ($event instanceof ObjectCreatingEvent === true) {
			$this->check(event: $event, publication: ($event->getObject()->getObject() ?? []));
			return;
		}

		if ($event instanceof ObjectUpdatingEvent === true) {
			$this->check(event: $event, publication: ($event->getNewObject()->getObject() ?? []));
		}
	}//end handle()

	/**
	 * Stop the save when the publication breaks one of its own rules.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event       The event.
	 * @param array<string, mixed>                    $publication The publication as it would be stored.
	 *
	 * @return void
	 */
	private function check(ObjectCreatingEvent|ObjectUpdatingEvent $event, array $publication): void {
		$refusal = $this->validation->refusal(publication: $publication);
		if ($refusal === null) {
			return;
		}

		$event->setErrors(
			[
				'message' => $this->translate(message: $refusal['message']),
				'property' => $refusal['property'],
			]
		);
		$event->stopPropagation();
	}//end check()

	/**
	 * Translate a refusal's source string.
	 *
	 * A known refusal is translated through a literal call, so the l10n tools
	 * (`npm run check:l10n`, Nextcloud's extractor) see its key; a call with a
	 * variable hides the key from both. An unknown message still goes through
	 * the translator.
	 *
	 * @param string $message The refusal's source string.
	 *
	 * @return string The translated message.
	 *
	 * @spec openspec/changes/publications-for-signed-in-readers/specs/publications/spec.md#requirement-a-woo-publication-stays-public-req-psr-004
	 */
	private function translate(string $message): string {
		return match ($message) {
			PublicationValidationService::WOO_STAYS_PUBLIC => $this->l10n->t(
				'A Woo publication is public by law. Remove the Woo category or make it readable for everyone.'
			),
			default => $this->l10n->t($message),
		};
	}//end translate()
}//end class
