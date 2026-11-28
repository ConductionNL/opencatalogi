<?php

/**
 * Tests for the harvest feed endpoints.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Controller
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
 *
 * @spec openspec/changes/harvest-feed-intake/specs/harvest-feed-intake/spec.md#requirement-a-harvest-feed-is-an-openregister-source-owned-by-opencatalogi-req-hfi-002
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Controller;

use OCA\OpenCatalogi\Controller\HarvestFeedController;
use OCA\OpenCatalogi\Service\Harvest\HarvestFeedRefused;
use OCA\OpenCatalogi\Service\Harvest\HarvestFeedService;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

/**
 * Every action is admin only and answers a refusal with its status and field.
 */
class HarvestFeedControllerTest extends TestCase {

	/**
	 * The feeds.
	 *
	 * @var HarvestFeedService&MockObject
	 */
	private HarvestFeedService&MockObject $feeds;

	/**
	 * The request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Build the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->feeds   = $this->createMock(originalClassName: HarvestFeedService::class);
		$this->request = $this->createMock(originalClassName: IRequest::class);

	}//end setUp()

	/**
	 * The controller under test.
	 *
	 * @return HarvestFeedController
	 */
	private function controller(): HarvestFeedController {
		return new HarvestFeedController(appName: 'opencatalogi', request: $this->request, feeds: $this->feeds);

	}//end controller()

	/**
	 * Every action carries the admin-setting attribute, so Nextcloud refuses a non-administrator.
	 *
	 * @return void
	 */
	public function testANonAdminIsRefused(): void {
		$actions = array_filter(
			(new ReflectionClass(objectOrClass: HarvestFeedController::class))->getMethods(filter: ReflectionMethod::IS_PUBLIC),
			static fn (ReflectionMethod $method): bool => $method->class === HarvestFeedController::class && $method->isConstructor() === false
		);

		$this->assertSame(
			expected: ['index', 'create', 'update', 'destroy'],
			actual: array_values(array_map(static fn (ReflectionMethod $method): string => $method->getName(), $actions))
		);
		foreach ($actions as $action) {
			$this->assertCount(expectedCount: 1, haystack: $action->getAttributes(name: AuthorizedAdminSetting::class), message: $action->getName());
			$this->assertSame(expected: [], actual: $action->getAttributes(name: 'OCP\AppFramework\Http\Attribute\NoAdminRequired'));
		}

		$this->feeds->method('list')->willThrowException(new HarvestFeedRefused(status: 403, message: 'Only an administrator can manage harvest feeds.'));
		$response = $this->controller()->index();
		$this->assertSame(expected: 403, actual: $response->getStatus());
		$this->assertSame(expected: ['error' => 'Only an administrator can manage harvest feeds.'], actual: $response->getData());

	}//end testANonAdminIsRefused()

	/**
	 * The list answers the feeds.
	 *
	 * @return void
	 */
	public function testTheListAnswersTheFeeds(): void {
		$this->feeds->method('list')->willReturn([['id' => 4]]);

		$response = $this->controller()->index();

		$this->assertSame(expected: 200, actual: $response->getStatus());
		$this->assertSame(expected: ['results' => [['id' => 4]]], actual: $response->getData());

	}//end testTheListAnswersTheFeeds()

	/**
	 * A new feed answers 201; a refused field answers 422 naming it.
	 *
	 * @return void
	 */
	public function testCreateAnswersTheFeedOrTheRefusedField(): void {
		$this->request->method('getParams')->willReturn(['title' => 'Open data']);
		$this->feeds->expects($this->exactly(count: 2))->method('create')
			->with(['title' => 'Open data'])
			->willReturnOnConsecutiveCalls(
				['id' => 4, 'title' => 'Open data'],
				$this->throwException(exception: new HarvestFeedRefused(status: 422, message: 'Schema 13 is not one of the schemas.', field: 'targetSchema'))
			);

		$created = $this->controller()->create();
		$refused = $this->controller()->create();

		$this->assertSame(expected: 201, actual: $created->getStatus());
		$this->assertSame(expected: 422, actual: $refused->getStatus());
		$this->assertSame(expected: ['error' => 'Schema 13 is not one of the schemas.', 'field' => 'targetSchema'], actual: $refused->getData());

	}//end testCreateAnswersTheFeedOrTheRefusedField()

	/**
	 * Update passes the changed fields without the route's own.
	 *
	 * @return void
	 */
	public function testUpdatePassesTheChangedFields(): void {
		$this->request->method('getParams')->willReturn(['id' => '4', '_route' => 'x', 'syncEnabled' => false]);
		$this->feeds->expects($this->once())->method('update')->with(4, ['syncEnabled' => false])->willReturn(['id' => 4, 'syncEnabled' => false]);

		$response = $this->controller()->update(id: 4);

		$this->assertSame(expected: 200, actual: $response->getStatus());

	}//end testUpdatePassesTheChangedFields()

	/**
	 * Delete answers 204, a missing feed 404 and an absent OpenRegister 503.
	 *
	 * @return void
	 */
	public function testDestroyAnswersNoContentOrTheRefusal(): void {
		$this->feeds->expects($this->exactly(count: 3))->method('delete')
			->willReturnOnConsecutiveCalls(
				null,
				$this->throwException(exception: new HarvestFeedRefused(status: 404, message: 'Harvest feed 9 does not exist.')),
				$this->throwException(exception: new RuntimeException(message: 'OpenRegister is not installed.'))
			);

		$this->assertSame(expected: 204, actual: $this->controller()->destroy(id: 4)->getStatus());
		$this->assertSame(expected: 404, actual: $this->controller()->destroy(id: 9)->getStatus());
		$unavailable = $this->controller()->destroy(id: 4);
		$this->assertSame(expected: 503, actual: $unavailable->getStatus());
		$this->assertSame(expected: ['error' => 'OpenRegister is not installed.'], actual: $unavailable->getData());

	}//end testDestroyAnswersNoContentOrTheRefusal()
}//end class
