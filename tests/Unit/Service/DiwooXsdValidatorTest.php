<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Http\XMLResponse;
use OCA\OpenCatalogi\Service\DiwooXsdValidator;
use OCA\OpenCatalogi\Service\StandardsVersionService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The render-time DiWoo XSD check (REQ-WNO-002).
 *
 * @spec openspec/changes/woo-national-output-assurance/specs/woo-compliance/spec.md#requirement-the-diwoo-output-is-validated-as-it-is-generated-against-the-current-standard-req-wno-002
 */
#[CoversClass(DiwooXsdValidator::class)]
#[UsesClass(XMLResponse::class)]
#[UsesClass(StandardsVersionService::class)]
class DiwooXsdValidatorTest extends TestCase {

	/**
	 * A url entry with the five mandatory DiWoo elements.
	 *
	 * @param array<string, mixed> $extra Elements added to `diwoo:DiWoo`.
	 *
	 * @return array<string, mixed>
	 */
	private static function entry(array $extra = []): array {
		return [
			'loc' => 'https://example.com/besluit.pdf',
			'lastmod' => '2026-11-01T10:00:00+00:00',
			'diwoo:Document' => ['diwoo:DiWoo' => array_merge([
				'diwoo:creatiedatum' => '2026-09-30',
				'diwoo:publisher' => ['@resource' => 'https://identifier.overheid.nl/tooi/id/gemeente/gm0855', '#text' => 'Tilburg'],
				'diwoo:titelcollectie' => ['diwoo:officieleTitel' => 'Besluit'],
				'diwoo:classificatiecollectie' => ['diwoo:informatiecategorieen' => ['diwoo:informatiecategorie' => [
					'@resource' => 'https://identifier.overheid.nl/tooi/def/thes/kern/c_c6cd1213',
					'#text' => 'Jaarplannen en jaarverslagen',
				]]],
				'diwoo:documenthandelingen' => ['diwoo:documenthandeling' => [
					'diwoo:soortHandeling' => ['@resource' => 'https://identifier.overheid.nl/tooi/def/thes/kern/c_dfcee535', '#text' => 'ontvangst'],
					'diwoo:atTime' => '2026-11-01T10:00:00+00:00',
				]],
			], $extra)],
		];
	}

	public function testAValidEntryHasNoErrors(): void {
		$this->assertSame([], (new DiwooXsdValidator())->entryErrors(self::entry()));
	}

	public function testAnInvalidElementValueIsNamed(): void {
		$errors = (new DiwooXsdValidator())->entryErrors(self::entry(['diwoo:creatiedatum' => '0000-01-01']));

		$this->assertCount(1, $errors);
		$this->assertStringContainsString('creatiedatum', $errors[0]);
		$this->assertStringContainsString("'0000-01-01' is not a valid value", $errors[0]);
	}

	public function testAnOrganisationOutsideTheListIsRefused(): void {
		$entry = self::entry(['diwoo:publisher' => ['@resource' => 'https://identifier.overheid.nl/tooi/id/gemeente/gm9999', '#text' => 'Nergens']]);

		$errors = (new DiwooXsdValidator())->entryErrors($entry);

		$this->assertCount(1, $errors);
		$this->assertStringContainsString("'https://identifier.overheid.nl/tooi/id/gemeente/gm9999' is not a valid value", $errors[0]);
	}

	public function testAnEntryWithoutADocumentIsAnError(): void {
		$this->assertSame(['the entry holds no diwoo:Document'], (new DiwooXsdValidator())->entryErrors(['loc' => 'https://example.com/a.pdf']));
	}

	public function testUnparsableXmlIsAnErrorNotAPass(): void {
		$errors = (new DiwooXsdValidator())->documentErrors('<urlset><url>');

		$this->assertCount(1, $errors);
		$this->assertNotSame([], $errors[0]);
	}

	public function testEachDocumentOfAPageIsJudgedOnItsOwn(): void {
		$page = (new XMLResponse(data: [
			'@root' => 'urlset',
			'@attributes' => ['xmlns' => StandardsVersionService::SITEMAP_NAMESPACE, 'xmlns:diwoo' => StandardsVersionService::DIWOO_NAMESPACE],
			'url' => [self::entry(), self::entry(['diwoo:creatiedatum' => 'gisteren'])],
		]))->render();

		$errors = (new DiwooXsdValidator())->documentErrors($page);

		$this->assertCount(2, $errors);
		$this->assertSame([], $errors[0]);
		$this->assertStringContainsString("'gisteren' is not a valid value", $errors[1][0]);
	}

	/**
	 * Without the shipped schema nothing validates: a missing XSD holds the document back.
	 */
	public function testAMissingSchemaFailsClosed(): void {
		$errors = (new DiwooXsdValidator(sys_get_temp_dir() . '/no-diwoo-xsd-here'))->entryErrors(self::entry());

		$this->assertNotSame([], $errors);
	}

	/**
	 * The MDTO import resolves to the shipped copy; a schema that reaches for any other
	 * location outside its directory is refused rather than fetched.
	 */
	public function testTheSchemaNeverReachesOutsideItsDirectory(): void {
		$dir = sys_get_temp_dir() . '/diwoo-xsd-' . bin2hex(random_bytes(4));
		mkdir($dir);
		file_put_contents($dir . '/diwoo-metadata.xsd', '<?xml version="1.0"?>'
			. '<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema" targetNamespace="' . StandardsVersionService::DIWOO_NAMESPACE . '">'
			. '<xs:include schemaLocation="https://example.invalid/elsewhere.xsd"/>'
			. '<xs:element name="Document"/></xs:schema>');
		try {
			$errors = (new DiwooXsdValidator($dir))->entryErrors(self::entry());
		} finally {
			unlink($dir . '/diwoo-metadata.xsd');
			rmdir($dir);
		}

		$this->assertNotSame([], $errors);
		$this->assertStringContainsString('example.invalid', implode(' ', $errors));
	}
}
