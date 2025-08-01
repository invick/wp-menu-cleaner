<?php
/**
 * Plugin Name: Menu Cleaner
 * Plugin URI: https://example.com/menu-cleaner
 * Description: Deletes menu items from any WordPress menu with progress tracking. Select menu, number of items, and watch real-time deletion progress.
 * Version: 1.6.0
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
define('MENU_CLEANER_VERSION', '1.6.0');
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

// Helper function to get count of draft menu items
function menu_cleaner_get_draft_item_count($menu_id) {
    global $wpdb;
    
    $count = $wpdb->get_var($wpdb->prepare("
        SELECT COUNT(DISTINCT p.ID)
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
        INNER JOIN {$wpdb->postmeta} pm_object ON p.ID = pm_object.post_id
        LEFT JOIN {$wpdb->posts} linked_post ON pm_object.meta_value = linked_post.ID
        WHERE p.post_type = 'nav_menu_item'
        AND tr.term_taxonomy_id = %d
        AND pm_object.meta_key = '_menu_item_object_id'
        AND pm_object.meta_value != '0'
        AND (
            linked_post.post_status = 'draft' 
            OR linked_post.post_status = 'pending'
            OR linked_post.post_status = 'auto-draft'
        )
    ", $menu_id));
    
    return intval($count);
}

// Helper function to get count of orphaned menu items
function menu_cleaner_get_orphaned_item_count($menu_id) {
    global $wpdb;
    
    $count = $wpdb->get_var($wpdb->prepare("
        SELECT COUNT(DISTINCT p.ID)
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
        INNER JOIN {$wpdb->postmeta} pm_type ON p.ID = pm_type.post_id
        INNER JOIN {$wpdb->postmeta} pm_object ON p.ID = pm_object.post_id
        LEFT JOIN {$wpdb->posts} linked_post ON (
            pm_object.meta_value = linked_post.ID 
            AND pm_type.meta_value IN ('post', 'page')
        )
        LEFT JOIN {$wpdb->terms} linked_term ON (
            pm_object.meta_value = linked_term.term_id 
            AND pm_type.meta_value = 'taxonomy'
        )
        WHERE p.post_type = 'nav_menu_item'
        AND tr.term_taxonomy_id = %d
        AND pm_type.meta_key = '_menu_item_type'
        AND pm_object.meta_key = '_menu_item_object_id'
        AND pm_object.meta_value != '0'
        AND pm_type.meta_value != 'custom'
        AND (
            (pm_type.meta_value IN ('post', 'page') AND linked_post.ID IS NULL)
            OR (pm_type.meta_value = 'taxonomy' AND linked_term.term_id IS NULL)
        )
    ", $menu_id));
    
    return intval($count);
}

// Helper function to get proper menu item title
function menu_cleaner_get_item_title($item_id) {
    // Get the menu item with all its properties
    $menu_item = wp_setup_nav_menu_item(get_post($item_id));
    
    if ($menu_item && isset($menu_item->title) && !empty($menu_item->title)) {
        return $menu_item->title;
    }
    
    // Fallback to post title if navigation label is not set
    $post = get_post($item_id);
    if ($post && !empty($post->post_title)) {
        return $post->post_title;
    }
    
    return __('(no title)', 'menu-cleaner');
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
                        <label for="deletion_mode"><?php _e('Deletion Mode', 'menu-cleaner'); ?></label>
                    </th>
                    <td>
                        <select name="deletion_mode" id="deletion_mode" class="regular-text">
                            <option value="count"><?php _e('Delete by Count', 'menu-cleaner'); ?></option>
                            <option value="draft"><?php _e('Delete Draft Items', 'menu-cleaner'); ?></option>
                            <option value="orphaned"><?php _e('Delete Orphaned Items', 'menu-cleaner'); ?></option>
                        </select>
                        <p class="description"><?php _e('Choose how to select items for deletion.', 'menu-cleaner'); ?></p>
                    </td>
                </tr>
                <tr id="num_items_row">
                    <th scope="row">
                        <label for="num_items"><?php _e('Number of Items to Delete', 'menu-cleaner'); ?></label>
                    </th>
                    <td>
                        <input type="number" id="num_items" name="num_items" value="100" min="1" max="500" class="regular-text" />
                        <p class="description"><?php _e('Enter the number of menu items to delete (1-500).', 'menu-cleaner'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="skip_parents"><?php _e('Skip Parent Items', 'menu-cleaner'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" id="skip_parents" name="skip_parents" value="1" checked="checked" />
                            <?php _e('Skip menu items that have sub-items (and skip their sub-items too)', 'menu-cleaner'); ?>
                        </label>
                        <p class="description"><?php _e('When enabled, parent items and all their children will be preserved.', 'menu-cleaner'); ?></p>
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
                        <span id="progress-current">0</span> / <span id="progress-total">0</span> <?php _e('items processed', 'menu-cleaner'); ?>
                        <span id="progress-details" style="display: none;">
                            (<span id="deleted-count">0</span> <?php _e('deleted', 'menu-cleaner'); ?>, 
                            <span id="skipped-count">0</span> <?php _e('skipped', 'menu-cleaner'); ?>)
                        </span>
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
    
    // Validate and sanitize inputs
    $menu_id = isset($_POST['menu_id']) ? absint($_POST['menu_id']) : 0;
    $batch_size = isset($_POST['batch_size']) ? absint($_POST['batch_size']) : 10;
    $offset = isset($_POST['offset']) ? absint($_POST['offset']) : 0;
    $skip_parents = isset($_POST['skip_parents']) && $_POST['skip_parents'] === 'true';
    $deletion_mode = isset($_POST['deletion_mode']) ? sanitize_text_field($_POST['deletion_mode']) : 'count';
    
    // Validate menu ID
    if (!$menu_id || $menu_id <= 0) {
        wp_send_json_error(array('message' => __('Invalid menu ID', 'menu-cleaner')));
    }
    
    // Validate batch size (limit to prevent abuse)
    if ($batch_size <= 0 || $batch_size > 50) {
        $batch_size = 10;
    }
    
    // Validate offset
    if ($offset < 0) {
        $offset = 0;
    }
    
    // Verify the menu exists
    $menu = wp_get_nav_menu_object($menu_id);
    if (!$menu) {
        wp_send_json_error(array('message' => __('Menu not found', 'menu-cleaner')));
    }
    
    global $wpdb;
    
    // Build query based on deletion mode
    if ($deletion_mode === 'draft') {
        // Get menu items that link to draft posts/pages
        $query = $wpdb->prepare("
            SELECT DISTINCT p.ID, p.post_title, p.menu_order
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
            INNER JOIN {$wpdb->postmeta} pm_object ON p.ID = pm_object.post_id
            LEFT JOIN {$wpdb->posts} linked_post ON pm_object.meta_value = linked_post.ID
            WHERE p.post_type = 'nav_menu_item'
            AND tr.term_taxonomy_id = %d
            AND pm_object.meta_key = '_menu_item_object_id'
            AND pm_object.meta_value != '0'
            AND (
                linked_post.post_status = 'draft' 
                OR linked_post.post_status = 'pending'
                OR linked_post.post_status = 'auto-draft'
            )
            ORDER BY p.menu_order DESC
            LIMIT %d OFFSET %d
        ", $menu_id, $batch_size, $offset);
    } elseif ($deletion_mode === 'orphaned') {
        // Get menu items where the linked object no longer exists
        $query = $wpdb->prepare("
            SELECT DISTINCT p.ID, p.post_title, p.menu_order
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
            INNER JOIN {$wpdb->postmeta} pm_type ON p.ID = pm_type.post_id
            INNER JOIN {$wpdb->postmeta} pm_object ON p.ID = pm_object.post_id
            LEFT JOIN {$wpdb->posts} linked_post ON (
                pm_object.meta_value = linked_post.ID 
                AND pm_type.meta_value IN ('post', 'page')
            )
            LEFT JOIN {$wpdb->terms} linked_term ON (
                pm_object.meta_value = linked_term.term_id 
                AND pm_type.meta_value = 'taxonomy'
            )
            WHERE p.post_type = 'nav_menu_item'
            AND tr.term_taxonomy_id = %d
            AND pm_type.meta_key = '_menu_item_type'
            AND pm_object.meta_key = '_menu_item_object_id'
            AND pm_object.meta_value != '0'
            AND pm_type.meta_value != 'custom'
            AND (
                (pm_type.meta_value IN ('post', 'page') AND linked_post.ID IS NULL)
                OR (pm_type.meta_value = 'taxonomy' AND linked_term.term_id IS NULL)
            )
            ORDER BY p.menu_order DESC
            LIMIT %d OFFSET %d
        ", $menu_id, $batch_size, $offset);
    } elseif ($skip_parents) {
        // Get all parent IDs first
        $parent_ids = $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT pm.meta_value 
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
            INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
            WHERE pm.meta_key = '_menu_item_menu_item_parent'
            AND pm.meta_value != '0'
            AND p.post_type = 'nav_menu_item'
            AND tr.term_taxonomy_id = %d
        ", $menu_id));
        
        // Get menu items to delete, excluding parents and their children
        if (!empty($parent_ids)) {
            // Use placeholders for safety
            $placeholders = implode(',', array_fill(0, count($parent_ids), '%d'));
            $query_args = array_merge(array($menu_id), $parent_ids, $parent_ids, array($batch_size, $offset));
            
            $query = $wpdb->prepare("
                SELECT p.ID, p.post_title, p.menu_order
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
                LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_menu_item_menu_item_parent'
                WHERE p.post_type = 'nav_menu_item'
                AND tr.term_taxonomy_id = %d
                AND NOT EXISTS (
                    SELECT 1 FROM {$wpdb->postmeta} pm2
                    WHERE pm2.meta_key = '_menu_item_menu_item_parent'
                    AND pm2.meta_value = CAST(p.ID AS CHAR)
                    AND pm2.meta_value != '0'
                )
                AND p.ID NOT IN ($placeholders)
                AND (pm.meta_value IS NULL OR pm.meta_value = '0' OR pm.meta_value NOT IN ($placeholders))
                ORDER BY p.menu_order DESC
                LIMIT %d OFFSET %d
            ", ...$query_args);
        } else {
            $query = $wpdb->prepare("
                SELECT p.ID, p.post_title, p.menu_order
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
                LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_menu_item_menu_item_parent'
                WHERE p.post_type = 'nav_menu_item'
                AND tr.term_taxonomy_id = %d
                AND NOT EXISTS (
                    SELECT 1 FROM {$wpdb->postmeta} pm2
                    WHERE pm2.meta_key = '_menu_item_menu_item_parent'
                    AND pm2.meta_value = CAST(p.ID AS CHAR)
                    AND pm2.meta_value != '0'
                )
                ORDER BY p.menu_order DESC
                LIMIT %d OFFSET %d
            ", $menu_id, $batch_size, $offset);
        }
    } else {
        // Original query - delete all items regardless of parent/child relationship
        $query = $wpdb->prepare("
            SELECT p.ID, p.post_title, p.menu_order
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
            WHERE p.post_type = 'nav_menu_item'
            AND tr.term_taxonomy_id = %d
            ORDER BY p.menu_order DESC
            LIMIT %d OFFSET %d
        ", $menu_id, $batch_size, $offset);
    }
    
    $menu_items = $wpdb->get_results($query);
    $deleted_items = array();
    $skipped_items = array();
    
    // If skip_parents is enabled, also get the items we're skipping
    if ($skip_parents && $batch_size > 0) {
        // Get parent items and their children that we're skipping
        $skip_query = $wpdb->prepare("
            SELECT p.ID, p.post_title, p.menu_order, 
                   pm_parent.meta_value as parent_id,
                   CASE 
                       WHEN EXISTS (
                           SELECT 1 FROM {$wpdb->postmeta} pm2
                           WHERE pm2.meta_key = '_menu_item_menu_item_parent'
                           AND pm2.meta_value = CAST(p.ID AS CHAR)
                           AND pm2.meta_value != '0'
                       ) THEN 'parent'
                       ELSE 'child'
                   END as skip_reason
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
            LEFT JOIN {$wpdb->postmeta} pm_parent ON p.ID = pm_parent.post_id 
                AND pm_parent.meta_key = '_menu_item_menu_item_parent'
            WHERE p.post_type = 'nav_menu_item'
            AND tr.term_taxonomy_id = %d
            AND (
                EXISTS (
                    SELECT 1 FROM {$wpdb->postmeta} pm2
                    WHERE pm2.meta_key = '_menu_item_menu_item_parent'
                    AND pm2.meta_value = CAST(p.ID AS CHAR)
                    AND pm2.meta_value != '0'
                )
                OR pm_parent.meta_value IN (
                    SELECT CAST(p2.ID AS CHAR) FROM {$wpdb->posts} p2
                    INNER JOIN {$wpdb->term_relationships} tr2 ON p2.ID = tr2.object_id
                    WHERE p2.post_type = 'nav_menu_item'
                    AND tr2.term_taxonomy_id = %d
                    AND EXISTS (
                        SELECT 1 FROM {$wpdb->postmeta} pm3
                        WHERE pm3.meta_key = '_menu_item_menu_item_parent'
                        AND pm3.meta_value = CAST(p2.ID AS CHAR)
                        AND pm3.meta_value != '0'
                    )
                )
            )
            ORDER BY p.menu_order DESC
            LIMIT %d
        ", $menu_id, $menu_id, min($batch_size * 2, 20)); // Limit to reasonable number
        
        $skipped_results = $wpdb->get_results($skip_query);
        foreach ($skipped_results as $item) {
            $skipped_items[] = array(
                'id' => $item->ID,
                'title' => menu_cleaner_get_item_title($item->ID),
                'order' => $item->menu_order,
                'reason' => $item->skip_reason === 'parent' ? __('Has sub-items', 'menu-cleaner') : __('Child of protected parent', 'menu-cleaner')
            );
        }
    }
    
    foreach ($menu_items as $item) {
        // Get the title BEFORE deleting
        $item_title = menu_cleaner_get_item_title($item->ID);
        
        $result = wp_delete_post($item->ID, true);
        if ($result !== false) {
            $deleted_items[] = array(
                'id' => $item->ID,
                'title' => $item_title,
                'order' => $item->menu_order
            );
        }
    }
    
    // Clear menu cache
    wp_cache_delete('last_changed', 'terms');
    
    wp_send_json_success(array(
        'deleted' => $deleted_items,
        'count' => count($deleted_items),
        'skipped' => $skipped_items,
        'skipped_count' => count($skipped_items),
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
    $deletion_mode = isset($_POST['deletion_mode']) ? sanitize_text_field($_POST['deletion_mode']) : 'count';
    
    if (!$menu_id) {
        wp_send_json_error(array('message' => __('Invalid menu ID', 'menu-cleaner')));
    }
    
    // Get count based on deletion mode
    switch ($deletion_mode) {
        case 'draft':
            $count = menu_cleaner_get_draft_item_count($menu_id);
            break;
        case 'orphaned':
            $count = menu_cleaner_get_orphaned_item_count($menu_id);
            break;
        default:
            $count = menu_cleaner_get_menu_item_count($menu_id);
    }
    
    wp_send_json_success(array('count' => $count));
}