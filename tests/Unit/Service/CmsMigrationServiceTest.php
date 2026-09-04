<?php

/**
 * Unit tests for the CMS-to-Portaliq mapping rules.
 *
 * @category Test
 * @package  OCA\OpenCatalogi\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenCatalogi.nl
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenCatalogi\Service\CmsMigrationService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Locks what a migrated page keeps, and what it refuses to guess.
 */
class CmsMigrationServiceTest extends TestCase {

	/**
	 * @var CmsMigrationService
	 */
	private CmsMigrationService $service;

	/**
	 * Build the service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->service = new CmsMigrationService(
			container: $this->createMock(ContainerInterface::class),
			logger: $this->createMock(LoggerInterface::class)
		);

	}//end setUp()

	/**
	 * A bare slug becomes an in-portal route with a leading slash.
	 *
	 * @return void
	 */
	public function testASlugBecomesARoute(): void {
		$this->assertSame('/about', $this->service->routeFor(slug: 'about'));
		$this->assertSame('/about', $this->service->routeFor(slug: '/about'));

	}//end testASlugBecomesARoute()

	/**
	 * A page with no slug becomes the portal root, which is what it already
	 * behaved as.
	 *
	 * @return void
	 */
	public function testAnEmptySlugBecomesTheRoot(): void {
		foreach (['', '   ', '/', null, ['x']] as $slug) {
			$this->assertSame('/', $this->service->routeFor(slug: $slug));
		}

	}//end testAnEmptySlugBecomesTheRoot()

	/**
	 * A text block becomes a markdown widget, and its content lands under the
	 * key that widget actually reads.
	 *
	 * This is the assertion that matters most in the file. Passing the block
	 * through unchanged would save cleanly and render an EMPTY widget, because
	 * Portaliq's markdown widget reads `markdown` and the source wrote
	 * `content`.
	 *
	 * @return void
	 */
	public function testATextBlockBecomesAMarkdownWidgetWithTheRightPropKey(): void {
		$built = $this->service->bodyFor(
			blocks: [['type' => 'text', 'data' => ['content' => 'Hello there.']]],
			pageId: 'home'
		);

		$widget = $built['body']['widgets'][0];
		$this->assertSame('markdown', $widget['widgetKey']);
		$this->assertSame('Hello there.', $widget['props']['markdown']);
		$this->assertArrayNotHasKey('content', $widget['props']);

	}//end testATextBlockBecomesAMarkdownWidgetWithTheRightPropKey()

	/**
	 * A hero block keeps its props, because Portaliq's hero reads the same
	 * names.
	 *
	 * @return void
	 */
	public function testAHeroBlockKeepsItsProps(): void {
		$built = $this->service->bodyFor(
			blocks: [['type' => 'hero', 'data' => ['title' => 'Welcome']]],
			pageId: 'home'
		);

		$widget = $built['body']['widgets'][0];
		$this->assertSame('hero', $widget['widgetKey']);
		$this->assertSame('Welcome', $widget['props']['title']);

	}//end testAHeroBlockKeepsItsProps()

	/**
	 * A hero subtitle is REPORTED, because Portaliq's hero declares none and it
	 * will not render. It is still carried, so the text is not destroyed.
	 *
	 * @return void
	 */
	public function testAHeroSubtitleIsReportedRatherThanSilentlyLost(): void {
		$built = $this->service->bodyFor(
			blocks: [['type' => 'hero', 'data' => ['title' => 'Welcome', 'subtitle' => 'To the catalog']]],
			pageId: 'home'
		);

		$this->assertContains('hero.subtitle', $built['dropped']);
		$this->assertSame('To the catalog', $built['body']['widgets'][0]['props']['subtitle']);

	}//end testAHeroSubtitleIsReportedRatherThanSilentlyLost()

	/**
	 * An unknown block type is REPORTED, not guessed. A guessed widget key
	 * saves a page that renders nothing and reports no error.
	 *
	 * @return void
	 */
	public function testAnUnknownBlockTypeIsRefusedRatherThanGuessed(): void {
		$built = $this->service->bodyFor(
			blocks: [
				['type' => 'text', 'data' => ['content' => 'kept']],
				['type' => 'carousel', 'data' => ['images' => []]],
			],
			pageId: 'home'
		);

		$this->assertSame(['carousel'], $built['unmapped']);
		$this->assertCount(1, $built['body']['widgets'], 'the mappable block is still built');

	}//end testAnUnknownBlockTypeIsRefusedRatherThanGuessed()

	/**
	 * Blocks stack down the grid in authored order, which is how they rendered
	 * before. A widget placed at the same gridY as the one above it would
	 * overlap it.
	 *
	 * @return void
	 */
	public function testBlocksStackInOrderWithoutOverlapping(): void {
		$built = $this->service->bodyFor(
			blocks: [
				['type' => 'hero', 'data' => ['title' => 'A']],
				['type' => 'text', 'data' => ['content' => 'B']],
				['type' => 'text', 'data' => ['content' => 'C']],
			],
			pageId: 'home'
		);

		$rows = array_map(static fn (array $w) => $w['gridY'], $built['body']['widgets']);
		$this->assertSame([0, 4, 8], $rows);
		foreach ($built['body']['widgets'] as $widget) {
			$this->assertSame(0, $widget['gridX']);
			$this->assertSame(12, $widget['gridWidth']);
		}

	}//end testBlocksStackInOrderWithoutOverlapping()

	/**
	 * Widget ids are unique within a page, so two blocks of the same type do
	 * not collide.
	 *
	 * @return void
	 */
	public function testWidgetIdsAreUniqueWithinAPage(): void {
		$built = $this->service->bodyFor(
			blocks: [
				['type' => 'text', 'data' => ['content' => 'one']],
				['type' => 'text', 'data' => ['content' => 'two']],
			],
			pageId: 'home'
		);

		$ids = array_map(static fn (array $w) => $w['id'], $built['body']['widgets']);
		$this->assertSame($ids, array_unique($ids));

	}//end testWidgetIdsAreUniqueWithinAPage()

	/**
	 * A migrated page is PUBLISHED. OpenCatalogi had no draft state, so every
	 * source page was live; importing them as drafts would take a working site
	 * offline.
	 *
	 * @return void
	 */
	public function testAMigratedPageIsPublished(): void {
		$built = $this->service->pageFor(
			page: ['title' => 'Home', 'slug' => 'home', 'contents' => []],
			portal: 'demo'
		);

		$this->assertSame('published', $built['page']['status']);
		$this->assertSame('demo', $built['page']['portal']);
		$this->assertSame('/home', $built['page']['route']);
		$this->assertSame('Home', $built['page']['title']);

	}//end testAMigratedPageIsPublished()

	/**
	 * A menu keeps its items and position, and the fields Portaliq has no
	 * counterpart for are reported.
	 *
	 * @return void
	 */
	public function testAMenuKeepsItsItemsAndReportsWhatItCannotCarry(): void {
		$built = $this->service->menuFor(
			menu: [
				'title' => 'Main Menu',
				'position' => 2,
				'items' => [['name' => 'Home', 'link' => '/']],
				'groups' => ['admin'],
				'icon' => 'home',
			],
			portal: 'demo'
		);

		$this->assertSame('Main Menu', $built['menu']['title']);
		$this->assertSame('demo', $built['menu']['portal']);
		$this->assertSame(2, $built['menu']['position']);
		$this->assertCount(1, $built['menu']['items']);
		$this->assertContains('groups', $built['dropped']);
		$this->assertContains('icon', $built['dropped']);

	}//end testAMenuKeepsItsItemsAndReportsWhatItCannotCarry()

	/**
	 * A menu that uses none of the unmappable fields reports nothing, so the
	 * report means something when it does appear.
	 *
	 * @return void
	 */
	public function testACleanMenuReportsNothing(): void {
		$built = $this->service->menuFor(
			menu: ['title' => 'Footer', 'position' => 1, 'items' => []],
			portal: 'demo'
		);

		$this->assertSame([], $built['dropped']);

	}//end testACleanMenuReportsNothing()

	/**
	 * Every widget the block map can produce has a declared height.
	 *
	 * The height lookup is no longer defended by a fallback, because a fallback
	 * hid the real requirement. Adding a block type without a height would now
	 * be an undefined-index at migration time, on real data. This turns that
	 * into a test failure instead.
	 *
	 * @return void
	 */
	public function testEveryMappedWidgetHasADeclaredHeight(): void {
		$reflection = new \ReflectionClass(CmsMigrationService::class);
		$blocks = $reflection->getConstant('WIDGET_FOR_BLOCK');
		$heights = $reflection->getConstant('WIDGET_HEIGHT');

		$missing = array_diff(array_values($blocks), array_keys($heights));

		$this->assertSame([], $missing, 'a widget with no height would overlap the one above it');

	}//end testEveryMappedWidgetHasADeclaredHeight()

	/**
	 * A page whose contents are not an array yields an empty grid rather than
	 * throwing mid-migration.
	 *
	 * @return void
	 */
	public function testMalformedContentsYieldAnEmptyGrid(): void {
		$built = $this->service->pageFor(page: ['title' => 'X', 'contents' => 'oops'], portal: 'demo');

		$this->assertSame([], $built['page']['body']['widgets']);
		$this->assertSame('grid', $built['page']['body']['type']);

	}//end testMalformedContentsYieldAnEmptyGrid()
}//end class
