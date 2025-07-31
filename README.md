# Menu Cleaner

A WordPress plugin by Victor Adams that helps you clean up your navigation menus by deleting menu items based on their visual position (menu_order). Users can select how many items to delete (1-500).

## Description

Menu Cleaner is a utility plugin designed to help WordPress administrators manage large navigation menus. It specifically targets the Primary Menu and removes items based on their menu order, allowing users to specify exactly how many items to delete (1-500), making it ideal for cleaning up test data or managing oversized menus.

## Features

- **Customizable Deletion**: Choose how many items to delete (1-500)
- **Targeted Deletion**: Only affects the Primary Menu, leaving other menus untouched
- **Safe Operation**: Requires administrator permissions (`manage_options` capability)
- **Visual Feedback**: Shows the number of deleted items and their IDs
- **Manual Control**: Operates through a dedicated admin page with confirmation dialog
- **Smart Detection**: Automatically finds the Primary Menu using various naming conventions
- **Complete Removal**: Uses `wp_delete_post()` with hard delete for thorough cleanup

## Requirements

- **WordPress**: 5.0 or higher (Recommended: 6.0+)
- **PHP**: 7.2 or higher (Recommended: 8.0+)
- Administrator access to WordPress admin panel

## Installation

1. Download the `menu-cleaner` folder
2. Upload it to your `/wp-content/plugins/` directory
3. Activate the plugin through the 'Plugins' menu in WordPress
4. Navigate to Tools → Clean Menu Items to use the plugin

## Usage

1. After activation, go to **Tools → Clean Menu Items** in your WordPress admin
2. The page will show a warning about the permanent nature of the deletion
3. Enter the number of items you want to delete (1-500)
4. Click the "Delete Menu Items" button
5. Confirm the action in the popup dialog
6. The plugin will delete the specified number of menu items and display the results

### What Gets Deleted

- The plugin identifies menu items by `post_type = 'nav_menu_item'`
- Items are sorted by `menu_order` in descending order (highest/last items first)
- Only items associated with the Primary Menu are affected
- You can delete between 1 and 500 items per operation

### Primary Menu Detection

The plugin looks for the Primary Menu in this order:
1. Checks the theme's registered 'primary' menu location
2. Searches for menus named: 'Primary Menu', 'Main Menu', 'Primary', or 'Main'
3. Returns safely if no Primary Menu is found

## Safety Features

- **Permission Check**: Only users with `manage_options` capability can access
- **Confirmation Dialog**: JavaScript confirmation required before deletion
- **Admin-Only**: The tool only works within the WordPress admin area
- **Detailed Reporting**: Shows exactly which item IDs were deleted
- **No Auto-Run**: Requires manual trigger (auto-run code is commented out)

## Technical Details

### Database Operations
- Uses WordPress `$wpdb` for efficient querying
- Joins `wp_posts` with `wp_term_relationships` tables
- Properly clears WordPress menu cache after deletion

### Hooks Used
- `admin_menu`: Registers the admin submenu page
- `wp_delete_post()`: Core function for safe post deletion

## Troubleshooting

**No items deleted?**
- Check if your theme has a Primary Menu defined
- Verify that the menu contains nav_menu_item posts
- Ensure you have administrator privileges

**Wrong menu affected?**
- The plugin only targets the Primary Menu
- Check your theme's menu locations in Appearance → Menus

**Need to delete more than 500 items?**
- Run the tool multiple times
- Each run can delete up to 500 items
- Or modify the plugin code to increase the limit

## Developer Notes

### Customization Options

To auto-run on activation (use with caution):
```php
register_activation_hook(__FILE__, 'menu_cleaner_run_once_on_activation');
function menu_cleaner_run_once_on_activation() {
    if (get_option('menu_cleaner_has_run') !== 'yes') {
        menu_cleaner_delete_menu_items();
        update_option('menu_cleaner_has_run', 'yes');
    }
}
```

### Modify Maximum Deletion Limit

To change the maximum allowed deletion limit (currently 500), modify this line:
```php
$num_items = max(1, min(500, $num_items)); // Change 500 to your desired maximum
```

## Changelog

### 1.1.0 - by Victor Adams
- Added user-selectable number of items to delete (1-500)
- Updated UI with number input field
- Dynamic confirmation message showing selected count
- Added PHP and WordPress version requirements

### 1.0.0
- Initial release
- Core deletion functionality (fixed 100 items)
- Admin interface with Tools submenu
- Safety confirmations and permission checks
- Detailed deletion reporting

## Author

**Victor Adams**

## License

GPL v2 or later

## Support

This is a utility plugin for development and testing purposes. Use with caution on production sites. Always backup your database before performing bulk deletions.