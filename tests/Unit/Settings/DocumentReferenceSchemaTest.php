<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The shipped documentReference schema, its seed and its configuration key (REQ-PBR-001).
 *
 * @spec openspec/changes/integration-publish-by-reference/specs/publication-document-references/spec.md#requirement-an-editor-adds-a-document-by-its-public-url-req-pbr-001
 */

declare(strict_types=1);

namespace Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Reads the fragment and the demo data as they ship.
 */
class DocumentReferenceSchemaTest extends TestCase {

	private const FRAGMENT = __DIR__ . '/../../../lib/Settings/register.d/publication-document-references.json';

	/**
	 * @param string $path The file.
	 *
	 * @return array<string, mixed>
	 */
	private static function json(string $path): array {
		return json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
	}//end json()

	/**
	 * @return array<string, mixed>
	 */
	private function schema(): array {
		return self::json(path: self::FRAGMENT)['components']['schemas']['documentReference'];
	}//end schema()

	public function testTheSchemaArrivesOnThePublicationRegister(): void {
		$fragment = self::json(path: self::FRAGMENT);

		$this->assertSame('documentReference', $this->schema()['slug']);
		$this->assertContains('documentReference', $fragment['components']['registers']['publication']['schemas']);
		$this->assertTrue($fragment['components']['registers']['publication']['configuration']['schemas']['documentReference']['magicMapping']);
	}//end testTheSchemaArrivesOnThePublicationRegister()

	public function testAReferenceHoldsAnHttpsUrlAndNoFile(): void {
		$properties = $this->schema()['properties'];

		$this->assertSame(['publication', 'url', 'title', 'format'], $this->schema()['required']);
		$this->assertSame(
			['publication', 'url', 'title', 'format', 'sourceSystem', 'publicationDate', 'depublicationDate', 'lastCheckedAt', 'reachable', 'lastStatus'],
			array_keys($properties)
		);
		$this->assertSame('^https://', $properties['url']['pattern']);
		$this->assertSame('uri', $properties['url']['format']);
		foreach (['lastCheckedAt', 'reachable', 'lastStatus'] as $checked) {
			$this->assertTrue($properties[$checked]['readOnly'], $checked . ' is written by the check only');
		}
	}//end testAReferenceHoldsAnHttpsUrlAndNoFile()

	public function testTheAnonymousReadIsLimitedToTheWindowAndOnlyStaffWrite(): void {
		$authorization = $this->schema()['authorization'];

		$public = array_values(array_filter($authorization['read'], static fn (mixed $rule): bool => is_array($rule) === true && $rule['group'] === 'public'));
		$this->assertCount(2, $public);
		foreach ($public as $rule) {
			$this->assertSame(['$lte' => '$now'], $rule['match']['publicationDate']);
			$this->assertArrayHasKey('depublicationDate', $rule['match']);
		}

		$this->assertContains('authenticated', $authorization['read']);
		foreach (['create', 'update', 'delete'] as $action) {
			$this->assertSame(['authenticated'], $authorization[$action]);
		}
	}//end testTheAnonymousReadIsLimitedToTheWindowAndOnlyStaffWrite()

	public function testTheSeedReferencePointsAtADemoPublication(): void {
		$mock = self::json(path: __DIR__ . '/../../../lib/Settings/opencatalogi_mock_register.json');

		$publications = [];
		$references = [];
		foreach ($mock['components']['objects'] as $object) {
			$schema = (string)($object['@self']['schema'] ?? '');
			if ($schema === 'publication') {
				$publications[] = '@ref:publication:' . (string)$object['@self']['slug'];
			}

			if ($schema === 'documentReference') {
				$references[] = $object;
			}
		}

		$this->assertCount(1, $references);
		$this->assertContains($references[0]['publication'], $publications);
		$this->assertSame('https://documenten.voorbeeldgemeente.nl/besluit-2026-001.pdf', $references[0]['url']);
		$this->assertSame('pdf', $references[0]['format']);
	}//end testTheSeedReferencePointsAtADemoPublication()

	public function testTheSchemaHasAConfigurationKey(): void {
		$settings = (string)file_get_contents(__DIR__ . '/../../../lib/Service/SettingsService.php');

		$this->assertStringContainsString("'document_reference_schema' => 'documentReference'", $settings);
	}//end testTheSchemaHasAConfigurationKey()
}//end class
