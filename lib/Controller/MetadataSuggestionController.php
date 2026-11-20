<?php

/**
 * OpenCatalogi Metadata Suggestion Controller.
 *
 * The suggestions on a publication, asking Hermiq for more, and a person's
 * accept or reject. Every rule lives in MetadataSuggestionDecisions; this
 * class only maps its answers and refusals to HTTP.
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
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\Contract\MetadataSuggestionDecisionsInterface;
use OCA\OpenCatalogi\Exception\MetadataSuggestionRefusedException;
use OCA\OpenCatalogi\Contract\MetadataRuleSuggesterInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Metadata suggestions on a publication (woo-metadata-suggestions D3, D4).
 *
 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-nothing-is-written-until-a-person-accepts-req-wms-003
 */
class MetadataSuggestionController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The request.
	 * @param IL10N $l10n Localisation.
	 * @param IUserSession $userSession The current session.
	 * @param MetadataSuggestionDecisionsInterface $decisions The suggestions and the decisions on them.
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly IL10N $l10n,
		private readonly IUserSession $userSession,
		private readonly MetadataSuggestionDecisionsInterface $decisions,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * The suggestions on a publication the caller can read.
	 *
	 * @param string $id The publication id.
	 *
	 * @return JSONResponse `{suggestions, hermiq, canDecide}`, 401 without a session, 404 when the caller cannot read it.
	 *
	 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-hermiq-may-add-suggestions-never-replace-them-req-wms-002
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) answer() hands every step the user id; the overview does not need it.
	 */
	#[NoAdminRequired]
	public function index(string $id): JSONResponse {
		return $this->answer(step: fn (string $userId): array => $this->decisions->overview(publicationId: $id));
	}//end index()

	/**
	 * Ask for suggestions on a publication; `source=hermiq` is the only source asked for on request.
	 *
	 * @param string $id The publication id.
	 *
	 * @return JSONResponse `{suggestions}` the new ones, 400 for another source, 409 `hermiq-unavailable` without Hermiq.
	 *
	 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-hermiq-may-add-suggestions-never-replace-them-req-wms-002
	 */
	#[NoAdminRequired]
	public function create(string $id): JSONResponse {
		$source = (string)$this->request->getParam('source', '');
		if ($source !== MetadataRuleSuggesterInterface::SOURCE_HERMIQ) {
			return new JSONResponse(
				data: ['error' => 'unknown-source', 'message' => $this->l10n->t('Suggestions can only be asked of Hermiq.')],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		return $this->answer(
			step: fn (string $userId): array => ['suggestions' => $this->decisions->askHermiq(publicationId: $id, userId: $userId)]
		);
	}//end create()

	/**
	 * Accept a suggestion: its value is written to the publication.
	 *
	 * @param string $id The suggestion id.
	 *
	 * @return JSONResponse `{suggestion}`, 403 without the update right on its publication, 409 when already decided.
	 *
	 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-nothing-is-written-until-a-person-accepts-req-wms-003
	 */
	#[NoAdminRequired]
	public function accept(string $id): JSONResponse {
		return $this->answer(step: fn (string $userId): array => ['suggestion' => $this->decisions->accept(id: $id, userId: $userId)]);
	}//end accept()

	/**
	 * Reject a suggestion: the publication is left as it is.
	 *
	 * @param string $id The suggestion id.
	 *
	 * @return JSONResponse `{suggestion}`, 403 without the update right on its publication, 409 when already decided.
	 *
	 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-nothing-is-written-until-a-person-accepts-req-wms-003
	 */
	#[NoAdminRequired]
	public function reject(string $id): JSONResponse {
		return $this->answer(step: fn (string $userId): array => ['suggestion' => $this->decisions->reject(id: $id, userId: $userId)]);
	}//end reject()

	/**
	 * Run a step for the signed-in user and answer it, or answer its refusal.
	 *
	 * @param callable(string): array<string, mixed> $step The step, given the user id.
	 *
	 * @return JSONResponse The step's answer, 401 without a session, or the refusal's status.
	 */
	private function answer(callable $step): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'not-logged-in'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			return new JSONResponse($step($user->getUID()));
		} catch (MetadataSuggestionRefusedException $e) {
			return new JSONResponse(
				data: ['error' => $e->error(), 'message' => $this->message(error: $e->error())],
				statusCode: $e->status()
			);
		}
	}//end answer()

	/**
	 * The message an editor reads for a refusal.
	 *
	 * @param string $error The refusal's error.
	 *
	 * @return string The message.
	 */
	private function message(string $error): string {
		return match ($error) {
			'forbidden' => $this->l10n->t('You may not change this publication.'),
			'not-found' => $this->l10n->t('This suggestion or publication was not found.'),
			'already-decided' => $this->l10n->t('This suggestion has already been decided.'),
			'hermiq-unavailable' => $this->l10n->t('Hermiq is not available to make suggestions.'),
			'not-configured' => $this->l10n->t('Metadata suggestions are not set up on this instance.'),
			default => $this->l10n->t('The suggestion could not be saved.'),
		};
	}//end message()
}//end class
