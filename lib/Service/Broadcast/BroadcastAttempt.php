<?php

/**
 * Where one broadcast target's retry loop stands.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Broadcast
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
 * @spec openspec/specs/federation/spec.md
 */

namespace OCA\OpenCatalogi\Service\Broadcast;

/**
 * The target, how many attempts were made and how many are allowed. Built
 * and read by BroadcastService::sendBroadcastRequest() only.
 *
 * @spec openspec/specs/federation/spec.md
 */
final class BroadcastAttempt {

	/**
	 * The target URL.
	 *
	 * @var string
	 */
	public string $url = '';

	/**
	 * The attempts made so far.
	 *
	 * @var int
	 */
	public int $attempt = 0;

	/**
	 * The attempts allowed.
	 *
	 * @var int
	 */
	public int $maxRetries = 0;
}//end class
