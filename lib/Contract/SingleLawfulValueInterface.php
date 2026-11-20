<?php

/**
 * Contract of SingleLawfulValue, for callers in other components.
 *
 * @category Contract
 * @package  OCA\OpenCatalogi\Contract
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-a-field-with-one-lawful-value-for-this-officer-fills-itself-req-wms-004
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Contract;

use OCP\IUser;

/**
 * Contract of SingleLawfulValue, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 *
 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-a-field-with-one-lawful-value-for-this-officer-fills-itself-req-wms-004
 */
interface SingleLawfulValueInterface {
	/**
	 * Fill each empty field that has only one value the officer may choose, and record each fill.
	 *
	 * @param array<string, mixed> $publication The publication as it would be written.
	 * @param IUser                $officer     The officer creating it.
	 *
	 * @return array<string, mixed> The publication, with the fills and their `filledByRule` records.
	 *
	 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-a-field-with-one-lawful-value-for-this-officer-fills-itself-req-wms-004
	 */
	public function fill(array $publication, IUser $officer): array;
}//end interface
