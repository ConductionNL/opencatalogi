<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Controller\RobotsController;
use OCA\OpenCatalogi\Http\TextResponse;
use OCA\OpenCatalogi\Service\SettingsService;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\App\IAppManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Unit tests for RobotsController.
 */
class RobotsControllerTest extends TestCase
{

    private IRequest|MockObject $request;
    private SettingsService|MockObject $settingsService;
    private ContainerInterface|MockObject $container;
    private IAppManager|MockObject $appManager;
    private IURLGenerator|MockObject $urlGenerator;
    private IL10N|MockObject $l10n;
    private RobotsController $controller;

    protected function setUp(): void
    {
        $this->request         = $this->createMock(IRequest::class);
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->container       = $this->createMock(ContainerInterface::class);
        $this->appManager      = $this->createMock(IAppManager::class);
        $this->urlGenerator    = $this->createMock(IURLGenerator::class);
        $this->l10n            = $this->createMock(IL10N::class);

        $this->l10n->method('t')
            ->willReturnCallback(fn(string $text) => $text);

        $this->controller = new RobotsController(
            'opencatalogi',
            $this->request,
            $this->settingsService,
            $this->container,
            $this->appManager,
            $this->urlGenerator,
            $this->l10n
        );
    }

    public function testIndexReturns500WhenSettingsMissingCatalogRegister(): void
    {
        $this->settingsService->method('getSettings')
            ->willReturn([
                'configuration' => [
                    'catalog_schema' => '5',
                ],
            ]);

        $response = $this->controller->index();

        $this->assertInstanceOf(TextResponse::class, $response);
    }

    public function testIndexReturns500WhenSettingsMissingCatalogSchema(): void
    {
        $this->settingsService->method('getSettings')
            ->willReturn([
                'configuration' => [
                    'catalog_register' => '1',
                ],
            ]);

        $response = $this->controller->index();

        $this->assertInstanceOf(TextResponse::class, $response);
    }

    public function testGetObjectServiceReturnsServiceWhenInstalled(): void
    {
        $mockObjService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);

        $this->appManager->method('getInstalledApps')
            ->willReturn(['openregister']);

        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($mockObjService);

        $result = $this->controller->getObjectService();

        $this->assertNotNull($result);
    }

    public function testGetObjectServiceThrowsWhenNotInstalled(): void
    {
        $this->appManager->method('getInstalledApps')
            ->willReturn([]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OpenRegister service is not available.');

        $this->controller->getObjectService();
    }
}
