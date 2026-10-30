<?php

/**
 * Stub for OCA\OpenRegister\Db\Register.
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
 * Minimal stub for Register entity.
 *
 * Extends Entity like the real class, so `getId()`/`setId()` and the other
 * accessors resolve magically here exactly as they do against OpenRegister.
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method string|null getSlug()
 * @method void setSlug(?string $slug)
 * @method string|null getTitle()
 * @method void setTitle(?string $title)
 */
class Register extends \OCP\AppFramework\Db\Entity implements \JsonSerializable {

	/**
	 * @var string|null
	 */
	protected ?string $uuid = null;

	/**
	 * @var string|null
	 */
	protected ?string $slug = null;

	/**
	 * @var string|null
	 */
	protected ?string $title = null;

	/**
	 * @var array<int, int|string>|null
	 */
	protected ?array $schemas = [];

	/**
	 * The ids of the schemas this register lists.
	 *
	 * @return array<int, int|string>
	 */
	public function getSchemas(): array {
		return ($this->schemas ?? []);
	}//end getSchemas()

	/**
	 * Set the schema ids, from an array or a JSON string.
	 *
	 * @param array<int, int|string>|string $schemas The schema ids.
	 *
	 * @return static
	 */
	public function setSchemas($schemas): static {
		if (is_string($schemas) === true) {
			$schemas = (json_decode(json: $schemas, associative: true) ?? []);
		}

		$this->schemas = $schemas;
		$this->markFieldUpdated(attribute: 'schemas');

		return $this;
	}//end setSchemas()

	/**
	 * @return array<string,mixed>
	 */
	public function jsonSerialize(): array {
		return [];
	}//end jsonSerialize()

}//end class
