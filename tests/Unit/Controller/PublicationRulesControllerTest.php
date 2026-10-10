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
use OCA\OpenCatalogi\Service\Publication\PublicationRuleService;
use OCA\OpenCatalogi\Service\Publication\ObligationReadService;
use OCA\OpenCatalogi\Service\Publication\UnreadableRuleException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
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
	private ObligationReadService|MockObject $obligations;

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

		$this->obligations = $this->createMock(ObligationReadService::class);
		$this->controller = new PublicationRulesController(
			'opencatalogi',
			$this->request,
			$this->config,
			$l10n,
			$this->userSession,
			new PublicationRuleService(),
			new DecisionPublicationValidator(),
			$this->obligations,
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

	/**
	 * An admin reads the overview as the service assembled it, with the
	 * failing source named under `unreadSources`.
	 *
	 * @spec openspec/changes/woo-obligation-overview/specs/woo-compliance/spec.md#requirement-the-overview-reads-every-registered-source-and-shows-the-ones-it-could-not-read-req-woo-001
	 */
	public function testAnAdminReadsTheObligationOverview(): void {
		$overview = [
			'total' => 3, 'published' => 1, 'late' => 1, 'outstanding' => 2,
			'unreadSources' => [['appId' => 'filinq', 'reason' => 'Filinq is in maintenance.']],
			'obligations' => [['title' => 'A'], ['title' => 'B'], ['title' => 'C']],
			'sourcesRead' => 1, 'sourcesRegistered' => 2,
		];
		$this->obligations->expects($this->once())->method('read')->willReturn($overview);

		$response = $this->controller->obligations();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($overview, $response->getData());

	}//end testAnAdminReadsTheObligationOverview()

	/**
	 * The overview is admin-only: the middleware refuses anyone else with a
	 * 403 because of this attribute, and there is no public or user route to it.
	 *
	 * @spec openspec/changes/woo-obligation-overview/specs/woo-compliance/spec.md#requirement-the-overview-reads-every-registered-source-and-shows-the-ones-it-could-not-read-req-woo-001
	 */
	public function testANonAdminIsRefusedTheOverview(): void {
		$method = new \ReflectionMethod(PublicationRulesController::class, 'obligations');

		$this->assertCount(1, $method->getAttributes(AuthorizedAdminSetting::class));
		$this->assertSame([], $method->getAttributes(\OCP\AppFramework\Http\Attribute\NoAdminRequired::class));
		$this->assertSame([], $method->getAttributes(\OCP\AppFramework\Http\Attribute\PublicPage::class));
		$this->assertStringNotContainsString('@NoAdminRequired', (string)$method->getDocComment());
		$this->assertStringNotContainsString('@PublicPage', (string)$method->getDocComment());

	}//end testANonAdminIsRefusedTheOverview()

	/**
	 * Sources that cannot be read refuse with 503 rather than an empty overview.
	 *
	 * @spec openspec/changes/woo-obligation-overview/specs/woo-compliance/spec.md#requirement-the-overview-reads-every-registered-source-and-shows-the-ones-it-could-not-read-req-woo-001
	 */
	public function testUnconfiguredSourcesAnswer503RatherThanAnEmptyOverview(): void {
		$this->obligations->method('read')->willThrowException(new UnreadableRuleException('not configured'));

		$response = $this->controller->obligations();

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame('obligations-unreadable', $response->getData()['error']);

	}//end testUnconfiguredSourcesAnswer503RatherThanAnEmptyOverview()
}//end class
