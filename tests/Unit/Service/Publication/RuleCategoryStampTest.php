<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service\Publication;

use OCA\OpenCatalogi\Contract\PublishedCollectionsServiceInterface;
use OCA\OpenCatalogi\Service\Publication\PublicationRuleService;
use OCA\OpenCatalogi\Service\Publication\RuleCategoryStamp;
use OCA\OpenCatalogi\Service\Publication\UnreadableRuleException;
use PHPUnit\Framework\TestCase;

/**
 * Which category a published collection files a record under (REQ-CVS-003).
 *
 * @covers \OCA\OpenCatalogi\Service\Publication\RuleCategoryStamp
 * @uses \OCA\OpenCatalogi\Service\Publication\PublicationRuleService
 *
 * @spec openspec/changes/capability-rows-verify-scenarios/specs/woo-compliance/spec.md#requirement-a-publication-rule-names-the-information-category-its-records-are-filed-under-req-cvs-003
 */
class RuleCategoryStampTest extends TestCase {

	/**
	 * The stamp over the given collections and the real rule evaluator.
	 *
	 * @param array<int, array<string, mixed>> $collections The configured collections.
	 *
	 * @return RuleCategoryStamp The stamp.
	 */
	private function stamp(array $collections): RuleCategoryStamp {
		$configured = $this->createMock(PublishedCollectionsServiceInterface::class);
		$configured->method('collections')->willReturn($collections);

		return new RuleCategoryStamp(collections: $configured, rules: new PublicationRuleService());
	}//end stamp()

	/**
	 * The council decision rule of the scenario.
	 *
	 * @return array<string, mixed> The collection.
	 */
	private function councilDecisions(): array {
		return [
			'register' => 'publication',
			'recordType' => 'raadsbesluit',
			'wooCategory' => 'infocat004',
			'anonymousProperties' => ['title'],
			'conditions' => [['property' => 'status', 'operator' => 'equals', 'value' => 'Vastgesteld']],
		];
	}//end councilDecisions()

	/**
	 * A council decision with status "Vastgesteld" is filed under infocat004.
	 */
	public function testAPublishedRecordIsFiledUnderTheRulesCategory(): void {
		$stamp = $this->stamp([$this->councilDecisions()]);

		$this->assertSame(
			'infocat004',
			$stamp->categoryFor(record: ['title' => 'Begroting 2027', 'status' => 'Vastgesteld'], typeNames: ['raadsbesluit'])
		);
	}//end testAPublishedRecordIsFiledUnderTheRulesCategory()

	/**
	 * A record the rule does not publish gets no category.
	 */
	public function testARecordTheRuleDoesNotPublishIsNotFiled(): void {
		$stamp = $this->stamp([$this->councilDecisions()]);

		$this->assertNull($stamp->categoryFor(record: ['status' => 'Concept'], typeNames: ['raadsbesluit']));
	}//end testARecordTheRuleDoesNotPublishIsNotFiled()

	/**
	 * A record of a type no collection governs gets no category.
	 */
	public function testARecordOfAnotherTypeIsNotFiled(): void {
		$stamp = $this->stamp([$this->councilDecisions()]);

		$this->assertNull($stamp->categoryFor(record: ['status' => 'Vastgesteld'], typeNames: ['42', 'vergunning']));
	}//end testARecordOfAnotherTypeIsNotFiled()

	/**
	 * The rule's category replaces another one, as the anonymous view shows it; the same one is not written again.
	 */
	public function testTheRulesCategoryWinsAndTheSameOneIsNotRewritten(): void {
		$stamp = $this->stamp([$this->councilDecisions()]);

		$this->assertSame(
			'infocat004',
			$stamp->categoryFor(record: ['status' => 'Vastgesteld', 'wooCategory' => 'infocat016'], typeNames: ['raadsbesluit'])
		);
		$this->assertNull(
			$stamp->categoryFor(record: ['status' => 'Vastgesteld', 'wooCategory' => 'infocat004'], typeNames: ['raadsbesluit'])
		);
	}//end testTheRulesCategoryWinsAndTheSameOneIsNotRewritten()

	/**
	 * A collection without a served category files nothing, and the next one that has one decides.
	 */
	public function testACollectionWithoutAServedCategoryIsPassedOver(): void {
		$legacy = $this->councilDecisions();
		$legacy['wooCategory'] = 'infocat099';
		$stamp = $this->stamp([$legacy, ['recordType' => 'raadsbesluit', 'wooCategory' => 'infocat002', 'conditions' => []]]);

		$this->assertSame('infocat002', $stamp->categoryFor(record: ['status' => 'Vastgesteld'], typeNames: ['raadsbesluit']));
		$this->assertNull($this->stamp([$legacy])->categoryFor(record: ['status' => 'Vastgesteld'], typeNames: ['raadsbesluit']));
	}//end testACollectionWithoutAServedCategoryIsPassedOver()

	/**
	 * The governed record types are listed once each, trimmed, without empty ones.
	 */
	public function testRecordTypesListEachGovernedTypeOnce(): void {
		$stamp = $this->stamp([
			['recordType' => ' raadsbesluit '],
			['recordType' => 'raadsbesluit'],
			['recordType' => ''],
			['register' => 'x'],
			['recordType' => 'advies'],
		]);

		$this->assertSame(['raadsbesluit', 'advies'], $stamp->recordTypes());
	}//end testRecordTypesListEachGovernedTypeOnce()

	/**
	 * A condition that cannot be read is not guessed at.
	 */
	public function testAnUnreadableConditionIsRaised(): void {
		$broken = $this->councilDecisions();
		$broken['conditions'] = [['property' => 'status', 'operator' => 'sortOf', 'value' => 'x']];

		$this->expectException(UnreadableRuleException::class);
		$this->stamp([$broken])->categoryFor(record: ['status' => 'x'], typeNames: ['raadsbesluit']);
	}//end testAnUnreadableConditionIsRaised()
}//end class
