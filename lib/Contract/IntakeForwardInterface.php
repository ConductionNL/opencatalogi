<?php

/**
 * Contract of DossiqWooForward, for callers in other components.
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
 * Contract of DossiqWooForward, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface IntakeForwardInterface {

	/**
	 * The keys every answer carries.
	 */
	public const ANSWER_KEYS = ['outcome', 'requestId', 'reference', 'dueAt', 'message'];

	/**
	 * The origin of a request sent through a portal form.
	 */
	public const ORIGIN_PORTAL_FORM = 'portal-form';

	/**
	 * The origin of a request recorded in OpenCatalogi.
	 */
	public const ORIGIN_OPENCATALOGI = 'opencatalogi';

	/**
	 * Whether the receiving app is enabled, so new requests go there.
	 *
	 * @return bool
	 *
	 * @spec exclude Structural contract, no functional requirement.
	 */
	public function isAvailable(): bool;

	/**
	 * Hand a newly received request over and answer the receiving app's answer.
	 *
	 * @param array<string, mixed> $answers    The request's answers.
	 * @param string               $receivedAt When it was sent (ISO 8601), or ''.
	 * @param string               $origin     Where it came from.
	 *
	 * @return array<string, string> The answer, with at least ANSWER_KEYS.
	 *
	 * @spec exclude Structural contract, no functional requirement.
	 */
	public function forward(array $answers, string $receivedAt, string $origin): array;
}//end interface
