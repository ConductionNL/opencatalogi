<?php

/**
 * Contract of PortalContributionProvider, for callers in other components.
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
 * Contract of PortalContributionProvider, for callers in other components.
 *
 * Callers outside Portal/ depend on this interface instead of on the class.
 */
interface PortalContributionProviderInterface {
	/**
	 * The rule key of a saved-search notice (contract C3).
	 */
	public const RULE_SAVED_SEARCH_MATCHED = 'opencatalogi.savedSearch.matched';

	/**
	 * The public index a portal's visitors read: the service status.
	 *
	 * Portaliq calls this without a session, for every portal; the status is
	 * the same for each.
	 *
	 * @param string $portal The portal slug.
	 *
	 * @return array<int, array<string, mixed>> The index items.
	 *
	 * @spec openspec/changes/service-status-on-the-portal/specs/service-status-on-the-portal/spec.md#requirement-the-portal-index-carries-the-service-status-ssp-001
	 */
	public function getPublicIndex(string $portal): array;

	/**
	 * The kinds of the public index, with the columns a site table can show.
	 *
	 * @param string $portal The portal slug.
	 *
	 * @return array<int, array<string, mixed>> The kinds.
	 *
	 * @spec openspec/changes/service-status-on-the-portal/specs/service-status-on-the-portal/spec.md#requirement-the-portal-index-carries-the-service-status-ssp-001
	 */
	public function getPublicIndexKinds(string $portal): array;

	/**
	 * Receive a Woo request a citizen sent through a portal form.
	 *
	 * The portal (portaliq) calls this from its intake delivery job for a form bound with
	 * `deliverTo: wooRequest`. opencatalogi mints the reference and arms the
	 * statutory term; portaliq only reports the outcome to the citizen.
	 *
	 * @param array<string, mixed> $answers The citizen's answers, keyed by the request's field names.
	 * @param string $receivedAt When the citizen sent it (ISO 8601).
	 *
	 * @return array{outcome: string, requestId: string, reference: string, dueAt: string, message: string}
	 *
	 * @spec openspec/changes/portal-woo-request-intake/specs/woo-request-intake/spec.md#requirement-a-woo-request-delivered-by-the-portal-arms-its-term-req-wri-008
	 */
	public function receiveWooRequest(array $answers, string $receivedAt=''): array;

	/**
	 * The items of one dossier, for portaliq's `itemList` (hydra C7).
	 *
	 * Portaliq calls this only after the resident's own scoped read of the
	 * dossier succeeded, so it does not check the owner again.
	 *
	 * @param string $collectionId The dossier id.
	 *
	 * @return array<int, array{id: string, title: string, url: string, note: string, public: bool, addedAt: string}>
	 *
	 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-opencatalogi-contributes-dossiers-to-the-portal-for-citizen-and-client-req-ccol-006
	 */
	public function dossierItems(string $collectionId): array;

	/**
	 * The audiences this provider contributes to (contract v2).
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-opencatalogi-contributes-dossiers-to-the-portal-for-citizen-and-client-req-ccol-006
	 */
	public function getAudiences(): array;

	/**
	 * The primary audience, for a contract v1 registry.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-opencatalogi-contributes-dossiers-to-the-portal-for-citizen-and-client-req-ccol-006
	 */
	public function getAudience(): string;

	/**
	 * The manifest for one resolved subject, or null for an audience not served.
	 *
	 * @param array<string, mixed> $subject The subject portaliq resolved.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-opencatalogi-contributes-dossiers-to-the-portal-for-citizen-and-client-req-ccol-006
	 */
	public function getContribution(array $subject): ?array;
}//end interface
