<?php

/**
 * Unit tests for the admin panel answers of the Woo-index registration, the
 * DIWOO sitemap report and the published-collections configuration.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Contract\IndexRegistrationServiceInterface;
use OCA\OpenCatalogi\Contract\NationalIndexServiceInterface;
use OCA\OpenCatalogi\Contract\PublishedCollectionsServiceInterface;
use OCA\OpenCatalogi\Contract\SitemapServiceInterface;
use OCA\OpenCatalogi\Controller\PublicationDisclosureController;
use OCA\OpenCatalogi\Controller\SitemapController;
use OCA\OpenCatalogi\Controller\WooRegistrationController;
use OCA\OpenCatalogi\Service\Publication\UnreadableRuleException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Each admin endpoint passes the service's answer through unchanged and maps
 * the service's refusals to the documented status codes.
 *
 * @covers \OCA\OpenCatalogi\Controller\WooRegistrationController
 * @covers \OCA\OpenCatalogi\Controller\SitemapController
 * @covers \OCA\OpenCatalogi\Controller\PublicationDisclosureController
 */
class AdminPanelControllersTest extends TestCase {

	private IRequest&MockObject $request;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
	}

	/**
	 * The disclosure controller over the given collections service.
	 *
	 * @param PublishedCollectionsServiceInterface $collections The collections service.
	 */
	private function disclosure(PublishedCollectionsServiceInterface $collections): PublicationDisclosureController {
		return new PublicationDisclosureController(
			'opencatalogi',
			$this->request,
			$this->createMock(NationalIndexServiceInterface::class),
			$collections
		);
	}

	public function testTheRegistrationPanelShowsTheStoredRegistrationRequestAndRules(): void {
		$service = $this->createMock(IndexRegistrationServiceInterface::class);
		$service->expects($this->once())->method('registration')->willReturn(['status' => 'requested']);
		$service->expects($this->once())->method('compose')->willReturn(['robotsTxt' => 'https://woo.example.nl/robots.txt']);
		$service->expects($this->once())->method('rootRules')->willReturn(['nginx' => 'location = /robots.txt {}']);

		$response = (new WooRegistrationController('opencatalogi', $this->request, $service))->show();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			[
				'registration' => ['status' => 'requested'],
				'request' => ['robotsTxt' => 'https://woo.example.nl/robots.txt'],
				'rules' => ['nginx' => 'location = /robots.txt {}'],
			],
			$response->getData()
		);
	}

	public function testConfirmingStoresAndReturnsTheRegistration(): void {
		$service = $this->createMock(IndexRegistrationServiceInterface::class);
		$service->expects($this->once())->method('confirm')->willReturn(['status' => 'registered']);

		$response = (new WooRegistrationController('opencatalogi', $this->request, $service))->confirm();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['registration' => ['status' => 'registered']], $response->getData());
	}

	public function testTheDiwooReportValidatesTheRequestedPage(): void {
		$this->request->method('getParams')->willReturn(['page' => '3']);
		$report = ['documents' => 2, 'violations' => [['axis' => 'publisher']]];
		$service = $this->createMock(SitemapServiceInterface::class);
		$service->expects($this->once())->method('validateDiwooOutput')->with('woo', 'infocat014', 3)->willReturn($report);

		$response = (new SitemapController('opencatalogi', $this->request, $service))->diwooReport('woo', 'infocat014');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame($report, $response->getData());
	}

	public function testTheDiwooReportDefaultsToTheFirstPage(): void {
		$this->request->method('getParams')->willReturn([]);
		$service = $this->createMock(SitemapServiceInterface::class);
		$service->expects($this->once())->method('validateDiwooOutput')->with('woo', 'infocat001', 1)->willReturn([]);

		(new SitemapController('opencatalogi', $this->request, $service))->diwooReport('woo', 'infocat001');
	}

	public function testThePublishedCollectionsAreListed(): void {
		$collections = $this->createMock(PublishedCollectionsServiceInterface::class);
		$collections->method('collections')->willReturn([['schema' => 'besluit', 'conditions' => []]]);

		$response = $this->disclosure($collections)->publishedCollections();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['collections' => [['schema' => 'besluit', 'conditions' => []]]], $response->getData());
	}

	public function testAnUnreadableConfigurationAnswers503(): void {
		$collections = $this->createMock(PublishedCollectionsServiceInterface::class);
		$collections->method('collections')->willThrowException(new UnreadableRuleException('stored rule is not JSON'));

		$response = $this->disclosure($collections)->publishedCollections();

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame(['error' => 'unreadable-configuration', 'message' => 'stored rule is not JSON'], $response->getData());
	}

	public function testSavingANonArrayIsRefusedWithoutWriting(): void {
		$this->request->method('getParam')->with('collections', [])->willReturn('besluit');
		$collections = $this->createMock(PublishedCollectionsServiceInterface::class);
		$collections->expects($this->never())->method('save');

		$response = $this->disclosure($collections)->savePublishedCollections();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'missing-collections'], $response->getData());
	}

	public function testAnInvalidSetIsRefusedWithTheServiceErrors(): void {
		$this->request->method('getParam')->willReturn(['a' => ['schema' => '']]);
		$collections = $this->createMock(PublishedCollectionsServiceInterface::class);
		$collections->expects($this->once())->method('save')->with([['schema' => '']])
			->willReturn(['saved' => false, 'errors' => ['0.schema' => 'required']]);

		$response = $this->disclosure($collections)->savePublishedCollections();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'invalid-collections', 'errors' => ['0.schema' => 'required']], $response->getData());
	}

	public function testAValidSetIsSavedAsAList(): void {
		$this->request->method('getParam')->willReturn(['x' => ['schema' => 'besluit'], 'y' => ['schema' => 'advies']]);
		$collections = $this->createMock(PublishedCollectionsServiceInterface::class);
		$collections->expects($this->once())->method('save')
			->with([['schema' => 'besluit'], ['schema' => 'advies']])
			->willReturn(['saved' => true, 'errors' => []]);

		$response = $this->disclosure($collections)->savePublishedCollections();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['saved' => true], $response->getData());
	}
}
