<?php

/**
 * Appends the official title to a PDF as one incremental update.
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
 * Writes a new Info dictionary with `/Title`, an XMP stream with `dc:title`
 * and `dc:identifier`, a new revision of the catalog pointing at that stream,
 * and a cross-reference section with `/Prev` after the last byte of the file.
 * No earlier byte changes, so the input is an exact prefix of the output.
 *
 * The update is written in the form of the file's last cross-reference: a
 * table after a table, a cross-reference stream after a stream.
 *
 * @spec openspec/changes/published-file-carries-its-facts/specs/woo-compliance/spec.md#requirement-the-published-file-carries-its-official-title-req-pff-002
 */
class PdfTitleUpdate {

	/**
	 * The reason a PDF that carries a digital signature is left alone.
	 */
	public const SIGNED = 'signed';

	/**
	 * The reason an encrypted PDF is left alone.
	 */
	public const ENCRYPTED = 'encrypted';

	/**
	 * The reason a PDF whose structure this writer cannot follow is left alone.
	 */
	public const UNPARSEABLE = 'unparseable';

	/**
	 * Constructor.
	 *
	 * @param PdfSyntax $syntax Reads the dictionaries the update copies.
	 */
	public function __construct(
		private readonly PdfSyntax $syntax = new PdfSyntax(),
	) {
	}//end __construct()

	/**
	 * The bytes with the title appended.
	 *
	 * @param string $bytes      The PDF.
	 * @param EmbeddedTitle $facts The official title and the publication identifier.
	 *
	 * @return string The PDF with the update appended.
	 *
	 * @throws TitleNotWritten With the reason when the PDF is encrypted, signed or cannot be followed.
	 *
	 * @spec openspec/changes/published-file-carries-its-facts/specs/woo-compliance/spec.md#requirement-the-published-file-carries-its-official-title-req-pff-002
	 */
	public function append(string $bytes, EmbeddedTitle $facts): string {
		$trailer = $this->lastTrailer(bytes: $bytes);
		[$root, $size] = $this->writableRoot(bytes: $bytes, trailer: $trailer['dict']);
		$catalog = $this->objectDictionary(bytes: $bytes, reference: $root);
		if ($catalog === null) {
			throw new TitleNotWritten(self::UNPARSEABLE);
		}

		$info = $this->infoDictionary(bytes: $bytes, trailer: $trailer['dict']);
		$infoNumber = $size;
		$xmpNumber = ($size + 1);
		$info['/Title'] = self::textString(text: $facts->title);
		$catalog['/Metadata'] = $xmpNumber . ' 0 R';

		$out = self::endingInNewline(bytes: $bytes);

		$offsets = [];
		$offsets[$root[0]] = [strlen($out), $root[1]];
		$out .= $root[0] . ' ' . $root[1] . " obj\n" . self::dictionary(entries: $catalog) . "\nendobj\n";
		$offsets[$infoNumber] = [strlen($out), 0];
		$out .= $infoNumber . " 0 obj\n" . self::dictionary(entries: $info) . "\nendobj\n";
		$xmp = self::xmp(facts: $facts);
		$offsets[$xmpNumber] = [strlen($out), 0];
		$out .= $xmpNumber . " 0 obj\n<< /Type /Metadata /Subtype /XML /Length " . strlen($xmp) . " >>\nstream\n" . $xmp . "\nendstream\nendobj\n";

		$newTrailer = [
			'/Root' => $root[0] . ' ' . $root[1] . ' R',
			'/Info' => $infoNumber . ' 0 R',
			'/Prev' => (string)$trailer['offset'],
		] + array_intersect_key($trailer['dict'], ['/ID' => true]);

		if ($trailer['stream'] === true) {
			return $this->withXrefStream(out: $out, offsets: $offsets, number: ($size + 2), trailer: $newTrailer);
		}

		return $this->withXrefTable(out: $out, offsets: $offsets, size: ($size + 2), trailer: $newTrailer);
	}//end append()

	/**
	 * The file with a line end after its last byte, so an appended object starts on its own line.
	 *
	 * @param string $bytes The file.
	 *
	 * @return string
	 */
	private static function endingInNewline(string $bytes): string {
		if (preg_match('/[\r\n]\z/', $bytes) === 1) {
			return $bytes;
		}

		return $bytes . "\n";
	}//end endingInNewline()

	/**
	 * The catalog reference and `/Size` of a PDF this writer may change.
	 *
	 * @param string                $bytes   The PDF.
	 * @param array<string, string> $trailer The last trailer dictionary.
	 *
	 * @return array{0: array{0: int, 1: int}, 1: int}
	 *
	 * @throws TitleNotWritten When it is encrypted, signed, or has no catalog or size.
	 */
	private function writableRoot(string $bytes, array $trailer): array {
		if (isset($trailer['/Encrypt']) === true) {
			throw new TitleNotWritten(self::ENCRYPTED);
		}

		if (preg_match('/\/ByteRange\s*\[|\/Type\s*\/Sig\b|\/SigFlags\s+[1-9]/', $bytes) === 1) {
			throw new TitleNotWritten(self::SIGNED);
		}

		$root = $this->reference(value: ($trailer['/Root'] ?? ''));
		$size = (int)($trailer['/Size'] ?? 0);
		if ($root === null || $size < 1) {
			throw new TitleNotWritten(self::UNPARSEABLE);
		}

		return [$root, $size];
	}//end writableRoot()

	/**
	 * The entries of the current Info dictionary, empty without one.
	 *
	 * @param string                $bytes   The PDF.
	 * @param array<string, string> $trailer The last trailer dictionary.
	 *
	 * @return array<string, string>
	 */
	private function infoDictionary(string $bytes, array $trailer): array {
		$info = $this->reference(value: ($trailer['/Info'] ?? ''));
		if ($info === null) {
			return [];
		}

		return ($this->objectDictionary(bytes: $bytes, reference: $info) ?? []);
	}//end infoDictionary()

	/**
	 * Close the update with a cross-reference table and a trailer.
	 *
	 * @param string                           $out     The bytes so far.
	 * @param array<int, array{0: int, 1: int}> $offsets Offset and generation per object number.
	 * @param int                              $size    The new `/Size`.
	 * @param array<string, string>            $trailer The trailer entries besides `/Size`.
	 *
	 * @return string
	 */
	private function withXrefTable(string $out, array $offsets, int $size, array $trailer): string {
		ksort($offsets);
		$start = strlen($out);
		$table = "xref\n";
		foreach ($this->runs(numbers: array_keys($offsets)) as [$first, $count]) {
			$table .= $first . ' ' . $count . "\n";
			for ($number = $first; $number < ($first + $count); $number++) {
				$table .= sprintf("%010d %05d n\r\n", $offsets[$number][0], $offsets[$number][1]);
			}
		}

		return $out . $table . "trailer\n" . self::dictionary(entries: ['/Size' => (string)$size] + $trailer) . "\nstartxref\n" . $start . "\n%%EOF\n";
	}//end withXrefTable()

	/**
	 * Close the update with an uncompressed cross-reference stream.
	 *
	 * @param string                           $out     The bytes so far.
	 * @param array<int, array{0: int, 1: int}> $offsets Offset and generation per object number.
	 * @param int                              $number  The object number of the stream itself.
	 * @param array<string, string>            $trailer The trailer entries besides `/Size`.
	 *
	 * @return string
	 */
	private function withXrefStream(string $out, array $offsets, int $number, array $trailer): string {
		$start = strlen($out);
		$offsets[$number] = [$start, 0];
		ksort($offsets);
		$index = [];
		$data = '';
		foreach ($this->runs(numbers: array_keys($offsets)) as [$first, $count]) {
			$index[] = $first . ' ' . $count;
			for ($entry = $first; $entry < ($first + $count); $entry++) {
				$data .= pack('CNn', 1, $offsets[$entry][0], $offsets[$entry][1]);
			}
		}

		$dict = [
			'/Type' => '/XRef',
			'/Size' => (string)($number + 1),
			'/W' => '[1 4 2]',
			'/Index' => '[' . implode(' ', $index) . ']',
			'/Length' => (string)strlen($data),
		] + $trailer;

		$object = $number . " 0 obj\n" . self::dictionary(entries: $dict) . "\nstream\n" . $data . "\nendstream\nendobj\n";
		return $out . $object . "startxref\n" . $start . "\n%%EOF\n";
	}//end withXrefStream()

	/**
	 * Consecutive object numbers as `[first, count]` runs.
	 *
	 * @param array<int, int> $numbers Sorted object numbers.
	 *
	 * @return array<int, array{0: int, 1: int}>
	 */
	private function runs(array $numbers): array {
		$runs = [];
		foreach ($numbers as $number) {
			$last = (count($runs) - 1);
			if ($last >= 0 && ($runs[$last][0] + $runs[$last][1]) === $number) {
				$runs[$last][1]++;
				continue;
			}

			$runs[] = [$number, 1];
		}

		return $runs;
	}//end runs()

	/**
	 * The dictionary and offset of the file's last cross-reference.
	 *
	 * @param string $bytes The PDF.
	 *
	 * @return array{offset: int, stream: bool, dict: array<string, string>}
	 *
	 * @throws TitleNotWritten When there is no cross-reference to follow.
	 */
	private function lastTrailer(string $bytes): array {
		if (str_starts_with($bytes, '%PDF-') === false || preg_match_all('/startxref\s+(\d+)\s+%%EOF/', $bytes, $found) < 1) {
			throw new TitleNotWritten(self::UNPARSEABLE);
		}

		$offset = (int)end($found[1]);
		if (substr($bytes, $offset, 4) === 'xref') {
			$open = false;
			$pos = strpos($bytes, 'trailer', $offset);
			if ($pos !== false) {
				$open = strpos($bytes, '<<', $pos);
			}

			if ($open !== false) {
				return ['offset' => $offset, 'stream' => false, 'dict' => $this->syntax->dictionaryAt(bytes: $bytes, offset: $open)[0]];
			}
		}

		if (preg_match('/\G\d+\s+\d+\s+obj\s*/', $bytes, $head, 0, $offset) === 1) {
			$dict = $this->syntax->dictionaryAt(bytes: $bytes, offset: ($offset + strlen($head[0])))[0];
			if (($dict['/Type'] ?? '') === '/XRef') {
				return ['offset' => $offset, 'stream' => true, 'dict' => $dict];
			}
		}

		throw new TitleNotWritten(self::UNPARSEABLE);
	}//end lastTrailer()

	/**
	 * The dictionary of the newest revision of an object written in the file
	 * itself (not inside an object stream), or null.
	 *
	 * @param string                 $bytes     The PDF.
	 * @param array{0: int, 1: int} $reference Object number and generation.
	 *
	 * @return array<string, string>|null
	 *
	 * @throws TitleNotWritten When the object is not a dictionary this writer can read.
	 */
	private function objectDictionary(string $bytes, array $reference): ?array {
		$pattern = '/(?<![0-9])' . $reference[0] . '\s+' . $reference[1] . '\s+obj\s*/';
		if (preg_match_all($pattern, $bytes, $found, PREG_OFFSET_CAPTURE) < 1) {
			return null;
		}

		$last = end($found[0]);
		return $this->syntax->dictionaryAt(bytes: $bytes, offset: ($last[1] + strlen($last[0])))[0];
	}//end objectDictionary()

	/**
	 * An indirect reference `n g R` as `[n, g]`, or null.
	 *
	 * @param string $value The raw value.
	 *
	 * @return array{0: int, 1: int}|null
	 */
	private function reference(string $value): ?array {
		if (preg_match('/^(\d+)\s+(\d+)\s+R$/', trim($value), $match) !== 1) {
			return null;
		}

		return [(int)$match[1], (int)$match[2]];
	}//end reference()

	/**
	 * A dictionary written out.
	 *
	 * @param array<string, string> $entries Raw values by key.
	 *
	 * @return string
	 */
	private static function dictionary(array $entries): string {
		$parts = [];
		foreach ($entries as $key => $value) {
			$parts[] = $key . ' ' . $value;
		}

		return '<< ' . implode(' ', $parts) . ' >>';
	}//end dictionary()

	/**
	 * A PDF text string in UTF-16BE with its byte order mark, as hex.
	 *
	 * @param string $text UTF-8 text.
	 *
	 * @return string
	 */
	private static function textString(string $text): string {
		return '<FEFF' . strtoupper(bin2hex(mb_convert_encoding($text, 'UTF-16BE', 'UTF-8'))) . '>';
	}//end textString()

	/**
	 * The XMP packet with the title and the identifier.
	 *
	 * @param EmbeddedTitle $facts The official title and the publication identifier.
	 *
	 * @return string
	 */
	private static function xmp(EmbeddedTitle $facts): string {
		$escape = static fn (string $text): string => htmlspecialchars($text, (ENT_XML1 | ENT_QUOTES), 'UTF-8');
		return '<?xpacket begin="' . "\u{FEFF}" . '" id="W5M0MpCehiHzreSzNTczkc9d"?>' . "\n"
			. '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
			. '<rdf:Description rdf:about="" xmlns:dc="http://purl.org/dc/elements/1.1/">'
			. '<dc:title><rdf:Alt><rdf:li xml:lang="x-default">' . $escape($facts->title) . '</rdf:li></rdf:Alt></dc:title>'
			. '<dc:identifier>' . $escape($facts->identifier) . '</dc:identifier>'
			. '</rdf:Description></rdf:RDF></x:xmpmeta>' . "\n"
			. '<?xpacket end="w"?>';
	}//end xmp()
}//end class
