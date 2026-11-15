<?php

/**
 * Reads a written PDF back by following its own cross-reference.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Service\Publication;

use RuntimeException;

/**
 * Independent of the writer: it takes the last `startxref`, reads that
 * cross-reference (a table or an uncompressed stream), and reads each object
 * at the offset the cross-reference gives, failing when the object is not there.
 */
final class PdfReadBack {

	/**
	 * The title, the XMP title and identifier, `/Prev`, the kind of the last
	 * cross-reference and the Info dictionary of a PDF.
	 *
	 * @param string $bytes The PDF.
	 *
	 * @return array{title: string, xmpTitle: string, xmpIdentifier: string, prev: int, stream: bool, info: string}
	 */
	public static function read(string $bytes): array {
		[$offsets, $trailer, $stream] = self::crossReference($bytes, self::lastStartxref($bytes));
		$info = self::object($bytes, $offsets, self::ref($trailer, '/Info'));
		$catalog = self::object($bytes, $offsets, self::ref($trailer, '/Root'));
		$xmp = self::object($bytes, $offsets, self::ref($catalog, '/Metadata'));
		preg_match('/\/Title\s*<FEFF([0-9A-F]*)>/', $info, $title);
		preg_match('/<rdf:li xml:lang="x-default">([^<]*)</', $xmp, $xmpTitle);
		preg_match('/<dc:identifier>([^<]*)</', $xmp, $xmpIdentifier);
		preg_match('/\/Prev\s+(\d+)/', $trailer, $prev);

		return [
			'title' => mb_convert_encoding((string)hex2bin($title[1] ?? ''), 'UTF-8', 'UTF-16BE'),
			'xmpTitle' => html_entity_decode($xmpTitle[1] ?? '', ENT_XML1),
			'xmpIdentifier' => html_entity_decode($xmpIdentifier[1] ?? '', ENT_XML1),
			'prev' => (int)($prev[1] ?? -1),
			'stream' => $stream,
			'info' => $info,
		];
	}//end read()

	/**
	 * The offset the last `startxref` names.
	 *
	 * @param string $bytes The PDF.
	 *
	 * @return int
	 */
	public static function lastStartxref(string $bytes): int {
		preg_match_all('/startxref\s+(\d+)/', $bytes, $found);
		return (int)end($found[1]);
	}//end lastStartxref()

	/**
	 * The text of the last classic trailer dictionary.
	 *
	 * @param string $bytes The PDF.
	 *
	 * @return string
	 */
	public static function lastTrailer(string $bytes): string {
		return substr($bytes, (int)strrpos($bytes, 'trailer'));
	}//end lastTrailer()

	/**
	 * Offsets by object number, the trailer dictionary, and whether it is a stream.
	 *
	 * @param string $bytes  The PDF.
	 * @param int    $offset Where the cross-reference is.
	 *
	 * @return array{0: array<int, int>, 1: string, 2: bool}
	 */
	private static function crossReference(string $bytes, int $offset): array {
		$offsets = [];
		if (substr($bytes, $offset, 4) === 'xref') {
			$end = (int)strpos($bytes, 'trailer', $offset);
			$lines = preg_split('/\r\n|\n/', trim(substr($bytes, ($offset + 4), ($end - $offset - 4))));
			$number = 0;
			foreach ($lines as $line) {
				if (preg_match('/^(\d+) (\d+)$/', $line, $run) === 1) {
					$number = (int)$run[1];
					continue;
				}

				$offsets[$number++] = (int)substr($line, 0, 10);
			}

			return [$offsets, substr($bytes, $end, ((int)strpos($bytes, 'startxref', $end) - $end)), false];
		}

		if (preg_match('/\G\d+ 0 obj\n(<<.*?>>)\nstream\n/s', $bytes, $head, 0, $offset) !== 1) {
			throw new RuntimeException('No cross-reference at ' . $offset);
		}

		preg_match('/\/Index \[([\d ]+)\]/', $head[1], $index);
		preg_match('/\/Length (\d+)/', $head[1], $length);
		$data = substr($bytes, ($offset + strlen($head[0])), (int)$length[1]);
		$runs = array_map('intval', explode(' ', $index[1]));
		$at = 0;
		for ($i = 0; $i < count($runs); $i += 2) {
			for ($number = $runs[$i]; $number < ($runs[$i] + $runs[($i + 1)]); $number++) {
				$entry = unpack('Ctype/Noffset/ngen', substr($data, $at, 7));
				$offsets[$number] = $entry['offset'];
				$at += 7;
			}
		}

		return [$offsets, $head[1], true];
	}//end crossReference()

	/**
	 * The body of an object at the offset its cross-reference gives.
	 *
	 * @param string          $bytes   The PDF.
	 * @param array<int, int> $offsets Offsets by object number.
	 * @param int             $number  The object number.
	 *
	 * @return string
	 */
	private static function object(string $bytes, array $offsets, int $number): string {
		$offset = ($offsets[$number] ?? -1);
		if ($offset < 0 || preg_match('/\G' . $number . ' \d+ obj\n(.*?)\nendobj/s', $bytes, $object, 0, $offset) !== 1) {
			throw new RuntimeException('Object ' . $number . ' is not where the cross-reference says');
		}

		return $object[1];
	}//end object()

	/**
	 * The object number of a reference in a dictionary.
	 *
	 * @param string $dict The dictionary text.
	 * @param string $key  The key.
	 *
	 * @return int
	 */
	private static function ref(string $dict, string $key): int {
		if (preg_match('/' . preg_quote($key, '/') . '\s+(\d+) \d+ R/', $dict, $match) !== 1) {
			throw new RuntimeException('No ' . $key);
		}

		return (int)$match[1];
	}//end ref()
}//end class
