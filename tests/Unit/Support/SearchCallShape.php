<?php

/**
 * Reads an ObjectService search call in the shape tests recorded before SearchOptions.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace Unit\Support;

use OCA\OpenRegister\Kernel\Contracts\ObjectSelection;
use OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef;
use OCA\OpenRegister\Kernel\Contracts\SearchOptions;

/**
 * searchObjects / searchObjectsPaginated now take `options: SearchOptions`,
 * countSearchObjects `selection: ObjectSelection` and searchObjectsBySlug a
 * `RegisterSchemaRef`. Recordings and doubles written against the old named
 * arguments read a call through here: a default value object disappears, a
 * non-default one is spread back into the old names (deleted, ids, uses,
 * views, _viewScopeRequired; register and schema for the slug search).
 */
final class SearchCallShape {

	/**
	 * The call's arguments with any search value object spread back into the old names.
	 *
	 * @param array<int|string, mixed> $args The arguments as the double received them.
	 *
	 * @return array<int|string, mixed> The arguments in the old shape.
	 */
	public static function named(array $args): array {
		$out = [];
		foreach ($args as $key => $value) {
			if ($value instanceof SearchOptions) {
				$out += self::spreadOptions(options: $value);
				continue;
			}

			if ($value instanceof ObjectSelection) {
				$out += self::spreadSelection(selection: $value);
				continue;
			}

			if ($value instanceof RegisterSchemaRef) {
				$out['register'] = $value->register;
				$out['schema'] = $value->schema;
				continue;
			}

			$out[$key] = $value;
		}

		return $out;
	}//end named()

	/**
	 * A SearchOptions' non-default values under the old parameter names.
	 *
	 * @param SearchOptions $options The options.
	 *
	 * @return array<string, mixed> The old named arguments.
	 */
	public static function spreadOptions(SearchOptions $options): array {
		$old = [];
		if ($options->deleted !== false) {
			$old['deleted'] = $options->deleted;
		}

		$old += self::spreadSelection(selection: $options->selection);
		if ($options->views !== null) {
			$old['views'] = $options->views;
		}

		if ($options->viewScopeRequired !== false) {
			$old['_viewScopeRequired'] = $options->viewScopeRequired;
		}

		return $old;
	}//end spreadOptions()

	/**
	 * An ObjectSelection's non-default values under the old parameter names.
	 *
	 * @param ObjectSelection $selection The selection.
	 *
	 * @return array<string, mixed> The old named arguments.
	 */
	public static function spreadSelection(ObjectSelection $selection): array {
		$old = [];
		if ($selection->ids !== null) {
			$old['ids'] = $selection->ids;
		}

		if ($selection->uses !== null) {
			$old['uses'] = $selection->uses;
		}

		return $old;
	}//end spreadSelection()
}//end class
