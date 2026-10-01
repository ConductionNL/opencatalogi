<?php

/**
 * Tests for CatalogScopeSlugResolver: seeded catalogue scopes name registers and
 * schemas by slug, and must end up as ids, because the catalogue scope check
 * runs every entry through intval.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/publiccode-github-harvest/specs/publiccode-github-harvest/spec.md#requirement-req-pgh-007-harvested-components-are-found-in-search-and-in-the-public-api
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Service;

use OCA\OpenCatalogi\Service\CatalogScopeSlugResolver;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenCatalogi\Service\CatalogScopeSlugResolver
 */
class CatalogScopeSlugResolverTest extends TestCase {

	/**
	 * A slug becomes its id; an id stays as it is.
	 *
	 * @return void
	 */
	public function testSlugsBecomeIdsAndIdsStay(): void {
		[$resolved, $changed] = CatalogScopeSlugResolver::resolve(
			entries: ['publiccode', '12', 7],
			resolve: static fn (string $slug): ?int => ($slug === 'publiccode' ? 41 : null)
		);

		$this->assertSame(['41', '12', 7], $resolved);
		$this->assertTrue($changed);
		$this->assertSame([41, 12, 7], array_map('intval', $resolved));
	}//end testSlugsBecomeIdsAndIdsStay()

	/**
	 * An unknown slug is kept so a later import can resolve it, and nothing reads as changed.
	 *
	 * @return void
	 */
	public function testAnUnknownSlugIsKeptAndNothingChanges(): void {
		[$resolved, $changed] = CatalogScopeSlugResolver::resolve(
			entries: ['not-imported-yet'],
			resolve: static function (string $slug): ?int {
				throw new RuntimeException('no schema ' . $slug);
			}
		);

		$this->assertSame(['not-imported-yet'], $resolved);
		$this->assertFalse($changed);
	}//end testAnUnknownSlugIsKeptAndNothingChanges()

	/**
	 * The backfill calls the resolver, so the seeded catalogue is not left on slugs.
	 *
	 * @return void
	 */
	public function testTheSettingsImportCallsTheResolver(): void {
		$source = (string)file_get_contents(__DIR__ . '/../../../lib/Service/SettingsService.php');

		$this->assertMatchesRegularExpression('/function backfillCatalogScopes\(\).*CatalogScopeSlugResolver::resolve\(/s', $source);
	}//end testTheSettingsImportCallsTheResolver()
}//end class
