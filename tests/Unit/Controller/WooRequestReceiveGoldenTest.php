<?php

/**
 * Golden test for WooRequestController::receive().
 *
 * Every case receives one request over recording doubles and compares the
 * answer (status and body) and every call, in order, to the recording in
 * fixtures/woo-request-receive-golden.json.
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
use OCA\OpenCatalogi\Contract\StatutoryTermInterface;
use OCA\OpenCatalogi\Contract\WooRequestServiceInterface;
use OCA\OpenCatalogi\Contract\WooRequestStoreInterface;
use OCA\OpenCatalogi\Controller\WooRequestController;
use OCA\OpenCatalogi\Service\Woo\TermEngineUnavailableException;
use OCA\OpenCatalogi\Service\Woo\TermRefusedException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Golden test for receiving a Woo request.
 *
 * @covers \OCA\OpenCatalogi\Controller\WooRequestController
 */
class WooRequestReceiveGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/woo-request-receive-golden.json';

	/**
	 * Everything asked, in order.
	 *
	 * @var array<int, mixed>
	 */
	private array $calls = [];

	/**
	 * The recorded cases: request parameters, the session user and what fails.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$form = ['requestedInformation' => 'Besluiten 2025', 'requesterName' => 'Jan', 'requesterEmail' => 'jan@example.nl'];

		return [
			'received and armed' => [['params' => $form]],
			'a forged receivedBy and actor are ignored' => [['params' => $form + ['receivedBy' => 'mallory', 'actor' => 'mallory', 'channel' => 'post']]],
			'not logged in' => [['user' => false, 'params' => $form]],
			'refused' => [['fail' => 'refuse', 'params' => $form]],
			'first save fails' => [['fail' => 'save1', 'params' => $form]],
			'saved without id or dates' => [['saved' => [], 'params' => $form]],
			'term engine unavailable' => [['fail' => 'engine', 'params' => $form]],
			'term refused' => [['fail' => 'term', 'params' => $form]],
			'term throws something else' => [['fail' => 'termOther', 'params' => $form]],
			'second save fails' => [['fail' => 'save2', 'params' => $form]],
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
	public function testReceiveMatchesTheRecording(array $case): void {
		$name = $this->dataName();
		try {
			$response = $this->controller(case: $case)->receive();
			$actual = ['status' => $response->getStatus(), 'data' => $response->getData(), 'calls' => $this->calls];
		} catch (\Throwable $e) {
			$actual = ['thrown' => get_class($e) . ': ' . $e->getMessage(), 'calls' => $this->calls];
		}

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

	}//end testReceiveMatchesTheRecording()

	/**
	 * The controller over recording doubles for one case.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return WooRequestController The controller.
	 */
	private function controller(array $case): WooRequestController {
		$params = $case['params'];
		$fail = ($case['fail'] ?? null);
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default));

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('ambtenaar');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn((($case['user'] ?? true) === true) ? $user : null);

		$requests = $this->createMock(WooRequestServiceInterface::class);
		$requests->method('receive')->willReturnCallback(
			function (array $input, string $receivedBy, ?DateTimeInterface $now = null) use ($fail): array {
				$this->calls[] = ['receive' => [$input, $receivedBy]];
				if ($fail === 'refuse') {
					throw new \DomainException('no information asked');
				}

				return ['reference' => 'WOO-1', 'receivedBy' => $receivedBy];
			}
		);
		$requests->method('receipt')->willReturnCallback(
			function (array $request): array {
				$this->calls[] = ['receipt' => $request];
				return ['reference' => ($request['reference'] ?? null), 'due' => ($request['term']['due'] ?? null)];
			}
		);
		$requests->method('withTerm')->willReturnCallback(
			function (array $request, array $term): array {
				$this->calls[] = ['withTerm' => [$request, $term]];
				return $request + ['term' => $term];
			}
		);

		$saves = 0;
		$store = $this->createMock(WooRequestStoreInterface::class);
		$store->method('save')->willReturnCallback(
			function (array $record, string $uuid = '') use ($fail, $case, &$saves): array {
				$saves++;
				$this->calls[] = ['save' => [$record, $uuid]];
				if ($fail === 'save' . $saves) {
					throw new RuntimeException('Register configuration is not set for woo_request_register');
				}

				if ($saves === 1 && array_key_exists('saved', $case) === true) {
					return $case['saved'];
				}

				return $record + ['id' => 'req-1', 'receivedAt' => '2026-03-02T10:00:00+00:00'];
			}
		);

		$terms = $this->createMock(StatutoryTermInterface::class);
		$terms->method('arm')->willReturnCallback(
			function (string $requestUuid, string $reference, DateTimeInterface $receivedAt, ?string $actor) use ($fail): array {
				$this->calls[] = ['arm' => [$requestUuid, $reference, ($requestUuid === '' ? 'now' : $receivedAt->format('c')), $actor]];
				if ($fail === 'engine') {
					throw new TermEngineUnavailableException('engine down');
				}

				if ($fail === 'term') {
					throw new TermRefusedException('no working days');
				}

				if ($fail === 'termOther') {
					throw new RuntimeException('unexpected');
				}

				return ['timer' => 't-1', 'due' => '2026-04-01'];
			}
		);

		return new WooRequestController('opencatalogi', $request, $l10n, $session, $requests, $store, $terms);
	}//end controller()
}//end class
