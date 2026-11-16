<?php

/**
 * Contract of RedactedOriginalGuard, for callers in other components.
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
 * @spec openspec/changes/scanned-attachments-become-searchable/specs/search/spec.md#requirement-a-published-scan-is-read-by-ocr-so-search-finds-its-words-req-socr-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Contract;

/**
 * Contract of RedactedOriginalGuard, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 *
 * @spec openspec/changes/scanned-attachments-become-searchable/specs/search/spec.md#requirement-a-published-scan-is-read-by-ocr-so-search-finds-its-words-req-socr-001
 */
interface RedactedOriginalGuardInterface {
	/**
	 * Whether the file is the original of a redacted document.
	 *
	 * @param int $fileId The Nextcloud file id.
	 *
	 * @return bool|null True for a redacted original, false when it is not
	 *                   one (or no Woo register is configured), null when it
	 *                   could not be checked.
	 *
	 * @spec openspec/changes/scanned-attachments-become-searchable/tasks.md#1.4
	 */
	public function isRedactedOriginal(int $fileId): ?bool;
}//end interface
