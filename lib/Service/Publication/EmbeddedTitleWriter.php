<?php

/**
 * Writes the official title into a published file's own metadata.
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
 * @spec openspec/changes/published-file-carries-its-facts/specs/woo-compliance/spec.md#requirement-the-published-file-carries-its-official-title-req-pff-002
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service\Publication;

use OCP\Files\File;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sets the title and the publication identifier in a published copy of a
 * document, and says what it did: `written`, `skipped` (with the reason) or
 * `failed` (with the reason), with the sha256 of the bytes before and after.
 *
 * It runs only on the copy attached to a public publication, after the publish
 * gate passed. A PDF gets an incremental update, so the bytes the gate approved
 * stay the exact prefix of the published file. Anything that is not a PDF or an
 * office package, and every PDF it may not or cannot change, is left as it was.
 *
 * @spec openspec/changes/published-file-carries-its-facts/specs/woo-compliance/spec.md#requirement-the-published-file-carries-its-official-title-req-pff-002
 */
class EmbeddedTitleWriter {

	public const WRITTEN = 'written';

	public const SKIPPED = 'skipped';

	public const FAILED = 'failed';

	/**
	 * The reason a file of another type is left alone.
	 */
	public const UNSUPPORTED = 'unsupported-type';

	/**
	 * The reason nothing is written without a title.
	 */
	public const NO_TITLE = 'no-title';

	/**
	 * The reason given when reading or writing the file failed.
	 */
	public const WRITE_ERROR = 'write-error';

	/**
	 * The reason a redacted copy is left as it was verified: OpenRegister's
	 * verifier did not find the bytes with the title clean.
	 */
	public const VERIFIER = 'verifier';

	/**
	 * OpenRegister's irreversibility verifier (REQ-RRS-001).
	 */
	private const VERIFIER_CLASS = 'OCA\\OpenRegister\\Service\\Anonymisation\\RedactionIrreversibilityVerifier';

	/**
	 * Where OpenRegister keeps the verdict of a copy, which its publish refuses on when not clean.
	 */
	private const VERDICT_STORE_CLASS = 'OCA\\OpenRegister\\Service\\Anonymisation\\RedactionVerdictStore';

	/**
	 * Constructor.
	 *
	 * @param PdfTitleUpdate          $pdf       Appends the title to a PDF.
	 * @param OfficeTitleUpdate       $office    Sets the title in an office package.
	 * @param LoggerInterface         $logger    Logs a failed write.
	 * @param ContainerInterface|null $container Resolves OpenRegister's verifier and verdict store.
	 */
	public function __construct(
		private readonly PdfTitleUpdate $pdf,
		private readonly OfficeTitleUpdate $office,
		private readonly LoggerInterface $logger,
		private readonly ?ContainerInterface $container = null,
	) {
	}//end __construct()

	/**
	 * Write the title and identifier into the file.
	 *
	 * For a redacted copy, OpenRegister's verifier runs again on the bytes
	 * with the title, against the values that were redacted, before anything
	 * is written. Only a `clean` verdict writes them, and that verdict is kept
	 * on the copy; any other leaves the verified bytes as they were.
	 *
	 * @param File   $file       The published copy.
	 * @param EmbeddedTitle $facts The official title and the publication identifier.
	 * @param RedactedValues|null $redaction What a redacted copy is verified against; null for a document
	 *                                      that was not redacted.
	 *
	 * @return array{status: string, hashBefore: string|null, hashAfter: string|null, reason?: string, verdict?: string}
	 *
	 * @spec openspec/changes/published-file-carries-its-facts/specs/woo-compliance/spec.md#requirement-the-published-file-carries-its-official-title-req-pff-002
	 * @spec openspec/changes/published-file-carries-its-facts/tasks.md#task-2.2b
	 */
	public function write(File $file, EmbeddedTitle $facts, ?RedactedValues $redaction = null): array {
		try {
			$before = $file->getContent();
		} catch (Throwable $e) {
			return $this->failed(file: $file, hash: null, error: $e);
		}

		$hash = hash('sha256', $before);
		$facts = new EmbeddedTitle(title: trim($facts->title), identifier: $facts->identifier, publisher: $facts->publisher, rsin: $facts->rsin);
		if ($facts->title === '') {
			return self::outcome(status: self::SKIPPED, before: $hash, after: $hash, reason: self::NO_TITLE);
		}

		try {
			$after = $this->withTitle(bytes: $before, facts: $facts);
		} catch (TitleNotWritten $skip) {
			return self::outcome(status: self::SKIPPED, before: $hash, after: $hash, reason: $skip->getMessage());
		} catch (Throwable $e) {
			return $this->failed(file: $file, hash: $hash, error: $e);
		}

		[$verification, $refusal] = $this->checkedAgain(bytes: $after, hash: $hash, redaction: $redaction);
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			$file->putContent($after);
		} catch (Throwable $e) {
			return $this->failed(file: $file, hash: $hash, error: $e);
		}

		return $this->written(file: $file, before: $hash, after: $after, verification: $verification);
	}//end write()

	/**
	 * For a redacted copy, OpenRegister's verdict on the bytes with the title,
	 * and the outcome that refuses them when it is not clean.
	 *
	 * @param string              $bytes     The bytes with the title.
	 * @param string              $hash      The sha256 of the bytes as they are.
	 * @param RedactedValues|null $redaction What a redacted copy is verified against, null otherwise.
	 *
	 * @return array{0: array<string, mixed>|null, 1: array<string, mixed>|null} The verdict and the refusal.
	 *
	 * @spec openspec/changes/published-file-carries-its-facts/tasks.md#task-2.2b
	 */
	private function checkedAgain(string $bytes, string $hash, ?RedactedValues $redaction): array {
		if ($redaction === null) {
			return [null, null];
		}

		$verification = $this->verifyAgain(bytes: $bytes, redaction: $redaction);
		if (($verification['verdict'] ?? '') === 'clean' && ($verification['mayBePublished'] ?? false) === true) {
			return [$verification, null];
		}

		$refusal = self::outcome(status: self::SKIPPED, before: $hash, after: $hash, reason: self::VERIFIER);
		$refusal['verdict'] = (string)($verification['verdict'] ?? 'unverifiable');

		return [$verification, $refusal];
	}//end checkedAgain()

	/**
	 * The outcome of a written title; a redacted copy's clean verdict is kept on it.
	 *
	 * @param File                      $file         The copy.
	 * @param string                    $before       The sha256 before.
	 * @param string                    $after        The bytes written.
	 * @param array<string, mixed>|null $verification The clean verdict of a redacted copy, null otherwise.
	 *
	 * @return array{status: string, hashBefore: string|null, hashAfter: string|null, reason?: string, verdict?: string}
	 *
	 * @spec openspec/changes/published-file-carries-its-facts/tasks.md#task-2.2b
	 */
	private function written(File $file, string $before, string $after, ?array $verification): array {
		$outcome = self::outcome(status: self::WRITTEN, before: $before, after: hash('sha256', $after));
		if ($verification !== null) {
			$this->keepVerdict(file: $file, verification: $verification);
			$outcome['verdict'] = 'clean';
		}

		return $outcome;
	}//end written()

	/**
	 * OpenRegister's verdict on the bytes about to be written, against the
	 * values that were redacted. Without the verifier, or when it fails, the
	 * verdict is `unverifiable`, which writes nothing.
	 *
	 * @param string         $bytes     The bytes with the title.
	 * @param RedactedValues $redaction The redacted values and the output mode.
	 *
	 * @return array<string, mixed> The verifier's result.
	 *
	 * @spec openspec/changes/published-file-carries-its-facts/tasks.md#task-2.2b
	 */
	private function verifyAgain(string $bytes, RedactedValues $redaction): array {
		$mode = $redaction->outputMode();
		if ($mode === '' && str_starts_with($bytes, '%PDF-') === true) {
			// An assessment from before the output mode was kept: this writer only changes a PDF that way.
			$mode = 'pdf';
		}

		try {
			$verifier = $this->container?->get(self::VERIFIER_CLASS);
			if (is_object($verifier) === false || method_exists($verifier, 'verify') === false) {
				return ['verdict' => 'unverifiable', 'mayBePublished' => false];
			}

			$result = $verifier->verify(bytes: $bytes, redactedValues: $redaction->values(), outputMode: $mode);
		} catch (Throwable $e) {
			// The class only: a message could quote a redacted value.
			$this->logger->warning('[EmbeddedTitleWriter] The redaction could not be verified again: ' . get_class($e));
			return ['verdict' => 'unverifiable', 'mayBePublished' => false];
		}

		if (is_array($result) === false) {
			return ['verdict' => 'unverifiable', 'mayBePublished' => false];
		}

		return $result;
	}//end verifyAgain()

	/**
	 * Keep the new verdict on the copy, where OpenRegister's publish reads it.
	 * Best effort: a verdict that cannot be kept is logged.
	 *
	 * @param File $file The copy.
	 * @param array<string, mixed> $verification The clean verdict.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/published-file-carries-its-facts/tasks.md#task-2.2b
	 */
	private function keepVerdict(File $file, array $verification): void {
		try {
			$store = $this->container?->get(self::VERDICT_STORE_CLASS);
			$kept = (is_object($store) === true && method_exists($store, 'record') === true)
				&& $store->record(fileId: (int)$file->getId(), verification: $verification) === true;
			if ($kept === true) {
				return;
			}
		} catch (Throwable $e) {
			$this->logger->warning('[EmbeddedTitleWriter] The verdict of {file} was not kept: ' . get_class($e), ['file' => $file->getName()]);
			return;
		}

		$this->logger->warning('[EmbeddedTitleWriter] The verdict of {file} was not kept', ['file' => $file->getName()]);
	}//end keepVerdict()

	/**
	 * The bytes with the title, by the file's own type.
	 *
	 * @param string $bytes      The file.
	 * @param EmbeddedTitle $facts The official title and the publication identifier.
	 *
	 * @return string
	 *
	 * @throws TitleNotWritten When the type is not one this writer changes, or the file may not be changed.
	 */
	private function withTitle(string $bytes, EmbeddedTitle $facts): string {
		if (str_starts_with($bytes, '%PDF-') === true) {
			return $this->pdf->append(bytes: $bytes, facts: $facts);
		}

		if ($this->office->handles(bytes: $bytes) === true) {
			return $this->office->apply(bytes: $bytes, facts: $facts);
		}

		throw new TitleNotWritten(self::UNSUPPORTED);
	}//end withTitle()

	/**
	 * A failed write: the file is left as it was and the reason is kept.
	 *
	 * @param File        $file  The published copy.
	 * @param string|null $hash  The sha256 of its bytes, when they were read.
	 * @param Throwable   $error What went wrong.
	 *
	 * @return array{status: string, hashBefore: string|null, hashAfter: string|null, reason: string}
	 */
	private function failed(File $file, ?string $hash, Throwable $error): array {
		$this->logger->warning(
			'[EmbeddedTitleWriter] The title was not written into {file}: {error}',
			['file' => $file->getName(), 'error' => $error->getMessage()]
		);

		return self::outcome(status: self::FAILED, before: $hash, after: $hash, reason: self::WRITE_ERROR);
	}//end failed()

	/**
	 * The stored outcome.
	 *
	 * @param string      $status `written`, `skipped` or `failed`.
	 * @param string|null $before The sha256 before.
	 * @param string|null $after  The sha256 after.
	 * @param string|null $reason Why it was skipped or failed.
	 *
	 * @return array{status: string, hashBefore: string|null, hashAfter: string|null, reason?: string, verdict?: string}
	 */
	private static function outcome(string $status, ?string $before, ?string $after, ?string $reason = null): array {
		$outcome = ['status' => $status, 'hashBefore' => $before, 'hashAfter' => $after];
		if ($reason !== null) {
			$outcome['reason'] = $reason;
		}

		return $outcome;
	}//end outcome()
}//end class
