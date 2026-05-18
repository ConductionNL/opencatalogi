<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Controller\ViewEnrichmentController;
use OCA\OpenCatalogi\Service\ViewService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ViewEnrichmentController.
 *
 * @spec openspec/changes/deelnames-gebruik/tasks.md#task-5
 */
class ViewEnrichmentControllerTest extends TestCase
{

    private IRequest|MockObject $request;
    private ViewService|MockObject $viewService;
    private ViewEnrichmentController $controller;


    protected function setUp(): void
    {
        $this->request     = $this->createMock(IRequest::class);
        $this->viewService = $this->createMock(ViewService::class);

        $this->request->server = [];

        $this->controller = new ViewEnrichmentController(
            'opencatalogi',
            $this->request,
            $this->viewService
        );

    }//end setUp()

    // -------------------------------------------------------------------------
    // CORS preflight
    // -------------------------------------------------------------------------

    public function testPreflightedCorsReturnsResponse(): void
    {
        $this->request->method('getHeader')
            ->with('Origin')
            ->willReturn('https://softwarecatalog.example.nl');

        $response = $this->controller->preflightedCors();

        $this->assertInstanceOf(Response::class, $response);

    }//end testPreflightedCorsReturnsResponse()

    public function testPreflightedCorsUsesWildcardWhenNoOrigin(): void
    {
        $this->request->method('getHeader')
            ->with('Origin')
            ->willReturn('');

        $response = $this->controller->preflightedCors();

        $this->assertInstanceOf(Response::class, $response);

    }//end testPreflightedCorsUsesWildcardWhenNoOrigin()

    // -------------------------------------------------------------------------
    // Parameter validation
    // -------------------------------------------------------------------------

    public function testMissingOrganizationIdReturns400(): void
    {
        $this->request->method('getParams')
            ->willReturn([]);

        $response = $this->controller->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(400, $response->getStatus());

    }//end testMissingOrganizationIdReturns400()

    public function testEmptyOrganizationIdReturns400(): void
    {
        $this->request->method('getParams')
            ->willReturn(['organization_id' => '   ']);

        $response = $this->controller->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(400, $response->getStatus());

    }//end testEmptyOrganizationIdReturns400()

    // -------------------------------------------------------------------------
    // Successful responses
    // -------------------------------------------------------------------------

    public function testIndexReturnsBothFlagsDisabled(): void
    {
        $this->request->method('getParams')
            ->willReturn(['organization_id' => 'org-uuid']);

        $this->viewService->expects($this->once())
            ->method('getGebruikForOrganization')
            ->with(
                organizationId: 'org-uuid',
                includeGebruik: false,
                includeDeelnames: false
            )
            ->willReturn(['owned' => [], 'deelnames' => [], 'warnings' => []]);

        $response = $this->controller->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());

        $data = $response->getData();
        $this->assertEquals('org-uuid', $data['organization_id']);
        $this->assertEmpty($data['owned']);
        $this->assertEmpty($data['deelnames']);

    }//end testIndexReturnsBothFlagsDisabled()

    public function testIndexWithGebruikFlagTrue(): void
    {
        $this->request->method('getParams')
            ->willReturn([
                'organization_id' => 'org-uuid',
                'include_gebruik'  => 'true',
            ]);

        $this->viewService->expects($this->once())
            ->method('getGebruikForOrganization')
            ->with(
                organizationId: 'org-uuid',
                includeGebruik: true,
                includeDeelnames: false
            )
            ->willReturn([
                'owned'    => [['id' => 'obj-1']],
                'deelnames' => [],
                'warnings' => [],
            ]);

        $response = $this->controller->index();

        $this->assertEquals(200, $response->getStatus());
        $this->assertCount(1, $response->getData()['owned']);

    }//end testIndexWithGebruikFlagTrue()

    public function testIndexWithDeelnamesFlagTrue(): void
    {
        $this->request->method('getParams')
            ->willReturn([
                'organization_id'           => 'org-uuid',
                'include_deelnames_gebruik' => '1',
            ]);

        $this->viewService->expects($this->once())
            ->method('getGebruikForOrganization')
            ->with(
                organizationId: 'org-uuid',
                includeGebruik: false,
                includeDeelnames: true
            )
            ->willReturn([
                'owned'    => [],
                'deelnames' => [['id' => 'deel-1', '_type' => 'deelnames']],
                'warnings' => [],
            ]);

        $response = $this->controller->index();

        $this->assertEquals(200, $response->getStatus());
        $this->assertCount(1, $response->getData()['deelnames']);

    }//end testIndexWithDeelnamesFlagTrue()

    public function testIndexPassesBooleanFlagDirectly(): void
    {
        $this->request->method('getParams')
            ->willReturn([
                'organization_id'           => 'org-uuid',
                'include_gebruik'           => true,
                'include_deelnames_gebruik' => false,
            ]);

        $this->viewService->expects($this->once())
            ->method('getGebruikForOrganization')
            ->with(
                organizationId: 'org-uuid',
                includeGebruik: true,
                includeDeelnames: false
            )
            ->willReturn(['owned' => [], 'deelnames' => [], 'warnings' => []]);

        $response = $this->controller->index();

        $this->assertEquals(200, $response->getStatus());

    }//end testIndexPassesBooleanFlagDirectly()

    public function testIndexResponseContainsWarnings(): void
    {
        $this->request->method('getParams')
            ->willReturn([
                'organization_id'           => 'org-uuid',
                'include_deelnames_gebruik' => 'true',
            ]);

        $this->viewService->method('getGebruikForOrganization')
            ->willReturn([
                'owned'    => [],
                'deelnames' => [],
                'warnings' => ['Deelnames query failed: timeout'],
            ]);

        $response = $this->controller->index();

        $data = $response->getData();
        $this->assertArrayHasKey('warnings', $data);
        $this->assertNotEmpty($data['warnings']);

    }//end testIndexResponseContainsWarnings()

}//end class
