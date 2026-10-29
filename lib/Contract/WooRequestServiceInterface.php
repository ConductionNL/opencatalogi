<?php

/**
 * Contract of WooRequestService, for callers in other components.
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
 * Contract of WooRequestService, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 */
interface WooRequestServiceInterface {
	/**
	 * Build the record for a request that just arrived.
	 *
	 * The reference is minted here rather than taken from the caller: a caller
	 * that supplies its own reference can supply one that is already in use, and
	 * the reference is what the requester quotes.
	 *
	 * @param array<string, mixed> $input What arrived: the requested information, the requester, the channel.
	 * @param string $receivedBy Who registered it, or the channel that did.
	 * @param DateTimeInterface|null $now When it arrived; defaults to now.
	 *
	 * @return array<string, mixed> The record to save.
	 *
	 * @throws DomainException When nothing was asked for, or the channel is unknown.
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-a-woo-request-is-a-record-of-its-own-req-wri-001
	 */
	public function receive(array $input, string $receivedBy, ?DateTimeInterface $now = null): array;

	/**
	 * Mint a reference the requester can quote.
	 *
	 * @param DateTimeInterface $now The moment, which gives the year.
	 *
	 * @return string The reference.
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-a-woo-request-is-a-record-of-its-own-req-wri-001
	 */
	public function mintReference(DateTimeInterface $now): string;

	/**
	 * Record the armed term on the request.
	 *
	 * @param array<string, mixed> $request The request.
	 * @param array<string, mixed> $term The term, as StatutoryTerm reports it.
	 *
	 * @return array<string, mixed> The request, with its term.
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-a-statutory-term-is-armed-when-a-request-is-received-req-wri-002
	 */
	public function withTerm(array $request, array $term): array;

	/**
	 * What the requester is told at intake: the reference and the clock.
	 *
	 * A receipt that leaves the date out is the cheapest way to fail the duty to
	 * tell a requester when their answer is owed, so the date is not optional
	 * here: a request whose term was not armed says so rather than leaving the
	 * field quietly empty.
	 *
	 * @param array<string, mixed> $request The request.
	 *
	 * @return array<string, mixed> The receipt.
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-the-requester-is-told-the-reference-and-the-due-date-at-intake-req-wri-005
	 */
	public function receipt(array $request): array;

	/**
	 * Attach the disclosure batch a request produced.
	 *
	 * The link is kept on the request, never on the batch: a batch has been in
	 * production at twelve organisations without one, and it has to keep working
	 * without one.
	 *
	 * @param array<string, mixed> $request The request.
	 * @param string $batchUuid The batch it produced.
	 *
	 * @return array<string, mixed> The request, with its batch.
	 *
	 * @throws DomainException When no batch was named.
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-a-request-may-produce-a-batch-and-a-batch-works-without-a-request-req-wri-007
	 */
	public function attachBatch(array $request, string $batchUuid): array;

	/**
	 * Terms met and missed, over the requests given.
	 *
	 * A term is MET when the decision went out on or before the due date, MISSED
	 * when it went out after it or when it is already overdue and undecided, and
	 * otherwise it is still running or suspended. An undecided request whose due
	 * date has not passed is not counted as met: counting it would report a
	 * compliance figure that can only get worse.
	 *
	 * @param array<int, array<string, mixed>> $requests The requests, each with `dueAt` and `decidedAt`.
	 * @param DateTimeInterface|null $now The moment; defaults to now.
	 *
	 * @return array<string, mixed> The report.
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-terms-met-and-missed-are-reported-req-wri-006
	 */
	public function termsReport(array $requests, ?DateTimeInterface $now = null): array;
}//end interface
