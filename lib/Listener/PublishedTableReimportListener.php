<?php

/**
 * Re-imports a published table when the file behind it is replaced.
 *
 * @category Listener
 * @package  OCA\OpenCatalogi\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/data-file-import-failure-alert/specs/open-data-tables/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Listener;

use OCA\OpenCatalogi\Service\Table\TableImportFollowUp;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Queues a re-import for every table published from a file whose content
 * changed (REQ-DFA-003).
 *
 * An attachment's content is replaced through Nextcloud's file layer, which
 * dispatches NodeWrittenEvent; OpenRegister dispatches FileVersionRestoredEvent
 * when an older version is restored on an object's file. Only CSV files can
 * back a table, so every other write is ignored before any lookup.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/data-file-import-failure-alert/tasks.md#1.4
 */
class PublishedTableReimportListener implements IEventListener {

	/**
	 * OpenRegister's event for a restored version of an object's file.
	 *
	 * @var string
	 */
	public const VERSION_RESTORED_EVENT = 'OCA\OpenRegister\Event\FileVersionRestoredEvent';

	/**
	 * Constructor.
	 *
	 * @param TableImportFollowUp $followUp Queues the re-imports.
	 * @param IUserSession        $session  Who replaced the file.
	 * @param LoggerInterface     $logger   Records a re-import that could not be queued.
	 */
	public function __construct(
		private readonly TableImportFollowUp $followUp,
		private readonly IUserSession $session,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Queue the re-imports for the file the event names.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/data-file-import-failure-alert/tasks.md#1.4
	 */
	public function handle(Event $event): void {
		$fileId = self::replacedCsv(event: $event);
		if ($fileId === null) {
			return;
		}

		try {
			$this->followUp->queueForFile(fileId: $fileId, importedBy: (string)$this->session->getUser()?->getUID());
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[PublishedTableReimportListener] the re-import could not be queued',
				context: ['fileId' => $fileId, 'exception' => $e->getMessage()]
			);
		}
	}//end handle()

	/**
	 * The id of the CSV file whose content the event replaced, or null for any other event.
	 *
	 * @param Event $event The event.
	 *
	 * @return integer|null The file id.
	 *
	 * @spec openspec/changes/data-file-import-failure-alert/tasks.md#1.4
	 */
	private static function replacedCsv(Event $event): ?int {
		if ($event instanceof NodeWrittenEvent) {
			$node = $event->getNode();
			if (($node instanceof File) === false || strtolower(pathinfo($node->getName(), PATHINFO_EXTENSION)) !== 'csv') {
				return null;
			}

			return (int)$node->getId();
		}

		if (is_a($event, self::VERSION_RESTORED_EVENT) === true && method_exists($event, 'getFileId') === true) {
			return (int)$event->getFileId();
		}

		return null;
	}//end replacedCsv()
}//end class
