<?php

/**
 * Tests that the federation read path counts views and downloads (ANA-001).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/capability-rows-close-gaps/tasks.md#task-2.1
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Contract\PublicationServiceInterface;
use OCA\OpenCatalogi\Contract\PublicationUsage;
use OCA\OpenCatalogi\Contract\UsageCounterServiceInterface;
use OCA\OpenCatalogi\Controller\FederationController;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

#[CoversClass(FederationController::class)]
#[UsesClass(PublicationUsage::class)]
final class FederationUsageCountTest extends TestCase {

	private IRequest&MockObject $request;
	private PublicationServiceInterface&MockObject $publications;
	private UsageCounterServiceInterface&MockObject $counter;
	private LoggerInterface&MockObject $logger;

	/** @var list<PublicationUsage> */
	private array $counted = [];

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getParams')->willReturn([]);
		$this->request->method('getHeader')->willReturnCallback(
			static fn (string $name): string => ($name === 'User-Agent' ? 'Mozilla/5.0' : '')
		);
		$this->publications = $this->createMock(PublicationServiceInterface::class);
		$this->counter = $this->createMock(UsageCounterServiceInterface::class);
		$this->counter->method('increment')->willReturnCallback(
			function (PublicationUsage $usage, ?string $userAgent = null): bool {
				$this->assertSame('Mozilla/5.0', $userAgent);
				$this->counted[] = $usage;
				return true;
			}
		);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function controller(): FederationController {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		return new FederationController(
			request: $this->request,
			publicationService: $this->publications,
			l10n: $l10n,
			logger: $this->logger,
			container: $this->container()
		);
	}

	private function container(): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $class): object {
				if ($class === UsageCounterServiceInterface::class) {
					return $this->counter;
				}

				throw new class ('not registered') extends \RuntimeException implements NotFoundExceptionInterface {
				};
			}
		);
		return $container;
	}

	public function testALocalPublicationServedIsCountedAsAView(): void {
		$this->request->method('getMethod')->willReturn('GET');
		$this->publications->method('getFederatedPublication')->willReturn(['data' => ['id' => 'p-1'], 'status' => 200]);

		$response = $this->controller()->publication(id: 'p-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertCount(1, $this->counted);
		$this->assertSame('p-1', $this->counted[0]->publicationId);
		$this->assertSame(UsageCounterServiceInterface::KIND_VIEW, $this->counted[0]->kind);
		$this->assertNull($this->counted[0]->catalog);
	}

	public function testAPublicationAPeerAnsweredIsNotCounted(): void {
		$this->request->method('getMethod')->willReturn('GET');
		$this->publications->method('getFederatedPublication')->willReturn(
			['data' => ['id' => 'p-1', 'sources' => ['peer']], 'status' => 200, 'federated' => true]
		);

		$this->controller()->publication(id: 'p-1');

		$this->assertSame([], $this->counted);
	}

	public function testANotFoundAnswerIsNotCounted(): void {
		$this->request->method('getMethod')->willReturn('GET');
		$this->publications->method('getFederatedPublication')->willReturn(['data' => ['error' => 'not found'], 'status' => 404]);

		$this->assertSame(404, $this->controller()->publication(id: 'p-1')->getStatus());
		$this->assertSame([], $this->counted);
	}

	public function testAHeadRequestIsNotCounted(): void {
		$this->request->method('getMethod')->willReturn('HEAD');
		$this->publications->method('getFederatedPublication')->willReturn(['data' => ['id' => 'p-1'], 'status' => 200]);

		$this->controller()->publication(id: 'p-1');

		$this->assertSame([], $this->counted);
	}

	public function testAServedFileIsCountedAsADownload(): void {
		$this->request->method('getMethod')->willReturn('GET');
		$download = new DataDownloadResponse('zip', 'p-1.zip', 'application/zip');
		$this->publications->method('download')->with('p-1')->willReturn($download);

		$this->assertSame($download, $this->controller()->publicationDownload(id: 'p-1'));
		$this->assertCount(1, $this->counted);
		$this->assertSame(UsageCounterServiceInterface::KIND_DOWNLOAD, $this->counted[0]->kind);
	}

	public function testAFailedDownloadIsNotCounted(): void {
		$this->request->method('getMethod')->willReturn('GET');
		$this->publications->method('download')->willReturn(new JSONResponse(['error' => 'no file'], 404));

		$this->assertSame(404, $this->controller()->publicationDownload(id: 'p-1')->getStatus());
		$this->assertSame([], $this->counted);
	}

	public function testACounterFailureIsLoggedAndTheReadStillAnswers(): void {
		$this->request->method('getMethod')->willReturn('GET');
		$this->publications->method('getFederatedPublication')->willReturn(['data' => ['id' => 'p-1'], 'status' => 200]);
		$counter = $this->createMock(UsageCounterServiceInterface::class);
		$counter->method('increment')->willThrowException(new RuntimeException('register down'));
		$this->counter = $counter;
		$this->logger->expects($this->once())->method('warning')
			->with('[FederationController] usage count failed (swallowed)', ['error' => 'register down']);

		$response = $this->controller()->publication(id: 'p-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['id' => 'p-1'], $response->getData());
	}
}
