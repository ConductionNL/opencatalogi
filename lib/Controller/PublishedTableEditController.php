<?php

/**
 * OpenCatalogi published table edit controller.
 *
 * Lets the editors of a publication publish a CSV attachment as a table, see
 * its tables whatever their status, and edit their data dictionaries.
 *
 * @category Controller
 * @package  OCA\OpenCatalogi\Controller
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
 * @spec openspec/changes/open-data-table-query-and-dictionary/specs/open-data-tables/spec.md#requirement-an-editor-publishes-a-csv-attachment-as-a-table-req-odt-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Controller;

use InvalidArgumentException;
use OCA\OpenCatalogi\Contract\PublishedTableServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * The editor side of published tables: every action needs the update right on the publication.
 *
 * @spec openspec/changes/open-data-table-query-and-dictionary/specs/open-data-tables/spec.md#requirement-an-editor-publishes-a-csv-attachment-as-a-table-req-odt-001
 */
class PublishedTableEditController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                         $appName The app name.
	 * @param IRequest                       $request The request.
	 * @param PublishedTableServiceInterface $tables  The published tables.
	 * @param PublicationWriteGuard          $guard   Loads the publication for a write by the signed-in user, or refuses it.
	 * @param IL10N                          $l10n    Translations.
	 * @param LoggerInterface                $logger  PSR-3 logger.
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly PublishedTableServiceInterface $tables,
		private readonly PublicationWriteGuard $guard,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * The tables of a publication, whatever their status, with the dictionary of each ready one.
	 *
	 * @param string $id The publication.
	 *
	 * @return JSONResponse The tables, or the refusal.
	 *
	 * @spec openspec/changes/open-data-table-query-and-dictionary/specs/open-data-tables/spec.md#requirement-readers-see-what-each-column-means-req-odt-003
	 */
	#[NoAdminRequired]
	public function index(string $id): JSONResponse {
		[$publication, $refusal] = $this->guard->guard(id: $id);
		if ($refusal !== null) {
			return $refusal;
		}

		return new JSONResponse(['results' => $this->tables->tablesFor(publicationId: $id)]);
	}//end index()

	/**
	 * Publish one CSV attachment of the publication as a table.
	 *
	 * @param string $id The publication.
	 *
	 * @return JSONResponse The table record (201), or the refusal.
	 *
	 * @spec openspec/changes/open-data-table-query-and-dictionary/specs/open-data-tables/spec.md#requirement-an-editor-publishes-a-csv-attachment-as-a-table-req-odt-001
	 */
	#[NoAdminRequired]
	public function create(string $id): JSONResponse {
		[$publication, $refusal] = $this->guard->guard(id: $id);
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->publish(id: $id, publication: $publication);
	}//end create()

	/**
	 * Write the column titles and descriptions of one of the publication's tables.
	 *
	 * @param string $id      The publication.
	 * @param string $tableId The table.
	 *
	 * @return JSONResponse The dictionary after the change, or the refusal.
	 *
	 * @spec openspec/changes/open-data-table-query-and-dictionary/specs/open-data-tables/spec.md#requirement-readers-see-what-each-column-means-req-odt-003
	 */
	#[NoAdminRequired]
	public function updateDictionary(string $id, string $tableId): JSONResponse {
		[$publication, $refusal] = $this->guard->guard(id: $id);
		if ($refusal !== null) {
			return $refusal;
		}

		$table = $this->readyTable(id: $id, tableId: $tableId);
		if ($table === null) {
			return $this->unknownTable(tableId: $tableId);
		}

		$columns = $this->request->getParam('columns', []);
		if (is_array($columns) === false) {
			return $this->badRequest(message: $this->l10n->t('Send the columns as a list.'));
		}

		try {
			return new JSONResponse(['table' => $tableId, 'columns' => $this->tables->updateDictionary(table: $table, columns: array_values($columns))]);
		} catch (InvalidArgumentException $e) {
			return $this->badRequest(message: $e->getMessage());
		}
	}//end updateDictionary()

	/**
	 * Publish the requested attachment as a table and answer with the table record.
	 *
	 * @param string $id          The publication.
	 * @param object $publication The publication, loaded for the write.
	 *
	 * @return JSONResponse The table record (201), or why it was not published.
	 */
	private function publish(string $id, object $publication): JSONResponse {
		$fileId = (string)$this->request->getParam('fileId', '');
		if (preg_match('/^\d+$/', $fileId) !== 1) {
			return $this->badRequest(message: $this->l10n->t('Choose the CSV attachment to publish as a table.'));
		}

		try {
			$table = $this->tables->publishAttachment(publication: $publication, fileId: $fileId, user: $this->guard->user());
		} catch (InvalidArgumentException $e) {
			return $this->badRequest(message: $e->getMessage());
		} catch (\Throwable $e) {
			return $this->notPublished(id: $id, exception: $e);
		}

		return new JSONResponse(data: $table, statusCode: Http::STATUS_CREATED);
	}//end publish()

	/**
	 * Log why a table could not be published and answer 500.
	 *
	 * @param string     $id        The publication.
	 * @param \Throwable $exception The failure.
	 *
	 * @return JSONResponse The answer.
	 */
	private function notPublished(string $id, \Throwable $exception): JSONResponse {
		$this->logger->error(
			'[PublishedTableEditController] The table could not be published',
			['publication' => $id, 'error' => $exception->getMessage()]
		);

		return new JSONResponse(
			data: ['error' => 'table-not-published', 'message' => $this->l10n->t('The table could not be published.')],
			statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
		);
	}//end notPublished()

	/**
	 * One of the publication's ready tables, or null.
	 *
	 * @param string $id      The publication.
	 * @param string $tableId The table.
	 *
	 * @return array<string, mixed>|null The table.
	 */
	private function readyTable(string $id, string $tableId): ?array {
		$table = $this->tables->findTable(tableId: $tableId);
		if ($table === null || (string)($table['publication'] ?? '') !== $id || ($table['status'] ?? null) !== 'ready') {
			return null;
		}

		return $table;
	}//end readyTable()

	/**
	 * The 404 for a table that does not exist, is not this publication's, or is not ready.
	 *
	 * @param string $tableId The table.
	 *
	 * @return JSONResponse The answer.
	 */
	private function unknownTable(string $tableId): JSONResponse {
		return new JSONResponse(
			data: ['error' => 'unknown-table', 'message' => $this->l10n->t('The table "%s" does not exist or is not accessible.', [$tableId])],
			statusCode: Http::STATUS_NOT_FOUND
		);
	}//end unknownTable()

	/**
	 * A 400 answer.
	 *
	 * @param string $message What is wrong.
	 *
	 * @return JSONResponse The answer.
	 */
	private function badRequest(string $message): JSONResponse {
		return new JSONResponse(data: ['error' => 'bad-request', 'message' => $message], statusCode: Http::STATUS_BAD_REQUEST);
	}//end badRequest()
}//end class
