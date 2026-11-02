<?php

/**
 * Received information requests and their stored records, for the request controller.
 *
 * @category Controller
 * @package  OCA\OpenCatalogi\Controller
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
 * @spec openspec/specs/woo-request-intake/spec.md#requirement-a-woo-request-is-a-record-of-its-own-req-wri-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\Contract\InformationRequestServiceInterface;
use OCA\OpenCatalogi\Contract\InformationRequestStoreInterface;

/**
 * Builds information request records, receipts and the terms report, and
 * stores and reads the requests.
 *
 * @spec openspec/specs/woo-request-intake/spec.md#requirement-a-woo-request-is-a-record-of-its-own-req-wri-001
 */
class RequestRecords {

	/**
	 * Constructor.
	 *
	 * @param InformationRequestServiceInterface $requests Builds request records, receipts and the terms report.
	 * @param InformationRequestStoreInterface   $store    Stores and reads the requests.
	 *
	 * @spec exclude pure framework plumbing: constructor dependency injection.
	 */
	public function __construct(
		private readonly InformationRequestServiceInterface $requests,
		private readonly InformationRequestStoreInterface $store,
	) {

	}//end __construct()

	/**
	 * Build the record of a newly received request.
	 *
	 * @param array  $input      The intake input.
	 * @param string $receivedBy The user id of whoever records it.
	 *
	 * @return array<string, mixed> The record to save.
	 *
	 * @throws \DomainException When nothing was asked for, or the channel is unknown.
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-a-woo-request-is-a-record-of-its-own-req-wri-001
	 */
	public function receive(array $input, string $receivedBy): array {
		return $this->requests->receive(input: $input, receivedBy: $receivedBy);

	}//end receive()

	/**
	 * The request with its statutory term.
	 *
	 * @param array $request The request.
	 * @param array $term    The term.
	 *
	 * @return array<string, mixed> The request, with its term.
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-a-woo-request-is-a-record-of-its-own-req-wri-001
	 */
	public function withTerm(array $request, array $term): array {
		return $this->requests->withTerm(request: $request, term: $term);

	}//end withTerm()

	/**
	 * The receipt of a request.
	 *
	 * @param array $request The request.
	 *
	 * @return array<string, mixed> The receipt.
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-a-woo-request-is-a-record-of-its-own-req-wri-001
	 */
	public function receipt(array $request): array {
		return $this->requests->receipt(request: $request);

	}//end receipt()

	/**
	 * The request with a batch attached.
	 *
	 * @param array  $request   The request.
	 * @param string $batchUuid The batch uuid.
	 *
	 * @return array<string, mixed> The request, with its batch.
	 *
	 * @throws \DomainException When no batch was named.
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-a-woo-request-is-a-record-of-its-own-req-wri-001
	 */
	public function attachBatch(array $request, string $batchUuid): array {
		return $this->requests->attachBatch(request: $request, batchUuid: $batchUuid);

	}//end attachBatch()

	/**
	 * The terms report over requests.
	 *
	 * @param array $requests The requests.
	 *
	 * @return array<string, mixed> The report.
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-a-woo-request-is-a-record-of-its-own-req-wri-001
	 */
	public function termsReport(array $requests): array {
		return $this->requests->termsReport(requests: $requests);

	}//end termsReport()

	/**
	 * Store a request.
	 *
	 * @param array  $record The request record.
	 * @param string $uuid   The uuid of an existing request, empty for a new one.
	 *
	 * @return array<string, mixed> The stored request.
	 *
	 * @throws \RuntimeException When the register is unresolvable or the write fails.
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-a-woo-request-is-a-record-of-its-own-req-wri-001
	 */
	public function save(array $record, string $uuid=''): array {
		return $this->store->save(record: $record, uuid: $uuid);

	}//end save()

	/**
	 * Read one stored request.
	 *
	 * @param string $requestId The request id.
	 *
	 * @return array<string, mixed>|null The request.
	 *
	 * @throws \RuntimeException When the register is unresolvable.
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-a-woo-request-is-a-record-of-its-own-req-wri-001
	 */
	public function find(string $requestId): ?array {
		return $this->store->find(requestId: $requestId);

	}//end find()

	/**
	 * Every stored request.
	 *
	 * @return array<int, array<string, mixed>> The requests.
	 *
	 * @throws \RuntimeException When the register is unresolvable or unreadable.
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-a-woo-request-is-a-record-of-its-own-req-wri-001
	 */
	public function all(): array {
		return $this->store->all();

	}//end all()
}//end class
