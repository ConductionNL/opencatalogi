<?php

/**
 * A changed-since parameter that cannot be read.
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
 * @spec openspec/changes/oai-pmh-endpoint/specs/oai-pmh-endpoint/spec.md#requirement-a-re-user-asks-what-changed-since-a-moment-oai-006
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Exception;

use InvalidArgumentException;

/**
 * Thrown when `since` is missing or not ISO 8601, or a cursor is not one this app issued.
 *
 * @spec openspec/changes/oai-pmh-endpoint/specs/oai-pmh-endpoint/spec.md#requirement-a-re-user-asks-what-changed-since-a-moment-oai-006
 */
class MalformedChangesParameterException extends InvalidArgumentException {
	/**
	 * Constructor.
	 *
	 * @param string $parameter The parameter the caller got wrong: `since` or `cursor`.
	 */
	public function __construct(
		private readonly string $parameter,
	) {
		parent::__construct(message: $parameter . ' cannot be read');

	}//end __construct()

	/**
	 * The parameter the caller got wrong.
	 *
	 * @return string The parameter name.
	 *
	 * @spec openspec/changes/oai-pmh-endpoint/specs/oai-pmh-endpoint/spec.md#requirement-a-re-user-asks-what-changed-since-a-moment-oai-006
	 */
	public function getParameter(): string {
		return $this->parameter;
	}//end getParameter()
}//end class
