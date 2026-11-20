<?php

/**
 * Contract of ErrorReportIntake, for callers in other components.
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
 * Contract of ErrorReportIntake, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 */
interface ErrorReportIntakeInterface {
	/**
	 * Store a visitor's error report on a publication.
	 *
	 * @param string $publicationId The publication.
	 * @param mixed  $message       The report text as posted.
	 * @param mixed  $contact       The optional e-mail address as posted.
	 *
	 * @return array{status: int, error?: string, reference?: string} 201 with the reference, 400 or 404 with an
	 *                                                                  error code, 503 when the schema is not configured.
	 *
	 * @spec openspec/changes/publication-detail-for-the-portal/specs/publications/spec.md#requirement-a-catalogue-may-take-error-reports-from-anyone-throttled-and-moderated-req-pdp-005
	 */
	public function submit(string $publicationId, mixed $message, mixed $contact): array;
}//end interface
