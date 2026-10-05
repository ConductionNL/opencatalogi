<?php

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\DirectoryService;
use OCA\OpenCatalogi\Service\StandardsVersionService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for StandardsVersionService.
 *
 * @covers \OCA\OpenCatalogi\Service\StandardsVersionService
 */
class StandardsVersionServiceTest extends TestCase {

	private MockObject&IClientService $clientService;
	private MockObject&IClient $client;
	private MockObject&DirectoryService $directoryService;
	private StandardsVersionService $service;

	/**
	 * The publisher's own sentence, copied from standaarden.overheid.nl/diwoo/metadata.
	 *
	 * It links every superseded release elsewhere on the page, which is why the parser
	 * is anchored on this sentence instead of on the highest number it can find.
	 */
	private const DIWOO_INDEX = <<<'HTML'
<li class="nav-sub__sub-item"><a href="/diwoo/metadata/doc/0.9.5/diwoo-xsd-documentatie">Schemadocumentatie (versie 0.9.4)</a></li>
<p xmlns="http://www.w3.org/1999/xhtml">De huidige versie van standaard en publicatievoorwaarden is versie
<a href="/diwoo/metadata/doc/0.9.8/inleiding.html">v0.9.8</a>.</p>
HTML;

	/**
	 * The release index, copied from docs.geostandaarden.nl/dcat/.
	 */
	private const DCAT_INDEX = <<<'HTML'
<a href="cv-st-dcat-ap-nl30-20240416/">cv-st-dcat-ap-nl30-20240416/</a>
<a href="dcat-ap-nl30/">dcat-ap-nl30/</a>
<a href="def-st-dcat-ap-nl30-20241212/">def-st-dcat-ap-nl30-20241212/</a>
<a href="def-st-dcat-ap-nl30-20250526/">def-st-dcat-ap-nl30-20250526/</a>
<a href="vv-st-dcat-ap-nl30-20240708/">vv-st-dcat-ap-nl30-20240708/</a>
HTML;

	protected function setUp(): void {
		$this->clientService = $this->createMock(IClientService::class);
		$this->client = $this->createMock(IClient::class);
		$this->directoryService = $this->createMock(DirectoryService::class);

		$this->clientService->method('newClient')->willReturn($this->client);
		$this->directoryService->method('validateOutboundUrl');

		$this->service = new StandardsVersionService($this->clientService, $this->directoryService);
	}

	/**
	 * Build a mock IResponse.
	 *
	 * @param int $status The HTTP status code.
	 * @param string $body The response body.
	 *
	 * @return MockObject&IResponse
	 */
	private function mockResponse(int $status, string $body): MockObject {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn($body);

		return $response;
	}

	public function testTheDeclaredDiwooVersionIsTheOneTheSitemapSchemaLocationPointsAt(): void {
		$location = StandardsVersionService::diwooSchemaLocation();

		$this->assertStringContainsString(StandardsVersionService::DIWOO_NAMESPACE, $location);
		$this->assertStringContainsString(StandardsVersionService::DIWOO_METADATA_XSD, $location);
		$this->assertStringContainsString('/' . StandardsVersionService::DIWOO_VERSION . '/', StandardsVersionService::DIWOO_METADATA_XSD);
		$this->assertStringContainsString('/' . StandardsVersionService::DIWOO_VERSION . '/', StandardsVersionService::DIWOO_LISTS_XSD);
	}

	public function testDiwooVersionIsReadFromThePublishersOwnSentence(): void {
		$this->assertSame('0.9.8', StandardsVersionService::parseDiwooVersion(self::DIWOO_INDEX));
	}

	public function testDiwooVersionIsNullWhenThePublisherStatesNone(): void {
		$this->assertNull(StandardsVersionService::parseDiwooVersion('<p>niets hier</p>'));
	}

	public function testDcatVersionIgnoresConsultationRounds(): void {
		$this->assertSame('3.0', StandardsVersionService::parseDcatApNlVersion(self::DCAT_INDEX));
	}

	public function testDcatVersionIsNullWhenNoAdoptedReleaseIsListed(): void {
		$onlyConsultations = '<a href="vv-st-dcat-ap-nl40-20260101/">vv-st-dcat-ap-nl40-20260101/</a>';

		$this->assertNull(StandardsVersionService::parseDcatApNlVersion($onlyConsultations));
	}

	public function testWeAreCurrentWhenThePublishedVersionMatches(): void {
		$this->client->method('get')->willReturn($this->mockResponse(200, self::DIWOO_INDEX));

		$version = $this->service->diwooVersion();

		$this->assertSame(StandardsVersionService::DIWOO_VERSION, $version['declared']);
		$this->assertSame('0.9.8', $version['published']);
		$this->assertSame(StandardsVersionService::STATUS_CURRENT, $version['status']);
	}

	public function testANewerPublishedVersionReportsBehind(): void {
		$newer = str_replace('v0.9.8', 'v0.9.9', self::DIWOO_INDEX);
		$this->client->method('get')->willReturn($this->mockResponse(200, $newer));

		$version = $this->service->diwooVersion();

		$this->assertSame('0.9.9', $version['published']);
		$this->assertSame(StandardsVersionService::STATUS_BEHIND, $version['status']);
	}

	public function testAnUnreachableIndexReportsUnknownNotCurrent(): void {
		$this->client->method('get')->willReturn($this->mockResponse(503, ''));

		$version = $this->service->diwooVersion();

		$this->assertNull($version['published']);
		$this->assertSame(StandardsVersionService::STATUS_UNKNOWN, $version['status']);
	}

	public function testANetworkErrorReportsUnknownNotCurrent(): void {
		$this->client->method('get')->willThrowException(new \RuntimeException('no route'));

		$this->assertSame(StandardsVersionService::STATUS_UNKNOWN, $this->service->diwooVersion()['status']);
	}

	public function testAnSsrfRejectionReportsUnknownNotCurrent(): void {
		$directoryService = $this->createMock(DirectoryService::class);
		$directoryService->method('validateOutboundUrl')->willThrowException(new \InvalidArgumentException('blocked'));

		$service = new StandardsVersionService($this->clientService, $directoryService);

		$this->client->expects($this->never())->method('get');

		$this->assertSame(StandardsVersionService::STATUS_UNKNOWN, $service->diwooVersion()['status']);
	}

	public function testTheIndexIsFetchedOncePerRequest(): void {
		$this->client->expects($this->once())->method('get')->willReturn($this->mockResponse(200, self::DIWOO_INDEX));

		$this->service->diwooVersion();
		$this->service->diwooVersion();
	}

	public function testTheDcatProfileComparisonCarriesTheProfileIri(): void {
		$this->client->method('get')->willReturn($this->mockResponse(200, self::DCAT_INDEX));

		$profile = $this->service->dcatApNlVersion();

		$this->assertSame(StandardsVersionService::DCAT_AP_NL_PROFILE, $profile['profile']);
		$this->assertSame(StandardsVersionService::DCAT_AP_NL_VERSION, $profile['declared']);
		$this->assertSame(StandardsVersionService::STATUS_CURRENT, $profile['status']);
	}
}
