<?php

/**
 * OpenCatalogi published table re-import listener.
 *
 * Queues a new import of every published table whose CSV was replaced: a new
 * version written to the file, or an older version restored through
 * OpenRegister. The import runs in PublishedTableImportJob, outside the
 * request that wrote the file.
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
 * @spec openspec/changes/data-file-import-failure-alert/specs/open-data-tables/spec.md#requirement-a-replaced-data-file-is-imported-again-req-dfa-003
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Listener;

use OCA\OpenCatalogi\Service\Table\PublishedTableService;
use OCA\OpenRegister\Event\FileVersionRestoredEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Queues the re-import of the tables behind a replaced CSV.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/data-file-import-failure-alert/specs/open-data-tables/spec.md#requirement-a-replaced-data-file-is-imported-again-req-dfa-003
 */
class PublishedTableReimportListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param PublishedTableService $tables The tables and their import queue.
	 * @param IUserSession $userSession Who replaced the file.
	 * @param LoggerInterface $logger Records a re-import that could not be queued.
	 */
	public function __construct(
		private readonly PublishedTableService $tables,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Queue a re-import of each table whose file was replaced.
	 *
	 * Only a CSV is looked up, because every file write in the instance passes
	 * here. A failure is logged and never thrown: the file was already saved.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/data-file-import-failure-alert/specs/open-data-tables/spec.md#requirement-a-replaced-data-file-is-imported-again-req-dfa-003
	 */
	public function handle(Event $event): void {
		$fileId = $this->replacedCsv(event: $event);
		if ($fileId === null) {
			return;
		}

		try {
			$importedBy = $this->userSession->getUser()?->getUID();
			foreach ($this->tables->tablesForFile(fileId: $fileId) as $table) {
				$this->tables->queueImport(tableId: (string)($table['id'] ?? ''), importedBy: $importedBy);
			}
		} catch (Throwable $e) {
			$this->logger->warning(
				'[PublishedTableReimportListener] the re-import could not be queued',
				['fileId' => $fileId, 'exception' => $e->getMessage()]
			);
		}

	}//end handle()

	/**
	 * The id of the CSV the event replaced, or null for any other event or file.
	 *
	 * @param Event $event The event.
	 *
	 * @return string|null The file id.
	 */
	private function replacedCsv(Event $event): ?string {
		if ($event instanceof FileVersionRestoredEvent === true) {
			return (string)$event->getFileId();
		}

		if ($event instanceof NodeWrittenEvent === false) {
			return null;
		}

		$node = $event->getNode();
		if ($node instanceof File === false || str_ends_with(strtolower($node->getName()), '.csv') === false) {
			return null;
		}

		return (string)$node->getId();

	}//end replacedCsv()
}//end class
