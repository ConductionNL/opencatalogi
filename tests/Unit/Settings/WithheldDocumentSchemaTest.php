<?php

/**
 * The shipped withheldDocument schema (REQ-WDW-001).
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/woo-decision-shows-what-was-withheld/specs/publications/spec.md#requirement-a-withheld-document-is-recorded-apart-from-the-publication-and-is-not-public-req-wdw-001
 */

declare(strict_types=1);

namespace Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Reads the fragment as it ships.
 */
class WithheldDocumentSchemaTest extends TestCase {

	private const FRAGMENT = __DIR__ . '/../../../lib/Settings/register.d/woo-decision-shows-what-was-withheld.json';

	/**
	 * @return array<string, mixed>
	 */
	private function fragment(): array {
		return json_decode((string)file_get_contents(self::FRAGMENT), true, 512, JSON_THROW_ON_ERROR);
	}//end fragment()

	/**
	 * @return array<string, mixed>
	 */
	private function schema(): array {
		return $this->fragment()['components']['schemas']['withheldDocument'];
	}//end schema()

	public function testTheSchemaHasNoPublicReadRule(): void {
		$authorization = $this->schema()['authorization'];

		foreach (['read', 'create', 'update', 'delete'] as $action) {
			$this->assertSame(['admin'], $authorization[$action], $action . ' must be admin only');
		}

		$this->assertStringNotContainsString('public', json_encode($authorization, JSON_THROW_ON_ERROR));
	}//end testTheSchemaHasNoPublicReadRule()

	public function testTheSchemaDeclaresNoContentProperty(): void {
		$properties = $this->schema()['properties'];

		$this->assertSame(['publication', 'position', 'grounds', 'source', 'recordedAt'], array_keys($properties));
		$this->assertSame(['code', 'article', 'label'], array_keys($properties['grounds']['items']['properties']));
		$this->assertSame(['publication', 'position', 'grounds', 'source', 'recordedAt'], $this->schema()['required']);
	}//end testTheSchemaDeclaresNoContentProperty()

	public function testTheSchemaArrivesWithItsSlugOnThePublicationRegister(): void {
		$fragment = $this->fragment();

		$this->assertSame('withheldDocument', $this->schema()['slug']);
		$this->assertNotEmpty($this->schema()['version']);
		$this->assertContains('withheldDocument', $fragment['components']['registers']['publication']['schemas']);
		$this->assertTrue($fragment['components']['registers']['publication']['configuration']['schemas']['withheldDocument']['magicMapping']);
	}//end testTheSchemaArrivesWithItsSlugOnThePublicationRegister()
}//end class
