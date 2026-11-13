<?php

/**
 * Unit tests for TooiVocabularyService.
 *
 * Covers the three DIWOO axes' resolution to official TOOI/DiWoo value-list URIs
 * and the fail-closed behaviour (unresolvable input → null so the caller omits
 * the axis rather than leaking a literal).
 *
 * @category Test
 * @package  Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2025 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\StandardsVersionService;
use OCA\OpenCatalogi\Service\TooiVocabularyService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TooiVocabularyService.
 */
class TooiVocabularyServiceTest extends TestCase {

	private TooiVocabularyService $service;

	/**
	 * Set up the service under test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->service = new TooiVocabularyService();

	}//end setUp()

	/**
	 * A category resolves by sitemap code, canonical label, alias, bare id, and full URI.
	 *
	 * @return void
	 */
	public function testResolveInformatiecategorieAcceptsMultipleKeys(): void {
		$expected = 'https://identifier.overheid.nl/tooi/def/thes/kern/c_3baef532';

		foreach (['infocat014', 'Woo-verzoeken en -besluiten', 'woo-verzoek', 'c_3baef532', $expected] as $input) {
			$resolved = $this->service->resolveInformatiecategorie($input);
			$this->assertNotNull($resolved, "should resolve: $input");
			$this->assertSame($expected, $resolved['uri']);
			$this->assertSame('Woo-verzoeken en -besluiten', $resolved['label']);
		}

	}//end testResolveInformatiecategorieAcceptsMultipleKeys()

	/**
	 * An unknown category returns null (fail closed).
	 *
	 * @return void
	 */
	public function testResolveInformatiecategorieFailsClosed(): void {
		$this->assertNull($this->service->resolveInformatiecategorie('https://example.com/tooi/woo'));
		$this->assertNull($this->service->resolveInformatiecategorie(''));
		$this->assertNull($this->service->resolveInformatiecategorie(null));

	}//end testResolveInformatiecategorieFailsClosed()

	/**
	 * The bundled list contains all 18 Woo categories.
	 *
	 * @return void
	 */
	public function testInformatiecategorieListHas18Members(): void {
		// Version 4 of the scw_woo_informatiecategorieen waardelijst, which DiWoo
		// 0.9.8 binds, has 18 members: the 17 art. 3.3 categories plus the art. 3.1
		// inspanningsverplichting.
		$this->assertCount(18, $this->service->informatiecategorieList());
		$this->assertSame(
			'https://identifier.overheid.nl/tooi/def/thes/kern/c_816e508d',
			$this->service->informatiecategorieList()['infocat018']['uri']
		);

	}//end testInformatiecategorieListHas18Members()

	/**
	 * soortHandeling defaults to ontvangst and honours a declared member.
	 *
	 * @return void
	 */
	public function testResolveSoortHandeling(): void {
		$default = $this->service->resolveSoortHandeling(null);
		$this->assertSame('ontvangst', $default['label']);
		$this->assertSame('https://identifier.overheid.nl/tooi/def/thes/kern/c_dfcee535', $default['uri']);

		$declared = $this->service->resolveSoortHandeling('vaststelling');
		$this->assertSame('vaststelling', $declared['label']);
		$this->assertSame('https://identifier.overheid.nl/tooi/def/thes/kern/c_641ecd76', $declared['uri']);

		$this->assertNull($this->service->resolveSoortHandeling('nonexistent-handling'));

	}//end testResolveSoortHandeling()

	/**
	 * A blank stored soortHandeling falls back to the default, not to null.
	 *
	 * An empty string is what a form leaves behind when the field is cleared, and
	 * reading it as "no member" would drop the axis from every document on that
	 * publication instead of claiming receipt.
	 *
	 * @return void
	 */
	public function testABlankSoortHandelingFallsBackToTheDefault(): void {
		foreach (['', '   '] as $blank) {
			$resolved = $this->service->resolveSoortHandeling($blank);
			$this->assertNotNull($resolved, 'A blank handling type resolved to nothing.');
			$this->assertSame(TooiVocabularyService::DEFAULT_SOORTHANDELING, $resolved['label']);
		}

	}//end testABlankSoortHandelingFallsBackToTheDefault()

	/**
	 * Only a well-formed TOOI organisatie URI is accepted; a UUID is rejected.
	 *
	 * @return void
	 */
	public function testResolveOrganisatie(): void {
		$uri = 'https://identifier.overheid.nl/tooi/id/gemeente/gm0855';
		$this->assertSame($uri, $this->service->resolveOrganisatie($uri));

		$this->assertNull($this->service->resolveOrganisatie('org-uuid-123'));
		$this->assertNull($this->service->resolveOrganisatie('https://example.com/org/1'));
		$this->assertNull($this->service->resolveOrganisatie(null));

	}//end testResolveOrganisatie()

	/**
	 * The enumeration of one simple type in the DiWoo value-list XSD, as the
	 * authority publishes it: `{code, label, uri}` per member, in XSD order.
	 *
	 * @param string $typeName The simple type, e.g. `documentsoortlijst`.
	 *
	 * @return array<int, array{code: string, label: string, uri: string}> The members.
	 */
	private function xsdMembers(string $typeName): array {
		$xsd = new \DOMDocument();
		$xsd->load(__DIR__ . '/fixtures/diwoo-0.9.8/diwoo-metadata-lijsten.xsd');
		$xpath = new \DOMXPath($xsd);
		$xpath->registerNamespace('xs', 'http://www.w3.org/2001/XMLSchema');

		$members = [];
		foreach ($xpath->query("/xs:schema/xs:simpleType[@name='" . $typeName . "']/xs:restriction/xs:enumeration") as $enumeration) {
			$uri = $enumeration->getAttribute('value');
			$members[] = [
				'code' => substr($uri, strrpos($uri, '/') + 1),
				'label' => trim($enumeration->textContent),
				'uri' => $uri,
			];
		}

		return $members;

	}//end xsdMembers()

	/**
	 * REQ-DWP-002: the bundled documentsoorten are exactly the members the
	 * declared DiWoo 0.9.8 value-list XSD enumerates, with their code, label
	 * and URI, and the bundle names that XSD as its source.
	 *
	 * @return void
	 */
	public function testTheBundledDocumentsoortenMatchTheDeclaredXsd(): void {
		$expected = $this->xsdMembers(typeName: 'documentsoortlijst');

		$this->assertCount(91, $expected, 'the XSD copy enumerates 91 documentsoorten');
		$this->assertSame($expected, $this->service->documentsoortList());

		$bundle = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/tooi_waardelijsten.json'), true);
		$this->assertSame(StandardsVersionService::DIWOO_LISTS_XSD, $bundle['documentsoorten']['source']);
		$this->assertSame(StandardsVersionService::DIWOO_VERSION, $bundle['documentsoorten']['xsdVersion']);

	}//end testTheBundledDocumentsoortenMatchTheDeclaredXsd()

	/**
	 * REQ-DWP-002: the bundled languages are the members of the XSD's language list.
	 *
	 * @return void
	 */
	public function testTheBundledLanguagesMatchTheDeclaredXsd(): void {
		$this->assertSame($this->xsdMembers(typeName: 'taallijst'), $this->service->languageList());

		$bundle = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/tooi_waardelijsten.json'), true);
		$this->assertSame(StandardsVersionService::DIWOO_LISTS_XSD, $bundle['talen']['source']);

	}//end testTheBundledLanguagesMatchTheDeclaredXsd()

	/**
	 * REQ-DWP-003: the mandatory-element fixture is what the declared metadata
	 * XSD requires: its hash matches the XSD copy, and each path's elements are
	 * declared without minOccurs="0".
	 *
	 * @return void
	 */
	public function testTheMandatoryElementFixtureFollowsTheXsd(): void {
		$fixture = json_decode((string)file_get_contents(__DIR__ . '/../../fixtures/diwoo/0.9.8-mandatory-elements.json'), true);
		$xsdPath = __DIR__ . '/fixtures/diwoo-0.9.8/diwoo-metadata.xsd';

		$this->assertSame(StandardsVersionService::DIWOO_METADATA_XSD, $fixture['source']);
		$this->assertSame(hash_file('sha256', $xsdPath), $fixture['sha256']);
		$this->assertSame(
			[
				'publisher',
				'titelcollectie/officieleTitel',
				'classificatiecollectie/informatiecategorieen/informatiecategorie',
				'documenthandelingen/documenthandeling/soortHandeling',
				'documenthandelingen/documenthandeling/atTime',
			],
			$fixture['mandatory']
		);

		$xsd = new \DOMDocument();
		$xsd->load($xsdPath);
		$xpath = new \DOMXPath($xsd);
		$xpath->registerNamespace('xs', 'http://www.w3.org/2001/XMLSchema');
		foreach ($fixture['mandatory'] as $path) {
			foreach (explode('/', $path) as $name) {
				$declarations = $xpath->query("//xs:complexType//xs:element[@name='" . $name . "']");
				$this->assertGreaterThan(0, $declarations->length, $name . ' is declared');
				$this->assertNotSame('0', $declarations->item(0)->getAttribute('minOccurs'), $name . ' is mandatory');
			}
		}

	}//end testTheMandatoryElementFixtureFollowsTheXsd()

	/**
	 * REQ-DWP-002: a document type resolves from its URI, its code or its label,
	 * case-insensitive, to the same member.
	 *
	 * @return void
	 */
	public function testADocumentsoortResolvesFromUriCodeOrLabel(): void {
		$expected = [
			'code' => 'c_852da199',
			'label' => 'Afkondigingsblad',
			'uri' => 'https://identifier.overheid.nl/tooi/def/thes/kern/c_852da199',
		];

		$this->assertSame($expected, $this->service->resolveDocumentsoort('https://identifier.overheid.nl/tooi/def/thes/kern/c_852da199'));
		$this->assertSame($expected, $this->service->resolveDocumentsoort('c_852da199'));
		$this->assertSame($expected, $this->service->resolveDocumentsoort('Afkondigingsblad'));
		$this->assertSame($expected, $this->service->resolveDocumentsoort('  aFKONDIGINGSBLAD '));

	}//end testADocumentsoortResolvesFromUriCodeOrLabel()

	/**
	 * REQ-DWP-002: anything that is not a member resolves to null, never to a literal.
	 *
	 * @return void
	 */
	public function testAnUnknownDocumentsoortResolvesToNull(): void {
		$this->assertNull($this->service->resolveDocumentsoort('memo van de wethouder'));
		$this->assertNull($this->service->resolveDocumentsoort('Afkondigings'));
		$this->assertNull($this->service->resolveDocumentsoort(''));
		$this->assertNull($this->service->resolveDocumentsoort(null));

	}//end testAnUnknownDocumentsoortResolvesToNull()

	/**
	 * A language resolves from its URI, code or label; anything else is null.
	 *
	 * @return void
	 */
	public function testALanguageResolvesFromUriCodeOrLabel(): void {
		$dutch = [
			'code' => 'NLD',
			'label' => 'Nederlands',
			'uri' => 'http://publications.europa.eu/resource/authority/language/NLD',
		];

		$this->assertSame($dutch, $this->service->resolveLanguage('http://publications.europa.eu/resource/authority/language/NLD'));
		$this->assertSame($dutch, $this->service->resolveLanguage('nld'));
		$this->assertSame($dutch, $this->service->resolveLanguage('Nederlands'));
		$this->assertNull($this->service->resolveLanguage('Klingon'));
		$this->assertNull($this->service->resolveLanguage(null));

	}//end testALanguageResolvesFromUriCodeOrLabel()
}//end class
