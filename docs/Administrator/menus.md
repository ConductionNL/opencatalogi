# Menu Management

## Overview

The menu management system allows administrators to create and configure navigation menus for the OpenCatalogi application. Menus can be positioned at different locations within the interface and can contain multiple menu items.

## Menu Properties

### Position
- **Type**: Numeric value
- **Description**: The position where the menu will be displayed in the interface
- **Values**: Any positive integer (0, 1, 2, 3, 4, 5, etc.)
- **Note**: Position values are displayed as numbers in the interface for consistency

### Title
- **Type**: String
- **Description**: The display name of the menu
- **Required**: Yes

### Slug
- **Type**: String
- **Description**: URL-friendly identifier for the menu
- **Required**: No

### Description
- **Type**: String
- **Description**: Additional information about the menu's purpose
- **Required**: No

### Menu Items
- **Type**: Array of menu item objects
- **Description**: The individual links and pages that make up the menu
- **Required**: No (menu can be empty)

## Creating a Menu

1. Navigate to the Menus section in the admin interface
2. Click the "Add Menu" button
3. Fill in the required fields:
   - **Title**: Enter a descriptive name for the menu
   - **Position**: Enter a numeric value for the menu's display position
   - **Description**: (Optional) Add additional context
   - **Slug**: (Optional) Enter a URL-friendly identifier
4. Click "Save" to create the menu

## Managing Menu Items

### Adding Items
1. Select a menu from the list
2. Click "Add Item" from the actions menu
3. Configure the menu item properties:
   - **Title**: Display name for the menu item
   - **Link**: URL or route the item should navigate to
   - **Description**: Additional information about the item
   - **Icon**: Visual representation for the item
   - **Order**: Position within the menu (optional)
   - **Groups**: Access control groups (optional)
   - **Hide After Login**: Whether to hide the item for authenticated users

### Editing Items
1. Select a menu from the list
2. Click "View" to see the menu details
3. Use the drag-and-drop interface to reorder items
4. Click "Edit" on individual items to modify their properties
5. Click "Save" to apply changes

### Deleting Items
1. Select a menu from the list
2. Click "View" to see the menu details
3. Click "Delete" on the item you want to remove
4. Confirm the deletion

## Menu Operations

### Copying Menus
- Use the "Copy" action to duplicate an existing menu
- This creates a new menu with the same configuration
- You can then modify the copy as needed

### Deleting Menus
- Use the "Delete" action to remove a menu and all its items
- This action cannot be undone
- Ensure you have a backup if needed

## View Modes

The menu management interface supports two view modes:

### Cards View
- Displays menus as individual cards
- Shows detailed information including position, item count, and last updated
- Provides quick access to common actions

### Table View
- Displays menus in a tabular format
- Shows key information in columns
- Supports bulk selection and operations

## Best Practices

1. **Position Planning**: Plan your menu positions in advance to avoid conflicts
2. **Descriptive Titles**: Use clear, descriptive names for menus and items
3. **Consistent Structure**: Maintain consistent naming and organization across menus
4. **Access Control**: Use groups to control who can see specific menu items
5. **Regular Review**: Periodically review and update menu configurations

## Troubleshooting

### Common Issues

**Menu not displaying**: Check that the position value is valid and doesn't conflict with other menus
**Items not showing**: Verify that menu items are properly configured and have valid links
**Access denied**: Ensure users are in the correct groups for menu item visibility

### Support

For additional help with menu management, refer to the help documentation or contact your system administrator.
