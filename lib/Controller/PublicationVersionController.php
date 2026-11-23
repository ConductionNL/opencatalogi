<?php

/**
 * OpenCatalogi Publication Version Controller.
 *
 * Serves the earlier public versions of a corrected publication at their own
 * URLs, so a reader who cited a version still finds it. Each route answers 404
 * unless the publication itself is public now.
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
 * @spec openspec/changes/publication-withdrawal-aftercare/tasks.md#task-1.3
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Response;
use OCP\IAppConfig;
use OCP\IRequest;

/**
 * The public versions of a publication.
 *
 * The version and document numbers are read from the route's parameters on
 * the request, beside the catalogue slug and id the methods take.
 *
 * @spec openspec/changes/publication-withdrawal-aftercare/specs/publications/spec.md#requirement-a-corrected-publication-keeps-its-earlier-public-versions-reachable-req-pwa-001
 */
class PublicationVersionController extends Controller {
	use AnswersCrossOriginReadRequests;

	/**
	 * Constructor.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The request.
	 * @param PublicationVersions $answers Builds the answers.
	 * @param IAppConfig|null $appConfig The CORS origin setting.
	 *
	 * @spec exclude pure framework plumbing: constructor dependency injection.
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly PublicationVersions $answers,
		private readonly ?IAppConfig $appConfig = null,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * `GET /api/{catalogSlug}/{id}/versions`: the earlier public versions of a publication.
	 *
	 * @param string $catalogSlug The catalogue slug.
	 * @param string $id The publication id.
	 *
	 * @return Response The list, or 404.
	 *
	 * @spec openspec/changes/publication-withdrawal-aftercare/specs/publications/spec.md#requirement-a-corrected-publication-keeps-its-earlier-public-versions-reachable-req-pwa-001
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function index(string $catalogSlug, string $id): Response {
		return $this->withCorsHeaders(response: $this->answers->respond(asked: ['catalogSlug' => $catalogSlug, 'id' => $id]));

	}//end index()

	/**
	 * `GET /api/{catalogSlug}/{id}/versions/{version}`: one earlier public version.
	 *
	 * @param string $catalogSlug The catalogue slug.
	 * @param string $id The publication id.
	 *
	 * @return Response The version, or 404.
	 *
	 * @spec openspec/changes/publication-withdrawal-aftercare/specs/publications/spec.md#requirement-a-corrected-publication-keeps-its-earlier-public-versions-reachable-req-pwa-001
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function show(string $catalogSlug, string $id): Response {
		return $this->withCorsHeaders(
			response: $this->answers->respond(
				asked: ['catalogSlug' => $catalogSlug, 'id' => $id, 'version' => (int)$this->request->getParam('version', '0')]
			)
		);

	}//end show()

	/**
	 * `GET /api/{catalogSlug}/{id}/versions/{version}/documents/{fileId}`: one document of an earlier version.
	 *
	 * @param string $catalogSlug The catalogue slug.
	 * @param string $id The publication id.
	 *
	 * @return Response The file, or 404.
	 *
	 * @spec openspec/changes/publication-withdrawal-aftercare/specs/publications/spec.md#requirement-a-corrected-publication-keeps-its-earlier-public-versions-reachable-req-pwa-001
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function document(string $catalogSlug, string $id): Response {
		return $this->withCorsHeaders(
			response: $this->answers->respond(
				asked: [
					'catalogSlug' => $catalogSlug,
					'id' => $id,
					'version' => (int)$this->request->getParam('version', '0'),
					'fileId' => (int)$this->request->getParam('fileId', '0'),
				]
			)
		);

	}//end document()

	/**
	 * Answer the CORS preflight of the version routes.
	 *
	 * @return Response The preflight answer.
	 *
	 * @spec openspec/changes/publication-withdrawal-aftercare/specs/publications/spec.md#requirement-a-corrected-publication-keeps-its-earlier-public-versions-reachable-req-pwa-001
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 240, period: 60)]
	public function preflightedCors(): Response {
		return $this->readOnlyPreflight();

	}//end preflightedCors()
}//end class
