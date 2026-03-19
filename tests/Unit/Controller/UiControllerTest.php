<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Controller\UiController;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for UiController.
 */
class UiControllerTest extends TestCase
{

    private IRequest|MockObject $request;
    private UiController $controller;

    protected function setUp(): void
    {
        $this->request = $this->createMock(IRequest::class);

        $this->controller = new UiController(
            'opencatalogi',
            $this->request
        );
    }

    public function testDashboardReturnsSpaTemplate(): void
    {
        $response = $this->controller->dashboard();

        $this->assertInstanceOf(TemplateResponse::class, $response);
        $this->assertEquals('index', $response->getTemplateName());
    }

    public function testDashboardHasContentSecurityPolicy(): void
    {
        $response = $this->controller->dashboard();

        $csp = $response->getContentSecurityPolicy();
        $this->assertNotNull($csp);
    }

    public function testCatalogiReturnsSpaTemplate(): void
    {
        $response = $this->controller->catalogi();

        $this->assertInstanceOf(TemplateResponse::class, $response);
        $this->assertEquals('index', $response->getTemplateName());
    }

    public function testPublicationsIndexReturnsSpaTemplate(): void
    {
        $response = $this->controller->publicationsIndex();

        $this->assertInstanceOf(TemplateResponse::class, $response);
        $this->assertEquals('index', $response->getTemplateName());
    }

    public function testPublicationsPageReturnsSpaTemplate(): void
    {
        $response = $this->controller->publicationsPage();

        $this->assertInstanceOf(TemplateResponse::class, $response);
        $this->assertEquals('index', $response->getTemplateName());
    }

    public function testSearchReturnsSpaTemplate(): void
    {
        $response = $this->controller->search();

        $this->assertInstanceOf(TemplateResponse::class, $response);
        $this->assertEquals('index', $response->getTemplateName());
    }

    public function testOrganizationsReturnsSpaTemplate(): void
    {
        $response = $this->controller->organizations();

        $this->assertInstanceOf(TemplateResponse::class, $response);
        $this->assertEquals('index', $response->getTemplateName());
    }

    public function testThemesReturnsSpaTemplate(): void
    {
        $response = $this->controller->themes();

        $this->assertInstanceOf(TemplateResponse::class, $response);
        $this->assertEquals('index', $response->getTemplateName());
    }

    public function testGlossaryReturnsSpaTemplate(): void
    {
        $response = $this->controller->glossary();

        $this->assertInstanceOf(TemplateResponse::class, $response);
        $this->assertEquals('index', $response->getTemplateName());
    }

    public function testPagesReturnsSpaTemplate(): void
    {
        $response = $this->controller->pages();

        $this->assertInstanceOf(TemplateResponse::class, $response);
        $this->assertEquals('index', $response->getTemplateName());
    }

    public function testMenusReturnsSpaTemplate(): void
    {
        $response = $this->controller->menus();

        $this->assertInstanceOf(TemplateResponse::class, $response);
        $this->assertEquals('index', $response->getTemplateName());
    }

    public function testDirectoryReturnsSpaTemplate(): void
    {
        $response = $this->controller->directory();

        $this->assertInstanceOf(TemplateResponse::class, $response);
        $this->assertEquals('index', $response->getTemplateName());
    }

    public function testAllSpaRoutesReturnSameTemplateName(): void
    {
        $methods = [
            'dashboard',
            'catalogi',
            'publicationsIndex',
            'publicationsPage',
            'search',
            'organizations',
            'themes',
            'glossary',
            'pages',
            'menus',
            'directory',
        ];

        foreach ($methods as $method) {
            $response = $this->controller->$method();
            $this->assertInstanceOf(TemplateResponse::class, $response);
            $this->assertEquals(
                'index',
                $response->getTemplateName(),
                "Method $method should return template 'index'"
            );
        }
    }

    public function testAllSpaRoutesHaveContentSecurityPolicy(): void
    {
        $methods = [
            'dashboard',
            'catalogi',
            'publicationsIndex',
            'publicationsPage',
            'search',
            'organizations',
            'themes',
            'glossary',
            'pages',
            'menus',
            'directory',
        ];

        foreach ($methods as $method) {
            $response = $this->controller->$method();
            $csp      = $response->getContentSecurityPolicy();
            $this->assertNotNull(
                $csp,
                "Method $method should have a ContentSecurityPolicy set"
            );
        }
    }
}
