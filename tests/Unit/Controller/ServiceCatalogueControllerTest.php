<?php

/**
 * Unit tests for ServiceCatalogueController.
 *
 * The controller's job on a publication surface is to keep two failures apart:
 * serving something that is not public, and reporting "nothing" when the truth
 * is "we could not look". Both are asserted here on the response the caller
 * actually gets, not on a service return value the response might discard.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Controller\ServiceCatalogueController;
use OCA\OpenCatalogi\Service\CaseTypeCatalogueService;
use OCA\OpenCatalogi\Service\Catalogue\CatalogueUnreadableException;
use OCA\OpenCatalogi\Service\Catalogue\ExternalCatalogueUnreachableException;
use OCA\OpenCatalogi\Service\KnowledgeArticleService;
use OCA\OpenCatalogi\Service\ServiceCatalogueService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Unit tests for ServiceCatalogueController.
 */
class ServiceCatalogueControllerTest extends TestCase {

	private IRequest|MockObject $request;
	private IAppConfig|MockObject $config;
	private ContainerInterface|MockObject $container;
	private IL10N|MockObject $l10n;
	private IUserSession|MockObject $userSession;
	private ServiceCatalogueService|MockObject $catalogueService;
	private CaseTypeCatalogueService|MockObject $caseTypeService;
	private ServiceCatalogueController $controller;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->config = $this->createMock(IAppConfig::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnArgument(0);
		$this->userSession = $this->createMock(IUserSession::class);

		// onlyMethods: a double that could invent a method the real service
		// lacks would let a green test cover a call that 500s in production.
		$this->catalogueService = $this->getMockBuilder(ServiceCatalogueService::class)
			->disableOriginalConstructor()
			->onlyMethods(['readCatalogue', 'getObjectService'])
			->getMock();
		$this->caseTypeService = $this->getMockBuilder(CaseTypeCatalogueService::class)
			->disableOriginalConstructor()
			->onlyMethods(['importDefinition', 'diffAgainstSource', 'applyResync', 'publishedLinks', 'syncedAt'])
			->getMock();

		$this->config->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') {
				if ($key === 'cors_allowed_origins') {
					return '*';
				}

				// Both register keys are configured, so the trait resolves.
				return '42';
			}
		);

		$this->container->method('get')->willReturnCallback(
			function (string $id) {
				if ($id === \OCP\IAppConfig::class) {
					return $this->config;
				}

				throw new \RuntimeException('not available: ' . $id);
			}
		);

		$this->controller = new ServiceCatalogueController(
			'opencatalogi',
			$this->request,
			$this->config,
			$this->container,
			$this->l10n,
			$this->userSession,
			$this->catalogueService,
			$this->caseTypeService,
			new KnowledgeArticleService(salt: 'test-salt')
		);

	}//end setUp()

	/**
	 * An unreadable catalogue answers 503 with a named error.
	 *
	 * The mirror failure of publishing too much: telling a resident their
	 * municipality offers nothing, when the truth is that this app could not
	 * read its own register.
	 */
	public function testAnUnreadableCatalogueAnswers503RatherThanAnEmptyList(): void {
		$this->request->method('getParams')->willReturn([]);
		$this->catalogueService->method('readCatalogue')
			->willThrowException(new CatalogueUnreadableException('OpenRegister is unavailable'));

		$response = $this->controller->index();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame('catalogue-unreadable', $response->getData()['error']);

	}//end testAnUnreadableCatalogueAnswers503RatherThanAnEmptyList()

	public function testThePublicCatalogueCarriesCorsHeaders(): void {
		$this->request->method('getParams')->willReturn([]);
		$this->catalogueService->method('readCatalogue')->willReturn(
			['entries' => [], 'groups' => [], 'unavailable' => 0, 'total' => 0]
		);

		$response = $this->controller->index();

		$this->assertSame('*', $response->getHeaders()['Access-Control-Allow-Origin']);

	}//end testThePublicCatalogueCarriesCorsHeaders()

	public function testAnUnavailableEntryIsServedRatherThanDropped(): void {
		$this->request->method('getParams')->willReturn([]);
		$this->catalogueService->method('readCatalogue')->willReturn(
			[
				'entries' => [
					['title' => 'Werkt', 'available' => true],
					['title' => 'Kapot', 'available' => false, 'unavailableReason' => 'unknown-case-type'],
				],
				'groups' => [],
				'unavailable' => 1,
				'total' => 2,
			]
		);

		$data = $this->controller->index()->getData();

		$this->assertCount(2, $data['entries']);
		$this->assertSame('unknown-case-type', $data['entries'][1]['unavailableReason']);

	}//end testAnUnavailableEntryIsServedRatherThanDropped()

	/**
	 * The national catalogue we could not ask is reported as unreachable.
	 *
	 * A 200 with an empty body here is the reading that sends an administrator
	 * to the wrong system, so the status itself is the assertion.
	 */
	public function testAnUnreachableSourceAnswers502AndNamesItself(): void {
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, $default = null) {
				return match ($key) {
					'sourceId' => 'i-navigator',
					'externalId' => 'verhuizing',
					default => $default,
				};
			}
		);
		$this->caseTypeService->method('importDefinition')
			->willThrowException(new ExternalCatalogueUnreachableException('the gateway is not installed'));

		$response = $this->controller->importCaseType();

		$this->assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());
		$this->assertSame('external-catalogue-unreachable', $response->getData()['error']);

	}//end testAnUnreachableSourceAnswers502AndNamesItself()

	public function testAnImportWithoutItsParametersIsRefused(): void {
		$this->request->method('getParam')->willReturn('');

		$response = $this->controller->importCaseType();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('missing-parameters', $response->getData()['error']);

	}//end testAnImportWithoutItsParametersIsRefused()

	public function testAnExtractionByAnUnauthenticatedCallerIsRefused(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$response = $this->controller->extractArticle();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testAnExtractionByAnUnauthenticatedCallerIsRefused()

	/**
	 * A published case type is answered with the links it publishes.
	 *
	 * The wire contract of `GET /api/case-types/{id}`: the definition, plus the
	 * form and API links resolved for it, plus when it was last synchronised.
	 */
	public function testAPublishedCaseTypeIsAnsweredWithItsLinks(): void {
		$definition = ['identifier' => 'vergunning', 'title' => 'Vergunning', 'published' => true];
		$objectService = new class($definition) {
			public function __construct(private readonly array $definition) {
			}

			public function find(string $id, string $register, string $schema): array {
				return $this->definition;
			}
		};
		$this->catalogueService->method('getObjectService')->willReturn($objectService);
		$this->caseTypeService->method('publishedLinks')->willReturn(
			['form' => 'https://example.org/form', 'apiDescription' => null, 'complete' => false]
		);
		$this->caseTypeService->method('syncedAt')->willReturn('2026-09-01T00:00:00+00:00');

		$response = $this->controller->caseType('vergunning');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('vergunning', $data['identifier']);
		$this->assertSame('https://example.org/form', $data['links']['form']);
		$this->assertSame('2026-09-01T00:00:00+00:00', $data['syncedAt']);

	}//end testAPublishedCaseTypeIsAnsweredWithItsLinks()

	/**
	 * A case type nobody can read answers 503, never an empty definition.
	 */
	public function testACaseTypeInAnUnreadableCatalogueAnswers503(): void {
		$this->catalogueService->method('getObjectService')
			->willThrowException(new CatalogueUnreadableException('OpenRegister is unavailable'));

		$response = $this->controller->caseType('vergunning');

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame('catalogue-unreadable', $response->getData()['error']);

	}//end testACaseTypeInAnUnreadableCatalogueAnswers503()
}//end class
