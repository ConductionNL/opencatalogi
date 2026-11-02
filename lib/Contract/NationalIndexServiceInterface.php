<?php

/**
 * Contract of NationalIndexService, for callers in other components.
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
 * Contract of NationalIndexService, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 */
interface NationalIndexServiceInterface {
	/**
	 * Compose the official notice for one channel.
	 *
	 * The composition is this app's and is testable without any gateway at
	 * all, which is why it is a method of its own rather than a step inside
	 * the delivery.
	 *
	 * @param array<string, mixed> $decision The decision being made known.
	 * @param string $channel The channel the notice is for.
	 * @param DateTimeInterface|null $now The moment; defaults to now.
	 *
	 * @return array<string, mixed> The notice.
	 *
	 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-official-notices-reach-the-national-platform-and-the-local-channel-req-pin-107
	 */
	public function composeNotice(array $decision, string $channel, ?DateTimeInterface $now = null): array;

	/**
	 * Compose the notice for both channels at once.
	 *
	 * @param array<string, mixed> $decision The decision.
	 * @param DateTimeInterface|null $now The moment.
	 *
	 * @return array<int, array<string, mixed>> The two notices.
	 *
	 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-official-notices-reach-the-national-platform-and-the-local-channel-req-pin-107
	 */
	public function composeNotices(array $decision, ?DateTimeInterface $now = null): array;

	/**
	 * Hand a notice to the gateway and record what the destination answered.
	 *
	 * @param array<string, mixed> $notice The composed notice.
	 * @param DateTimeInterface|null $now The moment.
	 *
	 * A notice for the national publication platform goes through integriq's
	 * `publicatie` gateway by reference; every other channel is called through
	 * its configured source.
	 *
	 * @return array{channel: string, deliveredAt: string, answer: string, delivery?: array<string, mixed>}
	 *
	 * @throws \OCA\OpenCatalogi\Service\Publication\IndexUnreachableException When the delivery could not be made.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md#requirement-official-notices-travel-by-reference-through-the-publication-gateway-req-wnd-002
	 */
	public function deliver(array $notice, ?DateTimeInterface $now = null): array;

	/**
	 * Read the channel-to-source map from the app configuration.
	 *
	 * @return array<string, string> Channel name to integriq source slug; unreadable or empty entries are left out.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md#requirement-a-hand-over-to-a-national-channel-calls-the-gateway-with-a-real-source-req-wnd-001
	 */
	public function channelSources(): array;

	/**
	 * Register this instance with the national Woo index.
	 *
	 * The request names the organisation, the root robots.txt and every sitemap
	 * index (WooRegistrationService::compose()); the index harvests from there.
	 *
	 * @param array<string, mixed> $request The composed registration request.
	 * @param DateTimeInterface|null $now The moment.
	 *
	 * @return array{channel: string, registeredAt: string, answer: string}
	 *
	 * @throws \OCA\OpenCatalogi\Service\Publication\IndexUnreachableException When the index could not be asked.
	 *
	 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-official-notices-reach-the-national-platform-and-the-local-channel-req-pin-107
	 */
	public function registerWithWooIndex(array $request, ?DateTimeInterface $now = null): array;

	/**
	 * Deliver one publication to PLOOI, the delivery API of open.overheid.nl.
	 *
	 * @param array<string, mixed> $document The DiWoo metadata and document links.
	 *
	 * @return array{answer: string, identifier: string|null} The platform's answer and the identifier it gave.
	 *
	 * @throws \OCA\OpenCatalogi\Service\Publication\IndexUnreachableException When PLOOI has no source, could not be reached, or refused.
	 *
	 * @spec openspec/specs/woo-compliance/spec.md#requirement-a-publication-that-turns-public-is-delivered-to-plooi-when-the-catalogue-asks-for-it-req-wnd-003
	 */
	public function deliverToPlooi(array $document): array;

	/**
	 * Withdraw a publication from one channel.
	 *
	 * @param string $channel The channel.
	 * @param string $publicationId The publication.
	 * @param string $reason Why it is coming down.
	 * @param DateTimeInterface|null $now The moment.
	 *
	 * @return array{channel: string, acknowledgedAt: string, answer: string}
	 *
	 * @throws \OCA\OpenCatalogi\Service\Publication\IndexUnreachableException When the channel could not be reached.
	 *
	 * @spec openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-something-published-in-error-is-depublished-with-one-action-req-pin-106
	 */
	public function withdraw(string $channel, string $publicationId, string $reason, ?DateTimeInterface $now = null): array;
}//end interface
