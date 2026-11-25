<?php

/**
 * The 410 answer for a publication that was withdrawn.
 *
 * @category Http
 * @package  OCA\OpenCatalogi\Http
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
 * @spec openspec/changes/archive/2026-11-25-publication-withdrawal-aftercare/specs/publications/spec.md#requirement-a-withdrawn-publication-answers-with-a-tombstone-about-the-withdrawal-req-pwa-002
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Http;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

/**
 * A tombstone is what the public side says about a withdrawn publication, not an
 * error: its body is served exactly as given, so the API's problem details shape
 * never adds a `title` the officer chose not to show.
 *
 * @psalm-suppress MissingTemplateParam
 *
 * @spec openspec/changes/archive/2026-11-25-publication-withdrawal-aftercare/specs/publications/spec.md#requirement-a-withdrawn-publication-answers-with-a-tombstone-about-the-withdrawal-req-pwa-002
 */
class TombstoneResponse extends JSONResponse {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $tombstone The tombstone: id, withdrawnAt, publicReason and the title when shown.
	 *
	 * @spec openspec/changes/archive/2026-11-25-publication-withdrawal-aftercare/specs/publications/spec.md#requirement-a-withdrawn-publication-answers-with-a-tombstone-about-the-withdrawal-req-pwa-002
	 */
	public function __construct(array $tombstone) {
		parent::__construct(data: $tombstone, statusCode: Http::STATUS_GONE);

	}//end __construct()
}//end class
