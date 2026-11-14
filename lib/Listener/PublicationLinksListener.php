<?php

/**
 * Writes a publication's public page and officer page on every save.
 *
 * A source system creating a publication through OpenRegister's object API
 * gets both pages back in the response. Both are read-only: whatever a caller
 * sends for them, on a create or an update, is replaced
 * (publication-tells-its-source, REQ-PTS-001).
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
 * @spec openspec/changes/publication-tells-its-source/specs/publications/spec.md#requirement-a-publication-carries-its-public-page-and-its-officer-page-req-pts-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Listener;

use OCA\OpenCatalogi\Service\Publication\PublicationLinks;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;

/**
 * Sets `publicUrl` and `officerUrl` on a publication before it is stored.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/publication-tells-its-source/specs/publications/spec.md#requirement-a-publication-carries-its-public-page-and-its-officer-page-req-pts-001
 */
class PublicationLinksListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param PublicationLinks $links  Builds both pages.
	 * @param IAppConfig       $config Names the publication schema.
	 *
	 * @spec openspec/changes/publication-tells-its-source/specs/publications/spec.md#requirement-a-publication-carries-its-public-page-and-its-officer-page-req-pts-001
	 */
	public function __construct(
		private readonly PublicationLinks $links,
		private readonly IAppConfig $config,
	) {
	}//end __construct()

	/**
	 * Set both pages on a publication about to be stored.
	 *
	 * OpenRegister gives the object its uuid before it dispatches the event,
	 * so the pages can name it. Without a uuid both stay empty.
	 *
	 * @param Event $event The pre-save event, a create or an update.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/publication-tells-its-source/specs/publications/spec.md#requirement-a-publication-carries-its-public-page-and-its-officer-page-req-pts-001
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === true) {
			$object = $event->getObject();
		} else if ($event instanceof ObjectUpdatingEvent === true) {
			// An update cannot set them either: they are computed again.
			$object = $event->getNewObject();
		} else {
			return;
		}

		$schema = $this->config->getValueString('opencatalogi', 'publication_schema', '');
		if ($schema === '' || (string)$object->getSchema() !== $schema) {
			return;
		}

		$links = $this->links->for(uuid: (string)$object->getUuid(), schema: $schema);
		$event->setModifiedData(array_merge($event->getModifiedData(), $links));
	}//end handle()
}//end class
