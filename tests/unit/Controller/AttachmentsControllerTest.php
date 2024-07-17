<?php

namespace OCA\OpenCatalogi\Tests\Controller;

use OCA\OpenCatalogi\Controller\AttachmentsController;
use OCA\OpenCatalogi\Service\ObjectService;
use OCA\OpenCatalogi\Service\ElasticSearchService;
use OCP\IRequest;
use OCP\IAppConfig;
use Test\TestCase; 
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\JSONResponse;
use PHPUnit\Framework\MockObject\MockObject;

class AttachmentsControllerTest extends TestCase
{
    /** @var MockObject|IRequest */
    private $request;

    /** @var MockObject|IAppConfig */
    private $config;

    /** @var AttachmentsController */
    private $controller;

    protected function setUp(): void
    {
        $this->request = $this->createMock(IRequest::class);
        $this->config = $this->createMock(IAppConfig::class);
        $this->controller = new AttachmentsController('opencatalogi', $this->request, $this->config);

        // Zorg ervoor dat de config mock altijd een string retourneert
        $this->config->method('getValueString')
            ->willReturn('http://localhost');
    }

    public function testPage()
    {
        $response = $this->controller->page('testParam');
        $this->assertInstanceOf(TemplateResponse::class, $response);
    }

    public function testCatalog()
    {
        $response = $this->controller->catalog('testId');
        $this->assertInstanceOf(TemplateResponse::class, $response);
    }

    public function testIndex()
    {
        $objectService = $this->createMock(ObjectService::class);

        $this->config->method('getValueString')
            ->willReturnMap([
                ['opencatalogi', 'mongodbLocation', '', 'http://localhost'],
                ['opencatalogi', 'mongodbKey', '', 'someKey'],
                ['opencatalogi', 'mongodbCluster', '', 'someCluster']
            ]);

        $this->request->method('getParams')->willReturn(['key' => 'value']);
        $objectService->method('findObjects')->willReturn(['documents' => [['key' => 'value']]]);

        $response = $this->controller->index($objectService);
        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(['results' => [['key' => 'value']]], $response->getData());
    }

    public function testShow()
    {
        $objectService = $this->createMock(ObjectService::class);

        $this->config->method('getValueString')
            ->willReturnMap([
                ['opencatalogi', 'mongodbLocation', '', 'http://localhost'],
                ['opencatalogi', 'mongodbKey', '', 'someKey'],
                ['opencatalogi', 'mongodbCluster', '', 'someCluster']
            ]);

        $objectService->method('findObject')->willReturn(['key' => 'value']);

        $response = $this->controller->show('testId', $objectService);
        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(['key' => 'value'], $response->getData());
    }

    public function testCreate()
    {
        $objectService = $this->createMock(ObjectService::class);
        $elasticSearchService = $this->createMock(ElasticSearchService::class);

        $this->config->method('getValueString')
            ->willReturnMap([
                ['opencatalogi', 'mongodbLocation', '', 'http://localhost'],
                ['opencatalogi', 'mongodbKey', '', 'someKey'],
                ['opencatalogi', 'mongodbCluster', '', 'someCluster']
            ]);

        $this->request->method('getParams')->willReturn(['key' => 'value']);
        $objectService->method('saveObject')->willReturn(['key' => 'value']);

        $response = $this->controller->create($objectService, $elasticSearchService);
        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(['key' => 'value'], $response->getData());
    }

    public function testUpdate()
    {
        $objectService = $this->createMock(ObjectService::class);
        $elasticSearchService = $this->createMock(ElasticSearchService::class);

        $this->config->method('getValueString')
            ->willReturnMap([
                ['opencatalogi', 'mongodbLocation', '', 'http://localhost'],
                ['opencatalogi', 'mongodbKey', '', 'someKey'],
                ['opencatalogi', 'mongodbCluster', '', 'someCluster']
            ]);

        $this->request->method('getParams')->willReturn(['key' => 'newValue']);
        $objectService->method('updateObject')->willReturn(['key' => 'newValue']);

        $response = $this->controller->update('testId', $objectService, $elasticSearchService);
        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(['key' => 'newValue'], $response->getData());
    }

    public function testDestroy()
    {
        $objectService = $this->createMock(ObjectService::class);
        $elasticSearchService = $this->createMock(ElasticSearchService::class);

        $this->config->method('getValueString')
            ->willReturnMap([
                ['opencatalogi', 'mongodbLocation', '', 'http://localhost'],
                ['opencatalogi', 'mongodbKey', '', 'someKey'],
                ['opencatalogi', 'mongodbCluster', '', 'someCluster']
            ]);

        $objectService->method('deleteObject')->willReturn([]);

        $response = $this->controller->destroy('testId', $objectService, $elasticSearchService);
        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals([], $response->getData());
    }
}
