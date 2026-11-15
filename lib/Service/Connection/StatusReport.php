<?php

/**
 * One connection status about to be reported to integriq.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Connection
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-oc-conn-003-opencatalogi-reports-what-a-sync-a-broadcast-and-a-readiness-check-met
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Connection;

/**
 * The connection key and the status an outcome says, as the throttle reads
 * and remembers them. Built and read by ConnectionReporter only.
 *
 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-oc-conn-003-opencatalogi-reports-what-a-sync-a-broadcast-and-a-readiness-check-met
 */
final class StatusReport {

	/**
	 * Constructor.
	 *
	 * @param string $key One of ConnectionReporter::KEYS.
	 * @param string $status The status the outcome says.
	 */
	public function __construct(
		public readonly string $key,
		public readonly string $status,
	) {
	}//end __construct()
}//end class
