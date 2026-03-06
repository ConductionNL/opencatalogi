<?php
/**
 * Script to set up menus and pages for local OpenCatalogi environment
 * Based on the accept environment structure
 */

require_once __DIR__ . '/../../openregister/lib/Service/ObjectService.php';
require_once __DIR__ . '/../../openregister/lib/Entity/ObjectEntity.php';

use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Entity\ObjectEntity;
use OCA\OpenRegister\Service\EntityService;

/**
 * Setup menus for local environment
 */
function setupMenus() {
    $objectService = new ObjectService();
    
    // Main Navigation Menu
    $mainMenuData = [
        '@self' => [
            'register' => 'publication',
            'schema' => 'menu',
            'version' => '1.1.1',
            'slug' => 'main-navigation'
        ],
        'title' => 'Main Navigation',
        'name' => 'main-navigation',
        'position' => 1,
        'items' => [
            [
                'name' => 'Home',
                'slug' => 'home',
                'link' => '/',
                'description' => 'Homepage',
                'icon' => 'home',
                'order' => '0'
            ],
            [
                'name' => 'Catalogs',
                'slug' => 'catalogs',
                'link' => '/catalogs',
                'description' => 'Browse available catalogs',
                'icon' => 'catalog',
                'order' => '1'
            ],
            [
                'name' => 'Publications',
                'slug' => 'publications',
                'link' => '/publications',
                'description' => 'Browse all publications',
                'icon' => 'publication',
                'order' => '2'
            ]
        ]
    ];
    
    // Admin Menu
    $adminMenuData = [
        '@self' => [
            'register' => 'publication',
            'schema' => 'menu',
            'version' => '1.1.1',
            'slug' => 'admin-menu'
        ],
        'title' => 'Admin Menu',
        'position' => 1,
        'groups' => ['admin'],
        'items' => [
            [
                'order' => 1,
                'name' => 'Dashboard',
                'link' => '/admin',
                'description' => 'Admin dashboard',
                'icon' => 'dashboard'
            ],
            [
                'order' => 2,
                'name' => 'Users',
                'link' => '/admin/users',
                'description' => 'Manage users',
                'icon' => 'users'
            ],
            [
                'order' => 3,
                'name' => 'Content',
                'link' => '/admin/content',
                'description' => 'Manage content',
                'icon' => 'content'
            ]
        ]
    ];
    
    try {
        // Create main navigation menu
        $result = $objectService->createObject($mainMenuData);
        if ($result['success']) {
            echo "✅ Main navigation menu created successfully\n";
        } else {
            echo "❌ Failed to create main navigation menu: " . $result['message'] . "\n";
        }
        
        // Create admin menu
        $result = $objectService->createObject($adminMenuData);
        if ($result['success']) {
            echo "✅ Admin menu created successfully\n";
        } else {
            echo "❌ Failed to create admin menu: " . $result['message'] . "\n";
        }
        
    } catch (Exception $e) {
        echo "❌ Error creating menus: " . $e->getMessage() . "\n";
    }
}

/**
 * Setup home page for local environment
 */
function setupHomePage() {
    $objectService = new ObjectService();
    
    $homePageData = [
        '@self' => [
            'register' => 'publication',
            'schema' => 'page',
            'version' => '1.0.0',
            'slug' => 'home'
        ],
        'title' => 'Welcome to OpenCatalogi',
        'slug' => 'home',
        'content' => "# Welcome to OpenCatalogi\n\nThis is the homepage of your catalog website. Here you can discover and browse through various catalogs and publications.\n\n## Features\n\n- **Catalogs**: Browse organized collections of items\n- **Publications**: Find and access publications\n- **Search**: Search through content efficiently\n\nGet started by exploring our catalogs!",
        'meta_title' => 'OpenCatalogi - Home',
        'meta_description' => 'Welcome to OpenCatalogi, your platform for discovering catalogs and publications.',
        'published' => true,
        'order' => 1
    ];
    
    try {
        $result = $objectService->createObject($homePageData);
        if ($result['success']) {
            echo "✅ Home page created successfully\n";
        } else {
            echo "❌ Failed to create home page: " . $result['message'] . "\n";
        }
    } catch (Exception $e) {
        echo "❌ Error creating home page: " . $e->getMessage() . "\n";
    }
}

/**
 * Main execution
 */
echo "🏠 Setting up OpenCatalogi Menus and Pages...\n\n";

echo "📋 Creating menus...\n";
setupMenus();

echo "\n📄 Creating home page...\n";
setupHomePage();

echo "\n🎉 Setup complete! Your local OpenCatalogi should now have menus and a home page.\n";
echo "📖 See 'setup-menus-and-pages.md' for configuration details.\n";
