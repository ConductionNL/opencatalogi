<?php

/**
 * Tests for OoapiScope: the catalog and organisation an OOAPI request is scoped to.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Contract;

use OCA\OpenCatalogi\Contract\OoapiScope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(OoapiScope::class)]
class OoapiScopeTest extends TestCase {

	private function scope(array $catalog): OoapiScope {
		return new OoapiScope(catalog: $catalog, register: 'reg', schema: 'course');
	}

	public function testTheCatalogIdIsTheCatalogsIdAsText(): void {
		self::assertSame('cat-1', $this->scope(catalog: ['id' => 'cat-1'])->catalogId());
		self::assertSame('7', $this->scope(catalog: ['id' => 7])->catalogId());
		self::assertSame('', $this->scope(catalog: [])->catalogId());
	}

	public function testAnOrganisationIdIsReturnedAsIs(): void {
		self::assertSame('org-uuid', $this->scope(catalog: ['organization' => 'org-uuid'])->organisationId());
	}

	/**
	 * @return array<string, array{0: array}>
	 */
	public static function catalogsWithoutAnOrganisation(): array {
		return [
			'absent'        => [['id' => 'cat-1']],
			'null'          => [['organization' => null]],
			'empty string'  => [['organization' => '']],
			'a number'      => [['organization' => 42]],
			'an object'     => [['organization' => ['id' => 'org-uuid']]],
		];
	}

	#[DataProvider('catalogsWithoutAnOrganisation')]
	public function testAnythingButANonEmptyStringIsNoOrganisation(array $catalog): void {
		self::assertNull($this->scope(catalog: $catalog)->organisationId());
	}

	public function testTheRegisterAndSchemaAreKept(): void {
		$scope = $this->scope(catalog: []);

		self::assertSame('reg', $scope->register);
		self::assertSame('course', $scope->schema);
	}
}
