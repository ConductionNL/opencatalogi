<?php

/**
 * OpenCatalogi Woo Document Redactor.
 *
 * Produces the redacted version of a partly public (`deels_openbaar`) Woo
 * document through OpenRegister's redaction pipeline, and verifies it before
 * it may be published. OpenCatalogi redacts nothing itself: detection, the
 * officer's per-finding skip decisions, the PDF/DOCX/ODT text replacement and
 * the strict residual check are all OpenRegister's (`FileService::
 * anonymizeDocument`, the same call `POST /api/files/{id}/anonymize` makes).
 *
 * Fail closed. Every path that does not end in a verified redacted file
 * returns `redactionStatus: failed` with an empty `anonymizedDocument`, so the
 * publish has nothing to attach and refuses. The original is never offered in
 * place of the redacted version.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service\Woo
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @version GIT: <git_id>
 * @link https://www.OpenCatalogi.nl
 * @spec openspec/changes/woo-redaction-pipeline/specs/woo-transparency/spec.md#requirement-a-partly-public-document-is-published-only-as-a-verified-redacted-version-req-wrp-001
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Woo;

use OCP\App\IAppManager;
use OCP\Files\File;
use OCP\IL10N;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Redacts a partly public Woo document through OpenRegister and verifies it.
 *
 * @spec openspec/changes/woo-redaction-pipeline/specs/woo-transparency/spec.md#requirement-a-partly-public-document-is-published-only-as-a-verified-redacted-version-req-wrp-001
 */
class DocumentRedactor {

	/**
	 * The redacted version exists and passed every check.
	 */
	public const VERIFIED = 'verified';

	/**
	 * No verified redacted version exists; the document cannot be published.
	 */
	public const FAILED = 'failed';

	/**
	 * Constructor.
	 *
	 * @param BatchPublicationWriter $writer     Resolves a document reference to a file.
	 * @param ContainerInterface     $container  Resolves OpenRegister's redaction services.
	 * @param IL10N                  $l10n       The officer-facing reasons.
	 * @param LoggerInterface        $logger     Failure diagnostics (never the document text).
	 * @param IAppManager|null       $appManager Whether OpenRegister is installed.
	 */
	public function __construct(
		private readonly BatchPublicationWriter $writer,
		private readonly ContainerInterface $container,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
		private readonly ?IAppManager $appManager=null,
	) {

	}//end __construct()

	/**
	 * The redaction fields an assessment carries after an officer's decision.
	 *
	 * A partly public document is redacted and verified now. Any other
	 * assessment drops a redaction an earlier one left behind.
	 *
	 * @param array<string, mixed> $assessment    The assessment, already carrying the new decision.
	 * @param string|null          $batchId       The batch, whose creator owns relative references.
	 * @param string               $fallbackOwner The acting officer, when the batch names no creator.
	 *
	 * @return array{anonymizedDocument: string, anonymizedDocumentHash: string, redactionStatus: string, redactionMessage: string}
	 *
	 * @spec openspec/changes/woo-redaction-pipeline/specs/woo-transparency/spec.md#requirement-a-partly-public-document-is-published-only-as-a-verified-redacted-version-req-wrp-001
	 */
	public function forAssessment(array $assessment, ?string $batchId, string $fallbackOwner): array {
		if ((string)($assessment['assessment'] ?? '') !== 'deels_openbaar') {
			return [
				'anonymizedDocument' => '',
				'anonymizedDocumentHash' => '',
				'redactionStatus' => '',
				'redactionMessage' => '',
			];
		}

		return $this->redact(assessment: $assessment, owner: $this->ownerOf(batchId: $batchId, fallback: $fallbackOwner));

	}//end forAssessment()

	/**
	 * Every partly public document without a verified redacted version, with
	 * the reason, so the officer sees why it cannot be published yet.
	 *
	 * @param array<int, array<string, mixed>> $assessments The batch's assessments.
	 *
	 * @return array<int, array{fileName: string, reason: string}>
	 *
	 * @spec openspec/changes/woo-redaction-pipeline/specs/woo-transparency/spec.md#requirement-the-officer-sees-why-a-partly-public-document-cannot-be-published-req-wrp-002
	 */
	public function unredacted(array $assessments): array {
		$out = [];
		foreach ($assessments as $assessment) {
			if ((string)($assessment['assessment'] ?? '') !== 'deels_openbaar'
				|| (string)($assessment['redactionStatus'] ?? '') === self::VERIFIED
			) {
				continue;
			}

			$reason = (string)($assessment['redactionMessage'] ?? '');
			if ($reason === '') {
				$reason = $this->l10n->t('No verified redacted version exists yet.');
			}

			$out[] = [
				'fileName' => (string)($assessment['fileName'] ?? ($assessment['documentReference'] ?? '')),
				'reason' => $reason,
			];
		}//end foreach

		return $out;

	}//end unredacted()

	/**
	 * Redact one assessed document and verify the result.
	 *
	 * The findings are OpenRegister's: every detected entity on the file
	 * except the ones the officer rejected (`skip_anonymization`, set through
	 * `PATCH /api/entity-relations/{id}`). This is the selection the
	 * OpenRegister endpoint itself makes; nothing is accepted on the
	 * officer's behalf here.
	 *
	 * @param array<string, mixed> $assessment The document assessment.
	 * @param string               $owner      Whose files a relative reference lives in.
	 *
	 * @return array{anonymizedDocument: string, anonymizedDocumentHash: string, redactionStatus: string, redactionMessage: string}
	 *
	 * @spec openspec/changes/woo-redaction-pipeline/specs/woo-transparency/spec.md#requirement-a-partly-public-document-is-published-only-as-a-verified-redacted-version-req-wrp-001
	 */
	public function redact(array $assessment, string $owner): array {
		if ($this->appManager !== null && $this->appManager->isInstalled('openregister') === false) {
			return $this->failed(reason: $this->l10n->t('Redaction is unavailable because OpenRegister is not installed.'));
		}

		$original = $this->writer->resolve(reference: (string)($assessment['documentReference'] ?? ''), owner: $owner);
		if ($original === null) {
			return $this->failed(reason: $this->l10n->t('The original document cannot be found, so it cannot be redacted.'));
		}

		try {
			$files = $this->container->get('OCA\OpenRegister\Service\FileService');
			$relations = $this->container->get('OCA\OpenRegister\Db\EntityRelationMapper');
		} catch (Throwable $e) {
			$this->logger->warning('[DocumentRedactor] OpenRegister redaction unavailable: '.get_class($e));
			return $this->failed(reason: $this->l10n->t('Redaction is unavailable in OpenRegister.'));
		}

		try {
			$entities = $this->entities(rows: $relations->findEntitiesForAnonymization($original->getId()));
		} catch (Throwable $e) {
			$this->logger->warning('[DocumentRedactor] findings unreadable for file '.$original->getId().': '.get_class($e));
			return $this->failed(reason: $this->l10n->t('The findings for this document cannot be read.'));
		}

		if ($entities === []) {
			return $this->failed(reason: $this->l10n->t('There are no findings to redact. Run text extraction and review the findings first.'));
		}

		try {
			$redacted = $files->anonymizeDocument(node: $original, entities: $entities);
			$residuals = $files->getLastResidualEntities();
		} catch (Throwable $e) {
			// Log the class only: an exception message may quote the text
			// that was meant to be redacted.
			$this->logger->warning('[DocumentRedactor] redaction failed for file '.$original->getId().': '.get_class($e));
			return $this->failed(reason: $this->l10n->t('Redaction failed in OpenRegister. The document stays unpublished.'));
		}

		return $this->verify(original: $original, redacted: $redacted, residuals: $residuals);

	}//end redact()

	/**
	 * Accept a redacted file only when it is a different file, with different
	 * bytes, and OpenRegister reports nothing left readable.
	 *
	 * @param File  $original  The original document.
	 * @param mixed $redacted  What OpenRegister returned.
	 * @param mixed $residuals Findings OpenRegister could not remove.
	 *
	 * @return array{anonymizedDocument: string, anonymizedDocumentHash: string, redactionStatus: string, redactionMessage: string}
	 *
	 * @spec openspec/changes/woo-redaction-pipeline/specs/woo-transparency/spec.md#requirement-a-partly-public-document-is-published-only-as-a-verified-redacted-version-req-wrp-001
	 */
	public function verify(File $original, mixed $redacted, mixed $residuals): array {
		if (($redacted instanceof File) === false || $redacted->getId() === $original->getId()) {
			return $this->failed(reason: $this->l10n->t('OpenRegister did not return a separate redacted file.'));
		}

		if (is_array($residuals) === false) {
			return $this->failed(reason: $this->l10n->t('The redacted file cannot be verified.'));
		}

		if ($residuals !== []) {
			$reason = $this->l10n->t(
				'%s findings are still readable in the redacted file. Review the findings and assess again.',
				[(string)count($residuals)]
			);
			return $this->failed(reason: $reason);
		}

		try {
			$hash = (string)$redacted->hash('sha256');
			$originalHash = (string)$original->hash('sha256');
		} catch (Throwable) {
			$hash = '';
			$originalHash = '';
		}

		if ($hash === '' || $hash === $originalHash) {
			return $this->failed(reason: $this->l10n->t('The redacted file cannot be verified.'));
		}

		return [
			'anonymizedDocument' => (string)$redacted->getId(),
			'anonymizedDocumentHash' => $hash,
			'redactionStatus' => self::VERIFIED,
			'redactionMessage' => '',
		];

	}//end verify()

	/**
	 * Refuse the publish while a partly public document has no verified
	 * redacted version (fail closed).
	 *
	 * A `deels_openbaar` document passes only when its redaction was verified,
	 * its redacted file still exists, is not the original, and still has the
	 * bytes that were verified. Anything else names the document and stops
	 * the publish before anything is written.
	 *
	 * @param array<int, array<string, mixed>> $listings The publishable documents.
	 * @param string                           $owner    The batch's creator.
	 *
	 * @return void
	 *
	 * @throws RuntimeException Naming every partly public document without a verified redacted version.
	 *
	 * @spec openspec/changes/woo-redaction-pipeline/specs/woo-transparency/spec.md#requirement-a-partly-public-document-is-published-only-as-a-verified-redacted-version-req-wrp-001
	 */
	public function assertPublishable(array $listings, string $owner): void {
		$unredacted = [];
		foreach ($listings as $listing) {
			if ((string)($listing['assessment'] ?? '') !== 'deels_openbaar') {
				continue;
			}

			if ($this->isVerified(listing: $listing, owner: $owner) === false) {
				$name = (string)($listing['title'] ?? '');
				if ($name === '') {
					$name = (string)($listing['original'] ?? '');
				}

				$unredacted[] = $name;
			}
		}

		if ($unredacted !== []) {
			throw new RuntimeException(
				$this->l10n->t('Publishing is blocked: these partly public documents have no verified redacted version: %s', [implode(', ', $unredacted)])
			);
		}

	}//end assertPublishable()

	/**
	 * Whether a partly public listing points at its verified redacted file.
	 *
	 * @param array<string, mixed> $listing The listing.
	 * @param string               $owner   The batch's creator.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/woo-redaction-pipeline/specs/woo-transparency/spec.md#requirement-a-partly-public-document-is-published-only-as-a-verified-redacted-version-req-wrp-001
	 */
	private function isVerified(array $listing, string $owner): bool {
		$reference = (string)($listing['document'] ?? '');
		$expected = (string)($listing['redactionHash'] ?? '');
		// The original reached under its own reference, or under another
		// one, is caught by the file id comparison below.
		if ((string)($listing['redactionStatus'] ?? '') !== self::VERIFIED || $expected === '') {
			return false;
		}

		$redacted = $this->writer->resolve(reference: $reference, owner: $owner);
		if ($redacted === null) {
			return false;
		}

		$original = $this->writer->resolve(reference: (string)($listing['original'] ?? ''), owner: $owner);
		if ($original !== null && $original->getId() === $redacted->getId()) {
			return false;
		}

		try {
			return hash_equals($expected, (string)$redacted->hash('sha256'));
		} catch (Throwable) {
			return false;
		}

	}//end isVerified()

	/**
	 * OpenRegister's finding rows as the entity list `anonymizeDocument`
	 * takes, one per distinct value, exactly as its own endpoint builds it.
	 *
	 * @param mixed $rows The rows of `findEntitiesForAnonymization`.
	 *
	 * @return array<int, array{text: string, entityType: string, key: string}>
	 *
	 * @spec openspec/changes/woo-redaction-pipeline/specs/woo-transparency/spec.md#requirement-a-partly-public-document-is-published-only-as-a-verified-redacted-version-req-wrp-001
	 */
	public function entities(mixed $rows): array {
		$entities = [];
		$seen = [];
		foreach ((array)$rows as $row) {
			$value = (string)($row['entity_value'] ?? '');
			if ($value === '' || isset($seen[$value]) === true) {
				continue;
			}

			$seen[$value] = true;
			$type = (string)($row['entity_type'] ?? '');
			$entities[] = [
				'text' => $value,
				'entityType' => $type,
				'key' => substr(md5($value.$type), 0, 8),
			];
		}

		return $entities;

	}//end entities()

	/**
	 * Whose files a relative document reference lives in: the batch's
	 * creator, the same owner the publish resolves against.
	 *
	 * @param string|null $batchId  The batch uuid, when known.
	 * @param string      $fallback The acting officer.
	 *
	 * @return string
	 */
	private function ownerOf(?string $batchId, string $fallback): string {
		if ((string)$batchId === '') {
			return $fallback;
		}

		try {
			$batch = $this->container->get('OCA\OpenRegister\Service\ObjectService')->find($batchId);
			if (is_object($batch) === true && method_exists($batch, 'jsonSerialize') === true) {
				$batch = $batch->jsonSerialize();
			}

			$owner = (string)($batch['createdBy'] ?? '');
		} catch (Throwable) {
			$owner = '';
		}

		if ($owner === '') {
			return $fallback;
		}

		return $owner;

	}//end ownerOf()

	/**
	 * The fields a failed redaction leaves on the assessment.
	 *
	 * @param string $reason Why the document cannot be published.
	 *
	 * @return array{anonymizedDocument: string, anonymizedDocumentHash: string, redactionStatus: string, redactionMessage: string}
	 */
	private function failed(string $reason): array {
		return [
			'anonymizedDocument' => '',
			'anonymizedDocumentHash' => '',
			'redactionStatus' => self::FAILED,
			'redactionMessage' => $reason,
		];

	}//end failed()
}//end class
