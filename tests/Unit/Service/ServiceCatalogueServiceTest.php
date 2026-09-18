<?php

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\Catalogue\CatalogueUnreadableException;
use OCA\OpenCatalogi\Service\ServiceCatalogueService;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for ServiceCatalogueService.
 *
 * @covers \OCA\OpenCatalogi\Service\ServiceCatalogueService
 */
class ServiceCatalogueServiceTest extends TestCase {

	private MockObject&ContainerInterface $container;
	private MockObject&LoggerInterface $logger;
	private ServiceCatalogueService $service;

	protected function setUp(): void {
		$this->container = $this->createMock(ContainerInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->service = new ServiceCatalogueService($this->container, $this->logger);

	}//end setUp()

	/**
	 * One published case type, used by most of the bindings below.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function caseTypes(): array {
		return [
			['identifier' => 'verhuizing', 'title' => 'Verhuizing doorgeven', 'published' => true],
			['identifier' => 'kapvergunning', 'title' => 'Kapvergunning', 'published' => false],
		];

	}//end caseTypes()

	public function testAnEntryWithAResolvableBindingIsAvailable(): void {
		$entry = [
			'title' => 'Verhuizing doorgeven',
			'formBinding' => ['caseType' => 'verhuizing', 'audience' => 'resident', 'formName' => 'verhuizing-form'],
		];

		$decided = $this->service->decideAvailability(
			entry: $entry,
			caseTypesByIdentifier: $this->service->indexCaseTypes(caseTypes: $this->caseTypes())
		);

		$this->assertTrue($decided['available']);
		$this->assertArrayNotHasKey('unavailableReason', $decided);

	}//end testAnEntryWithAResolvableBindingIsAvailable()

	public function testAnEntryWithNoBindingIsListedAsUnavailable(): void {
		$decided = $this->service->decideAvailability(
			entry: ['title' => 'Iets aanvragen'],
			caseTypesByIdentifier: $this->service->indexCaseTypes(caseTypes: $this->caseTypes())
		);

		$this->assertFalse($decided['available']);
		$this->assertSame(ServiceCatalogueService::REASON_NO_BINDING, $decided['unavailableReason']);

	}//end testAnEntryWithNoBindingIsListedAsUnavailable()

	public function testAnIncompleteBindingIsNamedAsSuch(): void {
		$decided = $this->service->decideAvailability(
			entry: ['title' => 'Iets', 'formBinding' => ['caseType' => 'verhuizing', 'audience' => '', 'formName' => 'f']],
			caseTypesByIdentifier: $this->service->indexCaseTypes(caseTypes: $this->caseTypes())
		);

		$this->assertSame(ServiceCatalogueService::REASON_INCOMPLETE_BINDING, $decided['unavailableReason']);

	}//end testAnIncompleteBindingIsNamedAsSuch()

	public function testAnUnknownCaseTypeIsNamedAsSuch(): void {
		$decided = $this->service->decideAvailability(
			entry: ['title' => 'Iets', 'formBinding' => ['caseType' => 'niets', 'audience' => 'resident', 'formName' => 'f']],
			caseTypesByIdentifier: $this->service->indexCaseTypes(caseTypes: $this->caseTypes())
		);

		$this->assertSame(ServiceCatalogueService::REASON_UNKNOWN_CASE_TYPE, $decided['unavailableReason']);

	}//end testAnUnknownCaseTypeIsNamedAsSuch()

	public function testAnUnpublishedCaseTypeIsNamedAsSuch(): void {
		$decided = $this->service->decideAvailability(
			entry: ['title' => 'Kap', 'formBinding' => ['caseType' => 'kapvergunning', 'audience' => 'resident', 'formName' => 'f']],
			caseTypesByIdentifier: $this->service->indexCaseTypes(caseTypes: $this->caseTypes())
		);

		$this->assertSame(ServiceCatalogueService::REASON_UNPUBLISHED_CASE_TYPE, $decided['unavailableReason']);

	}//end testAnUnpublishedCaseTypeIsNamedAsSuch()

	/**
	 * The failure this guards: an unresolvable entry silently dropped from the
	 * public list. The obligation to publish it does not go away because the
	 * form behind it broke.
	 */
	public function testAnUnavailableEntryIsStillListed(): void {
		$entries = [
			['title' => 'Werkt', 'group' => 'Wonen', 'formBinding' => ['caseType' => 'verhuizing', 'audience' => 'resident', 'formName' => 'f']],
			['title' => 'Kapot', 'group' => 'Wonen', 'formBinding' => ['caseType' => 'weg', 'audience' => 'resident', 'formName' => 'f']],
		];

		$assembled = $this->service->assemble(entries: $entries, caseTypes: $this->caseTypes());

		$this->assertCount(2, $assembled['entries']);
		$this->assertCount(2, $assembled['groups']['Wonen']);
		$this->assertSame(1, $assembled['unavailable']);
		$this->assertSame('Kapot', $assembled['entries'][1]['title']);

	}//end testAnUnavailableEntryIsStillListed()

	public function testEntriesWithoutAGroupLandInTheUngroupedGroup(): void {
		$assembled = $this->service->assemble(
			entries: [['title' => 'Los', 'formBinding' => ['caseType' => 'verhuizing', 'audience' => 'r', 'formName' => 'f']]],
			caseTypes: $this->caseTypes()
		);

		$this->assertArrayHasKey(ServiceCatalogueService::UNGROUPED, $assembled['groups']);

	}//end testEntriesWithoutAGroupLandInTheUngroupedGroup()

	public function testTheAdministratorsListNamesEveryUnavailableEntry(): void {
		$entries = [
			['title' => 'Werkt', 'formBinding' => ['caseType' => 'verhuizing', 'audience' => 'r', 'formName' => 'f']],
			['title' => 'Geen binding'],
			['title' => 'Onbekend type', 'formBinding' => ['caseType' => 'weg', 'audience' => 'r', 'formName' => 'f']],
		];

		$unavailable = $this->service->unavailableEntries(entries: $entries, caseTypes: $this->caseTypes());

		$this->assertCount(2, $unavailable);
		$this->assertSame(['Geen binding', 'Onbekend type'], array_column($unavailable, 'title'));

	}//end testTheAdministratorsListNamesEveryUnavailableEntry()

	/**
	 * An unreadable catalogue refuses. The failure this guards is the mirror of
	 * publishing too much: reporting "no services" to a resident when the truth
	 * is that this app could not read its own register.
	 */
	public function testAnUnreadableCatalogueRefusesRatherThanAnsweringEmpty(): void {
		$this->container->method('get')->willThrowException(new \RuntimeException('OpenRegister is not installed'));

		$this->expectException(CatalogueUnreadableException::class);

		$this->service->readCatalogue(
			entryConfig: ['register' => '1', 'schema' => '2'],
			caseTypeConfig: ['register' => '1', 'schema' => '3']
		);

	}//end testAnUnreadableCatalogueRefusesRatherThanAnsweringEmpty()

	public function testReadCatalogueDecoratesWhatOpenRegisterReturns(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('searchObjectsPaginated')->willReturnOnConsecutiveCalls(
			[
				'results' => [
					['id' => 'e1', 'object' => ['title' => 'Verhuizing', 'group' => 'Wonen', 'formBinding' => ['caseType' => 'verhuizing', 'audience' => 'r', 'formName' => 'f']]],
				],
				'total' => 1,
			],
			[
				'results' => [['id' => 'c1', 'object' => ['identifier' => 'verhuizing', 'published' => true]]],
				'total' => 1,
			]
		);

		$this->service->setObjectService($objectService);

		$catalogue = $this->service->readCatalogue(
			entryConfig: ['register' => '1', 'schema' => '2'],
			caseTypeConfig: ['register' => '1', 'schema' => '3']
		);

		$this->assertSame(1, $catalogue['total']);
		$this->assertTrue($catalogue['entries'][0]['available']);
		$this->assertSame('e1', $catalogue['entries'][0]['id']);

	}//end testReadCatalogueDecoratesWhatOpenRegisterReturns()
}//end class
