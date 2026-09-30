<?php

/**
 * A public search parameter that cannot be searched on.
 *
 * @category Exception
 * @package  OCA\OpenCatalogi\Exception
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
 * @spec openspec/specs/search/spec.md#requirement-the-public-search-filters-council-documents-by-meeting-date-range-req-scf-002
 */

namespace OCA\OpenCatalogi\Exception;

use InvalidArgumentException;

/**
 * Thrown when a range bound of the public search is neither a date nor a number.
 *
 * @spec openspec/specs/search/spec.md#requirement-the-public-search-filters-council-documents-by-meeting-date-range-req-scf-002
 */
class MalformedSearchParameterException extends InvalidArgumentException {
	/**
	 * Constructor.
	 *
	 * @param string $parameter The parameter as the caller wrote it, e.g. meetingDate[gte].
	 */
	public function __construct(private readonly string $parameter) {
		parent::__construct(message: $parameter . ' is not a date or a number');

	}//end __construct()

	/**
	 * The parameter as the caller wrote it.
	 *
	 * @return string The parameter, e.g. meetingDate[gte].
	 *
	 * @spec openspec/specs/search/spec.md#requirement-the-public-search-filters-council-documents-by-meeting-date-range-req-scf-002
	 */
	public function getParameter(): string {
		return $this->parameter;

	}//end getParameter()
}//end class
