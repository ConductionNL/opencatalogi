<?php

/**
 * Contract of StandardsVersionService, for callers in other components.
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
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Contract;

/**
 * Contract of StandardsVersionService, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 */
interface StandardsVersionServiceInterface {
	/**
	 * Compare the declared DiWoo version with the published one.
	 *
	 * @return array{declared: string, published: string|null, status: string} The comparison.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md
	 */
	public function diwooVersion(): array;

	/**
	 * Compare the targeted DCAT-AP-NL profile version with the published one.
	 *
	 * @return array{declared: string, published: string|null, status: string, profile: string} The comparison.
	 *
	 * @spec openspec/specs/dcat-ap-harvest/spec.md
	 */
	public function dcatApNlVersion(): array;

	/**
	 * The DiWoo version the publisher currently calls current.
	 *
	 * @return string|null The published version, or null when it could not be read.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md
	 */
	public function publishedDiwooVersion(): ?string;

	/**
	 * The newest adopted DCAT-AP-NL version the publisher lists.
	 *
	 * @return string|null The published version, or null when it could not be read.
	 *
	 * @spec openspec/specs/dcat-ap-harvest/spec.md
	 */
	public function publishedDcatApNlVersion(): ?string;
}//end interface
