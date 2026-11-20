<?php

/**
 * Tests for CouncilHarvestController: every route, its answers and the route table.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/council-documents-from-notubiz-and-ibabs/specs/council-documents-harvest/spec.md#requirement-an-administrator-sets-up-the-council-documents-harvest-req-cdh-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Controller;

use LogicException;
use OCA\OpenCatalogi\Contract\CouncilHarvestServiceInterface;
use OCA\OpenCatalogi\Controller\CouncilHarvestController;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * @covers \OCA\OpenCatalogi\Controller\CouncilHarvestController
 */
class CouncilHarvestControllerTest extends TestCase {

	/**
	 * The harvest service.
	 *
	 * @var CouncilHarvestServiceInterface&MockObject
	 */
	private CouncilHarvestServiceInterface $harvest;

	/**
	 * The request parameters.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	/**
	 * The controller under test.
	 *
	 * @var CouncilHarvestController
	 */
	private CouncilHarvestController $controller;

	/**
	 * Build the controller over a mocked service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $key, mixed $default = null): mixed => $this->params[$key] ?? $default);
		$this->harvest = $this->createMock(CouncilHarvestServiceInterface::class);
		$this->controller = new CouncilHarvestController(
			appName: 'opencatalogi',
			request: $request,
			harvest: $this->harvest,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end setUp()

	/**
	 * The status route answers the service's status.
	 *
	 * @return void
	 */
	public function testStatusReturnsTheServiceStatus(): void {
		$this->harvest->method('status')->willReturn(['integriq' => true, 'source' => 'notubiz-ris-v1']);

		$response = $this->controller->status();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['integriq' => true, 'source' => 'notubiz-ris-v1'], $response->getData());
	}//end testStatusReturnsTheServiceStatus()

	/**
	 * Set-up passes the chosen source and answers a refusal as 409 with its message.
	 *
	 * @return void
	 */
	public function testSetupPassesTheSourceAndAnswersTheRefusal(): void {
		$this->params = ['source' => 'ibabs-ris-v1'];
		$this->harvest->expects($this->exactly(2))->method('setUp')->with('ibabs-ris-v1')->willReturnOnConsecutiveCalls(
			['synchronization' => 'created', 'rule' => 'created'],
			$this->throwException(new RuntimeException('Fill in the iBabs key in integriq first.'))
		);

		$this->assertSame(['synchronization' => 'created', 'rule' => 'created'], $this->controller->setup()->getData());

		$refused = $this->controller->setup();
		$this->assertSame(409, $refused->getStatus());
		$this->assertSame(['error' => 'Fill in the iBabs key in integriq first.'], $refused->getData());
	}//end testSetupPassesTheSourceAndAnswersTheRefusal()

	/**
	 * Enable and disable pass the right flag.
	 *
	 * @return void
	 */
	public function testSwitchOnAndOffPassTheRightFlag(): void {
		$this->harvest->expects($this->exactly(2))->method('setEnabled')
			->willReturnCallback(static fn (bool $enabled): array => ['flow' => ['enabled' => $enabled]]);

		$this->assertSame(['flow' => ['enabled' => true]], $this->controller->enable()->getData());
		$this->assertSame(['flow' => ['enabled' => false]], $this->controller->disable()->getData());
	}//end testSwitchOnAndOffPassTheRightFlag()

	/**
	 * Publish on arrival reads the body flag as a boolean.
	 *
	 * @return void
	 */
	public function testPublishOnArrivalReadsTheFlag(): void {
		$seen = [];
		$this->harvest->method('setPublishOnArrival')->willReturnCallback(
			static function (bool $publish) use (&$seen): array {
				$seen[] = $publish;
				return ['publishOnArrival' => $publish];
			}
		);

		foreach (['true', '0', true] as $value) {
			$this->params = ['publish' => $value];
			$this->controller->publishOnArrival();
		}

		$this->assertSame([true, false, true], $seen);
	}//end testPublishOnArrivalReadsTheFlag()

	/**
	 * Run answers the queued run, and an unexpected failure as 500 without its details.
	 *
	 * @return void
	 */
	public function testRunAnswersTheRunOrAServerErrorWithoutDetails(): void {
		$this->harvest->expects($this->exactly(2))->method('runNow')->willReturnOnConsecutiveCalls(
			['uuid' => 'run-1', 'status' => 'queued'],
			$this->throwException(new LogicException('database password rejected'))
		);

		$this->assertSame(['uuid' => 'run-1', 'status' => 'queued'], $this->controller->run()->getData());

		$failed = $this->controller->run();
		$this->assertSame(500, $failed->getStatus());
		$this->assertStringNotContainsString('password', json_encode($failed->getData()));
	}//end testRunAnswersTheRunOrAServerErrorWithoutDetails()

	/**
	 * Every action is for OpenCatalogi settings administrators only.
	 *
	 * @return void
	 */
	public function testEveryActionIsAdminOnly(): void {
		foreach (['status', 'setup', 'enable', 'disable', 'publishOnArrival', 'run'] as $method) {
			$attributes = (new ReflectionMethod(CouncilHarvestController::class, $method))->getAttributes(AuthorizedAdminSetting::class);
			$this->assertCount(1, $attributes, $method . ' must be admin only');
		}
	}//end testEveryActionIsAdminOnly()

	/**
	 * The route table names each action once, next to the publiccode harvest routes.
	 *
	 * @return void
	 */
	public function testTheRouteTableNamesEveryAction(): void {
		$routes = [];
		foreach (file(__DIR__ . '/../../../appinfo/routes.php') as $line) {
			if (preg_match("/'name' => 'councilHarvest#(\\w+)', 'url' => '([^']+)', 'verb' => '(\\w+)'/", $line, $match) === 1) {
				$routes[$match[1]] = $match[3] . ' ' . $match[2];
			}
		}

		$this->assertSame(
			[
				'status' => 'GET /api/settings/council-harvest',
				'setup' => 'POST /api/settings/council-harvest/setup',
				'enable' => 'POST /api/settings/council-harvest/enable',
				'disable' => 'POST /api/settings/council-harvest/disable',
				'publishOnArrival' => 'POST /api/settings/council-harvest/publish-on-arrival',
				'run' => 'POST /api/settings/council-harvest/run',
			],
			$routes
		);
	}//end testTheRouteTableNamesEveryAction()
}//end class
