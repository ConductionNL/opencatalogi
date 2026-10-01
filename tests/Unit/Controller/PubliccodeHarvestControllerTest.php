<?php

/**
 * Tests for PubliccodeHarvestController: each endpoint answers with what the
 * harvest service returns, switch on and off reach the service with the right
 * flag, and a refusal comes back as an error status carrying its message.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/publiccode-github-harvest/specs/publiccode-github-harvest/spec.md#requirement-req-pgh-006-the-administrator-sets-the-harvest-up-switches-it-on-and-runs-it-from-opencatalogi
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Controller;

use OCA\OpenCatalogi\Controller\PubliccodeHarvestController;
use OCA\OpenCatalogi\Service\PubliccodeHarvestService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenCatalogi\Controller\PubliccodeHarvestController
 */
class PubliccodeHarvestControllerTest extends TestCase {

	/**
	 * The harvest service double.
	 *
	 * @var PubliccodeHarvestService&MockObject
	 */
	private PubliccodeHarvestService&MockObject $harvest;

	/**
	 * The controller under test.
	 *
	 * @var PubliccodeHarvestController
	 */
	private PubliccodeHarvestController $controller;

	/**
	 * Build the controller over a service double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->harvest = $this->getMockBuilder(PubliccodeHarvestService::class)
			->disableOriginalConstructor()
			->onlyMethods(['status', 'setUp', 'setEnabled', 'runNow'])
			->getMock();
		$this->controller = new PubliccodeHarvestController(
			'opencatalogi',
			$this->createMock(IRequest::class),
			$this->harvest
		);
	}//end setUp()

	/**
	 * The status endpoint returns the service's status as it is.
	 *
	 * @return void
	 */
	public function testStatusReturnsTheServiceStatus(): void {
		$this->harvest->method('status')->willReturn(['integriq' => false, 'shards' => ['expected' => 24, 'present' => 0]]);

		$response = $this->controller->status();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['integriq' => false, 'shards' => ['expected' => 24, 'present' => 0]], $response->getData());
	}//end testStatusReturnsTheServiceStatus()

	/**
	 * A status that cannot be read is a server error with its message, not an empty status.
	 *
	 * @return void
	 */
	public function testAnUnreadableStatusIsAServerError(): void {
		$this->harvest->method('status')->willThrowException(new RuntimeException('The shard file is missing.'));

		$response = $this->controller->status();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'The shard file is missing.'], $response->getData());
	}//end testAnUnreadableStatusIsAServerError()

	/**
	 * Set up returns what was written, and a refusal is a conflict.
	 *
	 * @return void
	 */
	public function testSetupReturnsWhatWasWrittenOrTheRefusal(): void {
		$this->harvest->expects($this->exactly(2))->method('setUp')->willReturnOnConsecutiveCalls(
			['created' => 24, 'updated' => 0],
			$this->throwException(new RuntimeException('The GitHub harvest needs integriq.'))
		);

		$this->assertSame(['created' => 24, 'updated' => 0], $this->controller->setup()->getData());

		$refused = $this->controller->setup();
		$this->assertSame(Http::STATUS_CONFLICT, $refused->getStatus());
		$this->assertSame(['error' => 'The GitHub harvest needs integriq.'], $refused->getData());
	}//end testSetupReturnsWhatWasWrittenOrTheRefusal()

	/**
	 * Switch on asks for true, switch off for false.
	 *
	 * @return void
	 */
	public function testSwitchOnAndOffPassTheRightFlag(): void {
		$asked = [];
		$this->harvest->method('setEnabled')->willReturnCallback(
			static function (bool $enabled) use (&$asked): array {
				$asked[] = $enabled;
				return ['enabled' => $enabled];
			}
		);

		$this->assertSame(['enabled' => true], $this->controller->enable()->getData());
		$this->assertSame(['enabled' => false], $this->controller->disable()->getData());
		$this->assertSame([true, false], $asked);
	}//end testSwitchOnAndOffPassTheRightFlag()

	/**
	 * A refused switch is a conflict carrying the reason.
	 *
	 * @return void
	 */
	public function testARefusedSwitchIsAConflict(): void {
		$this->harvest->method('setEnabled')->willThrowException(new RuntimeException('Reimport the OpenCatalogi configuration first.'));

		foreach ([$this->controller->enable(), $this->controller->disable()] as $response) {
			$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
			$this->assertSame(['error' => 'Reimport the OpenCatalogi configuration first.'], $response->getData());
		}
	}//end testARefusedSwitchIsAConflict()

	/**
	 * Run now returns the queued run, and a refusal is a conflict.
	 *
	 * @return void
	 */
	public function testRunReturnsTheQueuedRunOrTheRefusal(): void {
		$this->harvest->expects($this->exactly(2))->method('runNow')->willReturnOnConsecutiveCalls(
			['uuid' => 'run-1', 'status' => 'queued'],
			$this->throwException(new RuntimeException('Switch the harvest on before you run it.'))
		);

		$this->assertSame(['uuid' => 'run-1', 'status' => 'queued'], $this->controller->run()->getData());

		$refused = $this->controller->run();
		$this->assertSame(Http::STATUS_CONFLICT, $refused->getStatus());
		$this->assertSame(['error' => 'Switch the harvest on before you run it.'], $refused->getData());
	}//end testRunReturnsTheQueuedRunOrTheRefusal()
}//end class
