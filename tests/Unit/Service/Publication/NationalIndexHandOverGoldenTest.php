<?php

/**
 * Golden tests for what a national hand-over answers for every reply the
 * integriq gateway can give: the call it makes, the log line and the chained
 * exception of a failed call, where the status is read from, which statuses
 * and bodies count as acknowledged, and the message of every refusal.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Service\Publication
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

namespace Unit\Service\Publication;

use DateTimeImmutable;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\ConnectionStore;
use OCA\OpenCatalogi\Service\Publication\IndexUnreachableException;
use OCA\OpenCatalogi\Service\Publication\NationalIndexService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenCatalogi\Service\Publication\NationalIndexService
 */
class NationalIndexHandOverGoldenTest extends TestCase {

	/**
	 * Everything one registration did: the call, the log lines, the answer or
	 * the refusal and what it chained.
	 *
	 * @param mixed $reply What the gateway call returns, or a Throwable it throws.
	 *
	 * @return array<string, mixed>
	 */
	private function register(mixed $reply): array {
		$calls = [];
		$warnings = [];
		$callService = $this->getMockBuilder(CallService::class)->disableOriginalConstructor()->onlyMethods(['call'])->getMock();
		$callService->method('call')->willReturnCallback(
			static function (ObjectEntity $source, string $endpoint, string $method, array $config) use (&$calls, $reply): mixed {
				$calls[] = [$source->getUuid(), $endpoint, $method, $config];
				if ($reply instanceof \Throwable) {
					throw $reply;
				}

				return $reply;
			}
		);
		$store = $this->getMockBuilder(ConnectionStore::class)->disableOriginalConstructor()->onlyMethods(['findSourceBySlug'])->getMock();
		$source = new ObjectEntity();
		$source->setUuid('src-1');
		$store->method('findSourceBySlug')->willReturn($source);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => match ($id) {
				CallService::class => $callService,
				ConnectionStore::class => $store,
			}
		);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $key === 'channel_sources' ? '{"national-woo-index":"woo-index"}' : $default
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			static function (string $message) use (&$warnings): void {
				$warnings[] = $message;
			}
		);
		$service = new NationalIndexService(container: $container, config: $config, dispatcher: $this->createMock(IEventDispatcher::class), logger: $logger);

		try {
			$outcome = $service->registerWithWooIndex(request: ['robotsTxt' => 'https://woo.example.nl/robots.txt'], now: new DateTimeImmutable('2026-01-02T03:04:05+00:00'));
		} catch (IndexUnreachableException $e) {
			$outcome = ['refused' => $e->getMessage(), 'previous' => ($e->getPrevious() === null ? null : get_class($e->getPrevious()))];
		}

		return ['calls' => $calls, 'warnings' => $warnings, 'outcome' => $outcome];

	}//end register()

	/**
	 * A call log as the gateway returns it.
	 *
	 * @param array<string, mixed> $object The log's object.
	 *
	 * @return ObjectEntity
	 */
	private static function log(array $object): ObjectEntity {
		$log = new ObjectEntity();
		$log->setObject($object);
		return $log;
	}

	/**
	 * Gateway replies and the outcome of each.
	 *
	 * @return array<string, array{0: mixed, 1: array<string, mixed>|string}>
	 */
	public static function replies(): array {
		$answered = static fn (string $answer): array => ['channel' => 'national-woo-index', 'registeredAt' => '2026-01-02T03:04:05+00:00', 'answer' => $answer];
		$refused = static fn (string $message, ?string $previous = null): array => ['refused' => $message, 'previous' => $previous];
		return [
			'201 with a body' => [self::log(['statusCode' => 201, 'response' => ['statusCode' => 201, 'body' => '{"id":"x"}']]), $answered('{"id":"x"}')],
			'status only in the response' => [self::log(['response' => ['statusCode' => 502, 'body' => 'bad gateway']]), $refused('The channel "national-woo-index" answered HTTP 502, so the delivery cannot be called acknowledged: bad gateway')],
			'top status wins' => [self::log(['statusCode' => 200, 'response' => ['statusCode' => 500, 'body' => 'ok']]), $answered('ok')],
			'300 is not acknowledged' => [self::log(['statusCode' => 300, 'response' => ['body' => 'moved']]), $refused('The channel "national-woo-index" answered HTTP 300, so the delivery cannot be called acknowledged: moved')],
			'299 is' => [self::log(['statusCode' => 299, 'response' => ['body' => 'fine']]), $answered('fine')],
			'blank body' => [self::log(['statusCode' => 200, 'response' => ['body' => "  \n"]]), $refused('The channel "national-woo-index" answered nothing, so the delivery cannot be called acknowledged.')],
			'no body' => [self::log(['statusCode' => 200]), $refused('The channel "national-woo-index" answered nothing, so the delivery cannot be called acknowledged.')],
			'body not text' => [self::log(['statusCode' => 200, 'response' => ['body' => ['a' => 1]]]), $refused('The channel "national-woo-index" answered nothing, so the delivery cannot be called acknowledged.')],
			'the call throws' => [new RuntimeException('connection refused'), $refused('The channel "national-woo-index" could not be reached: connection refused', RuntimeException::class)],
		];

	}//end replies()

	/**
	 * The outcome of one reply.
	 *
	 * @param mixed $reply The gateway reply.
	 * @param array<string, mixed> $expected The answer or the refusal.
	 *
	 * @return void
	 */
	#[DataProvider('replies')]
	public function testReply(mixed $reply, array $expected): void {
		$traced = $this->register($reply);

		$this->assertSame($expected, $traced['outcome']);
		$this->assertSame(
			[['src-1', 'registrations', 'POST', ['body' => '{"robotsTxt":"https:\/\/woo.example.nl\/robots.txt"}', 'headers' => ['Content-Type' => 'application/json']]]],
			$traced['calls']
		);
		$warnings = [];
		if ($reply instanceof \Throwable) {
			$warnings = ['[NationalIndexService] The delivery to "national-woo-index" failed: connection refused'];
		}

		$this->assertSame($warnings, $traced['warnings']);

	}//end testReply()
}//end class
