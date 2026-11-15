<?php

/**
 * OpenCatalogi Channel Hand-Over.
 *
 * One hand-over to a national channel through integriq's gateway: the
 * channel, the endpoint at the destination, the payload and the integriq
 * source set for the channel.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Publication
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
 * @spec openspec/specs/woo-compliance/spec.md#requirement-a-hand-over-to-a-national-channel-calls-the-gateway-with-a-real-source-req-wnd-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Publication;

/**
 * One hand-over to a national channel. Built by
 * NationalIndexService::handOver() and never handed outside it.
 *
 * @spec openspec/specs/woo-compliance/spec.md#requirement-a-hand-over-to-a-national-channel-calls-the-gateway-with-a-real-source-req-wnd-001
 */
final class ChannelHandOver {

	/**
	 * The channel.
	 *
	 * @var string
	 */
	public string $channel = '';

	/**
	 * The endpoint at the destination.
	 *
	 * @var string
	 */
	public string $endpoint = '';

	/**
	 * What is being sent.
	 *
	 * @var array<string, mixed>
	 */
	public array $payload = [];

	/**
	 * The integriq source object set for the channel.
	 *
	 * @var object
	 */
	public object $source;
}//end class
