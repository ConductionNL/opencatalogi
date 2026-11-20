<?php

/**
 * Tests for SubjectSearch: the subjects a public search term matches, shaped
 * as subject results (subjects-as-first-class-records, REQ-SUB-004).
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
 * @spec openspec/changes/subjects-as-first-class-records/specs/subjects/spec.md#requirement-public-search-returns-subjects-as-their-own-result-type-req-sub-004
 */

declare(strict_types=1);

namespace Unit\Service\Subject;

use OCA\OpenCatalogi\Contract\OpenRegisterLookupInterface;
use OCA\OpenCatalogi\Service\Subject\SubjectPublications;
use OCA\OpenCatalogi\Service\Subject\SubjectSearch;
use OCP\AppFramework\Db\Entity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * An ObjectService double that records its queries and answers fixed theme rows.
 */
class SubjectSearchStore {

	/**
	 * @var array<int, array{0: array<string, mixed>, 1: bool, 2: bool}>
	 */
	public array $calls = [];

	/**
	 * @param array<int, mixed> $rows The theme rows to answer.
	 */
	public function __construct(
		private array $rows,
	) {
	}

	public function searchObjectsPaginated(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
		$this->calls[] = [$query, $_rbac, $_multitenancy];
		return ['results' => $this->rows, 'total' => count($this->rows)];
	}
}

/**
 * SubjectSearch.
 */
#[CoversClass(SubjectSearch::class)]
#[UsesClass(SubjectPublications::class)]
class SubjectSearchTest extends TestCase {

	private OpenRegisterLookupInterface&MockObject $registers;

	private SubjectPublications&MockObject $publications;

	protected function setUp(): void {
		$this->registers = $this->createMock(OpenRegisterLookupInterface::class);
		$this->publications = $this->createMock(SubjectPublications::class);
	}

	private function search(object $store, string $term, array $pair = ['register' => '4', 'schema' => '7']): array {
		$this->registers->method('registerConfiguration')->with('theme_register', 'theme_schema')->willReturn($pair);
		return (new SubjectSearch(registers: $this->registers, publications: $this->publications))->search(objectService: $store, term: $term);
	}

	public function testItSearchesTheThemesWithTheTermAndShapesEachSubject(): void {
		$entity = $this->getMockBuilder(Entity::class)->addMethods(['jsonSerialize'])->getMock();
		$entity->method('jsonSerialize')->willReturn(['@self' => ['id' => 'b/2'], 'title' => 'Zwembad']);
		$store = new SubjectSearchStore([
			['@self' => ['id' => 'a1'], 'slug' => 'sporthal', 'title' => 'Sporthal', 'summary' => 'S', 'image' => 'i.png', 'description' => 'not returned'],
			$entity,
		]);
		$this->publications->method('countPublic')->willReturnMap([['a1', 3], ['b/2', 0]]);

		$results = $this->search(store: $store, term: '  sport ');

		$this->assertSame(
			[[['@self' => ['register' => '4', 'schema' => '7'], '_search' => 'sport', '_limit' => SubjectSearch::LIMIT], true, false]],
			$store->calls
		);
		$this->assertSame(
			[
				['resultType' => 'subject', 'id' => 'a1', 'slug' => 'sporthal', 'title' => 'Sporthal', 'summary' => 'S', 'image' => 'i.png', 'publicationCount' => 3, 'url' => '/index.php/apps/opencatalogi/api/themes/a1'],
				['resultType' => 'subject', 'id' => 'b/2', 'slug' => null, 'title' => 'Zwembad', 'summary' => null, 'image' => null, 'publicationCount' => 0, 'url' => '/index.php/apps/opencatalogi/api/themes/b%2F2'],
			],
			$results
		);
	}

	public function testARowWithoutAnIdOrNotAnArrayIsLeftOut(): void {
		$store = new SubjectSearchStore([['title' => 'No id'], 'not a row', ['id' => 'top-level', 'title' => 'Top']]);
		$this->publications->method('countPublic')->willReturn(1);

		$this->assertSame(['top-level'], array_column($this->search(store: $store, term: 'x'), 'id'));
	}

	public function testAnEmptyTermReadsNothing(): void {
		$store = new SubjectSearchStore([['id' => 'a1']]);
		$this->registers->expects($this->never())->method('registerConfiguration');

		$this->assertSame([], (new SubjectSearch(registers: $this->registers, publications: $this->publications))->search(objectService: $store, term: '   '));
		$this->assertSame([], $store->calls);
	}

	public function testAnUnconfiguredThemeRegisterOrSchemaReadsNothing(): void {
		$store = new SubjectSearchStore([['id' => 'a1']]);

		$this->assertSame([], $this->search(store: $store, term: 'x', pair: ['register' => '4', 'schema' => '']));
		$this->assertSame([], $store->calls);
	}

	public function testAnObjectServiceWithoutSearchReadsNothing(): void {
		$this->registers->expects($this->never())->method('registerConfiguration');

		$this->assertSame([], (new SubjectSearch(registers: $this->registers, publications: $this->publications))->search(objectService: new \stdClass(), term: 'x'));
	}
}
