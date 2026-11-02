<?php

/**
 * Golden test for the opencatalogi.getPublication MCP tool.
 *
 * Every case invokes the tool over recording doubles and compares the answer,
 * the service calls and the log lines to the recording in
 * fixtures/get-publication-tool-golden.json.
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

namespace Unit\Mcp;

use OCA\OpenCatalogi\Contract\PublicationServiceInterface;
use OCA\OpenCatalogi\Mcp\OpenCatalogiToolProvider;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden test for the opencatalogi.getPublication MCP tool.
 *
 * @covers \OCA\OpenCatalogi\Mcp\OpenCatalogiToolProvider
 */
class GetPublicationToolGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/get-publication-tool-golden.json';

	/**
	 * The recorded cases: arguments, what show() and attachments() answer, and the session user.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$pub = ['id' => 'p-1', 'title' => 'Besluit', '@self' => ['uuid' => 'p-1']];
		$files = ['results' => [['name' => 'a.pdf'], ['name' => 'b.pdf']]];

		return [
			'found with attachments' => [['args' => ['id' => ' p-1 '], 'show' => [200, $pub], 'attachments' => [200, $files], 'user' => 'alice']],
			'attachments under files' => [['args' => ['id' => 'p-1'], 'show' => [200, $pub], 'attachments' => [200, ['files' => [['name' => 'c.pdf']]]]]],
			'attachments a plain list' => [['args' => ['id' => 'p-1'], 'show' => [200, $pub], 'attachments' => [200, [['name' => 'd.pdf']]]]],
			'attachments refused' => [['args' => ['id' => 'p-1'], 'show' => [200, $pub], 'attachments' => [403, ['error' => 'no']]]],
			'attachments throw' => [['args' => ['id' => 'p-1'], 'show' => [200, $pub], 'attachments' => 'throw', 'user' => 'alice']],
			'no id' => [['args' => []]],
			'id not a string' => [['args' => ['id' => 5]]],
			'blank id' => [['args' => ['id' => '  ']]],
			'show throws, anonymous' => [['args' => ['id' => 'p-1'], 'show' => 'throw']],
			'show throws, for a user' => [['args' => ['id' => 'p-1'], 'show' => 'throw', 'user' => 'bob']],
			'not found without body' => [['args' => ['id' => 'p-1'], 'show' => [404, []]]],
			'not found with error' => [['args' => ['id' => 'p-1'], 'show' => [404, ['error' => 'Gone']]]],
			'forbidden with error' => [['args' => ['id' => 'p-1'], 'show' => [403, ['error' => 'Denied']]]],
			'forbidden without error is answered' => [['args' => ['id' => 'p-1'], 'show' => [403, ['title' => 'x']], 'attachments' => [200, []]]],
			'server error with error' => [['args' => ['id' => 'p-1'], 'show' => [500, ['error' => 'Boom']]]],
			'error key on a 200 is answered' => [['args' => ['id' => 'p-1'], 'show' => [200, ['error' => 'odd', 'id' => 'p-1']], 'attachments' => [200, []]]],
			'error key on a 399 is answered' => [['args' => ['id' => 'p-1'], 'show' => [399, ['error' => 'odd']], 'attachments' => [200, []]]],
			'error key on a 400' => [['args' => ['id' => 'p-1'], 'show' => [400, ['error' => 7]]]],
			'show answers a string' => [['args' => ['id' => 'p-1'], 'show' => [200, 'text'], 'attachments' => [200, []]]],
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
	public function testGetPublicationMatchesTheRecording(array $case): void {
		$name = $this->dataName();
		$actual = $this->invoke(case: $case);

		$recorded = [];
		if (is_file(self::FIXTURE) === true) {
			$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$name] = $actual;
			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $recorded, 'No recording for ' . $name);
		$this->assertSame($recorded[$name], $actual);

	}//end testGetPublicationMatchesTheRecording()

	/**
	 * Invoke the tool for one case over recording doubles.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<string, mixed> The answer and every call and log line.
	 */
	private function invoke(array $case): array {
		$calls = [];
		$publications = $this->createMock(PublicationServiceInterface::class);
		foreach (['show', 'attachments'] as $method) {
			$publications->method($method)->willReturnCallback(
				static function (...$args) use (&$calls, $case, $method): JSONResponse {
					$calls[] = [$method, $args];
					$answer = ($case[$method] ?? [200, []]);
					if ($answer === 'throw') {
						throw new RuntimeException($method . ' down');
					}

					return new JSONResponse($answer[1], $answer[0]);
				}
			);
		}

		$session = $this->createMock(IUserSession::class);
		$user = null;
		if (isset($case['user']) === true) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($case['user']);
		}

		$session->method('getUser')->willReturn($user);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning', 'error', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				static function (string $message, array $context = []) use (&$calls, $level): void {
					$calls[] = [$level, $message, $context];
				}
			);
		}

		$provider = new OpenCatalogiToolProvider(publicationService: $publications, userSession: $session, logger: $logger);
		$answer = $provider->invokeTool('opencatalogi.getPublication', $case['args']);

		return json_decode((string)json_encode(['answer' => $answer, 'calls' => $calls]), true);
	}//end invoke()
}//end class
