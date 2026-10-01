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

		$this->assertMatchesRegularExpression('/function backfillCatalogScopes\(\).*\$this->resolveScopeSlugs\(/s', $source);
		$this->assertMatchesRegularExpression('/function resolveScopeSlugs\(.*CatalogScopeSlugResolver::resolveScope\(/s', $source);
	}//end testTheSettingsImportCallsTheResolver()

	/**
	 * A stackiq-like world: register `stackiq` (20) lists schemas 33 and 34,
	 * register `publication` (5) lists schema 9, which shares slug `organization`.
	 *
	 * @param array<int, int> $stackiqSchemas The schema ids register `stackiq` lists.
	 *
	 * @return array{0: callable, 1: callable, 2: callable}
	 */
	private function world(array $stackiqSchemas = [33, 34]): array {
		$registers = ['stackiq' => 20, 'publication' => 5];
		$lists = [20 => $stackiqSchemas, 5 => [9]];
		$slugs = [33 => 'organization', 34 => 'Module', 35 => 'usage', 9 => 'organization'];

		return [
			static fn (string $slug): ?int => ($registers[$slug] ?? null),
			static fn (int $id): array => ($lists[$id] ?? []),
			static fn (int $id): ?string => ($slugs[$id] ?? null),
		];
	}//end world()

	/**
	 * A schema slug resolves to the schema the catalogue's own register lists, not to another app's.
	 *
	 * @return void
	 */
	public function testASchemaSlugResolvesInsideTheCataloguesRegisters(): void {
		[$findRegister, $schemasOf, $slugOf] = $this->world();

		$scope = CatalogScopeSlugResolver::resolveScope(
			registers: ['stackiq'],
			schemas: ['organization', 'module'],
			findRegisterId: $findRegister,
			schemaIdsOfRegister: $schemasOf,
			schemaSlugOf: $slugOf
		);

		$this->assertSame(['20'], $scope['registers']);
		// 33, not 9: schema 9 has the same slug but lives in register publication.
		$this->assertSame(['33', '34'], $scope['schemas']);
		$this->assertTrue($scope['changed']);
		$this->assertSame([], $scope['pending']);
	}//end testASchemaSlugResolvesInsideTheCataloguesRegisters()

	/**
	 * A slug the register does not list yet stays a slug, and the register id is pending.
	 *
	 * @return void
	 */
	public function testASlugTheRegisterDoesNotListYetStaysPending(): void {
		[$findRegister, $schemasOf, $slugOf] = $this->world();

		$scope = CatalogScopeSlugResolver::resolveScope(
			registers: ['stackiq'],
			schemas: ['module', 'usage'],
			findRegisterId: $findRegister,
			schemaIdsOfRegister: $schemasOf,
			schemaSlugOf: $slugOf
		);

		$this->assertSame(['34', 'usage'], $scope['schemas']);
		$this->assertSame(['20'], $scope['pending']);
	}//end testASlugTheRegisterDoesNotListYetStaysPending()

	/**
	 * Without the register, nothing resolves, nothing is taken from another register, and the slug is pending.
	 *
	 * @return void
	 */
	public function testWithoutTheRegisterNothingResolves(): void {
		[, $schemasOf, $slugOf] = $this->world();

		$scope = CatalogScopeSlugResolver::resolveScope(
			registers: ['stackiq'],
			schemas: ['organization'],
			findRegisterId: static function (string $slug): ?int {
				throw new RuntimeException('register ' . $slug . ' does not exist');
			},
			schemaIdsOfRegister: $schemasOf,
			schemaSlugOf: $slugOf
		);

		$this->assertSame(['stackiq'], $scope['registers']);
		$this->assertSame(['organization'], $scope['schemas']);
		$this->assertFalse($scope['changed']);
		$this->assertSame(['stackiq'], $scope['pending']);
		// What PublicationService does with it: intval turns every entry into 0, which matches nothing.
		$this->assertSame([0], array_map('intval', $scope['registers']));
	}//end testWithoutTheRegisterNothingResolves()

	/**
	 * A resolved scope with ids only is not pending and not changed.
	 *
	 * @return void
	 */
	public function testAResolvedScopeIsLeftAlone(): void {
		[$findRegister, $schemasOf, $slugOf] = $this->world();

		$scope = CatalogScopeSlugResolver::resolveScope(
			registers: ['20'],
			schemas: ['33', '34'],
			findRegisterId: $findRegister,
			schemaIdsOfRegister: $schemasOf,
			schemaSlugOf: $slugOf
		);

		$this->assertFalse($scope['changed']);
		$this->assertSame([], $scope['pending']);
		$this->assertFalse(CatalogScopeSlugResolver::hasSlug(entries: $scope['schemas']));
	}//end testAResolvedScopeIsLeftAlone()
}//end class
