<?php

/**
 * Golden test for RetentionService::applyDefaults.
 *
 * Every world sets a catalog's retention defaults and a publication, runs the
 * defaults once and records the publication returned, the decision tables
 * evaluated and the warnings logged.
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

use OCA\OpenCatalogi\Service\RetentionService;
use OCP\IAppConfig;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Golden test for RetentionService::applyDefaults.
 *
 * @covers \OCA\OpenCatalogi\Service\RetentionService
 * @uses \OCA\OpenCatalogi\Service\RetentionPolicyTable
 */
class RetentionApplyDefaultsGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/retention-apply-defaults-golden.json';

	/**
	 * What the doubles were asked.
	 *
	 * @var array<string, list<mixed>>
	 */
	private array $log = [];

	/**
	 * The recorded worlds.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function worlds(): array {
		$defaults = [
			'open' => [
				'besluit' => ['termMonths' => 24, 'action' => 'depublish'],
				'advies' => ['termMonths' => '6'],
				'brief' => ['action' => 'archive'],
				'raar' => ['action' => 'shred'],
				'leeg' => [],
				'kapot' => 'not a rule',
				'vreemd' => ['termMonths' => 1],
				'_fallback' => ['termMonths' => 12, 'action' => 'review'],
			],
			'strict' => ['besluit' => ['termMonths' => 3]],
			'scalar' => 'nope',
			'nothing' => ['x' => 'not a rule'],
		];
		$published = ['title' => 'P', 'publicationDate' => '2026-01-31T10:00:00+00:00'];

		return [
			'no defaults configured' => [['defaults' => null, 'slug' => 'open', 'publication' => $published + ['retentionCategory' => 'besluit']]],
			'defaults not json' => [['defaults' => '{bad', 'slug' => 'open', 'publication' => $published]],
			'unknown catalog' => [['defaults' => $defaults, 'slug' => 'other', 'publication' => $published]],
			'catalog defaults not an array' => [['defaults' => $defaults, 'slug' => 'scalar', 'publication' => $published]],
			'no usable rule' => [['defaults' => $defaults, 'slug' => 'nothing', 'publication' => $published]],
			'no evaluator' => [['defaults' => $defaults, 'slug' => 'open', 'publication' => $published + ['retentionCategory' => 'besluit'], 'evaluator' => false]],
			'category matched' => [['defaults' => $defaults, 'slug' => 'open', 'publication' => $published + ['retentionCategory' => 'besluit']]],
			'term only, numeric string' => [['defaults' => $defaults, 'slug' => 'open', 'publication' => $published + ['retentionCategory' => 'advies']]],
			'action only, no term' => [['defaults' => $defaults, 'slug' => 'open', 'publication' => $published + ['retentionCategory' => 'brief']]],
			'unknown action' => [['defaults' => $defaults, 'slug' => 'open', 'publication' => $published + ['retentionCategory' => 'raar']]],
			'empty rule' => [['defaults' => $defaults, 'slug' => 'open', 'publication' => $published + ['retentionCategory' => 'leeg']]],
			'fallback' => [['defaults' => $defaults, 'slug' => 'open', 'publication' => $published + ['retentionCategory' => 'onbekend']]],
			'no category, fallback' => [['defaults' => $defaults, 'slug' => 'open', 'publication' => $published]],
			'no rule matched' => [['defaults' => $defaults, 'slug' => 'strict', 'publication' => $published + ['retentionCategory' => 'advies']]],
			'evaluation fails' => [['defaults' => $defaults, 'slug' => 'open', 'publication' => $published + ['retentionCategory' => 'besluit'], 'evaluator' => 'fails']],
			'outputs not an array' => [['defaults' => $defaults, 'slug' => 'open', 'publication' => $published + ['retentionCategory' => 'vreemd'], 'evaluator' => 'scalar-outputs']],
			'no outputs key' => [['defaults' => $defaults, 'slug' => 'open', 'publication' => $published + ['retentionCategory' => 'vreemd', 'retentionTermMonths' => 2], 'evaluator' => 'no-outputs']],
			'officer values kept' => [['defaults' => $defaults, 'slug' => 'open', 'publication' => $published + ['retentionCategory' => 'besluit', 'retentionTermMonths' => 5, 'retentionAction' => 'review']]],
			'expiry held' => [['defaults' => $defaults, 'slug' => 'open', 'publication' => $published + ['retentionCategory' => 'besluit', 'retentionExpiresAt' => '2030-01-01T00:00:00+00:00']]],
			'zero term kept empty' => [['defaults' => $defaults, 'slug' => 'open', 'publication' => $published + ['retentionCategory' => 'besluit', 'retentionTermMonths' => 0, 'retentionAction' => '']]],
			'no publication date' => [['defaults' => $defaults, 'slug' => 'open', 'publication' => ['retentionCategory' => 'besluit']]],
			'bad publication date' => [['defaults' => $defaults, 'slug' => 'open', 'publication' => ['retentionCategory' => 'besluit', 'publicationDate' => 'gisteren']]],
			'evaluation fails, term set' => [['defaults' => $defaults, 'slug' => 'open', 'publication' => $published + ['retentionCategory' => 'besluit', 'retentionTermMonths' => 2], 'evaluator' => 'fails']],
			'outputs not an array, term set' => [['defaults' => $defaults, 'slug' => 'open', 'publication' => $published + ['retentionCategory' => 'vreemd', 'retentionTermMonths' => 2], 'evaluator' => 'scalar-outputs']],
			'no rule matched, term set' => [['defaults' => $defaults, 'slug' => 'strict', 'publication' => $published + ['retentionCategory' => 'advies', 'retentionTermMonths' => 2]]],
			'term as text' => [['defaults' => $defaults, 'slug' => 'open', 'publication' => $published + ['retentionCategory' => 'besluit'], 'evaluator' => 'text-term']],
			'end of month' => [['defaults' => $defaults, 'slug' => 'open', 'publication' => $published + ['retentionCategory' => 'vreemd']]],
		];

	}//end worlds()

	/**
	 * Apply the world's defaults and compare with the recording.
	 *
	 * @param array<string, mixed> $world The world.
	 *
	 * @return void
	 */
	#[DataProvider('worlds')]
	public function testDefaultsMatchTheRecording(array $world): void {
		$this->log = ['evaluated' => [], 'warnings' => []];
		$result = $this->service(world: $world)->applyDefaults(publication: $world['publication'], catalogSlug: $world['slug']);

		$actual = json_decode((string)json_encode(['publication' => $result, 'log' => $this->log]), true);

		$recorded = [];
		if (file_exists(self::FIXTURE) === true) {
			$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		$name = (string)$this->dataName();
		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$name] = $actual;
			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $recorded, 'No recording for ' . $name);
		$this->assertSame($recorded[$name], $actual);

	}//end testDefaultsMatchTheRecording()

	/**
	 * Build the service around the world's defaults and evaluator.
	 *
	 * @param array<string, mixed> $world The world.
	 *
	 * @return RetentionService
	 */
	private function service(array $world): RetentionService {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static function ($app, $key, $default = '') use ($world) {
				if ($key !== RetentionService::CONFIG_DEFAULTS || $world['defaults'] === null) {
					return $default;
				}

				return is_string($world['defaults']) === true ? $world['defaults'] : (string)json_encode($world['defaults']);
			}
		);

		$record = function (array $evaluation): void {
			$this->log['evaluated'][] = $evaluation;
		};
		$evaluator = new class((string)($world['evaluator'] ?? 'first'), $record) {
			/**
			 * @param string   $mode   How to answer.
			 * @param \Closure $record Records an evaluation.
			 */
			public function __construct(private string $mode, private \Closure $record) {
			}

			/**
			 * Answer with the first matching rule's outputs.
			 *
			 * @param array<string, mixed> $table  The decision table.
			 * @param array<string, mixed> $inputs The inputs.
			 *
			 * @return array<string, mixed>
			 */
			public function evaluate(array $table, array $inputs): array {
				($this->record)(['table' => $table, 'inputs' => $inputs]);
				if ($this->mode === 'fails') {
					throw new \RuntimeException('engine down');
				}

				if ($this->mode === 'scalar-outputs') {
					return ['outputs' => 'x'];
				}

				if ($this->mode === 'text-term') {
					return ['outputs' => ['termMonths' => '7', 'action' => 'archive']];
				}

				if ($this->mode === 'no-outputs') {
					return [];
				}

				foreach ($table['rules'] as $rule) {
					$cell = $rule['inputEntries'][0];
					if ($cell === '-' || $cell === '"' . $inputs['category'] . '"') {
						return ['outputs' => ['termMonths' => $rule['outputEntries'][0], 'action' => $rule['outputEntries'][1]]];
					}
				}

				throw new class('no rule') extends \RuntimeException {
					/**
					 * @return string
					 */
					public function getErrorCode(): string {
						return 'no_rule_matched';
					}
				};
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($world, $evaluator) {
				if (($world['evaluator'] ?? true) === false) {
					throw new \RuntimeException('no ' . $id);
				}

				return $evaluator;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->log['warnings'][] = $message;
			}
		);

		return new RetentionService($config, $container, $this->createMock(IUserSession::class), $logger);

	}//end service()
}//end class
