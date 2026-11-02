<?php

/**
 * One page of an OOAPI resource collection.
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
 * @spec openspec/specs/ooapi-catalog-publication/spec.md#requirement-per-catalog-ooapi-5-0-resource-endpoints-ooapi-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Contract;

/**
 * The page number and page size asked for, and any extra equality filter.
 *
 * Unclamped: the service caps the size and floors both at one.
 *
 * @spec openspec/specs/ooapi-catalog-publication/spec.md#requirement-ooapi-5-0-pagination-ooapi-007
 */
final class OoapiPage {

	/**
	 * Constructor.
	 *
	 * @param int $number The 1-based page number.
	 * @param int $size The requested page size.
	 * @param array<string, mixed> $filter Extra equality filters, such as `['courseId' => '...']`.
	 */
	public function __construct(
		public readonly int $number,
		public readonly int $size,
		public readonly array $filter = [],
	) {

	}//end __construct()
}//end class
