<?php

/**
 * Tests for StatsController::top(), the most viewed publications endpoint (ANA-005).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/capability-rows-close-gaps/tasks.md#task-23
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Contract\CatalogiServiceInterface;
use OCA\OpenCatalogi\Contract\UsageCounterServiceInterface;
use OCA\OpenCatalogi\Controller\StatsController;
use OCA\OpenCatalogi\Service\MostViewedPublications;
use OCP\App\IAppManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

#[CoversClass(StatsController::class)]
final class StatsTopTest extends TestCase {

	private MostViewedPublications&MockObject $mostViewed;
	private IUserSession&MockObject $session;
	private LoggerInterface&MockObject $logger;

	/** @var array<string, mixed> */
	private array $params = [];

	protected function setUp(): void {
		$this->mostViewed = $this->createMock(MostViewedPublications::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function controller(): StatsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => ($this->params[$key] ?? $default));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		return new StatsController(
			appName: 'opencatalogi',
			request: $request,
			usageCounterService: $this->createMock(UsageCounterServiceInterface::class),
			catalogiService: $this->createMock(CatalogiServiceInterface::class),
			appManager: $this->createMock(IAppManager::class),
			container: $this->createMock(ContainerInterface::class),
			l10n: $l10n,
			userSession: $this->session,
			logger: $this->logger,
			mostViewed: $this->mostViewed
		);
	}

	private function signedIn(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('officer');
		$this->session->method('getUser')->willReturn($user);
	}

	public function testAnAnonymousCallerIsRefused(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->mostViewed->expects($this->never())->method('top');

		$response = $this->controller()->top();

		$this->assertSame(403, $response->getStatus());
		$this->assertSame(['error' => 'Authentication required.'], $response->getData());
	}

	public function testReturnsTheRankedPublicationsForTheWindow(): void {
		$this->signedIn();
		$this->params = ['from' => '2026-10-01', 'to' => '2026-10-31', 'top' => '5'];
		$rows = [['id' => 'p-b', 'title' => 'Besluit B', 'catalog' => 'woo', 'views' => 9, 'downloads' => 2]];
		$this->mostViewed->expects($this->once())->method('top')->with('2026-10-01', '2026-10-31', 5)->willReturn($rows);

		$response = $this->controller()->top();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['results' => $rows], $response->getData());
	}

	public function testANonPositiveTopFallsBackToTen(): void {
		$this->signedIn();
		$this->params = ['top' => '0'];
		$this->mostViewed->expects($this->once())->method('top')->with(null, null, 10)->willReturn([]);

		$this->assertSame(['results' => []], $this->controller()->top()->getData());
	}

	public function testAFailureAnswers500AndIsLogged(): void {
		$this->signedIn();
		$this->mostViewed->method('top')->willThrowException(new RuntimeException('boom'));
		$this->logger->expects($this->once())->method('error')->with('[StatsController::top] failed', ['error' => 'boom']);

		$response = $this->controller()->top();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'Could not compute usage statistics.'], $response->getData());
	}
}
