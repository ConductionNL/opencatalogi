<?php

/**
 * Unit tests for PublicationDisclosureController.
 *
 * Asserted on the response the caller gets, because that is the thing that
 * either discloses or refuses. A service that behaves and a controller that
 * discards its answer look identical from inside the service.
 *
 * Moved here from PublicationRulesControllerTest on 2026-09-19 with the
 * announcement and document-stamp endpoints, unchanged.
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

use OCA\OpenCatalogi\Controller\PublicationDisclosureController;
use OCA\OpenCatalogi\Service\Publication\IndexUnreachableException;
use OCA\OpenCatalogi\Service\Publication\NationalIndexService;
use OCA\OpenCatalogi\Service\Publication\PublicationRuleService;
use OCA\OpenCatalogi\Service\Publication\PublishedCollectionsService;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PublicationDisclosureController.
 */
class PublicationDisclosureControllerTest extends TestCase {

	private IRequest|MockObject $request;
	private IAppConfig|MockObject $config;
	private NationalIndexService|MockObject $indexService;
	private PublicationDisclosureController $controller;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->config = $this->createMock(IAppConfig::class);
		$this->config->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') {
				return $default;
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		// onlyMethods against the real class: the double cannot answer a call
		// the production class would not have.
		$this->indexService = $this->getMockBuilder(NationalIndexService::class)
			->disableOriginalConstructor()
			->onlyMethods(['composeNotices', 'deliver'])
			->getMock();

		$this->controller = new PublicationDisclosureController(
			'opencatalogi',
			$this->request,
			$this->indexService,
			new PublishedCollectionsService($this->config, new PublicationRuleService(), 'opencatalogi')
		);

	}//end setUp()

	/**
	 * Answer request parameters from a map.
	 *
	 * @param array<string, mixed> $params The parameters.
	 *
	 * @return void
	 */
	private function withParams(array $params): void {
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, $default = null) use ($params) {
				return ($params[$key] ?? $default);
			}
		);

	}//end withParams()

	/**
	 * An announcement that reached neither channel answers 502 and names the
	 * channels. A 200 here would let an operator read a partial announcement as
	 * a complete one.
	 */
	public function testAnUndeliveredAnnouncementAnswers502AndNamesTheChannels(): void {
		$this->indexService->method('composeNotices')->willReturn(
			[['channel' => 'national-publication-platform'], ['channel' => 'local-channel']]
		);
		$this->indexService->method('deliver')->willThrowException(new IndexUnreachableException('no gateway'));

		$this->withParams(['decision' => ['id' => 'b1', 'title' => 'Kapvergunning']]);

		$response = $this->controller->announce();

		$this->assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());
		$this->assertFalse($response->getData()['complete']);
		$this->assertCount(2, $response->getData()['unreachable']);
		$this->assertCount(2, $response->getData()['notices']);

	}//end testAnUndeliveredAnnouncementAnswers502AndNamesTheChannels()

}//end class
