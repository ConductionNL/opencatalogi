<?php

/**
 * Wiring test for the comment period: the job's registration and the API's routes.
 *
 * This is the call-site assertion, and it is the one this project most often
 * skips. A `TimedJob` class that is not listed in `appinfo/info.xml` never runs,
 * and it is indistinguishable from one that runs and finds nothing to do. A
 * controller method with no route entry is a 404 that no unit test of the method
 * can see.
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

namespace Unit\BackgroundJob;

use OCA\OpenCatalogi\BackgroundJob\CommentPeriodWithdrawal;
use OCA\OpenCatalogi\Controller\CommentPeriodController;
use OCA\OpenCatalogi\Controller\WooRequestController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Asserts the wiring from the caller.
 */
class CommentPeriodWithdrawalWiringTest extends TestCase {

	/**
	 * The app root.
	 *
	 * @return string The path.
	 */
	private function root(): string {
		return dirname(__DIR__, 3);

	}//end root()

	/**
	 * 🔴 The withdrawal job is REGISTERED, not merely written.
	 *
	 * This app already shipped a background job nobody registered, so the
	 * registration is asserted rather than assumed.
	 *
	 * @return void
	 */
	public function testTheWithdrawalJobIsRegisteredInInfoXml(): void {
		$info = (string)file_get_contents($this->root() . '/appinfo/info.xml');

		$this->assertStringContainsString(
			'<job>OCA\OpenCatalogi\BackgroundJob\CommentPeriodWithdrawal</job>',
			$info,
			'A TimedJob that is not listed in <background-jobs> never runs, and looks exactly like one that ran and '
			. 'found nothing to withdraw.'
		);

		$this->assertTrue(
			class_exists(CommentPeriodWithdrawal::class),
			'The registered job class must exist, or every cron pass logs a class-not-found and the job is skipped.'
		);

	}//end testTheWithdrawalJobIsRegisteredInInfoXml()

	/**
	 * The job's run() actually calls the withdrawal pass.
	 *
	 * A registered job with an empty body is the same no-op as an unregistered one.
	 *
	 * @return void
	 */
	public function testTheJobCallsTheWithdrawalPass(): void {
		$source = (string)file_get_contents($this->root() . '/lib/BackgroundJob/CommentPeriodWithdrawal.php');

		$this->assertStringContainsString(
			'->withdrawClosedPeriods(',
			$source,
			'The job must call the withdrawal pass; a job whose run() does nothing is a registered no-op.'
		);

	}//end testTheJobCallsTheWithdrawalPass()

	/**
	 * Every public endpoint on the two new controllers has a route entry.
	 *
	 * An unrouted controller method is a 404 at runtime, and no test of the
	 * method itself can see that.
	 *
	 * @return void
	 */
	public function testEveryNewControllerEndpointIsRouted(): void {
		$routes = (string)file_get_contents($this->root() . '/appinfo/routes.php');

		$expected = [
			'wooRequest#receive',
			'wooRequest#termsReport',
			'wooRequest#show',
			'wooRequest#extend',
			'wooRequest#pause',
			'wooRequest#resume',
			'wooRequest#attachBatch',
			'commentPeriod#open',
			'commentPeriod#show',
		];

		foreach ($expected as $route) {
			$this->assertStringContainsString(
				"'" . $route . "'",
				$routes,
				'The route "' . $route . '" is missing, so that endpoint 404s.'
			);
		}

		// And the other direction: no public endpoint exists that nothing routes.
		foreach ([WooRequestController::class => 'wooRequest', CommentPeriodController::class => 'commentPeriod'] as $class => $prefix) {
			foreach ((new ReflectionClass($class))->getMethods() as $method) {
				if ($method->isPublic() === false
					|| $method->isConstructor() === true
					|| $method->getDeclaringClass()->getName() !== $class
				) {
					continue;
				}

				$this->assertStringContainsString(
					"'" . $prefix . '#' . $method->getName() . "'",
					$routes,
					$class . '::' . $method->getName() . '() returns a response and nothing routes it.'
				);
			}
		}

	}//end testEveryNewControllerEndpointIsRouted()

	/**
	 * 🔴 Neither controller extends OCSController.
	 *
	 * Nextcloud's OCSMiddleware turns a 403 from an OCSController into HTTP 200. A
	 * refused second extension that reads as success in a browser is the exact
	 * failure this change must not ship, and the base class is what decides it.
	 *
	 * @return void
	 */
	public function testRefusalsSurviveBecauseNeitherControllerIsAnOcsController(): void {
		foreach ([WooRequestController::class, CommentPeriodController::class] as $class) {
			$parent = (new ReflectionClass($class))->getParentClass();
			$this->assertNotFalse($parent);
			$this->assertSame(
				'OCP\AppFramework\Controller',
				$parent->getName(),
				$class . ' must extend Controller. OCSMiddleware turns a 403 from an OCSController into HTTP 200, so a '
				. 'refusal would read as success in a browser while every test passed.'
			);
		}

	}//end testRefusalsSurviveBecauseNeitherControllerIsAnOcsController()
}//end class
