<?php

/**
 * The publication schema carries a plain language summary with its reading
 * level and its provenance (REQ-PPL-001).
 *
 * Validates payloads against the SHIPPED `publication` schema: the monolith with
 * every register.d fragment merged on through the production merge, with the
 * Opis validator OpenRegister uses.
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
 * @spec openspec/changes/publication-plain-language-and-translation/specs/publications/spec.md
 */

declare(strict_types=1);

namespace Unit\Settings;

use OCA\OpenCatalogi\Service\SettingsService;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the plain summary properties of the publication schema.
 *
 * @covers \OCA\OpenCatalogi\Service\SettingsService::deepMergeConfig
 */
class PlainSummarySchemaTest extends TestCase {

	/**
	 * The shipped publication schema, monolith plus every register.d fragment.
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
	 * Validate a payload against the merged schema's properties.
	 *
	 * @param array<string, mixed> $payload The publication payload.
	 *
	 * @return array{0: bool, 1: array<string, mixed>}
	 */
	private function validate(array $payload): array {
		$schema = [
			'type' => 'object',
			'properties' => $this->publicationSchema()['properties'],
		];
		$result = (new Validator())->validate(json_decode((string)json_encode($payload)), (string)json_encode($schema));
		$errors = [];
		if ($result->isValid() === false) {
			$errors = (new ErrorFormatter())->format($result->error());
		}

		return [$result->isValid(), $errors];
	}

	public function testTheFieldsAndTheDefaultLevel(): void {
		$properties = $this->publicationSchema()['properties'];

		$this->assertSame('string', $properties['plainSummary']['type'] ?? null);
		$this->assertSame(1500, $properties['plainSummary']['maxLength'] ?? null);
		$this->assertSame('Plain language summary', $properties['plainSummary']['title'] ?? null);

		$this->assertSame(['A2', 'B1', 'B2'], $properties['plainSummaryLevel']['enum'] ?? null);
		$this->assertSame('B1', $properties['plainSummaryLevel']['default'] ?? null);
		$this->assertSame('Reading level', $properties['plainSummaryLevel']['title'] ?? null);

		$provenance = $properties['plainSummaryProvenance'] ?? [];
		$this->assertSame('object', $provenance['type'] ?? null);
		$this->assertTrue($provenance['readOnly'] ?? false);
		$this->assertSame(['read' => ['authenticated']], $provenance['authorization'] ?? null, 'Anonymous readers must not learn who accepted the summary.');
		$this->assertSame(['generatedBy', 'provider', 'at', 'acceptedBy'], array_keys($provenance['properties'] ?? []));
		$this->assertSame(['person', 'ai'], $provenance['properties']['generatedBy']['enum']);
	}

	public function testABOneSummaryIsAccepted(): void {
		[$valid] = $this->validate(
			[
				'title' => 'Besluit op bezwaar parkeervergunning',
				'plainSummary' => 'U kreeg geen parkeervergunning. U maakte bezwaar. De gemeente blijft bij het besluit.',
				'plainSummaryLevel' => 'B1',
			]
		);
		$this->assertTrue($valid);
	}

	public function testALevelOutsideTheListIsRefused(): void {
		[$valid, $errors] = $this->validate(['title' => 'Besluit', 'plainSummary' => 'Kort.', 'plainSummaryLevel' => 'C1']);
		$this->assertFalse($valid);
		$this->assertArrayHasKey('/plainSummaryLevel', $errors);
	}

	public function testASummaryOverTheLimitIsRefused(): void {
		[$valid, $errors] = $this->validate(['title' => 'Besluit', 'plainSummary' => str_repeat('a', 1501), 'plainSummaryLevel' => 'B1']);
		$this->assertFalse($valid);
		$this->assertArrayHasKey('/plainSummary', $errors);
	}
}
