<?php

/**
 * PublicCategoriesController test: the explanation is served publicly, hidden categories are not.
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

use OCA\OpenCatalogi\Controller\PublicCategoriesController;
use OCA\OpenCatalogi\Service\DcatVocabularyService;
use OCA\OpenCatalogi\Service\TooiVocabularyService;
use OCA\OpenCatalogi\Service\ValueListsService;
use OCA\OpenCatalogi\Service\Woo\RefusalGrounds;
use OCA\OpenCatalogi\Service\Woo\ValueListChoiceStore;
use OCA\OpenCatalogi\Service\Woo\ValueListView;
use OCP\AppFramework\Http\Attribute\CORS;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * The real bundled category list and the real view; the choice store is a double.
 *
 * @covers \OCA\OpenCatalogi\Controller\PublicCategoriesController
 * @uses   \OCA\OpenCatalogi\Service\Woo\ValueListView
 * @uses   \OCA\OpenCatalogi\Service\ValueListsService
 * @uses   \OCA\OpenCatalogi\Service\TooiVocabularyService
 * @uses   \OCA\OpenCatalogi\Service\DcatVocabularyService
 * @uses   \OCA\OpenCatalogi\Service\Woo\RefusalGrounds
 */
class PublicCategoriesTest extends TestCase {

	/**
	 * The TOOI URI of the covenants category.
	 */
	private const COVENANTS = 'https://identifier.overheid.nl/tooi/def/thes/kern/c_8fc2335c';

	/**
	 * The TOOI URI of the Staten-Generaal meeting documents category.
	 */
	private const STATES_GENERAL = 'https://identifier.overheid.nl/tooi/def/thes/kern/c_c76862ab';

	/**
	 * The controller over the real list and a store answering the choices, or failing.
	 *
	 * @param array<string, array<string, mixed>>|null $choices The choices by entry URI, or null to fail.
	 *
	 * @return PublicCategoriesController
	 */
	private function controller(?array $choices): PublicCategoriesController {
		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturn('{}');
		$lists = new ValueListsService(
			tooi: new TooiVocabularyService(),
			dcat: new DcatVocabularyService(),
			grounds: new RefusalGrounds(),
			appConfig: $config
		);

		$store = $this->createMock(originalClassName: ValueListChoiceStore::class);
		if ($choices === null) {
			$store->method('forScheme')->willThrowException(new RuntimeException('not configured'));
		} else {
			$store->method('forScheme')->with(PublicCategoriesController::SCHEME)->willReturn($choices);
		}

		$view = new ValueListView(lists: $lists, choices: $store, logger: $this->createMock(originalClassName: LoggerInterface::class));

		return new PublicCategoriesController(
			appName: 'opencatalogi',
			request: $this->createMock(originalClassName: IRequest::class),
			view: $view,
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);
	}//end controller()

	/**
	 * One choice in the store's shape.
	 *
	 * @param array<string, mixed> $fields The fields that differ from no choice at all.
	 *
	 * @return array<string, mixed>
	 */
	private static function choice(array $fields): array {
		return array_merge(['order' => null, 'localLabel' => '', 'explanation' => '', 'hidden' => false, 'active' => false], $fields);
	}//end choice()

	/**
	 * Convenanten carries its explanation and the hidden category is absent.
	 *
	 * @return void
	 */
	public function testTheExplanationIsServedAndHiddenCategoriesAreNot(): void {
		$explanation = 'Afspraken die wij maken met andere overheden, bedrijven of organisaties.';
		$data = $this->controller(choices: [
			self::COVENANTS => self::choice(['explanation' => $explanation]),
			self::STATES_GENERAL => self::choice(['hidden' => true]),
		])->index()->getData();

		$byUri = array_column($data['results'], null, 'uri');
		$this->assertSame(expected: $explanation, actual: $byUri[self::COVENANTS]['explanation']);
		$this->assertSame(expected: 'Convenanten', actual: $byUri[self::COVENANTS]['label']);
		$this->assertArrayNotHasKey(key: self::STATES_GENERAL, array: $byUri);
		$this->assertSame(expected: ['code', 'uri', 'label', 'explanation', 'order'], actual: array_keys($data['results'][0]));
		$this->assertSame(expected: count($data['results']), actual: $data['total']);
		$this->assertFalse(condition: $data['choicesUnavailable']);
	}//end testTheExplanationIsServedAndHiddenCategoriesAreNot()

	/**
	 * The administrator's order leads the public list.
	 *
	 * @return void
	 */
	public function testTheAdministratorsOrderLeadsThePublicList(): void {
		$data = $this->controller(choices: [self::COVENANTS => self::choice(['order' => 0])])->index()->getData();

		$this->assertSame(expected: self::COVENANTS, actual: $data['results'][0]['uri']);
		$this->assertSame(expected: 0, actual: $data['results'][0]['order']);
	}//end testTheAdministratorsOrderLeadsThePublicList()

	/**
	 * Unreadable choices hide nothing, and the answer says the choices are unavailable.
	 *
	 * @return void
	 */
	public function testUnreadableChoicesHideNothing(): void {
		$data = $this->controller(choices: null)->index()->getData();

		$this->assertTrue(condition: $data['choicesUnavailable']);
		$this->assertContains(needle: self::STATES_GENERAL, haystack: array_column($data['results'], 'uri'));
		$this->assertGreaterThanOrEqual(expected: 17, actual: $data['total']);
	}//end testUnreadableChoicesHideNothing()

	/**
	 * The route answers anyone, cross-origin.
	 *
	 * @return void
	 */
	public function testTheRouteIsPublicAndCors(): void {
		$method = new ReflectionMethod(PublicCategoriesController::class, 'index');

		$this->assertNotEmpty(actual: $method->getAttributes(PublicPage::class));
		$this->assertNotEmpty(actual: $method->getAttributes(CORS::class));
	}//end testTheRouteIsPublicAndCors()

	/**
	 * The route is declared before the catalogue catch-all and points at this controller.
	 *
	 * @return void
	 */
	public function testTheRouteIsDeclared(): void {
		$routes = require __DIR__ . '/../../../appinfo/routes.php';
		$found = array_values(array_filter(
			$routes['routes'],
			static fn (array $route): bool => $route['url'] === '/api/woo/categories/public'
		));

		$this->assertCount(expectedCount: 1, haystack: $found);
		$this->assertSame(expected: 'publicCategories#index', actual: $found[0]['name']);
		$this->assertSame(expected: 'GET', actual: $found[0]['verb']);

		$urls = array_column($routes['routes'], 'url');
		$catchAll = array_search('/api/{catalogSlug}', $urls, true);
		if ($catchAll !== false) {
			$this->assertLessThan(expected: $catchAll, actual: array_search('/api/woo/categories/public', $urls, true));
		}
	}//end testTheRouteIsDeclared()
}//end class
