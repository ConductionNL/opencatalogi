<?php

/**
 * Integriq Gateway Delivery Requested Event, test copy.
 *
 * A verbatim copy of integriq's class on `development` (read 29 Sep 2026), so the
 * tests construct the real event shape. tests/bootstrap*.php load it only when
 * the real class is absent; in CI the real class is installed.
 *
 * The typed command a sibling app dispatches to send through a statutory gateway.
 *
 * @category Event
 * @package  OCA\Integriq\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 */

declare(strict_types=1);

namespace OCA\Integriq\Event;

use OCP\EventDispatcher\Event;

/**
 * "Send this through that gateway", with a slot for the delivery.
 *
 * ADR-041: a typed command with a result slot, answered synchronously. The
 * request shape depends on the gateway: `corv` and `ggk` take
 * `{messageType, message}`, `wkpb` takes `{propertyReference, restriction}`
 * and `publicatie` takes `{reference, instruction}`. The delivery is
 * GatewayDelivery::toArray(): delivered, refused before sending, or refused
 * by the other side (replayable). Refusal codes: `unknown-gateway` and
 * `invalid-request` (the request lacks the fields its gateway reads).
 *
 */
class GatewayDeliveryRequestedEvent extends Event {

	/**
	 * The delivery, set by integriq when it handled the request.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $delivery = null;

	/**
	 * Why integriq did not take the request, when it did not.
	 *
	 * @var array{code: string, reason: string}|null
	 */
	private ?array $refusal = null;

	/**
	 * Constructor.
	 *
	 * @param string $gatewayId The gateway: corv, ggk, wkpb or publicatie.
	 * @param array<string,mixed> $request The request, shaped for that gateway.
	 * @param string $sourceApp The app id asking.
	 * @param array<string,mixed> $config Transport settings (source, endpoint, method).
	 */
	public function __construct(
		private readonly string $gatewayId,
		private readonly array $request,
		private readonly string $sourceApp,
		private readonly array $config = [],
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The gateway id.
	 *
	 * @return string
	 *
	 */
	public function getGatewayId(): string {
		return $this->gatewayId;
	}//end getGatewayId()

	/**
	 * The request, shaped for the gateway.
	 *
	 * @return array<string,mixed>
	 *
	 */
	public function getRequest(): array {
		return $this->request;
	}//end getRequest()

	/**
	 * The app id asking.
	 *
	 * @return string
	 *
	 */
	public function getSourceApp(): string {
		return $this->sourceApp;
	}//end getSourceApp()

	/**
	 * Transport settings.
	 *
	 * @return array<string,mixed>
	 *
	 */
	public function getConfig(): array {
		return $this->config;
	}//end getConfig()

	/**
	 * Record the delivery.
	 *
	 * @param array<string,mixed> $delivery GatewayDelivery::toArray().
	 *
	 * @return void
	 *
	 */
	public function setDelivery(array $delivery): void {
		$this->delivery = $delivery;
	}//end setDelivery()

	/**
	 * The delivery, or null when integriq did not handle the request.
	 *
	 * @return array<string,mixed>|null
	 *
	 */
	public function getDelivery(): ?array {
		return $this->delivery;
	}//end getDelivery()

	/**
	 * Whether integriq handled the request (whatever the outcome).
	 *
	 * @return bool
	 *
	 */
	public function isHandled(): bool {
		return $this->delivery !== null;
	}//end isHandled()

	/**
	 * Refuse the request.
	 *
	 * @param string $reason What the requester is told.
	 * @param string $code   unknown-gateway or invalid-request.
	 *
	 * @return void
	 *
	 */
	public function refuse(string $reason, string $code): void {
		$this->refusal = ['code' => $code, 'reason' => $reason];
	}//end refuse()

	/**
	 * The refusal, or null when there was none.
	 *
	 * @return array{code: string, reason: string}|null
	 *
	 */
	public function getRefusal(): ?array {
		return $this->refusal;
	}//end getRefusal()
}//end class
