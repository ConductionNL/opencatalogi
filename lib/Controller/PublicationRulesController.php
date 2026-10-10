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
 * The obligation overview is here: `GET /api/obligations` (admin) asks every
 * enabled `obligationSource` through `ObligationsRequestedEvent` and lists the
 * sources it could not read beside the obligations of those it could
 * (woo-obligation-overview). The harvest intake joins as the source `harvest`
 * once `harvest-feed-intake` lands; until then no listener answers for it and
 * it shows as unread, which is the truth.
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
use OCA\OpenCatalogi\Service\Publication\ObligationReadService;
use OCA\OpenCatalogi\Service\Publication\PublicationRuleService;
use OCA\OpenCatalogi\Service\Publication\UnreadableRuleException;
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
	 * @param ObligationReadService $obligations The obligation overview reader.
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
		private readonly ObligationReadService $obligations,
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

	/**
	 * The obligation overview: what must be published, what is, and what is
	 * late, over every registered source.
	 *
	 * A source that failed or that no reader answered is in `unreadSources`
	 * with its reason, never counted as a source with nothing to publish.
	 *
	 * @return JSONResponse The overview, or a refusal when the sources cannot be read.
	 *
	 * @spec openspec/changes/woo-obligation-overview/specs/woo-obligation-overview/spec.md#requirement-the-overview-reads-every-registered-source-and-shows-the-ones-it-could-not-read-req-woo-001
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function obligations(): JSONResponse {
		try {
			return new JSONResponse($this->obligations->read());
		} catch (UnreadableRuleException $e) {
			return new JSONResponse(
				data: ['error' => 'obligations-unreadable', 'message' => $e->getMessage()],
				statusCode: Http::STATUS_SERVICE_UNAVAILABLE
			);
		} catch (\Throwable $e) {
			return new JSONResponse(
				data: [
					'error' => 'obligations-unreadable',
					'message' => $this->l10n->t('The obligation sources could not be read, so this is not an overview with nothing to publish.'),
				],
				statusCode: Http::STATUS_SERVICE_UNAVAILABLE
			);
		}

	}//end obligations()
}//end class
