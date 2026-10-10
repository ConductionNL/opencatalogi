<?php

/**
 * dossiq's refusal grounds list, on its documented signature, for when dossiq is not autoloadable.
 *
 * Copied from dossiq `lib/Woo/WooRefusalGrounds.php` (byCode, line 148) and
 * `lib/Woo/WooRefusalGroundsUnavailable.php` on dossiq development, 10 Oct 2026.
 * Declared only when the real classes are absent, so a run with dossiq on the
 * autoloader tests against the real ones.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Service\Woo\Fixtures
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Woo;

if (class_exists(WooRefusalGroundsUnavailable::class) === false) {
	/**
	 * dossiq: the list cannot be read.
	 */
	class WooRefusalGroundsUnavailable extends \RuntimeException {
	}
}

if (class_exists(WooRefusalGrounds::class) === false) {
	/**
	 * dossiq's list, reduced to the one method opencatalogi calls.
	 */
	class WooRefusalGrounds {

		/**
		 * One ground by its code, retired or not.
		 *
		 * @param string $code The code.
		 *
		 * @return array<string, mixed>|null The ground, or null.
		 */
		public function byCode(string $code): ?array {
			return null;
		}
	}
}
