<?php

/**
 * OpenCatalogi Publication Version Listener.
 *
 * Before OpenRegister writes a correction to a public publication, the old
 * public version is kept as a `publicationVersion`. When it cannot be kept the
 * correction is refused, so a public version is never erased unrecorded.
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
 * @spec openspec/changes/publication-withdrawal-aftercare/tasks.md#task-1.2
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Listener;

use OCA\OpenCatalogi\Service\Publication\PublicationVersionService;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Keeps the earlier public version of a publication before a correction is written.
 *
 * @spec openspec/changes/publication-withdrawal-aftercare/specs/publications/spec.md#requirement-a-corrected-publication-keeps-its-earlier-public-versions-reachable-req-pwa-001
 *
 * @template-implements IEventListener<Event>
 */
class PublicationVersionListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $config Names the publication schema.
	 * @param PublicationVersionService $versions Keeps the old version.
	 * @param IL10N $l10n Localisation.
	 * @param LoggerInterface $logger Logs why a correction was refused.
	 *
	 * @spec exclude pure framework plumbing: constructor dependency injection.
	 */
	public function __construct(
		private readonly IAppConfig $config,
		private readonly PublicationVersionService $versions,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Keep the old public version, or refuse the update when that fails.
	 *
	 * @param Event $event The OpenRegister pre-save event.
	 *
	 * @return void
	 *
	 * @psalm-suppress InvalidArgument OpenRegister events extend OCP Event.
	 *
	 * @spec openspec/changes/publication-withdrawal-aftercare/specs/publications/spec.md#requirement-a-corrected-publication-keeps-its-earlier-public-versions-reachable-req-pwa-001
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectUpdatingEvent === false) {
			return;
		}

		$old = $event->getOldObject();
		$new = $event->getNewObject();
		if ($old === null || $this->isPublication(schema: (string)$new->getSchema()) === false) {
			return;
		}

		try {
			if ($this->versions->replacesPublicVersion(old: $old, new: $new) === true) {
				$this->versions->snapshot(old: $old);
			}
		} catch (Throwable $e) {
			// Fail closed: a correction that would erase a public version unrecorded is not written.
			$this->logger->error(
				'[PublicationVersionListener] The earlier version of publication "' . (string)$old->getUuid() . '" was not kept: ' . $e->getMessage()
			);
			$event->setErrors(['message' => $this->l10n->t('The earlier public version could not be kept, so the change was not saved.')]);
			$event->stopPropagation();
		}

	}//end handle()

	/**
	 * Whether the written object is a publication.
	 *
	 * @param string $schema The schema id of the written object.
	 *
	 * @return bool True for the publication schema.
	 *
	 * @spec openspec/changes/publication-withdrawal-aftercare/specs/publications/spec.md#requirement-a-corrected-publication-keeps-its-earlier-public-versions-reachable-req-pwa-001
	 */
	private function isPublication(string $schema): bool {
		$configured = trim($this->config->getValueString('opencatalogi', 'publication_schema', ''));

		return $schema !== '' && $schema === $configured;

	}//end isPublication()
}//end class
