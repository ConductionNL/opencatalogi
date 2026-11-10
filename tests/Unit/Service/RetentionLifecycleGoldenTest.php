<?php

/**
 * Golden test for the RetentionService steps without a golden of their own:
 * applyDefaultsAtPublication, setRetentionDefaults, computeExpiry and
 * renderReportCsv.
 *
 * Every case is recorded once and replayed from the fixture: the outcome (or
 * the exception), the OpenRegister calls made, by parameter name, and the log
 * lines written.
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
use OCA\OpenRegister\Service\Object\ObjectSaveOptions;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden test for the retention lifecycle steps.
 *
 * @covers \OCA\OpenCatalogi\Service\RetentionService
 * @uses \OCA\OpenCatalogi\Service\RetentionRules
 * @uses   \OCA\OpenCatalogi\Service\ReadsObjectProperties
 * @uses   \OCA\OpenCatalogi\Service\RetentionPolicyTable
 * @uses   \OCA\OpenCatalogi\Service\RetentionRun
 * @uses   \OCA\OpenCatalogi\Service\BomCsv
 */
class RetentionLifecycleGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/retention-lifecycle-golden.json';

	/**
	 * Every OpenRegister call and log line, in order.
	 *
	 * @var array<int, mixed>
	 */
	private array $log = [];

	/**
	 * The recorded cases.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$self = ['uuid' => 'pub-1', 'register' => '11', 'schema' => '12'];
		$published = ['@self' => $self, 'title' => 'Kapvergunning', 'publicationDate' => '2026-06-11T00:00:00+00:00', 'retentionCategory' => 'besluit'];
		$defaults = ['setRetentionDefaults', ['vergunningen' => ['besluit' => ['termMonths' => 24, 'action' => 'archive'], 'x' => 'not a rule', 'y' => ['termMonths' => 3]]]];

		return [
			'publication: stamped' => [['call' => ['applyDefaultsAtPublication', $published]]],
			'publication: officer values kept' => [['call' => ['applyDefaultsAtPublication', $published + ['retentionTermMonths' => 60, 'retentionAction' => 'review']]]],
			'publication: fully stamped already' => [['call' => ['applyDefaultsAtPublication', $published + ['retentionTermMonths' => 60, 'retentionAction' => 'review', 'retentionExpiresAt' => '2031-01-01T00:00:00+00:00']]]],
			'publication: other register' => [['call' => ['applyDefaultsAtPublication', ['@self' => ['register' => '99', 'schema' => '12']] + $published]]],
			'publication: other schema' => [['call' => ['applyDefaultsAtPublication', ['@self' => ['register' => '11', 'schema' => '99']] + $published]]],
			'publication: envelope not an array' => [['call' => ['applyDefaultsAtPublication', ['@self' => 'x'] + $published]]],
			'publication: no envelope' => [['call' => ['applyDefaultsAtPublication', ['publicationDate' => '2026-06-11']]]],
			'publication: not published yet' => [['call' => ['applyDefaultsAtPublication', ['publicationDate' => ''] + $published]]],
			'publication: no OpenRegister' => [['call' => ['applyDefaultsAtPublication', $published], 'openregister' => false]],
			'publication: register unconfigured' => [['call' => ['applyDefaultsAtPublication', $published], 'store' => ['publication_register' => '']]],
			'publication: catalog register unconfigured' => [['call' => ['applyDefaultsAtPublication', $published], 'store' => ['catalog_register' => '']]],
			'publication: no catalog lists it' => [['call' => ['applyDefaultsAtPublication', $published], 'catalogs' => [['slug' => 'other', 'registers' => [11], 'schemas' => [13]]]]],
			'publication: catalog search fails' => [['call' => ['applyDefaultsAtPublication', $published], 'searchFails' => true]],
			'publication: save fails' => [['call' => ['applyDefaultsAtPublication', $published], 'saveFails' => true]],
			'publication: id from the envelope id' => [['call' => ['applyDefaultsAtPublication', ['@self' => ['id' => 'id-2', 'register' => '11', 'schema' => '12']] + $published]]],
			'publication: payload id kept' => [['call' => ['applyDefaultsAtPublication', ['id' => 'own'] + $published]]],
			'publication: no id anywhere' => [['call' => ['applyDefaultsAtPublication', ['@self' => ['register' => '11', 'schema' => '12']] + $published]]],
			'defaults: valid' => [['call' => $defaults]],
			'defaults: catalog not a map' => [['call' => ['setRetentionDefaults', ['vergunningen' => 'x']]]],
			'defaults: unknown action' => [['call' => ['setRetentionDefaults', ['a' => ['b' => ['action' => 'shred']], 'c' => 'never reached']]]],
			'defaults: numeric keys' => [['call' => ['setRetentionDefaults', [0 => [1 => ['action' => 'Archive']]]]]],
			'defaults: empty' => [['call' => ['setRetentionDefaults', []]]],
			'expiry: twelve months' => [['call' => ['computeExpiry', '2026-06-11T00:00:00+00:00', 12]]],
			'expiry: month end' => [['call' => ['computeExpiry', '2026-01-31T10:00:00+02:00', 1]]],
			'expiry: no date' => [['call' => ['computeExpiry', null, 12]]],
			'expiry: empty date' => [['call' => ['computeExpiry', '', 12]]],
			'expiry: no term' => [['call' => ['computeExpiry', '2026-06-11', null]]],
			'expiry: zero term' => [['call' => ['computeExpiry', '2026-06-11', 0]]],
			'expiry: negative term' => [['call' => ['computeExpiry', '2026-06-11', -1]]],
			'expiry: unreadable date' => [['call' => ['computeExpiry', 'not a date', 3]]],
			'csv: rows' => [['call' => ['renderReportCsv', [['id' => 'p1', 'title' => 'A;"b"', 'status' => 'archived'], ['decisionNote' => 'n', 'extra' => 'dropped']]]]],
			'csv: no rows' => [['call' => ['renderReportCsv', []]]],
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
	public function testStepMatchesTheRecording(array $case): void {
		$this->log = [];
		$service = $this->service(world: $case);
		$method = array_shift($case['call']);
		try {
			$outcome = ['returned' => $service->{$method}(...$case['call'])];
		} catch (\Throwable $e) {
			$outcome = ['threw' => get_class($e), 'message' => $e->getMessage()];
		}

		$actual = json_decode((string)json_encode(['outcome' => $outcome, 'calls' => $this->log]), true);

		$recorded = [];
		if (file_exists(self::FIXTURE) === true) {
			$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		$name = (string)$this->dataName();
		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$name] = $actual;
			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
		}

		$this->assertArrayHasKey($name, $recorded, 'No recording for ' . $name);
		$this->assertSame($recorded[$name], $actual);

	}//end testStepMatchesTheRecording()

	/**
	 * The service around the case's configuration and OpenRegister.
	 *
	 * @param array<string, mixed> $world The case.
	 *
	 * @return RetentionService
	 */
	private function service(array $world): RetentionService {
		$store = ($world['store'] ?? []) + [
			'publication_register' => '11',
			'publication_schema' => '12',
			'catalog_register' => '1',
			'catalog_schema' => '2',
			'retention_defaults' => json_encode(['vergunningen' => ['besluit' => ['termMonths' => 12, 'action' => 'depublish']]]),
		];
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = '') => ($store[$key] ?? $default)
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value) use (&$store): bool {
				$this->log[] = ['setValueString', $app, $key, $value];
				$store[$key] = $value;
				return true;
			}
		);

		$objects = $this->objectService(world: $world);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $class) use ($objects, $world): object {
				if ($class === 'OCA\OpenRegister\Service\Dmn\DecisionTableEvaluator') {
					return new \OCA\OpenRegister\Service\Dmn\DecisionTableEvaluator();
				}

				if (($world['openregister'] ?? true) === false) {
					throw new RuntimeException('no OpenRegister');
				}

				return $objects;
			}
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('officer1');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['error', 'warning', 'info', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message) use ($level): void {
					$this->log[] = [$level, $message];
				}
			);
		}

		return new RetentionService($config, $container, $session, $logger);
	}//end service()

	/**
	 * A duck-typed ObjectService that logs every call by parameter name.
	 *
	 * @param array<string, mixed> $world The case.
	 *
	 * @return object
	 */
	private function objectService(array $world): object {
		$log = function (array $entry): void {
			$this->log[] = $entry;
		};
		$catalogs = ($world['catalogs'] ?? [
			['slug' => 'leeg', 'registers' => [], 'schemas' => []],
			['slug' => 'vergunningen', 'registers' => ['11'], 'schemas' => [12]],
		]);

		return new class($log, $catalogs, $world) {
			public function __construct(
				private \Closure $log,
				private array $catalogs,
				private array $world,
			) {
			}

			public function searchObjectsPaginated(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
				($this->log)(['searchObjectsPaginated', ['query' => $query, '_rbac' => $_rbac, '_multitenancy' => $_multitenancy]]);
				if (($this->world['searchFails'] ?? false) === true) {
					throw new RuntimeException('search down');
				}

				return ['results' => $this->catalogs];
			}

			public function saveObject(array $object, ObjectSaveOptions $options = new ObjectSaveOptions(), bool $_rbac = true, bool $_multitenancy = true) {
				$register = $options->target->register;
				$schema   = $options->target->schema;
				$uuid     = $options->uuid;
				($this->log)(['saveObject', ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid, '_rbac' => $_rbac, '_multitenancy' => $_multitenancy]]);
				if (($this->world['saveFails'] ?? false) === true) {
					throw new RuntimeException('save failed');
				}

				return $object + ['saved' => true];
			}
		};

	}//end objectService()
}//end class
