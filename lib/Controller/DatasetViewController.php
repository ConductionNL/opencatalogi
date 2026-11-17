<?php

/**
 * The public views of a published table and their data.
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
 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-anyone-reads-a-view-s-data-while-the-publication-is-public-req-dsv-002
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\Contract\CatalogiServiceInterface;
use OCA\OpenCatalogi\Contract\DatasetViewServiceInterface;
use OCA\OpenCatalogi\Contract\PublishedTableServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Every answer first checks, as the rows endpoint does, that the catalogue
 * exists, that the table belongs to the publication in the URL and is ready,
 * and that the caller may read that publication at this moment. Only then is a
 * view read. Anything else answers 404.
 *
 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-anyone-reads-a-view-s-data-while-the-publication-is-public-req-dsv-002
 */
class DatasetViewController extends Controller {
	use AnswersCrossOriginReadRequests;

	/**
	 * Constructor.
	 *
	 * @param string                         $appName   The app name.
	 * @param IRequest                       $request   The request.
	 * @param CatalogiServiceInterface       $catalogs  Resolves the catalogue slug.
	 * @param PublishedTableServiceInterface $tables    The tables and the publication read check.
	 * @param DatasetViewServiceInterface    $views     The views and their data.
	 * @param IL10N                          $l10n      Localisation.
	 * @param LoggerInterface                $logger    Logs a view whose data could not be read.
	 * @param IAppConfig|null                $appConfig The CORS allowlist.
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly CatalogiServiceInterface $catalogs,
		private readonly PublishedTableServiceInterface $tables,
		private readonly DatasetViewServiceInterface $views,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
		private readonly ?IAppConfig $appConfig = null,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * The views of a table, in page order.
	 *
	 * @param string $catalogSlug   The catalogue.
	 * @param string $publicationId The publication.
	 * @param string $tableId       The table.
	 *
	 * @return Response The views, or 404 when the table is not readable.
	 *
	 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-anyone-reads-a-view-s-data-while-the-publication-is-public-req-dsv-002
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function index(string $catalogSlug, string $publicationId, string $tableId): Response {
		$table = $this->readableTable(catalogSlug: $catalogSlug, publicationId: $publicationId, tableId: $tableId);
		if ($table === null) {
			return $this->notFound();
		}

		$listed = $this->views->withViews(
			tables: [['id' => $tableId]],
			where: ['catalogSlug' => $catalogSlug, 'publicationId' => $publicationId]
		);

		return $this->withCorsHeaders(response: new JSONResponse(['table' => $tableId, 'results' => ($listed[0]['views'] ?? [])]));
	}//end index()

	/**
	 * The data of one view: rows, grouped values or GeoJSON.
	 *
	 * @param string $catalogSlug   The catalogue.
	 * @param string $publicationId The publication.
	 * @param string $tableId       The table.
	 * @param string $viewId        The view.
	 *
	 * @return Response The data, 404 when the view or its table is not readable, 500 when it cannot be read.
	 *
	 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-anyone-reads-a-view-s-data-while-the-publication-is-public-req-dsv-002
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function data(string $catalogSlug, string $publicationId, string $tableId, string $viewId): Response {
		$table = $this->readableTable(catalogSlug: $catalogSlug, publicationId: $publicationId, tableId: $tableId);
		if ($table === null) {
			return $this->notFound();
		}

		$view = $this->views->findView(viewId: $viewId);
		if ($view === null || (string)($view['table'] ?? '') !== $tableId) {
			return $this->notFound();
		}

		try {
			$data = $this->views->data(view: $view, table: $table);
		} catch (\Throwable $e) {
			$this->logger->error('[DatasetViewController] The data of a view could not be read', ['view' => $viewId, 'error' => $e->getMessage()]);
			return $this->withCorsHeaders(
				response: new JSONResponse(
					data: ['error' => 'view-unavailable', 'message' => $this->l10n->t('The data of this view could not be read.')],
					statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
				)
			);
		}

		return $this->withCorsHeaders(response: new JSONResponse(['view' => $viewId, 'title' => (string)($view['title'] ?? '')] + $data));
	}//end data()

	/**
	 * Answer a CORS preflight for the view routes.
	 *
	 * @return Response The preflight answer.
	 *
	 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-anyone-reads-a-view-s-data-while-the-publication-is-public-req-dsv-002
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function preflightedCors(): Response {
		return $this->readOnlyPreflight();
	}//end preflightedCors()

	/**
	 * The table, when the catalogue exists, the table is the publication's and
	 * ready, and the caller may read the publication now.
	 *
	 * @param string $catalogSlug   The catalogue.
	 * @param string $publicationId The publication.
	 * @param string $tableId       The table.
	 *
	 * @return array<string, mixed>|null The table record, or null.
	 */
	private function readableTable(string $catalogSlug, string $publicationId, string $tableId): ?array {
		if ($this->catalogs->getCatalogBySlug($catalogSlug) === null) {
			return null;
		}

		$table = $this->tables->findTable(tableId: $tableId);
		if ($table === null
			|| (string)($table['publication'] ?? '') !== $publicationId
			|| ($table['status'] ?? null) !== 'ready'
			|| $this->tables->canReadPublication(publicationId: $publicationId) === false
		) {
			return null;
		}

		return $table;
	}//end readableTable()

	/**
	 * The 404 answer, the same for a missing view, a missing table and an unreadable publication.
	 *
	 * @return Response The answer.
	 */
	private function notFound(): Response {
		return $this->withCorsHeaders(
			response: new JSONResponse(
				data: ['error' => 'unknown-view', 'message' => $this->l10n->t('This view does not exist or is not accessible.')],
				statusCode: Http::STATUS_NOT_FOUND
			)
		);
	}//end notFound()
}//end class
