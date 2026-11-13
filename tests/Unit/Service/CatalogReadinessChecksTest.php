<?php

/**
 * Unit tests for CatalogReadinessChecks.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\CatalogReadinessChecks;
use PHPUnit\Framework\TestCase;

/**
 * Checks are recorded in order, each carrying the catalog slug, and skipping
 * the index skips its dependent checks too.
 *
 * @covers \OCA\OpenCatalogi\Service\CatalogReadinessChecks
 */
class CatalogReadinessChecksTest extends TestCase {

	public function testChecksAreRecordedInOrderWithTheSlug(): void {
		$checks = new CatalogReadinessChecks('woo');
		$checks->pass('sitemapindex:woo');
		$checks->fail('diwoo-xsd:woo', 'element missing');
		$checks->skip('publication-sample:woo', 'no publications');

		$this->assertSame('woo', $checks->slug);
		$this->assertSame(
			[
				['id' => 'sitemapindex:woo', 'status' => 'pass', 'catalogSlug' => 'woo'],
				['id' => 'diwoo-xsd:woo', 'status' => 'fail', 'reason' => 'element missing', 'catalogSlug' => 'woo'],
				['id' => 'publication-sample:woo', 'status' => 'skipped', 'reason' => 'no publications', 'catalogSlug' => 'woo'],
			],
			$checks->all()
		);
	}

	public function testSkippingAllSkipsTheIndexAndItsDependents(): void {
		$checks = new CatalogReadinessChecks('open');
		$checks->skipAll('catalog not found');

		$this->assertSame(
			['sitemapindex:open', 'category-sitemap:open', 'diwoo-xsd:open', 'publication-sample:open'],
			array_column($checks->all(), 'id')
		);
		$this->assertSame(['skipped'], array_values(array_unique(array_column($checks->all(), 'status'))));
		$this->assertSame(['catalog not found'], array_values(array_unique(array_column($checks->all(), 'reason'))));
	}

	public function testANewCatalogHasNoChecks(): void {
		$this->assertSame([], (new CatalogReadinessChecks('x'))->all());
	}
}
