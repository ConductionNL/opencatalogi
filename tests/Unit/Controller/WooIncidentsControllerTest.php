<?php

/**
 * Tests for the Woo pipeline incident endpoints.
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

namespace Unit\Controller;

use OCA\OpenCatalogi\Controller\WooIncidentsController;
use OCA\OpenCatalogi\Service\Woo\PipelineIncidentSettings;
use OCA\OpenCatalogi\Settings\OpenCatalogiAdmin;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/changes/woo-national-output-assurance/specs/woo-compliance/spec.md#requirement-a-pipeline-failure-reaches-a-named-group-req-wno-004
 */
#[CoversClass(WooIncidentsController::class)]
class WooIncidentsControllerTest extends TestCase {

	/**
	 * @param PipelineIncidentSettings $settings The settings double.
	 *
	 * @return WooIncidentsController
	 */
	private function controller(PipelineIncidentSettings $settings): WooIncidentsController {
		return new WooIncidentsController('opencatalogi', $this->createMock(IRequest::class), $settings);
	}

	public function testTheGroupAndTheOpenIncidentsAreAnswered(): void {
		$settings = $this->createMock(PipelineIncidentSettings::class);
		$settings->method('group')->willReturn('woo-team');
		$settings->method('openIncidents')->willReturn([['id' => 'i-1', 'kind' => 'plooi-delivery', 'subject' => 'pub-1', 'message' => 'm', 'at' => 'a']]);

		$response = $this->controller($settings)->index();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('woo-team', $response->getData()['group']);
		$this->assertSame('i-1', $response->getData()['incidents'][0]['id']);
	}

	public function testUnreadableIncidentsStillAnswerTheGroup(): void {
		$settings = $this->createMock(PipelineIncidentSettings::class);
		$settings->method('group')->willReturn('');
		$settings->method('openIncidents')->willThrowException(new \RuntimeException('OpenRegister is not available'));

		$data = $this->controller($settings)->index()->getData();

		$this->assertSame(['group' => '', 'incidents' => [], 'error' => 'OpenRegister is not available'], $data);
	}

	public function testSettingTheGroupAnswersTheSavedGroup(): void {
		$settings = $this->createMock(PipelineIncidentSettings::class);
		$settings->expects($this->once())->method('setGroup')->with('woo-team')->willReturn('woo-team');

		$response = $this->controller($settings)->setGroup('woo-team');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['group' => 'woo-team'], $response->getData());
	}

	public function testAnUnknownGroupIsABadRequestAndAnUnassignableRoleAConflict(): void {
		$unknown = $this->createMock(PipelineIncidentSettings::class);
		$unknown->method('setGroup')->willThrowException(new \InvalidArgumentException('The group "x" does not exist.'));
		$this->assertSame(400, $this->controller($unknown)->setGroup('x')->getStatus());

		$stuck = $this->createMock(PipelineIncidentSettings::class);
		$stuck->method('setGroup')->willThrowException(new \RuntimeException('read-only'));
		$this->assertSame(409, $this->controller($stuck)->setGroup('woo-team')->getStatus());
	}

	public function testBothEndpointsAreAdminSettings(): void {
		foreach (['index', 'setGroup'] as $method) {
			$attributes = (new \ReflectionMethod(WooIncidentsController::class, $method))->getAttributes(AuthorizedAdminSetting::class);
			$this->assertCount(1, $attributes, $method);
			$this->assertSame(['settings' => OpenCatalogiAdmin::class], $attributes[0]->getArguments(), $method);
		}
	}

	public function testTheRoutesAreDeclared(): void {
		$routes = require __DIR__ . '/../../../appinfo/routes.php';
		$declared = array_column($routes['routes'], null, 'name');

		$this->assertSame(['name' => 'wooIncidents#index', 'url' => '/api/woo/incidents', 'verb' => 'GET'], $declared['wooIncidents#index']);
		$this->assertSame(['name' => 'wooIncidents#setGroup', 'url' => '/api/woo/incidents/group', 'verb' => 'PUT'], $declared['wooIncidents#setGroup']);
	}
}
