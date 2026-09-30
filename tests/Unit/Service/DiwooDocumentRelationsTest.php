<?php

/**
 * Unit tests for DiwooDocumentRelations.
 *
 * The relation elements are pinned against the DiWoo metadata XSD 0.9.1, kept
 * under fixtures/diwoo/ with its value-list include pointed at the local copy.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Http\XMLResponse;
use OCA\OpenCatalogi\Service\DiwooDocumentRelations;
use PHPUnit\Framework\TestCase;

/**
 * lastmod and the isPartOf / hasParts relations.
 */
class DiwooDocumentRelationsTest extends TestCase {

	private const PUBLICATION_URL = 'https://woo.example.nl/apps/opencatalogi/api/woo/pub-1';

	private DiwooDocumentRelations $relations;

	protected function setUp(): void {
		parent::setUp();
		$this->relations = new DiwooDocumentRelations();
	}

	/**
	 * A formatted file.
	 *
	 * @param string             $name   The file name.
	 * @param array<int, string> $labels The labels.
	 */
	private function file(string $name, array $labels = []): array {
		return ['downloadUrl' => 'https://woo.example.nl/files/' . $name, 'title' => $name, 'labels' => $labels, 'extension' => 'pdf'];
	}

	/**
	 * Render relation elements inside a minimal DiWoo document and validate it.
	 *
	 * @param array<string, mixed> $elements The relation elements of one document.
	 *
	 * @return boolean Whether the XSD accepts the document.
	 */
	private function xsdAccepts(array $elements): bool {
		$diwoo = [
			'diwoo:publisher' => ['@attributes' => ['resource' => 'https://identifier.overheid.nl/tooi/id/gemeente/gm0855'], '#text' => 'Tilburg'],
			'diwoo:titelcollectie' => ['diwoo:officieleTitel' => 'Besluit'],
			'diwoo:classificatiecollectie' => [
				'diwoo:informatiecategorieen' => [
					'diwoo:informatiecategorie' => ['@attributes' => ['resource' => 'https://identifier.overheid.nl/tooi/def/thes/kern/c_3baef532'], '#text' => 'Woo-verzoeken en -besluiten'],
				],
			],
			'diwoo:creatiedatum' => '2026-03-01',
		];
		$diwoo = array_merge($diwoo, $elements);
		$diwoo['diwoo:documenthandelingen'] = [
			'diwoo:documenthandeling' => [
				'diwoo:soortHandeling' => ['@attributes' => ['resource' => 'https://identifier.overheid.nl/tooi/def/thes/kern/c_641ecd76'], '#text' => 'vaststelling'],
				'diwoo:atTime' => '2026-03-01T09:00:00',
			],
		];

		$xml = (new XMLResponse([]))->arrayToXml(
			['@attributes' => ['xmlns:diwoo' => 'https://standaarden.overheid.nl/diwoo/metadata/'], 'diwoo:DiWoo' => $diwoo],
			'diwoo:Document'
		);

		$previous = libxml_use_internal_errors(true);
		$dom = new \DOMDocument();
		$dom->loadXML($xml);
		$valid = $dom->schemaValidate(__DIR__ . '/fixtures/diwoo/diwoo-metadata.xsd');
		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		return $valid;
	}

	/** REQ-WSD-001: an editor corrects the title of a published decision. */
	public function testAnEditorCorrectsTheTitleOfAPublishedDecision(): void {
		$publication = ['@self' => ['updated' => '2026-03-20 10:00:00']];
		$file = ['published' => '2026-03-01 09:00:00', 'modified' => '2026-03-01 08:00:00'];

		$this->assertSame('2026-03-20 10:00:00', $this->relations->lastmod($publication, $file));
	}

	/** REQ-WSD-001: a new file version is uploaded. */
	public function testANewFileVersionIsUploaded(): void {
		$publication = ['@self' => ['updated' => '2026-03-01 10:00:00']];
		$file = ['published' => '2026-02-01 09:00:00', 'modified' => '2026-03-05 11:00:00'];

		$this->assertSame('2026-03-05 11:00:00', $this->relations->lastmod($publication, $file));
	}

	/** REQ-WSD-002: a single document carries no relation. */
	public function testASingleDocument(): void {
		$result = $this->relations->forPublication([$this->file('besluit.pdf')], self::PUBLICATION_URL, 'Besluit');

		$this->assertSame([], $result['relations']);
	}

	/** REQ-WSD-002: a decision with two annexes and no main document points each at the publication. */
	public function testADecisionWithTwoAnnexes(): void {
		$files = [$this->file('besluit.pdf'), $this->file('bijlage-1.pdf'), $this->file('bijlage-2.pdf')];
		$result = $this->relations->forPublication($files, self::PUBLICATION_URL, 'Besluit');

		$this->assertCount(3, $result['relations']);
		foreach ($result['relations'] as $elements) {
			$this->assertSame(self::PUBLICATION_URL, $elements['diwoo:isPartOf']['@attributes']['resource']);
			$this->assertArrayNotHasKey('diwoo:hasParts', $elements);
		}

		$this->assertTrue($this->xsdAccepts($result['relations']['https://woo.example.nl/files/bijlage-1.pdf']));
	}

	/** REQ-WSD-003: an editor marks the decision as the main document. */
	public function testAnEditorMarksTheDecisionAsTheMainDocument(): void {
		$files = [$this->file('bijlage-1.pdf'), $this->file('besluit.pdf', ['main-document']), $this->file('bijlage-2.pdf')];
		$result = $this->relations->forPublication($files, self::PUBLICATION_URL, 'Besluit');
		$main = $result['relations']['https://woo.example.nl/files/besluit.pdf'];

		$this->assertSame(
			['https://woo.example.nl/files/bijlage-1.pdf', 'https://woo.example.nl/files/bijlage-2.pdf'],
			array_map(static fn (array $part): string => $part['@attributes']['resource'], $main['diwoo:hasParts']['diwoo:hasPart'])
		);
		$annex = $result['relations']['https://woo.example.nl/files/bijlage-2.pdf'];
		$this->assertSame('https://woo.example.nl/files/besluit.pdf', $annex['diwoo:isPartOf']['@attributes']['resource']);

		$this->assertTrue($this->xsdAccepts($main), 'The XSD refuses the main document with its parts.');
		$this->assertTrue($this->xsdAccepts($annex), 'The XSD refuses an annex pointing at the main document.');
	}

	/** The XSD fixture is a real check: hasParts after documenthandelingen is refused. */
	public function testTheXsdRefusesARelationInTheWrongPlace(): void {
		$wrongPlace = ['diwoo:documenthandelingen' => ['diwoo:documenthandeling' => []], 'diwoo:isPartOf' => ['@attributes' => ['resource' => 'x'], '#text' => 'x']];
		$this->assertFalse($this->xsdAccepts($wrongPlace));
	}

	/** REQ-WSD-003: two files marked by mistake fall back to the publication and are counted. */
	public function testTwoFilesMarkedByMistake(): void {
		$files = [$this->file('besluit.pdf', ['main-document']), $this->file('bijlage-1.pdf', ['main-document'])];
		$result = $this->relations->forPublication($files, self::PUBLICATION_URL, 'Besluit');

		$this->assertSame(2, $result['mainDocuments']);
		$this->assertSame(self::PUBLICATION_URL, $result['relations']['https://woo.example.nl/files/besluit.pdf']['diwoo:isPartOf']['@attributes']['resource']);
	}
}
