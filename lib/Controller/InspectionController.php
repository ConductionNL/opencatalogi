<?php

/**
 * OpenCatalogi Inspection Controller.
 *
 * Terinzagelegging. An administrator opens an inspection on a record, choosing
 * the documents that form its set; an anonymous reader follows the link.
 *
 * The link is checked at the read. It stops working because the window closed,
 * not because a job ran, so a scheduler that has not fired yet cannot leave
 * documents readable past their statutory period. A closed window is answered
 * with its end date, so a reader who followed a link from a letter learns the
 * period is over rather than that something is broken.
 *
 * @category Controller
 * @package  OCA\OpenCatalogi\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-documents-go-on-public-inspection-for-exactly-the-statutory-period-req-pin-103
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\Service\Catalogue\CatalogueUnreadableException;
use OCA\OpenCatalogi\Service\Publication\TermRollUnavailableException;
use OCA\OpenCatalogi\Settings\OpenCatalogiAdmin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Opens inspection windows and answers the inspection link.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-documents-go-on-public-inspection-for-exactly-the-statutory-period-req-pin-103
 */
class InspectionController extends Controller {
	use AnswersCrossOriginRequests;
	use ResolvesRegisterConfiguration;

	/**
	 * Constructor.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The request.
	 * @param IAppConfig $config App configuration.
	 * @param IL10N $l10n Localisation.
	 * @param IUserSession $userSession The current session.
	 * @param InspectionRecords $registers The inspection windows, and their records in the configured register pair.
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly IAppConfig $config,
		private readonly IL10N $l10n,
		private readonly IUserSession $userSession,
		private readonly InspectionRecords $registers,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * Answer a CORS preflight.
	 *
	 * @return Response The preflight response.
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @spec openspec/specs/cross-origin-api-access/spec.md#requirement-answer-cors-preflight-requests-on-public-api-controllers-cor-001
	 */
	#[AnonRateLimit(limit: 240, period: 60)]
	public function preflightedCors(): Response {
		$response = new Response();
		$response->addHeader('Access-Control-Allow-Origin', $this->resolveAllowedOrigin());
		$response->addHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
		$response->addHeader('Access-Control-Max-Age', '1728000');
		$response->addHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type, Accept');
		$response->addHeader('Access-Control-Allow-Credentials', 'false');

		return $response;
	}//end preflightedCors()

	/**
	 * Open an inspection on a record, choosing its documents.
	 *
	 * @return JSONResponse The inspection, or the refusal.
	 *
	 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-documents-go-on-public-inspection-for-exactly-the-statutory-period-req-pin-103
	 * @spec openspec/changes/inspection-period-rolls-on-the-calendar/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-an-inspection-period-ends-on-a-working-day-req-ipr-001
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function open(): JSONResponse {
		$parameters = $this->openParameters();
		if ($parameters === null) {
			return new JSONResponse(data: ['error' => 'missing-parameters'], statusCode: Http::STATUS_BAD_REQUEST);
		}

		try {
			$inspection = $this->registers->open(parameters: $parameters, openedBy: $this->openerId());
		} catch (\DomainException $e) {
			return new JSONResponse(
				data: ['error' => 'inspection-refused', 'message' => $e->getMessage()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		} catch (TermRollUnavailableException $e) {
			// A statutory end is never stored unrolled because the engine was down.
			return new JSONResponse(
				data: ['error' => 'term-engine-unavailable', 'message' => $e->getMessage()],
				statusCode: Http::STATUS_SERVICE_UNAVAILABLE
			);
		}

		return $this->storedAnswer(inspection: $inspection);
	}//end open()

	/**
	 * The record, its type and the chosen documents the request posts, or
	 * null when any of them is not an array.
	 *
	 * @return array{record: array<string, mixed>, recordType: array<string, mixed>, documents: array<int, string>}|null
	 */
	private function openParameters(): ?array {
		$record = $this->request->getParam('record', []);
		$recordType = $this->request->getParam('recordType', []);
		$documents = $this->request->getParam('documents', []);

		if (is_array($record) === false || is_array($recordType) === false || is_array($documents) === false) {
			return null;
		}

		return ['record' => $record, 'recordType' => $recordType, 'documents' => $documents];
	}//end openParameters()

	/**
	 * Who opens the inspection: the session user, or '' without a session.
	 *
	 * @return string The user id.
	 */
	private function openerId(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return '';
		}

		return $user->getUID();
	}//end openerId()

	/**
	 * Store an opened inspection and answer with it, or with why it could not
	 * be stored.
	 *
	 * @param array<string, mixed> $inspection The opened inspection.
	 *
	 * @return JSONResponse The stored inspection (201), or the refusal.
	 */
	private function storedAnswer(array $inspection): JSONResponse {
		try {
			$saved = $this->registers->store(inspection: $inspection);
		} catch (CatalogueUnreadableException $e) {
			return new JSONResponse(
				data: ['error' => 'register-unreadable', 'message' => $this->l10n->t('The inspection could not be stored.')],
				statusCode: Http::STATUS_SERVICE_UNAVAILABLE
			);
		} catch (\Throwable $e) {
			return $this->registerConfigErrorResponse(e: $e);
		}

		return new JSONResponse($this->registers->toArray(object: $saved), Http::STATUS_CREATED);
	}//end storedAnswer()

	/**
	 * Follow an inspection link.
	 *
	 * A request without a token reads nothing. The inspection is read without
	 * RBAC and answers nothing until the presented token matched it.
	 *
	 * @param string $id The inspection.
	 *
	 * @return JSONResponse The chosen documents, or the refusal with the end date.
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-documents-go-on-public-inspection-for-exactly-the-statutory-period-req-pin-103
	 */
	#[AnonRateLimit(limit: 60, period: 60)]
	public function follow(string $id): JSONResponse {
		$token = (string)$this->request->getParam('token', '');

		return $this->withCors(response: $this->linkResponse(id: $id, token: $token));
	}//end follow()

	/**
	 * Read the inspection a link names and answer it, or refuse.
	 *
	 * @param string $id The inspection.
	 * @param string $token The token the reader presented.
	 *
	 * @return JSONResponse The answer, without its CORS headers.
	 */
	private function linkResponse(string $id, string $token): JSONResponse {
		if ($token === '') {
			return $this->unknownInspection();
		}

		try {
			$inspection = $this->registers->find(id: $id);
		} catch (CatalogueUnreadableException $e) {
			return new JSONResponse(data: ['error' => 'register-unreadable'], statusCode: Http::STATUS_SERVICE_UNAVAILABLE);
		} catch (\Throwable $e) {
			return $this->unknownInspection();
		}

		return $this->outcomeResponse(inspection: $inspection, token: $token);
	}//end linkResponse()

	/**
	 * Answer a stored inspection for the presented token.
	 *
	 * Nothing from the inspection is answered before the token matched it.
	 *
	 * @param array<string, mixed> $inspection The stored inspection.
	 * @param string $token The token the reader presented.
	 *
	 * @return JSONResponse The chosen documents, or the refusal.
	 */
	private function outcomeResponse(array $inspection, string $token): JSONResponse {
		try {
			$outcome = $this->registers->resolveLink(inspection: $inspection, token: $token);
		} catch (\DomainException $e) {
			return $this->unreadableWindow(e: $e);
		}

		if ($outcome['readable'] === false && $outcome['reason'] === 'window-closed') {
			return $this->windowClosed(endDate: (string)$outcome['endDate']);
		}

		if ($outcome['readable'] === false) {
			return $this->unknownInspection();
		}

		return $this->documentsResponse(inspection: $inspection, outcome: $outcome);
	}//end outcomeResponse()

	/**
	 * The answer for a link that is open: the chosen documents and the window.
	 *
	 * @param array<string, mixed> $inspection The stored inspection.
	 * @param array{readable: boolean, documents: array<int, string>, reason: string|null, endDate: string|null} $outcome The resolved link.
	 *
	 * @return JSONResponse 200 with the documents.
	 */
	private function documentsResponse(array $inspection, array $outcome): JSONResponse {
		return new JSONResponse(
			[
				'record' => ($inspection['record'] ?? null),
				'documents' => $outcome['documents'],
				'startDate' => ($inspection['startDate'] ?? null),
				'endDate' => $outcome['endDate'],
			]
		);
	}//end documentsResponse()

	/**
	 * The answer for a window that cannot be read: it refuses rather than defaulting either way.
	 *
	 * @param \DomainException $e Why the window cannot be read.
	 *
	 * @return JSONResponse 503 with the reason.
	 */
	private function unreadableWindow(\DomainException $e): JSONResponse {
		return new JSONResponse(
			data: ['error' => 'unreadable-window', 'message' => $e->getMessage()],
			statusCode: Http::STATUS_SERVICE_UNAVAILABLE
		);
	}//end unreadableWindow()

	/**
	 * The answer for a link whose inspection period has ended.
	 *
	 * @param string $endDate The end of the period.
	 *
	 * @return JSONResponse 410 with the end date.
	 */
	private function windowClosed(string $endDate): JSONResponse {
		return new JSONResponse(
			data: [
				'error' => 'window-closed',
				'message' => $this->l10n->t('The inspection period for these documents has ended.'),
				'endDate' => $endDate,
			],
			statusCode: Http::STATUS_GONE
		);
	}//end windowClosed()

	/**
	 * The answer for an unknown inspection, a wrong token, or no token.
	 *
	 * @return JSONResponse 404.
	 */
	private function unknownInspection(): JSONResponse {
		return new JSONResponse(data: ['error' => 'unknown-inspection'], statusCode: Http::STATUS_NOT_FOUND);
	}//end unknownInspection()

	/**
	 * Add the CORS headers a public endpoint answers with.
	 *
	 * @param JSONResponse $response The response.
	 *
	 * @return JSONResponse The response, with its headers.
	 */
	private function withCors(JSONResponse $response): JSONResponse {
		$response->addHeader('Access-Control-Allow-Origin', $this->resolveAllowedOrigin());
		$response->addHeader('Access-Control-Allow-Credentials', 'false');

		return $response;
	}//end withCors()

}//end class
