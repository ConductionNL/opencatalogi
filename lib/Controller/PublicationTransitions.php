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
}//end class
