<?php

/**
 * Contract of CitizenCollectionService, for callers in other components.
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
 * Contract of CitizenCollectionService, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 */
interface CitizenCollectionServiceInterface {
	/**
	 * Add a public publication, or one of its documents, to a dossier.
	 *
	 * Without `collection` a new dossier with `title` is made. The same
	 * publication and document twice is kept once.
	 *
	 * @param string               $owner The subject reference.
	 * @param array<string, mixed> $input collection, title, publication, attachment, note.
	 *
	 * @return array<string, mixed> The owner's view of the dossier.
	 *
	 * @throws \OCA\OpenCatalogi\Exception\PortalNotFoundException When the dossier or publication is not the owner's or not public.
	 * @throws \OCA\OpenCatalogi\Exception\PortalInputException When the input is refused.
	 *
	 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-a-resident-adds-a-public-publication-or-one-of-its-documents-to-a-dossier-req-ccol-002
	 */
	public function addItem(string $owner, array $input): array;

	/**
	 * Remove one item from a dossier.
	 *
	 * @param string $owner        The subject reference.
	 * @param string $collectionId The dossier.
	 * @param string $itemId       The item.
	 *
	 * @return array<string, mixed> The owner's view.
	 *
	 * @throws \OCA\OpenCatalogi\Exception\PortalNotFoundException When the dossier or item is not found.
	 *
	 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-a-resident-removes-items-and-writes-notes-req-ccol-003
	 */
	public function removeItem(string $owner, string $collectionId, string $itemId): array;

	/**
	 * Write the note of one item, or of the dossier when no item is named.
	 *
	 * @param string $owner        The subject reference.
	 * @param string $collectionId The dossier.
	 * @param string $itemId       The item, or '' for the dossier.
	 * @param string $note         The note.
	 *
	 * @return array<string, mixed> The owner's view.
	 *
	 * @throws \OCA\OpenCatalogi\Exception\PortalNotFoundException When the dossier or item is not found.
	 * @throws \OCA\OpenCatalogi\Exception\PortalInputException When the note is too long.
	 *
	 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-a-resident-removes-items-and-writes-notes-req-ccol-003
	 */
	public function note(string $owner, string $collectionId, string $itemId, string $note): array;

	/**
	 * Delete a dossier.
	 *
	 * @param string $owner        The subject reference.
	 * @param string $collectionId The dossier.
	 *
	 * @return array{deleted: bool}
	 *
	 * @throws \OCA\OpenCatalogi\Exception\PortalNotFoundException When the dossier is not the owner's.
	 *
	 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-a-resident-removes-items-and-writes-notes-req-ccol-003
	 */
	public function delete(string $owner, string $collectionId): array;

	/**
	 * The owner's view of one dossier.
	 *
	 * @param string $owner        The subject reference.
	 * @param string $collectionId The dossier.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws \OCA\OpenCatalogi\Exception\PortalNotFoundException When the dossier is not the owner's.
	 *
	 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-the-owner-sees-a-depublished-item-as-no-longer-public-req-ccol-004
	 */
	public function view(string $owner, string $collectionId): array;

	/**
	 * The items of a dossier for portaliq's `itemList`, without an owner check:
	 * portaliq calls it only after the resident's own scoped read succeeded.
	 *
	 * @param string $collectionId The dossier.
	 *
	 * @return array<int, array{id: string, title: string, url: string, note: string, public: bool, addedAt: string}>
	 *
	 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-opencatalogi-contributes-dossiers-to-the-portal-for-citizen-and-client-req-ccol-006
	 */
	public function itemList(string $collectionId): array;

	/**
	 * Delete every dossier and saved search of one resident.
	 *
	 * @param string $owner The subject reference of the removed account.
	 *
	 * @return int How many objects were deleted.
	 *
	 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-removing-a-portal-account-deletes-that-residents-dossiers-req-ccol-007
	 */
	public function removeEverythingOf(string $owner): int;
}//end interface
