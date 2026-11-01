<?php

/**
 * Contract of WooRegistrationService, for callers in other components.
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

use DateTimeInterface;

/**
 * Contract of WooRegistrationService, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 */
interface IndexRegistrationServiceInterface {
	/**
	 * The stored registration, with the answer the gateway gave.
	 *
	 * @return array{status: string, registeredUrl: string, registeredAt: string, answer: string}
	 *
	 * @spec openspec/specs/woo-compliance/spec.md#requirement-the-woo-index-registration-is-requested-through-the-gateway-req-wih-003
	 */
	public function registration(): array;

	/**
	 * The registration request, composed from what the app holds.
	 *
	 * @return array{
	 *     organisations: array<int, array{name: string, tooiIdentifier: string}>,
	 *     baseUrl: string,
	 *     robotsTxt: string,
	 *     sitemapIndexes: array<int, string>
	 * }
	 *
	 * @spec openspec/specs/woo-compliance/spec.md#requirement-the-woo-index-registration-is-requested-through-the-gateway-req-wih-003
	 */
	public function compose(): array;

	/**
	 * Hand the registration to the gateway and record what it answered.
	 *
	 * The status moves to `requested` only on an answer. An unreachable gateway
	 * records nothing as sent; the caller shows the composed request instead.
	 *
	 * @param DateTimeInterface|null $now The moment, now when omitted.
	 *
	 * @return array{sent: bool, request: array<string, mixed>, registration: array<string, string>, reason?: string}
	 *
	 * @spec openspec/specs/woo-compliance/spec.md#requirement-the-woo-index-registration-is-requested-through-the-gateway-req-wih-003
	 */
	public function request(?DateTimeInterface $now = null): array;

	/**
	 * Record that the Woo-index confirmed the registration.
	 *
	 * @param DateTimeInterface|null $now The moment, now when omitted.
	 *
	 * @return array<string, string> The stored registration.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md#requirement-the-woo-index-registration-is-requested-through-the-gateway-req-wih-003
	 */
	public function confirm(?DateTimeInterface $now = null): array;

	/**
	 * The Apache and nginx rules that serve the app's robots.txt at the root.
	 *
	 * Rendered from the base URL, so an instance under a sub-path gets a rule
	 * that points at its own route.
	 *
	 * @return array{robotsUrl: string, apache: string, nginx: string}
	 *
	 * @spec openspec/specs/woo-compliance/spec.md#requirement-the-administrator-gets-the-rule-that-serves-robots-txt-at-the-domain-root-req-wih-002
	 */
	public function rootRules(): array;
}//end interface
