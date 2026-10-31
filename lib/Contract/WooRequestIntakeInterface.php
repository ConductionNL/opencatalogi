<?php

/**
 * Contract of WooRequestIntake, for callers in other components.
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
 * Contract of WooRequestIntake, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 */
interface WooRequestIntakeInterface {
	/**
	 * The register could not store the request.
	 */
	public const OUTCOME_UNAVAILABLE = 'unavailable';

	/**
	 * Receive one request from the portal.
	 *
	 * @param array<string, mixed> $answers What the citizen filled in, keyed by the request's own field names.
	 * @param string $receivedAt When the citizen sent it (ISO 8601). The term counts from then, not from delivery.
	 *
	 * @return array{outcome: string, requestId: string, reference: string, dueAt: string, message: string}
	 *
	 * @spec openspec/changes/portal-woo-request-intake/specs/woo-request-intake/spec.md#requirement-a-woo-request-delivered-by-the-portal-arms-its-term-req-wri-008
	 */
	public function receive(array $answers, string $receivedAt = ''): array;
}//end interface
