<?php

/**
 * OpenCatalogi Answers Portal Subjects.
 *
 * The one way the portal endpoint actions (hydra `woo-citizen-journey`, C1
 * and C2) learn who asks: portaliq's signed `X-Portal-Subject` assertion.
 * Without a valid one the answer is 401. The subject in it is the only owner
 * the services ever compare with, and their refusals map to 404 and 422.
 *
 * @category Controller
 * @package  OCA\OpenCatalogi\Controller
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @version GIT: <git_id>
 * @link https://www.OpenCatalogi.nl
 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-a-dossier-belongs-to-one-resident-and-answers-404-to-everyone-else-req-ccol-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\Exception\PortalInputException;
use OCA\OpenCatalogi\Exception\PortalNotFoundException;
use OCA\OpenCatalogi\Portal\PortalAssertionVerifier;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use Throwable;

/**
 * Runs a portal action as the asserted subject. The using controller has
 * `$request`, `$verifier` (PortalAssertionVerifier) and `$logger`.
 *
 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-a-dossier-belongs-to-one-resident-and-answers-404-to-everyone-else-req-ccol-001
 */
trait AnswersPortalSubjects {

	/**
	 * Run an action as the asserted subject and map its outcome to a status.
	 *
	 * @param callable $action The action, given the owner.
	 * @param int      $status The success status.
	 *
	 * @return JSONResponse 401 without a valid assertion, 404, 422, 500 or the success status.
	 */
	private function asSubject(callable $action, int $status=Http::STATUS_OK): JSONResponse {
		$owner = $this->verifier->subjectOf(header: $this->request->getHeader(PortalAssertionVerifier::HEADER));
		if ($owner === null) {
			return new JSONResponse(['error' => 'unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			return new JSONResponse($action($owner), $status);
		} catch (PortalNotFoundException) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		} catch (PortalInputException $e) {
			return new JSONResponse(['error' => 'invalid', 'message' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $e) {
			$this->logger->error('OpenCatalogi: portal action failed', ['reason' => $e->getMessage()]);
			return new JSONResponse(['error' => 'failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

	}//end asSubject()

	/**
	 * A request parameter as a string.
	 *
	 * @param string $name The parameter.
	 *
	 * @return string
	 */
	private function param(string $name): string {
		$value = $this->request->getParam($name, '');
		if (is_string($value) === true || is_int($value) === true) {
			return trim((string)$value);
		}

		return '';

	}//end param()
}//end trait
