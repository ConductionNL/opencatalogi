<?php

/**
 * The public facts of one document of a publication.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Publication
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
 * @spec openspec/changes/publication-detail-for-the-portal/specs/publications/spec.md#requirement-a-document-has-a-stable-id-and-its-own-metadata-req-pdp-002
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Publication;

use DateTimeImmutable;
use Throwable;

/**
 * Adds a stable document id and the stored metadata to a publication's attachments.
 *
 * The document id is the Nextcloud file id as a string, so a rename keeps it.
 * The document type is the file's own type first, else the publication's. The
 * creation date is the publication's stored `creationDate`. Neither is added
 * when nothing is stored.
 *
 * @spec openspec/changes/publication-detail-for-the-portal/specs/publications/spec.md#requirement-a-document-has-a-stable-id-and-its-own-metadata-req-pdp-002
 */
final class DocumentFacts {

	/**
	 * The keys one document answers on its own metadata route.
	 *
	 * @var list<string>
	 */
	public const PUBLIC_KEYS = [
		'documentId',
		'position',
		'title',
		'format',
		'size',
		'published',
		'downloadUrl',
		'documentsoort',
		'creationDate',
		'metadataUrl',
		'diwooUrl',
	];

	/**
	 * One attachment with its document id, format and stored metadata.
	 *
	 * @param array<string, mixed> $file        The attachment in the formatFiles shape.
	 * @param array<string, mixed> $publication The publication it belongs to.
	 *
	 * @return array<string, mixed> The attachment with `documentId`, `format` and, when stored, `documentsoort` and `creationDate`.
	 *
	 * @spec openspec/changes/publication-detail-for-the-portal/tasks.md#2.1
	 */
	public static function forFile(array $file, array $publication): array {
		$documentId = self::text(value: ($file['id'] ?? null));
		if ($documentId === null) {
			return $file;
		}

		$file['documentId'] = $documentId;
		$file['format'] = self::format(file: $file);

		$type = self::documentType(publication: $publication, documentId: $documentId);
		if ($type !== null) {
			$file['documentsoort'] = $type;
		}

		$created = self::text(value: ($publication['creationDate'] ?? null));
		if ($created !== null) {
			$file['creationDate'] = $created;
		}

		return $file;

	}//end forFile()

	/**
	 * Every attachment of a paged answer with its facts and, when given, its links.
	 *
	 * @param array<string, mixed>                                 $page        The paged answer (`results`).
	 * @param array<string, mixed>                                 $publication The publication.
	 * @param (callable(string): array<string, string>)|null $links       The links of one document id, or null for none.
	 *
	 * @return array<string, mixed> The answer.
	 *
	 * @spec openspec/changes/publication-detail-for-the-portal/tasks.md#2.1
	 */
	public static function onPage(array $page, array $publication, ?callable $links): array {
		if (is_array($page['results'] ?? null) === false) {
			return $page;
		}

		foreach ($page['results'] as $key => $file) {
			if (is_array($file) === false) {
				continue;
			}

			$file = self::forFile(file: $file, publication: $publication);
			if ($links !== null && isset($file['documentId']) === true) {
				$file = array_merge($file, $links($file['documentId']));
			}

			$page['results'][$key] = $file;
		}

		return $page;

	}//end onPage()

	/**
	 * Only the keys a document answers on its own route.
	 *
	 * @param array<string, mixed> $document The document with its facts.
	 *
	 * @return array<string, mixed> The public keys that are present.
	 *
	 * @spec openspec/changes/publication-detail-for-the-portal/tasks.md#2.1
	 */
	public static function publicView(array $document): array {
		return array_intersect_key($document, array_flip(self::PUBLIC_KEYS));

	}//end publicView()

	/**
	 * Whether a publication is published now: it has a publication moment in the past and no ended window.
	 *
	 * @param array<string, mixed> $publication The publication.
	 * @param DateTimeImmutable    $now         The moment.
	 *
	 * @return bool False for a draft, a future publication or a depublished one.
	 *
	 * @spec openspec/changes/publication-detail-for-the-portal/tasks.md#2.1
	 */
	public static function isLive(array $publication, DateTimeImmutable $now): bool {
		$self = $publication['@self'] ?? [];
		if (is_array($self) === false) {
			return false;
		}

		$published = self::moment(value: ($self['published'] ?? null));
		if ($published === null || $published > $now) {
			return false;
		}

		$depublished = self::moment(value: ($self['depublished'] ?? null));

		return $depublished === null || $depublished > $now;

	}//end isLive()

	/**
	 * Whether an attachment's own publication window has ended.
	 *
	 * @param array<string, mixed> $file The attachment.
	 * @param DateTimeImmutable    $now  The moment.
	 *
	 * @return bool True when its `depublished` moment is now or past.
	 *
	 * @spec openspec/changes/publication-detail-for-the-portal/tasks.md#2.1
	 */
	public static function isWithdrawn(array $file, DateTimeImmutable $now): bool {
		$end = self::moment(value: ($file['depublished'] ?? null));

		return $end !== null && $end <= $now;

	}//end isWithdrawn()

	/**
	 * The file's format: its extension in lower case, else its media type.
	 *
	 * @param array<string, mixed> $file The attachment.
	 *
	 * @return string|null The format, or null when neither is known.
	 */
	private static function format(array $file): ?string {
		$extension = self::text(value: ($file['extension'] ?? null));
		if ($extension !== null) {
			return strtolower($extension);
		}

		return self::text(value: ($file['type'] ?? null));

	}//end format()

	/**
	 * The stored document type of one file: its own, else the publication's.
	 *
	 * @param array<string, mixed> $publication The publication.
	 * @param string               $documentId  The file id.
	 *
	 * @return string|null The type, or null when none is stored.
	 */
	private static function documentType(array $publication, string $documentId): ?string {
		$perFile = $publication['fileDocumentsoorten'] ?? [];
		if (is_array($perFile) === true) {
			$own = self::text(value: ($perFile[$documentId] ?? null));
			if ($own !== null) {
				return $own;
			}
		}

		return self::text(value: ($publication['documentsoort'] ?? null));

	}//end documentType()

	/**
	 * A non-empty string or integer as a trimmed string.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null The text, or null when empty or of another type.
	 */
	private static function text(mixed $value): ?string {
		if (is_int($value) === true) {
			return (string) $value;
		}

		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		return trim($value);

	}//end text()

	/**
	 * A readable moment.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return DateTimeImmutable|null The moment, or null when absent or unreadable.
	 */
	private static function moment(mixed $value): ?DateTimeImmutable {
		$text = self::text(value: $value);
		if ($text === null) {
			return null;
		}

		try {
			return new DateTimeImmutable($text);
		} catch (Throwable) {
			return null;
		}

	}//end moment()
}//end class
