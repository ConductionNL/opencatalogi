<?php

/**
 * Pins how WooController::createBatch() reads its posted case, documents and
 * board, and what it answers.
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

use OCA\OpenCatalogi\Controller\WooController;
use OCA\OpenCatalogi\Service\WooService;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for WooController::createBatch().
 */
class WooCreateBatchTest extends TestCase {

	/**
	 * Posted values, what the service is asked, and the answer.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<int, mixed>|null, 2: int}>
	 */
	public static function posts(): array {
		return [
			'a case with a board as text' => [['caseReference' => 'Z-1', 'documents' => ['d-1'], 'boardId' => '7'], ['Z-1', ['d-1'], 7], 201],
			'a board as a number' => [['caseReference' => 'Z-1', 'boardId' => 9], ['Z-1', [], 9], 201],
			'no board' => [['caseReference' => 'Z-1', 'documents' => 'd-1'], ['Z-1', ['d-1'], null], 201],
			'a board that is no number' => [['caseReference' => 'Z-1', 'boardId' => 'x'], ['Z-1', [], 0], 201],
			'no case' => [['documents' => ['d-1'], 'boardId' => '7'], null, 400],
			'an empty case' => [['caseReference' => ''], null, 400],
		];

	}//end posts()

	/**
	 * The post is read, checked and handed to the service.
	 *
	 * @param array<string, mixed> $post The posted values.
	 * @param array<int, mixed>|null $asked What the service is asked, or null when it is not.
	 * @param int $status The answer's status.
	 *
	 * @return void
	 */
	#[DataProvider('posts')]
	public function testThePostIsReadAndHandedToTheService(array $post, ?array $asked, int $status): void {
		$calls = [];
		$service = $this->createMock(WooService::class);
		$service->method('createBatch')->willReturnCallback(
			static function (string $caseReference, array $documents, ?int $boardId) use (&$calls): array {
				$calls[] = [$caseReference, $documents, $boardId];
				return ['id' => 'b-1'];
			}
		);

		$response = $this->controller(post: $post, service: $service)->createBatch();

		$this->assertSame($status, $response->getStatus());
		$this->assertSame($asked === null ? [] : [$asked], $calls);
		if ($status === 400) {
			$this->assertSame(['error' => 'caseReference is required'], $response->getData());
		}
	}//end testThePostIsReadAndHandedToTheService()

	/**
	 * A failing service answers 400 with its message.
	 *
	 * @return void
	 */
	public function testAFailingServiceAnswers400WithItsMessage(): void {
		$service = $this->createMock(WooService::class);
		$service->method('createBatch')->willThrowException(new RuntimeException('board closed'));

		$response = $this->controller(post: ['caseReference' => 'Z-1'], service: $service)->createBatch();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => 'board closed'], $response->getData());
	}//end testAFailingServiceAnswers400WithItsMessage()

	/**
	 * The controller over a request carrying the post.
	 *
	 * @param array<string, mixed> $post The posted values.
	 * @param WooService $service The Woo service.
	 *
	 * @return WooController
	 */
	private function controller(array $post, WooService $service): WooController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($post[$key] ?? $default)
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$categories = new \OCA\OpenCatalogi\Service\Woo\WooCategoryRegistry(
			new \OCA\OpenCatalogi\Service\TooiVocabularyService(),
			$this->createMock(\OCA\OpenCatalogi\Service\SettingsService::class),
			$this->createMock(\Psr\Log\LoggerInterface::class),
			new \OCA\OpenCatalogi\Service\Woo\LocalCategoryAdmission(new \OCA\OpenCatalogi\Service\TooiVocabularyService()),
		);

		return new WooController('opencatalogi', $request, $service, $l10n, $this->createMock(IUserSession::class), $categories);
	}//end controller()
}//end class
