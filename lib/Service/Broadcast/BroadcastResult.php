<?php

/**
 * Value object describing the outcome of a single broadcast target.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Broadcast
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/opencatalogi-delegate-broadcast-to-or-webhooks/specs/federation/spec.md
 */

namespace OCA\OpenCatalogi\Service\Broadcast;

/**
 * BroadcastResult Class.
 *
 * Returned by {@see \OCA\OpenCatalogi\Service\BroadcastService::enqueueBroadcast()}
 * for a single target URL. Introduced as the Phase 1 adapter shape described in
 * `design.md` ("Decision 3 — Return-shape compatibility via BroadcastResult") for
 * the opencatalogi-delegate-broadcast-to-or-webhooks change: today `$status` and
 * `$delivered` describe the outcome of the still-synchronous legacy dispatch
 * (`STATUS_DELIVERED` / `STATUS_FAILED`); once broadcast delivery is wired to
 * OpenRegister's `WebhookService` the vocabulary grows to include `enqueued` /
 * `dead-letter` per that same design.
 */
class BroadcastResult {

	/**
	 * Status value when the broadcast was sent and acknowledged successfully.
	 *
	 * @var string
	 */
	public const STATUS_DELIVERED = 'delivered';

	/**
	 * Status value when the broadcast could not be delivered.
	 *
	 * @var string
	 */
	public const STATUS_FAILED = 'failed';

	/**
	 * Constructor for BroadcastResult.
	 *
	 * @param string $url The target URL this result describes.
	 * @param string $status One of the STATUS_* constants.
	 * @param boolean $delivered Whether the target ultimately received the broadcast.
	 */
	public function __construct(
		public readonly string $url,
		public readonly string $status,
		public readonly bool $delivered,
	) {
	}//end __construct()
}//end class
