<?php

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\CatalogScopeIds;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CatalogScopeIds.
 *
 * @covers \OCA\OpenCatalogi\Service\CatalogScopeIds
 */
class CatalogScopeIdsTest extends TestCase {

	public function testNormalizeIdsAcceptsJsonStringAndArrayAndCastsToInt(): void {
		$this->assertSame([1, 2], CatalogScopeIds::normalizeIds('[1,2]'));
		$this->assertSame([1, 2], CatalogScopeIds::normalizeIds('["1","2"]'));
		$this->assertSame([1, 2], CatalogScopeIds::normalizeIds([1, '2']));
		$this->assertSame([], CatalogScopeIds::normalizeIds(null));
		$this->assertSame([], CatalogScopeIds::normalizeIds('not-json'));
	}

	public function testForCatalogKeepsTheIds(): void {
		$this->assertSame(
			['registers' => [1], 'schemas' => [2, 3]],
			CatalogScopeIds::forCatalog(['registers' => '[1]', 'schemas' => [2, '3']])
		);
	}

	public function testForCatalogFailsClosedWhenARegisterOrSchemaIsNamedButNoneIsAnId(): void {
		$empty = ['registers' => [], 'schemas' => []];

		$this->assertSame($empty, CatalogScopeIds::forCatalog(['registers' => ['stackiq'], 'schemas' => [2]]));
		$this->assertSame($empty, CatalogScopeIds::forCatalog(['registers' => [1], 'schemas' => 'module']));
	}

	public function testForCatalogLeavesAnUnnamedFieldEmpty(): void {
		$this->assertSame(['registers' => [], 'schemas' => [2]], CatalogScopeIds::forCatalog(['schemas' => [2]]));
		$this->assertSame(['registers' => [1], 'schemas' => []], CatalogScopeIds::forCatalog(['registers' => [1], 'schemas' => ' ']));
	}
}
