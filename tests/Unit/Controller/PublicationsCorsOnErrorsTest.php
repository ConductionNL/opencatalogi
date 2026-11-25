<?php

/**
 * OpenCatalogi public publication errors carry CORS headers.
 *
 * A browser on another origin drops an answer without
 * `Access-Control-Allow-Origin` as a network error, so a 404 or 500 from a
 * public publication endpoint must carry the CORS headers like a 200 does
 * (publications task 3, REQ-PUB-010).
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
 *
 * @spec openspec/changes/publications/specs/publications/spec.md#req-pub-010-all-public-endpoints-include-cors-headers
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Contract\CatalogiServiceInterface;
use OCA\OpenCatalogi\Contract\PublicationQueryServiceInterface;
use OCA\OpenCatalogi\Contract\PublicationServiceInterface;
use OCA\OpenCatalogi\Contract\UsageCounterServiceInterface;
use OCA\OpenCatalogi\Controller\PublicationsController;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\Response;
use OCP\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Unit\Support\ServerAwareRequest;

/**
 * The list and detail endpoints answer their errors with the CORS headers.
 */
class PublicationsCorsOnErrorsTest extends TestCase {

	private const ORIGIN = 'https://www.voorbeeldgemeente.nl';

	/**
	 * An unknown catalogue's list answers 404 with the CORS headers.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/publications/specs/publications/spec.md#req-pub-010-all-public-endpoints-include-cors-headers
	 */
	public function testAnUnknownCatalogueListAnswers404WithCors(): void {
		$response = $this->controller(catalogi: $this->catalogiAnswering(catalog: null))->index(catalogSlug: 'nergens');

		$this->assertSame(expected: 404, actual: $response->getStatus());
		$this->assertCors(response: $response);
	}//end testAnUnknownCatalogueListAnswers404WithCors()

	/**
	 * An unknown catalogue's publication answers 404 with the CORS headers.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/publications/specs/publications/spec.md#req-pub-010-all-public-endpoints-include-cors-headers
	 */
	public function testAnUnknownCataloguePublicationAnswers404WithCors(): void {
		$response = $this->controller(catalogi: $this->catalogiAnswering(catalog: null))->show(catalogSlug: 'nergens', id: 'abc');

		$this->assertSame(expected: 404, actual: $response->getStatus());
		$this->assertCors(response: $response);
	}//end testAnUnknownCataloguePublicationAnswers404WithCors()

	/**
	 * A failing catalogue lookup answers 500 with the CORS headers.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/publications/specs/publications/spec.md#req-pub-010-all-public-endpoints-include-cors-headers
	 */
	public function testAFailingListAnswers500WithCors(): void {
		$catalogi = $this->createMock(originalClassName: CatalogiServiceInterface::class);
		$catalogi->method('getCatalogBySlug')->willThrowException(new RuntimeException('database down'));

		$response = $this->controller(catalogi: $catalogi)->index(catalogSlug: 'woo');

		$this->assertSame(expected: 500, actual: $response->getStatus());
		$this->assertCors(response: $response);
	}//end testAFailingListAnswers500WithCors()

	/**
	 * Assert the CORS headers a cross-origin browser needs to read the answer.
	 *
	 * @param Response $response The answer.
	 *
	 * @return void
	 */
	private function assertCors(Response $response): void {
		$headers = $response->getHeaders();
		$this->assertSame(expected: self::ORIGIN, actual: $headers['Access-Control-Allow-Origin'] ?? null);
		$this->assertNotEmpty(actual: $headers['Access-Control-Allow-Methods'] ?? '');
		$this->assertNotEmpty(actual: $headers['Access-Control-Allow-Headers'] ?? '');
	}//end assertCors()

	/**
	 * A catalogue service whose slug lookup answers the given catalogue.
	 *
	 * @param array<string, mixed>|null $catalog The catalogue, or null for none.
	 *
	 * @return CatalogiServiceInterface The service.
	 */
	private function catalogiAnswering(?array $catalog): CatalogiServiceInterface {
		$catalogi = $this->createMock(originalClassName: CatalogiServiceInterface::class);
		$catalogi->method('getCatalogBySlug')->willReturn($catalog);
		return $catalogi;
	}//end catalogiAnswering()

	/**
	 * The controller for a browser on ORIGIN, with that origin allowed.
	 *
	 * @param CatalogiServiceInterface $catalogi The catalogue service.
	 *
	 * @return PublicationsController The controller.
	 */
	private function controller(CatalogiServiceInterface $catalogi): PublicationsController {
		$request = $this->createMock(originalClassName: ServerAwareRequest::class);
		$request->method('getHeader')->willReturnCallback(
			static function (string $name): string {
				if ($name === 'Origin') {
					return self::ORIGIN;
				}

				return '';
			}
		);
		$request->server = ['HTTP_ORIGIN' => self::ORIGIN];

		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				if ($key === 'cors_allowed_origins') {
					return self::ORIGIN;
				}

				return $default;
			}
		);

		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnArgument(argumentIndex: 0);

		return new PublicationsController(
			'opencatalogi',
			$request,
			$this->createMock(originalClassName: PublicationServiceInterface::class),
			$catalogi,
			$this->createMock(originalClassName: PublicationQueryServiceInterface::class),
			$this->createMock(originalClassName: ContainerInterface::class),
			$this->createMock(originalClassName: IAppManager::class),
			$this->createMock(originalClassName: LoggerInterface::class),
			$l10n,
			$this->createMock(originalClassName: UsageCounterServiceInterface::class),
			$config
		);
	}//end controller()
}//end class
