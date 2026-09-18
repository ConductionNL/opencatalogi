<?php

/**
 * OpenCatalogi Publication Rules Controller.
 *
 * The admin side of active publication: the rules that decide what publishes
 * and what an anonymous reader may read of it, the preview that shows both
 * halves before a rule is saved, the walked process around a publication, the
 * zienswijze round, depublication, and the obligation overview.
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
use OCA\OpenCatalogi\Service\Publication\DepublicationService;
use OCA\OpenCatalogi\Service\Publication\DocumentStampService;
use OCA\OpenCatalogi\Service\Publication\IndexUnreachableException;
use OCA\OpenCatalogi\Service\Publication\NationalIndexService;
use OCA\OpenCatalogi\Service\Publication\PublicationProcessService;
use OCA\OpenCatalogi\Service\Publication\PublicationRuleService;
use OCA\OpenCatalogi\Service\Publication\PublishedCollectionsService;
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
	 * @param DepublicationService $depublicationService Taking a publication back.
	 * @param NationalIndexService $indexService The national channels.
	 * @param DocumentStampService $stampService The verifiable stamp.
	 * @param PublishedCollectionsService $collectionsService The configured published set.
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
		private readonly DepublicationService $depublicationService,
		private readonly NationalIndexService $indexService,
		private readonly DocumentStampService $stampService,
		private readonly PublishedCollectionsService $collectionsService,
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
	 * Resolve the Access-Control-Allow-Origin header value.
	 *
	 * @return string The header value.
	 */
	private function resolveAllowedOrigin(): string {
		$configured = trim($this->config->getValueString($this->appName, 'cors_allowed_origins', '*'));
		if ($configured === '' || $configured === '*') {
			return '*';
		}

		$allowlist = array_values(
			array_filter(array_map('trim', explode(',', $configured)), static fn (string $e): bool => $e !== '')
		);
		$callerOrigin = $this->request->getHeader('Origin');
		if ($callerOrigin !== '' && in_array($callerOrigin, $allowlist, true) === true) {
			return $callerOrigin;
		}

		return ($allowlist[0] ?? '*');

	}//end resolveAllowedOrigin()

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
	 * Depublish in one action and withdraw from every channel it reached.
	 *
	 * @return JSONResponse The depublication, with any outstanding channel named.
	 *
	 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-something-published-in-error-is-depublished-with-one-action-req-pin-106
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function depublish(): JSONResponse {
		$publication = $this->request->getParam('publication', []);
		$channels = $this->request->getParam('channels', []);

		if (is_array($publication) === false || $publication === []) {
			return new JSONResponse(data: ['error' => 'missing-publication'], statusCode: Http::STATUS_BAD_REQUEST);
		}

		if (is_array($channels) === false) {
			$channels = [];
		}

		try {
			$depublication = $this->depublicationService->depublish(
				publication: $publication,
				reason: trim((string)$this->request->getParam('reason', '')),
				depublishedBy: $this->actor(),
				channels: array_map('strval', $channels)
			);
		} catch (\DomainException $e) {
			return new JSONResponse(
				data: ['error' => 'depublication-refused', 'message' => $e->getMessage()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		$outstanding = $this->depublicationService->outstandingChannels(depublication: $depublication);

		return new JSONResponse(
			array_merge(
				$depublication,
				[
					'outstandingChannels' => $outstanding,
					'complete' => ($outstanding === []),
				]
			)
		);

	}//end depublish()

	/**
	 * Compose the official notice for both channels and hand it to the gateway.
	 *
	 * @return JSONResponse What was composed and what each destination answered.
	 *
	 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-official-notices-reach-the-national-platform-and-the-local-channel-req-pin-107
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function announce(): JSONResponse {
		$decision = $this->request->getParam('decision', []);
		if (is_array($decision) === false || $decision === []) {
			return new JSONResponse(data: ['error' => 'missing-decision'], statusCode: Http::STATUS_BAD_REQUEST);
		}

		$notices = $this->indexService->composeNotices(decision: $decision);
		$deliveries = [];
		$unreachable = [];

		foreach ($notices as $notice) {
			try {
				$deliveries[] = $this->indexService->deliver(notice: $notice);
			} catch (IndexUnreachableException $e) {
				$unreachable[] = [
					'channel' => (string)($notice['channel'] ?? ''),
					'reason' => $e->getMessage(),
				];
			}
		}

		// The notices are returned whether or not they were delivered, and an
		// undelivered channel is named. Answering 200 with only the deliveries
		// would let an operator read a partial announcement as a complete one.
		$statusCode = Http::STATUS_BAD_GATEWAY;
		if ($unreachable === []) {
			$statusCode = Http::STATUS_OK;
		}

		return new JSONResponse(
			data: [
				'notices' => $notices,
				'delivered' => $deliveries,
				'unreachable' => $unreachable,
				'complete' => ($unreachable === []),
			],
			statusCode: $statusCode
		);

	}//end announce()

	/**
	 * Read or write which collections publish, and on what conditions.
	 *
	 * @return JSONResponse The configured set.
	 *
	 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-which-collections-are-published-and-on-what-conditions-is-configured-req-pin-108
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function publishedCollections(): JSONResponse {
		try {
			return new JSONResponse(['collections' => $this->collectionsService->collections()]);
		} catch (UnreadableRuleException $e) {
			return new JSONResponse(
				data: ['error' => 'unreadable-configuration', 'message' => $e->getMessage()],
				statusCode: Http::STATUS_SERVICE_UNAVAILABLE
			);
		}

	}//end publishedCollections()

	/**
	 * Save which collections publish.
	 *
	 * @return JSONResponse The outcome.
	 *
	 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-which-collections-are-published-and-on-what-conditions-is-configured-req-pin-108
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function savePublishedCollections(): JSONResponse {
		$collections = $this->request->getParam('collections', []);
		if (is_array($collections) === false) {
			return new JSONResponse(data: ['error' => 'missing-collections'], statusCode: Http::STATUS_BAD_REQUEST);
		}

		$outcome = $this->collectionsService->save(collections: array_values($collections));

		if ($outcome['saved'] === false) {
			return new JSONResponse(
				data: ['error' => 'invalid-collections', 'errors' => $outcome['errors']],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		return new JSONResponse(['saved' => true]);

	}//end savePublishedCollections()

	/**
	 * The organisation's published verification key.
	 *
	 * @return JSONResponse The key, or a refusal saying there is none.
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-a-published-document-carries-a-verifiable-stamp-req-pin-109
	 */
	#[AnonRateLimit(limit: 60, period: 60)]
	public function verificationKey(): JSONResponse {
		$key = $this->stampService->publishedKey();

		if ($key === null) {
			return new JSONResponse(
				data: [
					'error' => 'no-key',
					'message' => $this->l10n->t('This organisation publishes no verification key, so a stamp here cannot be checked.'),
				],
				statusCode: Http::STATUS_SERVICE_UNAVAILABLE
			);
		}

		$response = new JSONResponse($key);
		$response->addHeader('Access-Control-Allow-Origin', $this->resolveAllowedOrigin());

		return $response;

	}//end verificationKey()

	/**
	 * Verify a published document against the organisation's key.
	 *
	 * @return JSONResponse Whether the check passed, and why it did not.
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-a-published-document-carries-a-verifiable-stamp-req-pin-109
	 */
	#[AnonRateLimit(limit: 30, period: 60)]
	public function verifyDocument(): JSONResponse {
		$document = (string)$this->request->getParam('document', '');
		$metadata = $this->request->getParam('metadata', []);
		$stamp = $this->request->getParam('stamp', []);

		if (is_array($metadata) === false || is_array($stamp) === false || $stamp === []) {
			return new JSONResponse(
				data: ['error' => 'missing-parameters', 'message' => $this->l10n->t('Send the document, its publication metadata and its stamp.')],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		// A document that is not valid base64 is verified as the raw bytes that
		// came in, so a caller that posted the document unencoded gets a real
		// verdict rather than a verification of the empty string.
		$documentBytes = base64_decode($document, true);
		if ($documentBytes === false) {
			$documentBytes = $document;
		}

		$outcome = $this->stampService->verify(
			documentBytes: $documentBytes,
			metadata: $metadata,
			stamp: $stamp
		);

		$response = new JSONResponse($outcome);
		$response->addHeader('Access-Control-Allow-Origin', $this->resolveAllowedOrigin());

		return $response;

	}//end verifyDocument()

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
