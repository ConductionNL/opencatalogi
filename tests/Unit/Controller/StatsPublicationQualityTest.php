<?php

/**
 * Tests for StatsController::publicationQuality() (PQM-001).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/capability-rows-close-gaps/tasks.md#task-2.5
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Contract\CatalogiServiceInterface;
use OCA\OpenCatalogi\Contract\UsageCounterServiceInterface;
use OCA\OpenCatalogi\Controller\StatsController;
use OCA\OpenCatalogi\Service\PublicationQuality;
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
final class StatsPublicationQualityTest extends TestCase {

	private const CATALOG = ['id' => 'cat-1', '@self' => ['register' => 'r', 'schema' => 's'], 'registers' => ['7'], 'schemas' => ['21']];

	private PublicationQuality&MockObject $quality;
	private IUserSession&MockObject $session;
	private LoggerInterface&MockObject $logger;
	private bool $catalogReadable = true;

	protected function setUp(): void {
		$this->quality = $this->createMock(PublicationQuality::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function controller(): StatsController {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$catalogs = $this->createMock(CatalogiServiceInterface::class);
		$catalogs->method('getCatalogBySlug')->willReturnCallback(static fn (string $slug): ?array => $slug === 'woo' ? self::CATALOG : null);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getInstalledApps')->willReturn(['openregister']);
		$objects = new class($this->catalogReadable) {
			public function __construct(private bool $readable) {
			}

			public function searchObjects(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
				return $this->readable === true && $_rbac === true ? [['id' => 'cat-1']] : [];
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objects);
		return new StatsController(
			appName: 'opencatalogi',
			request: $this->createMock(IRequest::class),
			usageCounterService: $this->createMock(UsageCounterServiceInterface::class),
			catalogiService: $catalogs,
			appManager: $apps,
			container: $container,
			l10n: $l10n,
			userSession: $this->session,
			logger: $this->logger,
			publicationQuality: $this->quality
		);
	}

	private function signedIn(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('officer');
		$this->session->method('getUser')->willReturn($user);
	}

	public function testReturnsTheScoreOfAReadablePublication(): void {
		$this->signedIn();
		$score = ['total' => 40, 'dimensions' => ['findability' => 50], 'missing' => ['dct:license']];
		$this->quality->expects($this->once())->method('score')->with(self::CATALOG, 'woo', 'p-1')->willReturn($score);

		$response = $this->controller()->publicationQuality(slug: 'woo', id: 'p-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame($score, $response->getData());
	}

	public function testAPublicationTheCallerCannotReadIs404(): void {
		$this->signedIn();
		$this->quality->method('score')->willReturn(null);

		$response = $this->controller()->publicationQuality(slug: 'woo', id: 'p-x');

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'Publication not found'], $response->getData());
	}

	public function testAnUnreadableCatalogueIsRefusedBeforeAnyScore(): void {
		$this->signedIn();
		$this->catalogReadable = false;
		$this->quality->expects($this->never())->method('score');

		$this->assertSame(403, $this->controller()->publicationQuality(slug: 'woo', id: 'p-1')->getStatus());
		$this->assertSame(404, $this->controller()->publicationQuality(slug: 'nope', id: 'p-1')->getStatus());
	}

	public function testAnAnonymousCallerIsRefused(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->quality->expects($this->never())->method('score');

		$this->assertSame(403, $this->controller()->publicationQuality(slug: 'woo', id: 'p-1')->getStatus());
	}

	public function testAFailureAnswers500AndIsLogged(): void {
		$this->signedIn();
		$this->quality->method('score')->willThrowException(new RuntimeException('dcat down'));
		$this->logger->expects($this->once())->method('error')->with('[StatsController::publicationQuality] failed', ['error' => 'dcat down']);

		$response = $this->controller()->publicationQuality(slug: 'woo', id: 'p-1');

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'Could not compute the metadata quality.'], $response->getData());
	}
}
