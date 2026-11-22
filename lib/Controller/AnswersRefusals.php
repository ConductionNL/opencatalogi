<?php

/**
 * Answers a step as JSON, or its refusal with the refusal's error, message and status.
 *
 * @category Controller
 * @package  OCA\OpenCatalogi\Controller
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
 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-nothing-is-written-until-a-person-accepts-req-wms-003
 * @spec openspec/changes/scanned-attachments-become-searchable/specs/search/spec.md#requirement-the-file-list-shows-whether-search-can-read-each-attachment-req-socr-004
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\Exception\RequestRefusedException;
use OCP\AppFramework\Http\JSONResponse;

/**
 * Answers a step as JSON, or its refusal with the refusal's error, message and status.
 *
 * The using controller words the message for each error in refusalMessage().
 *
 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-nothing-is-written-until-a-person-accepts-req-wms-003
 * @spec openspec/changes/scanned-attachments-become-searchable/specs/search/spec.md#requirement-the-file-list-shows-whether-search-can-read-each-attachment-req-socr-004
 */
trait AnswersRefusals {

	/**
	 * The message the caller reads for a refusal.
	 *
	 * @param string $error The refusal's error.
	 *
	 * @return string The message.
	 */
	abstract protected function refusalMessage(string $error): string;

	/**
	 * Run the step and answer its result, or the refusal it threw.
	 *
	 * @param callable(): array<string, mixed> $step The step.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md#requirement-nothing-is-written-until-a-person-accepts-req-wms-003
	 * @spec openspec/changes/scanned-attachments-become-searchable/specs/search/spec.md#requirement-the-file-list-shows-whether-search-can-read-each-attachment-req-socr-004
	 */
	private function answerOrRefuse(callable $step): JSONResponse {
		try {
			return new JSONResponse($step());
		} catch (RequestRefusedException $e) {
			return new JSONResponse(
				data: ['error' => $e->error(), 'message' => $this->refusalMessage(error: $e->error())],
				statusCode: $e->status()
			);
		}

	}//end answerOrRefuse()
}//end trait
