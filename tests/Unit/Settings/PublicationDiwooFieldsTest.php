<?php

/**
 * The DiWoo fields on the publication schema, as the app imports it.
 *
 * The fields live in lib/Settings/register.d/diwoo-metadata-on-the-publication.json
 * and reach OpenRegister merged onto publication_register.json with every other
 * fragment, in file-name order (ADR-037). These tests merge the shipped files
 * the same way SettingsService does and validate payloads against the result.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Settings;

use OCA\OpenCatalogi\Service\SettingsService;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * @spec openspec/changes/diwoo-metadata-on-the-publication/specs/woo-compliance/spec.md#requirement-the-publication-stores-its-diwoo-metadata-req-dwp-001
 */
class PublicationDiwooFieldsTest extends TestCase {

	private const SETTINGS = __DIR__ . '/../../../lib/Settings';

	private const FRAGMENT = self::SETTINGS . '/register.d/diwoo-metadata-on-the-publication.json';

	/**
	 * The publication schema after every shipped fragment is merged in.
	 *
	 * @return array<string, mixed> The merged publication schema.
	 */
	private function mergedPublication(): array {
		$merge = new ReflectionMethod(SettingsService::class, 'mergeRegisterFragments');
		$base = json_decode((string)file_get_contents(self::SETTINGS . '/publication_register.json'), true);
		$merged = $merge->invoke(null, $base, self::SETTINGS . '/register.d');

		return $merged['components']['schemas']['publication'];
	}

	/**
	 * Validate a publication payload against the merged schema's properties.
	 *
	 * @param array<string, mixed> $payload The publication.
	 *
	 * @return bool Whether it is valid.
	 */
	private function isValid(array $payload): bool {
		$publication = $this->mergedPublication();
		$schema = [
			'type' => 'object',
			'properties' => $publication['properties'],
			'required' => ($publication['required'] ?? []),
		];

		return (new Validator())->validate(json_decode((string)json_encode($payload)), (string)json_encode($schema))->isValid();
	}

	/**
	 * The fragment names the schema it extends: without `slug` OpenRegister
	 * rejects it while the import still reports success.
	 *
	 * @return void
	 */
	public function testTheFragmentNamesThePublicationSchemaBySlug(): void {
		$fragment = json_decode((string)file_get_contents(self::FRAGMENT), true);

		$this->assertSame('publication', $fragment['components']['schemas']['publication']['slug']);
		$this->assertSame('publication', $this->mergedPublication()['slug']);
	}

	/**
	 * The merged schema carries a version above the one installed before this
	 * change, whichever fragment merges last.
	 *
	 * @return void
	 */
	public function testTheMergedVersionIsBumped(): void {
		$this->assertTrue(version_compare($this->mergedPublication()['version'], '0.0.7', '>'), $this->mergedPublication()['version']);
	}

	/**
	 * The six DiWoo properties of REQ-DWP-001, with their types and formats.
	 *
	 * @return void
	 */
	public function testTheSixDiwooPropertiesAreDeclared(): void {
		$properties = $this->mergedPublication()['properties'];

		$this->assertSame('string', $properties['documentsoort']['type']);
		$this->assertSame('object', $properties['fileDocumentsoorten']['type']);
		$this->assertSame(['type' => 'string'], $properties['fileDocumentsoorten']['additionalProperties']);
		$this->assertSame('string', $properties['language']['type']);
		foreach (['creationDate', 'validFrom', 'validUntil'] as $date) {
			$this->assertSame('string', $properties[$date]['type'], $date);
			$this->assertSame('date', $properties[$date]['format'], $date);
		}

		foreach (['documentsoort', 'fileDocumentsoorten', 'language', 'creationDate', 'validFrom', 'validUntil'] as $name) {
			$this->assertNotContains($name, ($this->mergedPublication()['required'] ?? []), $name . ' is optional: a draft may be incomplete');
		}
	}

	/**
	 * REQ-DWP-001 "An editor records the legal effect apart from the
	 * publication date": the validity dates are their own properties.
	 *
	 * @return void
	 */
	public function testValidityDatesAreStoredApartFromThePublicationDate(): void {
		$payload = [
			'title' => 'Besluit',
			'publicationDate' => '2026-11-02T09:00:00+00:00',
			'validFrom' => '2027-01-01',
			'validUntil' => '2030-12-31',
		];

		$this->assertTrue($this->isValid($payload));
		$properties = $this->mergedPublication()['properties'];
		$this->assertArrayHasKey('publicationDate', $properties);
		$this->assertNotSame($properties['publicationDate'], $properties['validFrom']);
		$this->assertFalse($this->isValid(['title' => 'Besluit', 'validFrom' => 'next year']), 'a validity date must be a date');
	}

	/**
	 * REQ-DWP-001 "The language defaults to Dutch".
	 *
	 * @return void
	 */
	public function testTheLanguageDefaultsToDutch(): void {
		$this->assertSame('nl', $this->mergedPublication()['properties']['language']['default']);
		$this->assertTrue($this->isValid(['title' => 'Besluit']));
	}

	/**
	 * A per-file override maps file ids to strings, nothing else.
	 *
	 * @return void
	 */
	public function testAFileOverrideIsAMapOfStrings(): void {
		$this->assertTrue($this->isValid(['title' => 'Besluit', 'fileDocumentsoorten' => ['4711' => 'c_852da199']]));
		$this->assertFalse($this->isValid(['title' => 'Besluit', 'fileDocumentsoorten' => ['4711' => ['nested']]]));
	}
}
