<?php

/**
 * Each failure path REQ-WNO-004 names raises a pipeline incident, driven through its real caller.
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
 */

declare(strict_types=1);

namespace Unit\Service\Woo;

use OCA\OpenCatalogi\BackgroundJob\DiwooSitemapReconciliation;
use OCA\OpenCatalogi\Service\DirectoryService;
use OCA\OpenCatalogi\Service\Publication\IndexUnreachableException;
use OCA\OpenCatalogi\Service\Publication\NationalIndexService;
use OCA\OpenCatalogi\Service\Publication\PlooiDeliveryService;
use OCA\OpenCatalogi\Service\SettingsService;
use OCA\OpenCatalogi\Service\SitemapService;
use OCA\OpenCatalogi\Service\SitemapStandards;
use OCA\OpenCatalogi\Service\StandardsVersionService;
use OCA\OpenCatalogi\Service\Woo\PipelineIncidents;
use OCA\OpenCatalogi\Service\WooReadinessService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * One case per failure path. The other two paths are driven the same way in their
 * own tests: the reconciliation in DiwooSitemapReconciliationTest::testADocumentMissingFromTheSitemapIsReported,
 * the standard check in DiwooStandardCheckTest::testANewerVersionFailsReadiness, a failed
 * publishBatch() in WooServiceTest::testAFailedPublishRaisesAPipelineIncident and the
 * sitemap in SitemapServiceTest::testARefusedSitemapRaisesAndAnUnknownNameDoesNot.
 *
 * @spec openspec/changes/woo-national-output-assurance/specs/woo-compliance/spec.md#requirement-a-pipeline-failure-reaches-a-named-group-req-wno-004
 */
#[CoversClass(NationalIndexService::class)]
#[CoversClass(PlooiDeliveryService::class)]
#[CoversClass(WooReadinessService::class)]
#[UsesClass(PipelineIncidents::class)]
#[UsesClass(IndexUnreachableException::class)]
#[UsesClass(SitemapStandards::class)]
#[UsesClass(DiwooSitemapReconciliation::class)]
#[UsesClass(\OCA\OpenCatalogi\Service\CatalogReadinessChecks::class)]
class PipelineIncidentsWiringTest extends TestCase {

	/**
	 * The incidents raised, as [kind, subject, message].
	 *
	 * @var list<array{0: string, 1: string, 2: string}>
	 */
	private array $raised = [];

	/**
	 * An incident double that records what is raised.
	 *
	 * @return PipelineIncidents
	 */
	private function incidents(): PipelineIncidents {
		$incidents = $this->createMock(PipelineIncidents::class);
		$incidents->method('raise')->willReturnCallback(
			function (string $kind, string $subject, string $message): void {
				$this->raised[] = [$kind, $subject, $message];
			}
		);

		return $incidents;
	}

	/**
	 * A national index with no channel source configured: every hand-over fails before a call.
	 *
	 * @return NationalIndexService
	 */
	private function nationalIndexWithoutSources(): NationalIndexService {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new \RuntimeException('not installed'));
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => $default);

		return new NationalIndexService(
			container: $container,
			config: $config,
			dispatcher: $this->createMock(IEventDispatcher::class),
			logger: $this->createMock(LoggerInterface::class),
			incidents: $this->incidents()
		);
	}

	public function testAFailedNationalHandOverRaises(): void {
		try {
			$this->nationalIndexWithoutSources()->deliver(notice: ['channel' => NationalIndexService::CHANNEL_WOO_INDEX, 'publication' => 'pub-1']);
			$this->fail('A hand-over without a source must not be reported as delivered.');
		} catch (IndexUnreachableException $e) {
			$this->assertCount(1, $this->raised);
			$this->assertSame(['national-delivery', NationalIndexService::CHANNEL_WOO_INDEX], array_slice($this->raised[0], 0, 2));
			$this->assertStringContainsString($e->getMessage(), $this->raised[0][2]);
		}
	}

	public function testAFailedDeliveryToPlooiRaises(): void {
		try {
			$this->nationalIndexWithoutSources()->deliverToPlooi(document: ['identifier' => 'pub-1', 'title' => 'Besluit', 'documents' => []]);
			$this->fail('A PLOOI delivery without a source must not be reported as delivered.');
		} catch (IndexUnreachableException $e) {
			$this->assertSame(
				[[PipelineIncidents::KIND_PLOOI, 'pub-1', "PLOOI delivery of 'Besluit' failed: " . $e->getMessage()]],
				$this->raised
			);
		}
	}

	/**
	 * A failed delivery raises once from the delivery service with the same kind, subject and
	 * message as the channel would, so the two coalesce into one incident.
	 */
	public function testAFailedPlooiDeliveryRaisesAndPublishingGoesOn(): void {
		$objects = $this->getMockBuilder(ObjectService::class)
			->disableOriginalConstructor()
			->onlyMethods(['find', 'saveObject', 'searchObjectsPaginated'])
			->getMock();
		$publication = new ObjectEntity();
		$publication->setUuid('pub-1');
		$publication->setObject(['title' => 'Besluit', 'summary' => 'Kort']);
		$objects->method('find')->willReturn($publication);
		$objects->method('searchObjectsPaginated')->willReturn(['results' => [['registers' => ['7'], 'schemas' => ['12'], 'plooiDelivery' => true]]]);
		$saved = null;
		$objects->method('saveObject')->willReturnCallback(
			static function (array $object) use (&$saved, $publication): ObjectEntity {
				$saved = $object;
				return $publication;
			}
		);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getSettings')->willReturn(['configuration' => ['catalog_register' => '7', 'catalog_schema' => '3']]);
		$index = $this->getMockBuilder(NationalIndexService::class)->disableOriginalConstructor()->onlyMethods(['deliverToPlooi'])->getMock();
		$index->method('deliverToPlooi')->willThrowException(new IndexUnreachableException('The channel "plooi" answered HTTP 503.'));

		$service = new PlooiDeliveryService(settingsService: $settings, indexService: $index, logger: $this->createMock(LoggerInterface::class), incidents: $this->incidents());
		$result = $service->deliver(uuid: 'pub-1', register: '7', schema: '12');

		$this->assertSame('failed', $result['plooiStatus']);
		$this->assertSame('failed', $saved['plooiStatus']);
		$this->assertSame(
			[[PipelineIncidents::KIND_PLOOI, 'pub-1', PipelineIncidents::plooiFailure(title: 'Besluit', reason: 'The channel "plooi" answered HTTP 503.')]],
			$this->raised
		);
	}

	public function testAFailingReadinessCheckRaises(): void {
		$client = $this->createMock(IClient::class);
		$client->method('get')->willThrowException(new \RuntimeException('connection refused'));
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getSettings')->willReturn(['configuration' => []]);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getBaseUrl')->willReturn('https://example.org');
		$versions = $this->createMock(StandardsVersionService::class);
		$versions->method('diwooVersion')->willReturn(['declared' => '0.9.8', 'published' => '0.9.8', 'status' => StandardsVersionService::STATUS_CURRENT]);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => $default);

		$service = new WooReadinessService(
			$clients,
			$this->createMock(DirectoryService::class),
			new SitemapStandards($this->createMock(SitemapService::class), $versions),
			$settings,
			$config,
			$urls,
			incidents: $this->incidents()
		);
		$report = $service->runCheck();

		$this->assertSame('not-ready', $report['verdict']);
		$this->assertCount(1, $this->raised);
		$this->assertSame(['readiness', 'https://example.org'], array_slice($this->raised[0], 0, 2));
		$this->assertStringStartsWith('The Woo readiness check failed: robots-txt: ', $this->raised[0][2]);
	}
}
