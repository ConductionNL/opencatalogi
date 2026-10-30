<?php

/**
 * Golden test for how the Woo, withheld, portal and service-catalogue stores
 * reach OpenRegister.
 *
 * Each store is run in three worlds: OpenRegister not installed, installed but
 * not resolvable, and working. Every case is recorded once and replayed from
 * the fixture: the answer or the refusal (class, message, cause), and every
 * question asked of the app manager, the container, OpenRegister and the log.
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

namespace Unit\Service;

use OCA\OpenCatalogi\Service\Portal\PortalObjectStore;
use OCA\OpenCatalogi\Service\Portal\PublicSearchRunner;
use OCA\OpenCatalogi\Service\PublicationQueryService;
use OCA\OpenCatalogi\Service\ServiceCatalogueService;
use OCA\OpenCatalogi\Service\Withheld\WithheldFromPublicationStore;
use OCA\OpenCatalogi\Service\Woo\WithheldDocumentStore;
use OCA\OpenCatalogi\Service\Woo\WooRequestStore;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * An OpenRegister ObjectService stand-in that answers every call with one object.
 */
class AvailabilityGoldenObjects {

	/**
	 * @param array<int, string> $log The shared call log.
	 */
	public function __construct(
		private array &$log,
	) {
	}

	/**
	 * Record the call and answer with one stored object.
	 *
	 * @param string            $name The method.
	 * @param array<int, mixed> $args Its arguments.
	 *
	 * @return array<string, mixed>
	 */
	public function __call(string $name, array $args): array {
		$this->log[] = 'objects:' . $name;
		return ['id' => 'o-1', 'publication' => 'p1', 'results' => [['id' => 'o-1', 'publication' => 'p1']], 'total' => 1];
	}
}

/**
 * Golden test for how the stores reach OpenRegister.
 *
 * @covers \OCA\OpenCatalogi\Service\Woo\WooRequestStore
 * @covers \OCA\OpenCatalogi\Service\Withheld\WithheldFromPublicationStore
 * @covers \OCA\OpenCatalogi\Service\Woo\WithheldDocumentStore
 * @covers \OCA\OpenCatalogi\Service\Portal\PortalObjectStore
 * @covers \OCA\OpenCatalogi\Service\Portal\PublicSearchRunner
 * @covers \OCA\OpenCatalogi\Service\ServiceCatalogueService
 * @uses   \OCA\OpenCatalogi\Service\ReadsObjectProperties
 */
class OpenRegisterAvailabilityGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/openregister-availability-golden.json';

	/**
	 * Every question asked, in order.
	 *
	 * @var array<int, string>
	 */
	private array $log = [];

	/**
	 * The recorded cases: a store and a world.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function cases(): array {
		$cases = [];
		foreach (['woo-request', 'withheld-read', 'withheld-write', 'portal-save', 'portal-find', 'public-search', 'service-catalogue'] as $store) {
			foreach (['absent', 'unresolvable', 'working'] as $world) {
				$cases[$store . ' / ' . $world] = [$store, $world];
			}
		}

		return $cases;

	}//end cases()

	/**
	 * Replay a case and compare it to the recording.
	 *
	 * @param string $store The store.
	 * @param string $world The OpenRegister world.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testReachMatchesTheRecording(string $store, string $world): void {
		$name = $this->dataName();
		$actual = ['outcome' => $this->outcome(store: $store, world: $world), 'asked' => $this->log];

		$recorded = [];
		if (file_exists(self::FIXTURE) === true) {
			$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$name] = $actual;
			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $recorded, 'No recording for ' . $name);
		$this->assertSame($recorded[$name], $actual);

	}//end testReachMatchesTheRecording()

	/**
	 * Run one store call: its answer, or its refusal.
	 *
	 * @param string $store The store.
	 * @param string $world The OpenRegister world.
	 *
	 * @return array<string, mixed> The outcome.
	 */
	private function outcome(string $store, string $world): array {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturnCallback(
			function (string $app) use ($world): bool {
				$this->log[] = 'installed?' . $app;
				return $world !== 'absent';
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($world): object {
				$this->log[] = 'container:' . $id;
				if ($world === 'unresolvable') {
					throw new \RuntimeException('not resolvable');
				}

				return new AvailabilityGoldenObjects($this->log);
			}
		);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(static fn (string $app, string $key): string => $key . '-value');
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message) use ($level): void {
					$this->log[] = $level . ':' . $message;
				}
			);
		}

		try {
			return ['answer' => $this->describe(value: $this->call(store: $store, deps: [$config, $container, $appManager, $logger]))];
		} catch (\Throwable $e) {
			return [
				'refused' => get_class($e),
				'message' => $e->getMessage(),
				'cause' => ($e->getPrevious() !== null ? get_class($e->getPrevious()) : null),
			];
		}

	}//end outcome()

	/**
	 * Build the store and make its call.
	 *
	 * @param string            $store The store.
	 * @param array<int, mixed> $deps  The config, container, app manager and logger.
	 *
	 * @return mixed The answer.
	 */
	private function call(string $store, array $deps): mixed {
		[$config, $container, $appManager, $logger] = $deps;
		$queries = $this->createMock(PublicationQueryService::class);
		$queries->method('assemblePublicSearchResults')->willReturnCallback(
			function (array $queryParams, object $objectService): array {
				$this->log[] = 'search-with:' . get_class($objectService);
				return ['results' => [['id' => 'r-1'], 'not-a-row']];
			}
		);

		return match ($store) {
			'woo-request' => (new WooRequestStore($config, $container, $appManager))->save(['title' => 'x']),
			'withheld-read' => (new WithheldFromPublicationStore($config, $container, $appManager))->storedEntries('p1'),
			'withheld-write' => (new WithheldDocumentStore($config, $container, $appManager, $logger))->publicationExists('p1'),
			'portal-save' => (new PortalObjectStore($container, $queries, $logger, $appManager))->save('dossier', ['a' => 1]),
			'portal-find' => (new PortalObjectStore($container, $queries, $logger, $appManager))->find('dossier', 'id-1'),
			'public-search' => (new PublicSearchRunner($queries, $container, $appManager))->search(['q' => 'x']),
			'service-catalogue' => (new ServiceCatalogueService($container, $logger, $appManager))->getObjectService(),
		};

	}//end call()

	/**
	 * A JSON-safe description of an answer.
	 *
	 * @param mixed $value The answer.
	 *
	 * @return mixed The description.
	 */
	private function describe(mixed $value): mixed {
		if (is_object($value) === true) {
			return 'object:' . get_class($value);
		}

		return $value;

	}//end describe()
}//end class
