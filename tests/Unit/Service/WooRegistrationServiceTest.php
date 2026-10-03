<?php

/**
 * Unit tests for WooRegistrationService.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Service;

use DateTimeImmutable;
use OCA\OpenCatalogi\Service\Publication\IndexUnreachableException;
use OCA\OpenCatalogi\Service\Publication\NationalIndexService;
use OCA\OpenCatalogi\Service\SettingsService;
use OCA\OpenCatalogi\Service\WooReadinessService;
use OCA\OpenCatalogi\Service\WooRegistrationService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Compose, send and record the Woo-index registration.
 */
class WooRegistrationServiceTest extends TestCase {

	private MockObject&WooReadinessService $readiness;

	private MockObject&NationalIndexService $index;

	private MockObject&IAppConfig $config;

	/** @var array<string, string> What the service wrote to app config. */
	private array $written = [];

	protected function setUp(): void {
		parent::setUp();
		$this->readiness = $this->getMockBuilder(WooReadinessService::class)
			->disableOriginalConstructor()
			->onlyMethods(['getWooEnabledCatalogs', 'getRegistrationConfig'])
			->getMock();
		$this->readiness->method('getRegistrationConfig')->willReturnCallback(
			fn (): array => [
				'status' => ($this->written['woo_index_registration_status'] ?? 'not_registered'),
				'registeredUrl' => ($this->written['woo_index_registration_url'] ?? ''),
				'registeredAt' => ($this->written['woo_index_registration_at'] ?? ''),
			]
		);

		$woo = new ObjectEntity();
		$woo->setSlug('woo-a');
		$woo->setObject(['slug' => 'woo-a', 'hasWooSitemap' => true, 'organization' => 'org-1']);
		$this->readiness->method('getWooEnabledCatalogs')->willReturn([$woo]);

		$this->index = $this->getMockBuilder(NationalIndexService::class)
			->disableOriginalConstructor()
			->onlyMethods(['registerWithWooIndex'])
			->getMock();

		$this->config = $this->createMock(IAppConfig::class);
		$this->config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->written[$key] ?? $default)
		);
		$this->config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->written[$key] = $value;
				return true;
			}
		);
	}

	/**
	 * The service, on the given base URL.
	 *
	 * @param string $baseUrl The instance's base URL.
	 */
	private function service(string $baseUrl = 'https://woo.example.nl'): WooRegistrationService {
		$organisation = new ObjectEntity();
		$organisation->setObject(['name' => 'Gemeente Voorbeeld', 'tooiIdentifier' => 'https://identifier.overheid.nl/tooi/id/gemeente/gm9999']);
		$objectService = $this->getMockBuilder(ObjectService::class)
			->disableOriginalConstructor()
			->onlyMethods(['searchObjects'])
			->getMock();
		$objectService->method('searchObjects')->willReturn([$organisation]);
		$settings = $this->getMockBuilder(SettingsService::class)
			->disableOriginalConstructor()
			->onlyMethods(['getObjectService'])
			->getMock();
		$settings->method('getObjectService')->willReturn($objectService);

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getBaseUrl')->willReturn($baseUrl);

		return new WooRegistrationService($this->readiness, $this->index, $settings, $this->config, $urls);
	}

	/** REQ-WIH-003: the request carries the organisation, the root robots.txt and every sitemap index. */
	public function testTheRequestIsComposedFromWhatTheAppHolds(): void {
		$request = $this->service()->compose();

		$this->assertSame([['name' => 'Gemeente Voorbeeld', 'tooiIdentifier' => 'https://identifier.overheid.nl/tooi/id/gemeente/gm9999']], $request['organisations']);
		$this->assertSame('https://woo.example.nl/robots.txt', $request['robotsTxt']);
		$this->assertCount(17, $request['sitemapIndexes']);
		$this->assertSame('https://woo.example.nl/apps/opencatalogi/api/woo-a/sitemaps/sitemapindex-diwoo-infocat001.xml', $request['sitemapIndexes'][0]);
	}

	/** REQ-WIH-003: the gateway takes the request, and the status follows its answer. */
	public function testTheGatewayTakesTheRequest(): void {
		$this->index->expects($this->once())->method('registerWithWooIndex')
			->with($this->callback(static fn (array $request): bool => $request['robotsTxt'] === 'https://woo.example.nl/robots.txt'))
			->willReturn(['channel' => 'national-woo-index', 'registeredAt' => '2026-09-30T12:00:00+00:00', 'answer' => '{"id":"reg-7"}']);

		$result = $this->service()->request(new DateTimeImmutable('2026-09-30T12:00:00+00:00'));

		$this->assertTrue($result['sent']);
		$this->assertSame('requested', $result['registration']['status']);
		$this->assertSame('2026-09-30T12:00:00+00:00', $result['registration']['registeredAt']);
		$this->assertSame('{"id":"reg-7"}', $result['registration']['answer']);
		$this->assertSame('https://woo.example.nl', $result['registration']['registeredUrl']);
	}

	/** REQ-WIH-003: with no gateway nothing is recorded as sent, and the composed request comes back. */
	public function testNoGatewayIsInstalled(): void {
		$this->index->method('registerWithWooIndex')->willThrowException(new IndexUnreachableException('No gateway app is installed.'));

		$result = $this->service()->request();

		$this->assertFalse($result['sent']);
		$this->assertSame([], $this->written);
		$this->assertSame('not_registered', $result['registration']['status']);
		$this->assertSame('https://woo.example.nl/robots.txt', $result['request']['robotsTxt']);
		$this->assertCount(17, $result['request']['sitemapIndexes']);
	}

	/** REQ-WIH-002: the nginx and Apache rules target the app's route on the instance's own base URL. */
	public function testTheRootRulesFollowTheBaseUrl(): void {
		$rules = $this->service('https://example.nl/nextcloud/')->rootRules();

		$this->assertSame("location = /robots.txt {\n    rewrite ^ /nextcloud/index.php/apps/opencatalogi/api/robots.txt last;\n}", $rules['nginx']);
		$this->assertStringContainsString('RewriteRule ^/?robots\.txt$ /nextcloud/index.php/apps/opencatalogi/api/robots.txt [PT,L]', $rules['apache']);
		$this->assertSame('https://example.nl/nextcloud/index.php/apps/opencatalogi/api/robots.txt', $rules['robotsUrl']);
	}

	/** REQ-WIH-003: Mark as registered stores registered, the base URL and the time. */
	public function testMarkAsRegistered(): void {
		$registration = $this->service()->confirm(new DateTimeImmutable('2026-10-01T08:00:00+00:00'));

		$this->assertSame('registered', $registration['status']);
		$this->assertSame('https://woo.example.nl', $registration['registeredUrl']);
		$this->assertSame('2026-10-01T08:00:00+00:00', $registration['registeredAt']);
	}
}
