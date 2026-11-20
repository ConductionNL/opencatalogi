<?php

/**
 * A document of a publication batch as the public will see it.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Review
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
 * @spec openspec/changes/woo-review-surface/specs/woo-transparency/spec.md#requirement-the-reviewer-sees-each-document-as-the-public-will-req-wrv-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Review;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\OpenCatalogi\Service\Woo\BatchPublicationWriter;
use OCP\IURLGenerator;

/**
 * What the public gets of one document: the file that will be published and
 * the publication fields the public read returns.
 *
 * @spec openspec/changes/woo-review-surface/specs/woo-transparency/spec.md#requirement-the-reviewer-sees-each-document-as-the-public-will-req-wrv-001
 */
class PublicView {

	/**
	 * The assessments whose document is published, and so previewed.
	 *
	 * @var array<int, string>
	 */
	public const PREVIEWED = ['openbaar', 'deels_openbaar'];

	/**
	 * The publication fields the public read returns.
	 *
	 * @var array<int, string>
	 */
	public const PUBLIC_FIELDS = ['title', 'wooCategory', 'publicationKind', 'publicationDate', 'caseReference'];

	/**
	 * Constructor.
	 *
	 * @param BatchPublicationWriter $writer The publication a batch becomes, and its files.
	 * @param IURLGenerator          $urls   Links to the files.
	 */
	public function __construct(
		private readonly BatchPublicationWriter $writer,
		private readonly IURLGenerator $urls,
	) {
	}//end __construct()

	/**
	 * One document as the public will see it.
	 *
	 * The assessment, a link to the file that will be published (the verified
	 * redacted file of a partly public document, the original of a public one,
	 * none for a withheld one) and the publication metadata as the public read
	 * returns it. The redaction log of a partly public document comes along for
	 * the reviewer; this answer is never public.
	 *
	 * @param string               $documentId The assessment uuid.
	 * @param array<string, mixed> $assessment The assessment.
	 * @param array<string, mixed> $batch      The batch.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/woo-review-surface/specs/woo-transparency/spec.md#requirement-the-reviewer-sees-each-document-as-the-public-will-req-wrv-001
	 * @spec openspec/changes/woo-review-surface/specs/woo-transparency/spec.md#requirement-the-redaction-log-records-what-was-removed-and-on-which-ground-req-wrv-003
	 */
	public function document(string $documentId, array $assessment, array $batch): array {
		$kind = (string)($assessment['assessment'] ?? '');
		$title = (string)($assessment['fileName'] ?? '');
		$document = [
			'documentId' => $documentId,
			'title' => $title,
			'assessment' => $kind,
			'viewerUrl' => $this->viewerUrl(assessment: $assessment, owner: (string)($batch['createdBy'] ?? '')),
			'publicMetadata' => null,
		];
		if (in_array($kind, self::PREVIEWED, true) === true) {
			$document['publicMetadata'] = array_merge($this->publicMetadata(batch: $batch), ['documentTitle' => $title]);
		}

		if ($kind === 'deels_openbaar') {
			$document['redactionLog'] = ($assessment['redactionLog'] ?? null);
		}

		if (empty($assessment['sentBackReason']) === false) {
			$document['sentBackReason'] = $assessment['sentBackReason'];
		}

		return $document;
	}//end document()

	/**
	 * The publication metadata as the public read returns it.
	 *
	 * @param array<string, mixed> $batch The batch.
	 *
	 * @return array<string, mixed>
	 */
	private function publicMetadata(array $batch): array {
		$now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);

		// The publish transition writes the publication date (REQ-PLC-003); the payload no longer carries it.
		$payload = $this->writer->payload(batch: $batch, now: $now) + ['publicationDate' => $now];
		$public  = [];
		foreach (self::PUBLIC_FIELDS as $field) {
			if (array_key_exists($field, $payload) === true) {
				$public[$field] = $payload[$field];
			}
		}

		return $public;
	}//end publicMetadata()

	/**
	 * A link to the file a document is published as, or null when none is.
	 *
	 * @param array<string, mixed> $assessment The assessment.
	 * @param string               $owner      The batch's creator, for a relative path.
	 *
	 * @return string|null
	 */
	private function viewerUrl(array $assessment, string $owner): ?string {
		$kind = (string)($assessment['assessment'] ?? '');
		$reference = '';
		if ($kind === 'openbaar') {
			$reference = (string)($assessment['documentReference'] ?? '');
		}

		// Only the verified redacted file is published, never the original.
		if ($kind === 'deels_openbaar' && (string)($assessment['redactionStatus'] ?? '') === 'verified') {
			$reference = (string)($assessment['anonymizedDocument'] ?? '');
		}

		$file = $this->writer->resolve(reference: $reference, owner: $owner);
		if ($file === null) {
			return null;
		}

		return $this->urls->linkToRoute('files.View.showFile', ['fileid' => $file->getId()]);
	}//end viewerUrl()
}//end class
