<?php

/**
 * Unit tests for PublicationRulesController.
 *
 * Asserted on the response the caller gets, because that is the thing that
 * either discloses or refuses. A service that behaves and a controller that
 * discards its answer look identical from inside the service.
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

use OCA\OpenCatalogi\Controller\PublicationRulesController;
use OCA\OpenCatalogi\Service\Publication\DecisionPublicationValidator;
use OCA\OpenCatalogi\Service\Publication\PublicationProcessService;
use OCA\OpenCatalogi\Service\Publication\PublicationRuleService;
use OCA\OpenCatalogi\Service\Publication\ZienswijzeService;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PublicationRulesController.
 */
class PublicationRulesControllerTest extends TestCase {

	private IRequest|MockObject $request;
	private IAppConfig|MockObject $config;
	private IUserSession|MockObject $userSession;
	private PublicationRulesController $controller;

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

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('ambtenaar');
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturn($user);

		$this->controller = new PublicationRulesController(
			'opencatalogi',
			$this->request,
			$this->config,
			$l10n,
			$this->userSession,
			new PublicationRuleService(),
			new DecisionPublicationValidator(),
			new PublicationProcessService(),
			new ZienswijzeService()
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

	public function testThePreviewNamesTheExposedPropertiesAndNotOnlyACount(): void {
		$this->withParams(
			[
				'rule' => [
					'recordType' => 'besluit',
					'anonymousProperties' => ['title'],
					'conditions' => [['property' => 'status', 'operator' => 'equals', 'value' => 'definitief']],
				],
				'sample' => [
					['@type' => 'besluit', 'status' => 'definitief', 'title' => 'Een', 'bsn' => '1'],
				],
			]
		);

		$data = $this->controller->previewRule()->getData();

		$this->assertTrue($data['valid']);
		$this->assertSame(['title'], $data['exposedProperties']);
		$this->assertArrayNotHasKey('bsn', $data['wouldPublish'][0]);

	}//end testThePreviewNamesTheExposedPropertiesAndNotOnlyACount()

	public function testARuleWithAnUnknownOperatorIsRefusedByThePreview(): void {
		$this->withParams(
			[
				'rule' => [
					'recordType' => 'besluit',
					'anonymousProperties' => ['title'],
					'conditions' => [['property' => 'status', 'operator' => 'sortOfLike', 'value' => 'x']],
				],
				'sample' => [],
			]
		);

		$data = $this->controller->previewRule()->getData();

		$this->assertFalse($data['valid']);
		$this->assertNotEmpty($data['errors']);

	}//end testARuleWithAnUnknownOperatorIsRefusedByThePreview()

	public function testADecisionThatFailsItsTypeIsRefusedWithTheReason(): void {
		$this->withParams(
			[
				'decision' => ['id' => 'b1'],
				'decisionType' => ['publicationObligation' => true, 'responseTermDays' => 42],
			]
		);

		$response = $this->controller->validateDecision();

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame('not-publishable', $response->getData()['error']);
		$this->assertNotEmpty($response->getData()['reasons']);

	}//end testADecisionThatFailsItsTypeIsRefusedWithTheReason()

	public function testAPublicationHeldByAnOpenAskAnswers409AndNamesIt(): void {
		$process = (new PublicationProcessService())->start(publicationId: 'p1');
		$ask = (new ZienswijzeService())->raise(
			publicationId: 'p1',
			party: 'J. de Vries',
			channel: 'digid',
			termDays: 3650
		);

		$this->withParams(['process' => $process, 'step' => 'channels', 'asks' => [$ask]]);

		$response = $this->controller->completeStep();

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame('J. de Vries', $response->getData()['heldBy'][0]['party']);

	}//end testAPublicationHeldByAnOpenAskAnswers409AndNamesIt()

	public function testAnAskOverAnUnidentifiedChannelIsRefused(): void {
		$this->withParams(
			['publication' => 'p1', 'party' => 'J. de Vries', 'channel' => 'anonymous-webform', 'termDays' => 14]
		);

		$response = $this->controller->raiseZienswijze();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('ask-refused', $response->getData()['error']);

	}//end testAnAskOverAnUnidentifiedChannelIsRefused()

	public function testThePublicSearchRunsOverTheProjections(): void {
		$this->withParams(
			[
				'records' => [
					['@type' => 'besluit', 'status' => 'definitief', 'title' => 'Dorpsstraat', 'bsn' => 'Zeldzaamwoord'],
				],
				'rules' => [
					['recordType' => 'besluit', 'enabled' => true, 'anonymousProperties' => ['title']],
				],
				'q' => 'Zeldzaamwoord',
			]
		);

		$data = $this->controller->publicSearch()->getData();

		$this->assertSame(0, $data['total']);

	}//end testThePublicSearchRunsOverTheProjections()

}//end class
