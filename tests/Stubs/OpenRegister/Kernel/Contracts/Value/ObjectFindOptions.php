<?php

/**
 * Stub of OpenRegister's ObjectFindOptions for the bare CI unit run.
 *
 * Mirrors openregister main: how ObjectService::find() looks an object up.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Value;

/**
 * The options of one object lookup.
 */
final class ObjectFindOptions {

	/**
	 * Build the options.
	 *
	 * @param mixed      $register The register entity, id, uuid or slug.
	 * @param mixed      $schema   The schema entity, id, uuid or slug.
	 * @param array|null $extend   Properties to extend.
	 * @param bool       $files    Whether to include files.
	 * @param bool       $render   Whether to render the object.
	 * @param bool       $audit    Whether to write a read audit row.
	 */
	public function __construct(
		public readonly mixed $register = null,
		public readonly mixed $schema = null,
		public readonly ?array $extend = [],
		public readonly bool $files = false,
		public readonly bool $render = true,
		public readonly bool $audit = true,
	) {
	}//end __construct()
}//end class
