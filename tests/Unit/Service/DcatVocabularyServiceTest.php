<?php
/**
 * Unit tests for DcatVocabularyService.
 *
 * Covers HVD-category and EU MDR data-theme resolution to controlled authority
 * URIs, and the fail-closed behaviour (unresolvable input → null).
 *
 * @category Test
 * @package  Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2025 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\DcatVocabularyService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DcatVocabularyService.
 */
class DcatVocabularyServiceTest extends TestCase
{

    private DcatVocabularyService $service;


    /**
     * Set up the service under test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->service = new DcatVocabularyService();

    }//end setUp()


    /**
     * HVD category resolves by label, alias, bare id, and full URI.
     *
     * @return void
     */
    public function testResolveHvdCategory(): void
    {
        $expected = 'http://data.europa.eu/bna/c_b79e35eb';

        foreach (['Mobility', 'mobiliteit', 'c_b79e35eb', $expected] as $input) {
            $resolved = $this->service->resolveHvdCategory($input);
            $this->assertNotNull($resolved, "should resolve: $input");
            $this->assertSame($expected, $resolved['uri']);
            $this->assertSame('Mobility', $resolved['label']);
        }

        $this->assertNull($this->service->resolveHvdCategory('not-a-category'));
        $this->assertNull($this->service->resolveHvdCategory(null));

    }//end testResolveHvdCategory()


    /**
     * All six ODD HVD categories are bundled.
     *
     * @return void
     */
    public function testHvdCategoryListHasSixMembers(): void
    {
        $this->assertCount(6, $this->service->hvdCategoryList());

    }//end testHvdCategoryListHasSixMembers()


    /**
     * Data theme resolves by MDR code, synonym, and full URI; unknown → null.
     *
     * @return void
     */
    public function testResolveDataTheme(): void
    {
        $tran = 'http://publications.europa.eu/resource/authority/data-theme/TRAN';
        $this->assertSame($tran, $this->service->resolveDataTheme('TRAN'));
        $this->assertSame($tran, $this->service->resolveDataTheme('transport'));
        $this->assertSame($tran, $this->service->resolveDataTheme('vervoer'));
        $this->assertSame($tran, $this->service->resolveDataTheme($tran));

        $this->assertSame(
            'http://publications.europa.eu/resource/authority/data-theme/ENVI',
            $this->service->resolveDataTheme('milieu')
        );

        $this->assertNull($this->service->resolveDataTheme('nonsense-theme'));
        $this->assertNull($this->service->resolveDataTheme(null));

    }//end testResolveDataTheme()
}//end class
