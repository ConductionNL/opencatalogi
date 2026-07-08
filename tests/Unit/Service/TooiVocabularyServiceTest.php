<?php
/**
 * Unit tests for TooiVocabularyService.
 *
 * Covers the three DIWOO axes' resolution to official TOOI/DiWoo value-list URIs
 * and the fail-closed behaviour (unresolvable input → null so the caller omits
 * the axis rather than leaking a literal).
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

use OCA\OpenCatalogi\Service\TooiVocabularyService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TooiVocabularyService.
 */
class TooiVocabularyServiceTest extends TestCase
{

    private TooiVocabularyService $service;


    /**
     * Set up the service under test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->service = new TooiVocabularyService();

    }//end setUp()


    /**
     * A category resolves by sitemap code, canonical label, alias, bare id, and full URI.
     *
     * @return void
     */
    public function testResolveInformatiecategorieAcceptsMultipleKeys(): void
    {
        $expected = 'https://identifier.overheid.nl/tooi/def/thes/kern/c_3baef532';

        foreach (['infocat014', 'Woo-verzoeken en -besluiten', 'woo-verzoek', 'c_3baef532', $expected] as $input) {
            $resolved = $this->service->resolveInformatiecategorie($input);
            $this->assertNotNull($resolved, "should resolve: $input");
            $this->assertSame($expected, $resolved['uri']);
            $this->assertSame('Woo-verzoeken en -besluiten', $resolved['label']);
        }

    }//end testResolveInformatiecategorieAcceptsMultipleKeys()


    /**
     * An unknown category returns null (fail closed).
     *
     * @return void
     */
    public function testResolveInformatiecategorieFailsClosed(): void
    {
        $this->assertNull($this->service->resolveInformatiecategorie('https://example.com/tooi/woo'));
        $this->assertNull($this->service->resolveInformatiecategorie(''));
        $this->assertNull($this->service->resolveInformatiecategorie(null));

    }//end testResolveInformatiecategorieFailsClosed()


    /**
     * The bundled list contains all 17 Woo categories.
     *
     * @return void
     */
    public function testInformatiecategorieListHas17Members(): void
    {
        $this->assertCount(17, $this->service->informatiecategorieList());

    }//end testInformatiecategorieListHas17Members()


    /**
     * soortHandeling defaults to ontvangst and honours a declared member.
     *
     * @return void
     */
    public function testResolveSoortHandeling(): void
    {
        $default = $this->service->resolveSoortHandeling(null);
        $this->assertSame('ontvangst', $default['label']);
        $this->assertSame('https://identifier.overheid.nl/tooi/def/thes/kern/c_dfcee535', $default['uri']);

        $declared = $this->service->resolveSoortHandeling('vaststelling');
        $this->assertSame('vaststelling', $declared['label']);
        $this->assertSame('https://identifier.overheid.nl/tooi/def/thes/kern/c_641ecd76', $declared['uri']);

        $this->assertNull($this->service->resolveSoortHandeling('nonexistent-handling'));

    }//end testResolveSoortHandeling()


    /**
     * Only a well-formed TOOI organisatie URI is accepted; a UUID is rejected.
     *
     * @return void
     */
    public function testResolveOrganisatie(): void
    {
        $uri = 'https://identifier.overheid.nl/tooi/id/gemeente/gm0855';
        $this->assertSame($uri, $this->service->resolveOrganisatie($uri));

        $this->assertNull($this->service->resolveOrganisatie('org-uuid-123'));
        $this->assertNull($this->service->resolveOrganisatie('https://example.com/org/1'));
        $this->assertNull($this->service->resolveOrganisatie(null));

    }//end testResolveOrganisatie()
}//end class
