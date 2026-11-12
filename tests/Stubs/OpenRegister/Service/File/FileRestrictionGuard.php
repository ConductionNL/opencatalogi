<?php

/**
 * Stub for OCA\OpenRegister\Service\File\FileRestrictionGuard.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Stubs
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

/**
 * Minimal stub for FileRestrictionGuard used by PHPUnit mocks in bare CI.
 */
class FileRestrictionGuard {

	/**
	 * Keep only the nodes the caller may read, in their order.
	 *
	 * @param array<int|string, mixed> $nodes The nodes.
	 *
	 * @return array<int, mixed>
	 */
	public function readableNodes(array $nodes): array {
		return array_values($nodes);
	}//end readableNodes()

}//end class
