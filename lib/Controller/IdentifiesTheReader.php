<?php

/**
 * OpenCatalogi reader-token trait.
 *
 * One copy of the token that stands for a reader across a single vote or a
 * single verdict. Nothing here is stored: the consuming service hashes the
 * token with a salt, so the value only has to be stable for the length of one
 * request.
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

/**
 * The token that identifies a reader for the length of one action.
 *
 * Consuming controllers MUST expose a `private readonly IUserSession $userSession`
 * constructor-promoted property, and inherit `$request` from
 * `OCP\AppFramework\Controller`.
 */
trait IdentifiesTheReader {
	/**
	 * The token that identifies a reader for the length of one action.
	 *
	 * A signed-in reader is their user id; an anonymous one is the token they
	 * sent, or failing that their address. Neither is stored: the service
	 * hashes it with a salt.
	 *
	 * @return string The token.
	 *
	 * @spec exclude request-scoped identity helper with no requirement of its own.
	 */
	private function readerToken(): string {
		$user = $this->userSession->getUser();
		if ($user !== null) {
			return 'user:' . $user->getUID();
		}

		$sessionToken = (string)$this->request->getParam('readerToken', '');
		if ($sessionToken !== '') {
			return 'token:' . $sessionToken;
		}

		return 'address:' . (string)$this->request->getRemoteAddress();

	}//end readerToken()
}//end trait
