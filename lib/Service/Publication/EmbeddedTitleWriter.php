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
	 * Constructor.
	 *
	 * @param PdfTitleUpdate    $pdf    Appends the title to a PDF.
	 * @param OfficeTitleUpdate $office Sets the title in an office package.
	 * @param LoggerInterface   $logger Logs a failed write.
	 */
	public function __construct(
		private readonly PdfTitleUpdate $pdf,
		private readonly OfficeTitleUpdate $office,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Write the title and identifier into the file.
	 *
	 * @param File   $file       The published copy.
	 * @param EmbeddedTitle $facts The official title and the publication identifier.
	 *
	 * @return array{status: string, hashBefore: string|null, hashAfter: string|null, reason?: string}
	 *
	 * @spec openspec/changes/published-file-carries-its-facts/specs/woo-compliance/spec.md#requirement-the-published-file-carries-its-official-title-req-pff-002
	 */
	public function write(File $file, EmbeddedTitle $facts): array {
		try {
			$before = $file->getContent();
		} catch (Throwable $e) {
			return $this->failed(file: $file, hash: null, error: $e);
		}

		$hash = hash('sha256', $before);
		$facts = new EmbeddedTitle(title: trim($facts->title), identifier: $facts->identifier);
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

		try {
			$file->putContent($after);
		} catch (Throwable $e) {
			return $this->failed(file: $file, hash: $hash, error: $e);
		}

		return self::outcome(status: self::WRITTEN, before: $hash, after: hash('sha256', $after));
	}//end write()

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
	 * @return array{status: string, hashBefore: string|null, hashAfter: string|null, reason?: string}
	 */
	private static function outcome(string $status, ?string $before, ?string $after, ?string $reason = null): array {
		$outcome = ['status' => $status, 'hashBefore' => $before, 'hashAfter' => $after];
		if ($reason !== null) {
			$outcome['reason'] = $reason;
		}

		return $outcome;
	}//end outcome()
}//end class
