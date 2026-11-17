<?php

/**
 * Unit tests for PublishRightsController.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Controller\PublishRightsController;
use OCA\OpenCatalogi\Service\Publication\PublishRight;
use OCA\OpenCatalogi\Settings\OpenCatalogiAdmin;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * @covers \OCA\OpenCatalogi\Controller\PublishRightsController
 * @uses \OCA\OpenCatalogi\Service\Publication\PublishRight
 *
 * @spec openspec/changes/operations-publish-rights/specs/publications/spec.md#requirement-the-group-exists-and-the-administrator-can-see-it-req-opr-004
 */
class PublishRightsControllerTest extends TestCase {

	/**
	 * REQ-OPR-004: a fresh group shows with 0 members.
	 *
	 * @return void
	 */
	public function testShowAnswersTheGroupAndItsMemberCount(): void {
		$group = $this->createMock(IGroup::class);
		$group->method('count')->willReturn(0);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->with('opencatalogi-publishers')->willReturn($group);

		$controller = new PublishRightsController(
			'opencatalogi',
			$this->createMock(IRequest::class),
			new PublishRight($this->createMock(IUserSession::class), $groups)
		);
		$response = $controller->show();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['group' => 'opencatalogi-publishers', 'exists' => true, 'members' => 0], $response->getData());
	}

	/**
	 * The group and its members are admin information.
	 *
	 * @return void
	 */
	public function testShowIsAnAdminSetting(): void {
		$attributes = (new ReflectionMethod(PublishRightsController::class, 'show'))->getAttributes(AuthorizedAdminSetting::class);
		$this->assertCount(1, $attributes);
		$this->assertSame(['settings' => OpenCatalogiAdmin::class], $attributes[0]->getArguments());
	}
}
