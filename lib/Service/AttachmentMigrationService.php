<?php

/**
 * Turns `document` objects into files on the publication they belong to.
 *
 * A `document` was never a thing in its own right. It is a wrapper around one
 * attached file, and everything it carries has a home elsewhere now:
 *
 * | document property                     | where it lands            |
 * | ------------------------------------- | ------------------------- |
 * | `filename`, `mimeType`                | the file itself           |
 * | `title`                               | a label on the file       |
 * | `description`, `summary`              | the file's description    |
 * | `publication`, `organization`         | the owning publication    |
 * | `publicationDate` / `depublicationDate` | the file's window       |
 *
 * The last row is why this could not be done before openregister's
 * `file-publication-window` change: `publishFile()` was a boolean, so a bijlage
 * could not be depublished on a date independently of its publication.
 *
 * There is a second reason, and it is the one that matters for search.
 * OpenRegister already resolves a file chunk to its OWNING object, so once the
 * file hangs from the publication a body-text hit resolves to the publication
 * directly. The schema widening added for WOO-517 exists only because the
 * attachment is a separate object outside the catalog's schema scope.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenCatalogi.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service;

use DateTime;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Migrate `document` objects onto the files of their publications.
 *
 * @spec openspec/changes/attachments-are-files/specs/publication-attachments/spec.md#requirement-an-attachment-is-a-file-on-the-publication-req-att-101
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class AttachmentMigrationService {

	/**
	 * The register the publication and document schemas live in.
	 */
	public const REGISTER_SLUG = 'publication';

	/**
	 * The schema being retired.
	 */
	public const DOCUMENT_SCHEMA_SLUG = 'document';

	/**
	 * The schema an attachment ends up hanging from.
	 */
	public const PUBLICATION_SCHEMA_SLUG = 'publication';

	/**
	 * Wire the container and the logger.
	 *
	 * @param ContainerInterface $container Container, so OpenRegister is resolved lazily.
	 * @param LoggerInterface    $logger    Logger.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/attachments-are-files/specs/publication-attachments/spec.md#requirement-an-attachment-is-a-file-on-the-publication-req-att-101
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The publication uuid a document row points at.
	 *
	 * The link was written three different ways over the schema's life: a bare
	 * uuid string, an object carrying `id`, and an object carrying only `slug`
	 * and `title`. All three are live on real data, so all three resolve here.
	 * A slug is returned as a slug and looked up separately, because a slug is
	 * not a uuid and treating one as the other silently finds nothing.
	 *
	 * @param mixed $link The `publication` property as stored.
	 *
	 * @return array{id: string|null, slug: string|null} What the link identifies.
	 *
	 * @spec openspec/changes/attachments-are-files/specs/publication-attachments/spec.md#requirement-an-attachment-is-a-file-on-the-publication-req-att-101
	 */
	public function readPublicationLink(mixed $link): array {
		$none = ['id' => null, 'slug' => null];

		if (is_string($link) === true) {
			$link = trim($link);
			if ($link === '') {
				return $none;
			}

			// A bare string is a uuid when it looks like one, and a slug when it
			// does not. Guessing wrong either way finds nothing, so it is worth
			// the check rather than trying one and falling back.
			if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $link) === 1) {
				return ['id' => $link, 'slug' => null];
			}

			return ['id' => null, 'slug' => $link];
		}

		if (is_array($link) === false) {
			return $none;
		}

		$id = trim((string)($link['id'] ?? ($link['uuid'] ?? '')));
		$slug = trim((string)($link['slug'] ?? ''));

		if ($id === '') {
			$id = null;
		}

		if ($slug === '') {
			$slug = null;
		}

		return ['id' => $id, 'slug' => $slug];
	}//end readPublicationLink()

	/**
	 * The file metadata a document's fields become.
	 *
	 * `summary` and `description` both land in the file's single description
	 * field, joined rather than one overwriting the other: they are two pieces
	 * of prose about the same attachment and dropping either loses text a
	 * publisher wrote.
	 *
	 * @param array<string, mixed> $document The document's fields.
	 *
	 * @return array{description: string|null, labels: array<int, string>, published: string|null, depublished: string|null}
	 *         The metadata to write onto the file.
	 *
	 * @spec openspec/changes/attachments-are-files/specs/publication-attachments/spec.md#requirement-the-documents-metadata-lands-on-the-file-req-att-102
	 */
	public function fileMetadataFor(array $document): array {
		$parts = [];
		foreach (['summary', 'description'] as $field) {
			$value = trim((string)($document[$field] ?? ''));
			if ($value !== '' && in_array($value, $parts, true) === false) {
				$parts[] = $value;
			}
		}

		$description = null;
		if ($parts !== []) {
			$description = implode("\n\n", $parts);
		}

		$labels = [];
		$title = trim((string)($document['title'] ?? ''));
		if ($title !== '') {
			$labels[] = $title;
		}

		return [
			'description' => $description,
			'labels' => $labels,
			'published' => $this->readDate(value: ($document['publicationDate'] ?? null)),
			'depublished' => $this->readDate(value: ($document['depublicationDate'] ?? null)),
		];
	}//end fileMetadataFor()

	/**
	 * Read a stored date, returning null for anything unusable.
	 *
	 * An unparseable date becomes null rather than "now". A window that starts
	 * at the migration's own runtime would publish every attachment the moment
	 * this command ran, which is the opposite of preserving what was set.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return string|null An ISO-8601 string, or null.
	 *
	 * @spec openspec/changes/attachments-are-files/specs/publication-attachments/spec.md#requirement-the-documents-metadata-lands-on-the-file-req-att-102
	 */
	public function readDate(mixed $value): ?string {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		try {
			return (new DateTime(trim($value)))->format('c');
		} catch (Throwable $e) {
			return null;
		}
	}//end readDate()

	/**
	 * Load the publication a document points at.
	 *
	 * @param object                                  $objectService The OpenRegister ObjectService.
	 * @param array{id: string|null, slug: string|null} $link        What the document's link identifies.
	 *
	 * @return object|null The publication, or null when it cannot be found.
	 *
	 * @spec openspec/changes/attachments-are-files/specs/publication-attachments/spec.md#requirement-an-attachment-is-a-file-on-the-publication-req-att-101
	 */
	public function findPublication(object $objectService, array $link): ?object {
		if ($link['id'] !== null) {
			try {
				return $objectService->find(id: $link['id'], _rbac: false, _multitenancy: false);
			} catch (Throwable $e) {
				// Fall through to the slug, which is the older link shape.
			}
		}

		if ($link['slug'] === null) {
			return null;
		}

		// The slug lives in the object's `@self` metadata, not in its data, and
		// a bare `slug` filter searches the data. Measured on a live instance:
		// four publications with matching `_slug` values, zero returned. The app
		// already reached this conclusion in
		// `PublicationQueryService::tryPublicationSlugLookup()` and scans client
		// side; this does the same rather than inventing a second answer.
		$matches = $objectService->searchObjectsBySlug(
			self::REGISTER_SLUG,
			self::PUBLICATION_SCHEMA_SLUG,
			['_limit' => 500],
			false,
			false
		);

		if (is_array($matches) === false) {
			return null;
		}

		foreach ($matches as $candidate) {
			if ($this->slugOf(row: $candidate) !== $link['slug']) {
				continue;
			}

			return $this->entityOf(row: $candidate, objectService: $objectService);
		}

		return null;
	}//end findPublication()

	/**
	 * The slug a row carries, from wherever it is stored.
	 *
	 * It lives in the `@self` metadata block, and older shapes also carry it at
	 * the top level. Reading only one of the two silently matches nothing.
	 *
	 * @param mixed $row The row.
	 *
	 * @return string The slug, or an empty string.
	 *
	 * @spec openspec/changes/attachments-are-files/specs/publication-attachments/spec.md#requirement-an-attachment-is-a-file-on-the-publication-req-att-101
	 */
	public function slugOf(mixed $row): string {
		$fields = $this->toFields(row: $row);
		$self = ($fields['@self'] ?? []);

		if (is_array($self) === true && trim((string)($self['slug'] ?? '')) !== '') {
			return trim((string)$self['slug']);
		}

		return trim((string)($fields['slug'] ?? ''));
	}//end slugOf()

	/**
	 * Load the document as an entity, which is what the file API takes.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $uuid          The document uuid.
	 *
	 * @return object The document entity.
	 *
	 * @spec openspec/changes/attachments-are-files/specs/publication-attachments/spec.md#requirement-an-attachment-is-a-file-on-the-publication-req-att-101
	 */
	public function documentEntity(object $objectService, string $uuid): object {
		// `occ` has no user session, so the default RBAC check runs as Anonymous
		// and refuses a schema whose read rules name a group. Measured: the
		// command listed a document and then could not load it, one line later.
		return $objectService->find(id: $uuid, _rbac: false, _multitenancy: false);
	}//end documentEntity()

	/**
	 * Resolve a search row to an entity.
	 *
	 * @param mixed  $row           The row as returned by the reader.
	 * @param object $objectService The OpenRegister ObjectService.
	 *
	 * @return object|null The entity, or null.
	 *
	 * @spec openspec/changes/attachments-are-files/specs/publication-attachments/spec.md#requirement-an-attachment-is-a-file-on-the-publication-req-att-101
	 */
	public function entityOf(mixed $row, object $objectService): ?object {
		if (is_object($row) === true && method_exists($row, 'getUuid') === true) {
			return $row;
		}

		$fields = $this->toFields(row: $row);
		$uuid = (string)($fields['uuid'] ?? '');
		if ($uuid === '') {
			return null;
		}

		try {
			return $objectService->find(id: $uuid, _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			return null;
		}
	}//end entityOf()

	/**
	 * Flatten an object row into a field map with the uuid resolved.
	 *
	 * @param mixed $row The row as the reader returned it.
	 *
	 * @return array<string, mixed> The fields.
	 *
	 * @spec openspec/changes/attachments-are-files/specs/publication-attachments/spec.md#requirement-an-attachment-is-a-file-on-the-publication-req-att-101
	 */
	public function toFields(mixed $row): array {
		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$row = $row->jsonSerialize();
		}

		if (is_array($row) === false) {
			return [];
		}

		$self = ($row['@self'] ?? []);
		$uuid = '';
		if (is_array($self) === true) {
			$uuid = (string)($self['uuid'] ?? ($self['id'] ?? ''));
		}

		if ($uuid === '') {
			$uuid = (string)($row['uuid'] ?? ($row['id'] ?? ''));
		}

		$row['uuid'] = $uuid;

		return $row;
	}//end toFields()
	/**
	 * Resolve an OpenRegister collaborator, or null when it is unavailable.
	 *
	 * Resolved lazily and behind a guard: a hard constructor dependency on an
	 * OpenRegister class means that removing that class fatals every write in
	 * this app, not just this migration.
	 *
	 * @param string $id The service identifier.
	 *
	 * @return object|null The service, or null.
	 *
	 * @spec openspec/changes/attachments-are-files/specs/publication-attachments/spec.md#requirement-an-attachment-is-a-file-on-the-publication-req-att-101
	 */
	public function openRegister(string $id): ?object {
		try {
			$service = $this->container->get($id);
		} catch (Throwable $e) {
			$this->logger->warning(
				'OpenCatalogi: attachment migration could not resolve an OpenRegister service',
				['service' => $id, 'error' => $e->getMessage()]
			);
			return null;
		}

		if (is_object($service) === false) {
			return null;
		}

		return $service;
	}//end openRegister()
}//end class
