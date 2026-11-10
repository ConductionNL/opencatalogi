<?php

/**
 * Reads an ObjectService::find() call in the shape tests recorded before ObjectFindOptions.
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

use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;

/**
 * find() now takes `options: ObjectFindOptions` for register, schema, extend,
 * files, render and audit. Recordings and doubles written against the old
 * named arguments read a call through here, so a recording keeps its meaning
 * and only the call shape changes.
 */
final class FindCallShape {

	/**
	 * The call's named arguments with `options` spread back into the old names.
	 *
	 * Only values that differ from the default are written, as a caller of the
	 * old signature would have; they take the position `options` had.
	 *
	 * @param array<int|string, mixed> $args The arguments as the double received them.
	 *
	 * @return array<int|string, mixed> The arguments in the old shape.
	 */
	public static function named(array $args): array {
		$out = [];
		foreach ($args as $key => $value) {
			if (($key === 'options' || $key === 1) && ($value === null || $value instanceof ObjectFindOptions) === true) {
				$out += self::spread(options: $value);
				continue;
			}

			$out[$key] = $value;
		}

		return $out;
	}//end named()

	/**
	 * The options' non-default values under the old parameter names.
	 *
	 * @param ObjectFindOptions|null $options The options.
	 *
	 * @return array<string, mixed> The old named arguments.
	 */
	public static function spread(?ObjectFindOptions $options): array {
		$options ??= new ObjectFindOptions();
		$old = [];
		if ($options->extend !== []) {
			$old['_extend'] = $options->extend;
		}

		if ($options->files !== false) {
			$old['files'] = $options->files;
		}

		if ($options->register !== null) {
			$old['register'] = $options->register;
		}

		if ($options->schema !== null) {
			$old['schema'] = $options->schema;
		}

		if ($options->render !== true) {
			$old['_render'] = $options->render;
		}

		if ($options->audit !== true) {
			$old['_audit'] = $options->audit;
		}

		return $old;
	}//end spread()
}//end class
