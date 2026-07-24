<?php

declare(strict_types=1);

namespace Unit\Service;

use InvalidArgumentException;
use OCA\OpenCatalogi\Service\DirectoryService;
use OCA\OpenCatalogi\Service\SettingsService;
use OCA\OpenCatalogi\Service\SitemapService;
use OCA\OpenCatalogi\Service\WooReadinessService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for WooReadinessService.
 *
 * @covers \OCA\OpenCatalogi\Service\WooReadinessService
 */
class WooReadinessServiceTest extends TestCase
{

    private MockObject&IClientService $clientService;
    private MockObject&IClient $client;
    private MockObject&DirectoryService $directoryService;
    private MockObject&SitemapService $sitemapService;
    private MockObject&SettingsService $settingsService;
    private MockObject&IAppConfig $config;
    private MockObject&IURLGenerator $urlGenerator;
    private WooReadinessService $service;

    private const BASE_URL = 'https://example.org';

    private const SITEMAPINDEX_XML = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    <sitemap>
        <loc>https://example.org/apps/opencatalogi/api/demo-catalog/sitemaps/sitemapindex-diwoo-infocat001.xml/publications?page=1</loc>
        <lastmod>2026-01-01</lastmod>
    </sitemap>
</sitemapindex>
XML;

    private const CATEGORY_SITEMAP_XML = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<diwoo:Documents xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:diwoo="https://standaarden.overheid.nl/diwoo/metadata/">
    <diwoo:Document>
        <loc>https://example.org/downloads/document-1.pdf</loc>
        <lastmod>2026-01-01</lastmod>
    </diwoo:Document>
</diwoo:Documents>
XML;

    protected function setUp(): void
    {
        $this->clientService   = $this->createMock(IClientService::class);
        $this->client          = $this->createMock(IClient::class);
        $this->directoryService = $this->createMock(DirectoryService::class);
        $this->sitemapService   = $this->createMock(SitemapService::class);
        $this->settingsService  = $this->createMock(SettingsService::class);
        $this->config           = $this->createMock(IAppConfig::class);
        $this->urlGenerator     = $this->createMock(IURLGenerator::class);

        $this->clientService->method('newClient')->willReturn($this->client);
        $this->urlGenerator->method('getBaseUrl')->willReturn(self::BASE_URL);

        // SSRF guard is a no-op by default (does not throw); individual tests override.
        $this->directoryService->method('validateOutboundUrl');

        $this->service = new WooReadinessService(
            $this->clientService,
            $this->directoryService,
            $this->sitemapService,
            $this->settingsService,
            $this->config,
            $this->urlGenerator
        );
    }

    /**
     * Build a mock WOO-enabled catalog entity with the given slug.
     *
     * @param string $slug The catalog slug.
     *
     * @return MockObject
     */
    private function mockCatalog(string $slug): MockObject
    {
        $catalog = $this->getMockBuilder(\OCA\OpenRegister\Db\ObjectEntity::class)
            ->disableOriginalConstructor()
            ->addMethods(['getSlug'])
            ->getMock();
        $catalog->method('getSlug')->willReturn($slug);

        return $catalog;
    }

    /**
     * Configure settingsService + an ObjectService mock to return the given WOO-enabled catalogs.
     *
     * @param array<int, MockObject> $catalogs The catalog mocks to return.
     *
     * @return void
     */
    private function configureCatalogs(array $catalogs): void
    {
        $this->settingsService->method('getSettings')->willReturn([
            'configuration' => [
                'catalog_register' => '1',
                'catalog_schema'   => '5',
            ],
        ]);

        $objectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
        $objectService->method('searchObjectsPaginated')->willReturn(['results' => $catalogs]);

        $this->settingsService->method('getObjectService')->willReturn($objectService);
    }

    /**
     * Build a mock IResponse.
     *
     * @param int    $status The HTTP status code.
     * @param string $body   The response body.
     *
     * @return MockObject&IResponse
     */
    private function mockResponse(int $status, string $body): MockObject
    {
        $response = $this->createMock(IResponse::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('getBody')->willReturn($body);

        return $response;
    }

    public function testHasWooEnabledCatalogsFalseWhenNoCatalogRegisterConfigured(): void
    {
        $this->settingsService->method('getSettings')->willReturn(['configuration' => []]);

        $this->client->expects($this->never())->method('get');

        $this->assertFalse($this->service->hasWooEnabledCatalogs());
    }

    public function testHasWooEnabledCatalogsTrueWhenCatalogPresent(): void
    {
        $this->configureCatalogs([$this->mockCatalog('demo-catalog')]);

        $this->assertTrue($this->service->hasWooEnabledCatalogs());
    }

    public function testGetPersistedReportPerformsZeroOutboundRequests(): void
    {
        $this->config->method('getValueString')->willReturn('');

        $this->client->expects($this->never())->method('get');

        $this->assertNull($this->service->getPersistedReport());
    }

    public function testGetPersistedReportReturnsDecodedReport(): void
    {
        $report = ['verdict' => 'ready', 'checks' => []];
        $this->config->method('getValueString')->willReturnCallback(
            function (string $app, string $key, string $default = '') use ($report) {
                if ($key === 'woo_readiness_report') {
                    return json_encode($report);
                }
                return $default;
            }
        );

        $this->client->expects($this->never())->method('get');

        $this->assertSame($report, $this->service->getPersistedReport());
    }

    public function testRunCheckAllPassReportsReady(): void
    {
        $this->configureCatalogs([$this->mockCatalog('demo-catalog')]);

        $this->config->method('getValueString')->willReturnCallback(
            function (string $app, string $key, string $default = '') {
                return match ($key) {
                    'woo_index_registration_status' => 'registered',
                    'woo_index_registration_url' => self::BASE_URL,
                    'woo_index_registration_at' => '2026-01-01',
                    default => $default,
                };
            }
        );

        $this->client->method('get')->willReturnCallback(
            function (string $url) {
                if (str_ends_with($url, '/robots.txt') === true) {
                    return $this->mockResponse(200, "Sitemap: $url/apps/opencatalogi/api/demo-catalog/sitemaps/x\n");
                }

                if (str_contains($url, '/sitemaps/sitemapindex-diwoo-infocat001.xml') === true
                    && str_contains($url, 'publications') === false
                ) {
                    return $this->mockResponse(200, self::SITEMAPINDEX_XML);
                }

                if (str_contains($url, 'publications') === true) {
                    return $this->mockResponse(200, self::CATEGORY_SITEMAP_XML);
                }

                if (str_contains($url, 'document-1.pdf') === true) {
                    return $this->mockResponse(200, 'binary-content');
                }

                return $this->mockResponse(404, '');
            }
        );

        $this->sitemapService->method('validateDiwooOutput')->willReturn([
            'catalogSlug'  => 'demo-catalog',
            'categoryCode' => 'sitemapindex-diwoo-infocat001.xml',
            'valid'        => true,
            'violations'   => [],
        ]);

        $this->config->expects($this->once())->method('setValueString')
            ->with('opencatalogi', 'woo_readiness_report', $this->isType('string'));

        $report = $this->service->runCheck();

        $this->assertSame('ready', $report['verdict']);
        foreach ($report['checks'] as $check) {
            $this->assertSame('pass', $check['status'], (string) json_encode($check));
        }
    }

    public function testRunCheckSitemapIndex404FailsAndSkipsDependents(): void
    {
        $this->configureCatalogs([$this->mockCatalog('demo-catalog')]);

        $this->client->method('get')->willReturnCallback(
            function (string $url) {
                if (str_ends_with($url, '/robots.txt') === true) {
                    return $this->mockResponse(200, "Sitemap: $url/apps/opencatalogi/api/demo-catalog/sitemaps/x\n");
                }

                // Sitemapindex 404s.
                return $this->mockResponse(404, '');
            }
        );

        $report = $this->service->runCheck();

        $this->assertSame('not-ready', $report['verdict']);

        $byId = [];
        foreach ($report['checks'] as $check) {
            $byId[$check['id']] = $check;
        }

        $this->assertSame('fail', $byId['sitemapindex:demo-catalog']['status']);
        $this->assertSame('http-404', $byId['sitemapindex:demo-catalog']['reason']);

        $this->assertSame('skipped', $byId['category-sitemap:demo-catalog']['status']);
        $this->assertSame('sitemapindex-unreachable', $byId['category-sitemap:demo-catalog']['reason']);
        $this->assertSame('skipped', $byId['diwoo-xsd:demo-catalog']['status']);
        $this->assertSame('skipped', $byId['publication-sample:demo-catalog']['status']);

        // Prerequisite-failed checks must never report pass.
        foreach (['category-sitemap:demo-catalog', 'diwoo-xsd:demo-catalog', 'publication-sample:demo-catalog'] as $id) {
            $this->assertNotSame('pass', $byId[$id]['status']);
        }
    }

    public function testRunCheckInvalidXmlSitemapindexFails(): void
    {
        $this->configureCatalogs([$this->mockCatalog('demo-catalog')]);

        $this->client->method('get')->willReturnCallback(
            function (string $url) {
                if (str_ends_with($url, '/robots.txt') === true) {
                    return $this->mockResponse(200, "Sitemap: $url/apps/opencatalogi/api/demo-catalog/sitemaps/x\n");
                }

                return $this->mockResponse(200, '<not-well-formed');
            }
        );

        $report = $this->service->runCheck();

        $byId = [];
        foreach ($report['checks'] as $check) {
            $byId[$check['id']] = $check;
        }

        $this->assertSame('fail', $byId['sitemapindex:demo-catalog']['status']);
        $this->assertSame('invalid-xml', $byId['sitemapindex:demo-catalog']['reason']);
        $this->assertSame('not-ready', $report['verdict']);
    }

    public function testRunCheckDiwooXsdInvalidFails(): void
    {
        $this->configureCatalogs([$this->mockCatalog('demo-catalog')]);

        $this->client->method('get')->willReturnCallback(
            function (string $url) {
                if (str_ends_with($url, '/robots.txt') === true) {
                    return $this->mockResponse(200, "Sitemap: $url/apps/opencatalogi/api/demo-catalog/sitemaps/x\n");
                }

                if (str_contains($url, 'publications') === false) {
                    return $this->mockResponse(200, self::SITEMAPINDEX_XML);
                }

                return $this->mockResponse(200, self::CATEGORY_SITEMAP_XML);
            }
        );

        $this->sitemapService->method('validateDiwooOutput')->willReturn([
            'catalogSlug'  => 'demo-catalog',
            'categoryCode' => 'sitemapindex-diwoo-infocat001.xml',
            'valid'        => false,
            'violations'   => [['documentLoc' => 'x', 'axis' => 'publisher', 'reason' => 'unresolved']],
        ]);

        $report = $this->service->runCheck();

        $byId = [];
        foreach ($report['checks'] as $check) {
            $byId[$check['id']] = $check;
        }

        $this->assertSame('fail', $byId['diwoo-xsd:demo-catalog']['status']);
        $this->assertSame('diwoo-xsd-invalid', $byId['diwoo-xsd:demo-catalog']['reason']);
        $this->assertSame('not-ready', $report['verdict']);
    }

    public function testRunCheckSsrfGuardRejectionFailsCheckAndSkipsFetch(): void
    {
        $this->configureCatalogs([$this->mockCatalog('demo-catalog')]);

        // Every outbound URL is rejected by the SSRF guard.
        $this->directoryService->method('validateOutboundUrl')
            ->willThrowException(new InvalidArgumentException('blocked'));

        $this->client->expects($this->never())->method('get');

        $report = $this->service->runCheck();

        $byId = [];
        foreach ($report['checks'] as $check) {
            $byId[$check['id']] = $check;
        }

        $this->assertSame('fail', $byId['robots-txt']['status']);
        $this->assertSame('ssrf-blocked', $byId['robots-txt']['reason']);
        $this->assertSame('not-ready', $report['verdict']);
    }

    public function testRunCheckRegistrationUrlMismatchFails(): void
    {
        $this->configureCatalogs([$this->mockCatalog('demo-catalog')]);

        $this->config->method('getValueString')->willReturnCallback(
            function (string $app, string $key, string $default = '') {
                return match ($key) {
                    'woo_index_registration_status' => 'registered',
                    'woo_index_registration_url' => 'https://old.example.org',
                    'woo_index_registration_at' => '2025-01-01',
                    default => $default,
                };
            }
        );

        $this->client->method('get')->willReturnCallback(
            function (string $url) {
                if (str_ends_with($url, '/robots.txt') === true) {
                    return $this->mockResponse(200, "Sitemap: $url/apps/opencatalogi/api/demo-catalog/sitemaps/x\n");
                }

                if (str_contains($url, 'publications') === false) {
                    return $this->mockResponse(200, self::SITEMAPINDEX_XML);
                }

                if (str_contains($url, 'document-1.pdf') === true) {
                    return $this->mockResponse(200, 'binary-content');
                }

                return $this->mockResponse(200, self::CATEGORY_SITEMAP_XML);
            }
        );

        $this->sitemapService->method('validateDiwooOutput')->willReturn([
            'catalogSlug'  => 'demo-catalog',
            'categoryCode' => 'sitemapindex-diwoo-infocat001.xml',
            'valid'        => true,
            'violations'   => [],
        ]);

        $report = $this->service->runCheck();

        $byId = [];
        foreach ($report['checks'] as $check) {
            $byId[$check['id']] = $check;
        }

        $this->assertSame('fail', $byId['registration']['status']);
        $this->assertSame('url-mismatch', $byId['registration']['reason']);
        $this->assertSame('not-ready', $report['verdict']);
        $this->assertSame('https://old.example.org', $report['registration']['registeredUrl']);
    }

    public function testRunCheckUnconfiguredProducesNoCatalogChecks(): void
    {
        // The fail-closed gate (WOO-HR-004) lives in the controller: it calls
        // hasWooEnabledCatalogs() and never invokes runCheck() at all when that is
        // false. runCheck() itself is documented as requiring that precondition; here
        // we only assert that with an empty catalog set it produces no per-catalog
        // checks (the robots.txt prerequisite check still runs — it is not itself a
        // WOO-enabled-catalog check).
        $this->settingsService->method('getSettings')->willReturn(['configuration' => []]);
        $this->client->method('get')->willReturn($this->mockResponse(200, "Sitemap: /sitemaps/x\n"));

        $report = $this->service->runCheck();

        $catalogChecks = array_filter(
            $report['checks'],
            fn (array $check) => isset($check['catalogSlug']) === true
        );

        $this->assertSame([], $catalogChecks);
    }
}
