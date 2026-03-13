<?php

namespace OCA\OpenCatalogi\Tests\Service;

use OCA\OpenCatalogi\Service\BroadcastService;
use OCA\OpenCatalogi\Service\DirectoryService;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\App\IAppManager;
use OCP\IRequest;
use OCP\IServerContainer;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Container\ContainerInterface;

class DirectoryServiceTest extends TestCase
{
    /** @var MockObject&IURLGenerator */
    private $urlGenerator;

    /** @var MockObject&IAppConfig */
    private $config;

    /** @var MockObject&ContainerInterface */
    private $container;

    /** @var MockObject&IAppManager */
    private $appManager;

    /** @var MockObject&BroadcastService */
    private $broadcastService;

    /** @var MockObject&IServerContainer */
    private $server;

    /** @var MockObject&IRequest */
    private $request;

    /** @var DirectoryService */
    private $directoryService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->urlGenerator = $this->createMock(IURLGenerator::class);
        $this->config = $this->createMock(IAppConfig::class);
        $this->container = $this->createMock(ContainerInterface::class);
        $this->appManager = $this->createMock(IAppManager::class);
        $this->broadcastService = $this->createMock(BroadcastService::class);
        $this->server = $this->createMock(IServerContainer::class);
        $this->request = $this->createMock(IRequest::class);

        $this->directoryService = new DirectoryService(
            $this->urlGenerator,
            $this->config,
            $this->container,
            $this->appManager,
            $this->broadcastService,
            $this->server,
            $this->request
        );
    }

    public function testConstructor(): void
    {
        $this->assertInstanceOf(DirectoryService::class, $this->directoryService);
    }

    public function testConvertCatalogiToListings(): void
    {
        $catalogs = [
            [
                'title' => 'Test Catalog',
                'summary' => 'A test catalog',
            ],
        ];

        $result = $this->directoryService->convertCatalogiToListings($catalogs);

        $this->assertIsArray($result);
    }
}
