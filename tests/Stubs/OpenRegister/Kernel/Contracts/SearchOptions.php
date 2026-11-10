<?php

/**
 * Stub for OCA\OpenRegister\Kernel\Contracts\SearchOptions.
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
 * Mirrors the real value object: the selection, views and flags of a search.
 */
final class SearchOptions {

	/**
	 * Constructor.
	 *
	 * @param ObjectSelection $selection The ids and uses narrowing.
	 * @param array|null $views The views to search.
	 * @param boolean $viewScopeRequired Require a resolvable view.
	 * @param boolean $deleted Echo the search as one over deleted objects.
	 */
	public function __construct(
		public readonly ObjectSelection $selection = new ObjectSelection(),
		public readonly ?array $views = null,
		public readonly bool $viewScopeRequired = false,
		public readonly bool $deleted = false,
	) {
	}//end __construct()
}//end class
