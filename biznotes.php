<?php

/**
 * Plugin Name:       BizNotes
 * Description:       Enhance your WooCommerce order management with the ability to add private notes exclusively visible in the admin area. This feature ensures seamless communication and coordination among multiple admins or managers, streamlining your order processing and improving overall efficiency.
 * Version:           1.0.0
 * Author:            Devnet
 * Author URI:        https://devnet.hr
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       biznotes
 * WC tested up to:   9.0
 */

namespace Devnet\BizNotes;

use Automattic\WooCommerce\Utilities\OrderUtil;

// If this file is called directly, abort.
if (!defined('ABSPATH')) {
    exit;
}


class Plugin
{
    /**
     * Instance handle
     */
    private static $__instance = null;

    private $version = '1.0.0';
    private $plugin_name = 'biznotes';

    /**
     * Constructor, actually contains nothing
     */
    private function __construct()
    {
    }

    /**
     * Instance initiator, runs setup etc.
     */
    public static function instance()
    {
        if (!is_a(self::$__instance, __CLASS__)) {
            self::$__instance = new self;
            self::$__instance->setup();
        }

        return self::$__instance;
    }

    /**
     * Runs things that would normally be in __construct
     */
    private function setup()
    {

        add_action('init', [$this, 'load_plugin_textdomain']);

        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
        add_filter('woocommerce_admin_order_preview_get_order_details', [$this, 'admin_order_preview_add_custom_meta_data'], 10, 2);

        add_action('woocommerce_admin_order_preview_start', [$this, 'biznotes']);
        add_action('wp_ajax_submit_biznotes', [$this, 'submit_biznotes']);
        add_action('wp_ajax_delete_biznotes', [$this, 'delete_biznotes']);



        if (OrderUtil::custom_orders_table_usage_is_enabled()) {
            add_filter('woocommerce_shop_order_list_table_order_css_classes', [$this, 'shop_order_row_classes_hpos'], 10, 2);
        } else {
            add_filter('post_class', [$this, 'shop_order_row_classes'], 10, 3);
        }

        add_action('before_woocommerce_init', [$this, 'hpos_compatible']);
    }

    /**
     * Load the plugin text domain for translation.
     *
     */
    public function load_plugin_textdomain()
    {
        load_plugin_textdomain('biznotes', false, dirname(plugin_basename(__FILE__)) . '/languages/');
    }

    /**
     * Admin CSS and JS
     */
    public function admin_assets($hook)
    {

        $screen = get_current_screen();

        if ('shop_order' !== $screen->post_type && 'edit' !== $screen->base) {
            return;
        }

        wp_enqueue_style($this->plugin_name, plugin_dir_url(__FILE__) . 'assets/build/admin.css',  [], $this->version, 'all');

        $script_asset_path = plugin_dir_url(__FILE__) . 'assets/build/admin.asset.php';
        $script_info       = file_exists($script_asset_path)
            ? include $script_asset_path
            : ['dependencies' => ['jquery'], 'version' => $this->version];

        wp_enqueue_script(
            $this->plugin_name,
            plugin_dir_url(__FILE__) . 'assets/build/admin.js',
            $script_info['dependencies'],
            $script_info['version'],
            true
        );

        wp_localize_script($this->plugin_name, 'devnet_biznotes_ajax', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('biznotes-nonce'),
        ]);
    }


    public function admin_order_preview_add_custom_meta_data($data, $order)
    {
        //$order->delete_meta_data('_biznotes');
        $order_biznotes = $order->get_meta('_biznotes');

        $user = wp_get_current_user();

        $data['biznotes'] = $order_biznotes;
        $data['current_user_id'] = $user->ID;
        $data['biznotes_button_label'] = esc_html__('Add Note', 'biznotes');

        return $data;
    }


    public function biznotes()
    {
?>
        <div class="biznotes-wrap">

            <div class="biznotes-list">
                <# if (data.biznotes) { #>
                    <# _.each(data.biznotes, function(note) { #>
                        <div class="biznote">
                            <# if (data.current_user_id===note.user_id) { #>
                                <span class="delete-biznote" data-id="{{note.id}}" data-order-id="{{data.data.id}}">&times</span>
                                <# } #>
                                    <div class="biznote-meta">
                                        <small class="biznote-author">{{note.user}}</small>
                                        <small class="biznote-date">{{note.date}}</small>
                                    </div>
                                    <p class="biznote-message">{{note.note}}</p>
                        </div>
                        <# }); #>
                            <# } #>
            </div>

            <div class="biznotes-input-wrap">
                <textarea class="biznote-input"></textarea>
                <div class="add-biznote" data-order-id="{{data.data.id}}">{{data.biznotes_button_label}}</div>
            </div>

        </div>
<?php
    }

    public function submit_biznotes()
    {
        check_ajax_referer('biznotes-nonce', 'security');

        $note = isset($_POST['args']['note']) ? sanitize_textarea_field($_POST['args']['note']) : '';
        $order_id = isset($_POST['args']['orderId']) ? absint($_POST['args']['orderId']) : null;

        $user = wp_get_current_user();

        // Check if the current user can edit posts
        if ($note && $user->ID !== 0 && user_can($user->ID, 'edit_posts')) {

            $display_name = $user->display_name;

            $timezone = get_option('timezone_string') ?: 'Europe/London';
            $date_format = get_option('date_format') ?: 'F j, Y';
            $time_format = get_option('time_format') ?: 'g:1 a';

            $date_time_format = $date_format . ' ' . $time_format;

            $timezone = new \DateTimeZone($timezone);
            $date = wp_date($date_time_format, null, $timezone);

            $meta_key = '_biznotes';

            $order = wc_get_order($order_id);
            $order_biznotes = $order->get_meta($meta_key);

            if (empty($order_biznotes)) {
                $order_biznotes = [];
            }

            $biznote_id = time(); //$this->generate_id();

            $new_biznote = [
                'id'       => $biznote_id,
                'note'     => $note,
                'user'     => $display_name,
                'user_id'  => $user->ID,
                'date'     => $date,
                'order_id' => $order_id,
                //'color'   => $this->generate_color_hex_from_string($display_name),
            ];

            $order_biznotes[$biznote_id] = $new_biznote;

            $order->update_meta_data($meta_key, $order_biznotes);
            $order->save();

            wp_send_json(['status' => 'success', 'data' => $new_biznote]);
        } else {
            // The current user cannot edit posts, send an error response
            wp_send_json_error(['message' => esc_html__('You do not have permission to submit biznotes.', 'biznotes')]);
        }

        wp_die();
    }

    public function delete_biznotes()
    {
        check_ajax_referer('biznotes-nonce', 'security');

        $id = isset($_POST['args']['id']) ? absint($_POST['args']['id']) : null;
        $order_id = isset($_POST['args']['orderId']) ? absint($_POST['args']['orderId']) : null;

        $user = wp_get_current_user();

        // Check if the current user can edit posts
        if ($user->ID !== 0 && user_can($user->ID, 'edit_posts')) {

            $meta_key = '_biznotes';

            $order = wc_get_order($order_id);
            $order_biznotes = $order->get_meta($meta_key);

            unset($order_biznotes[$id]);

            $order->update_meta_data($meta_key, $order_biznotes);
            $order->save();

            wp_send_json(['status' => 'success']);
        } else {
            // The current user cannot edit posts, send an error response
            wp_send_json_error(['message' => esc_html__('You do not have permission to delete biznotes.', 'biznotes')]);
        }

        wp_die();
    }


    public function generate_id()
    {
        $timestamp = time() % 10000; // Get the current timestamp modulo 10000 to ensure it's four digits
        $unique_part = uniqid(); // Get a unique identifier

        $short_id = str_pad($timestamp, 4, '0', STR_PAD_LEFT) . substr($unique_part, 0, 4);

        return $short_id;
    }

    function generate_color_hex_from_string($text)
    {

        $hash = md5($text);

        $color_hex = substr($hash, 0, 6);

        return '#' . $color_hex;
    }

    public function shop_order_row_classes_hpos($css_classes, $order)
    {

        if ($order->get_meta('_biznotes')) {
            $css_classes[] = 'has-biznotes';
        }

        return $css_classes;
    }

    public function shop_order_row_classes($classes, $class, $post_id)
    {

        if (!is_admin()) { //make sure we are in the dashboard 
            return $classes;
        }

        $screen = get_current_screen(); //verify which page we're on

        if ($screen && 'shop_order' === $screen->post_type && 'edit' === $screen->base) {

            $order = wc_get_order($post_id);

            if ($order->get_meta('_biznotes')) {

                $classes[] = 'has-biznotes';
            }
        }

        return $classes;
    }

    /**
     * Declare that plugin is hpos compatible.
     * 
     */
    public function hpos_compatible()
    {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {

            $plugin_file = plugin_dir_path(dirname(__FILE__)) . 'biznotes.php';

            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', $plugin_file, true);
        }
    }
}

add_action('plugins_loaded', function () {

    if (!function_exists('is_plugin_active')) {
        include_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    // Go ahead only if WooCommerce is activated
    if (is_plugin_active('woocommerce/woocommerce.php')) {
        Plugin::instance();
    } else {
        // no woocommerce :(
        add_action('admin_notices', function () {
            $class   = 'notice notice-error';
            $message = esc_html__('The “BizNotes” plugin cannot run without WooCommerce. Please install and activate WooCommerce plugin.', 'biznotes');
            printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), esc_html($message));
        });
        return;
    }
});
