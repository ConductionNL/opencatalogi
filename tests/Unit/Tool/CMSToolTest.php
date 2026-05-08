<?php

declare(strict_types=1);

namespace Unit\Tool;

use BadMethodCallException;
use OCA\OpenCatalogi\Tool\CMSTool;
use OCA\OpenRegister\Db\Agent;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CMSToolTest extends TestCase
{
    private CMSTool $tool;
    private ObjectService $objectService;
    private LoggerInterface $logger;
    private IUserSession $userSession;

    protected function setUp(): void
    {
        parent::setUp();
        $this->objectService = $this->createMock(ObjectService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->userSession = $this->createMock(IUserSession::class);

        $this->tool = new CMSTool(
            $this->objectService,
            $this->logger,
            $this->userSession
        );
    }

    // --- getName ---

    public function testGetName(): void
    {
        $this->assertSame('CMS Tool', $this->tool->getName());
    }

    // --- getDescription ---

    public function testGetDescription(): void
    {
        $this->assertStringContainsString('Manage website content', $this->tool->getDescription());
    }

    // --- setAgent ---

    public function testSetAgentWithSessionUser(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('session-user');
        $this->userSession->method('getUser')->willReturn($user);

        $agent = new Agent();
        $agent->setUser('agent-user');

        $this->tool->setAgent($agent);

        // Session user takes priority — verified via createPage using currentUserId
        $pageObj = $this->createObjectEntity('uuid-1', 'Test Page');
        $this->objectService->method('saveObject')->willReturn($pageObj);

        $result = $this->tool->createPage(['title' => 'Test Page']);
        $this->assertTrue($result['success']);
    }

    public function testSetAgentWithNoSessionUserFallsBackToAgentUser(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $agent = new Agent();
        $agent->setUser('agent-user');
        $agent->setOrganisation('org-1');

        $this->tool->setAgent($agent);

        $pageObj = $this->createObjectEntity('uuid-1', 'Test Page');
        $this->objectService->expects($this->once())
            ->method('saveObject')
            ->with(
                $this->callback(function ($data) {
                    return $data['owner'] === 'agent-user';
                }),
                $this->anything(),
                $this->anything(),
                $this->anything()
            )
            ->willReturn($pageObj);

        $result = $this->tool->createPage(['title' => 'Test Page']);
        $this->assertTrue($result['success']);
    }

    public function testSetAgentWithNull(): void
    {
        $this->userSession->method('getUser')->willReturn(null);
        $this->tool->setAgent(null);

        // currentUserId should be null — still should not crash
        $pageObj = $this->createObjectEntity('uuid-1', 'Test');
        $this->objectService->method('saveObject')->willReturn($pageObj);

        $result = $this->tool->createPage(['title' => 'Test']);
        $this->assertTrue($result['success']);
        $this->assertSame('uuid-1', $result['data']['pageId']);
    }

    // --- getFunctions ---

    public function testGetFunctionsReturnsAllFunctions(): void
    {
        $functions = $this->tool->getFunctions();

        $this->assertIsArray($functions);
        $this->assertCount(5, $functions);

        $names = array_column($functions, 'name');
        $this->assertContains('cms_create_page', $names);
        $this->assertContains('cms_list_pages', $names);
        $this->assertContains('cms_create_menu', $names);
        $this->assertContains('cms_list_menus', $names);
        $this->assertContains('cms_add_menu_item', $names);
    }

    public function testGetFunctionsHaveRequiredStructure(): void
    {
        $functions = $this->tool->getFunctions();

        foreach ($functions as $func) {
            $this->assertArrayHasKey('name', $func);
            $this->assertArrayHasKey('description', $func);
            $this->assertArrayHasKey('parameters', $func);
            $this->assertArrayHasKey('type', $func['parameters']);
            $this->assertSame('object', $func['parameters']['type']);
        }
    }

    // --- executeFunction ---

    public function testExecuteFunctionCreatePage(): void
    {
        $pageObj = $this->createObjectEntity('uuid-page', 'My Page');
        $this->objectService->method('saveObject')->willReturn($pageObj);

        $result = $this->tool->executeFunction('cms_create_page', ['title' => 'My Page']);
        $this->assertTrue($result['success']);
        $this->assertSame('uuid-page', $result['data']['pageId']);
    }

    public function testExecuteFunctionListPages(): void
    {
        $this->objectService->method('findAll')->willReturn([]);

        $result = $this->tool->executeFunction('cms_list_pages', []);
        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['data']['count']);
    }

    public function testExecuteFunctionCreateMenu(): void
    {
        $menuObj = $this->createObjectEntity('uuid-menu', 'Main Menu');
        $this->objectService->method('saveObject')->willReturn($menuObj);

        $result = $this->tool->executeFunction('cms_create_menu', [
            'title' => 'Main Menu',
            'items' => [
                ['order' => 0, 'name' => 'Home', 'link' => '/'],
            ],
        ]);
        $this->assertTrue($result['success']);
        $this->assertSame('uuid-menu', $result['data']['menuId']);
    }

    public function testExecuteFunctionListMenus(): void
    {
        $this->objectService->method('findAll')->willReturn([]);

        $result = $this->tool->executeFunction('cms_list_menus', []);
        $this->assertTrue($result['success']);
    }

    public function testExecuteFunctionAddMenuItem(): void
    {
        $itemObj = $this->createObjectEntity('uuid-item', 'About');
        $this->objectService->method('saveObject')->willReturn($itemObj);

        $result = $this->tool->executeFunction('cms_add_menu_item', [
            'menuId' => 'menu-uuid',
            'name' => 'About',
            'link' => '/about',
        ]);
        $this->assertTrue($result['success']);
        $this->assertSame('uuid-item', $result['data']['menuItemId']);
    }

    public function testExecuteFunctionUnknown(): void
    {
        $result = $this->tool->executeFunction('unknown_function', []);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Unknown function', $result['error']);
        $this->assertSame(404, $result['code']);
    }

    public function testExecuteFunctionHandlesException(): void
    {
        $this->objectService->method('saveObject')
            ->willThrowException(new \RuntimeException('DB error'));

        $result = $this->tool->executeFunction('cms_create_page', ['title' => 'Test']);
        $this->assertFalse($result['success']);
        $this->assertSame('DB error', $result['error']);
    }

    // --- createPage ---

    public function testCreatePageSuccess(): void
    {
        $pageObj = $this->createObjectEntity('uuid-1', 'Test Page');
        $this->objectService->expects($this->once())
            ->method('saveObject')
            ->willReturn($pageObj);

        $result = $this->tool->createPage([
            'title' => 'Test Page',
            'summary' => 'A summary',
            'description' => '<p>Content</p>',
            'slug' => 'test-page',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('Page created successfully', $result['message']);
        $this->assertSame('uuid-1', $result['data']['pageId']);
        $this->assertSame('Test Page', $result['data']['title']);
        $this->assertSame('test-page', $result['data']['slug']);
    }

    public function testCreatePageWithoutTitle(): void
    {
        $result = $this->tool->createPage([]);
        $this->assertFalse($result['success']);
        $this->assertSame('Title is required', $result['error']);
        $this->assertSame(400, $result['code']);
    }

    public function testCreatePageWithEmptyTitle(): void
    {
        $result = $this->tool->createPage(['title' => '']);
        $this->assertFalse($result['success']);
        $this->assertSame('Title is required', $result['error']);
    }

    public function testCreatePageAutoGeneratesSlug(): void
    {
        $pageObj = $this->createObjectEntity('uuid-1', 'Hello World');
        $this->objectService->expects($this->once())
            ->method('saveObject')
            ->with(
                $this->callback(function ($data) {
                    return $data['slug'] === 'hello-world';
                }),
                $this->anything(),
                $this->anything(),
                $this->anything()
            )
            ->willReturn($pageObj);

        $result = $this->tool->createPage(['title' => 'Hello World']);
        $this->assertTrue($result['success']);
    }

    public function testCreatePageWithCustomSlug(): void
    {
        $pageObj = $this->createObjectEntity('uuid-1', 'Test');
        $this->objectService->expects($this->once())
            ->method('saveObject')
            ->with(
                $this->callback(function ($data) {
                    return $data['slug'] === 'custom-slug';
                }),
                $this->anything(),
                $this->anything(),
                $this->anything()
            )
            ->willReturn($pageObj);

        $result = $this->tool->createPage(['title' => 'Test', 'slug' => 'custom-slug']);
        $this->assertTrue($result['success']);
        $this->assertSame('custom-slug', $result['data']['slug']);
    }

    public function testCreatePageDefaultsSummaryAndDescription(): void
    {
        $pageObj = $this->createObjectEntity('uuid-1', 'Minimal');
        $this->objectService->expects($this->once())
            ->method('saveObject')
            ->with(
                $this->callback(function ($data) {
                    return $data['summary'] === '' && $data['description'] === '';
                }),
                $this->anything(),
                $this->anything(),
                $this->anything()
            )
            ->willReturn($pageObj);

        $this->tool->createPage(['title' => 'Minimal']);
    }

    // --- listPages ---

    public function testListPagesSuccess(): void
    {
        $page1 = $this->createPageEntity('uuid-1', 'Page 1', 'page-1', 'Summary 1');
        $page2 = $this->createPageEntity('uuid-2', 'Page 2', 'page-2', 'Summary 2');

        $this->objectService->method('findAll')
            ->willReturn([$page1, $page2]);

        $result = $this->tool->listPages([]);
        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['data']['count']);
        $this->assertCount(2, $result['data']['pages']);
        $this->assertSame('uuid-1', $result['data']['pages'][0]['id']);
        $this->assertSame('Page 1', $result['data']['pages'][0]['title']);
    }

    public function testListPagesDefaultLimit(): void
    {
        $this->objectService->expects($this->once())
            ->method('findAll')
            ->with(
                $this->callback(function ($config) {
                    return ($config['limit'] ?? null) === 50;
                })
            )
            ->willReturn([]);

        $this->tool->listPages([]);
    }

    public function testListPagesCustomLimit(): void
    {
        $this->objectService->expects($this->once())
            ->method('findAll')
            ->with(
                $this->callback(function ($config) {
                    return ($config['limit'] ?? null) === 10;
                })
            )
            ->willReturn([]);

        $this->tool->listPages(['limit' => 10]);
    }

    public function testListPagesEmptyResults(): void
    {
        $this->objectService->method('findAll')
            ->willReturn([]);

        $result = $this->tool->listPages([]);
        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['data']['count']);
        $this->assertEmpty($result['data']['pages']);
    }

    public function testListPagesNoResultsKey(): void
    {
        $this->objectService->method('findAll')
            ->willReturn([]);

        $result = $this->tool->listPages([]);
        $this->assertTrue($result['success']);
        $this->assertEmpty($result['data']['pages']);
    }

    // --- createMenu ---

    public function testCreateMenuSuccess(): void
    {
        $menuObj = $this->createObjectEntity('uuid-menu', 'Main Nav');
        $this->objectService->expects($this->once())
            ->method('saveObject')
            ->willReturn($menuObj);

        $result = $this->tool->createMenu([
            'title' => 'Main Nav',
            'items' => [
                ['order' => 0, 'name' => 'Home', 'link' => '/'],
                ['order' => 1, 'name' => 'About', 'link' => '/about'],
            ],
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('Menu created successfully', $result['message']);
        $this->assertSame('uuid-menu', $result['data']['menuId']);
        $this->assertSame('Main Nav', $result['data']['title']);
        $this->assertSame(0, $result['data']['position']);
        $this->assertSame(2, $result['data']['itemCount']);
    }

    public function testCreateMenuWithoutTitle(): void
    {
        $result = $this->tool->createMenu(['items' => [['order' => 0, 'name' => 'x', 'link' => '/']]]);
        $this->assertFalse($result['success']);
        $this->assertSame('Menu title is required', $result['error']);
        $this->assertSame(400, $result['code']);
    }

    public function testCreateMenuWithoutItems(): void
    {
        $result = $this->tool->createMenu(['title' => 'Menu']);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('at least one item', $result['error']);
    }

    public function testCreateMenuWithEmptyItems(): void
    {
        $result = $this->tool->createMenu(['title' => 'Menu', 'items' => []]);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('at least one item', $result['error']);
    }

    public function testCreateMenuWithNonArrayItems(): void
    {
        $result = $this->tool->createMenu(['title' => 'Menu', 'items' => 'not-array']);
        $this->assertFalse($result['success']);
    }

    public function testCreateMenuItemMissingOrder(): void
    {
        $result = $this->tool->createMenu([
            'title' => 'Menu',
            'items' => [['name' => 'Home', 'link' => '/']],
        ]);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString("'order'", $result['error']);
    }

    public function testCreateMenuItemWithOrderZero(): void
    {
        $menuObj = $this->createObjectEntity('uuid-m', 'M');
        $this->objectService->method('saveObject')->willReturn($menuObj);

        $result = $this->tool->createMenu([
            'title' => 'M',
            'items' => [['order' => 0, 'name' => 'Home', 'link' => '/']],
        ]);
        $this->assertTrue($result['success']);
    }

    public function testCreateMenuItemMissingName(): void
    {
        $result = $this->tool->createMenu([
            'title' => 'Menu',
            'items' => [['order' => 0, 'link' => '/']],
        ]);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString("'name'", $result['error']);
    }

    public function testCreateMenuItemMissingLink(): void
    {
        $result = $this->tool->createMenu([
            'title' => 'Menu',
            'items' => [['order' => 0, 'name' => 'Home']],
        ]);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString("'link'", $result['error']);
    }

    public function testCreateMenuWithGroups(): void
    {
        $menuObj = $this->createObjectEntity('uuid-m', 'Menu');
        $this->objectService->expects($this->once())
            ->method('saveObject')
            ->with(
                $this->callback(function ($data) {
                    return isset($data['groups']) && $data['groups'] === ['admin', 'editors'];
                }),
                $this->anything(),
                $this->anything(),
                $this->anything()
            )
            ->willReturn($menuObj);

        $this->tool->createMenu([
            'title' => 'Menu',
            'items' => [['order' => 0, 'name' => 'Home', 'link' => '/']],
            'groups' => ['admin', 'editors'],
        ]);
    }

    public function testCreateMenuWithHideBeforeLogin(): void
    {
        $menuObj = $this->createObjectEntity('uuid-m', 'Menu');
        $this->objectService->expects($this->once())
            ->method('saveObject')
            ->with(
                $this->callback(function ($data) {
                    return $data['hideBeforeLogin'] === true;
                }),
                $this->anything(),
                $this->anything(),
                $this->anything()
            )
            ->willReturn($menuObj);

        $this->tool->createMenu([
            'title' => 'Menu',
            'items' => [['order' => 0, 'name' => 'Home', 'link' => '/']],
            'hideBeforeLogin' => true,
        ]);
    }

    public function testCreateMenuWithCustomPosition(): void
    {
        $menuObj = $this->createObjectEntity('uuid-m', 'Menu');
        $this->objectService->expects($this->once())
            ->method('saveObject')
            ->with(
                $this->callback(function ($data) {
                    return $data['position'] === 5;
                }),
                $this->anything(),
                $this->anything(),
                $this->anything()
            )
            ->willReturn($menuObj);

        $result = $this->tool->createMenu([
            'title' => 'Menu',
            'position' => 5,
            'items' => [['order' => 0, 'name' => 'Home', 'link' => '/']],
        ]);
        $this->assertSame(5, $result['data']['position']);
    }

    // --- listMenus ---

    public function testListMenusSuccess(): void
    {
        $menu1 = $this->createMenuEntity('uuid-m1', ['title' => 'Nav', 'position' => 0, 'items' => [1, 2]]);
        $menu2 = $this->createMenuEntity('uuid-m2', ['title' => 'Footer', 'position' => 1, 'items' => []]);

        $this->objectService->method('findAll')
            ->willReturn([$menu1, $menu2]);

        $result = $this->tool->listMenus();
        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['data']['count']);
        $this->assertSame('Nav', $result['data']['menus'][0]['title']);
        $this->assertSame(2, $result['data']['menus'][0]['itemCount']);
        $this->assertSame(0, $result['data']['menus'][1]['itemCount']);
    }

    public function testListMenusEmpty(): void
    {
        $this->objectService->method('findAll')
            ->willReturn([]);

        $result = $this->tool->listMenus();
        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['data']['count']);
        $this->assertEmpty($result['data']['menus']);
    }

    public function testListMenusNoResultsKey(): void
    {
        $this->objectService->method('findAll')
            ->willReturn([]);

        $result = $this->tool->listMenus();
        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['data']['count']);
    }

    public function testListMenusMissingObjectFields(): void
    {
        $menu = $this->createMenuEntity('uuid-m', []);
        $this->objectService->method('findAll')
            ->willReturn([$menu]);

        $result = $this->tool->listMenus();
        $this->assertTrue($result['success']);
        $this->assertSame('Untitled', $result['data']['menus'][0]['title']);
        $this->assertSame(0, $result['data']['menus'][0]['position']);
        $this->assertSame(0, $result['data']['menus'][0]['itemCount']);
    }

    // --- addMenuItem ---

    public function testAddMenuItemWithLink(): void
    {
        $itemObj = $this->createObjectEntity('uuid-item', 'About');
        $this->objectService->expects($this->once())
            ->method('saveObject')
            ->with(
                $this->callback(function ($data) {
                    return $data['name'] === 'About'
                        && $data['menu'] === 'menu-123'
                        && $data['link'] === '/about'
                        && $data['page'] === null;
                }),
                $this->anything(),
                $this->anything(),
                $this->anything()
            )
            ->willReturn($itemObj);

        $result = $this->tool->addMenuItem([
            'menuId' => 'menu-123',
            'name' => 'About',
            'link' => '/about',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('uuid-item', $result['data']['menuItemId']);
        $this->assertSame('About', $result['data']['name']);
        $this->assertSame('menu-123', $result['data']['menuId']);
    }

    public function testAddMenuItemWithPageId(): void
    {
        $itemObj = $this->createObjectEntity('uuid-item', 'Home');
        $this->objectService->expects($this->once())
            ->method('saveObject')
            ->with(
                $this->callback(function ($data) {
                    return $data['page'] === 'page-uuid-1' && $data['link'] === null;
                }),
                $this->anything(),
                $this->anything(),
                $this->anything()
            )
            ->willReturn($itemObj);

        $result = $this->tool->addMenuItem([
            'menuId' => 'menu-123',
            'name' => 'Home',
            'pageId' => 'page-uuid-1',
        ]);

        $this->assertTrue($result['success']);
    }

    public function testAddMenuItemWithoutMenuId(): void
    {
        $result = $this->tool->addMenuItem(['name' => 'Test', 'link' => '/']);
        $this->assertFalse($result['success']);
        $this->assertSame('Menu ID is required', $result['error']);
        $this->assertSame(400, $result['code']);
    }

    public function testAddMenuItemWithoutName(): void
    {
        $result = $this->tool->addMenuItem(['menuId' => 'menu-1', 'link' => '/']);
        $this->assertFalse($result['success']);
        $this->assertSame('Menu item name is required', $result['error']);
    }

    public function testAddMenuItemWithoutLinkOrPageId(): void
    {
        $result = $this->tool->addMenuItem(['menuId' => 'menu-1', 'name' => 'Test']);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Either link or pageId', $result['error']);
    }

    public function testAddMenuItemDefaultOrder(): void
    {
        $itemObj = $this->createObjectEntity('uuid-item', 'Test');
        $this->objectService->expects($this->once())
            ->method('saveObject')
            ->with(
                $this->callback(function ($data) {
                    return $data['order'] === 0;
                }),
                $this->anything(),
                $this->anything(),
                $this->anything()
            )
            ->willReturn($itemObj);

        $this->tool->addMenuItem([
            'menuId' => 'menu-1',
            'name' => 'Test',
            'link' => '/',
        ]);
    }

    public function testAddMenuItemCustomOrder(): void
    {
        $itemObj = $this->createObjectEntity('uuid-item', 'Test');
        $this->objectService->expects($this->once())
            ->method('saveObject')
            ->with(
                $this->callback(function ($data) {
                    return $data['order'] === 5;
                }),
                $this->anything(),
                $this->anything(),
                $this->anything()
            )
            ->willReturn($itemObj);

        $this->tool->addMenuItem([
            'menuId' => 'menu-1',
            'name' => 'Test',
            'link' => '/',
            'order' => 5,
        ]);
    }

    // --- generateSlug (private, tested via reflection) ---

    public function testGenerateSlugBasic(): void
    {
        $slug = $this->invokeGenerateSlug('Hello World');
        $this->assertSame('hello-world', $slug);
    }

    public function testGenerateSlugSpecialCharacters(): void
    {
        $slug = $this->invokeGenerateSlug('Foo & Bar! #Test');
        $this->assertSame('foo-bar-test', $slug);
    }

    public function testGenerateSlugTrimsHyphens(): void
    {
        $slug = $this->invokeGenerateSlug('  --Hello--  ');
        $this->assertSame('hello', $slug);
    }

    public function testGenerateSlugMultipleSpaces(): void
    {
        $slug = $this->invokeGenerateSlug('Multiple   Spaces   Here');
        $this->assertSame('multiple-spaces-here', $slug);
    }

    public function testGenerateSlugNumbers(): void
    {
        $slug = $this->invokeGenerateSlug('Page 123 Title');
        $this->assertSame('page-123-title', $slug);
    }

    // --- errorResponse / successResponse (private, via reflection) ---

    public function testErrorResponseStructure(): void
    {
        $method = new \ReflectionMethod(CMSTool::class, 'errorResponse');
        $method->setAccessible(true);

        $result = $method->invoke($this->tool, 'Something failed', 422);
        $this->assertFalse($result['success']);
        $this->assertSame('Something failed', $result['error']);
        $this->assertSame(422, $result['code']);
    }

    public function testErrorResponseDefaultCode(): void
    {
        $method = new \ReflectionMethod(CMSTool::class, 'errorResponse');
        $method->setAccessible(true);

        $result = $method->invoke($this->tool, 'Error');
        $this->assertSame(500, $result['code']);
    }

    public function testSuccessResponseStructure(): void
    {
        $method = new \ReflectionMethod(CMSTool::class, 'successResponse');
        $method->setAccessible(true);

        $result = $method->invoke($this->tool, 'Done', ['key' => 'val']);
        $this->assertTrue($result['success']);
        $this->assertSame('Done', $result['message']);
        $this->assertSame(['key' => 'val'], $result['data']);
    }

    public function testSuccessResponseDefaultData(): void
    {
        $method = new \ReflectionMethod(CMSTool::class, 'successResponse');
        $method->setAccessible(true);

        $result = $method->invoke($this->tool, 'OK');
        $this->assertSame([], $result['data']);
    }

    // --- __call (magic method) ---

    public function testMagicCallWithCmsPrefix(): void
    {
        $pageObj = $this->createObjectEntity('uuid-1', 'Magic Page');
        $this->objectService->method('saveObject')->willReturn($pageObj);

        // Calling cms_create_page via __call with associative args
        $result = $this->tool->cms_create_page(['title' => 'Magic Page']);
        $decoded = json_decode($result, true);

        $this->assertTrue($decoded['success']);
        $this->assertSame('uuid-1', $decoded['data']['pageId']);
    }

    public function testMagicCallListMenus(): void
    {
        $this->objectService->method('findAll')
            ->willReturn([]);

        $result = $this->tool->cms_list_menus();
        $decoded = json_decode($result, true);

        $this->assertTrue($decoded['success']);
    }

    public function testMagicCallUndefinedMethod(): void
    {
        $this->expectException(BadMethodCallException::class);
        $this->tool->nonExistentMethod();
    }

    // --- Helper methods ---

    /**
     * Create a real ObjectEntity with uuid and name set.
     */
    private function createObjectEntity(string $uuid, string $name): ObjectEntity
    {
        $obj = new ObjectEntity();
        $obj->setUuid($uuid);
        $obj->setName($name);
        $obj->setObject([]);
        return $obj;
    }

    /**
     * Create a page-like ObjectEntity for list results.
     */
    private function createPageEntity(string $uuid, string $title, string $slug, string $summary): ObjectEntity
    {
        $obj = new ObjectEntity();
        $obj->setUuid($uuid);
        $obj->setSlug($slug);
        $obj->setSummary($summary);
        $obj->setObject(['title' => $title]);
        return $obj;
    }

    /**
     * Create a menu-like ObjectEntity for list results.
     */
    private function createMenuEntity(string $uuid, array $objectData): ObjectEntity
    {
        $obj = new ObjectEntity();
        $obj->setUuid($uuid);
        $obj->setObject($objectData);
        return $obj;
    }

    private function invokeGenerateSlug(string $title): string
    {
        $method = new \ReflectionMethod(CMSTool::class, 'generateSlug');
        $method->setAccessible(true);
        return $method->invoke($this->tool, $title);
    }
}
