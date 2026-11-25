<?php

/**
 * OrganisationActivationController test: the administrator's search and activation, and the officers' read.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenCatalogi\Tests\Unit\Controller
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link https://www.OpenCatalogi.nl
 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-the-administrator-activates-the-organisations-the-installation-publishes-for-req-wvl-005
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\OpenCatalogi\Controller\OrganisationActivationController;
use OCA\OpenCatalogi\Contract\OrganisationActivationInterface;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

/**
 * The activation is a double; the request checks and the answers are real.
 *
 * @spec openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#requirement-the-administrator-activates-the-organisations-the-installation-publishes-for-req-wvl-005
 */
#[CoversClass(OrganisationActivationController::class)]
class OrganisationActivationControllerTest extends TestCase {

	private const DORDRECHT = 'https://identifier.overheid.nl/tooi/id/gemeente/gm0505';

	/**
	 * What the activation was asked, in order.
	 *
	 * @var list<string>
	 */
	private array $calls = [];

	/**
	 * The controller over request parameters and an activation that may fail.
	 *
	 * @param array<string, mixed>  $params  The request parameters.
	 * @param \Throwable|null       $failure What the activation throws.
	 *
	 * @return OrganisationActivationController
	 */
	private function controller(array $params, ?\Throwable $failure = null): OrganisationActivationController {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($params[$key] ?? $default)
		);

		$activation = $this->createMock(originalClassName: OrganisationActivationInterface::class);
		$activation->method('search')->willReturnCallback(
			function (string $query) use ($failure): array {
				$this->calls[] = 'search:' . $query;
				if ($failure !== null) {
					throw $failure;
				}

				$row = ['uri' => self::DORDRECHT, 'label' => 'gemeente Dordrecht', 'notation' => 'gm0505', 'active' => true, 'deprecated' => false];
				return ['results' => [$row], 'total' => 1];
			}
		);
		$activation->method('activate')->willReturnCallback(
			function (string $uri) use ($failure): array {
				$this->calls[] = 'activate:' . $uri;
				if ($failure !== null) {
					throw $failure;
				}

				return ['uri' => $uri, 'label' => 'gemeente Dordrecht', 'organisation' => 'org-uuid', 'created' => true];
			}
		);
		$activation->method('deactivate')->willReturnCallback(
			function (string $uri): void {
				$this->calls[] = 'deactivate:' . $uri;
			}
		);

		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));

		return new OrganisationActivationController(appName: 'opencatalogi', request: $request, activation: $activation, l10n: $l10n);
	}//end controller()

	/**
	 * Searching and activating are for administrators; reading the activated set is for any signed-in user.
	 *
	 * @return void
	 */
	public function testOnlyAdministratorsSearchAndActivate(): void {
		$attributes = static fn (string $method, string $attribute): array => (
			new ReflectionMethod(OrganisationActivationController::class, $method)
		)->getAttributes($attribute);
		foreach (['index', 'update'] as $method) {
			$this->assertCount(expectedCount: 1, haystack: $attributes($method, AuthorizedAdminSetting::class), message: $method);
			$this->assertCount(expectedCount: 0, haystack: $attributes($method, NoAdminRequired::class), message: $method);
		}

		$this->assertCount(expectedCount: 1, haystack: $attributes('active', NoAdminRequired::class));
	}//end testOnlyAdministratorsSearchAndActivate()

	/**
	 * The search passes the query on; the activated set is the empty search.
	 *
	 * @return void
	 */
	public function testTheSearchAndTheActivatedSetAreAnswered(): void {
		$found = $this->controller(params: ['search' => 'drecht'])->index();
		$this->assertSame(expected: 200, actual: $found->getStatus());
		$this->assertSame(expected: 1, actual: $found->getData()['total']);

		$active = $this->controller(params: [])->active();
		$this->assertSame(expected: self::DORDRECHT, actual: $active->getData()['results'][0]['uri']);
		$this->assertSame(expected: ['search:drecht', 'search:'], actual: $this->calls);
	}//end testTheSearchAndTheActivatedSetAreAnswered()

	/**
	 * Activating answers the organisation; deactivating writes the choice only.
	 *
	 * @return void
	 */
	public function testActivatingAndDeactivating(): void {
		$on = $this->controller(params: ['entry' => self::DORDRECHT, 'active' => true])->update();
		$this->assertSame(expected: 200, actual: $on->getStatus());
		$expected = ['entry' => self::DORDRECHT, 'active' => true, 'organisation' => 'org-uuid', 'created' => true];
		$this->assertSame(expected: $expected, actual: $on->getData());

		$off = $this->controller(params: ['entry' => self::DORDRECHT, 'active' => false])->update();
		$this->assertSame(expected: ['entry' => self::DORDRECHT, 'active' => false, 'organisation' => null, 'created' => false], actual: $off->getData());
		$this->assertSame(expected: ['activate:' . self::DORDRECHT, 'deactivate:' . self::DORDRECHT], actual: $this->calls);
	}//end testActivatingAndDeactivating()

	/**
	 * A request without an entry or a boolean is a 400, and nothing is written.
	 *
	 * @return void
	 */
	public function testABadRequestIsRefused(): void {
		foreach ([['active' => true], ['entry' => self::DORDRECHT], ['entry' => self::DORDRECHT, 'active' => 'yes']] as $params) {
			$this->assertSame(expected: 400, actual: $this->controller(params: $params)->update()->getStatus());
		}

		$this->assertSame(expected: [], actual: $this->calls);
	}//end testABadRequestIsRefused()

	/**
	 * An unknown organisation is a 400; OpenRegister failing is a 503 naming why.
	 *
	 * @return void
	 */
	public function testRefusalsAndFailuresAreAnswered(): void {
		$unknown = $this->controller(params: ['entry' => 'x', 'active' => true], failure: new InvalidArgumentException('not in the registers'))->update();
		$this->assertSame(expected: 400, actual: $unknown->getStatus());
		$this->assertStringContainsString(needle: 'not in the registers', haystack: $unknown->getData()['error']);

		$down = $this->controller(params: ['entry' => self::DORDRECHT, 'active' => true], failure: new RuntimeException('OpenRegister down'))->update();
		$this->assertSame(expected: 503, actual: $down->getStatus());
		$this->assertStringContainsString(needle: 'OpenRegister down', haystack: $down->getData()['error']);

		$unreadable = $this->controller(params: [], failure: new RuntimeException('vocabulary gone'))->index();
		$this->assertSame(expected: 503, actual: $unreadable->getStatus());
		$this->assertStringContainsString(needle: 'vocabulary gone', haystack: $unreadable->getData()['error']);
	}//end testRefusalsAndFailuresAreAnswered()
}//end class
