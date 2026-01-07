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
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 */

namespace OCA\OpenCatalogi\Tool;

use OCA\OpenRegister\Tool\ToolInterface;
use OCA\OpenRegister\Db\Agent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

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
 */
class CMSTool implements ToolInterface
{

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
     * @param ObjectService   $objectService Object service
     * @param LoggerInterface $logger        Logger
     * @param IUserSession    $userSession   User session
     */
    public function __construct(
        ObjectService $objectService,
        LoggerInterface $logger,
        IUserSession $userSession
    ) {
        $this->objectService = $objectService;
        $this->logger        = $logger;
        $this->userSession   = $userSession;

    }//end __construct()


    /**
     * Get tool name
     *
     * @return string Tool name
     */
    public function getName(): string
    {
        return 'CMS Tool';

    }//end getName()


    /**
     * Get tool description
     *
     * @return string Tool description
     */
    public function getDescription(): string
    {
        return 'Manage website content: create and manage pages, menus, and menu items for OpenCatalogi';

    }//end getDescription()


    /**
     * Set agent context
     *
     * @param Agent|null $agent Agent entity
     *
     * @return void
     */
    public function setAgent(?Agent $agent): void
    {
        $this->agent = $agent;

        // Determine user ID for operations
        // Prioritize session user, fallback to agent's configured user
        $this->currentUserId = $this->userSession->getUser() ? $this->userSession->getUser()->getUID() : ($agent ? $agent->getUser() : null);

    }//end setAgent()


    /**
     * Get function definitions
     *
     * Returns OpenAI-compatible function definitions for the LLM.
     *
     * @return array<array<string, mixed>> Function definitions
     */
    public function getFunctions(): array
    {
        return [
            // Page functions
            [
                'name'        => 'cms_create_page',
                'description' => 'Create a new page with title and content. Returns the page UUID.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'title'       => [
                            'type'        => 'string',
                            'description' => 'Page title (required)',
                        ],
                        'summary'     => [
                            'type'        => 'string',
                            'description' => 'Brief summary of the page',
                        ],
                        'description' => [
                            'type'        => 'string',
                            'description' => 'Full page content in HTML or markdown',
                        ],
                        'slug'        => [
                            'type'        => 'string',
                            'description' => 'URL-friendly slug (auto-generated if not provided)',
                        ],
                    ],
                    'required'   => ['title'],
                ],
            ],
            [
                'name'        => 'cms_list_pages',
                'description' => 'List all pages. Returns array of pages with title, slug, and UUID.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'limit' => [
                            'type'        => 'integer',
                            'description' => 'Maximum number of pages to return (default: 50)',
                        ],
                    ],
                    'required'   => [],
                ],
            ],

            // Menu functions
            [
                'name'        => 'cms_create_menu',
                'description' => 'Create a new menu with items. Ask the user for menu position, menu items (with names, links, order), and access groups if not provided. Each menu MUST have at least one item.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'title'           => [
                            'type'        => 'string',
                            'description' => 'Menu title/name (required)',
                        ],
                        'position'        => [
                            'type'        => 'number',
                            'description' => 'Menu display position/order (0 = first, higher = later). ASK THE USER for this if not provided.',
                        ],
                        'items'           => [
                            'type'        => 'array',
                            'description' => 'Array of menu items. Each item MUST have: order (number), name (string), link (string). ASK THE USER what items they want.',
                            'items'       => [
                                'type'       => 'object',
                                'properties' => [
                                    'order'       => [
                                        'type'        => 'number',
                                        'description' => 'Item display order within menu',
                                    ],
                                    'name'        => [
                                        'type'        => 'string',
                                        'description' => 'Display name of the menu item',
                                    ],
                                    'link'        => [
                                        'type'        => 'string',
                                        'description' => 'URL or path (e.g., /page-slug or https://example.com)',
                                    ],
                                    'description' => [
                                        'type'        => 'string',
                                        'description' => 'Optional item description',
                                    ],
                                    'icon'        => [
                                        'type'        => 'string',
                                        'description' => 'Optional icon name',
                                    ],
                                    'groups'      => [
                                        'type'        => 'array',
                                        'description' => 'Nextcloud groups that can access this item (RBAC)',
                                        'items'       => ['type' => 'string'],
                                    ],
                                ],
                                'required'   => [
                                    'order',
                                    'name',
                                    'link',
                                ],
                            ],
                        ],
                        'groups'          => [
                            'type'        => 'array',
                            'description' => 'Nextcloud groups that can access this entire menu (RBAC). Ask user if they want to restrict access.',
                            'items'       => ['type' => 'string'],
                        ],
                        'hideBeforeLogin' => [
                            'type'        => 'boolean',
                            'description' => 'Whether to hide this menu before user login (security setting)',
                        ],
                    ],
                    'required'   => [
                        'title',
                        'items',
                    ],
                ],
            ],
            [
                'name'        => 'cms_list_menus',
                'description' => 'List all menus. Returns array of menus with name and UUID.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [],
                    'required'   => [],
                ],
            ],

            // Menu item functions
            [
                'name'        => 'cms_add_menu_item',
                'description' => 'Add an item to a menu. Can link to a page or external URL.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'menuId' => [
                            'type'        => 'string',
                            'description' => 'UUID of the menu to add item to (required)',
                        ],
                        'name'   => [
                            'type'        => 'string',
                            'description' => 'Label for the menu item (required)',
                        ],
                        'link'   => [
                            'type'        => 'string',
                            'description' => 'External URL to link to (provide either link or pageId)',
                        ],
                        'pageId' => [
                            'type'        => 'string',
                            'description' => 'UUID of a page to link to (provide either link or pageId)',
                        ],
                        'order'  => [
                            'type'        => 'integer',
                            'description' => 'Display order in the menu',
                        ],
                    ],
                    'required'   => [
                        'menuId',
                        'name',
                    ],
                ],
            ],
        ];

    }//end getFunctions()


    /**
     * Execute a function
     *
     * @param string      $functionName Function name
     * @param array       $parameters   Function parameters
     * @param string|null $userId       User ID (optional)
     *
     * @return array Execution result
     *
     * @throws \Exception If function execution fails
     */
    public function executeFunction(string $functionName, array $parameters, ?string $userId = null): array
    {
        $this->logger->info(
            '[CMSTool] Executing function',
            [
                'function' => $functionName,
                'userId'   => ($userId ?? $this->currentUserId),
                'agentId'  => $this->agent?->getId(),
            ]
        );

        try {
            return match ($functionName) {
                'cms_create_page' => $this->createPage($parameters),
                'cms_list_pages' => $this->listPages($parameters),
                'cms_create_menu' => $this->createMenu($parameters),
                'cms_list_menus' => $this->listMenus(),
                'cms_add_menu_item' => $this->addMenuItem($parameters),
                default => $this->errorResponse('Unknown function: '.$functionName, 404)
            };
        } catch (\Exception $e) {
            $this->logger->error(
                '[CMSTool] Function execution failed',
                [
                    'function' => $functionName,
                    'error'    => $e->getMessage(),
                    'trace'    => $e->getTraceAsString(),
                ]
            );

            return $this->errorResponse($e->getMessage());
        }//end try

    }//end executeFunction()


    /**
     * Create a new page
     *
     * @param array $parameters Function parameters
     *
     * @return array Result
     */
    public function createPage(array $parameters): array
    {
        // Validate required parameters
        if (empty($parameters['title'])) {
            return $this->errorResponse('Title is required', 400);
        }

        // Generate slug if not provided
        $slug = ($parameters['slug'] ?? $this->generateSlug($parameters['title']));

        // Create page object
        $pageData = [
            'title'        => $parameters['title'],
            'slug'         => $slug,
            'summary'      => ($parameters['summary'] ?? ''),
            'description'  => ($parameters['description'] ?? ''),
            'owner'        => $this->currentUserId,
            'organisation' => $this->agent?->getOrganisation(),
        ];

        // Use ObjectService to create page (it handles RBAC and validation)
        // Using the publication register from OpenCatalogi configuration
        // Use positional parameters for compatibility with different ObjectService versions
        $page = $this->objectService->saveObject($pageData, [], 'publication', 'page');

        return $this->successResponse(
            'Page created successfully',
            [
                'pageId' => $page->getUuid(),
                'title'  => $page->getTitle(),
                'slug'   => $slug,
            ]
        );

    }//end createPage()


    /**
     * List all pages
     *
     * @param array $parameters Function parameters
     *
     * @return array Result
     */
    public function listPages(array $parameters): array
    {
        $limit = ($parameters['limit'] ?? 50);

        // Get pages from ObjectService
        $filters = [
            'organisation' => $this->agent?->getOrganisation(),
        ];

        $pages = $this->objectService->findObjects(
            filters: $filters,
            limit: $limit,
            schema: 'page'
            // Schema name without register prefix
        );

        return $this->successResponse(
            'Pages retrieved successfully',
            [
                'count' => count($pages),
                'pages' => array_map(
                    function ($page) {
                        return [
                            'id'      => $page->getUuid(),
                            'title'   => $page->getTitle(),
                            'slug'    => ($page->getSlug() ?? ''),
                            'summary' => ($page->getSummary() ?? ''),
                        ];
                    },
                    ($pages['results'] ?? [])
                ),
            ]
        );

    }//end listPages()


    /**
     * Create a new menu with proper schema structure.
     *
     * @param array $parameters Function parameters
     *
     * @return array Result
     */
    public function createMenu(array $parameters): array
    {
        // Validate required parameters.
        if (empty($parameters['title'])) {
            return $this->errorResponse('Menu title is required', 400);
        }

        // Validate that items array is provided and not empty.
        if (empty($parameters['items']) || !is_array($parameters['items'])) {
            return $this->errorResponse('Menu must have at least one item. Please provide an items array.', 400);
        }

        // Validate each menu item has required fields.
        foreach ($parameters['items'] as $index => $item) {
            if (empty($item['order']) && $item['order'] !== 0) {
                return $this->errorResponse("Menu item {$index} is missing 'order' field", 400);
            }

            if (empty($item['name'])) {
                return $this->errorResponse("Menu item {$index} is missing 'name' field", 400);
            }

            if (empty($item['link'])) {
                return $this->errorResponse("Menu item {$index} is missing 'link' field", 400);
            }
        }

        // Create menu object with proper schema fields.
        $menuData = [
            'title'        => $parameters['title'],
            'position'     => ($parameters['position'] ?? 0),
            'items'        => $parameters['items'],
// Array of menu items
            'owner'        => $this->currentUserId,
            'organisation' => $this->agent?->getOrganisation(),
        ];

        // Add optional fields if provided.
        if (isset($parameters['groups']) && is_array($parameters['groups'])) {
            $menuData['groups'] = $parameters['groups'];
        }

        if (isset($parameters['hideBeforeLogin'])) {
            $menuData['hideBeforeLogin'] = (bool) $parameters['hideBeforeLogin'];
        }

        // Use ObjectService to create menu.
        // Use positional parameters for compatibility with different ObjectService versions
        $menu = $this->objectService->saveObject($menuData, [], 'publication', 'menu');

        return $this->successResponse(
            'Menu created successfully',
            [
                'menuId'    => $menu->getUuid(),
                'title'     => $parameters['title'],
                'position'  => $menuData['position'],
                'itemCount' => count($parameters['items']),
            ]
        );

    }//end createMenu()


    /**
     * List all menus
     *
     * @return array Result
     */
    public function listMenus(): array
    {
        // Get menus from ObjectService
        $filters = [
            'organisation' => $this->agent?->getOrganisation(),
        ];

        $menus = $this->objectService->findObjects(
            filters: $filters,
            schema: 'menu'
            // Schema name without register prefix
        );

        return $this->successResponse(
            'Menus retrieved successfully',
            [
                'count' => count(($menus['results'] ?? [])),
                'menus' => array_map(
                    function ($menu) {
                        $object = $menu->getObject();
                        return [
                            'id'        => $menu->getUuid(),
                            'title'     => ($object['title'] ?? 'Untitled'),
                            'position'  => ($object['position'] ?? 0),
                            'itemCount' => count(($object['items'] ?? [])),
                        ];
                    },
                    ($menus['results'] ?? [])
                ),
            ]
        );

    }//end listMenus()


    /**
     * Add a menu item to a menu
     *
     * @param array $parameters Function parameters
     *
     * @return array Result
     */
    public function addMenuItem(array $parameters): array
    {
        // Validate required parameters
        if (empty($parameters['menuId'])) {
            return $this->errorResponse('Menu ID is required', 400);
        }

        if (empty($parameters['name'])) {
            return $this->errorResponse('Menu item name is required', 400);
        }

        // Must provide either link or pageId
        if (empty($parameters['link']) && empty($parameters['pageId'])) {
            return $this->errorResponse('Either link or pageId must be provided', 400);
        }

        // Create menu item object
        $menuItemData = [
            'name'         => $parameters['name'],
            'menu'         => $parameters['menuId'],
            'link'         => $parameters['link'] ?? null,
            'page'         => $parameters['pageId'] ?? null,
            'order'        => ($parameters['order'] ?? 0),
            'owner'        => $this->currentUserId,
            'organisation' => $this->agent?->getOrganisation(),
        ];

        // Use ObjectService to create menu item
        // Use positional parameters for compatibility with different ObjectService versions
        $menuItem = $this->objectService->saveObject($menuItemData, [], 'publication', 'menuItem');

        return $this->successResponse(
            'Menu item added successfully',
            [
                'menuItemId' => $menuItem->getUuid(),
                'name'       => $menuItem->getName(),
                'menuId'     => $parameters['menuId'],
            ]
        );

    }//end addMenuItem()


    /**
     * Generate URL-friendly slug from title
     *
     * @param string $title Page title
     *
     * @return string Slug
     */
    private function generateSlug(string $title): string
    {
        // Convert to lowercase
        $slug = strtolower($title);

        // Replace non-alphanumeric characters with hyphens
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);

        // Remove leading/trailing hyphens
        $slug = trim($slug, '-');

        return $slug;

    }//end generateSlug()


    /**
     * Create error response
     *
     * @param string  $message Error message
     * @param integer $code    Error code
     *
     * @return array Error response
     */
    private function errorResponse(string $message, int $code = 500): array
    {
        $this->logger->error('[CMSTool] Error', ['message' => $message, 'code' => $code]);

        return [
            'success' => false,
            'error'   => $message,
            'code'    => $code,
        ];

    }//end errorResponse()


    /**
     * Create success response
     *
     * @param string $message Success message
     * @param array  $data    Response data
     *
     * @return array Success response
     */
    private function successResponse(string $message, array $data = []): array
    {
        return [
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ];

    }//end successResponse()


    /**
     * Magic method to support snake_case method calls for LLPhant compatibility
     *
     * Automatically converts snake_case method calls to camelCase for PSR compliance.
     * Also handles type coercion and JSON encoding of results.
     *
     * @param string $name      Method name (snake_case)
     * @param array  $arguments Method arguments
     *
     * @return mixed Method result (JSON encoded if array)
     *
     * @throws \BadMethodCallException If the camelCase method doesn't exist
     */
    public function __call(string $name, array $arguments)
    {
        // Strip 'cms_' prefix if present (function names are cms_* but methods are not)
        $methodName = preg_replace('/^cms_/', '', $name);

        // Convert snake_case to camelCase
        $camelCaseMethod = lcfirst(str_replace('_', '', ucwords($methodName, '_')));

        if (method_exists($this, $camelCaseMethod)) {
            // Get method reflection to understand parameter types
            $reflection = new \ReflectionMethod($this, $camelCaseMethod);
            $parameters = $reflection->getParameters();

            // Type-cast arguments based on method signature
            // Handle both positional and named arguments from LLPhant
            $isAssociative = array_keys($arguments) !== range(0, (count($arguments) - 1));

            $typedArguments = [];
            foreach ($parameters as $index => $param) {
                $paramName = $param->getName();

                // Get value from either named argument or positional argument
                if ($isAssociative && isset($arguments[$paramName])) {
                    $value = $arguments[$paramName];
                } else {
                    $value = $arguments[$index] ?? null;
                }

                // Handle string 'null' from LLM
                if ($value === 'null' || $value === null) {
                    // Use default value if available, otherwise null
                    $value = $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null;
                } else if ($param->hasType()) {
                    // Cast to the expected type
                    $type = $param->getType();
                    if ($type && $type instanceof \ReflectionNamedType) {
                        $typeName = $type->getName();
                        if ($typeName === 'int') {
                            $value = (int) $value;
                        } else if ($typeName === 'float') {
                            $value = (float) $value;
                        } else if ($typeName === 'bool') {
                            $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                        } else if ($typeName === 'string') {
                            $value = (string) $value;
                        } else if ($typeName === 'array') {
                            $value = is_array($value) ? $value : [];
                        }
                    }
                }//end if

                $typedArguments[] = $value;
            }//end foreach

            // CMSTool methods expect a single array parameter, not individual args
            // Combine all typed arguments back into a single associative array
            if ($isAssociative) {
                // If original was associative, pass it as-is
                $result = $this->$camelCaseMethod($arguments);
            } else {
                // If original was positional, wrap in array
                $result = $this->$camelCaseMethod($typedArguments);
            }

            // LLPhant expects tool results to be JSON strings, not arrays
            // Convert array results to JSON for LLM consumption
            if (is_array($result)) {
                return json_encode($result);
            }

            return $result;
        }//end if

        throw new \BadMethodCallException("Method {$name} (or {$camelCaseMethod}) does not exist");

    }//end __call()


}//end class
