<?php

/**
 * Golden test for the read-rule backfill repair step.
 *
 * Every case is recorded once and replayed from the fixture: each output line,
 * each authorization written, each schema update and each log entry.
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

namespace Unit\Repair;

use OCA\OpenCatalogi\Repair\WOO536RepairReadRules;
use OCP\App\IAppManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Golden test for the read-rule backfill repair step.
 *
 * @covers \OCA\OpenCatalogi\Repair\WOO536RepairReadRules
 * @uses   \OCA\OpenCatalogi\Repair\SchemaRepair
 */
class ReadRulesRepairGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/read-rules-repair-golden.json';

	/**
	 * Everything that happened, in order.
	 *
	 * @var array<int, mixed>
	 */
	private array $log = [];

	/**
	 * The recorded cases: the schemas OpenRegister holds, and how it behaves.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$single = ['read' => [['group' => 'public', 'match' => ['publicationDate' => ['$lte' => '$now']]], 'authenticated']];
		$dutch = ['read' => [['group' => 'public', 'match' => ['publicatiedatum' => ['$lte' => '$now']]]], 'update' => [['group' => 'x', 'match' => ['depublicatiedatum' => 1, 'other' => 2]]]];
		$both = ['read' => [['group' => 'public', 'match' => ['publicatiedatum' => ['$lte' => 'x'], 'publicationDate' => ['$lte' => '$now']]]]];
		$custom = ['read' => [['group' => 'public', 'match' => ['publicationDate' => ['$lte' => '$now']]], ['group' => 'public', 'match' => ['a' => 1]]]];
		$odd = ['read' => 'public', 'write' => ['plain', ['match' => 'x'], ['nomatch' => 1]]];

		return [
			'every shape' => [[
				'schemas' => [
					['publication', 1, $single],
					['document', 2, $dutch],
					['document', 3, $both],
					['publication', 4, $custom],
					['publication', 5, null],
					['catalog', 6, null],
					['catalog', 7, $dutch],
					['catalog', 8, $single],
					['publication', 9, $odd],
					['publication', 10, ['read' => [['group' => 'other', 'match' => ['publicationDate' => ['$lte' => '$now']]]]]],
					['publication', 11, ['read' => [['group' => 'public', 'match' => ['publicationDate' => ['$lte' => '$now', '$gte' => 'x']]]]]],
					['publication', 12, ['read' => [5]]],
				],
			]],
			'update fails' => [['schemas' => [['publication', 1, $single], ['document', 2, $dutch]], 'update' => 'throw']],
			'openregister disabled' => [['enabled' => false, 'schemas' => [['publication', 1, $single]]]],
			'mapper unavailable' => [['mapper' => 'throw', 'schemas' => []]],
			'no schemas' => [['schemas' => []]],
		];

	}//end cases()

	/**
	 * Replay a case and compare it to the recording.
	 *
	 * @param array<string, mixed> $world The world.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testRepairMatchesTheRecording(array $world): void {
		$name = $this->dataName();
		$this->step(world: $world)->run($this->recordingOutput());
		$actual = $this->log;

		$recorded = [];
		if (file_exists(self::FIXTURE) === true) {
			$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$name] = $actual;
			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
		}

		$this->assertArrayHasKey($name, $recorded, 'No recording for ' . $name);
		$this->assertSame($recorded[$name], $actual);

	}//end testRepairMatchesTheRecording()

	/**
	 * An output that records every line.
	 *
	 * @return IOutput The output.
	 */
	private function recordingOutput(): IOutput {
		$output = $this->createMock(IOutput::class);
		foreach (['info', 'warning'] as $level) {
			$output->method($level)->willReturnCallback(
				function (string $message) use ($level): void {
					$this->log[] = [$level => $message];
				}
			);
		}

		return $output;

	}//end recordingOutput()

	/**
	 * Build the step over the world.
	 *
	 * @param array<string, mixed> $world The world.
	 *
	 * @return WOO536RepairReadRules The step.
	 */
	private function step(array $world): WOO536RepairReadRules {
		$log = &$this->log;
		$schemas = [];
		foreach ($world['schemas'] as [$slug, $id, $authorization]) {
			$schemas[] = new class($log, $slug, $id, $authorization) {
				public function __construct(
					private array &$log,
					private string $slug,
					private int $id,
					private ?array $authorization,
				) {
				}

				public function getSlug(): string {
					return $this->slug;
				}

				public function getId(): int {
					return $this->id;
				}

				public function getAuthorization(): ?array {
					return $this->authorization;
				}

				public function setAuthorization(array $authorization): void {
					$this->log[] = ['set' => $this->id, 'authorization' => $authorization];
					$this->authorization = $authorization;
				}
			};
		}

		$mapper = new class($log, $schemas, ($world['update'] ?? 'ok')) {
			public function __construct(
				private array &$log,
				private array $schemas,
				private string $update,
			) {
			}

			public function findAll(): array {
				return $this->schemas;
			}

			public function update(object $schema): object {
				$this->log[] = ['update' => $schema->getId()];
				if ($this->update === 'throw') {
					throw new \RuntimeException('database locked');
				}

				return $schema;
			}
		};

		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForAnyone')->willReturn(($world['enabled'] ?? true));
		$container = $this->createMock(ContainerInterface::class);
		if (($world['mapper'] ?? 'ok') === 'throw') {
			$container->method('get')->willThrowException(new \RuntimeException('no mapper'));
		} else {
			$container->method('get')->willReturn($mapper);
		}

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->log[] = ['logger' => $message, 'context' => $context];
			}
		);

		return new WOO536RepairReadRules($apps, $container, $logger);

	}//end step()
}//end class
