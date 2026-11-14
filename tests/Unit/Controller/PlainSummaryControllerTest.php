<?php

/**
 * Unit tests for PlainSummaryController: each route answers what the drafter
 * decided, with the HTTP status of its refusal (REQ-PPL-002).
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

use OCA\OpenCatalogi\Controller\PlainSummaryController;
use OCA\OpenCatalogi\Service\Publication\PlainLanguageDrafter;
use OCA\OpenCatalogi\Service\Publication\PlainSummaryRefusal;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenCatalogi\Controller\PlainSummaryController
 * @uses \OCA\OpenCatalogi\Service\Publication\PlainSummaryRefusal
 */
class PlainSummaryControllerTest extends TestCase {

	private PlainLanguageDrafter&MockObject $drafter;

	private IRequest&MockObject $request;

	private ?IUser $user;

	/** @var array<string, mixed> */
	private array $params = [];

	protected function setUp(): void {
		$this->drafter = $this->createMock(PlainLanguageDrafter::class);
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getParam')->willReturnCallback(
			fn (string $key, mixed $default = null): mixed => ($this->params[$key] ?? $default)
		);
		$this->user = $this->createMock(IUser::class);
	}

	private function controller(): PlainSummaryController {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(fn (): ?IUser => $this->user);

		return new PlainSummaryController('opencatalogi', $this->request, $this->drafter, $session);
	}

	public function testWithoutAProviderTheDraftRouteAnswers409(): void {
		$this->drafter->method('draft')->willThrowException(new PlainSummaryRefusal('no-provider', 409, 'No text assistant is installed.'));

		$response = $this->controller()->draft('pub-1');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame(['error' => 'no-provider', 'message' => 'No text assistant is installed.'], $response->getData());
	}

	public function testTheDraftRouteAnswersTheTask(): void {
		$this->drafter->expects($this->once())->method('draft')->with('pub-1')->willReturn(['taskId' => 42]);

		$response = $this->controller()->draft('pub-1');

		$this->assertSame(Http::STATUS_ACCEPTED, $response->getStatus());
		$this->assertSame(['taskId' => 42], $response->getData());
	}

	public function testThePollRouteAnswersTheDraftsState(): void {
		$this->drafter->expects($this->once())->method('poll')->with('pub-1', 42)->willReturn(['status' => 'failed', 'error' => 'timed out']);

		$response = $this->controller()->poll('pub-1', '42');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['status' => 'failed', 'error' => 'timed out'], $response->getData());
	}

	public function testThePollRouteRefusesATaskIdThatIsNotANumber(): void {
		$this->drafter->expects($this->never())->method('poll');

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->poll('pub-1', '4x')->getStatus());
	}

	public function testThePollRouteAnswersTheRefusal(): void {
		$this->drafter->method('poll')->willThrowException(new PlainSummaryRefusal('task-not-found', 404));

		$response = $this->controller()->poll('pub-1', '7');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'task-not-found'], $response->getData());
	}

	public function testTheAcceptRoutePassesTheTextLevelAndTask(): void {
		$this->params = ['text' => 'U kreeg geen vergunning.', 'level' => 'B1', 'fromTaskId' => '42'];
		$stored = ['plainSummary' => 'U kreeg geen vergunning.', 'plainSummaryLevel' => 'B1', 'plainSummaryProvenance' => ['generatedBy' => 'ai']];
		$this->drafter->expects($this->once())->method('accept')->with('pub-1', 'U kreeg geen vergunning.', 'B1', 42)->willReturn($stored);

		$response = $this->controller()->accept('pub-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($stored, $response->getData());
	}

	public function testTheAcceptRouteWithoutATaskIsAPersonsText(): void {
		$this->params = ['text' => 'Zelf geschreven.', 'level' => 'A2', 'fromTaskId' => 'not-a-task'];
		$this->drafter->expects($this->once())->method('accept')->with('pub-1', 'Zelf geschreven.', 'A2', null)->willReturn([]);

		$this->controller()->accept('pub-1');
	}

	public function testTheAcceptRouteRefusesAnUpdateWithoutTheRight(): void {
		$this->params = ['text' => 'Tekst.', 'level' => 'B1'];
		$this->drafter->method('accept')->willThrowException(new PlainSummaryRefusal('forbidden', 403, 'You may not change this publication.'));

		$response = $this->controller()->accept('pub-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame('forbidden', $response->getData()['error']);
	}

	public function testTheAcceptRouteRefusesATextThatIsNotAString(): void {
		$this->params = ['text' => ['a'], 'level' => 'B1'];
		$this->drafter->expects($this->never())->method('accept');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller()->accept('pub-1')->getStatus());
	}

	public function testTheAssistantRouteSaysWhetherDraftIsOffered(): void {
		$this->drafter->method('isAvailable')->willReturn(false);

		$this->assertSame(['available' => false], $this->controller()->assistant()->getData());

		$this->user = null;
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller()->assistant()->getStatus());
	}
}
