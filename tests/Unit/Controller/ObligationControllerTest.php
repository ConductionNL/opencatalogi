<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Tests for ObligationController: the overview for an administrator, and 503
 * rather than an empty overview when the sources cannot be read.
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Controller\ObligationController;
use OCA\OpenCatalogi\Service\Publication\ObligationReadService;
use OCA\OpenCatalogi\Settings\OpenCatalogiAdmin;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

/**
 * Unit tests for ObligationController.
 */
class ObligationControllerTest extends TestCase {

	/**
	 * @param ObligationReadService $readService The read service double.
	 *
	 * @return ObligationController The controller.
	 */
	private function controller(ObligationReadService $readService): ObligationController {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new ObligationController('opencatalogi', $this->createMock(IRequest::class), $l10n, $readService);

	}//end controller()

	public function testAnswersTheOverview(): void {
		$readService = $this->createMock(ObligationReadService::class);
		$readService->method('read')->willReturn(['total' => 2, 'late' => 1]);

		$response = $this->controller($readService)->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['total' => 2, 'late' => 1], $response->getData());

	}//end testAnswersTheOverview()

	public function testUnreadableSourcesAnswer503(): void {
		$readService = $this->createMock(ObligationReadService::class);
		$readService->method('read')->willThrowException(new RuntimeException('down'));

		$response = $this->controller($readService)->index();

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame('sources-unreadable', $response->getData()['error']);

	}//end testUnreadableSourcesAnswer503()

	/**
	 * A non-admin is refused by the admin-setting attribute before the method runs.
	 */
	public function testOnlyAdministratorsMayRead(): void {
		$attributes = (new ReflectionMethod(ObligationController::class, 'index'))
			->getAttributes(AuthorizedAdminSetting::class);

		$this->assertCount(1, $attributes);
		$this->assertSame(OpenCatalogiAdmin::class, $attributes[0]->getArguments()['settings']);

	}//end testOnlyAdministratorsMayRead()
}//end class
