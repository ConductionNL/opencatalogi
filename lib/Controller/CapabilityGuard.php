<?php

/**
 * OpenCatalogi capability guard: answers 403 naming the right a caller lacks.
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
 * @spec openspec/changes/woo-access-boundary-hardening/specs/woo-compliance/spec.md#requirement-capabilities-are-rights-granted-per-group-req-wab-004
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Controller;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IUserSession;

/**
 * The first statement of every gated controller method (REQ-WAB-004).
 *
 * The using controller declares `?CapabilityRights $capabilities` and
 * `?IUserSession $userSession`. Without either, the guard refuses.
 */
trait CapabilityGuard {

	/**
	 * Null when the current user holds the right, else a 403 naming it.
	 *
	 * @param string $verb The right, one of CapabilityRights::VERBS.
	 *
	 * @return JSONResponse|null The refusal, or null to go on.
	 *
	 * @spec openspec/changes/woo-access-boundary-hardening/specs/woo-compliance/spec.md#requirement-capabilities-are-rights-granted-per-group-req-wab-004
	 */
	private function capabilityRefusal(string $verb): ?JSONResponse {
		$userId = null;
		if ($this->userSession instanceof IUserSession) {
			$userId = $this->userSession->getUser()?->getUID();
		}

		if ($this->capabilities !== null && $this->capabilities->may(verb: $verb, userId: $userId) === true) {
			return null;
		}

		return new JSONResponse(
			[
				'message' => 'This needs the right '.$verb.', which you do not have.',
				'right'   => $verb,
			],
			Http::STATUS_FORBIDDEN
		);

	}//end capabilityRefusal()
}//end trait
