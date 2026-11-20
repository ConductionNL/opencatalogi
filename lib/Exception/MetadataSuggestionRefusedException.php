<?php

/**
 * Exception for a refused metadata suggestion request.
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
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Exception;

use RuntimeException;

/**
 * A metadata suggestion request that is refused: it names the error and the HTTP status to answer with.
 *
 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-nothing-is-written-until-a-person-accepts-req-wms-003
 */
class MetadataSuggestionRefusedException extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string $error The machine-readable error, such as `forbidden` or `hermiq-unavailable`.
	 * @param int $status The HTTP status to answer with.
	 */
	public function __construct(
		private readonly string $error,
		private readonly int $status,
	) {
		parent::__construct(message: $error, code: $status);

	}//end __construct()

	/**
	 * The machine-readable error.
	 *
	 * @return string The error.
	 *
	 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-nothing-is-written-until-a-person-accepts-req-wms-003
	 */
	public function error(): string {
		return $this->error;
	}//end error()

	/**
	 * The HTTP status to answer with.
	 *
	 * @return int The status.
	 *
	 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-nothing-is-written-until-a-person-accepts-req-wms-003
	 */
	public function status(): int {
		return $this->status;
	}//end status()
}//end class
