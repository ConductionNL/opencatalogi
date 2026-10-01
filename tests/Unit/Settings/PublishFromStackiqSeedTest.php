<?php

/**
 * Tests for the Applicatielandschap catalogue seed (publish-from-stackiq).
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/publish-from-stackiq/specs/publish-from-stackiq/spec.md#requirement-req-pfs-001-opencatalogi-seeds-an-unpublished-applicatielandschap-catalogue-over-stackiq
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Reads the shipped fragment and checks it against the shipped catalog schema.
 *
 * @coversNothing
 */
class PublishFromStackiqSeedTest extends TestCase {

	/**
	 * The schemas stackiq keeps private as a whole (lane sq, 2026-10-01).
	 */
	private const NEVER_PUBLIC = ['catalogContract', 'contactPerson', 'aiSystem', 'technologyComponent'];

	/**
	 * The catalogue seed from the fragment.
	 *
	 * @return array<string, mixed>
	 */
	private function seed(): array {
		$fragment = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../lib/Settings/register.d/publish-from-stackiq.json'),
			true,
			512,
			JSON_THROW_ON_ERROR
		);
		$seeds = array_values(
			array_filter(
				($fragment['components']['objects'] ?? []),
				static fn (array $object): bool => (($object['@self']['slug'] ?? null) === 'applicatielandschap')
			)
		);
		$this->assertCount(1, $seeds, 'exactly one applicatielandschap seed');

		return $seeds[0];
	}//end seed()

	/**
	 * The seed names stackiq's register and its six application-level schemas by slug.
	 *
	 * The slugs are stackiq's on origin/development (lib/Settings/softwarecatalogus_register.json,
	 * components.registers.stackiq.schemas). The live run on :8096 resolves them.
	 *
	 * @return void
	 */
	public function testTheSeedNamesStackiqsSlugs(): void {
		$seed = $this->seed();

		$this->assertSame(['register' => 'publication', 'schema' => 'catalog', 'slug' => 'applicatielandschap'], $seed['@self']);
		$this->assertSame(['stackiq'], $seed['registers']);
		$this->assertSame(['module', 'moduleVersion', 'suite', 'catalogService', 'connection', 'usage'], $seed['schemas']);
	}//end testTheSeedNamesStackiqsSlugs()

	/**
	 * The seed ships unpublished and names nothing stackiq keeps private.
	 *
	 * @return void
	 */
	public function testTheSeedIsUnpublishedAndNamesNoPrivateSchema(): void {
		$seed = $this->seed();

		$this->assertArrayNotHasKey('published', $seed);
		$this->assertSame([], array_intersect(self::NEVER_PUBLIC, $seed['schemas']));
		$this->assertSame([], array_intersect(self::NEVER_PUBLIC, $seed['registers']));
	}//end testTheSeedIsUnpublishedAndNamesNoPrivateSchema()

	/**
	 * Every key of the seed is a property of the shipped catalog schema and fits its type.
	 *
	 * @return void
	 */
	public function testTheSeedFitsTheCatalogSchema(): void {
		$register = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../lib/Settings/publication_register.json'),
			true,
			512,
			JSON_THROW_ON_ERROR
		);
		$catalog = $register['components']['schemas']['catalog'];
		$seed = $this->seed();
		unset($seed['@self']);

		foreach ($catalog['required'] ?? [] as $required) {
			$this->assertArrayHasKey($required, $seed);
		}

		foreach ($seed as $key => $value) {
			$this->assertArrayHasKey($key, $catalog['properties'], 'catalog declares ' . $key);
			$property = $catalog['properties'][$key];
			match ($property['type']) {
				'string' => $this->assertIsString($value, $key),
				'boolean' => $this->assertIsBool($value, $key),
				'array' => $this->assertIsList($value, $key),
				default => null,
			};

			if (isset($property['enum']) === true) {
				$this->assertContains($value, $property['enum'], $key);
			}

			if (isset($property['pattern']) === true) {
				$this->assertMatchesRegularExpression('/' . $property['pattern'] . '/', $value, $key);
			}

			if (isset($property['maxLength']) === true) {
				$this->assertLessThanOrEqual($property['maxLength'], mb_strlen($value), $key);
			}
		}//end foreach
	}//end testTheSeedFitsTheCatalogSchema()
}//end class
