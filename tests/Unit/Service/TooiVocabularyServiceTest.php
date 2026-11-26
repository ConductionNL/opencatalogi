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
use Unit\Support\RecordedTooiRegister;

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
		$this->service = \Unit\Support\RecordedTooiRegister::service();

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
	 * The register holds all 18 Woo categories.
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
				'label' => self::memberLabel(enumeration: $enumeration, xpath: $xpath),
				'uri' => $uri,
			];
		}

		return $members;

	}//end xsdMembers()

	/**
	 * A member's label: the first paragraph of its documentation, which is the
	 * term; the paragraphs after it are the definition and the legal reference.
	 *
	 * @param \DOMElement $enumeration The XSD enumeration.
	 * @param \DOMXPath $xpath The XPath over the XSD.
	 *
	 * @return string The label.
	 */
	private static function memberLabel(\DOMElement $enumeration, \DOMXPath $xpath): string {
		$paragraphs = $xpath->query(".//*[local-name()='p']", $enumeration);
		if ($paragraphs->length > 0) {
			return trim($paragraphs->item(0)->textContent);
		}

		return trim($enumeration->textContent);

	}//end memberLabel()

	/**
	 * A list as the XSD sorts nothing: by label, as the service answers it.
	 *
	 * @param array<int, array{code: string, label: string, uri: string}> $members The members.
	 *
	 * @return array<int, array{code: string, label: string, uri: string}> The members by label.
	 */
	private static function byLabel(array $members): array {
		usort($members, static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));
		return $members;

	}//end byLabel()

	/**
	 * REQ-DWP-002: the documentsoorten the refreshed register holds (the
	 * recorded TOOI download of the version DiWoo 0.9.8 binds) are exactly the
	 * members the declared value-list XSD enumerates, with code, label and URI.
	 *
	 * @return void
	 */
	public function testTheRegisteredDocumentsoortenMatchTheDeclaredXsd(): void {
		$expected = $this->xsdMembers(typeName: 'documentsoortlijst');

		$this->assertCount(91, $expected, 'the XSD copy enumerates 91 documentsoorten');
		$this->assertSame(self::byLabel(members: $expected), $this->service->documentsoortList());

	}//end testTheRegisteredDocumentsoortenMatchTheDeclaredXsd()

	/**
	 * REQ-DWP-002: the languages the refreshed register holds are the members of the XSD's language list.
	 *
	 * @return void
	 */
	public function testTheRegisteredLanguagesMatchTheDeclaredXsd(): void {
		$this->assertSame(self::byLabel(members: $this->xsdMembers(typeName: 'taallijst')), $this->service->languageList());

	}//end testTheRegisteredLanguagesMatchTheDeclaredXsd()

	/**
	 * REQ-WVC-001: a category resolves from the register: its URI is a concept
	 * the register holds, and a register without that concept resolves nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-value-lists-on-the-concept-register/specs/woo-compliance/spec.md#requirement-the-tooi-lists-are-read-from-openregisters-vocabulary-register-req-wvc-001
	 */
	public function testTheCategoryResolvesFromTheVocabularyRegister(): void {
		$register = new RecordedTooiRegister();
		$service = new TooiVocabularyService(register: $register);

		$this->assertSame(
			['uri' => 'https://identifier.overheid.nl/tooi/def/thes/kern/c_c6cd1213', 'label' => 'Jaarplannen en jaarverslagen'],
			$service->resolveInformatiecategorie('infocat012')
		);
		$this->assertSame([TooiVocabularyService::CATEGORIES_SCHEME], $register->reads);
		// The register's own label names the category too.
		$this->assertSame('https://identifier.overheid.nl/tooi/def/thes/kern/c_c6cd1213', $service->resolveInformatiecategorie('jaarplan of jaarverslag')['uri']);
		// One read per request.
		$service->informatiecategorieList();
		$this->assertCount(1, $register->reads);

		$empty = new TooiVocabularyService(register: new RecordedTooiRegister(empty: [TooiVocabularyService::CATEGORIES_SCHEME]));
		$this->assertNull($empty->resolveInformatiecategorie('infocat012'));
		$this->assertSame([], $empty->informatiecategorieList());

	}//end testTheCategoryResolvesFromTheVocabularyRegister()

	/**
	 * REQ-WVC-001: an unreadable register throws on every list; no bundled copy stands in.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-value-lists-on-the-concept-register/specs/woo-compliance/spec.md#requirement-the-tooi-lists-are-read-from-openregisters-vocabulary-register-req-wvc-001
	 */
	public function testAnUnreadableRegisterThrows(): void {
		$service = RecordedTooiRegister::unreadableService();
		$calls = [
			'category' => static fn () => $service->resolveInformatiecategorie('infocat012'),
			'handling' => static fn () => $service->resolveSoortHandeling(null),
			'document type' => static fn () => $service->resolveDocumentsoort('c_852da199'),
			'language' => static fn () => $service->resolveLanguage('NLD'),
			'category list' => static fn () => $service->informatiecategorieList(),
			'handling list' => static fn () => $service->soortHandelingList(),
			'new value' => static fn () => $service->acceptsNewValue('https://identifier.overheid.nl/tooi/def/thes/kern/c_c6cd1213'),
		];

		foreach ($calls as $name => $call) {
			try {
				$call();
				$this->fail($name . ' answered from no register.');
			} catch (\RuntimeException $e) {
				$this->assertStringContainsString('cannot be read', $e->getMessage(), $name);
				$this->assertInstanceOf(\RuntimeException::class, $e->getPrevious(), $name);
			}
		}

		$this->expectException(\RuntimeException::class);
		(new TooiVocabularyService())->resolveDocumentsoort('c_852da199');

	}//end testAnUnreadableRegisterThrows()

	/**
	 * REQ-WVC-001: a deprecated concept still resolves for a stored record and is refused as a new value.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-value-lists-on-the-concept-register/specs/woo-compliance/spec.md#requirement-the-tooi-lists-are-read-from-openregisters-vocabulary-register-req-wvc-001
	 */
	public function testADeprecatedConceptResolvesButIsRefusedForANewValue(): void {
		$advies = 'https://identifier.overheid.nl/tooi/def/thes/kern/c_99a836c7';
		$service = new TooiVocabularyService(register: new RecordedTooiRegister(deprecated: [$advies]));

		$this->assertSame($advies, $service->resolveInformatiecategorie('infocat010')['uri']);
		$this->assertFalse($service->acceptsNewValue($advies));
		$this->assertTrue($service->acceptsNewValue('https://identifier.overheid.nl/tooi/def/thes/kern/c_c6cd1213'));
		$this->assertTrue($service->acceptsNewValue('http://publications.europa.eu/resource/authority/language/NLD'));
		$this->assertFalse($service->acceptsNewValue('https://example.com/not-a-member'));

	}//end testADeprecatedConceptResolvesButIsRefusedForANewValue()

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
