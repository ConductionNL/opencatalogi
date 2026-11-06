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

use OCA\OpenCatalogi\Controller\PublicationRuleChecks;
use OCA\OpenCatalogi\Controller\PublicationRulesController;
use OCA\OpenCatalogi\Service\Publication\DecisionPublicationValidator;
use OCA\OpenCatalogi\Service\Publication\PublicationRuleService;
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
			new PublicationRuleChecks(new PublicationRuleService(), new DecisionPublicationValidator())
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

	/**
	 * Validating a decision without a session is refused with 401, before the decision is read.
	 */
	public function testValidatingADecisionWithoutASessionIsRefused(): void {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$this->withParams(
			[
				'decision' => ['id' => 'b1'],
				'decisionType' => ['publicationObligation' => true, 'responseTermDays' => 42],
			]
		);

		$args = [];
		foreach ((new \ReflectionClass(PublicationRulesController::class))->getConstructor()->getParameters() as $param) {
			$type = $param->getType();
			$name = ($type instanceof \ReflectionNamedType ? $type->getName() : null);
			$args[] = match (true) {
				$param->getName() === 'appName' => 'opencatalogi',
				$name === IRequest::class => $this->request,
				$name === IAppConfig::class => $this->config,
				$name === IUserSession::class => $session,
				default => $this->createMock($name),
			};
		}

		$response = (new PublicationRulesController(...$args))->validateDecision();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'not-logged-in'], $response->getData());

	}//end testValidatingADecisionWithoutASessionIsRefused()

	/**
	 * A rule the public search cannot evaluate answers 503, not an empty result.
	 */
	public function testThePublicSearchRefusesAnUnreadableRuleWith503(): void {
		$this->withParams(
			[
				'records' => [['@type' => 'besluit', 'status' => 'definitief', 'title' => 'Een']],
				'rules' => [
					[
						'recordType' => 'besluit',
						'enabled' => true,
						'anonymousProperties' => ['title'],
						'conditions' => [['property' => 'status', 'operator' => 'sortOfLike', 'value' => 'x']],
					],
				],
				'q' => '',
			]
		);

		$response = $this->controller->publicSearch();

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame('unreadable-rule', $response->getData()['error']);

	}//end testThePublicSearchRefusesAnUnreadableRuleWith503()

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

	public function testEntriesThatAreNotRecordsAreLeftOutOfThePreviewAndTheSearch(): void {
		$this->withParams(
			[
				'rule' => [
					'recordType' => 'besluit',
					'anonymousProperties' => ['title'],
					'conditions' => [],
				],
				'sample' => ['not a record', 7, ['@type' => 'besluit', 'title' => 'Een']],
				'records' => ['not a record', ['@type' => 'besluit', 'title' => 'Dorpsstraat']],
				'rules' => ['not a rule', ['recordType' => 'besluit', 'enabled' => true, 'anonymousProperties' => ['title']]],
				'q' => 'Dorpsstraat',
			]
		);

		$preview = $this->controller->previewRule()->getData();
		$search = $this->controller->publicSearch()->getData();

		$this->assertSame([['title' => 'Een']], $preview['wouldPublish']);
		$this->assertSame(1, $search['total']);
		$this->assertSame('Dorpsstraat', $search['results'][0]['title']);

	}//end testEntriesThatAreNotRecordsAreLeftOutOfThePreviewAndTheSearch()

}//end class
