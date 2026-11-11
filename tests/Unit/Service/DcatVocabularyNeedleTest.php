<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pins how the DCAT vocabulary resolvers read a blank or padded value.
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\DcatVocabularyService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the resolvers' reading of their value.
 */
class DcatVocabularyNeedleTest extends TestCase {

	/**
	 * Blank values resolve to nothing; padded and capitalised values resolve.
	 *
	 * @return void
	 */
	public function testBlankValuesResolveToNothingAndPaddedOnesResolve(): void {
		$service = new DcatVocabularyService();

		$this->assertNull($service->resolveHvdCategory(''));
		$this->assertNull($service->resolveHvdCategory(" \t "));
		$this->assertNull($service->resolveDataTheme(''));
		$this->assertNull($service->resolveDataTheme("  \n"));
		$this->assertSame($service->resolveDataTheme('TRAN'), $service->resolveDataTheme('  tran  '));
		$this->assertNotNull($service->resolveDataTheme('  Vervoer '));
		$category = array_key_first($service->hvdCategoryList());
		$this->assertSame($service->resolveHvdCategory($category), $service->resolveHvdCategory('  ' . strtoupper($category) . ' '));
		$this->assertNotNull($service->resolveHvdCategory($category));
	}//end testBlankValuesResolveToNothingAndPaddedOnesResolve()
}//end class
