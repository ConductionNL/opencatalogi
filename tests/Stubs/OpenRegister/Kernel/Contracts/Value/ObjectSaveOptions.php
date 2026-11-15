<?php

/**
 * OpenRegister ObjectSaveOptions stub for tests and static analysis.
 *
 * Mirrors openregister/lib/Kernel/Contracts/Value/ObjectSaveOptions.php on
 * main (the options ObjectService::saveObject() takes), without the policy
 * and actor parameters no caller here passes. The real class ships in
 * openregister; this stub only exists for runs without it.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Kernel\Contracts\Value
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Value;

/**
 * Where and how ObjectService::saveObject() stores an object.
 */
final class ObjectSaveOptions {

	/**
	 * Constructor.
	 *
	 * @param ObjectFindTarget $target        The register and schema.
	 * @param string|null      $uuid          The object to update, null to create.
	 * @param array|null       $extend        Properties to extend in the result.
	 * @param array|null       $uploadedFiles Files uploaded with the object.
	 */
	public function __construct(
		public readonly ObjectFindTarget $target=new ObjectFindTarget(),
		public readonly ?string $uuid=null,
		public readonly ?array $extend=[],
		public readonly ?array $uploadedFiles=null,
	) {
	}//end __construct()
}//end class
