<?php

declare(strict_types=1);

namespace Unit\Service\Publication;

use OCA\OpenCatalogi\Service\Publication\PublicationRuleService;
use OCA\OpenCatalogi\Service\Publication\UnreadableRuleException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PublicationRuleService.
 *
 * @covers \OCA\OpenCatalogi\Service\Publication\PublicationRuleService
 */
class PublicationRuleServiceTest extends TestCase {

	private PublicationRuleService $service;

	protected function setUp(): void {
		$this->service = new PublicationRuleService();

	}//end setUp()

	/**
	 * A rule that publishes a besluit once it is definitive.
	 *
	 * @return array<string, mixed>
	 */
	private function rule(): array {
		return [
			'recordType' => 'besluit',
			'enabled' => true,
			'anonymousProperties' => ['title', 'publicationDate'],
			'conditions' => [
				['property' => 'status', 'operator' => 'equals', 'value' => 'definitief'],
			],
			'publicationText' => 'Tegen dit besluit kunt u bezwaar maken.',
		];

	}//end rule()

	public function testARecordOfAPublishedTypePublishesWithoutAnyonePickingIt(): void {
		$record = ['@type' => 'besluit', 'status' => 'definitief', 'title' => 'Kapvergunning'];

		$this->assertTrue($this->service->publishes(record: $record, rule: $this->rule()));

	}//end testARecordOfAPublishedTypePublishesWithoutAnyonePickingIt()

	public function testARecordThatFailsAConditionDoesNotPublish(): void {
		$record = ['@type' => 'besluit', 'status' => 'concept', 'title' => 'Kapvergunning'];

		$this->assertFalse($this->service->publishes(record: $record, rule: $this->rule()));

	}//end testARecordThatFailsAConditionDoesNotPublish()

	public function testADisabledRulePublishesNothing(): void {
		$rule = $this->rule();
		$rule['enabled'] = false;

		$this->assertFalse(
			$this->service->publishes(record: ['@type' => 'besluit', 'status' => 'definitief'], rule: $rule)
		);

	}//end testADisabledRulePublishesNothing()

	/**
	 * The failure that matters most here. A property outside the anonymous set
	 * must be absent from the response, not present and empty: a property with
	 * an empty value still tells the reader the record carries it.
	 */
	public function testAPropertyOutsideTheSetIsAbsentAndNotBlanked(): void {
		$record = [
			'@type' => 'besluit',
			'status' => 'definitief',
			'title' => 'Kapvergunning',
			'publicationDate' => '2026-09-18',
			'aanvrager' => 'J. de Vries',
			'bsn' => '123456789',
		];

		$projected = $this->service->projectForAnonymous(record: $record, rule: $this->rule());

		$this->assertArrayNotHasKey('aanvrager', $projected);
		$this->assertArrayNotHasKey('bsn', $projected);
		$this->assertArrayNotHasKey('status', $projected);
		$this->assertSame('Kapvergunning', $projected['title']);

		// Nothing that was withheld survives anywhere in the serialised answer.
		$serialised = (string)json_encode($projected);
		$this->assertStringNotContainsString('123456789', $serialised);
		$this->assertStringNotContainsString('J. de Vries', $serialised);

	}//end testAPropertyOutsideTheSetIsAbsentAndNotBlanked()

	public function testTheTypesPublicationTextIsPublishedWithTheRecord(): void {
		$projected = $this->service->projectForAnonymous(
			record: ['@type' => 'besluit', 'title' => 'Kapvergunning'],
			rule: $this->rule()
		);

		$this->assertSame('Tegen dit besluit kunt u bezwaar maken.', $projected['publicationText']);

	}//end testTheTypesPublicationTextIsPublishedWithTheRecord()

	public function testAPreviewShowsTheRecordsAndThePropertiesBeforeTheRuleIsSaved(): void {
		$draft = $this->rule();
		$draft['enabled'] = false;

		$preview = $this->service->preview(
			rule: $draft,
			sample: [
				['@type' => 'besluit', 'status' => 'definitief', 'title' => 'Een', 'publicationDate' => '2026-01-01', 'bsn' => '1'],
				['@type' => 'besluit', 'status' => 'concept', 'title' => 'Twee'],
			]
		);

		// A preview that answered "nothing" for a draft rule would be a check
		// that cannot see the thing it judges.
		$this->assertTrue($preview['valid']);
		$this->assertCount(1, $preview['wouldPublish']);
		$this->assertSame(1, $preview['wouldNotPublish']);
		$this->assertSame(['publicationDate', 'publicationText', 'title'], $preview['exposedProperties']);

	}//end testAPreviewShowsTheRecordsAndThePropertiesBeforeTheRuleIsSaved()

	public function testARuleWithoutARecordTypeIsRefused(): void {
		$validation = $this->service->validateRule(rule: ['anonymousProperties' => ['title']]);

		$this->assertFalse($validation['valid']);
		$this->assertNotEmpty($validation['errors']);

	}//end testARuleWithoutARecordTypeIsRefused()

	/**
	 * An operator nobody can evaluate refuses. Read as "matches" it publishes
	 * what nobody approved; read as "does not match" it silently withholds what
	 * the law requires. Neither is a default worth having.
	 */
	public function testAnUnknownOperatorRefusesRatherThanGuessing(): void {
		$rule = $this->rule();
		$rule['conditions'] = [['property' => 'status', 'operator' => 'sortOfLike', 'value' => 'x']];

		$this->expectException(UnreadableRuleException::class);

		$this->service->publishes(record: ['@type' => 'besluit', 'status' => 'definitief'], rule: $rule);

	}//end testAnUnknownOperatorRefusesRatherThanGuessing()

	public function testADateComparisonThatCannotBeReadRefuses(): void {
		$rule = $this->rule();
		$rule['conditions'] = [['property' => 'publicationDate', 'operator' => 'before', 'value' => 'volgende week']];

		$this->expectException(UnreadableRuleException::class);

		$this->service->publishes(
			record: ['@type' => 'besluit', 'publicationDate' => '2026-01-01'],
			rule: $rule
		);

	}//end testADateComparisonThatCannotBeReadRefuses()

	public function testTheSearchRunsOverTheProjectionsAndNotTheRecords(): void {
		$records = [
			[
				'@type' => 'besluit',
				'status' => 'definitief',
				'title' => 'Kapvergunning Dorpsstraat',
				'publicationDate' => '2026-01-01',
				'aanvrager' => 'Zeldzaamwoord',
				'dossier' => 'dossier-1',
			],
		];
		$rules = $this->service->indexByRecordType(rules: [$this->rule()]);

		// A word that occurs only in a withheld property must not return the
		// record: returning it publishes the property by telling the reader the
		// record contains it.
		$this->assertSame([], $this->service->searchPublic(records: $records, rulesByType: $rules, terms: 'Zeldzaamwoord'));

		$found = $this->service->searchPublic(records: $records, rulesByType: $rules, terms: 'Dorpsstraat');
		$this->assertCount(1, $found);
		$this->assertSame('dossier-1', $found[0]['dossier']);

	}//end testTheSearchRunsOverTheProjectionsAndNotTheRecords()

	public function testAnUnpublishedRecordNeverReachesTheSearch(): void {
		$records = [
			['@type' => 'besluit', 'status' => 'concept', 'title' => 'Dorpsstraat', 'dossier' => 'dossier-1'],
			['@type' => 'notitie', 'title' => 'Dorpsstraat', 'dossier' => 'dossier-2'],
		];

		$results = $this->service->searchPublic(
			records: $records,
			rulesByType: $this->service->indexByRecordType(rules: [$this->rule()]),
			terms: 'Dorpsstraat'
		);

		$this->assertSame([], $results);

	}//end testAnUnpublishedRecordNeverReachesTheSearch()
}//end class
