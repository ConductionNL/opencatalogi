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
	 * @param string            $appName   The app name.
	 * @param IRequest          $request   The request.
	 * @param DatasetViewReader $reader    What a public view read may see, and the views.
	 * @param IL10N             $l10n      Localisation.
	 * @param IAppConfig|null   $appConfig The CORS allowlist.
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly DatasetViewReader $reader,
		private readonly IL10N $l10n,
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
		$table = $this->reader->readableTable(where: ['catalogSlug' => $catalogSlug, 'publicationId' => $publicationId, 'tableId' => $tableId]);
		if ($table === null) {
			return $this->notFound();
		}

		$listed = $this->reader->views->withViews(
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
		$table = $this->reader->readableTable(where: ['catalogSlug' => $catalogSlug, 'publicationId' => $publicationId, 'tableId' => $tableId]);
		$view  = null;
		if ($table !== null) {
			$view = $this->reader->viewOf(viewId: $viewId, tableId: $tableId);
		}

		if ($table === null || $view === null) {
			return $this->notFound();
		}

		$data = $this->reader->dataOf(view: $view, table: $table);
		if ($data === null) {
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
