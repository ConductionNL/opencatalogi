<?php

/**
 * OpenCatalogi unpublished overview controller.
 *
 * Feeds the two Nextcloud dashboard widgets with what the signed-in user can
 * read and is not yet public.
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
 * @spec openspec/changes/publications-unpublished-overview/specs/dashboard/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\Service\UnpublishedOverviewService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Answers `GET /api/dashboard/unpublished`.
 *
 * @spec openspec/changes/publications-unpublished-overview/specs/dashboard/spec.md
 */
class UnpublishedOverviewController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The request.
	 * @param UnpublishedOverviewService $overview The overview lists.
	 * @param IUserSession $userSession The current user session.
	 * @param IL10N $l10n The localization service.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly UnpublishedOverviewService $overview,
		private readonly IUserSession $userSession,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The unpublished publications or documents the signed-in user can read.
	 *
	 * @param string $kind `publications` (default) or `documents`.
	 * @param int|null $limit The list length, 1 to 50, default 10.
	 *
	 * @return JSONResponse `{items, total, scope}`.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @no-admin-idor-exempt Takes no object id. Every row comes from an OpenRegister
	 *   search with RBAC on, so the caller only ever sees publications they may read;
	 *   `kind` picks a list and `limit` its length.
	 *
	 * @spec openspec/changes/publications-unpublished-overview/specs/dashboard/spec.md#requirement-unpublished-content-dashboard-widgets-dsh-011
	 */
	public function unpublished(string $kind = 'publications', ?int $limit = null): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(data: ['error' => $this->l10n->t('Not logged in')], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		if ($kind !== 'publications' && $kind !== 'documents') {
			return new JSONResponse(
				data: ['error' => $this->l10n->t('Unknown list: choose publications or documents.')],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		try {
			if ($kind === 'documents') {
				return new JSONResponse($this->overview->documents(limit: $limit));
			}

			return new JSONResponse($this->overview->publications(limit: $limit));
		} catch (\Throwable $e) {
			$this->logger->error('[UnpublishedOverviewController] The overview could not be read', ['exception' => $e]);
			return new JSONResponse(
				data: ['error' => $this->l10n->t('The unpublished overview could not be read.')],
				statusCode: Http::STATUS_SERVICE_UNAVAILABLE
			);
		}
	}//end unpublished()
}//end class
