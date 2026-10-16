<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Controller\UiController;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for UiController.
 */
class UiControllerTest extends TestCase {

	private IRequest|MockObject $request;
	private IAppConfig|MockObject $appConfig;
	private IInitialState|MockObject $initialState;
	private UiController $controller;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->initialState = $this->createMock(IInitialState::class);

		$this->controller = new UiController(
			'opencatalogi',
			$this->request,
			$this->appConfig,
			$this->initialState
		);
	}

	public function testDashboardReturnsSpaTemplate(): void {
		$response = $this->controller->dashboard();

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertEquals('index', $response->getTemplateName());
	}

	public function testDashboardHasContentSecurityPolicy(): void {
		$response = $this->controller->dashboard();

		$csp = $response->getContentSecurityPolicy();
		$this->assertNotNull($csp);
	}

	public function testCatalogiReturnsSpaTemplate(): void {
		$response = $this->controller->catalogi();

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertEquals('index', $response->getTemplateName());
	}

	public function testPublicationsIndexReturnsSpaTemplate(): void {
		$response = $this->controller->publicationsIndex();

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertEquals('index', $response->getTemplateName());
	}

	public function testPublicationsPageReturnsSpaTemplate(): void {
		$response = $this->controller->publicationsPage();

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertEquals('index', $response->getTemplateName());
	}

	public function testSearchReturnsSpaTemplate(): void {
		$response = $this->controller->search();

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertEquals('index', $response->getTemplateName());
	}

	public function testOrganizationsReturnsSpaTemplate(): void {
		$response = $this->controller->organizations();

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertEquals('index', $response->getTemplateName());
	}

	public function testThemesReturnsSpaTemplate(): void {
		$response = $this->controller->themes();

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertEquals('index', $response->getTemplateName());
	}

	public function testGlossaryReturnsSpaTemplate(): void {
		$response = $this->controller->glossary();

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertEquals('index', $response->getTemplateName());
	}

	/**
	 * Pages and menus are Portaliq's (cms-moves-to-portaliq, decision 138):
	 * this app serves no `/pages` or `/menus` screen, so a deep link there
	 * cannot open an empty CMS shell.
	 *
	 * @spec openspec/specs/content-management/spec.md
	 */
	public function testThereIsNoPagesOrMenusScreenAnyMore(): void {
		$this->assertFalse(method_exists($this->controller, 'pages'));
		$this->assertFalse(method_exists($this->controller, 'menus'));

		$routes = include __DIR__ . '/../../../appinfo/routes.php';
		$urls = array_column($routes['routes'], 'url');
		$this->assertNotContains('/pages', $urls);
		$this->assertNotContains('/menus', $urls);
		$this->assertNotContains('/api/pages', $urls);
		$this->assertNotContains('/api/menus', $urls);
	}

	public function testDirectoryReturnsSpaTemplate(): void {
		$response = $this->controller->directory();

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertEquals('index', $response->getTemplateName());
	}

	public function testAllSpaRoutesReturnSameTemplateName(): void {
		$methods = [
			'dashboard',
			'catalogi',
			'publicationsIndex',
			'publicationsPage',
			'search',
			'organizations',
			'themes',
			'glossary',
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

	public function testAllSpaRoutesHaveContentSecurityPolicy(): void {
		$methods = [
			'dashboard',
			'catalogi',
			'publicationsIndex',
			'publicationsPage',
			'search',
			'organizations',
			'themes',
			'glossary',
			'directory',
		];

		foreach ($methods as $method) {
			$response = $this->controller->$method();
			$csp = $response->getContentSecurityPolicy();
			$this->assertNotNull(
				$csp,
				"Method $method should have a ContentSecurityPolicy set"
			);
		}
	}
}
