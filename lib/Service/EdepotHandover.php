<?php

/**
 * Hands an archived publication to OpenRegister's e-Depot transfer and reads back how it went.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service
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
 * @spec openspec/changes/capability-rows-get-requirements/specs/publication-retention-lifecycle/spec.md#requirement-an-archive-decision-hands-the-publication-to-the-e-depot-req-crr-003
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The e-depot side of an archive decision.
 *
 * The app does not package or send anything itself: it asks OpenRegister's
 * transfer-list service for a transfer of the publication object (which
 * carries its attachments into the package) and keeps the transfer's
 * reference and status on the publication, as `edepotTransfer`. A transfer
 * that cannot be started, or that OpenRegister later reports as failed or
 * rejected, is recorded as `failed` with the reason; the publication stays
 * archived in the app either way.
 *
 * @spec openspec/changes/capability-rows-get-requirements/specs/publication-retention-lifecycle/spec.md#requirement-an-archive-decision-hands-the-publication-to-the-e-depot-req-crr-003
 */
class EdepotHandover {
	use ReadsObjectProperties;

	/**
	 * OpenRegister's transfer-list service: creates a transfer list for objects.
	 */
	public const TRANSFER_LISTS = 'OCA\OpenRegister\Service\Edepot\TransferListService';

	/**
	 * OpenRegister's transfer-record service: loads a stored transfer list.
	 */
	public const TRANSFER_RECORDS = 'OCA\OpenRegister\Service\Edepot\TransferRecordService';

	/**
	 * The status a started transfer carries until OpenRegister reports its end.
	 */
	public const STATUS_REQUESTED = 'requested';

	/**
	 * The status of a transfer the e-depot accepted.
	 */
	public const STATUS_TRANSFERRED = 'transferred';

	/**
	 * The status of a transfer that could not be started or did not succeed.
	 */
	public const STATUS_FAILED = 'failed';

	/**
	 * OpenRegister transfer-list statuses that end the transfer, and what they mean here.
	 */
	private const ENDED = [
		'completed' => self::STATUS_TRANSFERRED,
		'failed' => self::STATUS_FAILED,
		'partially_failed' => self::STATUS_FAILED,
		'rejected' => self::STATUS_FAILED,
	];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Server container for resolving OpenRegister.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Start a transfer of the publication to the e-depot.
	 *
	 * @param mixed $publication The publication object as OpenRegister's find() returned it.
	 * @param DateTimeImmutable|null $now The moment of the request; now when null.
	 *
	 * @return array<string, mixed> The `edepotTransfer` value to store on the publication.
	 *
	 * @spec openspec/changes/capability-rows-get-requirements/specs/publication-retention-lifecycle/spec.md#requirement-an-archive-decision-hands-the-publication-to-the-e-depot-req-crr-003
	 */
	public function request(mixed $publication, ?DateTimeImmutable $now = null): array {
		$moment = self::moment(now: $now);
		$transferLists = $this->service(id: self::TRANSFER_LISTS, method: 'createTransferList');
		if ($transferLists === null) {
			return self::failed(reason: 'The e-depot transfer is not available in OpenRegister', moment: $moment);
		}

		if (is_object($publication) === false || method_exists($publication, 'getUuid') === false) {
			return self::failed(reason: 'The publication could not be read for the e-depot transfer', moment: $moment);
		}

		try {
			$list = $transferLists->createTransferList([$publication]);
		} catch (\Throwable $e) {
			$this->logger->warning('[EdepotHandover] transfer request failed: ' . $e->getMessage());
			return self::failed(reason: $e->getMessage(), moment: $moment);
		}

		$reference = (string)($list['uuid'] ?? '');
		if ($reference === '') {
			return self::failed(reason: 'OpenRegister did not return a transfer reference', moment: $moment);
		}

		return ['reference' => $reference, 'status' => self::STATUS_REQUESTED, 'requestedAt' => $moment, 'updatedAt' => $moment];
	}//end request()

	/**
	 * The stored transfer state brought up to date with OpenRegister's transfer list.
	 *
	 * Only a `requested` transfer is looked up; an ended one is returned as it is.
	 * A list OpenRegister cannot load leaves the state unchanged.
	 *
	 * @param array<string, mixed> $transfer The stored `edepotTransfer` value.
	 * @param DateTimeImmutable|null $now The moment of the check; now when null.
	 *
	 * @return array<string, mixed> The state to store.
	 *
	 * @spec openspec/changes/capability-rows-get-requirements/specs/publication-retention-lifecycle/spec.md#requirement-an-archive-decision-hands-the-publication-to-the-e-depot-req-crr-003
	 */
	public function refresh(array $transfer, ?DateTimeImmutable $now = null): array {
		$reference = (string)($transfer['reference'] ?? '');
		if (($transfer['status'] ?? null) !== self::STATUS_REQUESTED || $reference === '') {
			return $transfer;
		}

		$list = $this->transferList(reference: $reference);
		if ($list === null) {
			return $transfer;
		}

		return self::afterList(transfer: $transfer, list: $list, moment: self::moment(now: $now));
	}//end refresh()

	/**
	 * Bring every archived publication's pending transfer up to date and save the ones that ended.
	 *
	 * @param RetentionRun $run The run over the publication register and schema.
	 *
	 * @return int How many publications got a new transfer status.
	 *
	 * @spec openspec/changes/capability-rows-get-requirements/specs/publication-retention-lifecycle/spec.md#requirement-an-archive-decision-hands-the-publication-to-the-e-depot-req-crr-003
	 */
	public function refreshArchived(RetentionRun $run): int {
		$updated = 0;
		foreach ($this->archivedPublications(run: $run) as $object) {
			$data = $this->plainArray(object: $object);
			if (self::isPending(transfer: ($data['edepotTransfer'] ?? null)) === false) {
				continue;
			}

			$transfer = $this->refresh(transfer: $data['edepotTransfer'], now: $run->now);
			if ($transfer['status'] === $data['edepotTransfer']['status']) {
				continue;
			}

			$data['edepotTransfer'] = $transfer;
			$run->save(data: $data);
			$updated++;
		}

		return $updated;
	}//end refreshArchived()

	/**
	 * The archived publications, or none when the search fails (logged).
	 *
	 * @param RetentionRun $run The run over the publication register and schema.
	 *
	 * @return array<int, mixed> The stored publications.
	 */
	private function archivedPublications(RetentionRun $run): array {
		$query = ['@self' => ['register' => $run->register, 'schema' => $run->schema], 'status' => 'archived', '_limit' => 1000];

		try {
			$result = $run->objectService->searchObjectsPaginated(query: $query, _rbac: false, _multitenancy: false);
		} catch (\Throwable $e) {
			$this->logger->warning('[EdepotHandover] archived publication search failed: ' . $e->getMessage());
			return [];
		}

		return ($result['results'] ?? []);
	}//end archivedPublications()

	/**
	 * Whether a stored transfer still waits for OpenRegister to report its end.
	 *
	 * @param mixed $transfer The stored `edepotTransfer` value.
	 *
	 * @return bool True for a requested transfer with a reference.
	 *
	 * @spec openspec/changes/capability-rows-get-requirements/specs/publication-retention-lifecycle/spec.md#requirement-an-archive-decision-hands-the-publication-to-the-e-depot-req-crr-003
	 */
	public static function isPending(mixed $transfer): bool {
		return is_array($transfer) === true
			&& ($transfer['status'] ?? null) === self::STATUS_REQUESTED
			&& (string)($transfer['reference'] ?? '') !== '';
	}//end isPending()

	/**
	 * The transfer state after OpenRegister's list: unchanged while it runs, ended with a reason when it failed.
	 *
	 * @param array<string, mixed> $transfer The stored state.
	 * @param array<string, mixed> $list OpenRegister's transfer list.
	 * @param string $moment The moment of the check.
	 *
	 * @return array<string, mixed> The new state.
	 */
	private static function afterList(array $transfer, array $list, string $moment): array {
		$status = self::ENDED[(string)($list['status'] ?? '')] ?? null;
		if ($status === null) {
			return $transfer;
		}

		$transfer['status'] = $status;
		$transfer['updatedAt'] = $moment;
		unset($transfer['reason']);
		if ($status === self::STATUS_FAILED) {
			$transfer['reason'] = self::reasonOf(list: $list);
		}

		return $transfer;
	}//end afterList()

	/**
	 * Why a transfer list did not succeed: the archivist's rejection, the transfer error, the first object error, or the status.
	 *
	 * @param array<string, mixed> $list OpenRegister's transfer list.
	 *
	 * @return string The reason.
	 */
	private static function reasonOf(array $list): string {
		$result = $list['transferResult'] ?? [];
		if (is_array($result) === false) {
			$result = [];
		}

		$candidates = [
			$list['approvalMetadata']['rejectionReason'] ?? null,
			$result['error'] ?? null,
			self::firstObjectError(result: $result),
		];
		foreach ($candidates as $candidate) {
			if (is_string($candidate) === true && trim($candidate) !== '') {
				return $candidate;
			}
		}

		return 'The e-depot transfer ended with status ' . (string)($list['status'] ?? '');
	}//end reasonOf()

	/**
	 * The first error among the per-object results of a transfer.
	 *
	 * @param array<string, mixed> $result The transfer result.
	 *
	 * @return string|null The error, or null when none is given.
	 */
	private static function firstObjectError(array $result): ?string {
		foreach (($result['objectResults'] ?? []) as $objectResult) {
			if (is_array($objectResult) === true && is_string($objectResult['error'] ?? null) === true && $objectResult['error'] !== '') {
				return $objectResult['error'];
			}
		}

		return null;
	}//end firstObjectError()

	/**
	 * Load a transfer list from OpenRegister, or null when it cannot.
	 *
	 * @param string $reference The transfer list uuid.
	 *
	 * @return array<string, mixed>|null The list.
	 */
	private function transferList(string $reference): ?array {
		$records = $this->service(id: self::TRANSFER_RECORDS, method: 'loadTransferList');
		if ($records === null) {
			return null;
		}

		try {
			$list = $records->loadTransferList($reference);
		} catch (\Throwable $e) {
			$this->logger->warning('[EdepotHandover] transfer lookup failed: ' . $e->getMessage());
			return null;
		}

		if (is_array($list) === false) {
			return null;
		}

		return $list;
	}//end transferList()

	/**
	 * An OpenRegister service that offers the method, or null when OpenRegister or the method is missing.
	 *
	 * @param string $id The container id.
	 * @param string $method The method the caller needs.
	 *
	 * @return object|null The service.
	 */
	private function service(string $id, string $method): ?object {
		try {
			$service = $this->container->get($id);
		} catch (\Throwable $e) {
			$this->logger->warning('[EdepotHandover] ' . $id . ' unavailable: ' . $e->getMessage());
			return null;
		}

		if (is_object($service) === false || method_exists($service, $method) === false) {
			return null;
		}

		return $service;
	}//end service()

	/**
	 * A failed transfer state.
	 *
	 * @param string $reason Why it failed.
	 * @param string $moment The moment.
	 *
	 * @return array<string, mixed> The state.
	 */
	private static function failed(string $reason, string $moment): array {
		return ['reference' => '', 'status' => self::STATUS_FAILED, 'reason' => $reason, 'requestedAt' => $moment, 'updatedAt' => $moment];
	}//end failed()

	/**
	 * The moment as an ATOM string in UTC.
	 *
	 * @param DateTimeImmutable|null $now The moment; now when null.
	 *
	 * @return string The moment.
	 */
	private static function moment(?DateTimeImmutable $now): string {
		return ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
	}//end moment()
}//end class
