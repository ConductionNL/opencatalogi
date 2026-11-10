<?php

/**
 * Stub for OCA\OpenRegister\Service\Object\ObjectSaveOptions.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Stubs
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

/**
 * Mirrors the real value object: where and how an object is saved.
 */
final class ObjectSaveOptions {

	/**
	 * Constructor.
	 *
	 * @param ObjectFindTarget $target The register and schema.
	 * @param string|null $uuid The object's uuid, for an update.
	 * @param array|null $extend The extensions.
	 * @param array|null $uploadedFiles Files uploaded with the save.
	 * @param SavePolicy $policy How the save is checked and announced.
	 * @param SaveActor $actor Who saves, and who owns.
	 */
	public function __construct(
		public readonly ObjectFindTarget $target = new ObjectFindTarget(),
		public readonly ?string $uuid = null,
		public readonly ?array $extend = [],
		public readonly ?array $uploadedFiles = null,
		public readonly SavePolicy $policy = new SavePolicy(),
		public readonly SaveActor $actor = new SaveActor(),
	) {
	}//end __construct()
}//end class
