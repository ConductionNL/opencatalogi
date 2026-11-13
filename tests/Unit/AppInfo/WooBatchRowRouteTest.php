<?php

/**
 * The Woo batches index opens a batch on its detail page.
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
 * @spec openspec/changes/woo-document-assessment-screen/specs/woo-transparency/spec.md#requirement-a-batch-opens-from-the-woo-index-req-wda-001
 */

declare(strict_types=1);

namespace Unit\AppInfo;

use PHPUnit\Framework\TestCase;

/**
 * A row click on WooBatches must land on the WooBatchDetail page at /woo/:id.
 */
class WooBatchRowRouteTest extends TestCase {

	/**
	 * The batch rows route to WooBatchDetail at /woo/:id.
	 *
	 * @return void
	 */
	public function testABatchRowOpensTheBatchPage(): void {
		$manifest = json_decode(
			json: (string)file_get_contents(filename: __DIR__ . '/../../../src/manifest.json'),
			associative: true,
			flags: JSON_THROW_ON_ERROR
		);
		$pages = array_column(array: $manifest['pages'], column_key: null, index_key: 'id');

		$target = ($pages['WooBatches']['config']['rowRoute'] ?? null);

		$this->assertSame(expected: 'WooBatchDetail', actual: $target);
		$this->assertSame(expected: '/woo/:id', actual: $pages[$target]['route']);

	}//end testABatchRowOpensTheBatchPage()
}//end class
