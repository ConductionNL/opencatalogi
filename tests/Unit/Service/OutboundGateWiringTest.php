<?php

/**
 * The outbound channels ask the gate first (instance-staging-mode REQ-STG-002).
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/instance-staging-mode/tasks.md#task-2.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use OCA\OpenCatalogi\Service\BroadcastService;
use OCA\OpenCatalogi\Service\DryRunRecorder;
use OCA\OpenCatalogi\Service\Federation\FederationHostPolicy;
use OCA\OpenCatalogi\Service\InstanceMode;
use OCA\OpenCatalogi\Service\OpenRegisterLookup;
use OCA\OpenCatalogi\Service\OutboundGate;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

/**
 * In staging a channel stores a dry run and sends nothing; in production it sends.
 *
 * @spec openspec/changes/instance-staging-mode/specs/admin-settings/spec.md#requirement-nothing-leaves-the-instance-in-staging-req-stg-002
 */
#[CoversClass(DryRunRecorder::class)]
#[CoversClass(BroadcastService::class)]
#[UsesClass(OutboundGate::class)]
#[UsesClass(InstanceMode::class)]
#[UsesClass(OpenRegisterLookup::class)]
#[UsesClass(FederationHostPolicy::class)]
final class OutboundGateWiringTest extends TestCase {

	/**
	 * The objects OpenRegister was asked to save: [register, schema, object].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $saved = [];

	/**
	 * The requests that left through the HTTP client.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $sent = [];

	/**
	 * A recorder over the given mode, with a recording object service.
	 *
	 * @param string $mode       The instance_mode value; 'throw' makes it unreadable.
	 * @param bool   $storeFails Whether saving the dry run fails.
	 *
	 * @return DryRunRecorder
	 */
	private function recorder(string $mode, bool $storeFails = false): DryRunRecorder {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($mode): string {
				if ($key === 'instance_mode' && $mode === 'throw') {
					throw new RuntimeException('config down');
				}

				return match ($key) {
					'instance_mode' => $mode,
					'publication_register' => 'publication',
					default => $default,
				};
			}
		);

		$objects = new class($this, $storeFails) {
			/**
			 * Hold the test and whether saving fails.
			 *
			 * @param object $owner The test.
			 * @param bool   $fails Whether saving fails.
			 */
			public function __construct(private object $owner, private bool $fails) {
			}//end __construct()

			/**
			 * Record the save.
			 *
			 * @param array  $object   The object.
			 * @param string $register The register.
			 * @param string $schema   The schema.
			 *
			 * @return array
			 */
			public function saveObject(array $object, string $register, string $schema): array {
				if ($this->fails === true) {
					throw new RuntimeException('store down');
				}

				$this->owner->recordSave(save: [$register, $schema, $object]);
				return $object;
			}//end saveObject()
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objects);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getInstalledApps')->willReturn(['openregister']);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('officer');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new DryRunRecorder(
			gate: new OutboundGate(new InstanceMode($config)),
			openRegister: new OpenRegisterLookup($container, $apps),
			config: $config,
			userSession: $session,
		);
	}//end recorder()

	/**
	 * Record a save (called by the object service double).
	 *
	 * @param array<int, mixed> $save The save.
	 *
	 * @return void
	 */
	public function recordSave(array $save): void {
		$this->saved[] = $save;
	}//end recordSave()

	/**
	 * A broadcast service whose HTTP client records what leaves and answers 200.
	 *
	 * @param DryRunRecorder|null $dryRuns The recorder.
	 *
	 * @return BroadcastService
	 */
	private function broadcast(?DryRunRecorder $dryRuns): BroadcastService {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => $default);
		$config->method('getValueInt')->willReturnCallback(static fn (string $app, string $key, int $default = 0): int => $default);
		$service = new BroadcastService(
			$this->createMock(IURLGenerator::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(IAppManager::class),
			$this->createMock(LoggerInterface::class),
			$config,
			new FederationHostPolicy($config),
			null,
			$dryRuns
		);

		$stack = HandlerStack::create(new MockHandler([new Response(200), new Response(200)]));
		$stack->push(Middleware::history($this->sent));
		(new ReflectionProperty($service, 'client'))->setValue($service, new Client(['handler' => $stack]));

		return $service;
	}//end broadcast()

	/**
	 * Send one broadcast through the private send method.
	 *
	 * @param BroadcastService $service The service.
	 *
	 * @return bool
	 */
	private static function send(BroadcastService $service): bool {
		return (new ReflectionMethod($service, 'sendBroadcastRequest'))->invoke($service, 'https://directory.example/api', 'https://catalogus.example/api/directory');
	}//end send()

	/**
	 * In staging the broadcast stores a dry run, sends nothing and goes on as accepted.
	 *
	 * @return void
	 */
	public function testTheBroadcastRecordsADryRunInStaging(): void {
		self::assertTrue(self::send(service: $this->broadcast(dryRuns: $this->recorder(mode: 'staging'))));

		self::assertSame([], $this->sent, 'no HTTP request may leave in staging');
		self::assertCount(1, $this->saved);
		[$register, $schema, $record] = $this->saved[0];
		self::assertSame(['publication', 'dryRunDelivery'], [$register, $schema]);
		self::assertSame('broadcast', $record['channel']);
		self::assertSame(['url' => 'https://directory.example/api', 'directoryUrl' => 'https://catalogus.example/api/directory'], $record['payload']);
		self::assertSame('officer', $record['user']);
		self::assertSame('broadcast held: instance mode staging', $record['reason']);
		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $record['at']);
	}//end testTheBroadcastRecordsADryRunInStaging()

	/**
	 * In production the broadcast leaves and no dry run is stored.
	 *
	 * @return void
	 */
	public function testProductionSends(): void {
		self::assertTrue(self::send(service: $this->broadcast(dryRuns: $this->recorder(mode: 'production'))));

		self::assertCount(1, $this->sent);
		self::assertSame('https://directory.example/api', (string)$this->sent[0]['request']->getUri());
		self::assertSame([], $this->saved);
	}//end testProductionSends()

	/**
	 * An unreadable mode holds the hand-over too; a dry run that cannot be stored still sends nothing.
	 *
	 * @return void
	 */
	public function testAnUnreadableModeAndAFailedStoreSendNothing(): void {
		$unreadable = $this->recorder(mode: 'throw')->hold(channel: 'plooi', payload: ['id' => 'p-1']);
		self::assertSame(['dryRun' => true, 'channel' => 'plooi', 'reason' => 'plooi held: instance mode unreadable: config down', 'stored' => true], $unreadable);

		$failing = $this->recorder(mode: 'staging', storeFails: true);
		self::assertSame(false, $failing->hold(channel: 'broadcast', payload: [])['stored']);
		self::assertTrue(self::send(service: $this->broadcast(dryRuns: $failing)));
		self::assertSame([], $this->sent);

		self::assertNull($this->recorder(mode: 'production')->hold(channel: 'plooi', payload: []));
	}//end testAnUnreadableModeAndAFailedStoreSendNothing()
}//end class
