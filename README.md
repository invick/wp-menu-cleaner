# Menu Cleaner

A WordPress plugin by Victor Adams that helps you clean up your navigation menus by deleting menu items based on their visual position (menu_order). Features menu selection, customizable deletion count, and real-time progress tracking with AJAX.

## Description

Menu Cleaner is a utility plugin designed to help WordPress administrators manage large navigation menus. It allows you to select any menu in your WordPress site and remove items based on their menu order. With real-time progress tracking, you can watch as items are deleted in batches, making it ideal for cleaning up test data or managing oversized menus.

## Features

- **Menu Selection**: Choose any menu from your WordPress site to clean
- **Customizable Deletion**: Specify how many items to delete (1-500)
- **Skip Parent Items**: Option to preserve menu items that have sub-items (and their children)
- **Real-time Progress Bar**: Visual progress indicator with percentage completion
- **AJAX-powered**: Batch processing prevents timeouts on large operations
- **Deletion Log**: Live feed showing each deleted item with ID and title
- **Item Count Display**: Shows current item count for each menu
- **Safe Operation**: Requires administrator permissions (`manage_options` capability)
- **Confirmation Dialog**: Prevents accidental deletions
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
2. Select a menu from the dropdown (shows item count for each menu)
3. Enter the number of items you want to delete (1-500)
4. Choose whether to skip parent items (enabled by default):
   - When **checked**: Parent items and all their sub-items will be preserved
   - When **unchecked**: All items will be deleted regardless of hierarchy
5. Click the "Delete Menu Items" button
6. Confirm the action in the popup dialog
7. Watch the real-time progress bar as items are deleted
8. Review the deletion log showing each removed item
9. The menu dropdown automatically updates with the new item count

### What Gets Deleted

- The plugin identifies menu items by `post_type = 'nav_menu_item'`
- Items are sorted by `menu_order` in descending order (highest/last items first)
- Only items from the selected menu are affected
- You can delete between 1 and 500 items per operation
- Items are deleted in batches of 10 to prevent timeouts
- Each deleted item is logged with its ID, title, and menu order

### Menu Selection

The plugin provides:
1. A dropdown list of all available menus in your WordPress site
2. Live item count for each menu
3. Automatic count updates after deletion
4. Support for any menu regardless of location or name

## Safety Features

- **Permission Check**: Only users with `manage_options` capability can access
- **Confirmation Dialog**: JavaScript confirmation required before deletion
- **Admin-Only**: The tool only works within the WordPress admin area
- **Detailed Reporting**: Shows exactly which item IDs were deleted
- **No Auto-Run**: Requires manual trigger (auto-run code is commented out)

## Technical Details

### AJAX Implementation
- Batch processing: Deletes items in groups of 10
- Prevents timeouts on large deletion operations
- Real-time progress updates without page refresh
- Graceful error handling with user-friendly messages

### Database Operations
- Uses WordPress `$wpdb` for efficient querying
- Joins `wp_posts` with `wp_term_relationships` tables
- Properly clears WordPress menu cache after deletion

### Hooks and Actions
- `admin_menu`: Registers the admin submenu page
- `admin_enqueue_scripts`: Loads JavaScript and CSS assets
- `wp_ajax_menu_cleaner_delete_items`: AJAX handler for deletion
- `wp_ajax_menu_cleaner_get_count`: AJAX handler for menu counts
- `wp_delete_post()`: Core function for safe post deletion

## Troubleshooting

**No items deleted?**
- Verify the selected menu contains items
- Check that items haven't already been deleted
- Ensure you have administrator privileges

**Progress bar stuck?**
- Check browser console for JavaScript errors
- Verify AJAX requests aren't being blocked
- Try refreshing the page and attempting again

**Timeout errors?**
- The plugin uses batch processing to prevent timeouts
- If issues persist, try deleting fewer items at once

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

### 1.4.0 - by Victor Adams
- Fixed menu item titles to show actual navigation labels instead of post titles
- Now uses wp_setup_nav_menu_item() for accurate menu item names
- Deletion log now shows the same titles users see in the menu editor
- Improved readability and accuracy of deletion/skip logs
- Confirmed deletion only happens on manual button click (no auto-deletion)

### 1.3.1
- Added identification of skipped items in the deletion log
- Shows which items were skipped and why (parent with sub-items or child of protected parent)
- Updated progress display to show both deleted and skipped counts
- Visual distinction between deleted (green checkmark) and skipped (yellow dash) items
- Improved transparency in the deletion process

### 1.3.0
- Added "Skip Parent Items" option to preserve hierarchical menu structures
- Parent items with sub-items can now be protected from deletion
- Sub-items of protected parent items are also preserved
- Option is enabled by default for safer cleaning
- Improved SQL queries to handle parent-child relationships

### 1.2.1
- Fixed compatibility issues with other plugins (WP-Optimize Premium)
- Improved menu item counting using direct database queries
- Added error suppression for third-party plugin warnings
- Enhanced stability when working with large menus

### 1.2.0
- Added menu selection dropdown with item counts
- Implemented AJAX-based deletion with real-time progress bar
- Added deletion log showing each removed item
- Batch processing to prevent timeouts
- Live menu count updates after deletion
- Enhanced UI with CSS animations
- Improved error handling and user feedback

### 1.1.0
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