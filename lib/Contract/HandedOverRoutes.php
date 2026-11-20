<?php

/**
 * Marks a controller whose routes serve records that are handed over to another app.
 *
 * @category Contract
 * @package  OCA\OpenCatalogi\Contract
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @version GIT: <git_id>
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Contract;

/**
 * A controller the hand-over middleware guards: without the receiving app its
 * routes answer 404, and once the records are read-only its change routes 410.
 *
 * @spec openspec/changes/woo-request-intake-hands-over-to-dossiq/specs/woo-request-intake/spec.md#requirement-without-dossiq-opencatalogi-takes-no-woo-requests-req-whd-005
 */
interface HandedOverRoutes {

	/**
	 * The route methods that change a record; the implementing controller names its own.
	 */
	public const WRITE_ROUTES = [];
}//end interface
