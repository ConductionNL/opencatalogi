<?php

/**
 * Contract of CollectionShareService, for callers in other components.
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
 * Contract of CollectionShareService, for callers in other components.
 *
 * Callers outside Service/ depend on this interface instead of on the class.
 */
interface CollectionShareServiceInterface {
	/**
	 * Make a new read-only link. An earlier link stops working.
	 *
	 * Answers `url`, the JSON path, and `link`, the absolute address of the
	 * page on the portal site that shows the shared dossier.
	 *
	 * @param string $owner        The subject reference.
	 * @param string $collectionId The dossier.
	 *
	 * @return array{token: string, createdAt: string, url: string, link: string}
	 *
	 * @throws \OCA\OpenCatalogi\Exception\PortalNotFoundException When the dossier is not the owner's.
	 *
	 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-a-shared-dossier-shows-only-what-is-public-now-and-a-revoked-link-answers-404-req-ccol-005
	 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-the-share-link-opens-a-page-on-the-portal-site-req-ccol-009
	 */
	public function share(string $owner, string $collectionId): array;

	/**
	 * Revoke the read-only link.
	 *
	 * @param string $owner        The subject reference.
	 * @param string $collectionId The dossier.
	 *
	 * @return array{shared: bool}
	 *
	 * @throws \OCA\OpenCatalogi\Exception\PortalNotFoundException When the dossier is not the owner's.
	 *
	 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-a-shared-dossier-shows-only-what-is-public-now-and-a-revoked-link-answers-404-req-ccol-005
	 */
	public function unshare(string $owner, string $collectionId): array;

	/**
	 * The shared view: title, description and the items public right now.
	 *
	 * @param string $token The share token.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws \OCA\OpenCatalogi\Exception\PortalNotFoundException When the token is not a live share.
	 *
	 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-a-shared-dossier-shows-only-what-is-public-now-and-a-revoked-link-answers-404-req-ccol-005
	 */
	public function shared(string $token): array;
}//end interface
