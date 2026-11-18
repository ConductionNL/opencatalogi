<?php

/**
 * The editor side of dataset views: list, save and remove the views of a published table.
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
 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-an-editor-adds-a-table-chart-or-map-view-to-a-published-table-req-dsv-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Controller;

use InvalidArgumentException;
use OCA\OpenCatalogi\Contract\DatasetViewServiceInterface;
use OCA\OpenCatalogi\Contract\PublishedTableServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Every action needs the update right on the publication (PublicationWriteGuard),
 * and the table must be one of that publication's ready tables.
 *
 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-an-editor-adds-a-table-chart-or-map-view-to-a-published-table-req-dsv-001
 */
class DatasetViewEditController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                         $appName The app name.
	 * @param IRequest                       $request The request.
	 * @param PublishedTableServiceInterface $tables  The published tables.
	 * @param DatasetViewServiceInterface    $views   The views.
	 * @param PublicationWriteGuard          $guard   Loads the publication for a write by the signed-in user, or refuses it.
	 * @param IL10N                          $l10n    Translations.
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly PublishedTableServiceInterface $tables,
		private readonly DatasetViewServiceInterface $views,
		private readonly PublicationWriteGuard $guard,
		private readonly IL10N $l10n,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * The views of one of the publication's tables.
	 *
	 * @param string $id      The publication.
	 * @param string $tableId The table.
	 *
	 * @return JSONResponse The views, or the refusal.
	 *
	 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-an-editor-adds-a-table-chart-or-map-view-to-a-published-table-req-dsv-001
	 */
	#[NoAdminRequired]
	public function index(string $id, string $tableId): JSONResponse {
		[$table, $refusal] = $this->writableTable(id: $id, tableId: $tableId);
		if ($refusal !== null) {
			return $refusal;
		}

		return new JSONResponse(['table' => $tableId, 'results' => $this->views->viewsOf(tableId: (string)$table['id'])]);
	}//end index()

	/**
	 * Add or change a view of one of the publication's tables; `viewId` in the body changes that view.
	 *
	 * @param string $id      The publication.
	 * @param string $tableId The table.
	 *
	 * @return JSONResponse The stored view (201 when added), 400 naming the column it cannot use, or the refusal.
	 *
	 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-an-editor-adds-a-table-chart-or-map-view-to-a-published-table-req-dsv-001
	 */
	#[NoAdminRequired]
	public function save(string $id, string $tableId): JSONResponse {
		[$table, $refusal] = $this->writableTable(id: $id, tableId: $tableId);
		if ($refusal !== null) {
			return $refusal;
		}

		$view = $this->request->getParams();
		unset($view['id'], $view['tableId'], $view['_route']);
		$viewId = (string)($view['viewId'] ?? '');
		unset($view['viewId']);
		if ($viewId !== '') {
			$stored = $this->views->findView(viewId: $viewId);
			if ($stored === null || (string)($stored['table'] ?? '') !== $tableId) {
				return $this->unknownView();
			}

			$view['id'] = $viewId;
		}

		try {
			$saved = $this->views->save(view: $view, table: $table);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(data: ['error' => 'bad-request', 'message' => $e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		}

		$status = Http::STATUS_OK;
		if ($viewId === '') {
			$status = Http::STATUS_CREATED;
		}

		return new JSONResponse(data: $saved, statusCode: $status);
	}//end save()

	/**
	 * Remove a view of one of the publication's tables.
	 *
	 * @param string $id      The publication.
	 * @param string $tableId The table.
	 * @param string $viewId  The view.
	 *
	 * @return JSONResponse 204, 404 for a view of another table, or the refusal.
	 *
	 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-an-editor-adds-a-table-chart-or-map-view-to-a-published-table-req-dsv-001
	 */
	#[NoAdminRequired]
	public function remove(string $id, string $tableId, string $viewId): JSONResponse {
		[, $refusal] = $this->writableTable(id: $id, tableId: $tableId);
		if ($refusal !== null) {
			return $refusal;
		}

		$stored = $this->views->findView(viewId: $viewId);
		if ($stored === null || (string)($stored['table'] ?? '') !== $tableId) {
			return $this->unknownView();
		}

		$this->views->remove(viewId: $viewId);

		return new JSONResponse(data: [], statusCode: Http::STATUS_NO_CONTENT);
	}//end remove()

	/**
	 * The table, when the signed-in user may update the publication and the table is its ready one.
	 *
	 * @param string $id      The publication.
	 * @param string $tableId The table.
	 *
	 * @return array{0: array<string, mixed>|null, 1: JSONResponse|null} The table, or the refusal.
	 */
	private function writableTable(string $id, string $tableId): array {
		[, $refusal] = $this->guard->guard(id: $id);
		if ($refusal !== null) {
			return [null, $refusal];
		}

		$table = $this->tables->findTable(tableId: $tableId);
		if ($table === null || (string)($table['publication'] ?? '') !== $id || ($table['status'] ?? null) !== 'ready') {
			return [
				null,
				new JSONResponse(
					data: ['error' => 'unknown-table', 'message' => $this->l10n->t('The table "%s" does not exist or is not accessible.', [$tableId])],
					statusCode: Http::STATUS_NOT_FOUND
				),
			];
		}

		$table['id'] = $tableId;

		return [$table, null];
	}//end writableTable()

	/**
	 * The 404 for a view that does not exist or belongs to another table.
	 *
	 * @return JSONResponse The answer.
	 */
	private function unknownView(): JSONResponse {
		return new JSONResponse(
			data: ['error' => 'unknown-view', 'message' => $this->l10n->t('This view does not exist or is not accessible.')],
			statusCode: Http::STATUS_NOT_FOUND
		);
	}//end unknownView()
}//end class
