<?php

/**
 * Publication state moves and withdrawals, for the publication state controller.
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
 * @spec openspec/specs/publications/spec.md#requirement-an-editor-publishes-or-withdraws-a-publication-in-one-action-req-ppw-002
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Controller;

use OCA\OpenCatalogi\Contract\DepublicationServiceInterface;
use OCA\OpenCatalogi\Contract\PublicationStateServiceInterface;
use OCA\OpenCatalogi\Service\Publication\CompletenessSweepService;
use Psr\Container\ContainerInterface;

/**
 * The visibility state of a publication and the record each move stores, the
 * withdrawals sent to the channels, a file's withdrawal, and the register pairs
 * and ObjectService the moves are stored through.
 *
 * @spec openspec/specs/publications/spec.md#requirement-an-editor-publishes-or-withdraws-a-publication-in-one-action-req-ppw-002
 */
class PublicationTransitions {
	use ForwardsRegisterAccess;

	/**
	 * Constructor.
	 *
	 * @param PublicationStateServiceInterface $states The visibility state and the record per move.
	 * @param DepublicationServiceInterface $depublisher Sends the withdrawals.
	 * @param ContainerInterface $container Server container, for OpenRegister's file service.
	 * @param RegisterAccess $registers The configured register pairs and the ObjectService.
	 *
	 * @spec exclude pure framework plumbing: constructor dependency injection.
	 */
	public function __construct(
		private readonly PublicationStateServiceInterface $states,
		private readonly DepublicationServiceInterface $depublisher,
		private readonly ContainerInterface $container,
		private readonly RegisterAccess $registers,
	) {

	}//end __construct()

	/**
	 * Whether a publication is a draft, scheduled, public, withdrawn or archived.
	 *
	 * @param array $publication The publication.
	 *
	 * @return string The state.
	 *
	 * @spec openspec/specs/publications/spec.md#requirement-an-editor-publishes-or-withdraws-a-publication-in-one-action-req-ppw-002
	 */
	public function stateOf(array $publication): string {
		return $this->states->stateOf(publication: $publication);
	}//end stateOf()

	/**
	 * The publication as it is stored once published.
	 *
	 * @param array $publication The publication.
	 *
	 * @return array The record to store.
	 *
	 * @spec openspec/specs/publications/spec.md#requirement-an-editor-publishes-or-withdraws-a-publication-in-one-action-req-ppw-002
	 */
	public function publishedRecord(array $publication): array {
		return $this->states->publishedRecord(publication: $publication);
	}//end publishedRecord()

	/**
	 * The publication as it is stored once withdrawn.
	 *
	 * @param array $publication The publication.
	 *
	 * @return array The record to store.
	 *
	 * @spec openspec/specs/publications/spec.md#requirement-an-editor-publishes-or-withdraws-a-publication-in-one-action-req-ppw-002
	 */
	public function withdrawnRecord(array $publication): array {
		return $this->states->withdrawnRecord(publication: $publication);
	}//end withdrawnRecord()

	/**
	 * The channels a publication reached.
	 *
	 * @param array $publication The publication.
	 *
	 * @return array The channels.
	 *
	 * @spec openspec/specs/publications/spec.md#requirement-an-editor-publishes-or-withdraws-a-publication-in-one-action-req-ppw-002
	 */
	public function channelsReached(array $publication): array {
		return $this->states->channelsReached(publication: $publication);
	}//end channelsReached()

	/**
	 * A depublication as it is stored.
	 *
	 * @param array $depublication The depublication.
	 *
	 * @return array The record to store.
	 *
	 * @spec openspec/specs/publications/spec.md#requirement-an-editor-publishes-or-withdraws-a-publication-in-one-action-req-ppw-002
	 */
	public function storable(array $depublication): array {
		return $this->states->storable(depublication: $depublication);
	}//end storable()

	/**
	 * Send the withdrawals of a publication.
	 *
	 * @param array{publication: array, reason: string, depublishedBy: string, channels: array} $withdrawal The withdrawal.
	 *
	 * @return array The depublication.
	 *
	 * @spec openspec/specs/publications/spec.md#requirement-an-editor-publishes-or-withdraws-a-publication-in-one-action-req-ppw-002
	 */
	public function depublish(array $withdrawal): array {
		return $this->depublisher->depublish(
			publication: $withdrawal['publication'],
			reason: $withdrawal['reason'],
			depublishedBy: $withdrawal['depublishedBy'],
			channels: $withdrawal['channels']
		);

	}//end depublish()

	/**
	 * Record one document of a publication coming down; the publication is not frozen.
	 *
	 * @param array{publication: array, reason: string, depublishedBy: string} $withdrawal The withdrawal.
	 *
	 * @return array The depublication.
	 *
	 * @spec openspec/changes/publication-withdrawal-aftercare/tasks.md#task-3.2
	 */
	public function depublishDocument(array $withdrawal): array {
		return $this->depublisher->depublishDocument(
			publication: $withdrawal['publication'],
			reason: $withdrawal['reason'],
			depublishedBy: $withdrawal['depublishedBy']
		);
	}//end depublishDocument()

	/**
	 * The channels a depublication has not reached yet.
	 *
	 * @param array $depublication The depublication.
	 *
	 * @return array The channels.
	 *
	 * @spec openspec/specs/publications/spec.md#requirement-an-editor-publishes-or-withdraws-a-publication-in-one-action-req-ppw-002
	 */
	public function outstandingChannels(array $depublication): array {
		return $this->depublisher->outstandingChannels(depublication: $depublication);
	}//end outstandingChannels()

	/**
	 * Withdraw one file of a publication through OpenRegister's file service.
	 *
	 * @param mixed $publication The publication object.
	 * @param int $fileId The file id.
	 *
	 * @return string|null Null when the file was withdrawn, else why it was not.
	 *
	 * @spec openspec/specs/publications/spec.md#requirement-an-editor-publishes-or-withdraws-a-publication-in-one-action-req-ppw-002
	 */
	public function unpublishFile(mixed $publication, int $fileId): ?string {
		try {
			$this->container->get('OCA\OpenRegister\Service\FileService')->unpublishFile(object: $publication, filePath: $fileId);
		} catch (\Throwable $e) {
			return $e->getMessage();
		}

		return null;
	}//end unpublishFile()

	/**
	 * End a file's own publication window now, keeping its start.
	 *
	 * The withdrawal of one attachment is recorded on the file itself
	 * (OpenRegister's file publication window), so no automatic path shares
	 * it again. An OpenRegister without file publication windows is left as
	 * it was.
	 *
	 * @param int $fileId The file id.
	 *
	 * @return string|null Null when the window ended (or there is none to end), else why it did not.
	 *
	 * @spec openspec/changes/attachments-are-files/specs/publication-attachments/spec.md#requirement-a-withdrawn-attachment-carries-its-withdrawal-on-the-file-req-att-103
	 */
	public function endFileWindow(int $fileId): ?string {
		try {
			$files = $this->container->get('OCA\OpenRegister\Files\Db\FileMapper');
			if (is_object($files) === false || method_exists($files, 'setPublicationWindowForFile') === false) {
				return null;
			}

			$current = ($files->findByFileIds([$fileId])[$fileId] ?? null);
			$window = 'OCA\OpenRegister\Files\Db\PublicationWindow';
			$files->setPublicationWindowForFile($fileId, new $window($current?->getPublished(), new \DateTime()));
		} catch (\Throwable $e) {
			return $e->getMessage();
		}

		return null;
	}//end endFileWindow()

	/**
	 * Re-evaluate a publication's completeness after one of its files was withdrawn.
	 *
	 * Withdrawing a file writes no publication, so no save evaluates it. A
	 * failure is logged by the sweep service and leaves the stored value.
	 *
	 * @param string $id The publication id.
	 *
	 * @return string `changed`, `unchanged`, `failed` or `skipped`.
	 *
	 * @spec openspec/changes/publication-tells-its-source/specs/publications/spec.md#requirement-a-publication-records-whether-it-is-complete-and-public-req-pts-002
	 */
	public function reevaluateCompleteness(string $id): string {
		if ($this->container->has(CompletenessSweepService::class) === false) {
			return 'skipped';
		}

		return $this->container->get(CompletenessSweepService::class)->reevaluate(id: $id);
	}//end reevaluateCompleteness()

	/**
	 * Share a file of this publication again through OpenRegister's file service.
	 *
	 * The file service resolves the file on THIS publication, so a file of
	 * another object is refused here and nothing else changes.
	 *
	 * @param mixed $publication The publication the file belongs to.
	 * @param int   $fileId      The file id.
	 *
	 * @return string|null Null when the file is shared again, else why it is not.
	 *
	 * @spec openspec/changes/attachments-are-files/specs/publication-attachments/spec.md#requirement-a-withdrawn-attachment-stays-withdrawn-req-att-104
	 */
	public function publishFile(mixed $publication, int $fileId): ?string {
		try {
			$this->container->get('OCA\OpenRegister\Service\FileService')->publishFile(object: $publication, file: $fileId);
		} catch (\Throwable $e) {
			return $e->getMessage();
		}

		return null;
	}//end publishFile()

	/**
	 * Reopen a file's own publication window: clear its end, keep its start.
	 *
	 * An OpenRegister without file publication windows is left as it was.
	 *
	 * @param int $fileId The file id.
	 *
	 * @return string|null Null when the window is open again (or there is none), else why it is not.
	 *
	 * @spec openspec/changes/attachments-are-files/specs/publication-attachments/spec.md#requirement-a-withdrawn-attachment-stays-withdrawn-req-att-104
	 */
	public function reopenFileWindow(int $fileId): ?string {
		try {
			$files = $this->container->get('OCA\OpenRegister\Files\Db\FileMapper');
			if (is_object($files) === false || method_exists($files, 'setPublicationWindowForFile') === false) {
				return null;
			}

			$current = ($files->findByFileIds([$fileId])[$fileId] ?? null);
			$window = 'OCA\OpenRegister\Files\Db\PublicationWindow';
			$files->setPublicationWindowForFile($fileId, new $window($current?->getPublished(), null));
		} catch (\Throwable $e) {
			return $e->getMessage();
		}

		return null;
	}//end reopenFileWindow()

	/**
	 * The label a file's withdrawal reason is kept under, followed by the reason.
	 */
	public const WITHDRAWN_REASON_LABEL = 'withdrawn-reason:';

	/**
	 * The label the withdrawing editor is kept under, followed by their user id.
	 */
	public const WITHDRAWN_BY_LABEL = 'withdrawn-by:';

	/**
	 * Keep why and by whom a file was withdrawn in its OpenRegister labels.
	 *
	 * OpenRegister's file metadata is a description, a category and labels.
	 * The description and category are the publisher's, so the withdrawal is
	 * kept as two labels next to the labels the file already has, which stay.
	 * A later withdrawal replaces the earlier pair.
	 *
	 * @param int    $fileId The file id.
	 * @param string $reason Why the file is withdrawn.
	 * @param string $editor Who withdrew it.
	 *
	 * @return string|null Null when kept (or OpenRegister has no file metadata), else why not.
	 *
	 * @spec openspec/changes/attachments-are-files/specs/publication-attachments/spec.md#requirement-a-withdrawn-attachment-carries-its-withdrawal-on-the-file-req-att-103
	 */
	public function recordFileWithdrawal(int $fileId, string $reason, string $editor): ?string {
		return $this->writeWithdrawalLabels(
			fileId: $fileId,
			withdrawal: [self::WITHDRAWN_REASON_LABEL.$reason, self::WITHDRAWN_BY_LABEL.$editor]
		);
	}//end recordFileWithdrawal()

	/**
	 * Remove a file's withdrawal labels when it is put back; its other labels stay.
	 *
	 * @param int $fileId The file id.
	 *
	 * @return string|null Null when cleared (or there was nothing to clear), else why not.
	 *
	 * @spec openspec/changes/attachments-are-files/specs/publication-attachments/spec.md#requirement-a-withdrawn-attachment-stays-withdrawn-req-att-104
	 */
	public function clearFileWithdrawal(int $fileId): ?string {
		return $this->writeWithdrawalLabels(fileId: $fileId, withdrawal: []);
	}//end clearFileWithdrawal()

	/**
	 * Write a file's labels as its own labels without any withdrawal, plus these.
	 *
	 * Nothing is written when there is nothing to add and nothing to remove.
	 *
	 * @param int           $fileId     The file id.
	 * @param array<string> $withdrawal The withdrawal labels to add.
	 *
	 * @return string|null Null when written or not needed, else why it failed.
	 */
	private function writeWithdrawalLabels(int $fileId, array $withdrawal): ?string {
		$change = 'OCA\OpenRegister\Service\File\FileMetadataChange';
		try {
			$service = $this->container->get('OCA\OpenRegister\Service\FileService');
			$labels  = $this->currentLabels(fileId: $fileId);
			$kept    = array_values(array_filter($labels, fn (string $label): bool => $this->isWithdrawalLabel(label: $label) === false));
			if (method_exists($service, 'changeFileMetadata') === false || class_exists($change) === false
				|| ($withdrawal === [] && $kept === $labels)
			) {
				return null;
			}

			$service->changeFileMetadata(new $change(fileId: $fileId, labels: array_merge($kept, $withdrawal)));
		} catch (\Throwable $e) {
			return $e->getMessage();
		}

		return null;
	}//end writeWithdrawalLabels()

	/**
	 * A file's OpenRegister labels, as strings; none when it has no metadata row.
	 *
	 * @param int $fileId The file id.
	 *
	 * @return array<string> The labels.
	 */
	private function currentLabels(int $fileId): array {
		$current = ($this->container->get('OCA\OpenRegister\Files\Db\FileMapper')->findByFileIds([$fileId])[$fileId] ?? null);
		if (is_object($current) === false || is_callable([$current, 'getLabels']) === false) {
			return [];
		}

		return array_values(array_map('strval', array_filter((array)($current->getLabels() ?? []), 'is_scalar')));
	}//end currentLabels()

	/**
	 * Whether a label is one of the two a withdrawal is kept under.
	 *
	 * @param string $label The label.
	 *
	 * @return boolean
	 */
	private function isWithdrawalLabel(string $label): bool {
		return str_starts_with($label, self::WITHDRAWN_REASON_LABEL) || str_starts_with($label, self::WITHDRAWN_BY_LABEL);
	}//end isWithdrawalLabel()
}//end class
