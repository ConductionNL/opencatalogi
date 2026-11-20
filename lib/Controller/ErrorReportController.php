<?php

/**
 * The public route a visitor reports an error in a publication through.
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
 * @spec openspec/changes/publication-detail-for-the-portal/specs/publications/spec.md#requirement-a-catalogue-may-take-error-reports-from-anyone-throttled-and-moderated-req-pdp-005
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\Contract\ErrorReportIntakeInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Serves `POST /api/publications/{id}/error-reports` and its preflight.
 *
 * @spec openspec/changes/publication-detail-for-the-portal/specs/publications/spec.md#requirement-a-catalogue-may-take-error-reports-from-anyone-throttled-and-moderated-req-pdp-005
 */
class ErrorReportController extends Controller {
	use AnswersCrossOriginRequests;

	/**
	 * Constructor.
	 *
	 * @param string                     $appName   The app name.
	 * @param IRequest                   $request   The request.
	 * @param ErrorReportIntakeInterface $intake    Stores the report.
	 * @param IL10N                      $l10n      Translations.
	 * @param IAppConfig                 $config    Holds the allowed CORS origins.
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly ErrorReportIntakeInterface $intake,
		private readonly IL10N $l10n,
		private readonly IAppConfig $config,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * Take an error report on a publication.
	 *
	 * @param string $id The publication.
	 *
	 * @return JSONResponse 201 with `{reference}`, 400 for a refused message, 404 unless the catalogue takes reports.
	 *
	 * @no-admin-idor-exempt Public write by design (REQ-PDP-005): ErrorReportIntake::submit() reads the
	 *   publication inside OpenRegister's anonymous scope and only for a catalogue that opted in and names
	 *   a moderator; it writes one new report and reads or changes no existing object of the caller's choosing.
	 *
	 * @spec openspec/changes/publication-detail-for-the-portal/tasks.md#5.1
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 5, period: 3600)]
	#[UserRateLimit(limit: 5, period: 3600)]
	public function create(string $id): JSONResponse {
		$outcome = $this->intake->submit(
			publicationId: $id,
			message: $this->request->getParam('message'),
			contact: $this->request->getParam('contact')
		);

		$body = ['reference' => ($outcome['reference'] ?? '')];
		if ($outcome['status'] !== 201) {
			$code = ($outcome['error'] ?? 'refused');
			$body = ['error' => $code, 'message' => $this->messageFor(code: $code)];
		}

		$response = new JSONResponse(data: $body, statusCode: $outcome['status']);
		$response->addHeader('Access-Control-Allow-Origin', $this->resolveAllowedOrigin());
		$response->addHeader('Access-Control-Allow-Credentials', 'false');

		return $response;

	}//end create()

	/**
	 * The CORS preflight of the route.
	 *
	 * @return Response The preflight answer.
	 *
	 * @spec openspec/changes/publication-detail-for-the-portal/tasks.md#5.1
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 240, period: 60)]
	public function preflightedCors(): Response {
		return $this->readWritePreflight();

	}//end preflightedCors()

	/**
	 * The message a visitor reads for a refusal.
	 *
	 * @param string $code The error code.
	 *
	 * @return string The message.
	 */
	private function messageFor(string $code): string {
		return match ($code) {
			'message-required' => $this->l10n->t('Describe the error you found.'),
			'message-too-long' => $this->l10n->t('Keep your report under %s characters.', ['2,000']),
			'invalid-contact' => $this->l10n->t('Enter a valid e-mail address, or leave it empty.'),
			'not-configured' => $this->l10n->t('Error reports cannot be taken right now.'),
			default => $this->l10n->t('This publication does not take error reports.'),
		};

	}//end messageFor()
}//end class
