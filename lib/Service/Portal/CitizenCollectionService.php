<?php

/**
 * OpenCatalogi Citizen Collection Service.
 *
 * A resident's dossiers of Woo publications (hydra `woo-citizen-journey`,
 * contract C1). Every method takes the owner as the subject reference from
 * portaliq's verified assertion and compares it with the dossier's `owner`.
 * A mismatch and a missing id give the same PortalNotFoundException, so the
 * answer is 404 either way and nobody can probe which ids exist.
 *
 * What counts as public is decided per read, through PortalObjectStore's
 * anonymous publication read: the owner sees every item marked public or not,
 * the share link shows only the public ones.
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
 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-a-dossier-belongs-to-one-resident-and-answers-404-to-everyone-else-req-ccol-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Portal;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\OpenCatalogi\Exception\PortalInputException;
use OCA\OpenCatalogi\Exception\PortalNotFoundException;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads and changes a resident's dossiers.
 *
 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-a-dossier-belongs-to-one-resident-and-answers-404-to-everyone-else-req-ccol-001
 */
class CitizenCollectionService {

	/**
	 * The dossier schema.
	 */
	public const SCHEMA = 'collection';

	/**
	 * The saved-search schema, removed with the account too.
	 */
	public const SAVED_SEARCH_SCHEMA = 'savedSearch';

	/**
	 * The most dossiers one resident keeps.
	 */
	public const MAX_DOSSIERS = 50;

	/**
	 * The most items one dossier holds (the schema's maxItems).
	 */
	public const MAX_ITEMS = 500;

	/**
	 * Text limits, the schema's own.
	 */
	private const MAX_TITLE = 200;

	private const MAX_NOTE = 2000;

	/**
	 * Constructor.
	 *
	 * @param PortalObjectStore $store  Reads and writes as the system.
	 * @param PublicationLinker $linker The public link to a publication.
	 * @param LoggerInterface   $logger The logger.
	 * @param IURLGenerator|null $urlGenerator Makes the share link absolute.
	 */
	public function __construct(
		private readonly PortalObjectStore $store,
		private readonly PublicationLinker $linker,
		private readonly LoggerInterface $logger,
		private readonly ?IURLGenerator $urlGenerator=null,
	) {

	}//end __construct()

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
	 * @throws PortalNotFoundException When the dossier or publication is not the owner's or not public.
	 * @throws PortalInputException    When the input is refused.
	 *
	 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-a-resident-adds-a-public-publication-or-one-of-its-documents-to-a-dossier-req-ccol-002
	 */
	public function addItem(string $owner, array $input): array {
		$collectionId = $this->text(value: ($input['collection'] ?? ''), max: 64, field: 'collection');
		$publicationId = $this->text(value: ($input['publication'] ?? ''), max: 64, field: 'publication');
		$attachment = $this->attachment(value: ($input['attachment'] ?? null));
		$note = $this->text(value: ($input['note'] ?? ''), max: self::MAX_NOTE, field: 'note');

		$dossier = null;
		if ($collectionId === '') {
			$title = $this->text(value: ($input['title'] ?? ''), max: self::MAX_TITLE, field: 'title');
			if ($title === '') {
				throw new PortalInputException('A new dossier needs a title');
			}

			if (count($this->store->findByOwner(schema: self::SCHEMA, owner: $owner)) >= self::MAX_DOSSIERS) {
				throw new PortalInputException('You have the most dossiers you can have');
			}

			$dossier = ['title' => $title, 'description' => '', 'owner' => $owner, 'items' => [], 'share' => null, 'sourceOf' => []];
		} else {
			$dossier = $this->owned(owner: $owner, collectionId: $collectionId);
		}

		if ($publicationId === '') {
			throw new PortalInputException('A publication is needed');
		}

		$publication = $this->store->publicPublication(id: $publicationId);
		if ($publication === null) {
			throw new PortalNotFoundException('Not found');
		}

		$items = (array)($dossier['items'] ?? []);
		foreach ($items as $item) {
			if (($item['publication'] ?? '') === $publicationId && ($item['attachment'] ?? null) === $attachment) {
				return $this->ownerView(dossier: $dossier);
			}
		}

		if (count($items) >= self::MAX_ITEMS) {
			throw new PortalInputException('This dossier is full');
		}

		$items[] = [
			'id' => $this->uuid(),
			'publication' => $publicationId,
			'attachment' => $attachment,
			'note' => $note,
			'addedAt' => $this->now(),
			'addedBy' => 'resident',
			'title' => mb_substr((string)($publication['title'] ?? ''), 0, 500),
		];
		$dossier['items'] = $items;

		return $this->ownerView(dossier: $this->store->save(schema: self::SCHEMA, data: $dossier, id: ($dossier['id'] ?? null)));

	}//end addItem()

	/**
	 * Remove one item from a dossier.
	 *
	 * @param string $owner        The subject reference.
	 * @param string $collectionId The dossier.
	 * @param string $itemId       The item.
	 *
	 * @return array<string, mixed> The owner's view.
	 *
	 * @throws PortalNotFoundException When the dossier or item is not found.
	 *
	 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-a-resident-removes-items-and-writes-notes-req-ccol-003
	 */
	public function removeItem(string $owner, string $collectionId, string $itemId): array {
		$dossier = $this->owned(owner: $owner, collectionId: $collectionId);
		$items = (array)($dossier['items'] ?? []);
		$kept = array_values(array_filter($items, static fn (array $item): bool => ($item['id'] ?? '') !== $itemId));
		if ($itemId === '' || count($kept) === count($items)) {
			throw new PortalNotFoundException('Not found');
		}

		$dossier['items'] = $kept;
		return $this->ownerView(dossier: $this->store->save(schema: self::SCHEMA, data: $dossier, id: $collectionId));

	}//end removeItem()

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
	 * @throws PortalNotFoundException When the dossier or item is not found.
	 * @throws PortalInputException    When the note is too long.
	 *
	 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-a-resident-removes-items-and-writes-notes-req-ccol-003
	 */
	public function note(string $owner, string $collectionId, string $itemId, string $note): array {
		$note = $this->text(value: $note, max: self::MAX_NOTE, field: 'note');
		$dossier = $this->owned(owner: $owner, collectionId: $collectionId);

		if ($itemId === '') {
			$dossier['description'] = $note;
			return $this->ownerView(dossier: $this->store->save(schema: self::SCHEMA, data: $dossier, id: $collectionId));
		}

		$found = false;
		$items = (array)($dossier['items'] ?? []);
		foreach ($items as $index => $item) {
			if (($item['id'] ?? '') === $itemId) {
				$items[$index]['note'] = $note;
				$found = true;
			}
		}

		if ($found === false) {
			throw new PortalNotFoundException('Not found');
		}

		$dossier['items'] = $items;
		return $this->ownerView(dossier: $this->store->save(schema: self::SCHEMA, data: $dossier, id: $collectionId));

	}//end note()

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
		$this->store->save(schema: self::SCHEMA, data: $dossier, id: $collectionId);

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
		$this->store->save(schema: self::SCHEMA, data: $dossier, id: $collectionId);

		return ['shared' => false];

	}//end unshare()

	/**
	 * Delete a dossier.
	 *
	 * @param string $owner        The subject reference.
	 * @param string $collectionId The dossier.
	 *
	 * @return array{deleted: bool}
	 *
	 * @throws PortalNotFoundException When the dossier is not the owner's.
	 *
	 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-a-resident-removes-items-and-writes-notes-req-ccol-003
	 */
	public function delete(string $owner, string $collectionId): array {
		$this->owned(owner: $owner, collectionId: $collectionId);
		return ['deleted' => $this->store->delete(schema: self::SCHEMA, id: $collectionId)];

	}//end delete()

	/**
	 * The owner's view of one dossier.
	 *
	 * @param string $owner        The subject reference.
	 * @param string $collectionId The dossier.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws PortalNotFoundException When the dossier is not the owner's.
	 *
	 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-the-owner-sees-a-depublished-item-as-no-longer-public-req-ccol-004
	 */
	public function view(string $owner, string $collectionId): array {
		return $this->ownerView(dossier: $this->owned(owner: $owner, collectionId: $collectionId));

	}//end view()

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
			throw new PortalNotFoundException('Not found');
		}

		$dossier = $this->store->find(schema: self::SCHEMA, id: $parts[0]);
		$stored = (string)($dossier['share']['token'] ?? '');
		if ($dossier === null || $stored === '' || hash_equals($stored, $token) === false) {
			throw new PortalNotFoundException('Not found');
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
	 * The items of a dossier for portaliq's `itemList`, without an owner check:
	 * portaliq calls it only after the resident's own scoped read succeeded.
	 *
	 * @param string $collectionId The dossier.
	 *
	 * @return array<int, array{id: string, title: string, url: string, note: string, public: bool, addedAt: string}>
	 *
	 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-opencatalogi-contributes-dossiers-to-the-portal-for-citizen-and-client-req-ccol-006
	 */
	public function itemList(string $collectionId): array {
		$dossier = $this->store->find(schema: self::SCHEMA, id: $collectionId);
		if ($dossier === null) {
			return [];
		}

		$items = [];
		foreach ($this->ownerView(dossier: $dossier)['items'] as $item) {
			$items[] = [
				'id' => $item['id'],
				'title' => $item['title'],
				'url' => $item['url'],
				'note' => $item['note'],
				'public' => $item['public'],
				'addedAt' => $item['addedAt'],
			];
		}

		return $items;

	}//end itemList()

	/**
	 * Delete every dossier and saved search of one resident.
	 *
	 * @param string $owner The subject reference of the removed account.
	 *
	 * @return int How many objects were deleted.
	 *
	 * @spec openspec/changes/citizen-collections/specs/citizen-collections/spec.md#requirement-removing-a-portal-account-deletes-that-residents-dossiers-req-ccol-007
	 */
	public function removeEverythingOf(string $owner): int {
		if ($owner === '') {
			return 0;
		}

		$deleted = 0;
		foreach ([self::SCHEMA, self::SAVED_SEARCH_SCHEMA] as $schema) {
			foreach ($this->store->findByOwner(schema: $schema, owner: $owner) as $row) {
				$id = (string)($row['id'] ?? '');
				if ($id === '' || ($row['owner'] ?? null) !== $owner) {
					continue;
				}

				try {
					if ($this->store->delete(schema: $schema, id: $id) === true) {
						$deleted++;
					}
				} catch (Throwable $e) {
					$this->logger->warning('OpenCatalogi: could not delete a removed resident\'s object', ['schema' => $schema, 'reason' => $e->getMessage()]);
				}
			}
		}

		return $deleted;

	}//end removeEverythingOf()

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
		$dossier = $this->store->find(schema: self::SCHEMA, id: $collectionId);
		if ($owner === '' || $dossier === null || ($dossier['owner'] ?? null) !== $owner) {
			throw new PortalNotFoundException('Not found');
		}

		$dossier['id'] = $collectionId;
		return $dossier;

	}//end owned()

	/**
	 * The owner's view: every item, each marked public or not right now.
	 *
	 * @param array<string, mixed> $dossier The stored dossier.
	 *
	 * @return array<string, mixed>
	 */
	private function ownerView(array $dossier): array {
		$items = [];
		foreach ((array)($dossier['items'] ?? []) as $item) {
			$publicationId = (string)($item['publication'] ?? '');
			$publication = $this->store->publicPublication(id: $publicationId);
			$items[] = [
				'id' => (string)($item['id'] ?? ''),
				'publication' => $publicationId,
				'attachment' => ($item['attachment'] ?? null),
				'note' => (string)($item['note'] ?? ''),
				'addedAt' => (string)($item['addedAt'] ?? ''),
				'addedBy' => (string)($item['addedBy'] ?? ''),
				'title' => (string)($item['title'] ?? ($publication['title'] ?? '')),
				'url' => $this->linker->url(id: $publicationId),
				'public' => $publication !== null,
			];
		}

		return [
			'id' => (string)($dossier['id'] ?? ''),
			'title' => (string)($dossier['title'] ?? ''),
			'description' => (string)($dossier['description'] ?? ''),
			'share' => ($dossier['share'] ?? null),
			'sourceOf' => array_values((array)($dossier['sourceOf'] ?? [])),
			'items' => $items,
		];

	}//end ownerView()

	/**
	 * A trimmed string within a length, or a refusal.
	 *
	 * @param mixed  $value The value.
	 * @param int    $max   The most characters.
	 * @param string $field The field, for the message.
	 *
	 * @return string
	 *
	 * @throws PortalInputException When it is not a string or too long.
	 */
	private function text(mixed $value, int $max, string $field): string {
		if ($value === null) {
			return '';
		}

		if (is_string($value) === false) {
			throw new PortalInputException($field.' must be text');
		}

		$value = trim($value);
		if (mb_strlen($value) > $max) {
			throw new PortalInputException($field.' is too long');
		}

		return $value;

	}//end text()

	/**
	 * A Nextcloud file id, or null for the whole publication.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null
	 *
	 * @throws PortalInputException When it is not a file id.
	 */
	private function attachment(mixed $value): ?string {
		if ($value === null || $value === '') {
			return null;
		}

		$value = (string)(is_int($value) === true ? $value : (is_string($value) === true ? $value : ''));
		if (preg_match('/^\d{1,20}$/', $value) !== 1) {
			throw new PortalInputException('attachment must be a file id');
		}

		return $value;

	}//end attachment()

	/**
	 * The current moment, ISO 8601 in UTC.
	 *
	 * @return string
	 */
	private function now(): string {
		return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);

	}//end now()

	/**
	 * A random version 4 uuid.
	 *
	 * @return string
	 */
	private function uuid(): string {
		$bytes = random_bytes(16);
		$bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
		$bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));

	}//end uuid()
}//end class
