<?php

/**
 * An attachment reading request that is refused: it names the error and the HTTP status to answer with.
 *
 * @category Exception
 * @package  OCA\OpenCatalogi\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/scanned-attachments-become-searchable/specs/search/spec.md#requirement-the-file-list-shows-whether-search-can-read-each-attachment-req-socr-004
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Exception;

use RuntimeException;

/**
 * An attachment reading request that is refused: it names the error and the HTTP status to answer with.
 *
 * @spec openspec/changes/scanned-attachments-become-searchable/specs/search/spec.md#requirement-the-file-list-shows-whether-search-can-read-each-attachment-req-socr-004
 */
class AttachmentReadingRefusedException extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string $error  The machine-readable error, such as `forbidden` or `not-found`.
	 * @param int    $status The HTTP status to answer with.
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
	 * @spec openspec/changes/scanned-attachments-become-searchable/specs/search/spec.md#requirement-the-file-list-shows-whether-search-can-read-each-attachment-req-socr-004
	 */
	public function error(): string {
		return $this->error;
	}//end error()

	/**
	 * The HTTP status to answer with.
	 *
	 * @return int The status.
	 *
	 * @spec openspec/changes/scanned-attachments-become-searchable/specs/search/spec.md#requirement-the-file-list-shows-whether-search-can-read-each-attachment-req-socr-004
	 */
	public function status(): int {
		return $this->status;
	}//end status()
}//end class
