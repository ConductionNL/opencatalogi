<?php

/**
 * Unit tests for CapabilityRightsController.
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
 *
 * @spec openspec/changes/woo-access-boundary-hardening/specs/woo-compliance/spec.md#requirement-capabilities-are-rights-granted-per-group-req-wab-004
 */

declare(strict_types=1);

namespace Unit\Controller;

use InvalidArgumentException;
use OCA\OpenCatalogi\Controller\CapabilityRightsController;
use OCA\OpenCatalogi\Service\CapabilityRightsTable;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * The rights table's endpoints: administrators only, a 400 for a bad grant, a 500 that says nothing more.
 */
#[CoversClass(CapabilityRightsController::class)]
class CapabilityRightsControllerTest extends TestCase {

	/**
	 * The table double.
	 *
	 * @var CapabilityRightsTable&MockObject
	 */
	private CapabilityRightsTable&MockObject $table;

	/**
	 * A controller whose request posts these parameters.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return CapabilityRightsController
	 */
	private function controller(array $params = []): CapabilityRightsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default));
		$this->table = $this->createMock(CapabilityRightsTable::class);

		return new CapabilityRightsController('opencatalogi', $request, $this->table, $this->createMock(LoggerInterface::class));
	}//end controller()

	/**
	 * Neither endpoint is open to a non-administrator or a delegated group.
	 *
	 * @return void
	 */
	public function testBothEndpointsAreForAdministratorsOnly(): void {
		foreach (['index', 'update'] as $method) {
			$reflection = new ReflectionMethod(CapabilityRightsController::class, $method);
			$names = array_map(static fn ($attribute): string => $attribute->getName(), $reflection->getAttributes());

			$this->assertNotContains('OCP\AppFramework\Http\Attribute\NoAdminRequired', $names, $method);
			$this->assertNotContains('OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting', $names, $method);
			$this->assertNotContains('OCP\AppFramework\Http\Attribute\PublicPage', $names, $method);
			$this->assertStringNotContainsString('@NoAdminRequired', (string) $reflection->getDocComment(), $method);
		}
	}//end testBothEndpointsAreForAdministratorsOnly()

	/**
	 * The index answers the rows under `rights`.
	 *
	 * @return void
	 */
	public function testTheIndexAnswersTheRows(): void {
		$controller = $this->controller();
		$rows = [['verb' => 'openInspection', 'description' => 'Open an inspection period on a publication', 'groups' => ['ter-inzage']]];
		$this->table->method('rows')->willReturn($rows);

		$response = $controller->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['rights' => $rows], $response->getData());
	}//end testTheIndexAnswersTheRows()

	/**
	 * A failing read answers 500 without the exception's text.
	 *
	 * @return void
	 */
	public function testAnUnreadableTableAnswers500(): void {
		$controller = $this->controller();
		$this->table->method('rows')->willThrowException(new RuntimeException('schema mapper: table oc_openregister_schemas missing'));

		$response = $controller->index();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'The rights could not be read.'], $response->getData());
	}//end testAnUnreadableTableAnswers500()

	/**
	 * An update grants the posted groups to the named right.
	 *
	 * @return void
	 */
	public function testAnUpdateGrantsThePostedGroups(): void {
		$controller = $this->controller(['groups' => ['b' => 'ter-inzage']]);
		$this->table->expects($this->once())->method('grant')->with('openInspection', ['ter-inzage'])->willReturn([]);

		$response = $controller->update('openInspection');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['rights' => []], $response->getData());
	}//end testAnUpdateGrantsThePostedGroups()

	/**
	 * Groups that are not a list are refused before the table is asked.
	 *
	 * @return void
	 */
	public function testGroupsThatAreNotAListAnswer400(): void {
		$controller = $this->controller(['groups' => 'ter-inzage']);
		$this->table->expects($this->never())->method('grant');

		$response = $controller->update('openInspection');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'groups must be a list of group ids.'], $response->getData());
	}//end testGroupsThatAreNotAListAnswer400()

	/**
	 * The table's refusal (an unknown right or group) answers 400 with its reason.
	 *
	 * @return void
	 */
	public function testARefusedGrantAnswers400WithTheReason(): void {
		$controller = $this->controller(['groups' => ['nobody']]);
		$this->table->method('grant')->willThrowException(new InvalidArgumentException('No such group: nobody'));

		$response = $controller->update('openInspection');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'No such group: nobody'], $response->getData());
	}//end testARefusedGrantAnswers400WithTheReason()

	/**
	 * A failing save answers 500 without the exception's text.
	 *
	 * @return void
	 */
	public function testAFailingSaveAnswers500(): void {
		$controller = $this->controller(['groups' => []]);
		$this->table->method('grant')->willThrowException(new RuntimeException('database is locked'));

		$response = $controller->update('depublish');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'The right could not be saved.'], $response->getData());
	}//end testAFailingSaveAnswers500()
}//end class
