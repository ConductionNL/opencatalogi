<?php
/**
 * Unit tests for DcatSerializer.
 *
 * Pure serialization tests: content negotiation (Accept + ?format=), 406 on
 * unsupported formats, and identical graph across JSON-LD / Turtle / RDF-XML.
 * No Nextcloud bootstrap required.
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
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DcatSerializer.
 */
class DcatSerializerTest extends TestCase
{

    private DcatSerializer $serializer;

    protected function setUp(): void
    {
        $this->serializer = new DcatSerializer();
    }

    private function sampleDocument(): array
    {
        return [
            '@context' => [
                'dcat' => 'http://www.w3.org/ns/dcat#',
                'dct'  => 'http://purl.org/dc/terms/',
                'foaf' => 'http://xmlns.com/foaf/0.1/',
            ],
            '@graph' => [
                [
                    '@id'          => 'https://host/api/catalogs/woo/dcat',
                    '@type'        => 'dcat:Catalog',
                    'dct:title'    => 'WOO besluiten',
                    'dcat:dataset' => [['@id' => 'https://host/api/woo/u1'], ['@id' => 'https://host/api/woo/u2']],
                ],
                [
                    '@id'           => 'https://host/api/woo/u1',
                    '@type'         => 'dcat:Dataset',
                    'dct:title'     => 'Dataset one',
                    'dct:publisher' => ['@type' => 'foaf:Agent', 'foaf:name' => 'Gemeente Tilburg'],
                    'dcat:keyword'  => ['a', 'b'],
                ],
                [
                    '@id'        => 'https://host/api/woo/u2',
                    '@type'      => 'dcat:Dataset',
                    'dct:title'  => 'Dataset two',
                ],
            ],
        ];
    }

    public function testNegotiateDefaultsToJsonLd(): void
    {
        $this->assertSame('jsonld', $this->serializer->negotiate(null, null));
        $this->assertSame('jsonld', $this->serializer->negotiate(null, '*/*'));
        $this->assertSame('jsonld', $this->serializer->negotiate(null, 'application/ld+json'));
    }

    public function testNegotiateTurtleViaAccept(): void
    {
        $this->assertSame('turtle', $this->serializer->negotiate(null, 'text/turtle'));
    }

    public function testNegotiateRdfXmlViaAccept(): void
    {
        $this->assertSame('rdfxml', $this->serializer->negotiate(null, 'application/rdf+xml'));
    }

    public function testFormatQueryParameterOverridesAccept(): void
    {
        $this->assertSame('rdfxml', $this->serializer->negotiate('rdfxml', '*/*'));
        $this->assertSame('turtle', $this->serializer->negotiate('ttl', 'application/ld+json'));
    }

    public function testUnsupportedFormatReturnsNull(): void
    {
        $this->assertNull($this->serializer->negotiate('excel', '*/*'));
        $this->assertNull($this->serializer->negotiate(null, 'application/vnd.ms-excel'));
    }

    public function testJsonLdRoundTrips(): void
    {
        $json    = $this->serializer->serialize($this->sampleDocument(), 'jsonld');
        $decoded = json_decode($json, true);
        $this->assertSame($this->sampleDocument(), $decoded);
        $this->assertCount(3, $decoded['@graph']);
    }

    public function testTurtleContainsSameDatasetCount(): void
    {
        $turtle = $this->serializer->serialize($this->sampleDocument(), 'turtle');
        $this->assertStringContainsString('@prefix dcat: <http://www.w3.org/ns/dcat#> .', $turtle);
        $this->assertSame(2, substr_count($turtle, 'dcat:Dataset'));
        $this->assertSame(1, substr_count($turtle, 'dcat:Catalog'));
        $this->assertStringContainsString('"Gemeente Tilburg"', $turtle);
    }

    public function testRdfXmlContainsSameDatasetCount(): void
    {
        $xml = $this->serializer->serialize($this->sampleDocument(), 'rdfxml');
        $this->assertStringContainsString('<?xml version="1.0"', $xml);
        $this->assertSame(2, substr_count($xml, '>dcat:Dataset<'));
        // RDF/XML must be well-formed.
        $loaded = simplexml_load_string($xml);
        $this->assertNotFalse($loaded);
    }

    public function testAllSerializationsExpressSameNodes(): void
    {
        $document = $this->sampleDocument();
        $nodes    = $this->serializer->graphNodes($document);
        $this->assertCount(3, $nodes);

        $jsonld = $this->serializer->serialize($document, 'jsonld');
        $turtle = $this->serializer->serialize($document, 'turtle');
        $rdfxml = $this->serializer->serialize($document, 'rdfxml');

        foreach (['https://host/api/woo/u1', 'https://host/api/woo/u2', 'https://host/api/catalogs/woo/dcat'] as $iri) {
            $this->assertStringContainsString($iri, $jsonld);
            $this->assertStringContainsString($iri, $turtle);
            $this->assertStringContainsString($iri, $rdfxml);
        }
    }

    public function testFormatsConstantListsThreeSerializations(): void
    {
        $this->assertSame(['jsonld', 'turtle', 'rdfxml'], array_keys(DcatSerializer::FORMATS));
        $this->assertSame('application/ld+json', DcatSerializer::FORMATS['jsonld']);
    }
}//end class
