<?php

/**
 * Whether the publications report can offer Generate annual report.
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
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/woo-annual-report/specs/woo-compliance/spec.md#requirement-an-officer-generates-the-annual-report-from-the-publications-report-req-war-002
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\Service\Woo\WooAnnualReport;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * GET /api/woo/annual-report: the report's availability and template.
 */
class WooAnnualReportController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string          $appName The app name.
	 * @param IRequest        $request The request.
	 * @param WooAnnualReport $report  Answers whether the report can be generated.
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly WooAnnualReport $report,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The availability of the Woo annual report. It names no data, only whether
	 * OpenRegister can generate the report and from which template; the counting
	 * itself happens in OpenRegister with the caller's rights.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/woo-annual-report/specs/woo-compliance/spec.md#requirement-an-officer-generates-the-annual-report-from-the-publications-report-req-war-002
	 */
	#[NoAdminRequired]
	public function availability(): JSONResponse {
		return new JSONResponse($this->report->availability());
	}//end availability()
}//end class
