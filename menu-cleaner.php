<?php
/**
 * Plugin Name: Menu Cleaner
 * Plugin URI: https://example.com/menu-cleaner
 * Description: Deletes menu items from any WordPress menu with progress tracking. Select menu, number of items, and watch real-time deletion progress.
 * Version: 1.2.1
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
define('MENU_CLEANER_VERSION', '1.2.1');
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

// Enqueue scripts and styles
add_action('admin_enqueue_scripts', 'menu_cleaner_enqueue_scripts');

// Helper function to safely get menu item count
function menu_cleaner_get_menu_item_count($menu_id) {
    global $wpdb;
    
    // Direct database query to avoid triggering other plugins' hooks
    $count = $wpdb->get_var($wpdb->prepare("
        SELECT COUNT(*) 
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
        WHERE p.post_type = 'nav_menu_item'
        AND tr.term_taxonomy_id = %d
    ", $menu_id));
    
    return intval($count);
}

function menu_cleaner_enqueue_scripts($hook) {
    if ('tools_page_menu-cleaner' !== $hook) {
        return;
    }
    
    wp_enqueue_script(
        'menu-cleaner-script',
        MENU_CLEANER_PLUGIN_URL . 'assets/menu-cleaner.js',
        array('jquery'),
        MENU_CLEANER_VERSION,
        true
    );
    
    wp_localize_script('menu-cleaner-script', 'menu_cleaner_ajax', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('menu_cleaner_ajax_nonce')
    ));
    
    wp_enqueue_style(
        'menu-cleaner-style',
        MENU_CLEANER_PLUGIN_URL . 'assets/menu-cleaner.css',
        array(),
        MENU_CLEANER_VERSION
    );
}

// Admin page callback
function menu_cleaner_admin_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.', 'menu-cleaner'));
    }
    
    // Get all menus
    $menus = wp_get_nav_menus();
    
    // Set error reporting level to suppress warnings from other plugins
    $old_error_level = error_reporting(E_ERROR | E_PARSE);
    ?>
    <div class="wrap">
        <h1><?php echo esc_html__('Clean Menu Items', 'menu-cleaner'); ?></h1>
        
        <div id="menu-cleaner-notices"></div>
        
        <p><?php _e('Select a menu and specify how many items to delete. Items will be deleted starting from the last/highest menu order.', 'menu-cleaner'); ?></p>
        <p><strong><?php _e('Warning:', 'menu-cleaner'); ?></strong> <?php _e('This action cannot be undone. Make sure to backup your menu before proceeding.', 'menu-cleaner'); ?></p>

        <form id="menu-cleaner-form" method="post">
            <?php wp_nonce_field('menu_cleaner_action', 'menu_cleaner_nonce'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="menu_id"><?php _e('Select Menu', 'menu-cleaner'); ?></label>
                    </th>
                    <td>
                        <select name="menu_id" id="menu_id" class="regular-text">
                            <option value=""><?php _e('— Select a Menu —', 'menu-cleaner'); ?></option>
                            <?php foreach ($menus as $menu): ?>
                                <option value="<?php echo esc_attr($menu->term_id); ?>">
                                    <?php echo esc_html($menu->name); ?> 
                                    (<?php 
                                        // Use direct database query to avoid plugin conflicts
                                        $count = menu_cleaner_get_menu_item_count($menu->term_id);
                                        printf(_n('%d item', '%d items', $count, 'menu-cleaner'), $count);
                                    ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php _e('Choose which menu to clean.', 'menu-cleaner'); ?></p>
                    </td>
                </tr>
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
            
            <div id="menu-cleaner-progress" style="display: none;">
                <h3><?php _e('Deletion Progress', 'menu-cleaner'); ?></h3>
                <div class="progress-wrapper">
                    <div class="progress-bar">
                        <div class="progress-fill"></div>
                    </div>
                    <div class="progress-text">
                        <span id="progress-current">0</span> / <span id="progress-total">0</span> <?php _e('items deleted', 'menu-cleaner'); ?>
                    </div>
                </div>
                <div id="deletion-log" class="deletion-log"></div>
            </div>
            
            <p class="submit">
                <input type="button" id="clean-menu-items" class="button button-primary" value="<?php esc_attr_e('Delete Menu Items', 'menu-cleaner'); ?>" />
                <span class="spinner"></span>
            </p>
        </form>
    </div>
    <?php
    
    // Restore error reporting level
    error_reporting($old_error_level);
}

// AJAX handler for deleting menu items
add_action('wp_ajax_menu_cleaner_delete_items', 'menu_cleaner_ajax_delete_items');

function menu_cleaner_ajax_delete_items() {
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'menu_cleaner_ajax_nonce')) {
        wp_die(__('Security check failed', 'menu-cleaner'));
    }
    
    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_die(__('Insufficient permissions', 'menu-cleaner'));
    }
    
    $menu_id = isset($_POST['menu_id']) ? intval($_POST['menu_id']) : 0;
    $batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 10;
    $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
    
    if (!$menu_id) {
        wp_send_json_error(array('message' => __('Invalid menu ID', 'menu-cleaner')));
    }
    
    global $wpdb;
    
    // Get menu items to delete (batch processing)
    $query = $wpdb->prepare("
        SELECT p.ID, p.post_title, p.menu_order
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
        WHERE p.post_type = 'nav_menu_item'
        AND tr.term_taxonomy_id = %d
        ORDER BY p.menu_order DESC
        LIMIT %d OFFSET %d
    ", $menu_id, $batch_size, $offset);
    
    $menu_items = $wpdb->get_results($query);
    $deleted_items = array();
    
    foreach ($menu_items as $item) {
        $result = wp_delete_post($item->ID, true);
        if ($result !== false) {
            $deleted_items[] = array(
                'id' => $item->ID,
                'title' => $item->post_title ?: __('(no title)', 'menu-cleaner'),
                'order' => $item->menu_order
            );
        }
    }
    
    // Clear menu cache
    wp_cache_delete('last_changed', 'terms');
    
    wp_send_json_success(array(
        'deleted' => $deleted_items,
        'count' => count($deleted_items),
        'has_more' => count($menu_items) === $batch_size
    ));
}

// AJAX handler for getting menu item count
add_action('wp_ajax_menu_cleaner_get_count', 'menu_cleaner_ajax_get_count');

function menu_cleaner_ajax_get_count() {
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'menu_cleaner_ajax_nonce')) {
        wp_die(__('Security check failed', 'menu-cleaner'));
    }
    
    $menu_id = isset($_POST['menu_id']) ? intval($_POST['menu_id']) : 0;
    
    if (!$menu_id) {
        wp_send_json_error(array('message' => __('Invalid menu ID', 'menu-cleaner')));
    }
    
    // Use our safe count function
    $count = menu_cleaner_get_menu_item_count($menu_id);
    
    wp_send_json_success(array('count' => $count));
}