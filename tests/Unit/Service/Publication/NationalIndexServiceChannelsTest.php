<?php

/**
 * Tests for the non-national channels of the national hand-over: notices to
 * a configured channel, withdrawals, and reading the channel-to-source map.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Service\Publication
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenCatalogi.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service\Publication;

use DateTimeImmutable;
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
 * @uses   \OCA\OpenCatalogi\Service\Publication\ChannelHandOver
 * @uses   \OCA\OpenCatalogi\Service\Publication\IndexUnreachableException
 */
class NationalIndexServiceChannelsTest extends TestCase {

	private MockObject&ContainerInterface $container;

	private MockObject&IAppConfig $config;

	private MockObject&IEventDispatcher $dispatcher;

	private MockObject&CallService $callService;

	private MockObject&ConnectionStore $connectionStore;

	/** @var object|null A gateway to hand out instead of the CallService double. */
	private ?object $gatewayOverride = null;

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
				CallService::class => ($this->gatewayOverride ?? $this->callService),
				ConnectionStore::class => $this->connectionStore,
				default => throw new \RuntimeException('not installed: ' . $id),
			}
		);
	}

	private function service(string $channelSourcesJson): NationalIndexService {
		$this->config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $key === NationalIndexService::CHANNEL_SOURCES_KEY ? $channelSourcesJson : $default
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
		$source->setUuid('0b6a4d1e-1111-4c2a-9d5e-000000000002');
		$source->setObject(['slug' => $slug, 'location' => 'https://channel.example.nl']);
		return $source;
	}

	private function callLog(int $status, string $body): ObjectEntity {
		$log = new ObjectEntity();
		$log->setObject(['statusCode' => $status, 'response' => ['statusCode' => $status, 'body' => $body]]);
		return $log;
	}

	public function testChannelSourcesThatAreNotAMapReadAsNone(): void {
		$this->assertSame([], $this->service('"just-a-string"')->channelSources());
		$this->assertSame([], $this->service('{not json')->channelSources());
	}

	public function testChannelSourcesDropUnreadableAndEmptyEntriesAndTrimSlugs(): void {
		$sources = $this->service(
			(string)json_encode(['plooi' => '  plooi-api  ', 'local-channel' => '   ', 'national-woo-index' => 42, 'x' => ['nested']])
		)->channelSources();

		$this->assertSame(['plooi' => 'plooi-api'], $sources);
	}

	public function testANoticeForAnotherChannelIsHandedOverToItsSource(): void {
		$source = $this->source('local-feed');
		$this->connectionStore->expects($this->once())->method('findSourceBySlug')->with('local-feed')->willReturn($source);
		$this->dispatcher->expects($this->never())->method('dispatchTyped');
		$this->callService->expects($this->once())->method('call')
			->with(
				$this->identicalTo($source),
				'notices',
				'POST',
				$this->callback(
					fn (array $config): bool => json_decode($config['body'], true) === ['channel' => NationalIndexService::CHANNEL_LOCAL, 'reference' => 'dec-7']
						&& $config['headers'] === ['Content-Type' => 'application/json']
				)
			)
			->willReturn($this->callLog(202, '{"accepted":true}'));

		$result = $this->service((string)json_encode([NationalIndexService::CHANNEL_LOCAL => 'local-feed']))->deliver(
			notice: ['channel' => NationalIndexService::CHANNEL_LOCAL, 'reference' => 'dec-7'],
			now: new DateTimeImmutable('2026-10-02T09:30:00+02:00')
		);

		$this->assertSame(
			[
				'channel' => NationalIndexService::CHANNEL_LOCAL,
				'deliveredAt' => '2026-10-02T09:30:00+02:00',
				'answer' => '{"accepted":true}',
			],
			$result
		);
	}

	public function testANoticeWithoutAMomentIsStampedNowInUtc(): void {
		$this->connectionStore->method('findSourceBySlug')->willReturn($this->source('local-feed'));
		$this->callService->method('call')->willReturn($this->callLog(200, 'ok'));

		$before = new DateTimeImmutable('now');
		$result = $this->service((string)json_encode([NationalIndexService::CHANNEL_LOCAL => 'local-feed']))
			->deliver(notice: ['channel' => NationalIndexService::CHANNEL_LOCAL]);

		$this->assertStringEndsWith('+00:00', $result['deliveredAt']);
		$this->assertGreaterThanOrEqual($before->getTimestamp(), (new DateTimeImmutable($result['deliveredAt']))->getTimestamp());
		$this->assertArrayNotHasKey('delivery', $result);
	}

	public function testAWithdrawalPostsTheReferenceAndTheReason(): void {
		$source = $this->source('plooi-api');
		$this->connectionStore->method('findSourceBySlug')->with('plooi-api')->willReturn($source);
		$this->callService->expects($this->once())->method('call')
			->with(
				$this->identicalTo($source),
				'withdrawals',
				'POST',
				$this->callback(
					fn (array $config): bool => json_decode($config['body'], true) === ['reference' => 'pub-3', 'reason' => 'Published in error']
				)
			)
			->willReturn($this->callLog(200, '{"withdrawn":"pub-3"}'));

		$result = $this->service((string)json_encode([NationalIndexService::CHANNEL_PLOOI => 'plooi-api']))->withdraw(
			channel: NationalIndexService::CHANNEL_PLOOI,
			publicationId: 'pub-3',
			reason: 'Published in error',
			now: new DateTimeImmutable('2026-10-03T12:00:00+00:00')
		);

		$this->assertSame(
			[
				'channel' => NationalIndexService::CHANNEL_PLOOI,
				'acknowledgedAt' => '2026-10-03T12:00:00+00:00',
				'answer' => '{"withdrawn":"pub-3"}',
			],
			$result
		);
	}

	public function testAWithdrawalWithoutAMomentIsStampedInUtc(): void {
		$this->connectionStore->method('findSourceBySlug')->willReturn($this->source('plooi-api'));
		$this->callService->method('call')->willReturn($this->callLog(200, 'done'));

		$result = $this->service((string)json_encode([NationalIndexService::CHANNEL_PLOOI => 'plooi-api']))
			->withdraw(channel: NationalIndexService::CHANNEL_PLOOI, publicationId: 'pub-3', reason: 'error');

		$this->assertSame('done', $result['answer']);
		$this->assertStringEndsWith('+00:00', $result['acknowledgedAt']);
	}

	public function testAGatewayAnsweringABareStringIsTakenAsTheAnswer(): void {
		$this->connectionStore->method('findSourceBySlug')->willReturn($this->source('plooi-api'));
		$this->gatewayOverride = new class {
			public function call(object $source, string $endpoint, string $method, array $config): string {
				return 'plain acknowledgement';
			}
		};

		$result = $this->service((string)json_encode([NationalIndexService::CHANNEL_PLOOI => 'plooi-api']))
			->withdraw(channel: NationalIndexService::CHANNEL_PLOOI, publicationId: 'pub-3', reason: 'error');

		$this->assertSame('plain acknowledgement', $result['answer']);
	}

	public function testAGatewayAnsweringNothingReadableIsNotAnAcknowledgement(): void {
		$this->connectionStore->method('findSourceBySlug')->willReturn($this->source('plooi-api'));
		$this->gatewayOverride = new class {
			public function call(object $source, string $endpoint, string $method, array $config): ?string {
				return null;
			}
		};

		$this->expectException(IndexUnreachableException::class);
		$this->expectExceptionMessage('The channel "plooi" answered nothing, so the delivery cannot be called acknowledged.');
		$this->service((string)json_encode([NationalIndexService::CHANNEL_PLOOI => 'plooi-api']))
			->withdraw(channel: NationalIndexService::CHANNEL_PLOOI, publicationId: 'pub-3', reason: 'error');
	}

	public function testAGatewayThatThrowsMakesTheChannelUnreachable(): void {
		$this->connectionStore->method('findSourceBySlug')->willReturn($this->source('plooi-api'));
		$failure = new \RuntimeException('connection refused');
		$this->callService->method('call')->willThrowException($failure);

		try {
			$this->service((string)json_encode([NationalIndexService::CHANNEL_PLOOI => 'plooi-api']))
				->withdraw(channel: NationalIndexService::CHANNEL_PLOOI, publicationId: 'pub-3', reason: 'error');
			$this->fail('A failed call must not be reported as acknowledged.');
		} catch (IndexUnreachableException $e) {
			$this->assertSame('The channel "plooi" could not be reached: connection refused', $e->getMessage());
			$this->assertSame($failure, $e->getPrevious());
		}
	}
}
