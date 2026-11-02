<?php

/**
 * Golden test for PublicationService::mergeFacetableData.
 *
 * Every world merges a local and a federated facetable block and records the
 * merged block.
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
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\PublicationService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Golden test for PublicationService::mergeFacetableData.
 *
 * @covers \OCA\OpenCatalogi\Service\PublicationService
 */
class PublicationFacetableGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/publication-facetable-golden.json';

	/**
	 * The recorded worlds: the local and the federated facetable block.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
	 */
	public static function worlds(): array {
		$federated = ['@self' => ['register' => ['type' => 'terms']], 'theme' => ['type' => 'terms']];

		return [
			'both empty' => [[], []],
			'federated only' => [[], $federated],
			'local wins' => [['@self' => ['status' => ['type' => 'categorical']], 'custom' => ['field' => 'data']], $federated],
			'local without @self' => [['title' => ['type' => 'text']], $federated],
			'local overrides the virtual fields' => [['@self' => ['directory' => 'old', 'catalogs' => ['x'], 'organization' => ['type' => 'terms']]], []],
			'federated with virtual fields' => [[], ['@self' => ['directory' => ['type' => 'other']]]],
		];

	}//end worlds()

	/**
	 * Merge the world's blocks and compare with the recording.
	 *
	 * @param array<string, mixed> $local     The local facetable block.
	 * @param array<string, mixed> $federated The federated facetable block.
	 *
	 * @return void
	 */
	#[DataProvider('worlds')]
	public function testMergeMatchesTheRecording(array $local, array $federated): void {
		$service = (new \ReflectionClass(PublicationService::class))->newInstanceWithoutConstructor();
		$actual = (new \ReflectionMethod(PublicationService::class, 'mergeFacetableData'))->invoke($service, $local, $federated);
		$actual = json_decode((string)json_encode($actual), true);

		$recorded = [];
		if (file_exists(self::FIXTURE) === true) {
			$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		$name = (string)$this->dataName();
		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$name] = $actual;
			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $recorded, 'No recording for ' . $name);
		$this->assertSame($recorded[$name], $actual);

	}//end testMergeMatchesTheRecording()
}//end class
