<?php

/**
 * OpenCatalogi Publication State Service.
 *
 * Whether a publication is a draft, scheduled, public, withdrawn or archived,
 * and what its record looks like once it is published now or withdrawn now.
 * The page never works this out from dates in the browser: it asks the server,
 * which reads the same two dates OpenRegister's published predicate reads.
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
 * @spec openspec/specs/publications/spec.md#requirement-the-server-tells-the-page-whether-a-publication-is-public-req-ppw-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Publication;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * The visibility state of a publication, and the record for each move.
 *
 * @spec openspec/specs/publications/spec.md#requirement-the-server-tells-the-page-whether-a-publication-is-public-req-ppw-001
 */
class PublicationStateService {

	public const STATE_DRAFT = 'draft';
	public const STATE_SCHEDULED = 'scheduled';
	public const STATE_PUBLIC = 'public';
	public const STATE_WITHDRAWN = 'withdrawn';
	public const STATE_ARCHIVED = 'archived';

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
	public function stateOf(array $publication, ?DateTimeInterface $now = null): string {
		if (($publication['status'] ?? '') === 'archived') {
			return self::STATE_ARCHIVED;
		}

		$moment = $this->moment(now: $now);
		$depublished = $this->parse(value: ($publication['depublicationDate'] ?? null));
		if ($depublished !== null && $depublished <= $moment) {
			return self::STATE_WITHDRAWN;
		}

		$published = $this->parse(value: ($publication['publicationDate'] ?? null));
		if ($published === null) {
			return self::STATE_DRAFT;
		}

		if ($published > $moment) {
			return self::STATE_SCHEDULED;
		}

		return self::STATE_PUBLIC;

	}//end stateOf()

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
	public function publishedRecord(array $publication, ?DateTimeInterface $now = null): array {
		$record = $this->writable(publication: $publication);
		unset($record['depublicationDate']);
		$record['publicationDate'] = $this->moment(now: $now)->format(DateTimeInterface::ATOM);

		return $record;

	}//end publishedRecord()

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
	public function withdrawnRecord(array $publication, ?DateTimeInterface $now = null): array {
		$record = $this->writable(publication: $publication);
		$record['depublicationDate'] = $this->moment(now: $now)->format(DateTimeInterface::ATOM);

		return $record;

	}//end withdrawnRecord()

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
	public function channelsReached(array $publication): array {
		$channels = [NationalIndexService::CHANNEL_WOO_INDEX];
		if (($publication['plooiStatus'] ?? '') === 'delivered') {
			$channels[] = NationalIndexService::CHANNEL_PLOOI;
		}

		return $channels;

	}//end channelsReached()

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
	public function storable(array $depublication): array {
		$withdrawals = [];
		foreach (($depublication['withdrawals'] ?? []) as $withdrawal) {
			if (is_array($withdrawal) === true) {
				$withdrawals[] = array_filter($withdrawal, static fn ($value): bool => $value !== null);
			}
		}

		$depublication['withdrawals'] = $withdrawals;

		return array_filter($depublication, static fn ($value): bool => $value !== null);

	}//end storable()

	/**
	 * The stored properties without the metadata OpenRegister adds on read.
	 *
	 * @param array<string, mixed> $publication The stored properties.
	 *
	 * @return array<string, mixed> The properties a save accepts.
	 */
	private function writable(array $publication): array {
		unset($publication['@self']);

		return $publication;

	}//end writable()

	/**
	 * The moment in UTC.
	 *
	 * @param DateTimeInterface|null $now The moment, now when omitted.
	 *
	 * @return DateTimeImmutable The moment.
	 */
	private function moment(?DateTimeInterface $now): DateTimeImmutable {
		if ($now === null) {
			return new DateTimeImmutable('now', new DateTimeZone('UTC'));
		}

		return new DateTimeImmutable($now->format('Y-m-d\TH:i:s.uP'));

	}//end moment()

	/**
	 * A date-time value, or null when there is none or it cannot be read.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return DateTimeImmutable|null The moment.
	 */
	private function parse(mixed $value): ?DateTimeImmutable {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (\Exception $e) {
			return null;
		}

	}//end parse()
}//end class
