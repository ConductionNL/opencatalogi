<?php

/**
 * Unit tests for WooRegistrationController.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenCatalogi\Controller\WooRegistrationController;
use OCA\OpenCatalogi\Service\WooRegistrationService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Both paths of the registration request.
 */
class WooRegistrationControllerTest extends TestCase {

	/**
	 * The controller over a service answering the given request result.
	 *
	 * @param array<string, mixed> $result What request() answers.
	 */
	private function controller(array $result): WooRegistrationController {
		$service = $this->getMockBuilder(WooRegistrationService::class)
			->disableOriginalConstructor()
			->onlyMethods(['request'])
			->getMock();
		$service->method('request')->willReturn($result);

		return new WooRegistrationController('opencatalogi', $this->createMock(IRequest::class), $service);
	}

	/** REQ-WIH-003: the gateway takes the request. */
	public function testTheGatewayTakesTheRequest(): void {
		$response = $this->controller(['sent' => true, 'request' => [], 'registration' => ['status' => 'requested']])->request();
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('requested', $response->getData()['registration']['status']);
	}

	/** REQ-WIH-003: no gateway answers 502 with the composed request. */
	public function testNoGatewayAnswers502WithTheRequest(): void {
		$result = ['sent' => false, 'reason' => 'No gateway app is installed.', 'request' => ['robotsTxt' => 'https://woo.example.nl/robots.txt'], 'registration' => ['status' => 'not_registered']];
		$response = $this->controller($result)->request();
		$this->assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());
		$this->assertSame('https://woo.example.nl/robots.txt', $response->getData()['request']['robotsTxt']);
	}
}
