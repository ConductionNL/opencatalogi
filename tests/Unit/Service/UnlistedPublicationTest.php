<?php

/**
 * UnlistedPublicationTest
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/publication-lifecycle-on-or/tasks.md#task-6.1
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Service;

use OCA\OpenCatalogi\Service\DcatService;
use OCA\OpenCatalogi\Service\OpenRegisterLookup;
use OCA\OpenCatalogi\Service\Publication\UnlistedPublications;
use OCA\OpenCatalogi\Service\PublicationService;
use OCA\OpenCatalogi\Service\SitemapService;
use OCP\AppFramework\Http\JSONResponse;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * An unlisted publication is left out of the sitemaps, DCAT and the federation list.
 *
 * @covers \OCA\OpenCatalogi\Service\Publication\UnlistedPublications
 * @covers \OCA\OpenCatalogi\Service\SitemapService
 * @covers \OCA\OpenCatalogi\Service\DcatService
 * @covers \OCA\OpenCatalogi\Service\PublicationService
 */
final class UnlistedPublicationTest extends TestCase {

	/** @var list<array<string, mixed>> */
	private const ROWS = [
		['id' => 'listed', 'title' => 'Besluit A'],
		['id' => 'unlisted', 'title' => 'Besluit B', 'unlisted' => true],
		['id' => 'cleared', 'title' => 'Besluit C', 'unlisted' => false],
	];

	/**
	 * Only a true flag unlists; false, null and a missing flag keep a publication listed.
	 *
	 * @return void
	 */
	public function testTheFlagIsReadStrictly(): void {
		self::assertTrue(UnlistedPublications::isUnlisted(['unlisted' => true]));
		self::assertTrue(UnlistedPublications::isUnlisted(['unlisted' => 'true']));
		self::assertFalse(UnlistedPublications::isUnlisted(['unlisted' => false]));
		self::assertFalse(UnlistedPublications::isUnlisted(['unlisted' => null]));
		self::assertFalse(UnlistedPublications::isUnlisted(['unlisted' => 'yes']));
		self::assertFalse(UnlistedPublications::isUnlisted([]));
		self::assertFalse(UnlistedPublications::isUnlisted('unlisted'));

		$page = UnlistedPublications::withoutUnlisted(['results' => self::ROWS, 'total' => 10]);
		self::assertSame(['listed', 'cleared'], array_column($page['results'], 'id'));
		self::assertSame(9, $page['total']);
	}//end testTheFlagIsReadStrictly()

	/**
	 * The DiWoo sitemap page, the DCAT page and the local federation list carry the listed rows only.
	 *
	 * @return void
	 */
	public function testAnUnlistedPublicationIsAbsentFromSitemapDcatAndFederation(): void {
		$files = $this->getMockBuilder(\OCA\OpenRegister\Service\FileService::class)
			->disableOriginalConstructor()
			->onlyMethods(['getFiles', 'formatFiles'])
			->getMock();
		$files->method('getFiles')->willReturn([]);
		$files->method('formatFiles')->willReturn(['results' => []]);
		$objects = $this->getMockBuilder(\OCA\OpenRegister\Service\ObjectService::class)
			->disableOriginalConstructor()
			->onlyMethods(['searchObjectsPaginated'])
			->getMock();
		$objects->method('searchObjectsPaginated')->willReturn(['results' => self::ROWS, 'total' => 3]);
		$lookup = $this->createMock(OpenRegisterLookup::class);
		$lookup->method('installedService')->willReturnCallback(
			static fn (string $class): object => str_ends_with($class, 'FileService') ? $files : $objects
		);
		$lookup->method('installedObjectService')->willReturn(null);

		// The sitemap gathers its publications in publicationsWithFiles().
		$sitemap = (new ReflectionClass(SitemapService::class))->newInstanceWithoutConstructor();
		(new ReflectionProperty(SitemapService::class, 'openRegister'))->setValue($sitemap, $lookup);
		$sitemapRows = (new ReflectionMethod(SitemapService::class, 'publicationsWithFiles'))->invoke($sitemap, self::ROWS);
		self::assertSame(['listed', 'cleared'], array_column($sitemapRows, 'id'));

		// DCAT reads one catalogue page in searchCatalogPage().
		$dcat = (new ReflectionClass(DcatService::class))->newInstanceWithoutConstructor();
		(new ReflectionProperty(DcatService::class, 'openRegister'))->setValue($dcat, $lookup);
		$dcatPage = (new ReflectionMethod(DcatService::class, 'searchCatalogPage'))->invoke($dcat, ['schemas' => [1], 'registers' => [2]], 1);
		self::assertSame(['listed', 'cleared'], array_column($dcatPage['results'], 'id'));
		self::assertSame(2, $dcatPage['total']);

		// The federation list starts from the local page in fetchLocalPublicationData().
		$publications = $this->getMockBuilder(PublicationService::class)->disableOriginalConstructor()->onlyMethods(['index'])->getMock();
		$publications->method('index')->willReturn(new JSONResponse(['results' => self::ROWS, 'total' => 3]));
		$local = (new ReflectionMethod(PublicationService::class, 'fetchLocalPublicationData'))->invoke($publications, []);
		self::assertSame(['listed', 'cleared'], array_column($local['results'], 'id'));
		self::assertSame(2, $local['total']);
	}//end testAnUnlistedPublicationIsAbsentFromSitemapDcatAndFederation()

	/**
	 * Clearing the flag puts a publication back on the list at once.
	 *
	 * @return void
	 */
	public function testClearingTheFlagPutsItBack(): void {
		$rows = self::ROWS;
		$rows[1]['unlisted'] = false;

		self::assertSame(['listed', 'unlisted', 'cleared'], array_column(UnlistedPublications::withoutUnlisted(['results' => $rows])['results'], 'id'));
	}//end testClearingTheFlagPutsItBack()
}//end class
