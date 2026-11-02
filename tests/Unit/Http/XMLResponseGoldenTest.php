<?php

/**
 * Golden test for XMLResponse::arrayToXml.
 *
 * Every case is recorded once and replayed from the fixture: the XML, or the
 * error and its class.
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

namespace Unit\Http;

use OCA\OpenCatalogi\Http\XMLResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Golden test for XMLResponse::arrayToXml.
 *
 * @covers \OCA\OpenCatalogi\Http\XMLResponse
 */
class XMLResponseGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/xml-response-golden.json';

	/**
	 * The recorded cases: the data and the root tag.
	 *
	 * @return array<string, array{0: array<mixed>, 1: string|null}>
	 */
	public static function cases(): array {
		$stringable = new class {
			public function __toString(): string {
				return 'als tekst';
			}
		};

		return [
			'empty' => [[], null],
			'root from data' => [['@root' => 'feed', 'a' => '1'], null],
			'root tag wins' => [['@root' => 'feed', 'a' => '1'], 'channel'],
			'attributes in order, then text' => [['@attributes' => ['z' => 1, 'a' => true, 'n' => null], '#text' => 'hallo', 'kind' => 'x'], 'r'],
			'attributes not a list' => [['@attributes' => 'plain', 'b' => 'c'], 'r'],
			'text only, entities' => [['#text' => 'A &amp;amp; B &#039;q&#039; <x>'], 'r'],
			'carriage return' => [['line' => "een\r\ntwee"], 'r'],
			'at-prefixed keys' => [['@id' => '7', '@@type' => 't'], 'r'],
			'numeric string keys' => [['5' => 'vijf', 'x' => ['12' => 'twaalf']], 'r'],
			'scalars' => [['int' => 3, 'float' => 1.5, 'true' => true, 'false' => false, 'null' => null, 'empty' => ''], 'r'],
			'objects' => [['stringable' => $stringable, 'plain' => new \stdClass()], 'r'],
			'nested associative' => [['a' => ['b' => ['c' => 'd', '@attributes' => ['k' => 'v']]]], 'r'],
			'indexed list of arrays' => [['item' => [['n' => '1'], ['n' => '2', '#text' => 't'], 'scalar after arrays']], 'r'],
			'indexed list of scalars' => [['tag' => ['a', 'b']], 'r'],
			'list starting with a scalar' => [['tag' => ['a', ['n' => '1']]], 'r'],
			'empty nested' => [['a' => []], 'r'],
			'text null' => [['#text' => null, 'b' => 'c'], 'r'],
			'attribute keys as ints' => [['@attributes' => ['a' => 'x', 'b' => 2.5], 'c' => ['@attributes' => [], '#text' => 0]], 'r'],
			'deep' => [['l1' => ['l2' => ['l3' => [['l4' => ['#text' => 'diep', '@attributes' => ['x' => 'y']]]]]]], 'r'],
		];

	}//end cases()

	/**
	 * Replay a case and compare it to the recording.
	 *
	 * @param array<mixed> $data    The data.
	 * @param string|null  $rootTag The root tag.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testXmlMatchesTheRecording(array $data, ?string $rootTag): void {
		try {
			$actual = ['xml' => (new XMLResponse())->arrayToXml($data, $rootTag)];
		} catch (\Throwable $e) {
			$actual = ['error' => get_class($e), 'message' => $e->getMessage()];
		}

		$recorded = [];
		if (file_exists(self::FIXTURE) === true) {
			$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		$name = (string)$this->dataName();
		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$name] = $actual;
			if (is_dir(dirname(self::FIXTURE)) === false) {
				mkdir(dirname(self::FIXTURE));
			}

			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
		}

		$this->assertArrayHasKey($name, $recorded, 'No recording for ' . $name);
		$this->assertSame($recorded[$name], $actual);

	}//end testXmlMatchesTheRecording()
}//end class
