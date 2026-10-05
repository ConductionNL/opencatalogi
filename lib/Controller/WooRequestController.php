<?php

/**
 * OpenCatalogi Woo request controller.
 *
 * The API a request arrives over, and the API a Woo officer works a term with.
 * The citizen-facing form is portaliq's, so this leaves a clean API for it to
 * call rather than rendering a form here.
 *
 * Extends Controller, not OCSController, deliberately. Nextcloud's OCSMiddleware
 * turns a 403 from an OCSController into HTTP 200, and a refused second extension
 * that reads as success in a browser is the exact failure this change must not
 * ship.
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
 * @spec openspec/specs/woo-request-intake/spec.md#requirement-a-woo-request-is-a-record-of-its-own-req-wri-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Controller;

use DateTimeImmutable;
use OCA\OpenCatalogi\Service\Woo\StatutoryTerm;
use OCA\OpenCatalogi\Service\Woo\TermEngineUnavailableException;
use OCA\OpenCatalogi\Service\Woo\TermRefusedException;
use OCA\OpenCatalogi\Service\Woo\WooRequestService;
use OCA\OpenCatalogi\Service\Woo\WooRequestStore;
use OCA\OpenCatalogi\Settings\OpenCatalogiAdmin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Mints Woo requests and works their statutory term.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/specs/woo-request-intake/spec.md#requirement-a-woo-request-is-a-record-of-its-own-req-wri-001
 */
class WooRequestController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The request.
	 * @param IL10N $l10n Localisation.
	 * @param IUserSession $userSession The current session.
	 * @param WooRequestService $requests Builds and reads the request record.
	 * @param WooRequestStore $store Reads and writes the stored requests.
	 * @param StatutoryTerm $terms Arms, extends, pauses and reads the statutory term.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly IL10N $l10n,
		private readonly IUserSession $userSession,
		private readonly WooRequestService $requests,
		private readonly WooRequestStore $store,
		private readonly StatutoryTerm $terms,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()



	/**
	 * The signed-in user id, or an empty string.
	 *
	 * @return string The user id.
	 *
	 * @spec exclude internal helper — reads the acting identity.
	 */
	private function actor(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return '';
		}

		return $user->getUID();

	}//end actor()

	/**
	 * The operator-actionable 503 for a register that could not be resolved.
	 *
	 * The store's message names the missing config key, so it is carried through
	 * rather than replaced: an admin who sees the key can fix it, and an admin
	 * who sees "service unavailable" cannot.
	 *
	 * @param \Throwable $e The failure.
	 *
	 * @return JSONResponse A 503 with the detail.
	 *
	 * @spec exclude internal helper — shapes the unresolved-register response.
	 */
	private function registerConfigErrorResponse(\Throwable $e): JSONResponse {
		return new JSONResponse(
			['error' => 'register_not_configured', 'detail' => $e->getMessage()],
			Http::STATUS_SERVICE_UNAVAILABLE
		);

	}//end registerConfigErrorResponse()

	/**
	 * Receive a Woo request, arm its statutory term, and answer with the
	 * reference and the due date.
	 *
	 * This is the API portaliq's citizen form calls. The clock is in the
	 * response, not behind a second call, because a receipt that leaves the date
	 * out is the cheapest way to fail the duty to tell a requester when their
	 * answer is owed.
	 *
	 * @return JSONResponse The request, its receipt, or the refusal.
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-the-requester-is-told-the-reference-and-the-due-date-at-intake-req-wri-005
	 *
	 * @no-admin-idor-exempt CREATES a new request from the body alone. There is no
	 *   caller-supplied object id and nothing existing is read or written, so there is
	 *   no object to scope the caller against. The reference is minted by
	 *   `WooRequestService::mintReference()` from `random_bytes`, never taken from the
	 *   caller, so a caller cannot address or overwrite somebody else's request. Reading
	 *   a request back is a separate, admin-authorized endpoint.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 20, period: 3600)]
	public function receive(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(
				data: ['error' => $this->l10n->t('Not logged in')],
				statusCode: Http::STATUS_UNAUTHORIZED
			);
		}

		try {
			$record = $this->requests->receive(input: $this->intakeInput(), receivedBy: $this->actor());
		} catch (\DomainException $e) {
			return new JSONResponse(
				data: ['error' => 'request-refused', 'message' => $e->getMessage()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$saved = $this->store->save(record: $record);
		} catch (\Throwable $e) {
			return $this->registerConfigErrorResponse(e: $e);
		}

		$requestId = (string)($saved['id'] ?? '');

		try {
			$term = $this->terms->arm(
				requestUuid: $requestId,
				reference: (string)($saved['reference'] ?? ''),
				receivedAt: new DateTimeImmutable((string)($saved['receivedAt'] ?? 'now')),
				actor: $this->actor()
			);
		} catch (TermEngineUnavailableException | TermRefusedException $e) {
			// The request is already stored, which is right: a request that
			// arrived has arrived. What is refused is the claim that a term is
			// running, so the receipt says the term was not armed rather than
			// quoting a due date nobody computed.
			return new JSONResponse(
				data: [
					'request' => $saved,
					'receipt' => $this->requests->receipt(request: $saved),
					'error' => 'term-not-armed',
					'message' => $e->getMessage(),
				],
				statusCode: Http::STATUS_ACCEPTED
			);
		}

		try {
			$stored = $this->store->save(
				record: $this->requests->withTerm(request: $saved, term: $term),
				uuid: $requestId
			);
		} catch (\Throwable $e) {
			return $this->registerConfigErrorResponse(e: $e);
		}

		return new JSONResponse(
			[
				'request' => $stored,
				'term' => $term,
				'receipt' => $this->requests->receipt(request: $stored),
			],
			Http::STATUS_CREATED
		);

	}//end receive()

	/**
	 * The intake body, read off the request.
	 *
	 * @return array<string, mixed> What arrived.
	 *
	 * @spec exclude internal helper — reads the intake body.
	 */
	private function intakeInput(): array {
		return [
			'requestedInformation' => $this->request->getParam('requestedInformation', ''),
			'requesterName' => $this->request->getParam('requesterName', ''),
			'requesterEmail' => $this->request->getParam('requesterEmail', ''),
			'requesterPhone' => $this->request->getParam('requesterPhone', ''),
			'requesterAddress' => $this->request->getParam('requesterAddress', ''),
			'channel' => $this->request->getParam('channel', 'web'),
		];

	}//end intakeInput()


	/**
	 * Terms met and missed, over every request.
	 *
	 * With the term armed this is a query, which is why it is here and not a
	 * second store of outcomes that can disagree with the terms.
	 *
	 * @return JSONResponse The report.
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-terms-met-and-missed-are-reported-req-wri-006
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function termsReport(): JSONResponse {
		try {
			$requests = $this->store->all();
		} catch (\Throwable $e) {
			return $this->registerConfigErrorResponse(e: $e);
		}

		return new JSONResponse($this->requests->termsReport(requests: $requests));

	}//end termsReport()

	/**
	 * Read one request back with its term as the engine reports it now.
	 *
	 * @param string $requestId The request.
	 *
	 * @return JSONResponse The request and its term.
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-a-woo-request-is-a-record-of-its-own-req-wri-001
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function show(string $requestId): JSONResponse {
		$loaded = $this->load(requestId: $requestId);
		if (($loaded instanceof JSONResponse) === true) {
			return $loaded;
		}

		return new JSONResponse(
			[
				'request' => $loaded,
				'term' => $this->currentTerm(request: $loaded),
				'receipt' => $this->requests->receipt(request: $loaded),
			]
		);

	}//end show()

	/**
	 * Extend the term by the two weeks the law allows.
	 *
	 * A second extension is REFUSED, with 409 Conflict and the engine's own
	 * message, which names the bound it refused on.
	 *
	 * @param string $requestId The request.
	 *
	 * @return JSONResponse The extended term, or the refusal.
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-the-term-is-extended-once-and-a-second-extension-is-refused-req-wri-003
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function extend(string $requestId): JSONResponse {
		$rationale = trim((string)$this->request->getParam('rationale', ''));
		if ($rationale === '') {
			return new JSONResponse(
				data: [
					'error' => 'rationale-required',
					'message' => $this->l10n->t('An extension records why it was granted.'),
				],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		$loaded = $this->load(requestId: $requestId);
		if (($loaded instanceof JSONResponse) === true) {
			return $loaded;
		}

		$timer = (string)($loaded['termTimer'] ?? '');
		if (trim($timer) === '') {
			return new JSONResponse(
				data: [
					'error' => 'no-term',
					'message' => $this->l10n->t('This request has no statutory term, so there is nothing to extend.'),
				],
				statusCode: Http::STATUS_CONFLICT
			);
		}

		try {
			$term = $this->terms->extendOnce(timerUuid: $timer, rationale: $rationale, actor: $this->actor());
		} catch (TermRefusedException $e) {
			return new JSONResponse(
				data: ['error' => 'extension-refused', 'message' => $e->getMessage()],
				statusCode: Http::STATUS_CONFLICT
			);
		} catch (TermEngineUnavailableException $e) {
			return new JSONResponse(
				data: ['error' => 'term-engine-unavailable', 'message' => $e->getMessage()],
				statusCode: Http::STATUS_SERVICE_UNAVAILABLE
			);
		}

		$updated = $this->requests->withTerm(request: $loaded, term: $term);
		$updated['extensionReason'] = $rationale;

		return $this->persist(request: $updated, requestId: $requestId, term: $term);

	}//end extend()

	/**
	 * Suspend the term while clarification is awaited.
	 *
	 * @param string $requestId The request.
	 *
	 * @return JSONResponse The suspended term, or the refusal.
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-the-term-pauses-while-clarification-is-awaited-req-wri-004
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function pause(string $requestId): JSONResponse {
		return $this->move(requestId: $requestId, operation: 'pause', status: 'awaiting_clarification');

	}//end pause()

	/**
	 * Resume the term once the clarification arrived.
	 *
	 * @param string $requestId The request.
	 *
	 * @return JSONResponse The running term, or the refusal.
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-the-term-pauses-while-clarification-is-awaited-req-wri-004
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function resume(string $requestId): JSONResponse {
		return $this->move(requestId: $requestId, operation: 'resume', status: 'in_progress');

	}//end resume()

	/**
	 * Attach the disclosure batch this request produced.
	 *
	 * The link lives on the request. A batch has been in production without one
	 * and keeps working without one.
	 *
	 * @param string $requestId The request.
	 *
	 * @return JSONResponse The request, or the refusal.
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-a-request-may-produce-a-batch-and-a-batch-works-without-a-request-req-wri-007
	 */
	#[AuthorizedAdminSetting(settings: OpenCatalogiAdmin::class)]
	public function attachBatch(string $requestId): JSONResponse {
		$loaded = $this->load(requestId: $requestId);
		if (($loaded instanceof JSONResponse) === true) {
			return $loaded;
		}

		try {
			$updated = $this->requests->attachBatch(
				request: $loaded,
				batchUuid: (string)$this->request->getParam('batch', '')
			);
		} catch (\DomainException $e) {
			return new JSONResponse(
				data: ['error' => 'batch-refused', 'message' => $e->getMessage()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		return $this->persist(request: $updated, requestId: $requestId, term: null);

	}//end attachBatch()

	/**
	 * Suspend or resume the term, and move the request's status with it.
	 *
	 * @param string $requestId The request.
	 * @param string $operation Either pause or resume.
	 * @param string $status The status the request moves to.
	 *
	 * @return JSONResponse The term, or the refusal.
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-the-term-pauses-while-clarification-is-awaited-req-wri-004
	 */
	private function move(string $requestId, string $operation, string $status): JSONResponse {
		$reason = trim((string)$this->request->getParam('reason', ''));
		if ($reason === '') {
			return new JSONResponse(
				data: [
					'error' => 'reason-required',
					'message' => $this->l10n->t('Suspending or resuming a term records why.'),
				],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		$loaded = $this->load(requestId: $requestId);
		if (($loaded instanceof JSONResponse) === true) {
			return $loaded;
		}

		$timer = trim((string)($loaded['termTimer'] ?? ''));
		if ($timer === '') {
			return new JSONResponse(
				data: [
					'error' => 'no-term',
					'message' => $this->l10n->t('This request has no statutory term, so there is nothing to pause.'),
				],
				statusCode: Http::STATUS_CONFLICT
			);
		}

		try {
			$term = $this->workTerm(operation: $operation, timer: $timer, reason: $reason);
		} catch (TermRefusedException $e) {
			return new JSONResponse(
				data: ['error' => $operation . '-refused', 'message' => $e->getMessage()],
				statusCode: Http::STATUS_CONFLICT
			);
		} catch (TermEngineUnavailableException $e) {
			return new JSONResponse(
				data: ['error' => 'term-engine-unavailable', 'message' => $e->getMessage()],
				statusCode: Http::STATUS_SERVICE_UNAVAILABLE
			);
		}

		$updated = $this->requests->withTerm(request: $loaded, term: $term);
		$updated['status'] = $status;

		return $this->persist(request: $updated, requestId: $requestId, term: $term);

	}//end move()

	/**
	 * Suspend or resume the term, whichever was asked for.
	 *
	 * Split out so the caller has no else branch and no path on which the term
	 * is undefined.
	 *
	 * @param string $operation Either pause or resume.
	 * @param string $timer The term.
	 * @param string $reason Why.
	 *
	 * @return array<string, mixed> The term.
	 *
	 * @throws TermRefusedException When the engine refuses.
	 * @throws TermEngineUnavailableException When the engine is unavailable.
	 *
	 * @spec exclude internal helper — dispatches to the term adapter.
	 */
	private function workTerm(string $operation, string $timer, string $reason): array {
		if ($operation === 'pause') {
			return $this->terms->pause(timerUuid: $timer, reason: $reason, actor: $this->actor());
		}

		return $this->terms->resume(timerUuid: $timer, reason: $reason, actor: $this->actor());

	}//end workTerm()

	/**
	 * Load a request, or the response that says why it could not be loaded.
	 *
	 * @param string $requestId The request.
	 *
	 * @return array<string, mixed>|JSONResponse The request, or the refusal.
	 *
	 * @spec exclude internal helper — loads one request.
	 */
	private function load(string $requestId): array|JSONResponse {
		try {
			$loaded = $this->store->find(requestId: $requestId);
		} catch (\Throwable $e) {
			return $this->registerConfigErrorResponse(e: $e);
		}

		if ($loaded === null) {
			return new JSONResponse(data: ['error' => 'unknown-request'], statusCode: Http::STATUS_NOT_FOUND);
		}

		return $loaded;

	}//end load()

	/**
	 * The term as the engine reports it now, or null when there is none to read.
	 *
	 * @param array<string, mixed> $request The request.
	 *
	 * @return array<string, mixed>|null The term.
	 *
	 * @spec exclude internal helper — reads the current term for a request.
	 */
	private function currentTerm(array $request): ?array {
		$timer = trim((string)($request['termTimer'] ?? ''));
		if ($timer === '') {
			return null;
		}

		try {
			return $this->terms->describe(timerUuid: $timer);
		} catch (TermEngineUnavailableException | TermRefusedException $e) {
			return null;
		}

	}//end currentTerm()

	/**
	 * Store the request and answer with it.
	 *
	 * @param array<string, mixed> $request The request.
	 * @param string $requestId Its id.
	 * @param array<string, mixed>|null $term The term, when one was just worked.
	 *
	 * @return JSONResponse The request.
	 *
	 * @spec exclude internal helper — persists one request.
	 */
	private function persist(array $request, string $requestId, ?array $term): JSONResponse {
		try {
			$stored = $this->store->save(record: $request, uuid: $requestId);
		} catch (\Throwable $e) {
			return $this->registerConfigErrorResponse(e: $e);
		}

		return new JSONResponse(['request' => $stored, 'term' => $term]);

	}//end persist()
}//end class
