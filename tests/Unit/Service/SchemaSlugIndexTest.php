<?php

/**
 * Unit tests for SchemaSlugIndex.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\SchemaSlugIndex;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Schema slugs are indexed lowercased to their id, the first register's schema
 * wins a duplicate slug, and lookups that fail or answer nothing are skipped.
 *
 * @covers \OCA\OpenCatalogi\Service\SchemaSlugIndex
 */
class SchemaSlugIndexTest extends TestCase {

	public function testSlugsAreIndexedLowercasedAndTheFirstOneWins(): void {
		$schemasOf = [1 => [10, '11', 'abc', 12, 13], 2 => [20]];
		$slugOf = [10 => 'Besluit', 11 => 'advies', 12 => '', 13 => 'BESLUIT', 20 => 'besluit'];
		$index = new SchemaSlugIndex(
			static fn (int $registerId): array => $schemasOf[$registerId],
			static function (int $schemaId) use ($slugOf): mixed {
				if ($schemaId === 99) {
					throw new RuntimeException('gone');
				}

				return ($slugOf[$schemaId] ?? null);
			}
		);

		$index->addRegister(1);
		$index->addRegister(2);

		$this->assertSame(['besluit' => 10, 'advies' => 11], $index->map());
	}

	public function testARegisterOrSchemaThatCannotBeReadIsSkipped(): void {
		$index = new SchemaSlugIndex(
			static function (int $registerId): array {
				if ($registerId === 1) {
					throw new RuntimeException('no register');
				}

				return [5, 6];
			},
			static function (int $schemaId): mixed {
				if ($schemaId === 5) {
					throw new RuntimeException('no schema');
				}

				return 'zaak';
			}
		);

		$index->addRegister(1);
		$this->assertSame([], $index->map());

		$index->addRegister(2);
		$this->assertSame(['zaak' => 6], $index->map());
	}
}
