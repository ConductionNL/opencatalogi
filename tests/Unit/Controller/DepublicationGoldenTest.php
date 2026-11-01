<?php

/**
 * Golden test for DepublicationController::depublish() and ::acknowledgeWithdrawal().
 *
 * Every case replays one request over recording doubles and compares the
 * answer (status and body) and every call, in order, to the recording in
 * fixtures/depublication-golden.json.
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

namespace Unit\Controller;

use DateTimeInterface;
use OCA\OpenCatalogi\Contract\DepublicationServiceInterface;
use OCA\OpenCatalogi\Contract\ServiceCatalogueServiceInterface;
use OCA\OpenCatalogi\Controller\DepublicationController;
use OCA\OpenCatalogi\Service\Catalogue\CatalogueUnreadableException;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Golden test for the depublication actions.
 *
 * @covers \OCA\OpenCatalogi\Controller\DepublicationController
 * @uses   \OCA\OpenCatalogi\Service\ReadsObjectProperties
 */
class DepublicationGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/depublication-golden.json';

	/**
	 * Everything asked, in order.
	 *
	 * @var array<int, mixed>
	 */
	private array $log = [];

	/**
	 * The recorded cases: action, request parameters, the session user and what fails.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$publication = ['id' => 'p-1', 'title' => 'Besluit'];
		$ack = ['depublication' => ' d-1 ', 'channel' => ' plooi ', 'answer' => ' gone '];

		return [
			'depublish' => [['action' => 'depublish', 'params' => ['publication' => $publication, 'channels' => ['plooi', 7], 'reason' => '  wrong  ']]],
			'depublish ignores a forged depublishedBy' => [['action' => 'depublish', 'params' => ['publication' => $publication, 'depublishedBy' => 'mallory', 'requestedBy' => 'mallory']]],
			'depublish without a session user' => [['action' => 'depublish', 'user' => null, 'params' => ['publication' => $publication]]],
			'depublish, channels not a list' => [['action' => 'depublish', 'params' => ['publication' => $publication, 'channels' => 'plooi']]],
			'depublish, no publication' => [['action' => 'depublish', 'params' => []]],
			'depublish, publication not a list' => [['action' => 'depublish', 'params' => ['publication' => 'p-1']]],
			'depublish refused' => [['action' => 'depublish', 'fail' => 'refuse', 'params' => ['publication' => $publication]]],
			'depublish, store unreadable' => [['action' => 'depublish', 'fail' => 'unreadable', 'params' => ['publication' => $publication]]],
			'depublish, store throws' => [['action' => 'depublish', 'fail' => 'save', 'params' => ['publication' => $publication]]],
			'depublish, register not configured' => [['action' => 'depublish', 'fail' => 'config', 'params' => ['publication' => $publication]]],
			'depublish, every channel acknowledged' => [['action' => 'depublish', 'outstanding' => [], 'params' => ['publication' => $publication]]],
			'acknowledge' => [['action' => 'acknowledge', 'params' => $ack]],
			'acknowledge, last channel' => [['action' => 'acknowledge', 'outstanding' => [], 'params' => $ack]],
			'acknowledge, no depublication' => [['action' => 'acknowledge', 'params' => ['channel' => 'plooi']]],
			'acknowledge, no channel' => [['action' => 'acknowledge', 'params' => ['depublication' => 'd-1', 'channel' => '  ']]],
			'acknowledge, unknown channel' => [['action' => 'acknowledge', 'params' => ['depublication' => 'd-1', 'channel' => 'koop']]],
			'acknowledge, withdrawals not lists' => [['action' => 'acknowledge', 'stored' => ['withdrawals' => ['plooi', ['channel' => 'koop']]], 'params' => $ack]],
			'acknowledge, no withdrawals' => [['action' => 'acknowledge', 'stored' => [], 'params' => $ack]],
			'acknowledge, unreadable' => [['action' => 'acknowledge', 'fail' => 'findUnreadable', 'params' => $ack]],
			'acknowledge, not found' => [['action' => 'acknowledge', 'fail' => 'find', 'params' => $ack]],
			'acknowledge, register not configured' => [['action' => 'acknowledge', 'fail' => 'config', 'params' => $ack]],
			'acknowledge, store unreadable' => [['action' => 'acknowledge', 'fail' => 'unreadable', 'params' => $ack]],
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
	public function testActionMatchesTheRecording(array $case): void {
		$name = $this->dataName();
		$controller = $this->controller(case: $case);
		if ($case['action'] === 'depublish') {
			$response = $controller->depublish();
		} else {
			$response = $controller->acknowledgeWithdrawal();
		}

		$data = $response->getData();
		if (isset($data['detail']) === true) {
			$data['detail'] = str_replace(dirname(__DIR__, 3) . '/', '', (string)$data['detail']);
		}

		$actual = ['status' => $response->getStatus(), 'data' => $data, 'log' => $this->log];

		$recorded = [];
		if (file_exists(self::FIXTURE) === true) {
			$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$name] = $actual;
			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $recorded, 'No recording for ' . $name);
		$this->assertSame($recorded[$name], json_decode((string)json_encode($actual), true));

	}//end testActionMatchesTheRecording()

	/**
	 * The controller over recording doubles for one case.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return DepublicationController The controller.
	 */
	private function controller(array $case): DepublicationController {
		$params = ($case['params'] ?? []);
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default));

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$session = $this->createMock(IUserSession::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('ambtenaar');
		$session->method('getUser')->willReturn((array_key_exists('user', $case) === true) ? null : $user);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') use ($case): string {
				$this->log[] = ['config' => $key];
				return (($case['fail'] ?? null) === 'config') ? '' : $key . '-id';
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($config): mixed {
				$this->log[] = ['get' => $id];
				if ($id === IAppConfig::class) {
					return $config;
				}

				throw new RuntimeException('not available: ' . $id);
			}
		);

		$objects = $this->createMock(ServiceCatalogueServiceInterface::class);
		$objects->method('getObjectService')->willReturnCallback(
			function () use ($case): object {
				$this->log[] = ['objects' => 'getObjectService'];
				if (($case['fail'] ?? null) === 'unreadable') {
					throw new CatalogueUnreadableException('store down');
				}

				return $this->store(case: $case);
			}
		);

		return new DepublicationController('opencatalogi', $request, $l10n, $session, $this->service(case: $case), $container, $objects);
	}//end controller()

	/**
	 * An ObjectService double recording finds and saves.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return object The double.
	 */
	private function store(array $case): object {
		$log = &$this->log;

		return new class($log, $case) {
			public function __construct(
				private array &$log,
				private array $case,
			) {
			}

			public function find(string $id, mixed $register = null, mixed $schema = null): array {
				$this->log[] = ['find' => [$id, $register, $schema]];
				if (($this->case['fail'] ?? null) === 'find') {
					throw new RuntimeException('no such object');
				}

				if (($this->case['fail'] ?? null) === 'findUnreadable') {
					throw new CatalogueUnreadableException('store down');
				}

				return ($this->case['stored'] ?? ['id' => $id, 'withdrawals' => [['channel' => 'koop2'], ['channel' => 'plooi']]]);
			}

			public function saveObject(array $object, array $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null): array {
				$this->log[] = ['save' => [$object, $extend, $register, $schema, $uuid]];
				if (($this->case['fail'] ?? null) === 'save') {
					throw new RuntimeException('save broke');
				}

				return $object + ['id' => 'stored-1'];
			}
		};

	}//end store()

	/**
	 * A depublication-service double recording each call.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return DepublicationServiceInterface The double.
	 */
	private function service(array $case): DepublicationServiceInterface {
		$service = $this->createMock(DepublicationServiceInterface::class);
		$service->method('depublish')->willReturnCallback(
			function (array $publication, string $reason, string $depublishedBy, array $channels, ?DateTimeInterface $now = null) use ($case): array {
				$this->log[] = ['depublish' => [$publication, $reason, $depublishedBy, $channels]];
				if (($case['fail'] ?? null) === 'refuse') {
					throw new \DomainException('already depublished');
				}

				return ['publication' => $publication['id'], 'depublishedBy' => $depublishedBy, 'withdrawals' => $channels];
			}
		);
		$service->method('recordAcknowledgement')->willReturnCallback(
			function (array $depublication, string $channel, string $answer, ?DateTimeInterface $now = null): array {
				$this->log[] = ['acknowledge' => [$depublication, $channel, $answer]];
				return $depublication + ['acknowledged' => $channel];
			}
		);
		$service->method('outstandingChannels')->willReturnCallback(
			function (array $depublication) use ($case): array {
				$this->log[] = ['outstanding' => $depublication];
				return ($case['outstanding'] ?? ['koop']);
			}
		);

		return $service;
	}//end service()
}//end class
