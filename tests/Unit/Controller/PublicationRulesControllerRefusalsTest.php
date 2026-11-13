<?php

/**
 * Unit tests for the request refusals of PublicationRulesController.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Controller\PublicationRuleChecks;
use OCA\OpenCatalogi\Controller\PublicationRulesController;
use OCA\OpenCatalogi\Service\Publication\UnreadableRuleException;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Malformed parameters are refused with 400 before the rule checks run, an
 * unreadable rule refuses the preview, and a publishable decision is passed
 * through as validated.
 *
 * @covers \OCA\OpenCatalogi\Controller\PublicationRulesController
 */
class PublicationRulesControllerRefusalsTest extends TestCase {

	private PublicationRuleChecks&MockObject $rules;

	/**
	 * The controller over a request answering the given parameters.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 */
	private function controller(array $params): PublicationRulesController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => (array_key_exists($key, $params) === true ? $params[$key] : $default)
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($this->createMock(IUser::class));
		$this->rules = $this->createMock(PublicationRuleChecks::class);

		return new PublicationRulesController(
			'opencatalogi',
			$request,
			$this->createMock(IAppConfig::class),
			$l10n,
			$userSession,
			$this->rules
		);
	}

	public function testAPreviewWithoutARuleIsRefused(): void {
		$controller = $this->controller(['rule' => []]);
		$this->rules->expects($this->never())->method('preview');

		$response = $controller->previewRule();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'missing-rule', 'message' => 'Send the rule to preview.'], $response->getData());
	}

	public function testANonArraySampleIsPreviewedAsEmpty(): void {
		$controller = $this->controller(['rule' => ['field' => 'status'], 'sample' => 'not-a-list']);
		$this->rules->expects($this->once())->method('preview')
			->with(['field' => 'status'], [])
			->willReturn(['exposed' => []]);

		$response = $controller->previewRule();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['exposed' => []], $response->getData());
	}

	public function testAnUnreadableRuleRefusesThePreview(): void {
		$controller = $this->controller(['rule' => ['op' => 'nonsense']]);
		$this->rules->method('preview')->willThrowException(new UnreadableRuleException('unknown operator "nonsense"'));

		$response = $controller->previewRule();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'unreadable-rule', 'message' => 'unknown operator "nonsense"'], $response->getData());
	}

	public function testADecisionWithoutItsTypeIsRefused(): void {
		$controller = $this->controller(['decision' => ['title' => 'Besluit']]);
		$this->rules->expects($this->never())->method('validateDecision');

		$response = $controller->validateDecision();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'missing-parameters', 'message' => 'Send the decision and its type.'], $response->getData());
	}

	public function testAPublishableDecisionIsAnsweredWithTheValidation(): void {
		$controller = $this->controller(['decision' => ['title' => 'Besluit'], 'decisionType' => ['name' => 'besluit']]);
		$this->rules->expects($this->once())->method('validateDecision')
			->with(['title' => 'Besluit'], ['name' => 'besluit'])
			->willReturn(['publishable' => true, 'reasons' => []]);

		$response = $controller->validateDecision();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['publishable' => true, 'reasons' => []], $response->getData());
	}

	public function testAPublicSearchOverNonArrayRecordsIsRefused(): void {
		$controller = $this->controller(['records' => 'all']);
		$this->rules->expects($this->never())->method('searchPublic');

		$response = $controller->publicSearch();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'missing-parameters'], $response->getData());
	}
}
