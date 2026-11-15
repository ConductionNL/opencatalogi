<?php

/**
 * Stub of OpenRegister's ImportCommit for the bare unit environment.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Import;

use OCP\IUser;

/**
 * The file hash and the actor of an import commit.
 */
final class ImportCommit {

	/**
	 * Constructor.
	 *
	 * @param string|null $sourceHash The sha256 of the committed file.
	 * @param IUser|null $currentUser The actor.
	 */
	public function __construct(
		public readonly ?string $sourceHash,
		public readonly ?IUser $currentUser = null,
	) {
	}//end __construct()
}//end class
