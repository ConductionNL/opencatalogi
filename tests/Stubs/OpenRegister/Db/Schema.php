<?php

/**
 * Stub for OCA\OpenRegister\Db\Schema.
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
 * Minimal stub for Schema entity.
 *
 * Extends Entity like the real class, so `getId()`/`setId()` resolve magically
 * here exactly as they do against OpenRegister.
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method string|null getSlug()
 * @method void setSlug(?string $slug)
 * @method string|null getTitle()
 * @method void setTitle(?string $title)
 */
class Schema extends \OCP\AppFramework\Db\Entity implements \JsonSerializable {

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
	 * @return array<string,mixed>
	 */
	public function jsonSerialize(): array {
		return [];
	}//end jsonSerialize()

}//end class
