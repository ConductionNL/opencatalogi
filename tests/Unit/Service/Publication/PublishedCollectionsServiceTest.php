<?php

declare(strict_types=1);

namespace Unit\Service\Publication;

use OCA\OpenCatalogi\Service\Publication\PublicationRuleService;
use OCA\OpenCatalogi\Service\Publication\PublishedCollectionsService;
use OCA\OpenCatalogi\Service\Publication\UnreadableRuleException;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PublishedCollectionsService.
 *
 * @covers \OCA\OpenCatalogi\Service\Publication\PublishedCollectionsService
 */
class PublishedCollectionsServiceTest extends TestCase {

	private MockObject&IAppConfig $config;
	private PublishedCollectionsService $service;

	/**
	 * The value the fake configuration currently holds.
	 *
	 * @var string
	 */
	private string $stored = '';

	protected function setUp(): void {
		$this->config = $this->createMock(IAppConfig::class);
		$this->config->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') {
				return ($key === PublishedCollectionsService::CONFIG_KEY ? $this->stored : $default);
			}
		);
		$this->config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				if ($key === PublishedCollectionsService::CONFIG_KEY) {
					$this->stored = $value;
				}

				return true;
			}
		);

		$this->service = new PublishedCollectionsService($this->config, new PublicationRuleService(), 'opencatalogi');

	}//end setUp()

	/**
	 * A collection is added on a running instance, and its records publish from
	 * that moment: the configuration is read on every call, not compiled in.
	 */
	public function testACollectionIsAddedWithoutARelease(): void {
		$record = ['@type' => 'besluit', 'status' => 'definitief'];

		$this->assertFalse($this->service->publishes(record: $record));

		$outcome = $this->service->save(
			collections: [
				[
					'recordType' => 'besluit',
					'anonymousProperties' => ['title'],
					'conditions' => [['property' => 'status', 'operator' => 'equals', 'value' => 'definitief']],
				],
			]
		);

		$this->assertTrue($outcome['saved']);
		$this->assertTrue($this->service->publishes(record: $record));

	}//end testACollectionIsAddedWithoutARelease()

	public function testAConditionNarrowsWhatPublishes(): void {
		$this->service->save(
			collections: [
				[
					'recordType' => 'besluit',
					'anonymousProperties' => ['title'],
					'conditions' => [['property' => 'status', 'operator' => 'equals', 'value' => 'definitief']],
				],
			]
		);

		$this->assertFalse($this->service->publishes(record: ['@type' => 'besluit', 'status' => 'concept']));

	}//end testAConditionNarrowsWhatPublishes()

	public function testACollectionWithAnUnknownOperatorIsRefusedAtSave(): void {
		$outcome = $this->service->save(
			collections: [
				[
					'recordType' => 'besluit',
					'anonymousProperties' => ['title'],
					'conditions' => [['property' => 'status', 'operator' => 'sortOfLike', 'value' => 'x']],
				],
			]
		);

		$this->assertFalse($outcome['saved']);
		$this->assertNotEmpty($outcome['errors']);
		$this->assertSame('', $this->stored);

	}//end testACollectionWithAnUnknownOperatorIsRefusedAtSave()

	/**
	 * An unreadable configuration refuses. Read as "publish nothing" it
	 * silently stops a statutory publication; read as "publish everything" it
	 * publishes what nobody approved.
	 */
	public function testAnUnreadableConfigurationRefusesRatherThanDefaulting(): void {
		$this->stored = 'this is not json';

		$this->expectException(UnreadableRuleException::class);

		$this->service->collections();

	}//end testAnUnreadableConfigurationRefusesRatherThanDefaulting()

	public function testAnUnsetConfigurationIsAnEmptySetAndNotAnError(): void {
		$this->assertSame([], $this->service->collections());

	}//end testAnUnsetConfigurationIsAnEmptySetAndNotAnError()
}//end class
