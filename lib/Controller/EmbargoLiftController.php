<?php

/**
 * OpenCatalogi Embargo Lift Controller.
 *
 * `POST /api/publications/{id}/lift-embargo`: a user in a lift group publishes a
 * publication under embargo before its date, with a reason, and the lift is logged.
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
 * @spec openspec/changes/publication-schedule-guards/specs/publications/spec.md#requirement-lifting-an-embargo-takes-a-named-right-and-a-reason-and-is-logged-req-psg-002
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Controller;

use DateTimeImmutable;
use OCA\OpenCatalogi\Contract\AppIdentity;
use OCA\OpenCatalogi\Service\Publication\PublishRefusedException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Throwable;

/**
 * Lifts the embargo on one publication.
 *
 * @spec openspec/changes/publication-schedule-guards/specs/publications/spec.md#requirement-lifting-an-embargo-takes-a-named-right-and-a-reason-and-is-logged-req-psg-002
 */
class EmbargoLiftController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest     $request     The request.
	 * @param IUserSession $userSession The lifting user.
	 * @param EmbargoLifts $lifts       Finds, checks the right, publishes and logs.
	 *
	 * @spec exclude pure framework plumbing: constructor dependency injection.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly EmbargoLifts $lifts,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Lift the embargo: publish at the given moment or now, with a reason, and log it.
	 *
	 * Refuses, in this order and before anything is written: no session (401), a publication the
	 * caller cannot read (404), a caller outside the lift groups or without the update right (403),
	 * no reason or an unreadable `publicationDate` (422).
	 *
	 * @param string $id The publication id.
	 *
	 * @return JSONResponse `{publicationDate, previousPublicationDate}`; 500 `lift-not-logged` when the
	 *                      publication was published but the audit entry could not be written.
	 *
	 * @spec openspec/changes/publication-schedule-guards/tasks.md#task-2.1
	 */
	#[NoAdminRequired]
	public function lift(string $id): JSONResponse {
		$caller = $this->userSession->getUser();
		if ($caller === null) {
			return self::error(error: 'not-logged-in', status: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$publication = $this->lifts->find(id: $id);
		} catch (Throwable $e) {
			return self::error(error: 'register-unavailable', status: Http::STATUS_SERVICE_UNAVAILABLE);
		}

		if ($publication === null) {
			return self::error(error: 'not-found', status: Http::STATUS_NOT_FOUND);
		}

		if ($this->lifts->mayLift(publication: $publication, caller: $caller) === false) {
			return self::error(error: 'not-allowed-to-lift', status: Http::STATUS_FORBIDDEN);
		}

		return $this->liftWithReason(id: $id, publication: $publication, caller: $caller);
	}//end lift()

	/**
	 * Read the reason and the moment, then lift and log.
	 *
	 * @param string $id          The publication id.
	 * @param object $publication The publication.
	 * @param IUser  $caller      The lifting user.
	 *
	 * @return JSONResponse The outcome.
	 */
	private function liftWithReason(string $id, object $publication, IUser $caller): JSONResponse {
		$reason = trim((string)$this->request->getParam('reason', ''));
		if ($reason === '') {
			return self::error(error: 'reason-missing', status: Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		$moment = self::moment(value: $this->request->getParam('publicationDate'));
		if ($moment === null) {
			return self::error(error: 'invalid-date', status: Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		try {
			$lifted = $this->lifts->lift(id: $id, publication: $publication, moment: $moment);
		} catch (PublishRefusedException $e) {
			return new JSONResponse(data: ['error' => 'transition-not-allowed', 'state' => $e->state], statusCode: Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $e) {
			return self::error(error: 'not-saved', status: Http::STATUS_BAD_REQUEST);
		}

		$previous = ($this->lifts->properties(publication: $publication)['publicationDate'] ?? null);
		$answer = ['publicationDate' => $moment, 'previousPublicationDate' => $previous];
		try {
			$this->lifts->log(
				before: $publication,
				after: $lifted,
				context: ['user' => $caller->getUID(), 'reason' => $reason, 'previousPublicationDate' => $previous, 'publicationDate' => $moment]
			);
		} catch (Throwable $e) {
			return new JSONResponse(data: ['error' => 'lift-not-logged'] + $answer, statusCode: Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(data: $answer);
	}//end liftWithReason()

	/**
	 * The lifted moment: now when none is given, the given one as ATOM, or null when it does not read as a date.
	 *
	 * @param mixed $value The `publicationDate` parameter.
	 *
	 * @return string|null
	 */
	private static function moment(mixed $value): ?string {
		if ($value === null || (is_string($value) === true && trim($value) === '')) {
			return (new DateTimeImmutable())->format(DATE_ATOM);
		}

		if (is_string($value) === false) {
			return null;
		}

		try {
			return (new DateTimeImmutable($value))->format(DATE_ATOM);
		} catch (\Exception $e) {
			return null;
		}
	}//end moment()

	/**
	 * An error answer carrying only its code.
	 *
	 * @param string $error  The code.
	 * @param int    $status The HTTP status.
	 *
	 * @return JSONResponse
	 */
	private static function error(string $error, int $status): JSONResponse {
		return new JSONResponse(data: ['error' => $error], statusCode: $status);
	}//end error()
}//end class
