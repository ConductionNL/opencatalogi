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
use OCA\OpenCatalogi\Service\Woo\LocalCategoryAdmission;
use OCA\OpenCatalogi\Service\Woo\WooCategoryRegistry;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for WooCategoryRegistry.
 */
class WooCategoryRegistryTest extends TestCase {

	/**
	 * A registry that reads the given rows from OpenRegister.
	 *
	 * The rows are handed in where OpenRegister hands them in, through the object
	 * service, so the production read path and the production admission rules are
	 * both under test. An earlier revision of this file subclassed the registry and
	 * overrode the read instead, which left the whole storage read uncovered and
	 * untested: the schema lookup, the query and the fail-soft catch.
	 *
	 * @param array<int, array<string, mixed>> $stored The stored local categories.
	 *
	 * @return WooCategoryRegistry The registry.
	 */
	private function registry(array $stored = []): WooCategoryRegistry {
		$rows = array_map(
			function (array $row): ObjectEntity {
				$object = $this->createMock(ObjectEntity::class);
				$object->method('jsonSerialize')->willReturn($row);

				return $object;
			},
			$stored
		);

		$schema = $this->createMock(Schema::class);
		$schema->method('getId')->willReturn(42);

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturn($schema);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('searchObjectsPaginated')->willReturn(['results' => $rows, 'total' => count($rows)]);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getSchemaMapper')->willReturn($schemaMapper);

		return $this->registryOver(settings: $settings);
	}

	/**
	 * A registry over a given settings service.
	 *
	 * @param SettingsService $settings The settings service.
	 *
	 * @return WooCategoryRegistry The registry.
	 */
	private function registryOver(SettingsService $settings): WooCategoryRegistry {
		$tooi = new TooiVocabularyService();

		return new WooCategoryRegistry(
			$tooi,
			$settings,
			$this->createMock(LoggerInterface::class),
			new LocalCategoryAdmission($tooi),
		);
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

	public function testALocalCategoryAlsoResolvesByItsOwnNameForADocument(): void {
		// A record written before the code existed can hold the category's name
		// rather than its code, so the name resolves too.
		$registry = $this->registry(
			[['code' => 'aanbestedingen', 'title' => 'Aanbestedingen', 'mapsTo' => 'infocat010']]
		);

		$member = $registry->resolveForDocument('Aanbestedingen');
		$this->assertNotNull($member);
		$this->assertSame('Adviezen', $member['label']);
	}

	public function testAStoredRowThatIsAPlainArrayIsReadToo(): void {
		// OpenRegister hands back entities, but a caller that already serialised them
		// must not silently lose its categories.
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('searchObjectsPaginated')->willReturn(
			['results' => [['code' => 'aanbestedingen', 'title' => 'Aanbestedingen', 'mapsTo' => 'infocat010']]]
		);

		$schema = $this->createMock(Schema::class);
		$schema->method('getId')->willReturn(42);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturn($schema);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getSchemaMapper')->willReturn($schemaMapper);

		$this->assertArrayHasKey('aanbestedingen', $this->registryOver(settings: $settings)->all());
	}

	public function testAnInstanceWithoutTheSchemaServesTheWaardelijstMembersOnly(): void {
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturn(null);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->createMock(ObjectService::class));
		$settings->method('getSchemaMapper')->willReturn($schemaMapper);

		$registry = $this->registryOver(settings: $settings);
		$this->assertCount(18, $registry->all());
		$this->assertSame([], $registry->rejected());
	}

	public function testAnInstanceWithoutOpenRegisterServesTheWaardelijstMembersOnly(): void {
		// The mock answers null for both accessors, which is what an instance without
		// OpenRegister looks like to this service.
		$this->assertCount(18, $this->registryOver(settings: $this->createMock(SettingsService::class))->all());
	}

	public function testAFailingStorageReadLeavesTheWaardelijstMembersStanding(): void {
		// Fail soft, not closed: the 18 statutory categories must keep publishing
		// when the lookup for the local extras throws.
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->createMock(ObjectService::class));
		$settings->method('getSchemaMapper')->willThrowException(new \RuntimeException('SchemaMapper is not available.'));

		$registry = $this->registryOver(settings: $settings);
		$this->assertCount(18, $registry->all());
		$this->assertArrayHasKey('sitemapindex-diwoo-infocat001.xml', $registry->sitemapFiles());
	}

	public function testTheMergedSetIsReadOncePerRequest(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->expects($this->once())->method('searchObjectsPaginated')->willReturn(['results' => []]);

		$schema = $this->createMock(Schema::class);
		$schema->method('getId')->willReturn(42);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturn($schema);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getSchemaMapper')->willReturn($schemaMapper);

		$registry = $this->registryOver(settings: $settings);
		$registry->all();
		$registry->sitemapFiles();
		$registry->find(code: 'infocat001');
	}

	public function testTheShippedDemoCategoriesAreOnesThisAppAdmits(): void {
		// Demo data the app's own admission rules refuse would publish nothing on a
		// fresh install, and the walkthrough that loads it would look like it worked.
		$register = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../../lib/Settings/opencatalogi_mock_register.json'),
			true
		);
		$demo = array_values(
			array_filter(
				($register['components']['objects'] ?? []),
				static fn (array $row): bool => (($row['@self']['schema'] ?? null) === WooCategoryRegistry::SCHEMA_SLUG)
			)
		);

		$this->assertNotSame([], $demo, 'The demo register ships no information category.');

		$registry = $this->registry($demo);
		$this->assertSame([], $registry->rejected(), 'A shipped demo category is refused by this app.');
		foreach ($demo as $row) {
			$record = $registry->find(code: $row['code']);
			$this->assertNotNull($record, 'Demo category ' . $row['code'] . ' is absent from the registry.');
			$this->assertSame(WooCategoryRegistry::ORIGIN_LOCAL, $record['origin']);
			$this->assertStringStartsWith(TooiVocabularyService::KERN_BASE, $record['tooiUri']);
		}
	}

	public function testASitemapFileNameOutsideTheConventionResolvesToNothing(): void {
		$this->assertNull(WooCategoryRegistry::codeOf(sitemapFile: 'sitemap.xml'));
		$this->assertNull(WooCategoryRegistry::codeOf(sitemapFile: 'sitemapindex-diwoo-.xml'));
		$this->assertNull(WooCategoryRegistry::codeOf(sitemapFile: 'sitemapindex-diwoo-infocat001.xml.bak'));
		$this->assertNull(WooCategoryRegistry::codeOf(sitemapFile: 'sitemapindex-diwoo-Infocat001.xml'));
		$this->assertSame('infocat001', WooCategoryRegistry::codeOf(sitemapFile: 'sitemapindex-diwoo-infocat001.xml'));
	}
}
