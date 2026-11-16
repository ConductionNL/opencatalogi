<?php

/**
 * Stub of OpenRegister's ObjectSaveOptions for unit runs without OpenRegister.
 *
 * Mirrors the constructor of OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions on main.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Value;

/**
 * The options of one object save.
 */
final class ObjectSaveOptions {

	/**
	 * Build the value.
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
