<?php

/**
 * OpenCatalogi Redaction Run.
 *
 * One document's redaction: the original file and the two OpenRegister
 * services the redaction reads the findings from and writes the redacted
 * file with.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Woo
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @version GIT: <git_id>
 * @link https://www.OpenCatalogi.nl
 * @spec openspec/changes/woo-redaction-pipeline/specs/woo-transparency/spec.md#requirement-a-partly-public-document-is-published-only-as-a-verified-redacted-version-req-wrp-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Woo;

use OCP\Files\File;

/**
 * One redaction of one document. Built by DocumentRedactor::redact() and
 * never handed outside it.
 *
 * @spec openspec/changes/woo-redaction-pipeline/specs/woo-transparency/spec.md#requirement-a-partly-public-document-is-published-only-as-a-verified-redacted-version-req-wrp-001
 */
final class RedactionRun {

	/**
	 * The original document.
	 *
	 * @var File
	 */
	public File $original;

	/**
	 * OpenRegister's FileService, which writes the redacted file.
	 *
	 * @var object
	 */
	public object $files;

	/**
	 * OpenRegister's EntityRelationMapper, which holds the findings.
	 *
	 * @var object
	 */
	public object $relations;
}//end class
