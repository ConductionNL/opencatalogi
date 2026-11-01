<?php

/**
 * Contract of DepublicationService, for callers in other components.
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
use DomainException;

/**
 * Contract of DepublicationService, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 */
interface DepublicationServiceInterface {
	/**
	 * Depublish in one action and withdraw from every channel it reached.
	 *
	 * @param array<string, mixed> $publication The publication.
	 * @param string $reason Why it is coming down.
	 * @param string $depublishedBy Who is taking it down.
	 * @param array<int, string> $channels The channels the publication reached.
	 * @param DateTimeInterface|null $now The moment; defaults to now.
	 *
	 * @return array<string, mixed> The depublication to save.
	 *
	 * @throws DomainException When no reason is given.
	 *
	 * @spec openspec/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-something-published-in-error-is-depublished-with-one-action-req-pin-106
	 */
	public function depublish(
		array $publication,
		string $reason,
		string $depublishedBy,
		array $channels,
		?DateTimeInterface $now = null,
	): array;

	/**
	 * The channels whose withdrawal is still outstanding.
	 *
	 * @param array<string, mixed> $depublication The depublication.
	 *
	 * @return array<int, string> The channels that have not acknowledged.
	 *
	 * @spec openspec/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-something-published-in-error-is-depublished-with-one-action-req-pin-106
	 */
	public function outstandingChannels(array $depublication): array;

	/**
	 * Whether the depublication is done everywhere.
	 *
	 * @param array<string, mixed> $depublication The depublication.
	 *
	 * @return boolean True only when every channel acknowledged.
	 *
	 * @spec openspec/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-something-published-in-error-is-depublished-with-one-action-req-pin-106
	 */
	public function isComplete(array $depublication): bool;

	/**
	 * Record a channel's late acknowledgement.
	 *
	 * @param array<string, mixed> $depublication The depublication.
	 * @param string $channel The channel that answered.
	 * @param string $answer What it said.
	 * @param DateTimeInterface|null $now The moment; defaults to now.
	 *
	 * @return array<string, mixed> The depublication.
	 *
	 * @spec openspec/specs/publication-inspection-and-the-national-indexes/spec.md#requirement-something-published-in-error-is-depublished-with-one-action-req-pin-106
	 */
	public function recordAcknowledgement(
		array $depublication,
		string $channel,
		string $answer,
		?DateTimeInterface $now = null,
	): array;
}//end interface
