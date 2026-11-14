<?php

/**
 * Tests for UnpublishedOverviewController.
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
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Controller\UnpublishedOverviewController;
use OCA\OpenCatalogi\Service\UnpublishedOverviewService;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @spec openspec/changes/publications-unpublished-overview/specs/dashboard/spec.md#requirement-unpublished-content-dashboard-widgets-dsh-011
 */
class UnpublishedOverviewControllerTest extends TestCase {

	/**
	 * The overview double.
	 *
	 * @var UnpublishedOverviewService&MockObject
	 */
	private UnpublishedOverviewService&MockObject $overview;

	/**
	 * Build the controller.
	 *
	 * @param bool $signedIn Whether a user is signed in.
	 *
	 * @return UnpublishedOverviewController The controller.
	 */
	private function controller(bool $signedIn = true): UnpublishedOverviewController {
		$this->overview = $this->createMock(UnpublishedOverviewService::class);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(($signedIn === true) ? $this->createMock(IUser::class) : null);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new UnpublishedOverviewController(
			'opencatalogi',
			$this->createMock(IRequest::class),
			$this->overview,
			$session,
			$l10n,
			$this->createMock(LoggerInterface::class)
		);
	}//end controller()

	/**
	 * A caller without a session is refused.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerIsRefused(): void {
		$controller = $this->controller(signedIn: false);
		$this->overview->expects($this->never())->method('publications');

		$this->assertSame(401, $controller->unpublished()->getStatus());
	}//end testAnAnonymousCallerIsRefused()

	/**
	 * An unknown list is refused.
	 *
	 * @return void
	 */
	public function testAnUnknownKindIsRefused(): void {
		$response = $this->controller()->unpublished(kind: 'attachments');

		$this->assertSame(400, $response->getStatus());
	}//end testAnUnknownKindIsRefused()

	/**
	 * The default list is publications.
	 *
	 * @return void
	 */
	public function testPublicationsIsTheDefaultList(): void {
		$controller = $this->controller();
		$answer = ['items' => [['title' => 'Draft']], 'total' => 1, 'scope' => null];
		$this->overview->expects($this->once())->method('publications')->with(null)->willReturn($answer);

		$response = $controller->unpublished();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame($answer, $response->getData());
	}//end testPublicationsIsTheDefaultList()

	/**
	 * The documents list is asked for with the requested length; the service clamps it.
	 *
	 * @return void
	 */
	public function testDocumentsAreAskedWithTheRequestedLength(): void {
		$controller = $this->controller();
		$answer = ['items' => [], 'total' => 0, 'scope' => 'recent-200'];
		$this->overview->expects($this->once())->method('documents')->with(999)->willReturn($answer);

		$response = $controller->unpublished(kind: 'documents', limit: 999);

		$this->assertSame($answer, $response->getData());
	}//end testDocumentsAreAskedWithTheRequestedLength()

	/**
	 * A failed read answers 503, not an empty list.
	 *
	 * @return void
	 */
	public function testAFailedReadIsNotAnEmptyList(): void {
		$controller = $this->controller();
		$this->overview->method('publications')->willThrowException(new \RuntimeException('down'));

		$response = $controller->unpublished();

		$this->assertSame(503, $response->getStatus());
		$this->assertArrayNotHasKey('items', $response->getData());
	}//end testAFailedReadIsNotAnEmptyList()
}//end class
