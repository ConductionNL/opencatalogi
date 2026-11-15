<?php

/**
 * Stub of OpenRegister's file publication window, for unit tests without OpenRegister.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Tests
 * @package  OCA\OpenCatalogi\Tests
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Db;

use DateTime;

/**
 * When a file becomes public and when it stops.
 */
final class PublicationWindow {

	/**
	 * Constructor.
	 *
	 * @param DateTime|null $published   When the file becomes public.
	 * @param DateTime|null $depublished When it stops.
	 */
	public function __construct(
		public readonly ?DateTime $published,
		public readonly ?DateTime $depublished,
	) {
	}//end __construct()
}//end class
