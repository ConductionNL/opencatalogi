<?php

/**
 * Golden test for the refusals of `StatsController`.
 *
 * Pins the status and body every officer-facing stats endpoint answers an
 * anonymous caller with, and the answer to an unknown catalog slug.
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
 *
 * @spec openspec/specs/publication-usage-analytics/spec.md
 *
 * @covers \OCA\OpenCatalogi\Controller\StatsController
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Contract\CatalogiServiceInterface;
use OCA\OpenCatalogi\Contract\UsageCounterServiceInterface;
use OCA\OpenCatalogi\Controller\StatsController;
use OCP\App\IAppManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Golden test for the StatsController refusals.
 */
class StatsRefusalsGoldenTest extends TestCase {

	/**
	 * Anonymous callers are refused, and an unknown catalog is not found.
	 *
	 * @return void
	 */
	public function testRefusals(): void {
		$anonymous = $this->controller(false);
		$officer = $this->controller(true);
		$forbidden = [403, ['error' => 'Authentication required.']];
		$notFound = [404, ['error' => 'Catalog not found']];

		$this->assertSame(
			[
				'anonymous catalog' => $forbidden,
				'anonymous series' => $forbidden,
				'anonymous quality' => $forbidden,
				'anonymous export' => $forbidden,
				'anonymous publication' => [403, ['error' => 'You are not allowed to view statistics for this publication.']],
				'unknown catalog' => $notFound,
				'unknown catalog quality' => $notFound,
				'unknown catalog export' => $notFound,
			],
			[
				'anonymous catalog' => $this->answer($anonymous->catalog('woo')),
				'anonymous series' => $this->answer($anonymous->series()),
				'anonymous quality' => $this->answer($anonymous->quality('woo')),
				'anonymous export' => $this->answer($anonymous->export('woo')),
				'anonymous publication' => $this->answer($anonymous->publication('p-1')),
				'unknown catalog' => $this->answer($officer->catalog('nope')),
				'unknown catalog quality' => $this->answer($officer->quality('nope')),
				'unknown catalog export' => $this->answer($officer->export('nope')),
			]
		);

	}//end testRefusals()

	/**
	 * Status and body of a response.
	 *
	 * @param mixed $response The response.
	 *
	 * @return array{0: int, 1: mixed}
	 */
	private function answer(mixed $response): array {
		return [$response->getStatus(), $response->getData()];
	}//end answer()

	/**
	 * The controller with or without a signed-in user, and no catalogs.
	 *
	 * @param bool $signedIn Whether a user is signed in.
	 *
	 * @return StatsController
	 */
	private function controller(bool $signedIn): StatsController {
		$session = $this->createMock(IUserSession::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('officer');
		$session->method('getUser')->willReturn($signedIn === true ? $user : null);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$catalogi = $this->createMock(CatalogiServiceInterface::class);
		$catalogi->method('getCatalogBySlug')->willReturn(null);
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnArgument(1);

		return new StatsController(
			'opencatalogi',
			$request,
			$this->createMock(UsageCounterServiceInterface::class),
			$catalogi,
			$this->createMock(IAppManager::class),
			$this->createMock(ContainerInterface::class),
			$l10n,
			$session,
			$this->createMock(LoggerInterface::class)
		);

	}//end controller()
}//end class
