<?php

/**
 * CuratedCategoryRegistry test: the administrator's order wins.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenCatalogi\Tests\Unit\Service\ValueList
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link https://www.OpenCatalogi.nl
 * @spec openspec/changes/woo-value-list-curation/tasks.md#task-2.1
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Service\ValueList;

use OCA\OpenCatalogi\Service\ValueList\CuratedCategoryRegistry;
use OCA\OpenCatalogi\Service\ValueList\ValueListChoiceStore;
use OCA\OpenCatalogi\Service\Woo\WooCategoryRegistry;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The wrapped registry and the choice store are doubles.
 *
 * @covers \OCA\OpenCatalogi\Service\ValueList\CuratedCategoryRegistry
 */
class CuratedCategoryRegistryTest extends TestCase {

	/**
	 * The TOOI base of the categories.
	 */
	private const KERN = 'https://identifier.overheid.nl/tooi/def/thes/kern/';

	/**
	 * Three categories in the set's own order, and a local one under the subsidies member.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function set(): array {
		return [
			'infocat001' => ['code' => 'infocat001', 'tooiUri' => self::KERN . 'c_139c6280', 'origin' => 'waardelijst'],
			'infocat011' => ['code' => 'infocat011', 'tooiUri' => self::KERN . 'c_8fc2335c', 'origin' => 'waardelijst'],
			'infocat013' => ['code' => 'infocat013', 'tooiUri' => self::KERN . 'c_cf268088', 'origin' => 'waardelijst'],
			'subsidieregeling' => ['code' => 'subsidieregeling', 'tooiUri' => self::KERN . 'c_cf268088', 'origin' => 'lokaal'],
		];
	}//end set()

	/**
	 * The decorator over the set and a store answering the choices, or failing.
	 *
	 * @param array<string, array<string, mixed>>|null $choices The choices by entry URI, or null to fail.
	 * @param int                                      $reads   How often the store may be read.
	 *
	 * @return CuratedCategoryRegistry
	 */
	private function registry(?array $choices, int $reads = 1): CuratedCategoryRegistry {
		$registry = $this->createMock(originalClassName: WooCategoryRegistry::class);
		$registry->method('all')->willReturn(self::set());
		$registry->method('find')->willReturnCallback(static fn (string $code): ?array => (self::set()[$code] ?? null));
		$registry->method('sitemapFiles')->willReturn(['sitemapindex-diwoo-infocat001.xml' => 'Wetten']);
		$registry->method('rejected')->willReturn(['bad' => 'no mapsTo']);
		$registry->method('schemasFor')->willReturn(['publication']);
		$registry->method('findBySitemapFile')->willReturn(['code' => 'infocat001']);
		$registry->method('resolveForDocument')->willReturn(['uri' => self::KERN . 'c_139c6280', 'label' => 'Wetten']);

		$store = $this->createMock(originalClassName: ValueListChoiceStore::class);
		$expects = $store->expects($this->exactly(count: $reads))->method('forScheme')->with(CuratedCategoryRegistry::SCHEME);
		if ($choices === null) {
			$expects->willThrowException(new RuntimeException('not configured'));
		} else {
			$expects->willReturn($choices);
		}

		return new CuratedCategoryRegistry(registry: $registry, choices: $store);
	}//end registry()

	/**
	 * An administrator puts subsidies first and covenants second: those lead, the rest follow in the set's order.
	 *
	 * @return void
	 */
	public function testTheAdministratorsOrderWins(): void {
		$all = $this->registry(choices: [
			self::KERN . 'c_cf268088' => ['order' => 0],
			self::KERN . 'c_8fc2335c' => ['order' => 1],
		])->all();

		$this->assertSame(expected: ['infocat013', 'subsidieregeling', 'infocat011', 'infocat001'], actual: array_keys($all));
		$this->assertSame(expected: self::set()['infocat001'], actual: $all['infocat001']);
	}//end testTheAdministratorsOrderWins()

	/**
	 * Without choices the set keeps its own order.
	 *
	 * @return void
	 */
	public function testWithoutAnOrderTheSetKeepsItsOwnOrder(): void {
		$this->assertSame(expected: array_keys(self::set()), actual: array_keys($this->registry(choices: [])->all()));
	}//end testWithoutAnOrderTheSetKeepsItsOwnOrder()

	/**
	 * Unreadable choices leave every category standing, in the set's own order.
	 *
	 * @return void
	 */
	public function testUnreadableChoicesKeepEveryCategory(): void {
		$this->assertSame(expected: self::set(), actual: $this->registry(choices: null)->all());
	}//end testUnreadableChoicesKeepEveryCategory()

	/**
	 * The order is read once per request.
	 *
	 * @return void
	 */
	public function testTheOrderIsReadOncePerRequest(): void {
		$registry = $this->registry(choices: [self::KERN . 'c_8fc2335c' => ['order' => 0]], reads: 1);

		$this->assertSame(expected: $registry->all(), actual: $registry->all());
	}//end testTheOrderIsReadOncePerRequest()

	/**
	 * Every other answer is the wrapped registry's, and reads no choice.
	 *
	 * @return void
	 */
	public function testEveryOtherAnswerIsTheWrappedRegistrys(): void {
		$registry = $this->registry(choices: [], reads: 0);

		$this->assertSame(expected: 'infocat011', actual: $registry->find(code: 'infocat011')['code']);
		$this->assertSame(expected: ['sitemapindex-diwoo-infocat001.xml' => 'Wetten'], actual: $registry->sitemapFiles());
		$this->assertSame(expected: ['bad' => 'no mapsTo'], actual: $registry->rejected());
		$this->assertSame(expected: ['publication'], actual: $registry->schemasFor(code: 'infocat001'));
		$this->assertSame(expected: ['code' => 'infocat001'], actual: $registry->findBySitemapFile(sitemapFile: 'sitemapindex-diwoo-infocat001.xml'));
		$this->assertSame(expected: 'Wetten', actual: $registry->resolveForDocument(value: 'infocat001')['label']);
	}//end testEveryOtherAnswerIsTheWrappedRegistrys()
	/**
	 * A hidden category is not offered, nor the local category under it; all() keeps both for the sitemaps.
	 *
	 * @return void
	 */
	public function testAHiddenCategoryIsNotOfferedButKeepsItsSitemap(): void {
		$registry = $this->registry(choices: [self::KERN . 'c_cf268088' => ['order' => null, 'hidden' => true]]);

		$this->assertSame(expected: ['infocat001', 'infocat011'], actual: array_keys($registry->offered()));
		$this->assertArrayHasKey(key: 'infocat013', array: $registry->all());
		$this->assertArrayHasKey(key: 'subsidieregeling', array: $registry->all());
		$this->assertSame(expected: ['sitemapindex-diwoo-infocat001.xml' => 'Wetten'], actual: $registry->sitemapFiles());
	}//end testAHiddenCategoryIsNotOfferedButKeepsItsSitemap()

	/**
	 * Unreadable choices hide nothing.
	 *
	 * @return void
	 */
	public function testUnreadableChoicesHideNothing(): void {
		$this->assertSame(expected: array_keys(self::set()), actual: array_keys($this->registry(choices: null)->offered()));
	}//end testUnreadableChoicesHideNothing()
}//end class
