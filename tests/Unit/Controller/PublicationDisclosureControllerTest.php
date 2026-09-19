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
use OCA\OpenCatalogi\Service\Publication\DocumentStampService;
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
			$this->config,
			$l10n,
			$this->indexService,
			new DocumentStampService(signingKey: 'test-key'),
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

	public function testTheVerificationKeyIsPublishedAndIsNotTheKey(): void {
		$data = $this->controller->verificationKey()->getData();

		$this->assertSame('default', $data['keyId']);
		$this->assertStringNotContainsString('test-key', (string)json_encode($data));

	}//end testTheVerificationKeyIsPublishedAndIsNotTheKey()

	/**
	 * A document verifies against the stamp that was made for it.
	 *
	 * The wire contract of `POST /api/publications/verify`: the reader sends
	 * the document, its publication metadata and the stamp, and gets a verdict.
	 */
	public function testAStampedDocumentVerifiesThroughTheEndpoint(): void {
		$stampService = new DocumentStampService(signingKey: 'test-key');
		$metadata = ['publication' => 'p1', 'publishedAt' => '2026-09-01T00:00:00+00:00'];
		$stamp = $stampService->stamp(documentBytes: 'the document bytes', metadata: $metadata);

		$this->withParams(
			[
				'document' => base64_encode('the document bytes'),
				'metadata' => $metadata,
				'stamp' => $stamp,
			]
		);

		$response = $this->controller->verifyDocument();

		$this->assertTrue($response->getData()['valid']);

	}//end testAStampedDocumentVerifiesThroughTheEndpoint()

	/**
	 * A document that was changed after it was stamped does not verify.
	 *
	 * The half that matters: a verify endpoint that answered `valid` for
	 * everything would tell every reader a document is authentic while nobody
	 * checked anything.
	 */
	public function testAChangedDocumentDoesNotVerify(): void {
		$stampService = new DocumentStampService(signingKey: 'test-key');
		$metadata = ['publication' => 'p1', 'publishedAt' => '2026-09-01T00:00:00+00:00'];
		$stamp = $stampService->stamp(documentBytes: 'the document bytes', metadata: $metadata);

		$this->withParams(
			[
				'document' => base64_encode('the document bytes, altered'),
				'metadata' => $metadata,
				'stamp' => $stamp,
			]
		);

		$data = $this->controller->verifyDocument()->getData();

		$this->assertFalse($data['valid']);
		$this->assertSame('does-not-match', $data['reason']);

	}//end testAChangedDocumentDoesNotVerify()

	/**
	 * A verify call without a stamp is refused, never answered `valid`.
	 */
	public function testAVerifyWithoutAStampIsRefused(): void {
		$this->withParams(['document' => 'x', 'metadata' => [], 'stamp' => []]);

		$response = $this->controller->verifyDocument();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('missing-parameters', $response->getData()['error']);

	}//end testAVerifyWithoutAStampIsRefused()

	/**
	 * The preflight answers on this controller too, so the two public
	 * verification URLs keep the OPTIONS route that moved with them.
	 */
	public function testThePreflightAnswersOnTheVerificationSurface(): void {
		$response = $this->controller->preflightedCors();

		$this->assertSame('*', $response->getHeaders()['Access-Control-Allow-Origin']);
		$this->assertSame('false', $response->getHeaders()['Access-Control-Allow-Credentials']);

	}//end testThePreflightAnswersOnTheVerificationSurface()

}//end class
