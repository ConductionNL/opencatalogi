<?php

/**
 * Moves this app's CMS pages and menus onto Portaliq.
 *
 * OpenCatalogi and Portaliq both declared `page` and `menu`, and a schema slug
 * is global per organisation, so the two collided. They are not two copies of
 * one model: opencatalogi's page carries `contents`, `groups` and
 * `hideBeforeLogin` (a catalog website's page), Portaliq's carries `body`,
 * `route`, `locale` and `status` (a portal's page). They share `title`.
 *
 * Portaliq is where a portal's content belongs, and it already serves
 * `/api/content/pages` and `/api/content/menus` publicly. So this moves the
 * content there rather than teaching either app about the other.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenCatalogi.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenCatalogi\Service;

use OCA\OpenCatalogi\Contract\CmsMigrationServiceInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The rules for turning an OpenCatalogi page into a Portaliq page.
 *
 * @spec openspec/changes/cms-moves-to-portaliq/specs/portal-content/spec.md#requirement-a-page-becomes-a-portal-page-req-cms-101
 */
class CmsMigrationService implements CmsMigrationServiceInterface {
	use FlattensObjectRows;

	/**
	 * How an OpenCatalogi content block maps onto a Portaliq widget.
	 *
	 * Declared rather than passed through. A block type with no entry here is
	 * REFUSED: guessing a widget key produces a page that saves cleanly, renders
	 * nothing, and reports no error.
	 *
	 * @var array<string, string>
	 */
	public const WIDGET_FOR_BLOCK = [
		'hero' => 'hero',
		'text' => 'markdown',
	];

	/**
	 * How many grid rows each widget occupies.
	 *
	 * @var array<string, int>
	 */
	public const WIDGET_HEIGHT = [
		'hero' => 4,
		'markdown' => 4,
	];

	// Every value in WIDGET_FOR_BLOCK must have an entry above. A widget with no
	// height would be placed on top of the one before it.

	/**
	 * Wire the container and the logger.
	 *
	 * @param ContainerInterface $container Container, so OpenRegister is resolved lazily.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The Portaliq route an OpenCatalogi slug becomes.
	 *
	 * Portaliq serves a page at an in-portal path with a leading slash;
	 * OpenCatalogi stored a bare slug. An empty slug becomes the portal root,
	 * which is what a page with no slug was already acting as.
	 *
	 * @param mixed $slug The stored slug.
	 *
	 * @return string The route.
	 *
	 * @spec openspec/changes/cms-moves-to-portaliq/specs/portal-content/spec.md#requirement-a-page-becomes-a-portal-page-req-cms-101
	 */
	public function routeFor(mixed $slug): string {
		if (is_scalar($slug) === false) {
			$slug = '';
		}

		$slug = trim((string)$slug);
		if ($slug === '' || $slug === '/') {
			return '/';
		}

		return '/' . ltrim($slug, '/');
	}//end routeFor()

	/**
	 * Turn a page's content blocks into a Portaliq widget grid.
	 *
	 * Blocks stack down a 12-column grid in the order they were authored, which
	 * is how they rendered before.
	 *
	 * @param array<int, mixed> $blocks The stored content blocks.
	 * @param string $pageId A stable prefix for widget ids.
	 *
	 * @return array{body: array<string, mixed>, unmapped: array<int, string>, dropped: array<int, string>}
	 *
	 * Returns: The body, plus any block type with no mapping and any prop the target widget does not declare.
	 *
	 * @spec openspec/changes/cms-moves-to-portaliq/specs/portal-content/spec.md#requirement-content-blocks-become-widgets-req-cms-102
	 */
	public function bodyFor(array $blocks, string $pageId): array {
		$layout = ['widgets' => [], 'unmapped' => [], 'dropped' => [], 'row' => 0];
		foreach ($blocks as $index => $block) {
			if (is_array($block) === true) {
				$layout = self::placeBlock(layout: $layout, entry: ['id' => $pageId . '-' . $index, 'block' => $block]);
			}
		}

		return [
			'body' => ['type' => 'grid', 'widgets' => $layout['widgets']],
			'unmapped' => array_values(array_unique($layout['unmapped'])),
			'dropped' => array_values(array_unique($layout['dropped'])),
		];
	}//end bodyFor()

	/**
	 * The layout with one block placed below the others as its widget, or its type
	 * listed as unmapped when no widget carries it.
	 *
	 * @param array{widgets: array, unmapped: array, dropped: array, row: int} $layout The layout so far.
	 * @param array{id: string, block: array} $entry The block and its id prefix.
	 *
	 * @return array{widgets: array, unmapped: array, dropped: array, row: int}
	 *
	 * @spec openspec/changes/cms-moves-to-portaliq/specs/portal-content/spec.md#requirement-content-blocks-become-widgets-req-cms-102
	 */
	private static function placeBlock(array $layout, array $entry): array {
		$type = (string)($entry['block']['type'] ?? '');
		$widgetKey = (self::WIDGET_FOR_BLOCK[$type] ?? null);
		if ($widgetKey === null) {
			$layout['unmapped'][] = $type;
			return $layout;
		}

		$data = self::blockData(block: $entry['block']);
		$layout['dropped'] = array_merge($layout['dropped'], self::droppedFields(widgetKey: $widgetKey, data: $data));
		$height = self::WIDGET_HEIGHT[$widgetKey];
		$layout['widgets'][] = [
			'id' => $entry['id'] . '-' . $widgetKey,
			'widgetKey' => $widgetKey,
			'gridX' => 0,
			'gridY' => $layout['row'],
			'gridWidth' => 12,
			'gridHeight' => $height,
			'props' => self::widgetProps(widgetKey: $widgetKey, data: $data),
		];
		$layout['row'] += $height;

		return $layout;
	}//end placeBlock()

	/**
	 * A block's data, or an empty array when it has none or it is not a map.
	 *
	 * @param array $block The block.
	 *
	 * @return array
	 *
	 * @spec openspec/changes/cms-moves-to-portaliq/specs/portal-content/spec.md#requirement-content-blocks-become-widgets-req-cms-102
	 */
	private static function blockData(array $block): array {
		$data = ($block['data'] ?? []);
		if (is_array($data) === false) {
			return [];
		}

		return $data;
	}//end blockData()

	/**
	 * The widget's props: the block data, except for markdown, which reads `markdown`, not `content`.
	 *
	 * Passing a text block through unchanged would save fine and render an empty widget.
	 *
	 * @param string $widgetKey The widget.
	 * @param array $data The block data.
	 *
	 * @return array
	 *
	 * @spec openspec/changes/cms-moves-to-portaliq/specs/portal-content/spec.md#requirement-content-blocks-become-widgets-req-cms-102
	 */
	private static function widgetProps(string $widgetKey, array $data): array {
		if ($widgetKey === 'markdown') {
			return ['markdown' => (string)($data['content'] ?? '')];
		}

		return $data;
	}//end widgetProps()

	/**
	 * The block fields the widget declares no prop for: a hero's subtitle.
	 *
	 * The subtitle stays in props so the text is not destroyed, and is reported so
	 * nobody discovers it missing from the rendered page instead.
	 *
	 * @param string $widgetKey The widget.
	 * @param array $data The block data.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/cms-moves-to-portaliq/specs/portal-content/spec.md#requirement-content-blocks-become-widgets-req-cms-102
	 */
	private static function droppedFields(string $widgetKey, array $data): array {
		if ($widgetKey === 'hero' && ($data['subtitle'] ?? '') !== '') {
			return ['hero.subtitle'];
		}

		return [];
	}//end droppedFields()

	/**
	 * The Portaliq page one OpenCatalogi page becomes.
	 *
	 * @param array<string, mixed> $page The source page's fields.
	 * @param string $portal The portal slug it belongs to.
	 *
	 * @return array{page: array<string, mixed>, unmapped: array<int, string>, dropped: array<int, string>}
	 *
	 * Returns: The page to write, and what could not be carried.
	 *
	 * @spec openspec/changes/cms-moves-to-portaliq/specs/portal-content/spec.md#requirement-a-page-becomes-a-portal-page-req-cms-101
	 */
	public function pageFor(array $page, string $portal): array {
		$blocks = ($page['contents'] ?? []);
		if (is_array($blocks) === false) {
			$blocks = [];
		}

		$route = $this->routeFor(slug: ($page['slug'] ?? ''));

		// The root page's route trims to an empty string, which would make every
		// one of its widget ids start with a bare dash.
		$pageId = trim($route, '/');
		if ($pageId === '') {
			$pageId = 'root';
		}

		$built = $this->bodyFor(blocks: $blocks, pageId: $pageId);

		return [
			'page' => [
				'title' => (string)($page['title'] ?? 'Untitled'),
				'route' => $route,
				'portal' => $portal,
				// Every source page was live: OpenCatalogi had no draft state, so
				// importing them as drafts would take a working site offline.
				'status' => 'published',
				'body' => $built['body'],
			],
			'unmapped' => $built['unmapped'],
			'dropped' => $built['dropped'],
		];
	}//end pageFor()

	/**
	 * The Portaliq menu one OpenCatalogi menu becomes.
	 *
	 * `groups`, `hideBeforeLogin` and `icon` have no counterpart and are
	 * reported by the caller rather than carried.
	 *
	 * @param array<string, mixed> $menu The source menu's fields.
	 * @param string $portal The portal slug it belongs to.
	 *
	 * @return array{menu: array<string, mixed>, dropped: array<int, string>} The menu, and what is lost.
	 *
	 * @spec openspec/changes/cms-moves-to-portaliq/specs/portal-content/spec.md#requirement-a-menu-becomes-a-portal-menu-req-cms-103
	 */
	public function menuFor(array $menu, string $portal): array {
		$dropped = [];
		foreach (['groups', 'hideBeforeLogin', 'icon'] as $field) {
			$value = ($menu[$field] ?? null);
			if ($value !== null && $value !== '' && $value !== []) {
				$dropped[] = $field;
			}
		}

		$items = ($menu['items'] ?? []);
		if (is_array($items) === false) {
			$items = [];
		}

		return [
			'menu' => [
				'title' => (string)($menu['title'] ?? 'Untitled'),
				'portal' => $portal,
				'position' => (int)($menu['position'] ?? 0),
				'items' => $items,
			],
			'dropped' => $dropped,
		];
	}//end menuFor()

	/**
	 * Resolve an OpenRegister collaborator, or null when it is unavailable.
	 *
	 * @param string $id The service identifier.
	 *
	 * @return object|null The service, or null.
	 *
	 * @spec openspec/changes/cms-moves-to-portaliq/specs/portal-content/spec.md#requirement-a-page-becomes-a-portal-page-req-cms-101
	 */
	public function openRegister(string $id): ?object {
		try {
			$service = $this->container->get($id);
		} catch (Throwable $e) {
			$this->logger->warning(
				'OpenCatalogi: CMS migration could not resolve an OpenRegister service',
				['service' => $id, 'error' => $e->getMessage()]
			);
			return null;
		}

		if (is_object($service) === false) {
			return null;
		}

		return $service;
	}//end openRegister()
}//end class
