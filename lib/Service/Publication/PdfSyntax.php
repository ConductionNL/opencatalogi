<?php

/**
 * Reads PDF dictionaries as raw values by key.
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

/**
 * The PDF object syntax PdfTitleUpdate needs: a dictionary at an offset, its
 * values kept as the exact bytes they were written as, so a copied entry is
 * written back unchanged.
 *
 * @spec openspec/changes/published-file-carries-its-facts/specs/woo-compliance/spec.md#requirement-the-published-file-carries-its-official-title-req-pff-002
 */
final class PdfSyntax {

	/**
	 * The PDF delimiters, which end a name or a keyword.
	 */
	private const DELIMITERS = "()<>[]{}/%";

	/**
	 * The dictionary that starts at an offset.
	 *
	 * @param string $bytes  The PDF.
	 * @param int    $offset Where `<<` is, possibly after whitespace.
	 *
	 * @return array{0: array<string, string>, 1: int} The raw values by key, and the offset after `>>`.
	 *
	 * @throws TitleNotWritten When there is no well-formed dictionary there.
	 *
	 * @spec openspec/changes/published-file-carries-its-facts/specs/woo-compliance/spec.md#requirement-the-published-file-carries-its-official-title-req-pff-002
	 */
	public function dictionaryAt(string $bytes, int $offset): array {
		$pos = $this->skip(bytes: $bytes, pos: $offset);
		if (substr($bytes, $pos, 2) !== '<<') {
			throw new TitleNotWritten(PdfTitleUpdate::UNPARSEABLE);
		}

		$entries = [];
		$pos += 2;
		while (true) {
			$pos = $this->skip(bytes: $bytes, pos: $pos);
			if (substr($bytes, $pos, 2) === '>>') {
				return [$entries, ($pos + 2)];
			}

			if (($bytes[$pos] ?? '') !== '/') {
				throw new TitleNotWritten(PdfTitleUpdate::UNPARSEABLE);
			}

			$keyEnd = $this->valueEnd(bytes: $bytes, pos: $pos);
			$key = substr($bytes, $pos, ($keyEnd - $pos));
			$start = $this->skip(bytes: $bytes, pos: $keyEnd);
			$end = $this->valueEnd(bytes: $bytes, pos: $start);
			$entries[$key] = substr($bytes, $start, ($end - $start));
			$pos = $end;
		}
	}//end dictionaryAt()

	/**
	 * The offset after the value that starts at an offset.
	 *
	 * @param string $bytes The PDF.
	 * @param int    $pos   Where the value starts.
	 *
	 * @return int
	 *
	 * @throws TitleNotWritten When the value runs past the end of the file.
	 */
	private function valueEnd(string $bytes, int $pos): int {
		$char = ($bytes[$pos] ?? throw new TitleNotWritten(PdfTitleUpdate::UNPARSEABLE));

		if (substr($bytes, $pos, 2) === '<<') {
			return $this->dictionaryAt(bytes: $bytes, offset: $pos)[1];
		}

		return match ($char) {
			'(' => $this->stringEnd(bytes: $bytes, pos: $pos),
			'<' => $this->closing(bytes: $bytes, pos: $pos, close: '>'),
			'[' => $this->arrayEnd(bytes: $bytes, pos: $pos),
			'/' => $this->wordEnd(bytes: $bytes, pos: ($pos + 1)),
			default => $this->numberOrReferenceEnd(bytes: $bytes, pos: $pos),
		};
	}//end valueEnd()

	/**
	 * The offset after a literal string, with its nesting and escapes.
	 *
	 * @param string $bytes The PDF.
	 * @param int    $pos   Where `(` is.
	 *
	 * @return int
	 *
	 * @throws TitleNotWritten When the string is not closed.
	 */
	private function stringEnd(string $bytes, int $pos): int {
		$depth = 0;
		$length = strlen($bytes);
		for ($i = $pos; $i < $length; $i++) {
			$char = $bytes[$i];
			if ($char === '\\') {
				$i++;
				continue;
			}

			$depth += match ($char) {
				'(' => 1,
				')' => -1,
				default => 0,
			};
			if ($depth === 0) {
				return ($i + 1);
			}
		}

		throw new TitleNotWritten(PdfTitleUpdate::UNPARSEABLE);
	}//end stringEnd()

	/**
	 * The offset after an array.
	 *
	 * @param string $bytes The PDF.
	 * @param int    $pos   Where `[` is.
	 *
	 * @return int
	 */
	private function arrayEnd(string $bytes, int $pos): int {
		$pos = $this->skip(bytes: $bytes, pos: ($pos + 1));
		while (($bytes[$pos] ?? ']') !== ']') {
			$pos = $this->skip(bytes: $bytes, pos: $this->valueEnd(bytes: $bytes, pos: $pos));
		}

		return $this->closing(bytes: $bytes, pos: $pos, close: ']');
	}//end arrayEnd()

	/**
	 * The offset after a number, or after `n g R` when the number starts a reference.
	 *
	 * @param string $bytes The PDF.
	 * @param int    $pos   Where the number is.
	 *
	 * @return int
	 */
	private function numberOrReferenceEnd(string $bytes, int $pos): int {
		if (preg_match('/\G\d+\s+\d+\s+R(?![^\s' . preg_quote(self::DELIMITERS, '/') . '])/', $bytes, $match, 0, $pos) === 1) {
			return ($pos + strlen($match[0]));
		}

		return $this->wordEnd(bytes: $bytes, pos: $pos);
	}//end numberOrReferenceEnd()

	/**
	 * The offset of the first whitespace or delimiter from an offset on.
	 *
	 * @param string $bytes The PDF.
	 * @param int    $pos   Where the word starts.
	 *
	 * @return int
	 *
	 * @throws TitleNotWritten When the word is empty.
	 */
	private function wordEnd(string $bytes, int $pos): int {
		$length = strcspn($bytes, " \t\r\n\f\0" . self::DELIMITERS, $pos);
		if ($length === 0 && ($bytes[($pos - 1)] ?? '') !== '/') {
			throw new TitleNotWritten(PdfTitleUpdate::UNPARSEABLE);
		}

		return ($pos + $length);
	}//end wordEnd()

	/**
	 * The offset after a closing character.
	 *
	 * @param string $bytes The PDF.
	 * @param int    $pos   Where the opening is.
	 * @param string $close The closing character.
	 *
	 * @return int
	 *
	 * @throws TitleNotWritten When it is not closed.
	 */
	private function closing(string $bytes, int $pos, string $close): int {
		$end = false;
		if ($pos < strlen($bytes)) {
			$end = strpos($bytes, $close, $pos);
		}

		if ($end === false) {
			throw new TitleNotWritten(PdfTitleUpdate::UNPARSEABLE);
		}

		return ($end + 1);
	}//end closing()

	/**
	 * The offset after whitespace and comments.
	 *
	 * @param string $bytes The PDF.
	 * @param int    $pos   Where to start.
	 *
	 * @return int
	 */
	private function skip(string $bytes, int $pos): int {
		while (true) {
			$pos += strspn($bytes, " \t\r\n\f\0", $pos);
			if (($bytes[$pos] ?? '') !== '%') {
				return $pos;
			}

			$pos += strcspn($bytes, "\r\n", $pos);
		}
	}//end skip()
}//end class
