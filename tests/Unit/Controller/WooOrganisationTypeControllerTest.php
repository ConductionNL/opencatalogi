<?php

/**
 * Tests for the organisation type routes (REQ-WVL-004).
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

use OCA\OpenCatalogi\Controller\WooOrganisationTypeController;
use OCA\OpenCatalogi\Service\ValueList\CategoryApplicability;
use OCA\OpenCatalogi\Settings\OpenCatalogiAdmin;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Reading and setting the organisation type answers the proposal; nothing is hidden by it.
 *
 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-categories-that-do-not-apply-are-hidden-and-their-sitemap-stays-valid-req-wvl-004
 */
#[CoversClass(WooOrganisationTypeController::class)]
class WooOrganisationTypeControllerTest extends TestCase {

	/**
	 * The stored app config.
	 *
	 * @var array<string, string>
	 */
	private array $stored = [];

	/**
	 * The controller over the request parameters, with the shipped table.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return WooOrganisationTypeController
	 */
	private function controller(array $params): WooOrganisationTypeController {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($params[$key] ?? $default)
		);

		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->stored[$key] ?? $default)
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->stored[$key] = $value;
				return true;
			}
		);

		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new WooOrganisationTypeController(
			appName: 'opencatalogi',
			request: $request,
			applicability: new CategoryApplicability(config: $config),
			l10n: $l10n
		);
	}//end controller()

	/**
	 * Without a type the types are offered and nothing is proposed.
	 *
	 * @return void
	 */
	public function testWithoutATypeNothingIsProposed(): void {
		$data = $this->controller(params: [])->index()->getData();

		$this->assertNull(actual: $data['type']);
		$this->assertContains(needle: 'waterschap', haystack: $data['types']);
		$this->assertSame(expected: [], actual: $data['proposal']);
	}//end testWithoutATypeNothingIsProposed()

	/**
	 * Setting the type stores it and answers its proposal.
	 *
	 * @return void
	 */
	public function testSettingTheTypeAnswersItsProposal(): void {
		$response = $this->controller(params: ['type' => 'waterschap'])->update();

		$this->assertSame(expected: 200, actual: $response->getStatus());
		$this->assertSame(expected: 'waterschap', actual: $response->getData()['type']);
		$this->assertSame(expected: ['infocat007'], actual: array_column($response->getData()['proposal'], 'category'));
		$this->assertSame(expected: 'waterschap', actual: $this->stored[CategoryApplicability::TYPE_KEY]);
		$this->assertSame(expected: 'waterschap', actual: $this->controller(params: [])->index()->getData()['type']);
	}//end testSettingTheTypeAnswersItsProposal()

	/**
	 * An unknown type is refused and nothing is stored.
	 *
	 * @return void
	 */
	public function testAnUnknownTypeIsRefused(): void {
		$response = $this->controller(params: ['type' => 'kerkgenootschap'])->update();

		$this->assertSame(expected: 400, actual: $response->getStatus());
		$this->assertSame(expected: [], actual: $this->stored);
	}//end testAnUnknownTypeIsRefused()

	/**
	 * Both routes are for the OpenCatalogi administrators only.
	 *
	 * @return void
	 */
	public function testBothRoutesAreAdminOnly(): void {
		foreach (['index', 'update'] as $method) {
			$attributes = (new ReflectionMethod(WooOrganisationTypeController::class, $method))->getAttributes(AuthorizedAdminSetting::class);
			$this->assertCount(expectedCount: 1, haystack: $attributes, message: $method);
			$this->assertSame(expected: OpenCatalogiAdmin::class, actual: $attributes[0]->getArguments()['settings']);
		}
	}//end testBothRoutesAreAdminOnly()
}//end class
