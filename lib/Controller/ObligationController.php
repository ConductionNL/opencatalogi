<?php

/**
 * OpenCatalogi Obligation Controller.
 *
 * The overview of what must still be published, read from every registered
 * source, for administrators.
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
 * @spec openspec/changes/woo-obligation-overview/specs/woo-compliance/spec.md#requirement-the-overview-reads-every-registered-source-and-shows-the-ones-it-could-not-read-req-woo-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\Contract\ObligationReadServiceInterface;
use OCA\OpenCatalogi\Settings\OpenCatalogiAdmin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Answers the obligation overview.
 *
 * @spec openspec/changes/woo-obligation-overview/specs/woo-compliance/spec.md#requirement-the-overview-reads-every-registered-source-and-shows-the-ones-it-could-not-read-req-woo-001
 */
class ObligationController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                $appName     The app name.
	 * @param IRequest              $request     The request.
	 * @param IL10N                 $l10n        Localisation.
	 * @param ObligationReadServiceInterface $readService Reads the sources.
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly IL10N $l10n,
		private readonly ObligationReadServiceInterface $readService,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * What must still be published, per source, with the sources that could not be read.
	 *
	 * @return JSONResponse The overview, or 503 when the sources cannot be read.
	 *
	 * @spec openspec/changes/woo-obligation-overview/specs/woo-compliance/spec.md#requirement-the-overview-reads-every-registered-source-and-shows-the-ones-it-could-not-read-req-woo-001
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function index(): JSONResponse {
		try {
			return new JSONResponse($this->readService->read());
		} catch (\RuntimeException $e) {
			return new JSONResponse(
				data: [
					'error'   => 'sources-unreadable',
					'message' => $this->l10n->t('The obligation sources could not be read, so this is not an overview of nothing.'),
				],
				statusCode: Http::STATUS_SERVICE_UNAVAILABLE
			);
		}

	}//end index()
}//end class
