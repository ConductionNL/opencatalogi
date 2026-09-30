<?php

/**
 * Tests for the portal search vocabulary.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @link https://www.OpenCatalogi.nl
 * @spec openspec/changes/woo-dossier-publication/specs/publications/spec.md#requirement-public-search-takes-the-portals-filter-names-req-wdp-002
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Exception\MalformedSearchParameterException;
use OCA\OpenCatalogi\Service\PublicationQueryService;
use OCA\OpenCatalogi\Service\SearchQueryTranslator;
use OCA\OpenCatalogi\Service\SearchRangeGuard;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SearchQueryTranslator.
 */
class SearchQueryTranslatorTest extends TestCase {

	public function testThePortalNamesBecomeThePublicationsProperties(): void {
		$this->assertSame(
			[
				'_search' => 'windpark',
				'wooCategory' => ['infocat014'],
				'organization' => ['org-1'],
				'publicationDate' => ['gte' => '2026-01-01', 'lte' => '2026-06-30'],
			],
			SearchQueryTranslator::translateSearchParams([
				'_search' => 'windpark',
				'informatiecategorie' => ['infocat014'],
				'organisation' => ['org-1'],
				'periodFrom' => '2026-01-01',
				'periodTo' => '2026-06-30',
			])
		);
	}

	public function testTheCallersOwnNamesWin(): void {
		$this->assertSame(
			['wooCategory' => 'infocat010', 'publicationDate' => ['gte' => '2025-01-01']],
			SearchQueryTranslator::translateSearchParams(['wooCategory' => 'infocat010', 'informatiecategorie' => 'infocat014', 'publicationDate' => ['gte' => '2025-01-01'], 'periodFrom' => '2026-01-01'])
		);
	}

	public function testEmptyFiltersAreDropped(): void {
		$this->assertSame(['_search' => 'x'], SearchQueryTranslator::translateSearchParams(['_search' => 'x', 'informatiecategorie' => [], 'periodFrom' => '', 'periodTo' => null]));
	}

	public function testAMalformedPeriodIsRefusedByNameByTheExistingGuard(): void {
		$params = SearchQueryTranslator::translateSearchParams(['periodFrom' => 'yesterday']);
		$this->assertSame('publicationDate[gte]', SearchRangeGuard::malformedParameter(queryParams: $params));

		$this->expectException(MalformedSearchParameterException::class);
		(new PublicationQueryService(container: $this->createMock(\Psr\Container\ContainerInterface::class)))->assemblePublicSearchResults(queryParams: $params, objectService: new \stdClass());
	}

	/**
	 * @spec openspec/changes/saved-searches-and-alerts/specs/saved-searches/spec.md#requirement-matching-finds-only-publications-the-resident-could-have-found-new-since-the-last-run-req-ssa-002
	 */
	public function testASavedQueryBecomesTheSameSearch(): void {
		$this->assertSame(
			[
				'_search' => 'windpark',
				'_catalog' => 'woo',
				'wooCategory' => ['infocat014'],
				'publicationDate' => ['gte' => '2026-01-01'],
			],
			SearchQueryTranslator::fromSavedQuery([
				'text' => 'windpark',
				'filters' => ['informatiecategorie' => ['infocat014'], 'organisation' => [], 'periodFrom' => '2026-01-01', 'periodTo' => ''],
				'catalog' => 'woo',
			])
		);
	}
}
