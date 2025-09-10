<?php
/**
 * Plugin Name: MSW Product SEO
 * Description: WooCommerce Product SEO metrics synchronization with Google Search Console.
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__.'/classes/ProductSEO.php';

/**
 * Activation hook to create required tables.
 */
function msw_ps_activate() {
    global $wpdb;
    $seo = new ProductSEO($wpdb, $wpdb->prefix);
    $seo->create_tables();
}
register_activation_hook(__FILE__, 'msw_ps_activate');

/**
 * Register settings for the plugin.
 */
function msw_ps_register_settings() {
    register_setting('msw_ps_options', 'msw_ps_auto_update', ['type'=>'boolean','default'=>0]);
    register_setting('msw_ps_options', 'msw_ps_interval', ['type'=>'integer','default'=>12]);
    register_setting('msw_ps_options', 'msw_ps_gsc_key', ['type'=>'string','default'=>'']);
    register_setting('msw_ps_options', 'msw_ps_gsc_property', ['type'=>'string','default'=>'']);
    register_setting('msw_ps_options', 'msw_ps_last_log', ['type'=>'string','default'=>'']);
    register_setting('msw_ps_options', 'msw_ps_last_run', ['type'=>'string','default'=>'']);
}
add_action('admin_init', 'msw_ps_register_settings');

/**
 * Add admin menu for Product SEO settings and dashboard.
 */
function msw_ps_admin_menu() {
    add_menu_page(
        __('تنظیمات Product SEO','msw'),
        __('Product SEO','msw'),
        'manage_options',
        'msw-product-seo',
        'msw_ps_settings_page',
        'dashicons-chart-line'
    );
}
add_action('admin_menu', 'msw_ps_admin_menu');

/**
 * Render the settings page with summary dashboard and DataTable.
 */
function msw_ps_settings_page() {
    if (!current_user_can('manage_options')) {
        return;
    }
    global $wpdb;
    $seo = new ProductSEO($wpdb, $wpdb->prefix);
    $summary = $seo->summary();
    ?>
    <div class="wrap">
        <h1><?php _e('تنظیمات Product SEO','msw'); ?></h1>
        <form method="post" action="options.php">
            <?php settings_fields('msw_ps_options'); ?>
            <?php do_settings_sections('msw_ps_options'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php _e('فعال‌سازی بروزرسانی خودکار','msw'); ?></th>
                    <td><input type="checkbox" name="msw_ps_auto_update" value="1" <?php checked(1,get_option('msw_ps_auto_update')); ?>/></td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('بازه زمانی (ساعت)','msw'); ?></th>
                    <td><input type="number" name="msw_ps_interval" value="<?php echo esc_attr(get_option('msw_ps_interval',12)); ?>"/></td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('GSC Key','msw'); ?></th>
                    <td><input type="text" name="msw_ps_gsc_key" value="<?php echo esc_attr(get_option('msw_ps_gsc_key')); ?>" class="regular-text"/></td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('GSC Property','msw'); ?></th>
                    <td><input type="text" name="msw_ps_gsc_property" value="<?php echo esc_attr(get_option('msw_ps_gsc_property')); ?>" class="regular-text"/></td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
        <hr/>
        <h2><?php _e('Summary','msw'); ?></h2>
        <p><?php printf(__('Total: %d, Indexed: %d, Unindexed: %d','msw'), $summary['total'], $summary['indexed'], $summary['unindexed']); ?></p>
        <p><?php printf(__('Last Update: %s','msw'), esc_html($summary['last_update'])); ?></p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('msw_ps_initial'); ?>
            <input type="hidden" name="action" value="msw_ps_initial" />
            <?php submit_button(__('مقداردهی اولیه','msw'), 'secondary'); ?>
        </form>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('msw_ps_manual_update'); ?>
            <input type="hidden" name="action" value="msw_ps_manual_update" />
            <?php submit_button(__('بروزرسانی دستی','msw'), 'secondary'); ?>
        </form>
        <hr/>
        <table id="msw-ps-table" class="widefat striped">
            <thead><tr><th><?php _e('محصول','msw'); ?></th><th>Impr.</th><th>Clicks</th><th>CTR</th><th>Pos</th><th><?php _e('وضعیت','msw'); ?></th></tr></thead>
            <tbody>
            <?php
            $rows = $wpdb->get_results("SELECT product_name,impressions,clicks,ctr,avg_position,indexed_status FROM {$wpdb->prefix}msw_products_seo", ARRAY_A);
            foreach ($rows as $r): ?>
                <tr>
                    <td><?php echo esc_html($r['product_name']); ?></td>
                    <td><?php echo intval($r['impressions']); ?></td>
                    <td><?php echo intval($r['clicks']); ?></td>
                    <td><?php echo round($r['ctr'],2); ?></td>
                    <td><?php echo round($r['avg_position'],2); ?></td>
                    <td><?php echo esc_html($r['indexed_status']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <script>
    jQuery(function($){ $('#msw-ps-table').DataTable(); });
    </script>
    <?php
}

/**
 * Handle initial population request.
 */
function msw_ps_handle_initial() {
    if (!current_user_can('manage_options') || !check_admin_referer('msw_ps_initial')) {
        wp_die(__('Unauthorized','msw'));
    }
    global $wpdb;
    $seo = new ProductSEO($wpdb, $wpdb->prefix);
    $seo->create_tables();
    $seo->seed_products();
    update_option('msw_ps_last_log', __('Initial population completed','msw'));
    update_option('msw_ps_last_run', current_time('mysql'));
    wp_redirect(admin_url('admin.php?page=msw-product-seo'));
    exit;
}
add_action('admin_post_msw_ps_initial','msw_ps_handle_initial');

/**
 * Manual update handler.
 */
function msw_ps_handle_manual_update() {
    if (!current_user_can('manage_options') || !check_admin_referer('msw_ps_manual_update')) {
        wp_die(__('Unauthorized','msw'));
    }
    msw_ps_run_update();
    wp_redirect(admin_url('admin.php?page=msw-product-seo'));
    exit;
}
add_action('admin_post_msw_ps_manual_update','msw_ps_handle_manual_update');

/**
 * Schedule cron based on settings.
 */
function msw_ps_schedule_event() {
    $enabled = get_option('msw_ps_auto_update');
    $interval = intval(get_option('msw_ps_interval',12));
    $hook = 'msw_ps_cron_update';
    $timestamp = wp_next_scheduled($hook);
    if ($enabled) {
        if (!$timestamp) {
            wp_schedule_event(time(), $interval >= 24 ? 'daily' : 'twicedaily', $hook);
        }
    } else {
        if ($timestamp) wp_unschedule_event($timestamp, $hook);
    }
}
add_action('update_option_msw_ps_auto_update','msw_ps_schedule_event');
add_action('update_option_msw_ps_interval','msw_ps_schedule_event');
add_action('admin_init','msw_ps_schedule_event');

/**
 * Cron callback.
 */
function msw_ps_run_update() {
    global $wpdb;
    $seo = new ProductSEO($wpdb, $wpdb->prefix);
    // Placeholder for Google Search Console API calls; insert metrics
    $metrics = [];
    // TODO: Fetch real data from GSC and populate $metrics
    $seo->update_metrics($metrics);
    update_option('msw_ps_last_log', __('Scheduled update completed','msw'));
    update_option('msw_ps_last_run', current_time('mysql'));
}
add_action('msw_ps_cron_update','msw_ps_run_update');
