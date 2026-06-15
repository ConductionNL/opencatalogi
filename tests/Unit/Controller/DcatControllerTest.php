<?php
/**
 * Unit tests for DcatController.
 *
 * Covers content negotiation (406 on unsupported), unknown/disabled catalog 404s,
 * conditional-GET 304, and CORS preflight. The serializer and DcatService are real
 * where pure and mocked where they touch OpenRegister.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2025 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Controller\DcatController;
use OCA\OpenCatalogi\Http\DcatResponse;
use OCA\OpenCatalogi\Service\CatalogiService;
use OCA\OpenCatalogi\Service\DcatSerializer;
use OCA\OpenCatalogi\Service\DcatService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for DcatController.
 */
class DcatControllerTest extends TestCase
{

    private IRequest|MockObject $request;
    private DcatService|MockObject $dcatService;
    private CatalogiService|MockObject $catalogiService;
    private DcatController $controller;

    protected function setUp(): void
    {
        $this->request         = $this->createMock(IRequest::class);
        $this->dcatService     = $this->createMock(DcatService::class);
        $this->catalogiService = $this->createMock(CatalogiService::class);

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);

        $this->controller = new DcatController(
            'opencatalogi',
            $this->request,
            $this->dcatService,
            new DcatSerializer(),
            $this->catalogiService,
            $l10n,
            $this->createMock(LoggerInterface::class),
            null
        );
    }

    public function testCatalogUnsupportedFormatReturns406(): void
    {
        $this->request->method('getParam')->willReturnCallback(
            static fn($key, $default=null) => $key === 'format' ? 'excel' : $default
        );
        $this->request->method('getHeader')->willReturn('*/*');

        $response = $this->controller->catalog('woo');
        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(406, $response->getStatus());
    }

    public function testCatalogUnknownSlugReturns404(): void
    {
        $this->request->method('getParam')->willReturn(null);
        $this->request->method('getHeader')->willReturn('application/ld+json');
        $this->catalogiService->method('getCatalogBySlug')->with('nope')->willReturn(null);

        $response = $this->controller->catalog('nope');
        $this->assertSame(404, $response->getStatus());
    }

    public function testCatalogDisabledReturns404(): void
    {
        $this->request->method('getParam')->willReturn(null);
        $this->request->method('getHeader')->willReturn('application/ld+json');
        $this->catalogiService->method('getCatalogBySlug')->willReturn(['hasDcat' => false]);
        $this->dcatService->method('isDcatEnabled')->willReturn(false);

        $response = $this->controller->catalog('disabled');
        $this->assertSame(404, $response->getStatus());
    }

    public function testCatalogServesJsonLd(): void
    {
        $this->request->method('getParam')->willReturnCallback(
            static fn($key, $default=null) => $key === 'page' ? 1 : null
        );
        $this->request->method('getHeader')->willReturnCallback(
            static fn($key) => $key === 'Accept' ? 'application/ld+json' : ''
        );
        $this->catalogiService->method('getCatalogBySlug')->willReturn(['hasDcat' => true]);
        $this->dcatService->method('isDcatEnabled')->willReturn(true);
        $this->dcatService->method('buildCatalogDocument')->willReturn(
            [
                '@context' => ['dcat' => 'http://www.w3.org/ns/dcat#'],
                '@graph'   => [['@id' => 'https://host/api/catalogs/woo/dcat', '@type' => 'dcat:Catalog']],
                '_meta'    => ['lastModified' => 'Mon, 01 Jan 2025 00:00:00 GMT', 'etag' => '"abc"', 'count' => 0],
            ]
        );

        $response = $this->controller->catalog('woo');
        $this->assertInstanceOf(DcatResponse::class, $response);
        $this->assertSame(200, $response->getStatus());
        $this->assertStringContainsString('dcat:Catalog', $response->render());
        $this->assertSame('"abc"', $response->getHeaders()['ETag']);
    }

    public function testCatalogConditionalGetReturns304(): void
    {
        $this->request->method('getParam')->willReturnCallback(
            static fn($key, $default=null) => $key === 'page' ? 1 : null
        );
        $this->request->method('getHeader')->willReturnCallback(
            static fn($key) => match ($key) {
                'Accept'        => 'application/ld+json',
                'If-None-Match' => '"abc"',
                default         => '',
            }
        );
        $this->catalogiService->method('getCatalogBySlug')->willReturn(['hasDcat' => true]);
        $this->dcatService->method('isDcatEnabled')->willReturn(true);
        $this->dcatService->method('buildCatalogDocument')->willReturn(
            [
                '@context' => [],
                '@graph'   => [],
                '_meta'    => ['etag' => '"abc"', 'lastModified' => 'Mon, 01 Jan 2025 00:00:00 GMT'],
            ]
        );

        $response = $this->controller->catalog('woo');
        $this->assertSame(304, $response->getStatus());
    }

    public function testPreflightedCorsHasCorsHeaders(): void
    {
        $response = $this->controller->preflightedCors();
        $headers  = $response->getHeaders();
        $this->assertSame('*', $headers['Access-Control-Allow-Origin']);
        $this->assertStringContainsString('GET', $headers['Access-Control-Allow-Methods']);
    }

    public function testValidateUnknownCatalogReturns404(): void
    {
        $this->catalogiService->method('getCatalogBySlug')->willReturn(null);
        $response = $this->controller->validate('nope');
        $this->assertSame(404, $response->getStatus());
    }

    public function testValidateReportsViolations(): void
    {
        $this->catalogiService->method('getCatalogBySlug')->willReturn(['hasDcat' => true]);
        $this->dcatService->method('validateCatalog')->willReturn(
            [['iri' => 'https://host/api/woo/u1', 'missing' => ['dct:publisher']]]
        );

        $response = $this->controller->validate('woo');
        $this->assertSame(200, $response->getStatus());
        $data = $response->getData();
        $this->assertFalse($data['valid']);
        $this->assertCount(1, $data['violations']);
    }
}//end class
