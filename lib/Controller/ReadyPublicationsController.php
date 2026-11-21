<?php

/**
 * Ready publications controller
 *
 * `GET /api/publications/ready`: the publications in state `approved` that the
 * signed-in caller may read, filterable by `since`, `caseReference` and
 * `sourceIdentifier` (publication-lifecycle-on-or REQ-PLC-005,
 * publication-relations-place-and-source-ids REQ-PRS-003). Authenticated only.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Controller
 * @package  OCA\OpenCatalogi\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/publication-lifecycle-on-or/specs/publications/spec.md#requirement-a-caller-lists-exactly-the-publications-ready-to-publish-req-plc-005
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Controller;

use DateTimeImmutable;
use OCA\OpenCatalogi\Service\Publication\ReadyPublicationsService;
use OCA\OpenCatalogi\Service\Publication\SourceIdentifierFilter;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Lists the publications ready to publish.
 *
 * @spec openspec/changes/publication-lifecycle-on-or/specs/publications/spec.md#requirement-a-caller-lists-exactly-the-publications-ready-to-publish-req-plc-005
 */
class ReadyPublicationsController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                   $appName     The app name.
	 * @param IRequest                 $request     The request.
	 * @param ReadyPublicationsService $ready       The approved publications the caller may read.
	 * @param IUserSession             $userSession The signed-in caller.
	 * @param IL10N                    $l10n        Translations.
	 * @param LoggerInterface          $logger      Records a failed read.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ReadyPublicationsService $ready,
		private readonly IUserSession $userSession,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * `{results: [{id, title, catalog, caseReference, approvedAt, updated}], total, since}`; 401 without a session.
	 *
	 * `sourceIdentifier=<system>:<identifier>` matches an entry of
	 * `sourceIdentifiers`, public or not; a value that is not of that form is a 400.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/publication-lifecycle-on-or/specs/publications/spec.md#requirement-a-caller-lists-exactly-the-publications-ready-to-publish-req-plc-005
	 * @spec openspec/changes/publication-relations-place-and-source-ids/tasks.md#task-2.3
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) SourceIdentifierFilter::parse() is a pure function over the parameter.
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(
				data: ['error' => $this->l10n->t('Sign in to list the publications ready to publish.')],
				statusCode: Http::STATUS_UNAUTHORIZED
			);
		}

		$since = null;
		$sinceParam = trim((string)$this->request->getParam('since', ''));
		if ($sinceParam !== '') {
			$since = self::moment(value: $sinceParam);
			if ($since === null) {
				return new JSONResponse(data: ['error' => $this->l10n->t('Give since as an ISO 8601 date and time.')], statusCode: Http::STATUS_BAD_REQUEST);
			}
		}

		$caseReference = trim((string)$this->request->getParam('caseReference', ''));

		$sourceParam = trim((string)$this->request->getParam(SourceIdentifierFilter::PARAM, ''));
		$sourceIdentifier = SourceIdentifierFilter::parse(value: $sourceParam);
		if ($sourceParam !== '' && $sourceIdentifier === null) {
			return new JSONResponse(data: ['error' => $this->l10n->t('Give sourceIdentifier as <system>:<identifier>.')], statusCode: Http::STATUS_BAD_REQUEST);
		}

		try {
			return new JSONResponse(data: $this->ready->ready(since: $since, caseReference: $caseReference, sourceIdentifier: $sourceIdentifier));
		} catch (\Throwable $e) {
			$this->logger->error('[ReadyPublicationsController] Listing the ready publications failed', ['exception' => $e]);
			return new JSONResponse(
				data: ['error' => $this->l10n->t('The publications ready to publish could not be listed.')],
				statusCode: Http::STATUS_SERVICE_UNAVAILABLE
			);
		}
	}//end index()

	/**
	 * The `since` moment, or null when it is not an ISO 8601 date-time.
	 *
	 * @param string $value The parameter.
	 *
	 * @return DateTimeImmutable|null
	 */
	private static function moment(string $value): ?DateTimeImmutable {
		try {
			return new DateTimeImmutable($value);
		} catch (\Exception $e) {
			return null;
		}
	}//end moment()
}//end class
