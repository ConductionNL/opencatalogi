<?php

/**
 * OpenCatalogi Handover Middleware.
 *
 * The Woo request routes once dossiq handles the requests: without dossiq they
 * do not exist here (404), and once every stored request moved to dossiq a
 * change to one is gone from here (410). One middleware rather than a check
 * per route, so a route added to the controller is covered by default.
 *
 * @category Middleware
 * @package  OCA\OpenCatalogi\Middleware
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @version GIT: <git_id>
 * @link https://www.OpenCatalogi.nl
 * @spec openspec/changes/woo-request-intake-hands-over-to-dossiq/specs/woo-request-intake/spec.md#requirement-without-dossiq-opencatalogi-takes-no-woo-requests-req-whd-005
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Middleware;

use OCA\OpenCatalogi\Contract\HandedOverRoutes;
use OCA\OpenCatalogi\Contract\HandoverStateInterface;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\IL10N;
use RuntimeException;

/**
 * Answers the Woo request routes 404 without dossiq, and the change routes 410 once the requests are read-only here.
 *
 * @spec openspec/changes/woo-request-intake-hands-over-to-dossiq/specs/woo-request-intake/spec.md#requirement-with-nothing-left-to-migrate-the-request-schema-is-read-only-req-whd-004
 */
class HandoverMiddleware extends Middleware {

	/**
	 * The marker thrown when dossiq is not installed.
	 */
	private const NO_DOSSIQ = 'opencatalogi-woo-requests-without-dossiq';

	/**
	 * The marker thrown for a change once the requests are read-only.
	 */
	private const READ_ONLY = 'opencatalogi-woo-requests-read-only';

	/**
	 * Constructor.
	 *
	 * @param HandoverStateInterface $state Whether dossiq is installed and the requests read-only.
	 * @param IL10N $l10n Translates the answers.
	 */
	public function __construct(
		private readonly HandoverStateInterface $state,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * Stop a Woo request route that no longer serves here.
	 *
	 * @param object $controller The controller.
	 * @param string $methodName The method.
	 *
	 * @return void
	 *
	 * @throws RuntimeException A marker, answered by afterException().
	 *
	 * @spec openspec/changes/woo-request-intake-hands-over-to-dossiq/specs/woo-request-intake/spec.md#requirement-without-dossiq-opencatalogi-takes-no-woo-requests-req-whd-005
	 */
	public function beforeController($controller, $methodName): void {
		if (($controller instanceof HandedOverRoutes) === false) {
			return;
		}

		if ($this->state->dossiqInstalled() === false) {
			throw new RuntimeException(self::NO_DOSSIQ);
		}

		if (in_array((string)$methodName, $controller::WRITE_ROUTES, true) === true && $this->state->isReadOnly() === true) {
			throw new RuntimeException(self::READ_ONLY);
		}
	}//end beforeController()

	/**
	 * Answer the markers; rethrow anything else.
	 *
	 * @param object $controller The controller.
	 * @param string $methodName The method.
	 * @param \Exception $exception The exception.
	 *
	 * @return Response
	 *
	 * @throws \Exception Anything that is not one of the markers.
	 *
	 * @spec openspec/changes/woo-request-intake-hands-over-to-dossiq/specs/woo-request-intake/spec.md#requirement-with-nothing-left-to-migrate-the-request-schema-is-read-only-req-whd-004
	 */
	public function afterException($controller, $methodName, \Exception $exception): Response {
		if (($exception instanceof RuntimeException) === false) {
			throw $exception;
		}

		$message = $exception->getMessage();
		if ($message === self::NO_DOSSIQ) {
			return new JSONResponse(
				['error' => 'dossiq-not-installed', 'message' => $this->l10n->t('Woo requests are handled by dossiq, which is not installed.')],
				Http::STATUS_NOT_FOUND
			);
		}

		if ($message === self::READ_ONLY) {
			return new JSONResponse(
				['error' => 'handled-by-dossiq', 'message' => $this->l10n->t('This Woo request has moved to dossiq and can no longer be changed here.')],
				Http::STATUS_GONE
			);
		}

		throw $exception;
	}//end afterException()
}//end class
