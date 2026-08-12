<?php

/**
 * Stub for OCA\OpenRegister\Db\ObjectEntity.
 *
 * Used in the bare php:8.3-cli unit-test environment where the real OpenRegister
 * app is not installed. PHPUnit createMock / getMockBuilder needs the class to
 * exist so it can build a type-safe mock; the methods here are left unimplemented.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Stubs
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * Minimal stub for ObjectEntity — only the surface used by unit tests.
 *
 * Extends OCP\AppFramework\Db\Entity so that production code doing
 * instanceof checks resolves correctly in bare php:8.3-cli CI containers.
 * The __call magic is overridden to bypass Entity's strict property_exists
 * guard (the real ObjectEntity stores data in a JSON blob, not declared
 * PHP properties, so it overrides __call in the same way).
 */
class ObjectEntity extends \OCP\AppFramework\Db\Entity implements \JsonSerializable {

	/**
	 * Internal property bag for magic setter/getter/hasser calls.
	 * Mirrors how the real ObjectEntity stores its dynamic payload.
	 *
	 * @var array<string,mixed>
	 */
	private array $_props = [];

	/**
	 * Magic method router — overrides OCP\AppFramework\Db\Entity::__call so
	 * arbitrary setter/getter/hasser calls do not throw BadFunctionCallException.
	 *
	 * @param string $name Method name.
	 * @param array<mixed> $arguments Arguments.
	 *
	 * @return mixed
	 */
	public function __call(string $name, array $arguments): mixed {
		if (str_starts_with($name, 'set') === true && count($arguments) >= 1) {
			$prop = lcfirst(substr($name, 3));
			$this->_props[$prop] = $arguments[0];
			return null;
		}

		if (str_starts_with($name, 'get') === true) {
			$prop = lcfirst(substr($name, 3));
			return $this->_props[$prop] ?? null;
		}

		if (str_starts_with($name, 'has') === true) {
			$prop = lcfirst(substr($name, 3));
			return isset($this->_props[$prop]) === true;
		}

		return null;
	}//end __call()

	/**
	 * Stub implementation of jsonSerialize — a REAL method on the real class.
	 *
	 * Mirrors the real ObjectEntity::jsonSerialize() where it matters: the `@self`
	 * envelope is REBUILT from the entity's own properties and any `@self` key the
	 * stored object payload happens to carry is overwritten, and the uuid is
	 * surfaced as a top-level `id`. Keeping this faithful matters — an earlier
	 * version returned the payload verbatim, which let a test hand-craft an `@self`
	 * that only ever survived under the stub and vanished against the real class.
	 *
	 * @return array<string,mixed>
	 */
	public function jsonSerialize(): array {
		// Base is the stored object data (set via setObject or __call).
		$object = $this->_props['object'] ?? [];

		// '@self' is entity metadata, rebuilt from the entity's own properties —
		// never carried through from the object payload (real class, getObjectArray()).
		$uuid = ($this->_props['uuid'] ?? null);
		$object['@self'] = [
			'id' => $uuid,
			'name' => ($this->_props['name'] ?? $uuid),
			'register' => ($this->_props['register'] ?? null),
			'schema' => ($this->_props['schema'] ?? null),
		];

		// Surface the uuid as a top-level 'id' key, matching real ObjectEntity.
		if ($uuid !== null) {
			$object['id'] = $uuid;
		}

		return $object;
	}//end jsonSerialize()

	/*
	 * NOTE: getId() / getUuid() / getRegister() / getSchema() are deliberately NOT
	 * declared here. The real OCA\OpenRegister\Db\ObjectEntity declares none of them
	 * either — they are served by OCP\AppFramework\Db\Entity::__call (routed through
	 * this stub's own __call above). Declaring them made the stub's method surface
	 * diverge from the real class's, which silently changed how PHPUnit builds a
	 * double: onlyMethods() succeeded here and threw CannotUseOnlyMethodsException in
	 * CI, and addMethods() would do the reverse. Keep the magic surface magic.
	 */

	/**
	 * Stub for getObject — a REAL method on the real class.
	 *
	 * Mirrors the real implementation, which injects the uuid as `id` ahead of the
	 * stored payload.
	 *
	 * @return array<string,mixed>
	 */
	public function getObject(): array {
		return array_merge(['id' => ($this->_props['uuid'] ?? null)], ($this->_props['object'] ?? []));
	}//end getObject()

}//end class
