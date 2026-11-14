<?php

/**
 * Outbound gate: whether a national or federation hand-over may leave the instance.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/instance-staging-mode
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service;

/**
 * Every outbound channel asks the gate first (REQ-STG-002). In staging, or
 * when the mode cannot be read, it answers no, and the channel records a
 * `dryRunDelivery` instead of sending, with `reason()` as its reason.
 *
 * @spec openspec/changes/instance-staging-mode
 */
class OutboundGate {

	/**
	 * The channels that hand something over outside the instance.
	 */
	public const CHANNELS = ['national-index', 'plooi', 'woo-registration', 'broadcast', 'directory-announce'];

	/**
	 * Constructor.
	 *
	 * @param InstanceMode $mode The instance mode.
	 */
	public function __construct(
		private readonly InstanceMode $mode,
	) {
	}//end __construct()

	/**
	 * Whether this channel may send now.
	 *
	 * @param string $channel One of CHANNELS.
	 *
	 * @return bool False in staging or when the mode cannot be read.
	 *
	 * @spec openspec/changes/instance-staging-mode/specs/admin-settings/spec.md#requirement-nothing-leaves-the-instance-in-staging-req-stg-002
	 */
	public function allow(string $channel): bool {
		return $this->reason(channel: $channel) === null;
	}//end allow()

	/**
	 * Why this channel may not send, or null when it may.
	 *
	 * @param string $channel One of CHANNELS.
	 *
	 * @return string|null The reason a dry run records.
	 *
	 * @spec openspec/changes/instance-staging-mode/specs/admin-settings/spec.md#requirement-nothing-leaves-the-instance-in-staging-req-stg-002
	 */
	public function reason(string $channel): ?string {
		$staging = $this->mode->stagingReason();
		if ($staging === null) {
			return null;
		}

		return $channel . ' held: instance mode ' . $staging;
	}//end reason()
}//end class
