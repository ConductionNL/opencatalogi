<?php

/**
 * Reads an ObjectService::saveObject() call in the shape tests recorded before ObjectSaveOptions.
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

use OCA\OpenRegister\Service\Object\ObjectSaveOptions;

/**
 * saveObject() now takes `options: ObjectSaveOptions` for register, schema,
 * uuid, extend, uploaded files, the save policy and the actor. Recordings and
 * doubles written against the old named arguments read a call through here.
 */
final class SaveCallShape {

	/**
	 * The call's arguments with `options` spread back into the old names, at the place it had.
	 *
	 * @param array<int|string, mixed> $args The arguments as the double received them.
	 *
	 * @return array<int|string, mixed> The arguments in the old shape.
	 */
	public static function named(array $args): array {
		$out = [];
		foreach ($args as $key => $value) {
			if ($value instanceof ObjectSaveOptions) {
				$out += self::spread(options: $value);
				continue;
			}

			$out[$key] = $value;
		}

		return $out;
	}//end named()

	/**
	 * Like named(), then sorted into the old saveObject() parameter order.
	 *
	 * named() keeps the spread where `options` was, ahead of `_rbac` and
	 * `_multitenancy`; the old signature had those between `uuid` and `silent`.
	 * Keys that are not old saveObject() parameters keep their order, at the end.
	 *
	 * @param array<int|string, mixed> $args The arguments as the double received them.
	 *
	 * @return array<int|string, mixed> The arguments in the old shape and order.
	 */
	public static function namedInOldOrder(array $args): array {
		$named = self::named(args: $args);
		$order = [
			'object', 'extend', 'register', 'schema', 'uuid', '_rbac', '_multitenancy', 'silent',
			'_validation', 'uploadedFiles', 'currentUser', 'failIfExists', '_unowned', '_dedupOverride', '_owner',
		];

		$out = [];
		foreach ($order as $name) {
			if (array_key_exists($name, $named) === true) {
				$out[$name] = $named[$name];
				unset($named[$name]);
			}
		}

		return $out + $named;
	}//end namedInOldOrder()

	/**
	 * The options' non-default values under the old parameter names, in the old parameter order.
	 *
	 * @param ObjectSaveOptions $options The options.
	 *
	 * @return array<string, mixed> The old named arguments.
	 */
	public static function spread(ObjectSaveOptions $options): array {
		$old = [
			'extend' => [$options->extend, []],
			'register' => [$options->target->register, null],
			'schema' => [$options->target->schema, null],
			'uuid' => [$options->uuid, null],
			'silent' => [$options->policy->silent, false],
			'_validation' => [$options->policy->validation, true],
			'uploadedFiles' => [$options->uploadedFiles, null],
			'currentUser' => [$options->actor->currentUser, null],
			'failIfExists' => [$options->policy->failIfExists, false],
			'_unowned' => [$options->actor->unowned, false],
			'_dedupOverride' => [$options->policy->dedupOverride, false],
			'_owner' => [$options->actor->owner, null],
		];

		$spread = [];
		foreach ($old as $name => [$value, $default]) {
			if ($value !== $default) {
				$spread[$name] = $value;
			}
		}

		return $spread;
	}//end spread()

	/**
	 * The old fifteen-slot positional argument list, for doubles that recorded it whole.
	 *
	 * @param mixed $object The object.
	 * @param ObjectSaveOptions $options The options.
	 * @param boolean $rbac The RBAC flag.
	 * @param boolean $multitenancy The multitenancy flag.
	 *
	 * @return list<mixed> object, extend, register, schema, uuid, _rbac, _multitenancy, silent,
	 *                     _validation, uploadedFiles, currentUser, failIfExists, _unowned, _dedupOverride, _owner.
	 */
	public static function positional(mixed $object, ObjectSaveOptions $options, bool $rbac = true, bool $multitenancy = true): array {
		return [
			$object,
			$options->extend,
			$options->target->register,
			$options->target->schema,
			$options->uuid,
			$rbac,
			$multitenancy,
			$options->policy->silent,
			$options->policy->validation,
			$options->uploadedFiles,
			$options->actor->currentUser,
			$options->policy->failIfExists,
			$options->actor->unowned,
			$options->policy->dedupOverride,
			$options->actor->owner,
		];
	}//end positional()
}//end class
