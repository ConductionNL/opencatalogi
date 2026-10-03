<?php

declare(strict_types=1);

namespace Unit\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenCatalogi\Service\CaseTypeCatalogueService;
use OCA\OpenCatalogi\Service\Catalogue\CaseTypeSourceReader;
use OCA\OpenCatalogi\Service\Catalogue\ExternalCatalogueUnreachableException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CaseTypeCatalogueService.
 *
 * @covers \OCA\OpenCatalogi\Service\CaseTypeCatalogueService
 */
class CaseTypeCatalogueServiceTest extends TestCase {

	private MockObject&CaseTypeSourceReader $reader;
	private CaseTypeCatalogueService $service;

	protected function setUp(): void {
		// onlyMethods against the interface: a double cannot invent a method the
		// real reader lacks, so a test cannot pass on a call production makes.
		$this->reader = $this->getMockBuilder(CaseTypeSourceReader::class)
			->onlyMethods(['fetchDefinition', 'listDefinitions'])
			->getMock();
		$this->service = new CaseTypeCatalogueService($this->reader);

	}//end setUp()

	public function testAnImportRecordsItsSourceVersionAndMoment(): void {
		$this->reader->method('fetchDefinition')->willReturn(
			[
				'identifier' => 'verhuizing',
				'title' => 'Verhuizing doorgeven',
				'description' => 'Geef uw verhuizing door',
				'inspectionTermDays' => 42,
				'version' => '3.1.0',
			]
		);

		$definition = $this->service->importDefinition(
			sourceId: 'i-navigator',
			externalId: 'verhuizing',
			now: new DateTimeImmutable('2026-09-18T10:00:00+00:00', new DateTimeZone('UTC'))
		);

		$this->assertSame('i-navigator', $definition['source']['sourceId']);
		$this->assertSame('3.1.0', $definition['source']['version']);
		$this->assertSame('2026-09-18T10:00:00+00:00', $definition['source']['importedAt']);
		$this->assertSame('Verhuizing doorgeven', $definition['importedSnapshot']['title']);

	}//end testAnImportRecordsItsSourceVersionAndMoment()

	/**
	 * An unreachable source raises. Reporting "no definitions" when the truth
	 * is "we could not ask" sends the administrator to the wrong system.
	 */
	public function testAnUnreachableSourceRaisesRatherThanReturningNothing(): void {
		$this->reader->method('fetchDefinition')->willThrowException(
			new ExternalCatalogueUnreachableException('the gateway is not installed')
		);

		$this->expectException(ExternalCatalogueUnreachableException::class);

		$this->service->importDefinition(sourceId: 'i-navigator', externalId: 'verhuizing');

	}//end testAnUnreachableSourceRaisesRatherThanReturningNothing()

	/**
	 * The local definition after an import, with one property edited locally.
	 *
	 * @return array<string, mixed>
	 */
	private function localDefinition(): array {
		return [
			'identifier' => 'verhuizing',
			'title' => 'Verhuizing doorgeven (gemeente Zuiderdorp)',
			'description' => 'Geef uw verhuizing door',
			'inspectionTermDays' => 42,
			'formUrl' => 'https://zuiderdorp.nl/verhuizing',
			'source' => [
				'sourceId' => 'i-navigator',
				'externalId' => 'verhuizing',
				'version' => '3.1.0',
				'importedAt' => '2026-09-01T10:00:00+00:00',
			],
			'importedSnapshot' => [
				'identifier' => 'verhuizing',
				'title' => 'Verhuizing doorgeven',
				'description' => 'Geef uw verhuizing door',
				'inspectionTermDays' => 42,
			],
		];

	}//end localDefinition()

	public function testTheDiffFlagsAPropertyChangedLocally(): void {
		$this->reader->method('fetchDefinition')->willReturn(
			[
				'identifier' => 'verhuizing',
				'title' => 'Verhuizing doorgeven',
				'description' => 'Geef uw verhuizing tijdig door',
				'inspectionTermDays' => 42,
				'version' => '3.2.0',
			]
		);

		$diff = $this->service->diffAgainstSource(
			local: $this->localDefinition(),
			sourceId: 'i-navigator',
			externalId: 'verhuizing'
		);

		$this->assertSame('3.2.0', $diff['sourceVersion']);
		$this->assertSame(['title'], $diff['locallyChanged']);

		$byProperty = array_column($diff['changes'], null, 'property');
		$this->assertTrue($byProperty['title']['locallyChanged']);
		$this->assertFalse($byProperty['description']['locallyChanged']);
		$this->assertSame('Geef uw verhuizing tijdig door', $byProperty['description']['sourceValue']);

	}//end testTheDiffFlagsAPropertyChangedLocally()

	/**
	 * Nothing applies until it is accepted. A preview that quietly wrote would
	 * change a running configuration from outside the organisation, which is
	 * the one thing the inrichtingscheck exists to prevent.
	 */
	public function testNothingIsAppliedWhenNothingIsAccepted(): void {
		$this->reader->method('fetchDefinition')->willReturn(
			['identifier' => 'verhuizing', 'title' => 'Iets anders', 'description' => 'Ook anders', 'version' => '3.2.0']
		);

		$local = $this->localDefinition();
		$diff = $this->service->diffAgainstSource(local: $local, sourceId: 'i-navigator', externalId: 'verhuizing');

		$unchanged = $this->service->applyResync(local: $local, diff: $diff, accepted: []);

		$this->assertSame($local, $unchanged);

	}//end testNothingIsAppliedWhenNothingIsAccepted()

	public function testOnlyTheAcceptedPropertiesAreApplied(): void {
		$this->reader->method('fetchDefinition')->willReturn(
			[
				'identifier' => 'verhuizing',
				'title' => 'Verhuizing doorgeven',
				'description' => 'Geef uw verhuizing tijdig door',
				'inspectionTermDays' => 42,
				'version' => '3.2.0',
			]
		);

		$local = $this->localDefinition();
		$diff = $this->service->diffAgainstSource(local: $local, sourceId: 'i-navigator', externalId: 'verhuizing');

		$updated = $this->service->applyResync(
			local: $local,
			diff: $diff,
			accepted: ['description'],
			now: new DateTimeImmutable('2026-09-18T12:00:00+00:00', new DateTimeZone('UTC'))
		);

		$this->assertSame('Geef uw verhuizing tijdig door', $updated['description']);
		$this->assertSame('Verhuizing doorgeven (gemeente Zuiderdorp)', $updated['title']);
		$this->assertSame('Geef uw verhuizing tijdig door', $updated['importedSnapshot']['description']);
		$this->assertSame('Verhuizing doorgeven', $updated['importedSnapshot']['title']);
		$this->assertSame('3.2.0', $updated['source']['version']);
		$this->assertSame('2026-09-18T12:00:00+00:00', $updated['source']['importedAt']);
		$this->assertSame('https://zuiderdorp.nl/verhuizing', $updated['formUrl']);

	}//end testOnlyTheAcceptedPropertiesAreApplied()

	public function testAPublishedCaseTypeLinksToItsFormAndItsApiDescription(): void {
		$links = $this->service->publishedLinks(
			definition: [
				'formUrl' => 'https://zuiderdorp.nl/verhuizing',
				'apiDescriptionUrl' => 'https://zuiderdorp.nl/api/openapi.json',
			]
		);

		$this->assertSame('https://zuiderdorp.nl/verhuizing', $links['form']);
		$this->assertSame('https://zuiderdorp.nl/api/openapi.json', $links['apiDescription']);
		$this->assertTrue($links['complete']);

	}//end testAPublishedCaseTypeLinksToItsFormAndItsApiDescription()

	public function testAMissingLinkIsNullRatherThanAnEmptyString(): void {
		$links = $this->service->publishedLinks(definition: ['formUrl' => '  ']);

		$this->assertNull($links['form']);
		$this->assertNull($links['apiDescription']);
		$this->assertFalse($links['complete']);

	}//end testAMissingLinkIsNullRatherThanAnEmptyString()

	public function testTheSyncDateIsShownAndIsNullWhenNeverImported(): void {
		$this->assertSame('2026-09-01T10:00:00+00:00', $this->service->syncedAt(definition: $this->localDefinition()));
		$this->assertNull($this->service->syncedAt(definition: ['identifier' => 'lokaal']));

	}//end testTheSyncDateIsShownAndIsNullWhenNeverImported()
}//end class
