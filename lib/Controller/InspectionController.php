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

use OCA\OpenCatalogi\Contract\InspectionServiceInterface;
use OCA\OpenCatalogi\Contract\ServiceCatalogueServiceInterface;
use OCA\OpenCatalogi\Service\Catalogue\CatalogueUnreadableException;
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
use Psr\Container\ContainerInterface;

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
	use ReadsOpenRegisterResults;

	/**
	 * Constructor.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The request.
	 * @param IAppConfig $config App configuration.
	 * @param ContainerInterface $container Server container.
	 * @param IL10N $l10n Localisation.
	 * @param IUserSession $userSession The current session.
	 * @param InspectionServiceInterface $inspectionService The inspection windows.
	 * @param ServiceCatalogueServiceInterface $objects The OpenRegister reader that refuses rather than defaulting.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly IAppConfig $config,
		private readonly ContainerInterface $container,
		private readonly IL10N $l10n,
		private readonly IUserSession $userSession,
		private readonly InspectionServiceInterface $inspectionService,
		private readonly ServiceCatalogueServiceInterface $objects,
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
		$this->addAllowedOriginHeaders(response: $response);
		$response->addHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
		$response->addHeader('Access-Control-Max-Age', '1728000');
		$response->addHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type, Accept');
		$response->addHeader('Access-Control-Allow-Credentials', 'false');

		return $response;

	}//end preflightedCors()

	/**
	 * The register and schema the inspections live in.
	 *
	 * @return array<string, string> The register and schema identifiers.
	 */
	private function inspectionConfiguration(): array {
		return $this->resolveRegisterConfiguration(
			registerKey: 'publication_register',
			schemaKey: 'inspection_schema'
		);

	}//end inspectionConfiguration()

	/**
	 * Open an inspection on a record, choosing its documents.
	 *
	 * @return JSONResponse The inspection, or the refusal.
	 *
	 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-documents-go-on-public-inspection-for-exactly-the-statutory-period-req-pin-103
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function open(): JSONResponse {
		$record = $this->request->getParam('record', []);
		$recordType = $this->request->getParam('recordType', []);
		$documents = $this->request->getParam('documents', []);

		if (is_array($record) === false || is_array($recordType) === false || is_array($documents) === false) {
			return new JSONResponse(data: ['error' => 'missing-parameters'], statusCode: Http::STATUS_BAD_REQUEST);
		}

		$user = $this->userSession->getUser();
		$openedBy = '';
		if ($user !== null) {
			$openedBy = $user->getUID();
		}

		try {
			$inspection = $this->inspectionService->open(
				record: $record,
				recordType: $recordType,
				documents: array_map('strval', $documents),
				openedBy: $openedBy
			);
		} catch (\DomainException $e) {
			return new JSONResponse(
				data: ['error' => 'inspection-refused', 'message' => $e->getMessage()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$config = $this->inspectionConfiguration();
			$saved = $this->objects->getObjectService()->saveObject(
				object: $inspection,
				extend: [],
				register: $config['register'],
				schema: $config['schema']
			);
		} catch (CatalogueUnreadableException $e) {
			return new JSONResponse(
				data: ['error' => 'register-unreadable', 'message' => $this->l10n->t('The inspection could not be stored.')],
				statusCode: Http::STATUS_SERVICE_UNAVAILABLE
			);
		} catch (\Throwable $e) {
			return $this->registerConfigErrorResponse(e: $e);
		}

		return new JSONResponse($this->asArray(object: $saved), Http::STATUS_CREATED);

	}//end open()

	/**
	 * Follow an inspection link.
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

		try {
			$config = $this->inspectionConfiguration();
			$inspection = $this->asArray(
				object: $this->objects->getObjectService()->find(
					id: $id,
					register: $config['register'],
					schema: $config['schema']
				)
			);
		} catch (CatalogueUnreadableException $e) {
			return $this->withCors(
				response: new JSONResponse(
					data: ['error' => 'register-unreadable'],
					statusCode: Http::STATUS_SERVICE_UNAVAILABLE
				)
			);
		} catch (\Throwable $e) {
			return $this->withCors(
				response: new JSONResponse(data: ['error' => 'unknown-inspection'], statusCode: Http::STATUS_NOT_FOUND)
			);
		}

		try {
			$outcome = $this->inspectionService->resolveLink(inspection: $inspection, token: $token);
		} catch (\DomainException $e) {
			// An unreadable window refuses rather than defaulting either way.
			return $this->withCors(
				response: new JSONResponse(
					data: ['error' => 'unreadable-window', 'message' => $e->getMessage()],
					statusCode: Http::STATUS_SERVICE_UNAVAILABLE
				)
			);
		}

		if ($outcome['readable'] === false && $outcome['reason'] === 'window-closed') {
			return $this->withCors(
				response: new JSONResponse(
					data: [
						'error' => 'window-closed',
						'message' => $this->l10n->t('The inspection period for these documents has ended.'),
						'endDate' => $outcome['endDate'],
					],
					statusCode: Http::STATUS_GONE
				)
			);
		}

		if ($outcome['readable'] === false) {
			return $this->withCors(
				response: new JSONResponse(data: ['error' => 'unknown-inspection'], statusCode: Http::STATUS_NOT_FOUND)
			);
		}

		return $this->withCors(
			response: new JSONResponse(
				[
					'record' => ($inspection['record'] ?? null),
					'documents' => $outcome['documents'],
					'startDate' => ($inspection['startDate'] ?? null),
					'endDate' => $outcome['endDate'],
				]
			)
		);

	}//end follow()

	/**
	 * Add the CORS headers a public endpoint answers with.
	 *
	 * @param JSONResponse $response The response.
	 *
	 * @return JSONResponse The response, with its headers.
	 */
	private function withCors(JSONResponse $response): JSONResponse {
		$this->addAllowedOriginHeaders(response: $response);
		$response->addHeader('Access-Control-Allow-Credentials', 'false');

		return $response;

	}//end withCors()
}//end class
