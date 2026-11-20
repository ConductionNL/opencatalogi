<?php

/**
 * PublicationLifecycleControllerTest
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/changes/publication-lifecycle-on-or/tasks.md#task-1.3
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Controller;

use OCA\OpenCatalogi\Controller\PublicationLifecycleController;
use OCA\OpenCatalogi\Service\Publication\PublicationLifecycleTable;
use OCA\OpenCatalogi\Service\SettingsService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * `GET /api/publications/lifecycle` answers the shipped transition table.
 *
 * @covers \OCA\OpenCatalogi\Controller\PublicationLifecycleController
 * @covers \OCA\OpenCatalogi\Service\Publication\PublicationLifecycleTable
 * @covers \OCA\OpenCatalogi\Service\SettingsService::shippedSchema
 */
final class PublicationLifecycleControllerTest extends TestCase {

	/**
	 * All seven transitions with their from and to states, read from the shipped schema.
	 *
	 * @return void
	 */
	public function testTheTransitionTableIsPublished(): void {
		$controller = new PublicationLifecycleController('opencatalogi', $this->createMock(IRequest::class), new PublicationLifecycleTable());

		$response = $controller->index();
		$body = $response->getData();

		self::assertSame(200, $response->getStatus());
		self::assertSame('status', $body['field']);
		self::assertSame(['draft', 'in_review', 'approved', 'published', 'archived'], $body['states']);
		self::assertSame('draft', $body['initial']);
		self::assertSame(['archived'], $body['final']);

		$moves = [];
		foreach ($body['transitions'] as $row) {
			$moves[$row['action']] = $row['from'][0] . '>' . $row['to'];
		}

		self::assertSame(
			[
				'submit' => 'draft>in_review',
				'approve' => 'in_review>approved',
				'reject' => 'in_review>draft',
				'retract' => 'approved>draft',
				'publish' => 'approved>published',
				'publishWithoutReview' => 'draft>published',
				'archive' => 'published>archived',
			],
			$moves
		);
	}//end testTheTransitionTableIsPublished()

	/**
	 * The table names a move's inputs, and never the guard class or the actions behind it.
	 *
	 * @return void
	 */
	public function testTheTableCarriesInputsButNoInternals(): void {
		$rows = [];
		foreach ((new PublicationLifecycleTable())->table()['transitions'] as $row) {
			$rows[$row['action']] = $row;
		}

		self::assertSame([['field' => 'reason', 'required' => true]], $rows['reject']['inputs']);
		self::assertSame(['action', 'from', 'to', 'label', 'description', 'inputs'], array_keys($rows['publishWithoutReview']));
		self::assertSame('Publish without review', $rows['publishWithoutReview']['label']);
	}//end testTheTableCarriesInputsButNoInternals()

	/**
	 * A directory without a register answers an empty table, not an error.
	 *
	 * @return void
	 */
	public function testAMissingRegisterAnswersAnEmptyTable(): void {
		$table = (new PublicationLifecycleTable(sys_get_temp_dir() . '/no-such-settings-' . uniqid()))->table();

		self::assertSame([], $table['transitions']);
		self::assertSame([], $table['states']);
		self::assertNull($table['initial']);
		self::assertSame([], SettingsService::shippedSchema(sys_get_temp_dir(), 'publication'));
	}//end testAMissingRegisterAnswersAnEmptyTable()

	/**
	 * The literal route exists and comes before the catalogue wildcard `/api/{catalogSlug}/{id}`.
	 *
	 * @return void
	 */
	public function testTheRouteIsDeclaredBeforeTheCatalogueWildcard(): void {
		$routes = array_values((include dirname(__DIR__, 3) . '/appinfo/routes.php')['routes']);
		$position = null;
		$wildcard = null;
		foreach ($routes as $index => $route) {
			if ($route['url'] === '/api/publications/lifecycle' && $route['verb'] === 'GET') {
				$position = $index;
				self::assertSame('publicationLifecycle#index', $route['name']);
			}

			if ($wildcard === null && $route['url'] === '/api/{catalogSlug}/{id}' && $route['verb'] === 'GET') {
				$wildcard = $index;
			}
		}

		self::assertNotNull($position, 'the lifecycle route is registered');
		self::assertNotNull($wildcard);
		self::assertLessThan($wildcard, $position);
	}//end testTheRouteIsDeclaredBeforeTheCatalogueWildcard()
}//end class
