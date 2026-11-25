<?php

/**
 * HiddenCategoryCheck test: the publications filed under a category are counted by every value they may store.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenCatalogi\Tests\Unit\Listener
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link https://www.OpenCatalogi.nl
 * @spec openspec/changes/woo-value-list-curation/tasks.md#task-4.2
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Listener;

use OCA\OpenCatalogi\Contract\InformationCategoryRegistryInterface;
use OCA\OpenCatalogi\Contract\OpenRegisterLookupInterface;
use OCA\OpenCatalogi\Listener\HiddenCategoryCheck;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The registry and OpenRegister are doubles.
 *
 * @covers \OCA\OpenCatalogi\Listener\HiddenCategoryCheck
 */
class HiddenCategoryCheckTest extends TestCase {

	/**
	 * The TOOI URI of the subsidies category.
	 */
	private const SUBSIDIES = 'https://identifier.overheid.nl/tooi/def/thes/kern/c_cf268088';

	/**
	 * The category values queried.
	 *
	 * @var list<string>
	 */
	private array $queried = [];

	/**
	 * The check over the categories and publications counted per stored value.
	 *
	 * @param array<string, int> $counts     Publications per stored category value.
	 * @param bool               $configured Whether the publication schema is configured.
	 *
	 * @return HiddenCategoryCheck
	 */
	private function check(array $counts, bool $configured = true): HiddenCategoryCheck {
		$registry = $this->createMock(originalClassName: InformationCategoryRegistryInterface::class);
		$registry->method('all')->willReturn([
			'infocat013' => ['code' => 'infocat013', 'label' => 'Subsidieverplichtingen anders dan met beschikking', 'tooiUri' => self::SUBSIDIES],
			'subsidieregeling' => ['code' => 'subsidieregeling', 'label' => 'Subsidieregeling', 'tooiUri' => self::SUBSIDIES],
			'infocat011' => ['code' => 'infocat011', 'label' => 'Convenanten', 'tooiUri' => 'https://identifier.overheid.nl/tooi/def/thes/kern/c_8fc2335c'],
		]);

		$test = $this;
		$objects = new class($counts, $test) {
			/**
			 * @param array<string, int>    $counts The counts.
			 * @param HiddenCategoryCheckTest $test   The test, which records the values queried.
			 */
			public function __construct(private array $counts, private HiddenCategoryCheckTest $test) {
			}

			/**
			 * @param array<string, mixed> $query The query.
			 * @param bool $_rbac Whether RBAC applies.
			 * @param bool $_multitenancy Whether multitenancy applies.
			 * @return array<string, mixed>
			 */
			public function searchObjectsPaginated(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->test->record(value: (string)$query['wooCategory']);
				return ['results' => [], 'total' => ($this->counts[(string)$query['wooCategory']] ?? 0)];
			}
		};

		$openRegister = $this->createMock(originalClassName: OpenRegisterLookupInterface::class);
		$openRegister->method('objectService')->willReturn($objects);
		$openRegister->method('registerConfiguration')->willReturnCallback(
			static function () use ($configured): array {
				if ($configured === false) {
					throw new RuntimeException('publication_schema is not configured');
				}

				return ['register' => '7', 'schema' => '12'];
			}
		);

		return new HiddenCategoryCheck(categories: $registry, openRegister: $openRegister);
	}//end check()

	/**
	 * Record one queried value.
	 *
	 * @param string $value The value.
	 *
	 * @return void
	 */
	public function record(string $value): void {
		$this->queried[] = $value;
	}//end record()

	/**
	 * Publications stored by code, by label, under a local category and by URI are all counted.
	 *
	 * @return void
	 */
	public function testEveryValueAPublicationMayStoreIsCounted(): void {
		$count = $this->check(counts: [
			'infocat013' => 1,
			'Subsidieverplichtingen anders dan met beschikking' => 1,
			'subsidieregeling' => 1,
			self::SUBSIDIES => 0,
		])->publicationsUnder(entry: self::SUBSIDIES);

		$this->assertSame(expected: 3, actual: $count);
		$this->assertContains(needle: self::SUBSIDIES, haystack: $this->queried);
		$this->assertNotContains(needle: 'infocat011', haystack: $this->queried);
	}//end testEveryValueAPublicationMayStoreIsCounted()

	/**
	 * An entry no category has counts nothing and queries nothing.
	 *
	 * @return void
	 */
	public function testAnUnknownEntryHasNoPublications(): void {
		$this->assertSame(expected: 0, actual: $this->check(counts: [])->publicationsUnder(entry: 'https://example.org/none'));
		$this->assertSame(expected: [], actual: $this->queried);
	}//end testAnUnknownEntryHasNoPublications()

	/**
	 * Without the publication schema the count is unknown, not zero.
	 *
	 * @return void
	 */
	public function testWithoutThePublicationSchemaTheCountIsUnknown(): void {
		$this->assertNull(actual: $this->check(counts: [], configured: false)->publicationsUnder(entry: self::SUBSIDIES));
	}//end testWithoutThePublicationSchemaTheCountIsUnknown()
}//end class
