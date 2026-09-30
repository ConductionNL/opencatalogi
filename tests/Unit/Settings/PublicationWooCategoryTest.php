<?php

/**
 * The publication schema stores a Woo information category (REQ-WPC-001).
 *
 * Validates real payloads against the SHIPPED `publication` fragment in
 * lib/Settings/publication_register.json with the same Opis validator
 * OpenRegister uses, so a value the schema refuses can never pass here.
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

use OCA\OpenCatalogi\Service\SitemapService;
use OCA\OpenCatalogi\Service\WooCategory;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the publication schema's wooCategory property.
 */
class PublicationWooCategoryTest extends TestCase {

	/**
	 * The shipped publication schema fragment.
	 *
	 * @return array<string, mixed>
	 */
	private function publicationSchema(): array {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/publication_register.json'), true);
		return $register['components']['schemas']['publication'];
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

	public function testTheSchemaDeclaresTheSeventeenCodes(): void {
		$property = ($this->publicationSchema()['properties']['wooCategory'] ?? null);
		$this->assertNotNull($property, 'The publication schema declares no wooCategory.');
		$this->assertSame('string', $property['type']);
		$this->assertArrayNotHasKey('format', $property);

		$expected = [];
		foreach (array_keys(SitemapService::INFO_CAT) as $file) {
			preg_match('/infocat\d{3}/', $file, $match);
			$expected[] = $match[0];
		}

		$this->assertSame($expected, $property['enum']);
		$this->assertSame($expected, array_keys(WooCategory::ALL));
		$this->assertSame(array_keys($property['x-enum-labels']), $expected);
		foreach (WooCategory::ALL as $code => $names) {
			$this->assertSame($names['en'], $property['x-enum-labels'][$code]);
		}
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
