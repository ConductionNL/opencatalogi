<?php

/**
 * OpenCatalogi CMS Tool
 *
 * Tool for OpenRegister agents to manage CMS content (pages, menus, menu items).
 *
 * @category Tool
 * @package  OCA\OpenCatalogi\Tool
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 *
 * @spec openspec/specs/cms-tool/spec.md
 * @spec openspec/specs/cms-tool/spec.md
 * @spec openspec/specs/cms-tool/spec.md
 * @spec openspec/specs/cms-tool/spec.md
 * @spec openspec/specs/cms-tool/spec.md
 * @spec openspec/specs/cms-tool/spec.md
 * @spec openspec/specs/cms-tool/spec.md
 * @spec openspec/specs/cms-tool/spec.md
 * @spec openspec/specs/cms-tool/spec.md
 * @spec openspec/specs/cms-tool/spec.md
 */

namespace OCA\OpenCatalogi\Tool;

use BadMethodCallException;
use OCA\OpenCatalogi\Contract\CMSToolInterface;
use OCA\OpenRegister\Mcp\Db\Agent;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Mcp\Tool\ToolInterface;
use OCA\OpenRegister\Service\Object\ObjectFindTarget;
use OCA\OpenRegister\Service\Object\ObjectSaveOptions;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * CMS Tool for OpenCatalogi
 *
 * Allows AI agents to create and manage CMS content including:
 * - Pages: Create, list, update, and delete pages
 * - Menus: Create and list menus
 * - Menu Items: Add items to menus
 *
 * SECURITY:
 * - Uses OpenRegister's ObjectService for RBAC
 * - Respects agent's organization boundaries
 * - Validates all input parameters
 *
 * @category Tool
 * @package  OCA\OpenCatalogi\Tool
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.TooManyMethods) Each input check is its own small private method.
 */
class CMSTool implements ToolInterface, CMSToolInterface {

	/**
	 * Agent context
	 *
	 * @var Agent|null
	 */
	private ?Agent $agent = null;

	/**
	 * Current user ID
	 *
	 * @var string|null
	 */
	private ?string $currentUserId = null;

	/**
	 * Logger
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * Object service for data operations
	 *
	 * @var ObjectService
	 */
	private ObjectService $objectService;

	/**
	 * User session
	 *
	 * @var IUserSession
	 */
	private IUserSession $userSession;

	/**
	 * Constructor
	 *
	 * @param ObjectService $objectService Object service
	 * @param LoggerInterface $logger Logger
	 * @param IUserSession $userSession User session
	 */
	public function __construct(
		ObjectService $objectService,
		LoggerInterface $logger,
		IUserSession $userSession,
	) {
		$this->objectService = $objectService;
		$this->logger = $logger;
		$this->userSession = $userSession;

	}//end __construct()

	/**
	 * Get tool name
	 *
	 * @return string Tool name
	 */
	public function getName(): string {
		return 'CMS Tool';
	}//end getName()

	/**
	 * Get tool description
	 *
	 * @return string Tool description
	 */
	public function getDescription(): string {
		return 'Manage website content: create and manage pages, menus, and menu items for OpenCatalogi';
	}//end getDescription()

	/**
	 * Set agent context
	 *
	 * @param Agent|null $agent Agent entity
	 *
	 * @return void
	 */
	public function setAgent(?Agent $agent): void {
		$this->agent = $agent;

		// Determine user ID for operations.
		// Prioritize session user, fallback to agent's configured user.
		$this->currentUserId = null;
		$sessionUser = $this->userSession->getUser();
		if ($sessionUser !== null) {
			$this->currentUserId = $sessionUser->getUID();
			return;
		}

		if ($agent !== null) {
			$this->currentUserId = $agent->getUser();
		}

	}//end setAgent()

	/**
	 * Get function definitions
	 *
	 * Returns OpenAI-compatible function definitions for the LLM.
	 *
	 * @return array<array<string, mixed>> Function definitions
	 *
	 * @spec openspec/specs/cms-tool/spec.md
	 */
	public function getFunctions(): array {
		return [
			$this->createPageFunction(),
			$this->listPagesFunction(),
			$this->createMenuFunction(),
			$this->listMenusFunction(),
			$this->addMenuItemFunction(),
		];

	}//end getFunctions()

	/**
	 * The definition of the `cms_create_page` function.
	 *
	 * @return array<string, mixed> The function definition
	 *
	 * @spec openspec/specs/cms-tool/spec.md
	 */
	private function createPageFunction(): array {
		$properties = $this->typedProperties(
			type: 'string',
			descriptions: [
				'title' => 'Page title (required)',
				'summary' => 'Brief summary of the page',
				'description' => 'Full page content in HTML or markdown',
				'slug' => 'URL-friendly slug (auto-generated if not provided)',
			]
		);

		return $this->definition(
			head: ['cms_create_page', 'page', 'create', 'Create a new page with title and content. Returns the page UUID.'],
			parameters: $this->objectParameters(properties: $properties, required: ['title'])
		);

	}//end createPageFunction()

	/**
	 * The definition of the `cms_list_pages` function.
	 *
	 * @return array<string, mixed> The function definition
	 *
	 * @spec openspec/specs/cms-tool/spec.md
	 */
	private function listPagesFunction(): array {
		$properties = $this->typedProperties(type: 'integer', descriptions: ['limit' => 'Maximum number of pages to return (default: 50)']);

		return $this->definition(
			head: ['cms_list_pages', 'page', 'list', 'List all pages. Returns array of pages with title, slug, and UUID.'],
			parameters: $this->objectParameters(properties: $properties, required: [])
		);

	}//end listPagesFunction()

	/**
	 * The definition of the `cms_create_menu` function.
	 *
	 * @return array<string, mixed> The function definition
	 *
	 * @spec openspec/specs/cms-tool/spec.md
	 */
	private function createMenuFunction(): array {
		return $this->definition(
			head: [
				'cms_create_menu',
				'menu',
				'create',
				'Create a new menu with items including position, names, links, order, and access groups.',
			],
			parameters: $this->objectParameters(properties: $this->createMenuProperties(), required: ['title', 'items'])
		);

	}//end createMenuFunction()

	/**
	 * The properties of the `cms_create_menu` function: title, position, items, groups, hideBeforeLogin.
	 *
	 * @return array<string, array<string, mixed>> The property schemas
	 *
	 * @spec openspec/specs/cms-tool/spec.md
	 */
	private function createMenuProperties(): array {
		$position = 'Menu display position/order (0 = first, higher = later). ASK THE USER for this if not provided.';
		$groups = 'Nextcloud groups that can access this entire menu (RBAC). Ask user if they want to restrict access.';

		$properties = $this->typedProperties(type: 'string', descriptions: ['title' => 'Menu title/name (required)']);
		$properties += $this->typedProperties(type: 'number', descriptions: ['position' => $position]);
		$properties += $this->typedProperties(type: 'array', descriptions: ['items' => 'Array of menu items. Each item MUST have: order, name, and link.']);
		$properties['items']['items'] = $this->menuItemSchema();
		$properties += $this->groupsProperty(description: $groups);
		$properties += $this->typedProperties(
			type: 'boolean',
			descriptions: ['hideBeforeLogin' => 'Whether to hide this menu before user login (security setting)']
		);

		return $properties;
	}//end createMenuProperties()

	/**
	 * The schema of one item in the `cms_create_menu` function's `items` parameter.
	 *
	 * @return array<string, mixed> The JSON schema of a menu item
	 *
	 * @spec openspec/specs/cms-tool/spec.md
	 */
	private function menuItemSchema(): array {
		$properties = $this->typedProperties(type: 'number', descriptions: ['order' => 'Item display order within menu']);
		$properties += $this->typedProperties(
			type: 'string',
			descriptions: [
				'name' => 'Display name of the menu item',
				'link' => 'URL or path (e.g., /page-slug or https://example.com)',
				'description' => 'Optional item description',
				'icon' => 'Optional icon name',
			]
		);
		$properties += $this->groupsProperty(description: 'Nextcloud groups that can access this item (RBAC)');

		return $this->objectParameters(properties: $properties, required: ['order', 'name', 'link']);
	}//end menuItemSchema()

	/**
	 * The definition of the `cms_list_menus` function.
	 *
	 * @return array<string, mixed> The function definition
	 *
	 * @spec openspec/specs/cms-tool/spec.md
	 */
	private function listMenusFunction(): array {
		return $this->definition(
			head: ['cms_list_menus', 'menu', 'list', 'List all menus. Returns array of menus with name and UUID.'],
			parameters: $this->objectParameters(properties: [], required: [])
		);

	}//end listMenusFunction()

	/**
	 * The definition of the `cms_add_menu_item` function.
	 *
	 * @return array<string, mixed> The function definition
	 *
	 * @spec openspec/specs/cms-tool/spec.md
	 */
	private function addMenuItemFunction(): array {
		$properties = $this->typedProperties(
			type: 'string',
			descriptions: [
				'menuId' => 'UUID of the menu to add item to (required)',
				'name' => 'Label for the menu item (required)',
				'link' => 'External URL to link to (provide either link or pageId)',
				'pageId' => 'UUID of a page to link to (provide either link or pageId)',
			]
		);
		$properties += $this->typedProperties(type: 'integer', descriptions: ['order' => 'Display order in the menu']);

		return $this->definition(
			head: ['cms_add_menu_item', 'menuItem', 'create', 'Add an item to a menu. Can link to a page or external URL.'],
			parameters: $this->objectParameters(properties: $properties, required: ['menuId', 'name'])
		);

	}//end addMenuItemFunction()

	/**
	 * A function definition from its name, subject, action and description, and its parameters.
	 *
	 * @param array{0: string, 1: string, 2: string, 3: string} $head Name, subject, action and description.
	 * @param array<string, mixed> $parameters The parameters schema.
	 *
	 * @return array<string, mixed> The function definition
	 *
	 * @spec openspec/specs/cms-tool/spec.md
	 */
	private function definition(array $head, array $parameters): array {
		return [
			'name' => $head[0],
			'subject' => $head[1],
			'action' => $head[2],
			'description' => $head[3],
			'parameters' => $parameters,
		];

	}//end definition()

	/**
	 * An object schema with these properties, of which these are required.
	 *
	 * @param array<string, mixed> $properties The property schemas.
	 * @param array<int, string> $required The required property names.
	 *
	 * @return array<string, mixed> The object schema
	 *
	 * @spec openspec/specs/cms-tool/spec.md
	 */
	private function objectParameters(array $properties, array $required): array {
		return [
			'type' => 'object',
			'properties' => $properties,
			'required' => $required,
		];

	}//end objectParameters()

	/**
	 * Property schemas of one JSON type, keyed by property name, each with its description.
	 *
	 * @param string $type The JSON type.
	 * @param array<string, string> $descriptions The description per property name.
	 *
	 * @return array<string, array{type: string, description: string}> The property schemas
	 *
	 * @spec openspec/specs/cms-tool/spec.md
	 */
	private function typedProperties(string $type, array $descriptions): array {
		return array_map(
			static fn (string $description): array => ['type' => $type, 'description' => $description],
			$descriptions
		);

	}//end typedProperties()

	/**
	 * The `groups` property: a list of Nextcloud group names.
	 *
	 * @param string $description The property description.
	 *
	 * @return array<string, array<string, mixed>> The property schema keyed `groups`
	 *
	 * @spec openspec/specs/cms-tool/spec.md
	 */
	private function groupsProperty(string $description): array {
		return ['groups' => ['type' => 'array', 'description' => $description, 'items' => ['type' => 'string']]];
	}//end groupsProperty()

	/**
	 * Execute a function
	 *
	 * @param string $functionName Function name
	 * @param array $parameters Function parameters
	 * @param string|null $userId User ID (optional)
	 *
	 * @return array Execution result
	 *
	 * @throws \Exception If function execution fails
	 *
	 * @spec openspec/specs/cms-tool/spec.md
	 */
	public function executeFunction(string $functionName, array $parameters, ?string $userId = null): array {
		$this->logger->info(
			'[CMSTool] Executing function',
			['function' => $functionName, 'userId' => ($userId ?? $this->currentUserId), 'agentId' => $this->agent?->getId()]
		);

		try {
			return $this->dispatch(functionName: $functionName, parameters: $parameters);
		} catch (\Exception $e) {
			$this->logger->error(
				'[CMSTool] Function execution failed',
				['function' => $functionName, 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]
			);

			return $this->errorResponse(message: $e->getMessage());
		}

	}//end executeFunction()

	/**
	 * Run the action a function name stands for, or answer 404 for an unknown name.
	 *
	 * @param string $functionName Function name
	 * @param array $parameters Function parameters
	 *
	 * @return array Execution result
	 *
	 * @spec openspec/specs/cms-tool/spec.md
	 */
	private function dispatch(string $functionName, array $parameters): array {
		return match ($functionName) {
			'cms_create_page' => $this->createPage(parameters: $parameters),
			'cms_list_pages' => $this->listPages(parameters: $parameters),
			'cms_create_menu' => $this->createMenu(parameters: $parameters),
			'cms_list_menus' => $this->listMenus(),
			'cms_add_menu_item' => $this->addMenuItem(parameters: $parameters),
			default => $this->errorResponse(message: 'Unknown function: ' . $functionName, code: 404)
		};

	}//end dispatch()

	/**
	 * Create a new page
	 *
	 * @param array $parameters Function parameters
	 *
	 * @return array Result
	 *
	 * @spec openspec/specs/cms-tool/spec.md
	 */
	public function createPage(array $parameters): array {
		// Validate required parameters.
		if (empty($parameters['title']) === true) {
			return $this->errorResponse(message: 'Title is required', code: 400);
		}

		$pageData = $this->pageData(parameters: $parameters);

		// ObjectService handles RBAC and validation; the page lives in the publication register.
		$page = $this->objectService->saveObject(
			object: $pageData,
			options: new ObjectSaveOptions(target: new ObjectFindTarget(register: 'publication', schema: 'page'))
		);

		return $this->successResponse(
			message: 'Page created successfully',
			data: ['pageId' => $page->getUuid(), 'title' => $parameters['title'], 'slug' => $pageData['slug']]
		);

	}//end createPage()

	/**
	 * The page object to store, with a slug made from the title when none is given.
	 *
	 * The owner is the user this tool acts for, never a value from the parameters.
	 *
	 * @param array $parameters Function parameters
	 *
	 * @return array<string, mixed> The page object.
	 *
	 * @spec openspec/specs/cms-tool/spec.md
	 */
	private function pageData(array $parameters): array {
		return [
			'title' => $parameters['title'],
			'slug' => ($parameters['slug'] ?? $this->generateSlug(title: $parameters['title'])),
			'summary' => ($parameters['summary'] ?? ''),
			'description' => ($parameters['description'] ?? ''),
			'owner' => $this->currentUserId,
			'organisation' => $this->agent?->getOrganisation(),
		];

	}//end pageData()

	/**
	 * List all pages
	 *
	 * @param array $parameters Function parameters
	 *
	 * @return array Result
	 *
	 * @spec openspec/specs/cms-tool/spec.md
	 */
	public function listPages(array $parameters): array {
		$pages = $this->objectService->findAll(
			config: ['filters' => $this->organisationFilter(), 'limit' => ($parameters['limit'] ?? 50)]
		);

		return $this->successResponse(
			message: 'Pages retrieved successfully',
			data: ['count' => count($pages), 'pages' => array_map(fn (ObjectEntity $page): array => $this->pageRow(page: $page), $pages)]
		);

	}//end listPages()

	/**
	 * One page in a page list: id, title, slug and summary.
	 *
	 * @param ObjectEntity $page The page.
	 *
	 * @return array<string, mixed> The page row.
	 *
	 * @spec openspec/specs/cms-tool/spec.md
	 */
	private function pageRow(ObjectEntity $page): array {
		return [
			'id' => $page->getUuid(),
			'title' => ($page->getObject()['title'] ?? ''),
			'slug' => ($page->getSlug() ?? ''),
			'summary' => ($page->getSummary() ?? ''),
		];

	}//end pageRow()

	/**
	 * The list filter that keeps the agent's organisation's objects.
	 *
	 * @return array<string, string|null> The filters.
	 *
	 * @spec openspec/specs/cms-tool/spec.md
	 */
	private function organisationFilter(): array {
		return ['organisation' => $this->agent?->getOrganisation()];
	}//end organisationFilter()

	/**
	 * Create a new menu with proper schema structure.
	 *
	 * @param array $parameters Function parameters
	 *
	 * @return array Result
	 *
	 * @spec openspec/specs/cms-tool/spec.md
	 */
	public function createMenu(array $parameters): array {
		$rejection = $this->menuRejection(parameters: $parameters);
		if ($rejection !== null) {
			return $this->errorResponse(message: $rejection, code: 400);
		}

		$menuData = $this->menuData(parameters: $parameters);

		$menu = $this->objectService->saveObject(
			object: $menuData,
			options: new ObjectSaveOptions(target: new ObjectFindTarget(register: 'publication', schema: 'menu'))
		);

		return $this->successResponse(
			message: 'Menu created successfully',
			data: [
				'menuId' => $menu->getUuid(),
				'title' => $parameters['title'],
				'position' => $menuData['position'],
				'itemCount' => count($parameters['items']),
			]
		);

	}//end createMenu()

	/**
	 * Why a create-menu call cannot make a menu: no title, no items, or an incomplete item.
	 *
	 * @param array $parameters Function parameters
	 *
	 * @return string|null The error message, or null when the menu can be made.
	 *
	 * @spec openspec/specs/cms-tool/spec.md
	 */
	private function menuRejection(array $parameters): ?string {
		if (empty($parameters['title']) === true) {
			return 'Menu title is required';
		}

		return $this->menuItemsRejection(items: ($parameters['items'] ?? null));
	}//end menuRejection()

	/**
	 * Why a menu's items cannot make a menu: none given, not a list, or one incomplete.
	 *
	 * @param mixed $items The items parameter.
	 *
	 * @return string|null The error message, or null when every item is complete.
	 *
	 * @spec openspec/specs/cms-tool/spec.md
	 */
	private function menuItemsRejection(mixed $items): ?string {
		if (empty($items) === true || is_array($items) === false) {
			return 'Menu must have at least one item. Please provide an items array.';
		}

		return $this->firstIncompleteItem(items: $items);
	}//end menuItemsRejection()

	/**
	 * Why the first incomplete menu item is incomplete, or null when every item is complete.
	 *
	 * @param array $items The menu items.
	 *
	 * @return string|null The error message, or null.
	 *
	 * @spec openspec/specs/cms-tool/spec.md
	 */
	private function firstIncompleteItem(array $items): ?string {
		foreach ($items as $index => $item) {
			$rejection = $this->menuItemRejection(index: $index, item: $item);
			if ($rejection !== null) {
				return $rejection;
			}
		}

		return null;
	}//end firstIncompleteItem()

	/**
	 * Why a menu item is incomplete: it lacks `order` (0 counts), `name` or `link`.
	 *
	 * @param int|string $index The item's key in the items list.
	 * @param mixed $item The menu item.
	 *
	 * @return string|null The error message, or null when the item is complete.
	 *
	 * @spec openspec/specs/cms-tool/spec.md
	 */
	private function menuItemRejection(int|string $index, mixed $item): ?string {
		if ($this->lacksOrder(item: $item) === true) {
			return "Menu item {$index} is missing 'order' field";
		}

		foreach (['name', 'link'] as $field) {
			if (empty($item[$field]) === true) {
				return "Menu item {$index} is missing '{$field}' field";
			}
		}

		return null;
	}//end menuItemRejection()

	/**
	 * Whether a menu item has no `order`; an order of 0 counts as given.
	 *
	 * @param mixed $item The menu item.
	 *
	 * @return bool True when the order is missing or empty.
	 *
	 * @spec openspec/specs/cms-tool/spec.md
	 */
	private function lacksOrder(mixed $item): bool {
		// `order` is optional in the caller's payload, so read it defensively:
		// testing `$item['order']` after empty() has already established the key
		// is absent raises an "Undefined array key" warning on a normal input.
		$itemOrder = ($item['order'] ?? null);

		return (empty($itemOrder) === true && $itemOrder !== 0);
	}//end lacksOrder()

	/**
	 * The menu object to store: title, position, items, the acting user as owner, the agent's organisation.
	 *
	 * The owner is the user this tool acts for, never a value from the parameters.
	 *
	 * @param array $parameters Function parameters
	 *
	 * @return array<string, mixed> The menu object.
	 *
	 * @spec openspec/specs/cms-tool/spec.md
	 */
	private function menuData(array $parameters): array {
		$menuData = [
			'title' => $parameters['title'],
			'position' => ($parameters['position'] ?? 0),
			'items' => $parameters['items'],
			'owner' => $this->currentUserId,
			'organisation' => $this->agent?->getOrganisation(),
		];

		if (isset($parameters['groups']) === true && is_array($parameters['groups']) === true) {
			$menuData['groups'] = $parameters['groups'];
		}

		if (isset($parameters['hideBeforeLogin']) === true) {
			$menuData['hideBeforeLogin'] = (bool)$parameters['hideBeforeLogin'];
		}

		return $menuData;
	}//end menuData()

	/**
	 * List all menus
	 *
	 * @return array Result
	 *
	 * @spec openspec/specs/cms-tool/spec.md
	 */
	public function listMenus(): array {
		$menus = $this->objectService->findAll(config: ['filters' => $this->organisationFilter()]);

		return $this->successResponse(
			message: 'Menus retrieved successfully',
			data: ['count' => count($menus), 'menus' => array_map(fn (ObjectEntity $menu): array => $this->menuRow(menu: $menu), $menus)]
		);

	}//end listMenus()

	/**
	 * One menu in a menu list: id, title, position and the number of items.
	 *
	 * @param ObjectEntity $menu The menu.
	 *
	 * @return array<string, mixed> The menu row.
	 *
	 * @spec openspec/specs/cms-tool/spec.md
	 */
	private function menuRow(ObjectEntity $menu): array {
		$object = $menu->getObject();

		return [
			'id' => $menu->getUuid(),
			'title' => ($object['title'] ?? 'Untitled'),
			'position' => ($object['position'] ?? 0),
			'itemCount' => count(($object['items'] ?? [])),
		];

	}//end menuRow()

	/**
	 * Add a menu item to a menu
	 *
	 * @param array $parameters Function parameters
	 *
	 * @return array Result
	 *
	 * @spec openspec/specs/cms-tool/spec.md
	 */
	public function addMenuItem(array $parameters): array {
		$rejection = $this->addItemRejection(parameters: $parameters);
		if ($rejection !== null) {
			return $this->errorResponse(message: $rejection, code: 400);
		}

		$itemData = $this->menuItemData(parameters: $parameters);
		$menuItem = $this->objectService->saveObject(
			object: $itemData,
			options: new ObjectSaveOptions(target: new ObjectFindTarget(register: 'publication', schema: 'menuItem'))
		);

		return $this->successResponse(
			message: 'Menu item added successfully',
			data: ['menuItemId' => $menuItem->getUuid(), 'name' => $menuItem->getName(), 'menuId' => $parameters['menuId']]
		);

	}//end addMenuItem()

	/**
	 * Why an add-menu-item call cannot add an item: no menu, no name, or neither a link nor a page.
	 *
	 * @param array $parameters Function parameters
	 *
	 * @return string|null The error message, or null when the item can be added.
	 *
	 * @spec openspec/specs/cms-tool/spec.md
	 */
	private function addItemRejection(array $parameters): ?string {
		foreach (['menuId' => 'Menu ID is required', 'name' => 'Menu item name is required'] as $field => $message) {
			if (empty($parameters[$field]) === true) {
				return $message;
			}
		}

		if (empty($parameters['link']) === true && empty($parameters['pageId']) === true) {
			return 'Either link or pageId must be provided';
		}

		return null;
	}//end addItemRejection()

	/**
	 * The menu item object to store, owned by the user this tool acts for.
	 *
	 * @param array $parameters Function parameters
	 *
	 * @return array<string, mixed> The menu item object.
	 *
	 * @spec openspec/specs/cms-tool/spec.md
	 */
	private function menuItemData(array $parameters): array {
		return [
			'name' => $parameters['name'],
			'menu' => $parameters['menuId'],
			'link' => $parameters['link'] ?? null,
			'page' => $parameters['pageId'] ?? null,
			'order' => ($parameters['order'] ?? 0),
			'owner' => $this->currentUserId,
			'organisation' => $this->agent?->getOrganisation(),
		];

	}//end menuItemData()

	/**
	 * Generate URL-friendly slug from title
	 *
	 * @param string $title Page title
	 *
	 * @return string Slug
	 *
	 * @spec openspec/specs/cms-tool/spec.md
	 */
	private function generateSlug(string $title): string {
		// Convert to lowercase.
		$slug = strtolower($title);

		// Replace non-alphanumeric characters with hyphens.
		$slug = preg_replace('/[^a-z0-9]+/', '-', $slug);

		// Remove leading/trailing hyphens.
		$slug = trim($slug, '-');

		return $slug;
	}//end generateSlug()

	/**
	 * Resolve a parameter value, handling null and type casting.
	 *
	 * @param \ReflectionParameter $param The reflection parameter.
	 * @param mixed $value The current value.
	 *
	 * @return mixed The resolved value.
	 *
	 * @spec openspec/specs/cms-tool/spec.md
	 */
	private function resolveParameterValue(\ReflectionParameter $param, mixed $value): mixed {
		if ($value === 'null' || $value === null) {
			// Use default value if available, otherwise null.
			if ($param->isDefaultValueAvailable() === true) {
				return $param->getDefaultValue();
			}

			return null;
		}

		if ($param->hasType() === true) {
			return $this->castParameterValue(param: $param, value: $value);
		}

		return $value;
	}//end resolveParameterValue()

	/**
	 * Cast a parameter value based on its reflection type.
	 *
	 * @param \ReflectionParameter $param The reflection parameter.
	 * @param mixed $value The current value.
	 *
	 * @return mixed The cast value.
	 *
	 * @spec openspec/specs/cms-tool/spec.md
	 */
	private function castParameterValue(\ReflectionParameter $param, mixed $value): mixed {
		$type = $param->getType();
		if ($type === null || ($type instanceof \ReflectionNamedType) === false) {
			return $value;
		}

		return match ($type->getName()) {
			'int' => (int)$value,
			'float' => (float)$value,
			'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
			'string' => (string)$value,
			'array' => $this->castToArray(value: $value),
			default => $value,
		};

	}//end castParameterValue()

	/**
	 * Cast a value to array, returning empty array if not already an array.
	 *
	 * @param mixed $value The value to cast.
	 *
	 * @return array The resulting array.
	 *
	 * @spec openspec/specs/cms-tool/spec.md
	 */
	private function castToArray(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		return [];
	}//end castToArray()

	/**
	 * Create error response
	 *
	 * @param string $message Error message
	 * @param integer $code Error code
	 *
	 * @return array Error response
	 */
	private function errorResponse(string $message, int $code = 500): array {
		$this->logger->error('[CMSTool] Error', ['message' => $message, 'code' => $code]);

		return [
			'success' => false,
			'error' => $message,
			'code' => $code,
		];

	}//end errorResponse()

	/**
	 * Create success response
	 *
	 * @param string $message Success message
	 * @param array $data Response data
	 *
	 * @return array Success response
	 */
	private function successResponse(string $message, array $data = []): array {
		return [
			'success' => true,
			'message' => $message,
			'data' => $data,
		];

	}//end successResponse()

	/**
	 * Magic method to support snake_case method calls for LLPhant compatibility
	 *
	 * Automatically converts snake_case method calls to camelCase for PSR compliance.
	 * Also handles type coercion and JSON encoding of results.
	 *
	 * @param string $name Method name (snake_case)
	 * @param array $arguments Method arguments
	 *
	 * @return mixed Method result (JSON encoded if array)
	 *
	 * @throws \BadMethodCallException If the camelCase method doesn't exist
	 *
	 */
	public function __call(string $name, array $arguments) {
		// Function names are cms_* but the methods are not, and they are camelCase.
		$methodName = preg_replace('/^cms_/', '', $name);
		$camelCaseMethod = lcfirst(str_replace('_', '', ucwords($methodName, '_')));

		if (method_exists($this, $camelCaseMethod) === false) {
			throw new BadMethodCallException("Method {$name} (or {$camelCaseMethod}) does not exist");
		}

		$result = $this->$camelCaseMethod(...$this->callArguments(method: new ReflectionMethod($this, $camelCaseMethod), arguments: $arguments));

		// LLPhant expects tool results to be JSON strings, not arrays.
		if (is_array($result) === true) {
			return json_encode($result);
		}

		return $result;
	}//end __call()

	/**
	 * The arguments to call a method with: named arguments as given, positional ones cast to the method's types.
	 *
	 * The LLM sends the text 'null' for an absent value; it becomes the parameter's default.
	 *
	 * @param ReflectionMethod $method The method to call.
	 * @param array $arguments The arguments of the magic call.
	 *
	 * @return array The arguments to spread into the call.
	 *
	 * @spec openspec/specs/cms-tool/spec.md
	 */
	private function callArguments(ReflectionMethod $method, array $arguments): array {
		if (array_keys($arguments) !== range(0, (count($arguments) - 1))) {
			return $arguments;
		}

		$typedArguments = [];
		foreach ($method->getParameters() as $index => $param) {
			$typedArguments[] = $this->resolveParameterValue(param: $param, value: ($arguments[$index] ?? null));
		}

		return $typedArguments;
	}//end callArguments()
}//end class
