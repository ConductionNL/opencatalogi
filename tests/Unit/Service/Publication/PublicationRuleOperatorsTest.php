<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service\Publication;

use OCA\OpenCatalogi\Service\Publication\PublicationRuleService;
use OCA\OpenCatalogi\Service\Publication\UnreadableRuleException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the condition operators and edge cases of PublicationRuleService.
 *
 * @covers \OCA\OpenCatalogi\Service\Publication\PublicationRuleService
 * @uses   \OCA\OpenCatalogi\Service\Publication\UnreadableRuleException
 */
class PublicationRuleOperatorsTest extends TestCase {

	private PublicationRuleService $service;

	protected function setUp(): void {
		$this->service = new PublicationRuleService();

	}//end setUp()

	/**
	 * A rule for a besluit with the given conditions.
	 *
	 * @param array<int, mixed> $conditions The conditions.
	 *
	 * @return array<string, mixed>
	 */
	private function rule(array $conditions): array {
		return [
			'recordType' => 'besluit',
			'enabled' => true,
			'anonymousProperties' => ['title'],
			'conditions' => $conditions,
		];

	}//end rule()

	private function holds(array $record, string $property, string $operator, mixed $value = null): bool {
		return $this->service->publishes(
			record: array_merge(['@type' => 'besluit'], $record),
			rule: $this->rule([['property' => $property, 'operator' => $operator, 'value' => $value]])
		);

	}//end holds()

	public function testNotEqualsHoldsOnlyForADifferentValue(): void {
		$this->assertTrue($this->holds(['status' => 'definitief'], 'status', 'notEquals', 'concept'));
		$this->assertFalse($this->holds(['status' => 'concept'], 'status', 'notEquals', 'concept'));

	}//end testNotEqualsHoldsOnlyForADifferentValue()

	public function testExistsLooksAtThePresenceOfTheKeyNotItsValue(): void {
		$this->assertTrue($this->holds(['note' => null], 'note', 'exists'));
		$this->assertFalse($this->holds(['title' => 'x'], 'note', 'exists'));

	}//end testExistsLooksAtThePresenceOfTheKeyNotItsValue()

	public function testNotEmptyRefusesNullEmptyStringAndEmptyList(): void {
		$this->assertTrue($this->holds(['summary' => 'Kort'], 'summary', 'notEmpty'));
		$this->assertFalse($this->holds(['summary' => null], 'summary', 'notEmpty'));
		$this->assertFalse($this->holds(['summary' => ''], 'summary', 'notEmpty'));
		$this->assertFalse($this->holds(['summary' => []], 'summary', 'notEmpty'));
		$this->assertFalse($this->holds([], 'summary', 'notEmpty'));

	}//end testNotEmptyRefusesNullEmptyStringAndEmptyList()

	public function testInMatchesStrictlyAndOnlyAgainstAList(): void {
		$this->assertTrue($this->holds(['status' => 'definitief'], 'status', 'in', ['definitief', 'vastgesteld']));
		$this->assertFalse($this->holds(['status' => 'concept'], 'status', 'in', ['definitief', 'vastgesteld']));
		$this->assertFalse($this->holds(['year' => '2026'], 'year', 'in', [2026]));
		$this->assertFalse($this->holds(['status' => 'definitief'], 'status', 'in', 'definitief'));

	}//end testInMatchesStrictlyAndOnlyAgainstAList()

	public function testBeforeAndAfterCompareDates(): void {
		$this->assertTrue($this->holds(['publicationDate' => '2026-01-01'], 'publicationDate', 'before', '2026-06-01'));
		$this->assertFalse($this->holds(['publicationDate' => '2026-09-01'], 'publicationDate', 'before', '2026-06-01'));
		$this->assertTrue($this->holds(['publicationDate' => '2026-09-01'], 'publicationDate', 'after', '2026-06-01'));
		$this->assertFalse($this->holds(['publicationDate' => '2026-06-01'], 'publicationDate', 'after', '2026-06-01'));

	}//end testBeforeAndAfterCompareDates()

	public function testARecordOfAnotherTypeDoesNotPublish(): void {
		$this->assertFalse(
			$this->service->publishes(
				record: ['recordType' => 'notitie', 'status' => 'definitief'],
				rule: $this->rule([])
			)
		);

	}//end testARecordOfAnotherTypeDoesNotPublish()

	public function testTheRecordTypeMayBeNamedByRecordType(): void {
		$this->assertTrue(
			$this->service->publishes(record: ['recordType' => 'besluit'], rule: $this->rule([]))
		);

	}//end testTheRecordTypeMayBeNamedByRecordType()

	public function testAConditionThatIsNotAMapRefuses(): void {
		$this->expectException(UnreadableRuleException::class);
		$this->expectExceptionMessage('This rule carries a condition that cannot be read.');

		$this->service->publishes(record: ['@type' => 'besluit'], rule: $this->rule(['status equals definitief']));

	}//end testAConditionThatIsNotAMapRefuses()

	public function testAnAnonymousSetThatIsNotAListShowsNothing(): void {
		$rule = $this->rule([]);
		$rule['anonymousProperties'] = 'title';

		$projected = $this->service->projectForAnonymous(record: ['@type' => 'besluit', 'title' => 'Kapvergunning'], rule: $rule);

		$this->assertArrayNotHasKey('title', $projected);

	}//end testAnAnonymousSetThatIsNotAListShowsNothing()

	public function testABlankSearchReturnsEveryPublishedView(): void {
		$rules = $this->service->indexByRecordType(rules: [$this->rule([])]);
		$records = [
			['@type' => 'besluit', 'title' => 'Eerste'],
			['@type' => 'besluit', 'title' => 'Tweede'],
		];

		$views = $this->service->searchPublic(records: $records, rulesByType: $rules, terms: '   ');

		$this->assertCount(2, $views);
		$this->assertSame('Eerste', $views[0]['title']);
		$this->assertSame('Tweede', $views[1]['title']);

	}//end testABlankSearchReturnsEveryPublishedView()

	public function testRulesWithoutARecordTypeAreLeftOutOfTheIndex(): void {
		$indexed = $this->service->indexByRecordType(
			rules: [
				['recordType' => '  ', 'enabled' => true],
				['enabled' => true],
				['recordType' => ' besluit ', 'enabled' => true],
			]
		);

		$this->assertSame(['besluit'], array_keys($indexed));

	}//end testRulesWithoutARecordTypeAreLeftOutOfTheIndex()
}//end class
