<?php

/**
 * OpenCatalogi published table controller.
 *
 * Answers the rows of a published table and its data dictionary to anyone who
 * may read the publication the table belongs to.
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
 * @spec openspec/changes/open-data-table-query-and-dictionary/specs/open-data-tables/spec.md#requirement-anyone-queries-the-rows-of-a-public-table-req-odt-002
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\Contract\CatalogiServiceInterface;
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

/**
 * The public rows and dictionary of a published table.
 *
 * Every answer first checks that the catalogue exists, that the table belongs
 * to the publication in the URL, and that the caller may read that
 * publication. Only then are the rows read.
 *
 * @spec openspec/changes/open-data-table-query-and-dictionary/specs/open-data-tables/spec.md#requirement-anyone-queries-the-rows-of-a-public-table-req-odt-002
 */
class PublishedTableController extends Controller {
	use AnswersCrossOriginReadRequests;

	/**
	 * Constructor.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The request.
	 * @param CatalogiServiceInterface $catalogs Resolves the catalogue slug.
	 * @param PublishedTableServiceInterface $tables The tables, their rows and dictionaries.
	 * @param IL10N $l10n Localisation.
	 * @param IAppConfig|null $appConfig The CORS allowlist.
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly CatalogiServiceInterface $catalogs,
		private readonly PublishedTableServiceInterface $tables,
		private readonly IL10N $l10n,
		private readonly ?IAppConfig $appConfig = null,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * One page of a table's rows, filtered, searched and sorted.
	 *
	 * @param string $catalogSlug The catalogue.
	 * @param string $publicationId The publication.
	 * @param string $tableId The table.
	 *
	 * @return Response The rows with total, page and pages; 400 for an unknown column; 404 when not readable.
	 *
	 * @spec openspec/changes/open-data-table-query-and-dictionary/specs/open-data-tables/spec.md#requirement-anyone-queries-the-rows-of-a-public-table-req-odt-002
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function rows(string $catalogSlug, string $publicationId, string $tableId): Response {
		$table = $this->readableTable(catalogSlug: $catalogSlug, publicationId: $publicationId, tableId: $tableId);
		if ($table === null) {
			return $this->notFound(tableId: $tableId);
		}

		$query = $this->request->getParams();
		$unknown = $this->tables->undeclaredFilters(query: $query, table: $table);
		if ($unknown !== []) {
			return $this->withCorsHeaders(
				response: new JSONResponse(
					data: [
						'error' => 'unknown-column',
						'message' => $this->l10n->t('This table has no column named %s.', [implode(', ', $unknown)]),
						'columns' => $unknown,
					],
					statusCode: Http::STATUS_BAD_REQUEST
				)
			);
		}

		return $this->withCorsHeaders(response: new JSONResponse($this->tables->rows(table: $table, query: $query)));
	}//end rows()

	/**
	 * The data dictionary of a table: each column's name, title, description and type.
	 *
	 * @param string $catalogSlug The catalogue.
	 * @param string $publicationId The publication.
	 * @param string $tableId The table.
	 *
	 * @return Response The columns, or 404 when not readable.
	 *
	 * @spec openspec/changes/open-data-table-query-and-dictionary/specs/open-data-tables/spec.md#requirement-readers-see-what-each-column-means-req-odt-003
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function dictionary(string $catalogSlug, string $publicationId, string $tableId): Response {
		$table = $this->readableTable(catalogSlug: $catalogSlug, publicationId: $publicationId, tableId: $tableId);
		if ($table === null) {
			return $this->notFound(tableId: $tableId);
		}

		return $this->withCorsHeaders(
			response: new JSONResponse(
				[
					'table' => $tableId,
					'title' => (string)($table['title'] ?? ''),
					'columns' => $this->tables->dictionary(table: $table),
				]
			)
		);
	}//end dictionary()

	/**
	 * Answer a CORS preflight for the table routes.
	 *
	 * @return Response The preflight answer.
	 *
	 * @spec openspec/changes/open-data-table-query-and-dictionary/specs/open-data-tables/spec.md#requirement-anyone-queries-the-rows-of-a-public-table-req-odt-002
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function preflightedCors(): Response {
		return $this->readOnlyPreflight();
	}//end preflightedCors()

	/**
	 * The table, when the catalogue exists, the table belongs to the publication,
	 * its rows are imported, and the caller may read the publication.
	 *
	 * @param string $catalogSlug The catalogue.
	 * @param string $publicationId The publication.
	 * @param string $tableId The table.
	 *
	 * @return array<string, mixed>|null The table record, or null.
	 *
	 * @spec openspec/changes/open-data-table-query-and-dictionary/specs/open-data-tables/spec.md#requirement-anyone-queries-the-rows-of-a-public-table-req-odt-002
	 */
	private function readableTable(string $catalogSlug, string $publicationId, string $tableId): ?array {
		if ($this->catalogs->getCatalogBySlug($catalogSlug) === null) {
			return null;
		}

		$table = $this->tables->findTable(tableId: $tableId);
		if ($table === null
			|| (string)($table['publication'] ?? '') !== $publicationId
			|| ($table['status'] ?? null) !== 'ready'
		) {
			return null;
		}

		if ($this->tables->canReadPublication(publicationId: $publicationId) === false) {
			return null;
		}

		return $table;
	}//end readableTable()

	/**
	 * The 404 answer, the same for a missing table and an unreadable publication.
	 *
	 * @param string $tableId The table id from the URL.
	 *
	 * @return Response The answer.
	 *
	 * @spec openspec/changes/open-data-table-query-and-dictionary/specs/open-data-tables/spec.md#requirement-anyone-queries-the-rows-of-a-public-table-req-odt-002
	 */
	private function notFound(string $tableId): Response {
		return $this->withCorsHeaders(
			response: new JSONResponse(
				data: [
					'error' => 'unknown-table',
					'message' => $this->l10n->t('The table "%s" does not exist or is not accessible.', [$tableId]),
				],
				statusCode: Http::STATUS_NOT_FOUND
			)
		);
	}//end notFound()
}//end class
