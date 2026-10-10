<?php

/**
 * OpenCatalogi harvest architecture rule.
 *
 * Every inbound harvest runs as an OpenRegister source (REQ-DOH-001), so the
 * app ships no harvest feed, item or run schema of its own. This test loads
 * every register file under `lib/Settings` (the monolith, the `register.d`
 * fragments and the other descriptors) and fails on a schema named
 * `harvest-feed`, `harvested-item` or `harvest-run`, by key or by slug.
 *
 * A control test runs the same finder over a fixture that does declare one,
 * so a finder that silently reads nothing cannot pass the rule.
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
 * @spec openspec/specs/harvest-feed-intake/spec.md#requirement-every-inbound-harvest-runs-as-an-openregister-source-req-doh-001
 */

declare(strict_types=1);

namespace Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * The app ships no harvest schema of its own.
 */
class HarvestArchitectureTest extends TestCase {

	/**
	 * The schema names the rule forbids.
	 *
	 * @var array<int, string>
	 */
	private const FORBIDDEN = ['harvest-feed', 'harvested-item', 'harvest-run'];

	/**
	 * The forbidden schemas declared by the given JSON files, as "file: name".
	 *
	 * Names are compared lower-cased with camel case split to kebab, so
	 * `harvestFeed` and `HarvestRun` are caught as well.
	 *
	 * @param array<int, string> $files The register files.
	 *
	 * @return array<int, string> The offending declarations.
	 */
	private function forbiddenSchemas(array $files): array {
		$found = [];
		foreach ($files as $file) {
			$data = json_decode((string)file_get_contents($file), true);
			$this->assertIsArray($data, $file . ' is not valid JSON');
			$schemas = ($data['components']['schemas'] ?? []);
			foreach ($schemas as $key => $schema) {
				$names = [(string)$key];
				if (is_array($schema) === true && isset($schema['slug']) === true) {
					$names[] = (string)$schema['slug'];
				}

				foreach ($names as $name) {
					$normalised = strtolower((string)preg_replace('/(?<!^)[A-Z]/', '-$0', $name));
					if (in_array($normalised, self::FORBIDDEN, true) === true) {
						$found[] = basename($file) . ': ' . $name;
					}
				}
			}
		}

		return array_values(array_unique($found));

	}//end forbiddenSchemas()

	/**
	 * No register file the app ships declares a harvest schema (REQ-DOH-001).
	 *
	 * @return void
	 */
	public function testNoHarvestSchemaShips(): void {
		$root = dirname(__DIR__, 3) . '/lib/Settings';
		$files = array_merge(glob($root . '/*.json') ?: [], glob($root . '/register.d/*.json') ?: []);
		$this->assertNotEmpty($files, 'No register files found under lib/Settings: the rule would pass on nothing.');
		$this->assertContains($root . '/publication_register.json', $files);

		$this->assertSame([], $this->forbiddenSchemas(files: $files));

	}//end testNoHarvestSchemaShips()

	/**
	 * Control: the finder does catch a harvest schema, by key and by slug.
	 *
	 * @return void
	 */
	public function testTheFinderCatchesAHarvestSchema(): void {
		$file = tempnam(sys_get_temp_dir(), 'harvest-arch-');
		file_put_contents(
			$file,
			(string)json_encode(
				[
					'components' => [
						'schemas' => [
							'harvestFeed' => ['slug' => 'harvestFeed'],
							'run' => ['slug' => 'harvest-run'],
							'publication' => ['slug' => 'publication'],
						],
					],
				]
			)
		);

		try {
			$found = $this->forbiddenSchemas(files: [$file]);
		} finally {
			unlink($file);
		}

		$this->assertCount(2, $found);
		$this->assertStringEndsWith('harvestFeed', $found[0]);
		$this->assertStringEndsWith('harvest-run', $found[1]);

	}//end testTheFinderCatchesAHarvestSchema()
}//end class
