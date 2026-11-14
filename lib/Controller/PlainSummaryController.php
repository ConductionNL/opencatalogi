<?php

/**
 * OpenCatalogi Plain Summary Controller.
 *
 * The routes an officer uses to have a text assistant draft a publication's
 * plain language summary, to follow the draft, and to accept a text.
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
 * @spec openspec/changes/publication-plain-language-and-translation/specs/publications/spec.md#requirement-a-drafted-plain-summary-is-a-suggestion-an-officer-accepts-req-ppl-002
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\Service\Publication\PlainLanguageDrafter;
use OCA\OpenCatalogi\Service\Publication\PlainSummaryRefusal;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Draft, follow and accept a publication's plain language summary.
 *
 * @spec openspec/changes/publication-plain-language-and-translation/specs/publications/spec.md#requirement-a-drafted-plain-summary-is-a-suggestion-an-officer-accepts-req-ppl-002
 */
class PlainSummaryController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The request.
	 * @param PlainLanguageDrafter $drafter Drafts, polls and accepts the summary.
	 * @param IUserSession $userSession The current session.
	 *
	 * @spec exclude pure framework plumbing: constructor dependency injection.
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly PlainLanguageDrafter $drafter,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * Whether a text assistant can draft, so the page shows Draft or says why not.
	 *
	 * @return JSONResponse `{available}`, or 401 without a session.
	 *
	 * @spec openspec/changes/publication-plain-language-and-translation/specs/publications/spec.md#requirement-a-drafted-plain-summary-is-a-suggestion-an-officer-accepts-req-ppl-002
	 */
	#[NoAdminRequired]
	public function assistant(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(data: ['error' => 'not-logged-in'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse(['available' => $this->drafter->isAvailable()]);

	}//end assistant()

	/**
	 * Ask the text assistant for a draft.
	 *
	 * @param string $id The publication id.
	 *
	 * @return JSONResponse `{taskId}` (202), or the refusal: 409 `no-provider` without a text assistant.
	 *
	 * @spec openspec/changes/publication-plain-language-and-translation/specs/publications/spec.md#requirement-a-drafted-plain-summary-is-a-suggestion-an-officer-accepts-req-ppl-002
	 */
	#[NoAdminRequired]
	public function draft(string $id): JSONResponse {
		try {
			return new JSONResponse(data: $this->drafter->draft(publicationId: $id), statusCode: Http::STATUS_ACCEPTED);
		} catch (PlainSummaryRefusal $refusal) {
			return $this->refused(refusal: $refusal);
		}

	}//end draft()

	/**
	 * The state of a draft.
	 *
	 * @param string $id The publication id.
	 * @param string $taskId The draft task id.
	 *
	 * @return JSONResponse `{status, text?, error?}`, or the refusal.
	 *
	 * @spec openspec/changes/publication-plain-language-and-translation/specs/publications/spec.md#requirement-a-drafted-plain-summary-is-a-suggestion-an-officer-accepts-req-ppl-002
	 */
	#[NoAdminRequired]
	public function poll(string $id, string $taskId): JSONResponse {
		if (ctype_digit($taskId) === false) {
			return new JSONResponse(data: ['error' => 'task-not-found'], statusCode: Http::STATUS_NOT_FOUND);
		}

		try {
			return new JSONResponse($this->drafter->poll(publicationId: $id, taskId: (int)$taskId));
		} catch (PlainSummaryRefusal $refusal) {
			return $this->refused(refusal: $refusal);
		}

	}//end poll()

	/**
	 * Store the text the officer accepted, with its level.
	 *
	 * @param string $id The publication id.
	 *
	 * @return JSONResponse The stored plain summary, level and provenance, or the refusal.
	 *
	 * @spec openspec/changes/publication-plain-language-and-translation/specs/publications/spec.md#requirement-a-drafted-plain-summary-is-a-suggestion-an-officer-accepts-req-ppl-002
	 */
	#[NoAdminRequired]
	public function accept(string $id): JSONResponse {
		$text = $this->request->getParam('text', '');
		$level = $this->request->getParam('level', '');
		$fromTaskId = $this->request->getParam('fromTaskId');
		if (is_string($text) === false || is_string($level) === false) {
			return new JSONResponse(data: ['error' => 'invalid-text'], statusCode: Http::STATUS_BAD_REQUEST);
		}

		$taskId = null;
		if (is_int($fromTaskId) === true || (is_string($fromTaskId) === true && ctype_digit($fromTaskId) === true)) {
			$taskId = (int)$fromTaskId;
		}

		try {
			return new JSONResponse(
				$this->drafter->accept(publicationId: $id, text: $text, level: $level, fromTaskId: $taskId)
			);
		} catch (PlainSummaryRefusal $refusal) {
			return $this->refused(refusal: $refusal);
		}

	}//end accept()

	/**
	 * The answer for a refusal.
	 *
	 * @param PlainSummaryRefusal $refusal The refusal.
	 *
	 * @return JSONResponse The reason and, when there is one, the text the officer reads.
	 */
	private function refused(PlainSummaryRefusal $refusal): JSONResponse {
		$data = ['error' => $refusal->reason];
		if ($refusal->getMessage() !== '') {
			$data['message'] = $refusal->getMessage();
		}

		return new JSONResponse(data: $data, statusCode: $refusal->status);

	}//end refused()
}//end class
