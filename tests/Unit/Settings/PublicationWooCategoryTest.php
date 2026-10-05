<?php

/**
 * The publication schema stores a Woo information category (REQ-WPC-001).
 *
 * Validates real payloads against the SHIPPED `publication` schema with the same
 * Opis validator OpenRegister uses, so a value the schema refuses can never pass
 * here. The schema is the monolith in lib/Settings/publication_register.json with
 * every lib/Settings/register.d fragment merged onto it through the production
 * merge, because that merge is what the instance imports: reading the monolith
 * alone asserts a schema nobody runs.
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
 *
 * @spec openspec/specs/woo-compliance/spec.md
 */

declare(strict_types=1);

namespace Unit\Settings;

use OCA\OpenCatalogi\Service\SettingsService;
use OCA\OpenCatalogi\Service\TooiVocabularyService;
use OCA\OpenCatalogi\Service\Woo\WooCategoryRegistry;
use OCA\OpenCatalogi\Service\WooCategory;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the publication schema's wooCategory property.
 */
class PublicationWooCategoryTest extends TestCase {

	/**
	 * The shipped publication schema, monolith plus every register.d fragment.
	 *
	 * Merged with SettingsService::deepMergeConfig() itself, by filename order, so
	 * the test cannot pass against a merge of its own invention.
	 *
	 * @return array<string, mixed>
	 */
	private function publicationSchema(): array {
		$settings = __DIR__ . '/../../../lib/Settings';
		$merged = json_decode((string)file_get_contents($settings . '/publication_register.json'), true);

		$merge = new \ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		$merge->setAccessible(true);

		$fragments = glob($settings . '/register.d/*.json');
		sort($fragments);
		foreach ($fragments as $fragment) {
			$overlay = json_decode((string)file_get_contents($fragment), true);
			$merged = $merge->invoke(null, $merged, $overlay);
		}

		return $merged['components']['schemas']['publication'];
	}

	/**
	 * Validate a payload against the real fragment (properties + required).
	 *
	 * @param array<string, mixed> $payload The publication payload.
	 *
	 * @return array{0: bool, 1: array<string, mixed>}
	 */
	private function validate(array $payload): array {
		$fragment = $this->publicationSchema();
		$schema = [
			'type' => 'object',
			'properties' => $fragment['properties'],
			'required' => $fragment['required'],
		];
		$result = (new Validator())->validate(json_decode((string)json_encode($payload)), (string)json_encode($schema));
		$errors = [];
		if ($result->isValid() === false) {
			$errors = (new ErrorFormatter())->format($result->error());
		}

		return [$result->isValid(), $errors];
	}

	public function testTheSchemaDeclaresEveryWaardelijstCode(): void {
		$property = ($this->publicationSchema()['properties']['wooCategory'] ?? null);
		$this->assertNotNull($property, 'The publication schema declares no wooCategory.');
		$this->assertSame('string', $property['type']);
		$this->assertArrayNotHasKey('format', $property);

		// The waardelijst resolver is the one authority for the member list.
		$expected = array_keys((new TooiVocabularyService())->informatiecategorieList());
		$this->assertCount(18, $expected);
		$this->assertContains('infocat018', $expected, 'The art 3.1 category is missing.');

		$this->assertSame($expected, $property['enum']);
		$this->assertSame($expected, array_keys(WooCategory::ALL));
		$this->assertSame(array_keys($property['x-enum-labels']), $expected);
		foreach (WooCategory::ALL as $code => $names) {
			$this->assertSame($names['en'], $property['x-enum-labels'][$code]);
		}
	}

	public function testEveryCodeRoundTripsThroughItsSitemapFileName(): void {
		// The national harvester matches the file name, so the convention is
		// load-bearing: a code whose file name does not parse back is not published.
		foreach (array_keys((new TooiVocabularyService())->informatiecategorieList()) as $code) {
			$file = WooCategoryRegistry::sitemapFileFor(code: $code);
			$this->assertSame('sitemapindex-diwoo-' . $code . '.xml', $file);
			$this->assertSame($code, WooCategoryRegistry::codeOf(sitemapFile: $file));
		}
	}

	public function testTheSchemaStoresADocumenthandeling(): void {
		$property = ($this->publicationSchema()['properties']['soortHandeling'] ?? null);
		$this->assertNotNull($property, 'The publication schema stores no soortHandeling.');
		$this->assertSame('string', $property['type']);

		// The enum is the DiWoo documenthandelingen value list, nothing else.
		$expected = array_keys((new TooiVocabularyService())->soortHandelingList());
		$this->assertSame($expected, $property['enum']);
		$this->assertSame($expected, array_keys($property['x-enum-labels']));
	}

	public function testAStoredDocumenthandelingIsAccepted(): void {
		[$valid] = $this->validate(
			['title' => 'Besluit', 'wooCategory' => 'infocat016', 'soortHandeling' => 'vaststelling']
		);
		$this->assertTrue($valid);
	}

	public function testADocumenthandelingOutsideTheValueListIsRefused(): void {
		[$valid, $errors] = $this->validate(['title' => 'Besluit', 'soortHandeling' => 'inzage']);
		$this->assertFalse($valid);
		$this->assertArrayHasKey('/soortHandeling', $errors);
	}

	public function testTheArt31CategoryIsAccepted(): void {
		[$valid] = $this->validate(['title' => 'Inspanningsverplichting', 'wooCategory' => 'infocat018']);
		$this->assertTrue($valid);
	}

	public function testAFiledPublicationIsAccepted(): void {
		[$valid] = $this->validate(['title' => 'Jaarverslag 2025', 'wooCategory' => 'infocat012']);
		$this->assertTrue($valid);
	}

	public function testAPublicationWithoutACategoryIsAccepted(): void {
		[$valid] = $this->validate(['title' => 'Nieuwsbericht']);
		$this->assertTrue($valid);
	}

	public function testAnUnknownCodeIsRefusedNamingTheProperty(): void {
		[$valid, $errors] = $this->validate(['title' => 'Jaarverslag 2025', 'wooCategory' => 'infocat099']);
		$this->assertFalse($valid);
		$this->assertArrayHasKey('/wooCategory', $errors);
	}
}
