<?php

/**
 * OpenCatalogi Publication Rules Controller.
 *
 * The admin side of active publication: the rules that decide what publishes
 * and what an anonymous reader may read of it, the preview that shows both
 * halves before a rule is saved, the walked process around a publication, and
 * the zienswijze round.
 *
 * Depublication with its acknowledgements is DepublicationController, and what
 * is published outward (the collections, the official notice and the
 * verifiable stamp) is PublicationDisclosureController. Both moved out on
 * 2026-09-19 and this paragraph names them, because a docblock that still
 * advertises a surface is what stops the next person looking for it.
 *
 * There is NO obligation overview here, and this sentence says so because the
 * previous one claimed there was. `ObligationOverviewService` assembles an
 * overview from what it is given, and nothing gives it anything yet: the
 * per-source readers and the harvest intake it reads from are
 * `harvest-feed-intake`. REQ-PIN-110 is unmet until that lands, and a docblock
 * advertising the surface is what stops the next person checking.
 *
 * Every write here is admin-gated. The two public surfaces of this change live
 * in InspectionController (the inspection link) and in the public search
 * handler below, and both run the anonymous permission set at the read.
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
 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\Service\Publication\DecisionPublicationValidator;
use OCA\OpenCatalogi\Service\Publication\PublicationProcessService;
use OCA\OpenCatalogi\Service\Publication\PublicationRuleService;
use OCA\OpenCatalogi\Service\Publication\UnreadableRuleException;
use OCA\OpenCatalogi\Service\Publication\ZienswijzeService;
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
 * The admin surfaces of active publication, and the public search over it.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md
 */
class PublicationRulesController extends Controller {
	use AnswersCrossOriginRequests;

	/**
	 * Constructor.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The request.
	 * @param IAppConfig $config App configuration.
	 * @param IL10N $l10n Localisation.
	 * @param IUserSession $userSession The current session.
	 * @param PublicationRuleService $ruleService The rule evaluator.
	 * @param DecisionPublicationValidator $decisionValidator The decision type validation.
	 * @param PublicationProcessService $processService The walked process.
	 * @param ZienswijzeService $zienswijzeService The consultation round.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly IAppConfig $config,
		private readonly IL10N $l10n,
		private readonly IUserSession $userSession,
		private readonly PublicationRuleService $ruleService,
		private readonly DecisionPublicationValidator $decisionValidator,
		private readonly PublicationProcessService $processService,
		private readonly ZienswijzeService $zienswijzeService,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * Who is acting, for the record.
	 *
	 * @return string The user id, or an empty string.
	 */
	private function actor(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return '';
		}

		return $user->getUID();

	}//end actor()

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
	 * Preview a rule before it is saved.
	 *
	 * The preview answers both halves: which records the rule would publish,
	 * and which properties it would expose. A rule that is too wide is the risk
	 * this exists for, and a preview that only counted records would not show
	 * it.
	 *
	 * @return JSONResponse The preview.
	 *
	 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-a-record-type-is-readable-without-an-account-with-the-visible-parts-chosen-req-pin-101
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function previewRule(): JSONResponse {
		$rule = $this->request->getParam('rule', []);
		$sample = $this->request->getParam('sample', []);

		if (is_array($rule) === false || $rule === []) {
			return new JSONResponse(
				data: ['error' => 'missing-rule', 'message' => $this->l10n->t('Send the rule to preview.')],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		if (is_array($sample) === false) {
			$sample = [];
		}

		try {
			$preview = $this->ruleService->preview(rule: $rule, sample: array_values(array_filter($sample, 'is_array')));
		} catch (UnreadableRuleException $e) {
			return new JSONResponse(
				data: ['error' => 'unreadable-rule', 'message' => $e->getMessage()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		return new JSONResponse($preview);

	}//end previewRule()

	/**
	 * Validate a decision against its type before it is published.
	 *
	 * The refusal travels back to the case app as a refusal to publish, with
	 * the reason, so the caller learns what is missing rather than that
	 * something went wrong.
	 *
	 * @return JSONResponse The validation, or the refusal with its reasons.
	 *
	 * @NoAdminRequired
	 *
	 * @no-admin-idor-exempt the decision and its type arrive in the request body
	 * and are validated as given. Nothing is looked up by identifier, nothing is
	 * stored, and an unauthenticated caller is refused above, so there is no
	 * object reference to substitute.
	 *
	 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-the-type-declares-publication-and-the-decision-types-rules-are-validated-req-pin-102
	 */
	public function validateDecision(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(data: ['error' => 'not-logged-in'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$decision = $this->request->getParam('decision', []);
		$decisionType = $this->request->getParam('decisionType', []);

		if (is_array($decision) === false || is_array($decisionType) === false || $decisionType === []) {
			return new JSONResponse(
				data: ['error' => 'missing-parameters', 'message' => $this->l10n->t('Send the decision and its type.')],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		$validation = $this->decisionValidator->validate(decision: $decision, decisionType: $decisionType);

		if ($validation['publishable'] === false) {
			return new JSONResponse(
				data: [
					'error' => 'not-publishable',
					'reasons' => $validation['reasons'],
				],
				statusCode: Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		return new JSONResponse($validation);

	}//end validateDecision()

	/**
	 * Start the walked process for one publication.
	 *
	 * @return JSONResponse The process.
	 *
	 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-publication-runs-as-a-walked-process-req-pin-104
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function startProcess(): JSONResponse {
		$publicationId = trim((string)$this->request->getParam('publication', ''));
		if ($publicationId === '') {
			return new JSONResponse(
				data: ['error' => 'missing-publication'],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		$enabled = json_decode(
			$this->config->getValueString($this->appName, 'publication_process_steps', '{}'),
			true
		);
		if (is_array($enabled) === false) {
			$enabled = [];
		}

		return new JSONResponse(
			$this->processService->start(publicationId: $publicationId, enabledSteps: $enabled)
		);

	}//end startProcess()

	/**
	 * Record that a step of the process was completed.
	 *
	 * The publication is held while a zienswijze ask is open inside its term,
	 * and the open ask is named in the refusal.
	 *
	 * @return JSONResponse The process, or the hold with what holds it.
	 *
	 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-publication-runs-as-a-walked-process-req-pin-104
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function completeStep(): JSONResponse {
		$process = $this->request->getParam('process', []);
		$step = trim((string)$this->request->getParam('step', ''));
		$asks = $this->request->getParam('asks', []);

		if (is_array($process) === false || $process === [] || $step === '') {
			return new JSONResponse(data: ['error' => 'missing-parameters'], statusCode: Http::STATUS_BAD_REQUEST);
		}

		if (is_array($asks) === false) {
			$asks = [];
		}

		$advance = $this->processService->mayAdvance(
			asks: array_values(array_filter($asks, 'is_array'))
		);

		if ($advance['mayAdvance'] === false) {
			return new JSONResponse(
				data: [
					'error' => 'held',
					'message' => $this->l10n->t('This publication is held while a zienswijze is still open.'),
					'heldBy' => $advance['heldBy'],
				],
				statusCode: Http::STATUS_CONFLICT
			);
		}

		try {
			$updated = $this->processService->complete(
				process: $process,
				step: $step,
				completedBy: $this->actor()
			);
		} catch (\DomainException $e) {
			return new JSONResponse(
				data: ['error' => 'step-refused', 'message' => $e->getMessage()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		return new JSONResponse($updated);

	}//end completeStep()

	/**
	 * Raise a zienswijze ask to an interested party.
	 *
	 * @return JSONResponse The ask, or the refusal.
	 *
	 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-interested-parties-are-consulted-before-information-about-them-is-published-req-pin-105
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function raiseZienswijze(): JSONResponse {
		try {
			$ask = $this->zienswijzeService->raise(
				publicationId: trim((string)$this->request->getParam('publication', '')),
				party: trim((string)$this->request->getParam('party', '')),
				channel: trim((string)$this->request->getParam('channel', '')),
				termDays: (int)$this->request->getParam('termDays', 0)
			);
		} catch (\DomainException $e) {
			return new JSONResponse(
				data: ['error' => 'ask-refused', 'message' => $e->getMessage()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		return new JSONResponse($ask, Http::STATUS_CREATED);

	}//end raiseZienswijze()

	/**
	 * Search published information in plain words, without an account.
	 *
	 * The search runs over the anonymous projections, never over the records,
	 * so a word that occurs only in a withheld property cannot return the
	 * record. Every result names the dossier the document belongs to.
	 *
	 * @return JSONResponse The results.
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-the-public-searches-published-information-in-plain-words-req-pin-111
	 */
	#[AnonRateLimit(limit: 60, period: 60)]
	public function publicSearch(): JSONResponse {
		$records = $this->request->getParam('records', []);
		$rules = $this->request->getParam('rules', []);
		$terms = (string)$this->request->getParam('q', '');

		if (is_array($records) === false || is_array($rules) === false) {
			return new JSONResponse(data: ['error' => 'missing-parameters'], statusCode: Http::STATUS_BAD_REQUEST);
		}

		try {
			$results = $this->ruleService->searchPublic(
				records: array_values(array_filter($records, 'is_array')),
				rulesByType: $this->ruleService->indexByRecordType(
					rules: array_values(array_filter($rules, 'is_array'))
				),
				terms: $terms
			);
		} catch (UnreadableRuleException $e) {
			// A rule this app cannot evaluate refuses. Answering the empty set
			// would read as "nothing published matches", which is a claim
			// nobody checked.
			return new JSONResponse(
				data: ['error' => 'unreadable-rule', 'message' => $e->getMessage()],
				statusCode: Http::STATUS_SERVICE_UNAVAILABLE
			);
		}

		$response = new JSONResponse(['results' => $results, 'total' => count($results)]);
		$response->addHeader('Access-Control-Allow-Origin', $this->resolveAllowedOrigin());

		return $response;

	}//end publicSearch()
}//end class
