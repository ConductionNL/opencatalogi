<?php

/**
 * OpenCatalogi Document Reference Listener.
 *
 * Refuses, before OpenRegister stores it, a document reference whose URL is
 * not a public https address (REQ-PBR-001), with the reason the form shows.
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
 * @spec openspec/changes/integration-publish-by-reference/specs/publication-document-references/spec.md#requirement-an-editor-adds-a-document-by-its-public-url-req-pbr-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Listener;

use OCA\OpenCatalogi\Service\DocumentReferences;
use OCA\OpenCatalogi\Service\DocumentReferenceUrlPolicy;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use OCP\IUserSession;

/**
 * Stops the save of a reference to a private address.
 *
 * A write without a session (the seed import, a background job) is the
 * system's own and passes. An update that leaves the URL as it was passes too,
 * so the daily check can always record its result.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/integration-publish-by-reference/specs/publication-document-references/spec.md#requirement-an-editor-adds-a-document-by-its-public-url-req-pbr-001
 */
class DocumentReferenceListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param DocumentReferenceUrlPolicy $policy Decides the URL.
	 * @param IAppConfig $config Holds the schema id.
	 * @param IUserSession $userSession The caller.
	 */
	public function __construct(
		private readonly DocumentReferenceUrlPolicy $policy,
		private readonly IAppConfig $config,
		private readonly IUserSession $userSession,
	) {
	}//end __construct()

	/**
	 * Refuse a reference to an address that is not public.
	 *
	 * @param Event $event The pre-save event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/integration-publish-by-reference/specs/publication-document-references/spec.md#requirement-an-editor-adds-a-document-by-its-public-url-req-pbr-001
	 */
	public function handle(Event $event): void {
		$old = null;
		if ($event instanceof ObjectCreatingEvent === true) {
			$new = $event->getObject();
		} elseif ($event instanceof ObjectUpdatingEvent === true) {
			$new = $event->getNewObject();
			$old = $event->getOldObject();
		} else {
			return;
		}

		if ($this->isReference(object: $new) === false || $this->userSession->getUser() === null) {
			return;
		}

		$url = (string)(($new->getObject() ?? [])['url'] ?? '');
		if ($old !== null && $url === (string)(($old->getObject() ?? [])['url'] ?? '')) {
			return;
		}

		$reason = $this->policy->refusal(url: $url);
		if ($reason === null) {
			return;
		}

		$event->setErrors(['message' => $reason, 'field' => 'url']);
		$event->stopPropagation();
	}//end handle()

	/**
	 * Whether the object is a documentReference.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return bool True when it is.
	 */
	private function isReference(ObjectEntity $object): bool {
		$schema = $this->config->getValueString('opencatalogi', DocumentReferences::SCHEMA_KEY, '');
		return $schema !== '' && (string)$object->getSchema() === $schema;
	}//end isReference()
}//end class
