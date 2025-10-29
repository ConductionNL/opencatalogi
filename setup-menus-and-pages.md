# Setting up Menus and Home Page for Local OpenCatalogi

Based on the accept environment structure, here's how to set up menus and pages for your local OpenCatalogi installation:

## 1. Configuration Setup

First, configure OpenCatalogi to know which schema and register to use for menus and pages:

```bash
# Configure menu settings
docker exec -u 33 master-nextcloud-1 php occ config:app:set opencatalogi menu_schema 'menu'
docker exec -u 33 master-nextcloud-1 php occ config:app:set opencatalogi menu_register 'publication'

# Configure page settings  
docker exec -u 33 master-nextcloud-1 php occ config:app:set opencatalogi page_schema 'page'
docker exec -u 33 master-nextcloud-1 php occ config:app:set opencatalogi page_register 'publication'
```

## 2. Create Main Navigation Menu

Create the main navigation menu object through OpenRegister:

```bash
curl -X POST "https://nextcloud.local/apps/openregister/api/objects" \
  -H "Content-Type: application/json" \
  -H "Authorization: Basic $(echo -n 'admin:admin' | base64)" \
  -d '{
    "@self": {
      "register": "publication",
      "schema": "menu",
      "version": "1.1.1",
      "slug": "main-navigation"
    },
    "title": "Main Navigation",
    "name": "main-navigation", 
    "position": 1,
    "items": [
      {
        "name": "Home",
        "slug": "home",
        "link": "/",
        "description": "Homepage",
        "icon": "home",
        "order": "0"
      },
      {
        "name": "Catalogs",
        "slug": "catalogs",
        "link": "/catalogs",
        "description": "Browse available catalogs",
        "icon": "catalog",
        "order": "1"
      },
      {
        "name": "Publications",
        "slug": "publications", 
        "link": "/publications",
        "description": "Browse all publications",
        "icon": "publication",
        "order": "2"
      }
    ]
  }'
```

## 3. Create Admin Navigation Menu

```bash
curl -X POST "https://nextcloud.local/apps/openregister/api/objects" \
  -H "Content-Type: application/json" \
  -H "Authorization: Basic $(echo -n 'admin:admin' | base64)" \
  -d '{
    "@self": {
      "register": "publication", 
      "schema": "menu",
      "version": "1.1.1",
      "slug": "admin-menu"
    },
    "title": "Admin Menu",
    "position": 1,
    "groups": ["admin"],
    "items": [
      {
        "order": 1,
        "name": "Dashboard",
        "link": "/admin",
        "description": "Admin dashboard",
        "icon": "dashboard"
      },
      {
        "order": 2,
        "name": "Users", 
        "link": "/admin/users",
        "description": "Manage users",
        "icon": "users"
      },
      {
        "order": 3,
        "name": "Content",
        "link": "/admin/content", 
        "description": "Manage content",
        "icon": "content"
      }
    ]
  }'
```

## 4. Create Home Page

```bash
curl -X POST "https://nextcloud.local/apps/openregister/api/objects" \
  -H "Content-Type: application/json" \
  -H "Authorization: Basic $(echo -n 'admin:admin' | base64)" \
  -d '{
    "@self": {
      "register": "publication",
      "schema": "page", 
      "version": "1.0.0",
      "slug": "home"
    },
    "title": "Welcome to OpenCatalogi",
    "slug": "home",
    "content": "# Welcome to OpenCatalogi\n\nThis is the homepage of your catalog website. Here you can discover and browse through various catalogs and publications.\n\n## Features\n\n- **Catalogs**: Browse organized collections of items\n- **Publications**: Find and access publications\n- **Search**: Search through content efficiently\n\nGet started by exploring our catalogs!",
    "meta_title": "OpenCatalogi - Home",
    "meta_description": "Welcome to OpenCatalogi, your platform for discovering catalogs and publications.",
    "published": true,
    "order": 1
  }'
```

## 5. Verify Setup

After creating the objects, verify they work:

```bash
# Check menus
curl -s "https://nextcloud.local/apps/opencatalogi/api/menus" \
  -H "Accept: application/json"

# Check pages  
curl -s "https://nextcloud.local/apps/opencatalogi/api/pages" \
  -H "Accept: application/json"

# Check specific home page
curl -s "https://nextcloud.local/apps/opencatalogi/api/pages/home" \
  -H "Accept: application/json"
```

## 6. Alternative: Direct Database Setup

If the REST API doesn't work due to local environment issues, you can insert the data directly:

First, check your OpenRegister configuration:
```bash
docker exec -u 33 master-nextcloud-1 php -r "print_r(include '/var/www/html/config/config.php');" | grep -E "(dbhost|dbname|dbuser|dbpassword|dbtableprefix)"
```

Then insert the objects directly into the database:
```sql
-- Note: Replace 'oc_' with your actual table prefix
INSERT INTO oc_object_objects (schema, register, data, public, tenant_id, created_user_id, created, modified) 
VALUES 
('menu', 'publication', '{"@self":{"register":"publication","schema":"menu","version":"1.1.1","slug":"main-navigation"},"title":"Main Navigation","name":"main-navigation","position":1,"items":[{"name":"Home","slug":"home","link":"/","description":"Homepage","icon":"home","order":"0"},{"name":"Catalogs","slug":"catalogs","link":"/catalogs","description":"Browse available catalogs","icon":"catalog","order":"1"},{"name":"Publications","slug":"publications","link":"/publications","description":"Browse all publications","icon":"publication","order":"2"}]}', 1, 'local', 1, NOW(), NOW()),

('page', 'publication', '{"@self":{"register":"publication","schema":"page","version":"1.0.0","slug":"home"},"title":"Welcome to OpenCatalogi","slug":"home","content":"# Welcome to OpenCatalogi\n\nThis is the homepage of your catalog website.","published":true,"order":1}', 1, 'local', 1, NOW(), NOW());
```

## Notes

- The menus are stored as OpenRegister objects with schema="menu" 
- Pages are stored as OpenRegister objects with schema="page"
- Both use register="publication" based on the accept environment setup
- Menu items support ordering, icons, and hierarchical structure
- Pages support slugs for SEO-friendly URLs

This setup replicates the structure from your accept environment to your local development environment.
