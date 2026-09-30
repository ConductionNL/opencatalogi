<?php

/**
 * OpenCatalogi Collection Share Service.
 *
 * The read-only share link of a resident's dossier (hydra
 * `woo-citizen-journey`, C1). The owner makes and revokes the link; anyone
 * with it reads the title, the description and the items that are public at
 * that moment, never the owner. The token is the dossier id, a dot and 192
 * random bits; it is compared in full with `hash_equals`.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Portal
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @version GIT: <git_id>
 * @link https://www.OpenCatalogi.nl
 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-a-shared-dossier-shows-only-what-is-public-now-and-a-revoked-link-answers-404-req-ccol-005
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Portal;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\OpenCatalogi\Exception\PortalNotFoundException;
use OCP\IURLGenerator;

/**
 * Makes, revokes and reads the share link of a dossier.
 *
 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-a-shared-dossier-shows-only-what-is-public-now-and-a-revoked-link-answers-404-req-ccol-005
 */
class CollectionShareService {

	/**
	 * Constructor.
	 *
	 * @param PortalObjectStore  $store        Reads and writes as the system.
	 * @param PublicationLinker  $linker       The public link to a publication.
	 * @param IURLGenerator|null $urlGenerator Makes the share link absolute.
	 */
	public function __construct(
		private readonly PortalObjectStore $store,
		private readonly PublicationLinker $linker,
		private readonly ?IURLGenerator $urlGenerator=null,
	) {

	}//end __construct()

	/**
	 * Make a new read-only link. An earlier link stops working.
	 *
	 * @param string $owner        The subject reference.
	 * @param string $collectionId The dossier.
	 *
	 * @return array{token: string, createdAt: string, url: string, link: string}
	 *
	 * @throws PortalNotFoundException When the dossier is not the owner's.
	 *
	 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-a-shared-dossier-shows-only-what-is-public-now-and-a-revoked-link-answers-404-req-ccol-005
	 */
	public function share(string $owner, string $collectionId): array {
		$dossier = $this->owned(owner: $owner, collectionId: $collectionId);
		$share = ['token' => $collectionId.'.'.bin2hex(random_bytes(24)), 'createdAt' => $this->now()];
		$dossier['share'] = $share;
		$this->store->save(schema: CitizenCollectionService::SCHEMA, data: $dossier, id: $collectionId);

		$path = '/index.php/apps/opencatalogi/api/collections/shared/'.$share['token'];
		$link = $path;
		if ($this->urlGenerator !== null) {
			$link = $this->urlGenerator->getAbsoluteURL($path);
		}

		return $share + ['url' => $path, 'link' => $link];

	}//end share()

	/**
	 * Revoke the read-only link.
	 *
	 * @param string $owner        The subject reference.
	 * @param string $collectionId The dossier.
	 *
	 * @return array{shared: bool}
	 *
	 * @throws PortalNotFoundException When the dossier is not the owner's.
	 *
	 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-a-shared-dossier-shows-only-what-is-public-now-and-a-revoked-link-answers-404-req-ccol-005
	 */
	public function unshare(string $owner, string $collectionId): array {
		$dossier = $this->owned(owner: $owner, collectionId: $collectionId);
		$dossier['share'] = null;
		$this->store->save(schema: CitizenCollectionService::SCHEMA, data: $dossier, id: $collectionId);

		return ['shared' => false];

	}//end unshare()

	/**
	 * The shared view: title, description and the items public right now.
	 *
	 * @param string $token The share token.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws PortalNotFoundException When the token is not a live share.
	 *
	 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-a-shared-dossier-shows-only-what-is-public-now-and-a-revoked-link-answers-404-req-ccol-005
	 */
	public function shared(string $token): array {
		$parts = explode('.', $token, 2);
		if (count($parts) !== 2 || preg_match('/^[0-9a-f]{48}$/', $parts[1]) !== 1) {
			throw new PortalNotFoundException(message: 'Not found');
		}

		$dossier = $this->store->find(schema: CitizenCollectionService::SCHEMA, id: $parts[0]);
		$stored = (string)($dossier['share']['token'] ?? '');
		if ($dossier === null || $stored === '' || hash_equals($stored, $token) === false) {
			throw new PortalNotFoundException(message: 'Not found');
		}

		$items = [];
		foreach ((array)($dossier['items'] ?? []) as $item) {
			$publication = $this->store->publicPublication(id: (string)($item['publication'] ?? ''));
			if ($publication === null) {
				continue;
			}

			$items[] = [
				'id' => (string)($item['id'] ?? ''),
				'publication' => (string)$item['publication'],
				'attachment' => ($item['attachment'] ?? null),
				'note' => (string)($item['note'] ?? ''),
				'addedAt' => (string)($item['addedAt'] ?? ''),
				'title' => (string)($publication['title'] ?? ($item['title'] ?? '')),
				'url' => $this->linker->url(id: (string)$item['publication']),
			];
		}

		return [
			'title' => (string)($dossier['title'] ?? ''),
			'description' => (string)($dossier['description'] ?? ''),
			'items' => $items,
		];

	}//end shared()

	/**
	 * The dossier when this owner owns it.
	 *
	 * @param string $owner        The subject reference.
	 * @param string $collectionId The dossier.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws PortalNotFoundException Otherwise.
	 */
	private function owned(string $owner, string $collectionId): array {
		$dossier = $this->store->find(schema: CitizenCollectionService::SCHEMA, id: $collectionId);
		if ($owner === '' || $dossier === null || ($dossier['owner'] ?? null) !== $owner) {
			throw new PortalNotFoundException(message: 'Not found');
		}

		return $dossier;

	}//end owned()

	/**
	 * The current moment, ISO 8601 in UTC.
	 *
	 * @return string
	 */
	private function now(): string {
		return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);

	}//end now()
}//end class
