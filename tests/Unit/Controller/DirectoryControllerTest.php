<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Controller\DirectoryController;
use OCA\OpenCatalogi\Service\DirectoryService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use GuzzleHttp\Exception\TransferException;

/**
 * Unit tests for DirectoryController.
 */
class DirectoryControllerTest extends TestCase
{

    private IRequest|MockObject $request;
    private DirectoryService|MockObject $directoryService;
    private IL10N|MockObject $l10n;
    private LoggerInterface|MockObject $logger;
    private IAppConfig|MockObject $config;
    private DirectoryController $controller;

    protected function setUp(): void
    {
        $this->request          = $this->createMock(IRequest::class);
        $this->directoryService = $this->createMock(DirectoryService::class);
        $this->l10n             = $this->createMock(IL10N::class);
        $this->logger           = $this->createMock(LoggerInterface::class);
        $this->config           = $this->createMock(IAppConfig::class);

        $this->l10n->method('t')
            ->willReturnCallback(fn(string $text, array $params = []) => $text);

        // CORS allowlist defaults to '*' unless a test overrides it.
        $this->config->method('getValueString')
            ->willReturnCallback(fn(string $app, string $key, string $default = '') => $default);

        $this->controller = new DirectoryController(
            'opencatalogi',
            $this->request,
            $this->directoryService,
            $this->l10n,
            $this->logger,
            $this->config
        );
    }

    public function testPreflightedCorsReturnsResponse(): void
    {
        $this->request->method('getHeader')
            ->with('Origin')
            ->willReturn('https://example.com');

        $response = $this->controller->preflightedCors();

        $this->assertInstanceOf(Response::class, $response);
    }

    public function testPreflightedCorsWildcardWhenNoOrigin(): void
    {
        $this->request->method('getHeader')
            ->with('Origin')
            ->willReturn('');

        $response = $this->controller->preflightedCors();

        $this->assertInstanceOf(Response::class, $response);
    }

    public function testIndexReturnsJsonResponseSuccess(): void
    {
        $directoryData = ['results' => [['id' => 1]], 'total' => 1];

        $this->request->method('getParams')
            ->willReturn([]);

        $this->directoryService->method('getDirectory')
            ->willReturn($directoryData);

        $this->request->server = ['HTTP_ORIGIN' => 'https://test.com'];

        $response = $this->controller->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }

    public function testIndexReturns500OnException(): void
    {
        $this->request->method('getParams')
            ->willReturn([]);

        $this->directoryService->method('getDirectory')
            ->willThrowException(new \Exception('Database error: revealing SQL fragment'));

        $this->request->server = [];

        $response = $this->controller->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(500, $response->getStatus());
    }

    /**
     * Security (#735): a public 500 response must NOT leak the raw exception message
     * to the caller — internal SQL/file-path fragments accelerate reconnaissance.
     */
    public function testIndexReturns500WithoutLeakingExceptionMessage(): void
    {
        $this->request->method('getParams')
            ->willReturn([]);

        $secretMessage = 'PDOException: SQLSTATE[42S02] in /var/www/html/internal-path/foo.php:123';
        $this->directoryService->method('getDirectory')
            ->willThrowException(new \Exception($secretMessage));

        $this->request->server = [];

        $response = $this->controller->index();

        $this->assertSame(500, $response->getStatus());
        $body = json_encode($response->getData());
        $this->assertStringNotContainsString($secretMessage, (string) $body);
        $this->assertStringNotContainsString('PDOException', (string) $body);
        $this->assertStringNotContainsString('/var/www/html', (string) $body);
    }

    /**
     * Security (#735): an attacker-controlled Origin must NOT be reflected when a
     * non-wildcard allowlist is configured.
     */
    public function testIndexDoesNotReflectArbitraryOriginWhenAllowlistConfigured(): void
    {
        // Re-create the config + controller with a non-wildcard allowlist.
        $config = $this->createMock(IAppConfig::class);
        $config->method('getValueString')
            ->willReturnCallback(function (string $app, string $key, string $default = '') {
                return match ($key) {
                    'cors_allowed_origins' => 'https://trusted.example',
                    default                => $default,
                };
            });

        $controller = new DirectoryController(
            'opencatalogi',
            $this->request,
            $this->directoryService,
            $this->l10n,
            $this->logger,
            $config
        );

        $this->directoryService->method('getDirectory')->willReturn(['results' => []]);
        $this->request->method('getParams')->willReturn([]);
        $this->request->server = ['HTTP_ORIGIN' => 'https://evil.attacker.test'];

        $response = $controller->index();

        $this->assertSame(
            'https://trusted.example',
            $response->getHeaders()['Access-Control-Allow-Origin']
        );
    }

    public function testUpdateReturnsBadRequestWhenNoDirectoryUrl(): void
    {
        $this->request->method('getParam')
            ->with('directory')
            ->willReturn(null);

        $this->request->method('getHeader')
            ->with('Origin')
            ->willReturn('');

        $response = $this->controller->update();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(400, $response->getStatus());
    }

    public function testUpdateReturnsBadRequestWithEmptyDirectoryUrl(): void
    {
        $this->request->method('getParam')
            ->with('directory')
            ->willReturn('');

        $this->request->method('getHeader')
            ->with('Origin')
            ->willReturn('');

        $response = $this->controller->update();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(400, $response->getStatus());
    }

    public function testUpdateReturnsSuccessOnValidSync(): void
    {
        $this->request->method('getParam')
            ->with('directory')
            ->willReturn('https://example.com/directory');

        $this->directoryService->method('syncDirectory')
            ->with('https://example.com/directory')
            ->willReturn(['synced' => 5]);

        $this->request->method('getHeader')
            ->with('Origin')
            ->willReturn('https://example.com');

        $response = $this->controller->update();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }

    public function testUpdateReturns400OnInvalidArgumentException(): void
    {
        $this->request->method('getParam')
            ->with('directory')
            ->willReturn('not-a-url');

        $this->directoryService->method('syncDirectory')
            ->willThrowException(new \InvalidArgumentException('Invalid URL'));

        $this->request->method('getHeader')
            ->with('Origin')
            ->willReturn('');

        $response = $this->controller->update();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(400, $response->getStatus());
    }

    public function testUpdateReturns500OnGenericException(): void
    {
        $this->request->method('getParam')
            ->with('directory')
            ->willReturn('https://example.com/dir');

        $this->directoryService->method('syncDirectory')
            ->willThrowException(new \Exception('Unexpected error'));

        $this->request->method('getHeader')
            ->with('Origin')
            ->willReturn('');

        $response = $this->controller->update();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(500, $response->getStatus());
    }
}
