<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Controller\WooReadinessController;
use OCA\OpenCatalogi\Service\WooReadinessService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for WooReadinessController.
 *
 * @covers \OCA\OpenCatalogi\Controller\WooReadinessController
 */
class WooReadinessControllerTest extends TestCase
{

    private IRequest|MockObject $request;
    private WooReadinessService|MockObject $wooReadinessService;
    private WooReadinessController $controller;

    protected function setUp(): void
    {
        $this->request             = $this->createMock(IRequest::class);
        $this->wooReadinessService = $this->createMock(WooReadinessService::class);

        $this->controller = new WooReadinessController(
            'opencatalogi',
            $this->request,
            $this->wooReadinessService
        );
    }

    public function testReportReturnsPersistedReport(): void
    {
        $report = ['verdict' => 'ready', 'checks' => []];

        $this->wooReadinessService->expects($this->once())
            ->method('getPersistedReport')
            ->willReturn($report);

        $response = $this->controller->report();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(['report' => $report], $response->getData());
    }

    public function testReportReturnsNullWhenNoRunYet(): void
    {
        $this->wooReadinessService->method('getPersistedReport')->willReturn(null);

        $response = $this->controller->report();

        $this->assertSame(['report' => null], $response->getData());
    }

    public function testRunReturns409WithZeroOutboundWhenUnconfigured(): void
    {
        $this->wooReadinessService->expects($this->once())
            ->method('hasWooEnabledCatalogs')
            ->willReturn(false);

        // The fail-closed contract (WOO-HR-004): runCheck() must never even be
        // invoked when there is no WOO-enabled catalog, guaranteeing zero outbound
        // requests regardless of what runCheck() itself might do.
        $this->wooReadinessService->expects($this->never())->method('runCheck');

        $response = $this->controller->run();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
        $this->assertSame(['error' => 'not-configured'], $response->getData());
    }

    public function testRunReturnsReportWhenConfigured(): void
    {
        $report = ['verdict' => 'ready', 'checks' => []];

        $this->wooReadinessService->method('hasWooEnabledCatalogs')->willReturn(true);
        $this->wooReadinessService->expects($this->once())
            ->method('runCheck')
            ->willReturn($report);

        $response = $this->controller->run();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame($report, $response->getData());
    }

    public function testRunReturns400OnServiceException(): void
    {
        $this->wooReadinessService->method('hasWooEnabledCatalogs')->willReturn(true);
        $this->wooReadinessService->method('runCheck')
            ->willThrowException(new \RuntimeException('boom'));

        $response = $this->controller->run();

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame(['error' => 'boom'], $response->getData());
    }
}
