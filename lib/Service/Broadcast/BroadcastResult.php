<?php

/**
 * Value object describing the outcome of a single broadcast delivery attempt.
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
 * @spec openspec/changes/opencatalogi-delegate-broadcast-to-or-webhooks/specs/federation/spec.md
 */

namespace OCA\OpenCatalogi\Service\Broadcast;

/**
 * BroadcastResult Class.
 *
 * Carries the outcome of a single call to
 * {@see \OCA\OpenCatalogi\Service\BroadcastService::enqueueBroadcast()} so
 * callers can distinguish delivery outcomes without re-deriving them from a
 * bare boolean.
 *
 * `STATUS_ENQUEUED` is reserved for the OpenRegister-webhook delivery path.
 * That path is not wired up yet (see the openspec change referenced above,
 * Task 2 — it depends on confirming `WebhookService::triggerWebhookForEvent`'s
 * signature against OpenRegister's development HEAD, which was not possible
 * to verify from this checkout). Today `enqueueBroadcast()` only ever
 * returns `STATUS_DELIVERED` or `STATUS_FAILED` because it still dispatches
 * synchronously via the legacy retry loop.
 */
final class BroadcastResult {

	/**
	 * The broadcast was accepted by the target and confirmed delivered.
	 *
	 * @var string
	 */
	public const STATUS_DELIVERED = 'delivered';

	/**
	 * The broadcast was handed off to OR's WebhookService for async delivery.
	 *
	 * @var string
	 */
	public const STATUS_ENQUEUED = 'enqueued';

	/**
	 * The broadcast could not be delivered.
	 *
	 * @var string
	 */
	public const STATUS_FAILED = 'failed';

	/**
	 * Constructor for BroadcastResult.
	 *
	 * @param string $url The target URL this result describes.
	 * @param string $status One of the STATUS_* constants.
	 */
	public function __construct(
		public readonly string $url,
		public readonly string $status,
	) {
	}//end __construct()

	/**
	 * Whether the target should be considered a broadcast success.
	 *
	 * Both a confirmed delivery and a successful hand-off to async delivery
	 * count as success; only STATUS_FAILED does not.
	 *
	 * @return boolean True when the broadcast was delivered or enqueued.
	 */
	public function isSuccessful(): bool {
		return $this->status === self::STATUS_DELIVERED || $this->status === self::STATUS_ENQUEUED;
	}//end isSuccessful()
}//end class
