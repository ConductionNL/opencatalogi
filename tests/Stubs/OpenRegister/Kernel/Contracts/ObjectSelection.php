<?php

/**
 * Stub for OCA\OpenRegister\Kernel\Contracts\ObjectSelection.
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
 * Mirrors the real value object: which objects a search is narrowed to.
 */
final class ObjectSelection {

	/**
	 * Constructor.
	 *
	 * @param array|null $ids The object ids, or null for any.
	 * @param string|null $uses Only objects that use this one.
	 */
	public function __construct(
		public readonly ?array $ids = null,
		public readonly ?string $uses = null,
	) {
	}//end __construct()
}//end class
