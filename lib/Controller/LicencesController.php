<?php

/**
 * OpenCatalogi licences controller.
 *
 * Serves the EU licences authority list a catalogue's and a publication's
 * licence is chosen from (REQ-DCC-001).
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
 * @spec openspec/changes/dcat-catalogue-completion/tasks.md#task-1.4
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\Service\DcatVocabularyService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The licence list the licence pickers offer.
 *
 * @spec openspec/changes/dcat-catalogue-completion/specs/dcat-ap-harvest/spec.md#requirement-the-licence-comes-from-a-controlled-list-bind-or-omit-req-dcc-001
 */
class LicencesController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string                $appName     The app name.
	 * @param IRequest              $request     The request.
	 * @param IL10N                 $l10n        The localization service.
	 * @param IUserSession          $userSession The current user session.
	 * @param DcatVocabularyService $vocabulary  The bundled DCAT value lists.
	 *
	 * @spec exclude pure framework plumbing: constructor dependency injection.
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly IL10N $l10n,
		private readonly IUserSession $userSession,
		private readonly DcatVocabularyService $vocabulary,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * Return the licences a licence picker offers, with the list's source and version.
	 *
	 * Read-only and authenticated. Each row carries the authority code, the
	 * authority IRI a save stores, and the English and Dutch labels. The same
	 * list `LicenceCheck` and `DcatLicenceRule` hold a save to.
	 *
	 * @return JSONResponse The licences, their count, and where the list comes from.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/dcat-catalogue-completion/tasks.md#task-1.4
	 *
	 * @no-admin-idor-exempt Returns the EU licences authority list bundled with
	 *   the app, the same rows for every caller. The endpoint takes no
	 *   parameter, so there is no object id to scope.
	 */
	public function index(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(data: ['error' => $this->l10n->t('Not logged in')], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$rows = [];
		foreach ($this->vocabulary->licenceList() as $code => $member) {
			$rows[] = ['code' => (string)$code] + $member;
		}

		return new JSONResponse(
			data: [
				'results' => $rows,
				'total' => count($rows),
			] + $this->vocabulary->licenceListSource()
		);
	}//end index()
}//end class
