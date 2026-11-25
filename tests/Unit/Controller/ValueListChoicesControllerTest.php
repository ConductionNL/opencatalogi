<?php

/**
 * ValueListChoicesController test: an administrator reads a list and saves one choice.
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
 * @spec openspec/changes/woo-value-list-curation/tasks.md#task-3.1
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Tests\Unit\Controller;

use OCA\OpenCatalogi\Controller\ValueListChoicesController;
use OCA\OpenCatalogi\Service\ValueList\ValueListView;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

/**
 * The view is a double; the request checks are real.
 *
 * @covers \OCA\OpenCatalogi\Controller\ValueListChoicesController
 */
class ValueListChoicesControllerTest extends TestCase {

	/**
	 * The URI of the covenants category.
	 */
	private const COVENANTS = 'https://identifier.overheid.nl/tooi/def/thes/kern/c_8fc2335c';

	/**
	 * The saves the store received.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $saves = [];

	/**
	 * The controller over the request parameters.
	 *
	 * @param array<string, mixed> $params    The request parameters.
	 * @param bool                 $saveFails Whether the store refuses the save.
	 *
	 * @return ValueListChoicesController
	 */
	private function controller(array $params, bool $saveFails = false): ValueListChoicesController {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($params[$key] ?? $default)
		);

		$view = $this->createMock(originalClassName: ValueListView::class);
		$view->method('entries')->willReturnCallback(
			static fn (string $scheme): array => [
				'entries' => [['code' => 'infocat011', 'uri' => self::COVENANTS]],
				'choicesUnavailable' => false,
				'scheme' => $scheme,
			]
		);
		$view->method('save')->willReturnCallback(function (array $choice) use ($saveFails): void {
			if ($saveFails === true) {
				throw new RuntimeException('The field code belongs to the national value list.');
			}

			$this->saves[] = $choice;
		});
		$view->method('saveOrder')->willReturnCallback(function (string $scheme, array $entries): int {
			$this->saves[] = ['order' => $entries, 'scheme' => $scheme];
			return count($entries);
		});

		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters)
		);

		return new ValueListChoicesController(
			appName: 'opencatalogi',
			request: $request,
			view: $view,
			l10n: $l10n
		);
	}//end controller()

	/**
	 * The list is read for the scheme asked, the information categories by default.
	 *
	 * @return void
	 */
	public function testTheListIsReadForTheSchemeAsked(): void {
		$this->assertSame(expected: 'informatiecategorieen', actual: $this->controller(params: [])->index()->getData()['scheme']);
		$this->assertSame(expected: 'documentsoorten', actual: $this->controller(params: ['scheme' => 'documentsoorten'])->index()->getData()['scheme']);
		$this->assertSame(expected: 400, actual: $this->controller(params: ['scheme' => 'nope'])->index()->getStatus());
	}//end testTheListIsReadForTheSchemeAsked()

	/**
	 * An explanation is saved as the choice about the entry, and the list after the save is answered.
	 *
	 * @return void
	 */
	public function testAnExplanationIsSaved(): void {
		$response = $this->controller(params: [
			'scheme' => 'informatiecategorieen',
			'entry' => self::COVENANTS,
			'choice' => ['explanation' => 'Afspraken met andere partijen.'],
		])->update();

		$this->assertSame(expected: 200, actual: $response->getStatus());
		$this->assertSame(
			expected: [['explanation' => 'Afspraken met andere partijen.', 'scheme' => 'informatiecategorieen', 'entry' => self::COVENANTS]],
			actual: $this->saves
		);
		$this->assertSame(expected: self::COVENANTS, actual: $response->getData()['entries'][0]['uri']);
	}//end testAnExplanationIsSaved()

	/**
	 * A bad request is refused before anything is saved.
	 *
	 * @return void
	 */
	public function testABadRequestIsRefusedBeforeTheSave(): void {
		$cases = [
			'unknown list' => ['scheme' => 'nope', 'entry' => self::COVENANTS, 'choice' => []],
			'no entry' => ['scheme' => 'informatiecategorieen', 'entry' => ' ', 'choice' => []],
			'normative field' => ['scheme' => 'informatiecategorieen', 'entry' => self::COVENANTS, 'choice' => ['code' => 'infocat099']],
			'too long' => ['scheme' => 'informatiecategorieen', 'entry' => self::COVENANTS, 'choice' => ['explanation' => str_repeat('a', 1001)]],
		];

		foreach ($cases as $name => $params) {
			$response = $this->controller(params: $params)->update();
			$this->assertSame(expected: 400, actual: $response->getStatus(), message: $name);
		}

		$this->assertStringContainsString(
			needle: 'code',
			haystack: $this->controller(params: $cases['normative field'])->update()->getData()['error']
		);
		$this->assertSame(expected: [], actual: $this->saves);
	}//end testABadRequestIsRefusedBeforeTheSave()

	/**
	 * A save the guard refuses is answered 422 with the reason.
	 *
	 * @return void
	 */
	public function testARefusedSaveSaysWhy(): void {
		$response = $this->controller(
			params: ['scheme' => 'informatiecategorieen', 'entry' => self::COVENANTS, 'choice' => ['hidden' => true]],
			saveFails: true
		)->update();

		$this->assertSame(expected: 422, actual: $response->getStatus());
		$this->assertStringContainsString(needle: 'national value list', haystack: $response->getData()['error']);
	}//end testARefusedSaveSaysWhy()

	/**
	 * An order is saved as the entry URIs, first first; a request without entries is refused.
	 *
	 * @return void
	 */
	public function testAnOrderIsSaved(): void {
		$params = ['scheme' => 'informatiecategorieen', 'entries' => [self::COVENANTS, '', 'https://example.org/b']];
		$response = $this->controller(params: $params)->reorder();

		$this->assertSame(expected: 200, actual: $response->getStatus());
		$this->assertSame(expected: [['order' => [self::COVENANTS, 'https://example.org/b'], 'scheme' => 'informatiecategorieen']], actual: $this->saves);

		$this->assertSame(expected: 400, actual: $this->controller(params: ['scheme' => 'informatiecategorieen', 'entries' => []])->reorder()->getStatus());
		$this->assertSame(expected: 400, actual: $this->controller(params: ['scheme' => 'nope', 'entries' => [self::COVENANTS]])->reorder()->getStatus());
	}//end testAnOrderIsSaved()

	/**
	 * The curation routes are for administrators only.
	 *
	 * @return void
	 */
	public function testTheRoutesAreForAdministratorsOnly(): void {
		foreach (['index', 'update', 'reorder'] as $name) {
			$method = new ReflectionMethod(objectOrMethod: ValueListChoicesController::class, method: $name);
			$this->assertSame(expected: [], actual: $method->getAttributes(name: NoAdminRequired::class), message: $name);
			$this->assertSame(expected: [], actual: $method->getAttributes(name: PublicPage::class), message: $name);
			$this->assertCount(expectedCount: 1, haystack: $method->getAttributes(name: AuthorizedAdminSetting::class), message: $name);
			$this->assertStringNotContainsString(needle: '@NoAdminRequired', haystack: (string)$method->getDocComment());
		}
	}//end testTheRoutesAreForAdministratorsOnly()
}//end class
