<?php

/**
 * The information categories are data, and a local one publishes under a member.
 *
 * Covers REQ-WIC-001 (a new category needs no code), REQ-WIC-002 (a local category
 * that names no waardelijst member reaches no sitemap) and REQ-WIC-003 (a category
 * names the schemas its sitemap lists).
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
 * @spec openspec/specs/woo-compliance/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Woo;

use OCA\OpenCatalogi\Service\SettingsService;
use OCA\OpenCatalogi\Service\TooiVocabularyService;
use OCA\OpenCatalogi\Service\Woo\WooCategoryRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for WooCategoryRegistry.
 */
class WooCategoryRegistryTest extends TestCase {

	/**
	 * A registry whose stored categories are the given rows.
	 *
	 * The rows are handed in where OpenRegister would hand them in, so the admission
	 * rules under test are the production ones and not a copy.
	 *
	 * @param array<int, array<string, mixed>> $stored The stored local categories.
	 *
	 * @return WooCategoryRegistry The registry.
	 */
	private function registry(array $stored = []): WooCategoryRegistry {
		$settings = $this->createMock(SettingsService::class);

		$registry = new class(new TooiVocabularyService(), $settings, $this->createMock(LoggerInterface::class), $stored) extends WooCategoryRegistry {
			/**
			 * @param TooiVocabularyService $tooi The waardelijst resolver.
			 * @param SettingsService $settings The settings service.
			 * @param LoggerInterface $logger The logger.
			 * @param array<int, array<string, mixed>> $stored The stored rows.
			 */
			public function __construct(
				TooiVocabularyService $tooi,
				SettingsService $settings,
				LoggerInterface $logger,
				private readonly array $stored,
			) {
				parent::__construct($tooi, $settings, $logger);
			}

			/**
			 * @return array<int, array<string, mixed>> The stored rows.
			 */
			protected function readStoredCategories(): array {
				return $this->stored;
			}
		};

		return $registry;
	}

	public function testTheEighteenWaardelijstMembersNeedNoStoredRow(): void {
		$all = $this->registry()->all();

		$this->assertCount(18, $all);
		$this->assertSame('Inspanningsverplichting art 3.1 Woo', $all['infocat018']['label']);
		$this->assertSame(
			'https://identifier.overheid.nl/tooi/def/thes/kern/c_816e508d',
			$all['infocat018']['tooiUri']
		);
		$this->assertSame(WooCategoryRegistry::ORIGIN_VALUE_LIST, $all['infocat018']['origin']);
	}

	public function testALocalCategoryNeedsNoCode(): void {
		$all = $this->registry(
			[['code' => 'aanbestedingen', 'title' => 'Aanbestedingen', 'mapsTo' => 'infocat018']]
		)->all();

		$this->assertCount(19, $all);
		$this->assertSame('Aanbestedingen', $all['aanbestedingen']['label']);
		$this->assertSame(WooCategoryRegistry::ORIGIN_LOCAL, $all['aanbestedingen']['origin']);
		$this->assertSame('sitemapindex-diwoo-aanbestedingen.xml', $all['aanbestedingen']['sitemapFile']);
	}

	public function testALocalCategoryCarriesTheWaardelijstUriItPublishesUnder(): void {
		$all = $this->registry(
			[['code' => 'aanbestedingen', 'title' => 'Aanbestedingen', 'mapsTo' => 'inspanningsverplichting art 3.1 Woo']]
		)->all();

		$this->assertSame(
			'https://identifier.overheid.nl/tooi/def/thes/kern/c_816e508d',
			$all['aanbestedingen']['tooiUri']
		);
		$this->assertSame('infocat018', $all['aanbestedingen']['mapsTo']);
	}

	public function testEveryCategoryTheRegistryServesCarriesAWaardelijstUri(): void {
		// This is the guarantee: the sitemap is built from all(), and nothing in
		// all() lacks a URI, so no sitemap can carry a category the index refuses.
		$all = $this->registry(
			[
				['code' => 'aanbestedingen', 'title' => 'Aanbestedingen', 'mapsTo' => 'infocat018'],
				['code' => 'verzonnen', 'title' => 'Verzonnen', 'mapsTo' => 'iets anders'],
				['code' => 'leeg', 'title' => 'Leeg'],
			]
		)->all();

		foreach ($all as $code => $record) {
			$this->assertNotSame('', $record['tooiUri'], "Category $code has no waardelijst URI.");
			$this->assertStringStartsWith(TooiVocabularyService::KERN_BASE, $record['tooiUri']);
		}
	}

	public function testALocalCategoryWithAnUnresolvableMapsToGetsNoSitemap(): void {
		$registry = $this->registry(
			[['code' => 'verzonnen', 'title' => 'Verzonnen', 'mapsTo' => 'iets anders']]
		);

		$this->assertArrayNotHasKey('verzonnen', $registry->all());
		$this->assertArrayNotHasKey('sitemapindex-diwoo-verzonnen.xml', $registry->sitemapFiles());
		$this->assertNull($registry->findBySitemapFile(sitemapFile: 'sitemapindex-diwoo-verzonnen.xml'));
		$this->assertArrayHasKey('verzonnen', $registry->rejected());
	}

	public function testALocalCategoryWithoutAMapsToGetsNoSitemap(): void {
		$registry = $this->registry([['code' => 'leeg', 'title' => 'Leeg']]);

		$this->assertArrayNotHasKey('leeg', $registry->all());
		$this->assertArrayHasKey('leeg', $registry->rejected());
	}

	public function testALocalCategoryCannotShadowAWaardelijstMember(): void {
		$registry = $this->registry(
			[['code' => 'infocat012', 'title' => 'Mijn jaarverslagen', 'mapsTo' => 'infocat010']]
		);

		$this->assertSame('Jaarplannen en jaarverslagen', $registry->all()['infocat012']['label']);
		$this->assertArrayHasKey('infocat012', $registry->rejected());
	}

	public function testACodeThatCannotBeAFileNameIsRefused(): void {
		$registry = $this->registry(
			[['code' => 'mijn/categorie.xml', 'title' => 'Mijn categorie', 'mapsTo' => 'infocat010']]
		);

		$this->assertCount(18, $registry->all());
		$this->assertCount(1, $registry->rejected());
	}

	public function testARefusedCategoryResolvesToNoMemberForADocument(): void {
		$registry = $this->registry(
			[['code' => 'verzonnen', 'title' => 'Verzonnen', 'mapsTo' => 'iets anders']]
		);

		// The renderer omits the axis on null, so a publication filed under a refused
		// category never emits a free-text @resource.
		$this->assertNull($registry->resolveForDocument('verzonnen'));
	}

	public function testAnAdmittedLocalCategoryResolvesToItsWaardelijstMemberForADocument(): void {
		$registry = $this->registry(
			[['code' => 'aanbestedingen', 'title' => 'Aanbestedingen', 'mapsTo' => 'infocat018']]
		);

		$member = $registry->resolveForDocument('aanbestedingen');
		$this->assertNotNull($member);
		$this->assertSame('https://identifier.overheid.nl/tooi/def/thes/kern/c_816e508d', $member['uri']);
		// The label is the waardelijst label, not the local name: the index reads the
		// value list, so the text beside the URI must be that list's own wording.
		$this->assertSame('Inspanningsverplichting art 3.1 Woo', $member['label']);
	}

	public function testAWaardelijstCodeStillResolvesForADocument(): void {
		$member = $this->registry()->resolveForDocument('infocat012');

		$this->assertNotNull($member);
		$this->assertSame('Jaarplannen en jaarverslagen', $member['label']);
	}

	public function testACategoryNamesTheSchemasItsSitemapLists(): void {
		$registry = $this->registry(
			[
				[
					'code' => 'aanbestedingen',
					'title' => 'Aanbestedingen',
					'mapsTo' => 'infocat018',
					'schemas' => ['tender', '', 'tender', 'award'],
				],
			]
		);

		$this->assertSame(['tender', 'award'], $registry->schemasFor(code: 'aanbestedingen'));
		$this->assertSame([], $registry->schemasFor(code: 'infocat012'));
		$this->assertSame([], $registry->schemasFor(code: 'nonexistent'));
	}

	public function testASitemapFileNameOutsideTheConventionResolvesToNothing(): void {
		$this->assertNull(WooCategoryRegistry::codeOf(sitemapFile: 'sitemap.xml'));
		$this->assertNull(WooCategoryRegistry::codeOf(sitemapFile: 'sitemapindex-diwoo-.xml'));
		$this->assertNull(WooCategoryRegistry::codeOf(sitemapFile: 'sitemapindex-diwoo-infocat001.xml.bak'));
		$this->assertNull(WooCategoryRegistry::codeOf(sitemapFile: 'sitemapindex-diwoo-Infocat001.xml'));
		$this->assertSame('infocat001', WooCategoryRegistry::codeOf(sitemapFile: 'sitemapindex-diwoo-infocat001.xml'));
	}
}
