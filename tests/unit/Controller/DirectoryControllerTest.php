<?php

namespace OCA\OpenCatalogi\Tests\Controller;

use OCA\OpenCatalogi\Controller\DirectoryController;
use OCA\OpenCatalogi\Service\DirectoryService;
use OCA\OpenCatalogi\Service\ObjectService;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\JSONResponse;
use PHPUnit\Framework\MockObject\MockObject;

class DirectoryControllerTest extends TestCase
{
    /** @var MockObject|IRequest */
    private $request;

    /** @var MockObject|IAppConfig */
    private $config;

    /** @var MockObject|ObjectService */
    private $objectService;

    /** @var MockObject|DirectoryService */
    private $directoryService;

    /** @var DirectoryController */
    private $controller;

    protected function setUp(): void
    {
        $this->request = $this->createMock(IRequest::class);
        $this->config = $this->createMock(IAppConfig::class);
        $this->objectService = $this->createMock(ObjectService::class);
        $this->directoryService = $this->createMock(DirectoryService::class);
        $this->controller = new DirectoryController('opencatalogi', $this->request, $this->config);
    }

    public function testPage()
    {
        $response = $this->controller->page('testParam');
        $this->assertInstanceOf(TemplateResponse::class, $response);
    }

    public function testPageWithError()
    {
        $this->controller = $this->getMockBuilder(DirectoryController::class)
            ->setConstructorArgs(['opencatalogi', $this->request, $this->config])
            ->onlyMethods(['page'])
            ->getMock();

        $this->controller->method('page')
            ->will($this->throwException(new \Exception('Template load error')));

        try {
            $this->controller->page('testParam');
            $this->fail('Expected exception not thrown');
        } catch (\Exception $e) {
            $this->assertEquals('Template load error', $e->getMessage());
        }
    }

    public function testIndex()
    {
        $this->config->method('getValueString')->willReturn('someValue');

        $this->objectService->method('findObjects')->willReturn([
            'documents' => DirectoryController::TEST_ARRAY
        ]);

        $response = $this->controller->index($this->objectService);
        $this->assertInstanceOf(JSONResponse::class, $response);

        $expectedData = [
            "results" => DirectoryController::TEST_ARRAY
        ];

        $this->assertEquals($expectedData, $response->getData());
    }

    public function testIndexWithInvalidFilters()
    {
        $this->config->method('getValueString')->willReturn('someValue');

        $this->request->method('getParams')->willReturn(['_invalid' => 'value']);

        $this->objectService->method('findObjects')->willReturn(['documents' => []]);

        $response = $this->controller->index($this->objectService);
        $this->assertInstanceOf(JSONResponse::class, $response);

        $expectedData = ["results" => []];
        $this->assertEquals($expectedData, $response->getData());
    }

    public function testIndexWithError()
    {
        $this->config->method('getValueString')->willReturn('someValue');

        $this->objectService->method('findObjects')->willThrowException(new \Exception('Database error'));

        try {
            $this->controller->index($this->objectService);
            $this->fail('Expected exception not thrown');
        } catch (\Exception $e) {
            $this->assertEquals('Database error', $e->getMessage());
        }
    }

    public function testShow()
    {
        $this->config->method('getValueString')->willReturn('someValue');

        $id = '64996753-5109-4396-9f07-17040d7fb137';
        $this->objectService->method('findObject')->willReturn(DirectoryController::TEST_ARRAY[$id]);

        $response = $this->controller->show($id, $this->objectService, $this->directoryService);
        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(DirectoryController::TEST_ARRAY[$id], $response->getData());
    }

    public function testShowWithNonExistentId()
    {
        $this->config->method('getValueString')->willReturn('someValue');

        $id = 'non-existent-id';
        $this->objectService->method('findObject')->willReturn([]);

        $response = $this->controller->show($id, $this->objectService, $this->directoryService);
        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals([], $response->getData());
    }

    public function testShowWithError()
    {
        $this->config->method('getValueString')->willReturn('someValue');

        $this->objectService->method('findObject')->willThrowException(new \Exception('Object not found'));

        try {
            $this->controller->show('nonExistentId', $this->objectService, $this->directoryService);
            $this->fail('Expected exception not thrown');
        } catch (\Exception $e) {
            $this->assertEquals('Object not found', $e->getMessage());
        }
    }

    public function testCreate()
    {
        $this->config->method('getValueString')->willReturn('someValue');

        $data = [
            "id" => "new-id",
            "title" => "New Directory",
            "summary" => "A new testing directory",
            "description" => "A new testing directory description",
            "search" => "string",
            "metadata" => "string",
            "status" => "A status",
            "lastSync" => "string",
            "default" => "string",
            "available" => "true",
            "_schema" => "directory"
        ];

        $this->request->method('getParams')->willReturn($data);

        $this->objectService->method('saveObject')->willReturn($data);
        $this->directoryService->method('registerToExternalDirectory')->willReturn(200);

        $response = $this->controller->create($this->objectService, $this->directoryService);
        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals($data, $response->getData());
    }

    public function testCreateWithError()
    {
        $this->config->method('getValueString')->willReturn('someValue');

        $data = [
            "id" => "new-id",
            "title" => "New Directory",
            "summary" => "A new testing directory",
            "description" => "A new testing directory description",
            "search" => "string",
            "metadata" => "string",
            "status" => "A status",
            "lastSync" => "string",
            "default" => "string",
            "available" => "true",
            "_schema" => "directory"
        ];

        $this->request->method('getParams')->willReturn($data);

        $this->objectService->method('saveObject')->willThrowException(new \Exception('Save failed'));

        try {
            $this->controller->create($this->objectService, $this->directoryService);
            $this->fail('Expected exception not thrown');
        } catch (\Exception $e) {
            $this->assertEquals('Save failed', $e->getMessage());
        }
    }

    public function testUpdate()
    {
        $this->config->method('getValueString')->willReturn('someValue');

        $id = '64996753-5109-4396-9f07-17040d7fb137';
        $data = [
            "title" => "Updated Directory",
            "summary" => "An updated testing directory",
            "description" => "An updated testing directory description",
            "search" => "string",
            "metadata" => "string",
            "status" => "A status",
            "lastSync" => "string",
            "default" => "string",
            "available" => "true"
        ];

        $this->request->method('getParams')->willReturn($data);

        $updatedData = array_merge(DirectoryController::TEST_ARRAY[$id], $data);
        $this->objectService->method('updateObject')->willReturn($updatedData);

        $response = $this->controller->update($id, $this->objectService);
        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals($updatedData, $response->getData());
    }

    public function testUpdateWithNonExistentId()
    {
        $this->config->method('getValueString')->willReturn('someValue');

        $id = 'non-existent-id';
        $data = [
            "title" => "Updated Directory",
            "summary" => "An updated testing directory",
            "description" => "An updated testing directory description",
            "search" => "string",
            "metadata" => "string",
            "status" => "A status",
            "lastSync" => "string",
            "default" => "string",
            "available" => "true"
        ];

        $this->request->method('getParams')->willReturn($data);

        $this->objectService->method('updateObject')->willReturn([]);

        $response = $this->controller->update($id, $this->objectService);
        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals([], $response->getData());
    }

    public function testUpdateWithError()
    {
        $this->config->method('getValueString')->willReturn('someValue');

        $this->request->method('getParams')->willReturn(['key' => 'newValue']);
        $this->objectService->method('updateObject')->willThrowException(new \Exception('Update failed'));

        try {
            $this->controller->update('invalidId', $this->objectService);
            $this->fail('Expected exception not thrown');
        } catch (\Exception $e) {
            $this->assertEquals('Update failed', $e->getMessage());
        }
    }

    public function testDestroy()
    {
        $this->config->method('getValueString')->willReturn('someValue');

        $id = '64996753-5109-4396-9f07-17040d7fb137';

        $this->objectService->method('deleteObject')->willReturn([]);

        $response = $this->controller->destroy($id, $this->objectService);
        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals([], $response->getData());
    }

    public function testDestroyWithNonExistentId()
    {
        $this->config->method('getValueString')->willReturn('someValue');

        $id = 'non-existent-id';

        $this->objectService->method('deleteObject')->willReturn([]);

        $response = $this->controller->destroy($id, $this->objectService);
        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals([], $response->getData());
    }

    public function testDestroyWithError()
    {
        $this->config->method('getValueString')->willReturn('someValue');

        $this->objectService->method('deleteObject')->willThrowException(new \Exception('Delete failed'));

        try {
            $this->controller->destroy('invalidId', $this->objectService);
            $this->fail('Expected exception not thrown');
        } catch (\Exception $e) {
            $this->assertEquals('Delete failed', $e->getMessage());
        }
    }
}
