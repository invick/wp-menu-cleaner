<?php
/**
 * Plugin Name: Menu Cleaner
 * Plugin URI: https://example.com/menu-cleaner
 * Description: Deletes menu items (by visual position) from the Primary Menu based on menu_order. User can select how many items to delete.
 * Version: 1.1.0
 * Requires at least: 5.0
 * Requires PHP: 7.2
 * Author: Victor Adams
 * Author URI: https://example.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: menu-cleaner
 * Domain Path: /languages
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('MENU_CLEANER_VERSION', '1.1.0');
define('MENU_CLEANER_PLUGIN_URL', plugin_dir_url(__FILE__));
define('MENU_CLEANER_PLUGIN_PATH', plugin_dir_path(__FILE__));

// Hook to add admin menu
add_action('admin_menu', 'menu_cleaner_add_admin_menu');

function menu_cleaner_add_admin_menu() {
    add_submenu_page(
        'tools.php',
        'Clean Menu Items',
        'Clean Menu Items',
        'manage_options',
        'menu-cleaner',
        'menu_cleaner_admin_page'
    );
}

// Admin page callback
function menu_cleaner_admin_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.', 'menu-cleaner'));
    }

    $deleted_count = 0;
    $deleted_ids = array();

    // Check if form was submitted
    if (isset($_POST['clean_menu_items']) && check_admin_referer('menu_cleaner_action', 'menu_cleaner_nonce')) {
        $num_items = isset($_POST['num_items']) ? intval($_POST['num_items']) : 100;
        $num_items = max(1, min(500, $num_items)); // Limit between 1 and 500
        
        $result = menu_cleaner_delete_menu_items($num_items);
        $deleted_count = $result['count'];
        $deleted_ids = $result['ids'];
    }

    ?>
    <div class="wrap">
        <h1><?php echo esc_html__('Clean Menu Items', 'menu-cleaner'); ?></h1>
        
        <?php if ($deleted_count > 0): ?>
            <div class="notice notice-success is-dismissible">
                <p><?php printf(__('Successfully deleted %d menu items from the Primary Menu.', 'menu-cleaner'), $deleted_count); ?></p>
                <?php if (!empty($deleted_ids)): ?>
                    <p><strong><?php _e('Deleted IDs:', 'menu-cleaner'); ?></strong> <?php echo implode(', ', $deleted_ids); ?></p>
                <?php endif; ?>
            </div>
        <?php elseif (isset($_POST['clean_menu_items'])): ?>
            <div class="notice notice-warning is-dismissible">
                <p><?php _e('No menu items were found to delete.', 'menu-cleaner'); ?></p>
            </div>
        <?php endif; ?>

        <p><?php _e('This tool will delete menu items from your Primary Menu based on their menu order (starting from the last/highest items).', 'menu-cleaner'); ?></p>
        <p><strong><?php _e('Warning:', 'menu-cleaner'); ?></strong> <?php _e('This action cannot be undone. Make sure to backup your menu before proceeding.', 'menu-cleaner'); ?></p>

        <form method="post" action="">
            <?php wp_nonce_field('menu_cleaner_action', 'menu_cleaner_nonce'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="num_items"><?php _e('Number of Items to Delete', 'menu-cleaner'); ?></label>
                    </th>
                    <td>
                        <input type="number" id="num_items" name="num_items" value="100" min="1" max="500" class="regular-text" />
                        <p class="description"><?php _e('Enter the number of menu items to delete (1-500).', 'menu-cleaner'); ?></p>
                    </td>
                </tr>
            </table>
            <p>
                <input type="submit" name="clean_menu_items" class="button button-primary" value="<?php esc_attr_e('Delete Menu Items', 'menu-cleaner'); ?>" onclick="return confirm('Are you sure you want to delete ' + document.getElementById('num_items').value + ' menu items? This action cannot be undone.');" />
            </p>
        </form>
    </div>
    <?php
}

// Function to delete menu items
function menu_cleaner_delete_menu_items($num_items = 100) {
    global $wpdb;
    
    $deleted_count = 0;
    $deleted_ids = array();
    
    // Get the Primary Menu
    $primary_menu = get_nav_menu_locations();
    
    if (!isset($primary_menu['primary']) || empty($primary_menu['primary'])) {
        // Try to find a menu with name 'Primary Menu' or 'Main Menu'
        $menu_names = array('Primary Menu', 'Main Menu', 'Primary', 'Main');
        foreach ($menu_names as $menu_name) {
            $menu = wp_get_nav_menu_object($menu_name);
            if ($menu) {
                $primary_menu_id = $menu->term_id;
                break;
            }
        }
        
        if (!isset($primary_menu_id)) {
            return array('count' => 0, 'ids' => array());
        }
    } else {
        $primary_menu_id = $primary_menu['primary'];
    }
    
    // Get the last N menu items ordered by menu_order DESC
    $query = $wpdb->prepare("
        SELECT p.ID 
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
        WHERE p.post_type = 'nav_menu_item'
        AND tr.term_taxonomy_id = %d
        ORDER BY p.menu_order DESC
        LIMIT %d
    ", $primary_menu_id, $num_items);
    
    $menu_items = $wpdb->get_results($query);
    
    // Delete each menu item
    foreach ($menu_items as $item) {
        $result = wp_delete_post($item->ID, true);
        if ($result !== false) {
            $deleted_count++;
            $deleted_ids[] = $item->ID;
        }
    }
    
    // Clear menu cache
    wp_cache_delete('last_changed', 'terms');
    
    return array(
        'count' => $deleted_count,
        'ids' => $deleted_ids
    );
}

// Optional: Auto-run once on activation (commented out for safety)
// register_activation_hook(__FILE__, 'menu_cleaner_run_once_on_activation');
// function menu_cleaner_run_once_on_activation() {
//     if (get_option('menu_cleaner_has_run') !== 'yes') {
//         menu_cleaner_delete_menu_items();
//         update_option('menu_cleaner_has_run', 'yes');
//     }
// }