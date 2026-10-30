<?php

/**
 * Golden test for WithheldDocumentStore::replace.
 *
 * Every world is recorded once and replayed from the fixture: each call
 * OpenRegister got, in order, the errors logged, and what reached the caller.
 * The moment of recording is recorded by its shape.
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

use OCA\OpenCatalogi\Service\OpenRegisterLookup;
use OCA\OpenCatalogi\Service\Woo\WithheldDocumentStore;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden test for WithheldDocumentStore::replace.
 *
 * @covers \OCA\OpenCatalogi\Service\Woo\WithheldDocumentStore
 * @uses \OCA\OpenCatalogi\Service\OpenRegisterLookup
 */
class WithheldStoreReplaceGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/withheld-store-replace-golden.json';

	/**
	 * Every call and log line, in order.
	 *
	 * @var array<int, mixed>
	 */
	private array $log = [];

	/**
	 * The recorded worlds.
	 *
	 * `rows` is what the search answers; `failSaveAt` makes that save (1-based)
	 * throw; `ids` is what each save answers as its id (null for none, `object`
	 * for an entity-like answer); `failDelete` makes deletes of those ids throw.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function worlds(): array {
		$grounds = [['code' => '5.1.2.e', 'article' => '5.1', 'label' => 'Privacy']];
		$accepted = [['position' => 3, 'grounds' => $grounds], ['position' => 1, 'grounds' => []]];
		$rows = [
			['id' => 'old-1', 'publication' => 'pub-1'],
			['id' => 'other', 'publication' => 'pub-2'],
			['id' => 'old-2', 'publication' => 'pub-1'],
			['publication' => 'pub-1'],
			['id' => 'no-publication'],
		];

		return [
			'replace two with two' => [['rows' => $rows, 'accepted' => $accepted]],
			'replace with nothing' => [['rows' => $rows, 'accepted' => []]],
			'nothing stored yet' => [['rows' => [], 'accepted' => $accepted]],
			'search answers no results key' => [['search' => [], 'accepted' => $accepted]],
			'search answers entities' => [['rows' => [['id' => 'old-9', 'publication' => 'pub-1']], 'entities' => true, 'accepted' => $accepted]],
			'saved as entity' => [['rows' => [], 'accepted' => $accepted, 'ids' => ['object', 'object']]],
			'saved as entity, then fails' => [['rows' => [], 'accepted' => array_merge($accepted, $accepted), 'ids' => ['object', 'object'], 'failSaveAt' => 3]],
			'first save fails' => [['rows' => $rows, 'accepted' => $accepted, 'failSaveAt' => 1]],
			'second save fails' => [['rows' => $rows, 'accepted' => $accepted, 'failSaveAt' => 2]],
			'third save fails' => [['rows' => $rows, 'accepted' => array_merge($accepted, $accepted), 'failSaveAt' => 3]],
			'rollback skips a missing id' => [['rows' => $rows, 'accepted' => array_merge($accepted, $accepted), 'failSaveAt' => 3, 'ids' => [null, 'w-2']]],
			'rollback delete fails' => [['rows' => $rows, 'accepted' => array_merge($accepted, $accepted), 'failSaveAt' => 3, 'failDelete' => ['w-1']]],
			'superseded delete fails' => [['rows' => $rows, 'accepted' => $accepted, 'failDelete' => ['old-2']]],
			'no openregister' => [['installed' => false, 'accepted' => $accepted]],
			'schema not configured' => [['schema' => '', 'accepted' => $accepted]],
			'register not configured' => [['register' => ' ', 'accepted' => $accepted]],
			'search fails' => [['searchFails' => true, 'accepted' => $accepted]],
		];

	}//end worlds()

	/**
	 * Replay a world and compare it to the recording.
	 *
	 * @param array<string, mixed> $world The world.
	 *
	 * @return void
	 */
	#[DataProvider('worlds')]
	public function testReplaceMatchesTheRecording(array $world): void {
		$this->log = [];
		try {
			$this->store(world: $world)->replace('pub-1', $world['accepted'], 'dossiq');
			$this->log[] = ['returned'];
		} catch (\Throwable $e) {
			$this->log[] = ['threw', get_class($e), $e->getMessage()];
		}

		$recorded = [];
		if (file_exists(self::FIXTURE) === true) {
			$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		$name = (string)$this->dataName();
		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$name] = $this->log;
			if (is_dir(dirname(self::FIXTURE)) === false) {
				mkdir(dirname(self::FIXTURE));
			}

			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $recorded, 'No recording for ' . $name);
		$this->assertSame($recorded[$name], json_decode((string)json_encode($this->log), true));

	}//end testReplaceMatchesTheRecording()

	/**
	 * The store of a world.
	 *
	 * @param array<string, mixed> $world The world.
	 *
	 * @return WithheldDocumentStore
	 */
	private function store(array $world): WithheldDocumentStore {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default): string => match ($key) {
				'publication_register' => ($world['register'] ?? '5'),
				'withheld_document_schema' => ($world['schema'] ?? '9'),
				default => $default,
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->objects(world: $world));

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(($world['installed'] ?? true));

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['error', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->log[] = [$level, $message, $context];
				}
			);
		}

		return new WithheldDocumentStore($config, new OpenRegisterLookup($container, $appManager), $logger);

	}//end store()

	/**
	 * An ObjectService that records its calls.
	 *
	 * @param array<string, mixed> $world The world.
	 *
	 * @return object
	 */
	private function objects(array $world): object {
		$log = &$this->log;
		return new class($world, $log) {

			private int $saves = 0;

			public function __construct(private array $world, private array &$log) {
			}

			public function searchObjectsPaginated(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->log[] = ['search', $query, $_rbac, $_multitenancy];
				if (($this->world['searchFails'] ?? false) === true) {
					throw new RuntimeException('search down');
				}

				if (array_key_exists('search', $this->world) === true) {
					return $this->world['search'];
				}

				$rows = ($this->world['rows'] ?? []);
				if (($this->world['entities'] ?? false) === true) {
					$rows = array_map(static fn (array $row): object => new class($row) implements \JsonSerializable {
						public function __construct(private array $row) {
						}

						public function jsonSerialize(): array {
							return $this->row;
						}
					}, $rows);
				}

				return ['results' => $rows];
			}

			public function saveObject(array $object, $register = null, $schema = null, bool $_rbac = true, bool $_multitenancy = true): mixed {
				$this->saves++;
				$object['recordedAt'] = (preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d$/', (string)$object['recordedAt']) === 1) ? '<now>' : $object['recordedAt'];
				$this->log[] = ['save', $object, $register, $schema, $_rbac, $_multitenancy];
				if ($this->saves === ($this->world['failSaveAt'] ?? 0)) {
					throw new RuntimeException('OpenRegister is down');
				}

				$id = (array_key_exists('ids', $this->world) === true) ? ($this->world['ids'][$this->saves - 1] ?? null) : 'w-' . $this->saves;
				if ($id === 'object') {
					return new class('e-' . $this->saves) implements \JsonSerializable {
						public function __construct(private string $id) {
						}

						public function jsonSerialize(): array {
							return ['id' => $this->id];
						}
					};
				}

				return array_filter(array_merge($object, ['id' => $id]), static fn ($value): bool => $value !== null);
			}

			public function deleteObject(string $uuid, $register = null, $schema = null, bool $_rbac = true, bool $_multitenancy = true): bool {
				$this->log[] = ['delete', $uuid, $register, $schema, $_rbac, $_multitenancy];
				if (in_array($uuid, ($this->world['failDelete'] ?? []), true) === true) {
					throw new RuntimeException('delete of ' . $uuid . ' failed');
				}

				return true;
			}
		};

	}//end objects()
}//end class
