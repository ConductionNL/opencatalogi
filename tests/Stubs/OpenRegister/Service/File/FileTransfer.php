<?php

/**
 * Stub for OCA\OpenRegister\Service\File\FileTransfer.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Stubs
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Mirrors the real value object: the object a file is attached to, the file, and the object it goes to.
 */
final readonly class FileTransfer {
	/**
	 * Constructor.
	 *
	 * @param ObjectEntity $source The object the file is attached to.
	 * @param integer $fileId The file id.
	 * @param ObjectEntity $target The object the file goes to.
	 */
	public function __construct(
		public ObjectEntity $source,
		public int $fileId,
		public ObjectEntity $target,
	) {
	}//end __construct()
}//end class
