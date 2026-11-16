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
use OCA\OpenCatalogi\Service\DirectoryService;
use OCA\OpenCatalogi\Service\Publication\IndexUnreachableException;
use OCA\OpenCatalogi\Service\Publication\NationalIndexService;
use OCA\OpenCatalogi\Service\Publication\PlooiDeliveryService;
use OCA\OpenCatalogi\Service\SettingsService;
use OCA\OpenCatalogi\Service\TooiVocabularyService;
use OCA\OpenCatalogi\Service\Woo\LocalCategoryAdmission;
use OCA\OpenCatalogi\Service\Woo\WooCategoryRegistry;
use OCA\OpenCatalogi\Service\WooReadinessService;
use OCA\OpenCatalogi\Service\WooRegistrationService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IRequest;
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
#[CoversClass(NationalIndexService::class)]
#[CoversClass(PlooiDeliveryService::class)]
#[CoversClass(WooRegistrationService::class)]
#[CoversClass(DirectoryService::class)]
#[UsesClass(OutboundGate::class)]
#[UsesClass(InstanceMode::class)]
#[UsesClass(OpenRegisterLookup::class)]
#[UsesClass(FederationHostPolicy::class)]
#[UsesClass(IndexUnreachableException::class)]
#[UsesClass(WooCategoryRegistry::class)]
#[UsesClass(LocalCategoryAdmission::class)]
#[UsesClass(TooiVocabularyService::class)]
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
			 * @param array             $object  The object.
			 * @param ObjectSaveOptions $options Where it is saved.
			 *
			 * @return array
			 */
			public function saveObject(array $object, ObjectSaveOptions $options=new ObjectSaveOptions()): array {
				if ($this->fails === true) {
					throw new RuntimeException('store down');
				}

				$this->owner->recordSave(save: [$options->target->register, $options->target->schema, $object]);
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
	/**
	 * What the container was asked for and the events dispatched: anything here would have left.
	 *
	 * @var array<int, string>
	 */
	private array $reached = [];

	/**
	 * Record that a hand-over reached for the outside world.
	 *
	 * @param string $what What it reached for.
	 *
	 * @return void
	 */
	public function recordReach(string $what): void {
		$this->reached[] = $what;
	}//end recordReach()

	/**
	 * A national index service whose gateway lookups and dispatched events are recorded.
	 *
	 * Every gateway lookup fails, so in production a hand-over ends unreachable after reaching out.
	 *
	 * @param DryRunRecorder|null $dryRuns The recorder.
	 *
	 * @return NationalIndexService
	 */
	private function nationalIndex(?DryRunRecorder $dryRuns): NationalIndexService {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id): object {
				$this->recordReach(what: 'container:' . $id);
				throw new RuntimeException('not installed');
			}
		);
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (Event $event): void {
				$this->recordReach(what: 'event:' . $event::class);
			}
		);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				'channel_sources' => '{"national-woo-index":"woo-index","plooi":"plooi","local-channel":"local"}',
				default => $default,
			}
		);

		return new NationalIndexService($container, $config, $dispatcher, $this->createMock(LoggerInterface::class), $dryRuns);
	}//end nationalIndex()

	/**
	 * A PLOOI delivery over one publication in a PLOOI catalogue; what it saves is recorded.
	 *
	 * @param NationalIndexService $index   The national index service.
	 * @param DryRunRecorder|null  $dryRuns The recorder.
	 *
	 * @return PlooiDeliveryService
	 */
	private function plooi(NationalIndexService $index, ?DryRunRecorder $dryRuns): PlooiDeliveryService {
		$publication = new ObjectEntity();
		$publication->setUuid('pub-1');
		$publication->setObject(['title' => 'Besluit', 'summary' => 'Kort', 'publicationDate' => '2026-09-01T00:00:00+00:00']);
		$objects = $this->getMockBuilder(ObjectService::class)
			->disableOriginalConstructor()
			->onlyMethods(['find', 'saveObject', 'searchObjectsPaginated'])
			->getMock();
		$objects->method('find')->willReturn($publication);
		$objects->method('searchObjectsPaginated')->willReturn(['results' => [['registers' => ['7'], 'schemas' => ['12'], 'plooiDelivery' => true]], 'total' => 1]);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object) use ($publication): ObjectEntity {
				$this->recordSave(save: ['7', '12', $object]);
				return $publication;
			}
		);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getSettings')->willReturn(['configuration' => ['catalog_register' => '7', 'catalog_schema' => '3']]);

		return new PlooiDeliveryService($settings, $index, $this->createMock(LoggerInterface::class), $dryRuns);
	}//end plooi()

	/**
	 * A Woo registration over a real national index service; what it writes to app config is recorded.
	 *
	 * @param NationalIndexService $index   The national index service.
	 * @param DryRunRecorder|null  $dryRuns The recorder.
	 *
	 * @return WooRegistrationService
	 */
	private function wooRegistration(NationalIndexService $index, ?DryRunRecorder $dryRuns): WooRegistrationService {
		$readiness = $this->getMockBuilder(WooReadinessService::class)
			->disableOriginalConstructor()
			->onlyMethods(['getWooEnabledCatalogs', 'getRegistrationConfig'])
			->getMock();
		$readiness->method('getWooEnabledCatalogs')->willReturn([]);
		$readiness->method('getRegistrationConfig')->willReturn(['status' => 'not_registered', 'registeredUrl' => '', 'registeredAt' => '']);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => $default);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->recordReach(what: 'config:' . $key);
				return true;
			}
		);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getBaseUrl')->willReturn('https://woo.example.nl');
		$categories = new WooCategoryRegistry(
			new TooiVocabularyService(),
			$this->createMock(SettingsService::class),
			$this->createMock(LoggerInterface::class),
			new LocalCategoryAdmission(new TooiVocabularyService()),
		);

		return new WooRegistrationService($readiness, $index, $this->createMock(SettingsService::class), $config, $urls, $categories, $dryRuns);
	}//end wooRegistration()

	/**
	 * A directory service on a public URL whose broadcast service must never be asked.
	 *
	 * @param DryRunRecorder|null $dryRuns The recorder.
	 *
	 * @return DirectoryService
	 */
	private function directory(?DryRunRecorder $dryRuns): DirectoryService {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRoute')->willReturn('/apps/opencatalogi/api/directory');
		$urls->method('getAbsoluteURL')->willReturn('https://catalogus.example/apps/opencatalogi/api/directory');
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => $default);
		$broadcast = $this->getMockBuilder(BroadcastService::class)->disableOriginalConstructor()->onlyMethods(['broadcast'])->getMock();
		$broadcast->method('broadcast')->willReturnCallback(
			function (string $url): array {
				$this->recordReach(what: 'broadcast:' . $url);
				return [];
			}
		);

		return new DirectoryService(
			$urls,
			$config,
			$this->createMock(ContainerInterface::class),
			$this->createMock(IAppManager::class),
			$broadcast,
			$this->createMock(IRequest::class),
			null,
			null,
			$dryRuns
		);
	}//end directory()

	/**
	 * Drive every gated method once: two notices, PLOOI twice, the Woo-index registration,
	 * a withdrawal, a broadcast and a directory announcement.
	 *
	 * @param DryRunRecorder $dryRuns The recorder.
	 *
	 * @return array<string, mixed> What each answered; an unreachable hand-over answers its exception class.
	 */
	private function driveEveryChannel(DryRunRecorder $dryRuns): array {
		$index = $this->nationalIndex(dryRuns: $dryRuns);
		$calls = [
			'national' => static fn (): array => $index->deliver(notice: ['channel' => 'national-publication-platform', 'reference' => 'pub-1']),
			'woo-index' => static fn (): array => $index->deliver(notice: ['channel' => 'national-woo-index', 'reference' => 'pub-1']),
			'deliverToPlooi' => static fn (): array => $index->deliverToPlooi(document: ['identifier' => 'pub-1']),
			'withdraw' => static fn (): array => $index->withdraw(channel: 'national-woo-index', publicationId: 'pub-1', reason: 'In error'),
			'plooiDelivery' => fn (): ?array => $this->plooi(index: $index, dryRuns: $dryRuns)->deliver(uuid: 'pub-1', register: '7', schema: '12'),
			'registration' => fn (): array => $this->wooRegistration(index: $index, dryRuns: $dryRuns)->request(),
			'broadcast' => fn (): bool => self::send(service: $this->broadcast(dryRuns: $dryRuns)),
			'announce' => fn (): mixed => (new ReflectionMethod(DirectoryService::class, 'broadcastToDirectory'))
				->invoke($this->directory(dryRuns: $dryRuns), 'https://directory.example/apps/opencatalogi/api/directory'),
		];

		$answers = [];
		foreach ($calls as $name => $call) {
			try {
				$answers[$name] = $call();
			} catch (IndexUnreachableException $e) {
				$answers[$name] = IndexUnreachableException::class;
			}
		}

		return $answers;
	}//end driveEveryChannel()

	/**
	 * In staging every channel stores a dry run: no HTTP request, no gateway lookup, no event.
	 *
	 * @return void
	 */
	public function testEveryChannelRecordsADryRunInStaging(): void {
		$answers = $this->driveEveryChannel(dryRuns: $this->recorder(mode: 'staging'));

		self::assertSame([], $this->sent, 'no HTTP request may leave in staging');
		self::assertSame([], $this->reached, 'no gateway, event, broadcast or registration may be reached in staging');
		self::assertSame(
			['national-index', 'national-index', 'plooi', 'national-index', 'plooi', 'woo-registration', 'broadcast', 'directory-announce'],
			array_map(static fn (array $save): string => $save[2]['channel'], $this->saved)
		);
		self::assertSame(['dryRunDelivery'], array_values(array_unique(array_map(static fn (array $save): string => $save[1], $this->saved))));
		self::assertSame(['channel' => 'national-woo-index', 'reference' => 'pub-1'], $this->saved[1][2]['payload']);
		self::assertSame('pub-1', $this->saved[4][2]['payload']['identifier']);
		self::assertSame('https://directory.example/apps/opencatalogi/api/directory', $this->saved[7][2]['payload']['directoryUrl']);
		foreach (['national', 'woo-index', 'deliverToPlooi', 'withdraw', 'plooiDelivery', 'registration'] as $name) {
			self::assertTrue($answers[$name]['dryRun'], $name . ' answers a dry run');
		}

		self::assertTrue($answers['broadcast']);
	}//end testEveryChannelRecordsADryRunInStaging()

	/**
	 * A dry run claims no delivery: no delivered, registered or acknowledged moment, no
	 * identifier, no PLOOI status on the publication, no stored registration answer.
	 *
	 * @return void
	 */
	public function testADryRunSetsNoDeliveryField(): void {
		$answers = $this->driveEveryChannel(dryRuns: $this->recorder(mode: 'staging'));

		self::assertNull($answers['national']['deliveredAt']);
		self::assertSame('', $answers['national']['answer']);
		self::assertArrayNotHasKey('delivery', $answers['national']);
		self::assertSame(['identifier' => null, 'answer' => '', 'dryRun' => true, 'reason' => 'plooi held: instance mode staging'], $answers['deliverToPlooi']);
		self::assertNull($answers['withdraw']['acknowledgedAt']);
		self::assertArrayNotHasKey('plooiStatus', $answers['plooiDelivery']);
		self::assertArrayNotHasKey('plooiDeliveredAt', $answers['plooiDelivery']);
		self::assertFalse($answers['registration']['sent']);
		self::assertSame('not_registered', $answers['registration']['registration']['status']);
		$publicationSaves = array_filter($this->saved, static fn (array $save): bool => $save[1] === '12');
		self::assertSame([], $publicationSaves, 'the publication is not saved with PLOOI fields');
	}//end testADryRunSetsNoDeliveryField()

	/**
	 * In production the same calls reach the gateway, the event dispatcher and the peer, and store no dry run.
	 *
	 * @return void
	 */
	public function testProductionReachesEveryChannel(): void {
		$answers = $this->driveEveryChannel(dryRuns: $this->recorder(mode: 'production'));

		self::assertSame([], array_filter($this->saved, static fn (array $save): bool => $save[1] === 'dryRunDelivery'));
		self::assertContains('broadcast:https://directory.example/apps/opencatalogi/api/directory', $this->reached);
		self::assertNotSame([], array_filter($this->reached, static fn (string $what): bool => str_starts_with($what, 'container:')));
		self::assertCount(1, $this->sent);
		self::assertSame(IndexUnreachableException::class, $answers['deliverToPlooi']);
		self::assertSame('failed', $answers['plooiDelivery']['plooiStatus']);
	}//end testProductionReachesEveryChannel()
}//end class
