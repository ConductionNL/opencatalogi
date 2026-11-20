<?php

/**
 * Tests for ValueListsController and ValueListsService: every controlled-list entry on one route.
 *
 * @category Test
 * @package  Unit\Controller
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @version GIT: <git_id>
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Controller\ValueListsController;
use OCA\OpenCatalogi\Service\DcatVocabularyService;
use OCA\OpenCatalogi\Service\TooiVocabularyService;
use OCA\OpenCatalogi\Service\ValueListsService;
use OCA\OpenCatalogi\Service\Woo\RefusalGrounds;
use OCP\AppFramework\Http\Attribute\CORS;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * The real list services answer; only the request, the app config and the
 * logger are doubles. Without dossiq the refusal grounds come from the
 * vendored snapshot.
 *
 * @covers \OCA\OpenCatalogi\Controller\ValueListsController
 * @covers \OCA\OpenCatalogi\Service\ValueListsService
 * @uses   \OCA\OpenCatalogi\Service\TooiVocabularyService
 * @uses   \OCA\OpenCatalogi\Service\DcatVocabularyService
 * @uses   \OCA\OpenCatalogi\Service\Woo\RefusalGrounds
 */
class ValueListsControllerTest extends TestCase {

	/**
	 * The controller over the real lists.
	 *
	 * @param array<string, mixed> $params        The request parameters.
	 * @param string               $refreshState  The stored refresh state.
	 * @param string               $snapshotPath  The refusal grounds snapshot.
	 *
	 * @return ValueListsController
	 */
	private function controller(array $params = [], string $refreshState = '{}', string $snapshotPath = RefusalGrounds::SNAPSHOT_PATH): ValueListsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($params[$key] ?? $default)
		);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')
			->with('opencatalogi', ValueListsService::REFRESH_STATE_KEY, '{}')
			->willReturn($refreshState);

		$service = new ValueListsService(
			tooi: new TooiVocabularyService(),
			dcat: new DcatVocabularyService(),
			grounds: new RefusalGrounds(snapshotPath: $snapshotPath),
			appConfig: $config
		);

		return new ValueListsController('opencatalogi', $request, $service, $this->createMock(LoggerInterface::class));
	}//end controller()

	/**
	 * Every scheme OpenCatalogi uses is answered, each entry with a code, label and URI.
	 *
	 * @return void
	 */
	public function testEveryUsedSchemeIsListedWithUris(): void {
		$response = $this->controller()->index();

		$this->assertSame(200, $response->getStatus());
		$data = $response->getData();
		$this->assertSame([], $data['unavailable']);
		$this->assertSame(ValueListsService::SCHEMES, $data['schemes']);
		$this->assertSame(count($data['results']), $data['total']);

		$seen = array_unique(array_column($data['results'], 'scheme'));
		$this->assertSame(ValueListsService::SCHEMES, array_values($seen));

		foreach ($data['results'] as $row) {
			$this->assertSame(['scheme', 'schemeUri', 'code', 'label', 'uri', 'status', 'lastRefreshAt'], array_keys($row));
			$this->assertNotSame('', $row['code']);
			$this->assertMatchesRegularExpression('#^https?://#', $row['uri'], $row['scheme'] . ' ' . $row['code'] . ' has no URI');
		}

		$infocat = array_values(array_filter($data['results'], static fn (array $row): bool => $row['code'] === 'infocat012'));
		$this->assertSame(
			[
				'scheme' => 'informatiecategorieen',
				'schemeUri' => 'https://identifier.overheid.nl/tooi/set/scw_woo_informatiecategorieen',
				'code' => 'infocat012',
				'label' => 'Jaarplannen en jaarverslagen',
				'uri' => 'https://identifier.overheid.nl/tooi/def/thes/kern/c_c6cd1213',
				'status' => 'active',
				'lastRefreshAt' => null,
			],
			$infocat[0]
		);
	}//end testEveryUsedSchemeIsListedWithUris()

	/**
	 * A scheme filter answers that scheme only, with the refresh moment a refresh recorded.
	 *
	 * @return void
	 */
	public function testTheSchemeFilterAndTheRefreshMoment(): void {
		$response = $this->controller(
			params: ['scheme' => 'documentsoorten'],
			refreshState: '{"documentsoorten":{"lastRefreshAt":"2026-11-17T03:00:00+00:00","created":1}}'
		)->index();

		$data = $response->getData();
		$this->assertSame(['documentsoorten'], $data['schemes']);
		$this->assertNotEmpty($data['results']);
		foreach ($data['results'] as $row) {
			$this->assertSame('documentsoorten', $row['scheme']);
			$this->assertSame('2026-11-17T03:00:00+00:00', $row['lastRefreshAt']);
			$this->assertStringStartsWith('https://identifier.overheid.nl/tooi/set/ccw_plooi_documentsoorten', $row['schemeUri']);
		}
	}//end testTheSchemeFilterAndTheRefreshMoment()

	/**
	 * An unknown scheme is a 400 naming the schemes, not an empty list.
	 *
	 * @return void
	 */
	public function testAnUnknownSchemeIsRefused(): void {
		$response = $this->controller(params: ['scheme' => 'nope'])->index();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => 'unknown_scheme', 'schemes' => ValueListsService::SCHEMES], $response->getData());
	}//end testAnUnknownSchemeIsRefused()

	/**
	 * An unreadable list is named in `unavailable` and the others still answer.
	 *
	 * @return void
	 */
	public function testAnUnreadableListIsNamedNotEmptied(): void {
		$data = $this->controller(snapshotPath: '/nonexistent/snapshot.json')->index()->getData();

		$this->assertSame(['weigeringsgronden'], $data['unavailable']);
		$this->assertNotContains('weigeringsgronden', array_column($data['results'], 'scheme'));
		$this->assertContains('informatiecategorieen', array_column($data['results'], 'scheme'));
	}//end testAnUnreadableListIsNamedNotEmptied()

	/**
	 * The route is public and CORS-enabled for integrators.
	 *
	 * @return void
	 */
	public function testTheRouteIsPublicAndCrossOrigin(): void {
		$method = new ReflectionMethod(ValueListsController::class, 'index');

		$this->assertCount(1, $method->getAttributes(PublicPage::class));
		$this->assertCount(1, $method->getAttributes(CORS::class));
	}//end testTheRouteIsPublicAndCrossOrigin()
}//end class
