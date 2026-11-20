<?php

/**
 * The Woo pipeline incident group and the open incidents, for the Woo settings.
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
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Controller;

use InvalidArgumentException;
use OCA\OpenCatalogi\Service\Woo\PipelineIncidentSettings;
use OCA\OpenCatalogi\Settings\OpenCatalogiAdmin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Admin only, like the rest of the Woo settings (REQ-WNO-004).
 *
 * @spec openspec/changes/woo-national-output-assurance/specs/woo-compliance/spec.md#requirement-a-pipeline-failure-reaches-a-named-group-req-wno-004
 */
class WooIncidentsController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                   $appName  The app name.
	 * @param IRequest                 $request  The request.
	 * @param PipelineIncidentSettings $settings The incident group and the open incidents.
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly PipelineIncidentSettings $settings,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The incident group and the open incidents.
	 *
	 * @return JSONResponse `{group, incidents}`, or `{group, incidents: [], error}` when the incidents cannot be read.
	 *
	 * @spec openspec/changes/woo-national-output-assurance/specs/woo-compliance/spec.md#requirement-a-pipeline-failure-reaches-a-named-group-req-wno-004
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function index(): JSONResponse {
		$data = ['group' => $this->settings->group(), 'incidents' => []];
		try {
			$data['incidents'] = $this->settings->openIncidents();
		} catch (\Throwable $e) {
			$data['error'] = $e->getMessage();
		}

		return new JSONResponse($data);
	}//end index()

	/**
	 * Set the incident group.
	 *
	 * @param string $group The group id, or '' for nobody.
	 *
	 * @return JSONResponse `{group}`; 400 for a group that does not exist, 409 when the role cannot be assigned.
	 *
	 * @spec openspec/changes/woo-national-output-assurance/specs/woo-compliance/spec.md#requirement-a-pipeline-failure-reaches-a-named-group-req-wno-004
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function setGroup(string $group = ''): JSONResponse {
		try {
			return new JSONResponse(['group' => $this->settings->setGroup(group: $group)]);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
		}
	}//end setGroup()
}//end class
