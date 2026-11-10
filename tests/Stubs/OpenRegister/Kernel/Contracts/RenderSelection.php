<?php

/**
 * Stub for OCA\OpenRegister\Kernel\Contracts\RenderSelection.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Stubs
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * Mirrors the real value object: which relations to extend and which fields to filter, keep or drop.
 */
final class RenderSelection {

	/**
	 * Constructor.
	 *
	 * @param array|string|null $extend The relations to extend.
	 * @param array|null $filter The filter.
	 * @param array|null $fields The fields to keep.
	 * @param array|null $unset The fields to drop.
	 */
	public function __construct(
		public readonly array|string|null $extend,
		public readonly ?array $filter,
		public readonly ?array $fields,
		public readonly ?array $unset,
	) {
	}//end __construct()
}//end class
