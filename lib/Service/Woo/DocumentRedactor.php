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
			// Log the class and the structured reason only: an exception
			// message may quote the text that was meant to be redacted.
			$reason = '';
			if (method_exists($e, 'getReason') === true) {
				$reason = (string)$e->getReason();
			}

			$this->logger->warning('[DocumentRedactor] redaction failed for file '.$original->getId().': '.get_class($e).' '.$reason);
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
		if (($redacted instanceof File) === false || $redacted->getId() <= 0 || $redacted->getId() === $original->getId()) {
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
