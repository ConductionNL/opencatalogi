<?php

/**
 * Contract of PublicationStateService, for callers in other components.
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
 * Contract of PublicationStateService, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 */
interface PublicationStateServiceInterface {
	public const STATE_ARCHIVED = 'archived';

	public const STATE_PUBLIC = 'public';

	public const STATE_SCHEDULED = 'scheduled';

	public const STATE_WITHDRAWN = 'withdrawn';

	/**
	 * The state of a publication at a moment.
	 *
	 * Archived wins, because the archived lifecycle state removes a publication
	 * from every public surface whatever its dates say. A depublication date
	 * that has passed wins over a publication date, because that is how
	 * OpenRegister's published predicate reads the pair.
	 *
	 * @param array<string, mixed>   $publication The publication's properties.
	 * @param DateTimeInterface|null $now         The moment, now when omitted.
	 *
	 * @return string One of the STATE_ constants.
	 *
	 * @spec openspec/specs/publications/spec.md#requirement-the-server-tells-the-page-whether-a-publication-is-public-req-ppw-001
	 */
	public function stateOf(array $publication, ?DateTimeInterface $now = null): string;

	/**
	 * The record once it is published now.
	 *
	 * The publication date becomes now and the depublication date is left out
	 * rather than set to null: the schema types it as a date-time string, so a
	 * null would be refused, and OpenRegister's update replaces the record, so
	 * leaving it out clears it.
	 *
	 * @param array<string, mixed>   $publication The stored properties.
	 * @param DateTimeInterface|null $now         The moment, now when omitted.
	 *
	 * @return array<string, mixed> The properties to save.
	 *
	 * @spec openspec/specs/publications/spec.md#requirement-an-editor-publishes-or-withdraws-a-publication-in-one-action-req-ppw-002
	 */
	public function publishedRecord(array $publication, ?DateTimeInterface $now = null): array;

	/**
	 * The record once it is withdrawn now.
	 *
	 * @param array<string, mixed>   $publication The stored properties.
	 * @param DateTimeInterface|null $now         The moment, now when omitted.
	 *
	 * @return array<string, mixed> The properties to save.
	 *
	 * @spec openspec/specs/publications/spec.md#requirement-an-editor-publishes-or-withdraws-a-publication-in-one-action-req-ppw-002
	 */
	public function withdrawnRecord(array $publication, ?DateTimeInterface $now = null): array;

	/**
	 * The channels a publication reached, so each gets a withdrawal.
	 *
	 * The national Woo-index harvests every public publication from the DiWoo
	 * sitemap, so a publication that was public reached it. PLOOI only holds
	 * what was delivered to it.
	 *
	 * @param array<string, mixed> $publication The publication's properties.
	 *
	 * @return array<int, string> The channel names.
	 *
	 * @spec openspec/specs/publications/spec.md#requirement-an-editor-publishes-or-withdraws-a-publication-in-one-action-req-ppw-002
	 */
	public function channelsReached(array $publication): array;

	/**
	 * A depublication as the register stores it.
	 *
	 * The schema types a withdrawal's `acknowledgedAt` and `answer` as strings.
	 * An outstanding withdrawal has neither yet, so those keys are left out
	 * rather than sent as null, which the register would refuse.
	 *
	 * @param array<string, mixed> $depublication The depublication.
	 *
	 * @return array<string, mixed> The depublication without null values.
	 *
	 * @spec openspec/specs/publications/spec.md#requirement-an-editor-publishes-or-withdraws-a-publication-in-one-action-req-ppw-002
	 */
	public function storable(array $depublication): array;
}//end interface
