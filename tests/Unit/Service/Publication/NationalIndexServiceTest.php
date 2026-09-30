<?php

/**
 * Tests for the national hand-over through integriq.
 *
 * The doubles are built on integriq's own classes (`onlyMethods`, never
 * `addMethods`), so a call that does not match integriq's real signature fails
 * here the way it fails in production. In CI integriq is installed and these run
 * against its real classes; locally tests/Stubs/Integriq mirrors them.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Service\Publication
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Service\Publication;

use OCA\Integriq\Event\GatewayDeliveryRequestedEvent;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\ConnectionStore;
use OCA\OpenCatalogi\Service\Publication\IndexUnreachableException;
use OCA\OpenCatalogi\Service\Publication\NationalIndexService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenCatalogi\Service\Publication\NationalIndexService
 */
class NationalIndexServiceTest extends TestCase {

	private MockObject&ContainerInterface $container;

	private MockObject&IAppConfig $config;

	private MockObject&IEventDispatcher $dispatcher;

	private MockObject&CallService $callService;

	private MockObject&ConnectionStore $connectionStore;

	protected function setUp(): void {
		parent::setUp();
		$this->container = $this->createMock(ContainerInterface::class);
		$this->config = $this->createMock(IAppConfig::class);
		$this->dispatcher = $this->createMock(IEventDispatcher::class);
		$this->callService = $this->getMockBuilder(CallService::class)
			->disableOriginalConstructor()
			->onlyMethods(['call'])
			->getMock();
		$this->connectionStore = $this->getMockBuilder(ConnectionStore::class)
			->disableOriginalConstructor()
			->onlyMethods(['findSourceBySlug'])
			->getMock();
		$this->container->method('get')->willReturnCallback(
			fn (string $id): object => match ($id) {
				CallService::class => $this->callService,
				ConnectionStore::class => $this->connectionStore,
				default => throw new \RuntimeException('not installed: ' . $id),
			}
		);
	}

	private function service(array $channelSources): NationalIndexService {
		$this->config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $key === 'channel_sources' ? json_encode($channelSources) : $default
		);

		return new NationalIndexService(
			container: $this->container,
			config: $this->config,
			dispatcher: $this->dispatcher,
			logger: $this->createMock(LoggerInterface::class)
		);
	}

	private function source(string $slug): ObjectEntity {
		$source = new ObjectEntity();
		$source->setUuid('0b6a4d1e-1111-4c2a-9d5e-000000000001');
		$source->setObject(['slug' => $slug, 'location' => 'https://index.example.nl']);
		return $source;
	}

	private function callLog(int $status, string $body): ObjectEntity {
		$log = new ObjectEntity();
		$log->setObject(['statusCode' => $status, 'response' => ['statusCode' => $status, 'body' => $body]]);
		return $log;
	}

	/** REQ-WND-001: a configured channel is reached with the source OBJECT, not its name. */
	public function testAConfiguredChannelIsCalledWithTheSourceObject(): void {
		$source = $this->source('woo-index');
		$this->connectionStore->expects($this->once())->method('findSourceBySlug')->with('woo-index')->willReturn($source);
		$this->callService->expects($this->once())->method('call')
			->with($this->identicalTo($source), 'registrations', 'POST', $this->callback(
				fn (array $config): bool => json_decode($config['body'], true)['reference'] === 'pub-1'
			))
			->willReturn($this->callLog(201, '{"id":"idx-9"}'));

		$result = $this->service(['national-woo-index' => 'woo-index'])
			->registerWithWooIndex(publication: ['id' => 'pub-1', 'title' => 'Besluit']);

		$this->assertSame(NationalIndexService::CHANNEL_WOO_INDEX, $result['channel']);
		$this->assertSame('{"id":"idx-9"}', $result['answer']);
	}

	/** REQ-WND-001: a channel with no source fails before any call and names the setting. */
	public function testAChannelWithNoSourceFailsBeforeAnyCall(): void {
		$this->connectionStore->expects($this->never())->method('findSourceBySlug');
		$this->callService->expects($this->never())->method('call');

		try {
			$this->service([])->withdraw(channel: NationalIndexService::CHANNEL_PLOOI, publicationId: 'pub-1', reason: 'error');
			$this->fail('A channel with no source must not be reported as delivered.');
		} catch (IndexUnreachableException $e) {
			$this->assertStringContainsString(NationalIndexService::CHANNEL_PLOOI, $e->getMessage());
			$this->assertStringContainsString('channel_sources', $e->getMessage());
		}
	}

	/** REQ-WND-001: a source slug nothing answers to is unreachable, not delivered. */
	public function testASourceThatDoesNotExistIsUnreachable(): void {
		$this->connectionStore->method('findSourceBySlug')->willReturn(null);
		$this->callService->expects($this->never())->method('call');

		$this->expectException(IndexUnreachableException::class);
		$this->expectExceptionMessage('gone-source');
		$this->service(['national-woo-index' => 'gone-source'])->registerWithWooIndex(publication: ['id' => 'pub-1']);
	}

	/** REQ-WND-001: an error status from the platform is not an acknowledgement. */
	public function testAnErrorStatusIsNotAnAcknowledgement(): void {
		$this->connectionStore->method('findSourceBySlug')->willReturn($this->source('woo-index'));
		$this->callService->method('call')->willReturn($this->callLog(500, '{"error":"down"}'));

		$this->expectException(IndexUnreachableException::class);
		$this->expectExceptionMessage('500');
		$this->service(['national-woo-index' => 'woo-index'])->registerWithWooIndex(publication: ['id' => 'pub-1']);
	}

	/** REQ-WND-002: a notice for the national platform is a `publicatie` delivery request by reference. */
	public function testANoticeIsDispatchedToThePublicationGatewayByReference(): void {
		$this->callService->expects($this->never())->method('call');
		$dispatched = null;
		$this->dispatcher->expects($this->once())->method('dispatchTyped')->willReturnCallback(
			function (object $event) use (&$dispatched): void {
				$dispatched = $event;
				$event->setDelivery(['gateway' => 'publicatie', 'delivered' => true, 'identifier' => 'gvop-1', 'reason' => '']);
			}
		);
		$service = $this->service([NationalIndexService::CHANNEL_NATIONAL => 'officielebekendmakingen']);
		$notice = $service->composeNotice(
			decision: ['id' => 'dec-1', 'title' => 'Besluit', 'url' => 'https://gemeente.nl/besluit/1', 'publicationType' => 'gemeenteblad', 'effectiveDate' => '2026-10-01'],
			channel: NationalIndexService::CHANNEL_NATIONAL
		);

		$result = $service->deliver(notice: $notice);

		$this->assertInstanceOf(GatewayDeliveryRequestedEvent::class, $dispatched);
		$this->assertSame('publicatie', $dispatched->getGatewayId());
		$this->assertSame('opencatalogi', $dispatched->getSourceApp());
		$this->assertSame(['app' => 'opencatalogi', 'id' => 'dec-1', 'url' => 'https://gemeente.nl/besluit/1'], $dispatched->getRequest()['reference']);
		$this->assertSame(['publicationType' => 'gemeenteblad', 'effectiveDate' => '2026-10-01'], $dispatched->getRequest()['instruction']);
		$this->assertArrayNotHasKey('document', $dispatched->getRequest()['reference']);
		$this->assertSame(['source' => 'officielebekendmakingen'], $dispatched->getConfig());
		$this->assertSame('gvop-1', $result['delivery']['identifier']);
	}

	/** REQ-WND-002: nobody took the request, so the channel is unreachable, never acknowledged. */
	public function testANoticeNobodyTookIsUnreachable(): void {
		$this->dispatcher->method('dispatchTyped');

		$this->expectException(IndexUnreachableException::class);
		$this->service([NationalIndexService::CHANNEL_NATIONAL => 'officielebekendmakingen'])
			->deliver(notice: ['channel' => NationalIndexService::CHANNEL_NATIONAL, 'reference' => 'dec-1']);
	}

	/** REQ-WND-002: a refusal comes back to the editor with its code. */
	public function testARefusalIsReturnedWithItsCode(): void {
		$this->dispatcher->method('dispatchTyped')->willReturnCallback(
			fn (object $event) => $event->refuse(reason: 'The request names no messageType.', code: 'invalid-request')
		);

		$this->expectException(IndexUnreachableException::class);
		$this->expectExceptionMessage('invalid-request');
		$this->service([NationalIndexService::CHANNEL_NATIONAL => 'officielebekendmakingen'])
			->deliver(notice: ['channel' => NationalIndexService::CHANNEL_NATIONAL, 'reference' => 'dec-1']);
	}

	/** REQ-WND-002: a delivery the gateway did not send is not a delivery. */
	public function testANotSentDeliveryIsNotAcknowledged(): void {
		$this->dispatcher->method('dispatchTyped')->willReturnCallback(
			fn (object $event) => $event->setDelivery(['gateway' => 'publicatie', 'delivered' => false, 'identifier' => null, 'reason' => 'The publication instruction names no "effectiveDate".'])
		);

		$this->expectException(IndexUnreachableException::class);
		$this->expectExceptionMessage('effectiveDate');
		$this->service([NationalIndexService::CHANNEL_NATIONAL => 'officielebekendmakingen'])
			->deliver(notice: ['channel' => NationalIndexService::CHANNEL_NATIONAL, 'reference' => 'dec-1']);
	}

	/** REQ-WND-003: a PLOOI delivery goes to the PLOOI source and returns the platform identifier. */
	public function testAPlooiDeliveryReturnsThePlatformIdentifier(): void {
		$source = $this->source('plooi-api');
		$this->connectionStore->method('findSourceBySlug')->with('plooi-api')->willReturn($source);
		$this->callService->expects($this->once())->method('call')
			->with($this->identicalTo($source), '', 'POST', $this->anything())
			->willReturn($this->callLog(201, '{"identificatie":"plooi-42"}'));

		$result = $this->service([NationalIndexService::CHANNEL_PLOOI => 'plooi-api'])
			->deliverToPlooi(document: ['identifier' => 'pub-1', 'title' => 'Besluit']);

		$this->assertSame('plooi-42', $result['identifier']);
	}
}
