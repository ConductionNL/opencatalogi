<?php

/**
 * Golden test for CatalogSchemaEventListener::handle().
 *
 * Every case handles one event over recording doubles and compares the event's
 * modified data and every call, in order, to the recording in
 * fixtures/catalog-schema-event-listener-golden.json.
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

namespace Unit\Listener;

use OCA\OpenCatalogi\Contract\CatalogiServiceInterface;
use OCA\OpenCatalogi\Listener\CatalogSchemaEventListener;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden test for rewriting catalog registers and schemas before a save.
 *
 * @covers \OCA\OpenCatalogi\Listener\CatalogSchemaEventListener
 */
class CatalogSchemaEventListenerGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/catalog-schema-event-listener-golden.json';

	/**
	 * Everything asked, in order.
	 *
	 * @var array<int, mixed>
	 */
	private array $calls = [];

	/**
	 * The recorded cases.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$catalog = ['schema' => 'cat-schema', 'register' => 'cat-reg', 'object' => ['registers' => ['r'], 'schemas' => ['s']]];

		return [
			'a post-save event' => [['event' => 'created'] + $catalog],
			'an unrelated event' => [['event' => 'plain'] + $catalog],
			'creating, rewritten' => [['event' => 'creating', 'rewrite' => ['registers' => [42]]] + $catalog],
			'updating, rewritten' => [['event' => 'updating', 'rewrite' => ['schemas' => [7]]] + $catalog],
			'updating, merged with earlier data' => [['event' => 'updating', 'earlier' => ['title' => 'x', 'schemas' => [1]], 'rewrite' => ['schemas' => [7]]] + $catalog],
			'nothing to rewrite' => [['event' => 'creating', 'rewrite' => []] + $catalog],
			'another schema' => [['event' => 'creating', 'rewrite' => ['registers' => [42]], 'schema' => 'other'] + $catalog],
			'another register' => [['event' => 'creating', 'rewrite' => ['registers' => [42]], 'register' => 'other'] + $catalog],
			'empty object data' => [['event' => 'creating', 'rewrite' => ['registers' => [42]], 'object' => []] + $catalog],
			'the rewrite throws' => [['event' => 'updating', 'fail' => 'exception'] + $catalog],
			'the rewrite errors' => [['event' => 'updating', 'fail' => 'error'] + $catalog],
			'the rewrite throws a plain exception' => [['event' => 'creating', 'fail' => 'plain'] + $catalog],
			'an unconfigured catalog schema' => [['event' => 'creating', 'rewrite' => ['registers' => [42]], 'schema' => '', 'register' => '', 'configured' => false] + $catalog],
		];

	}//end cases()

	/**
	 * Replay a case and compare it to the recording.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testHandleMatchesTheRecording(array $case): void {
		$name = $this->dataName();
		$event = $this->event(case: $case);
		try {
			$this->listener(case: $case)->handle($event);
			$actual = ['calls' => $this->calls];
			if (method_exists($event, 'getModifiedData') === true) {
				$actual['modified'] = $event->getModifiedData();
			}
		} catch (\Throwable $e) {
			$actual = ['thrown' => get_class($e) . ': ' . $e->getMessage(), 'calls' => $this->calls];
		}

		$recorded = [];
		if (file_exists(self::FIXTURE) === true) {
			$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$name] = $actual;
			if (is_dir(dirname(self::FIXTURE)) === false) {
				mkdir(dirname(self::FIXTURE));
			}

			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $recorded, 'No recording for ' . $name);
		$this->assertSame($recorded[$name], json_decode((string)json_encode($actual), true));

	}//end testHandleMatchesTheRecording()

	/**
	 * The event of a case.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return Event
	 */
	private function event(array $case): Event {
		$entity = $this->getMockBuilder(ObjectEntity::class)
			->disableOriginalConstructor()
			->onlyMethods(['getSchema', 'getRegister', 'getObject'])
			->getMock();
		$entity->method('getSchema')->willReturnCallback(
			function () use ($case): string {
				$this->calls[] = 'getSchema';
				return $case['schema'];
			}
		);
		$entity->method('getRegister')->willReturnCallback(
			function () use ($case): string {
				$this->calls[] = 'getRegister';
				return $case['register'];
			}
		);
		$entity->method('getObject')->willReturnCallback(
			function () use ($case): array {
				$this->calls[] = 'getObject';
				return $case['object'];
			}
		);

		$event = match ($case['event']) {
			'created' => new ObjectCreatedEvent($entity),
			'creating' => new ObjectCreatingEvent($entity),
			'updating' => new ObjectUpdatingEvent($entity, null),
			default => new Event(),
		};
		if (isset($case['earlier']) === true) {
			$event->setModifiedData($case['earlier']);
		}

		return $event;

	}//end event()

	/**
	 * The listener over recording doubles.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return CatalogSchemaEventListener
	 */
	private function listener(array $case): CatalogSchemaEventListener {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') use ($case): string {
				$this->calls[] = ['getValueString' => [$app, $key, $default]];
				if (($case['configured'] ?? true) === false) {
					return $default;
				}

				return ['catalog_schema' => 'cat-schema', 'catalog_register' => 'cat-reg'][$key] ?? 'unexpected';
			}
		);

		$catalogi = $this->createMock(CatalogiServiceInterface::class);
		$catalogi->method('computeRewrittenRegistersAndSchemas')->willReturnCallback(
			function (array $object) use ($case): array {
				$this->calls[] = ['compute' => $object];
				if (($case['fail'] ?? '') === 'exception') {
					throw new \RuntimeException('Register ghost not found.');
				}

				if (($case['fail'] ?? '') === 'plain') {
					throw new \Exception('stopped');
				}

				if (($case['fail'] ?? '') === 'error') {
					throw new \Error('crashed');
				}

				return ($case['rewrite'] ?? []);
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->calls[] = ['error' => [$message, get_class($context['exception'] ?? new \stdClass())]];
			}
		);

		return new CatalogSchemaEventListener($catalogi, $logger, $config);

	}//end listener()
}//end class
