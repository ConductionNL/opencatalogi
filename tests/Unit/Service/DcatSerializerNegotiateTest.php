<?php

/**
 * Characterisation tests for DcatSerializer::negotiate().
 *
 * Pins every branch of the format negotiation: the `?format=` aliases (case
 * and whitespace insensitive, unknown ones refused), the empty and wildcard
 * Accept fallbacks, the order in which Accept media types are tried, and the
 * refusal when none matches.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2025 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\DcatSerializer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Characterisation tests for DcatSerializer::negotiate().
 */
class DcatSerializerNegotiateTest extends TestCase {

	/**
	 * Every negotiation case: format parameter, Accept header, expected format.
	 *
	 * @return array<string, array{0: string|null, 1: string|null, 2: string|null}>
	 */
	public static function negotiationCases(): array {
		return [
			'alias jsonld'              => ['jsonld', null, 'jsonld'],
			'alias json-ld'             => ['json-ld', 'text/turtle', 'jsonld'],
			'alias json'                => ['json', null, 'jsonld'],
			'alias turtle'              => ['turtle', null, 'turtle'],
			'alias ttl'                 => ['ttl', null, 'turtle'],
			'alias rdfxml'              => ['rdfxml', null, 'rdfxml'],
			'alias rdf+xml'             => ['rdf+xml', null, 'rdfxml'],
			'alias rdf'                 => ['rdf', null, 'rdfxml'],
			'alias xml'                 => ['xml', 'application/ld+json', 'rdfxml'],
			'alias upper and padded'    => ['  TTL ', null, 'turtle'],
			'unknown format'            => ['csv', null, null],
			'blank-only format'         => ['   ', null, null],
			'empty format uses accept'  => ['', 'text/turtle', 'turtle'],
			'no accept'                 => [null, null, 'jsonld'],
			'empty accept'              => [null, '', 'jsonld'],
			'wildcard accept'           => [null, 'text/turtle, */*;q=0.1', 'jsonld'],
			'uppercase wildcard-free'   => [null, 'TEXT/TURTLE', 'turtle'],
			'turtle before rdfxml'      => [null, 'application/rdf+xml, text/turtle', 'turtle'],
			'rdfxml before jsonld'      => [null, 'application/ld+json, application/rdf+xml', 'rdfxml'],
			'ld+json'                   => [null, 'application/ld+json', 'jsonld'],
			'plain json'                => [null, 'application/json', 'jsonld'],
			'json with parameters'      => [null, 'application/json; charset=utf-8', 'jsonld'],
			'unsupported accept'        => [null, 'text/html', null],
			'uppercase wildcard'        => [null, 'TEXT/HTML, */*', 'jsonld'],
		];

	}//end negotiationCases()

	/**
	 * The negotiated format for each case.
	 *
	 * @param string|null $formatParam  The `?format=` value
	 * @param string|null $acceptHeader The Accept header
	 * @param string|null $expected     The expected canonical format
	 *
	 * @return void
	 */
	#[DataProvider('negotiationCases')]
	public function testNegotiate(?string $formatParam, ?string $acceptHeader, ?string $expected): void {
		$this->assertSame($expected, (new DcatSerializer())->negotiate($formatParam, $acceptHeader));

	}//end testNegotiate()
}//end class
