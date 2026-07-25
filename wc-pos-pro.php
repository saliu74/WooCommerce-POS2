<?php
/**
 * Plugin Name: WooCommerce POS Pro (Enterprise Edition)
 * Plugin URI: https://github.com/saliu75/WooCommerce-pos-pro/
 * Description: Enterprise-grade, atomic inventory protected Point of Sale system built specifically for WooCommerce.
 * Version: 1.3.3
 * Author: Muideen Saliu
 * Author URI: https://github.com/saliu74
 * License: GPL-2.0+
 * Text Domain: wc-pos-pro
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * WC requires at least: 7.0
 * WC tests up to: 9.2
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

define( 'WC_POS_VERSION', '1.3.0' );
define( 'WC_POS_FILE', __FILE__ );
define( 'WC_POS_PATH', plugin_dir_path( __FILE__ ) );
define( 'WC_POS_URL', plugin_dir_url( __FILE__ ) );

/**
 * Autoload Classes using PSR-4 standard.
 */
require_once WC_POS_PATH . 'includes/Autoloader.php';
WCPOS\Autoloader::register();

/**
 * Main Plugin Bootstrap Class
 */
final class WC_POS_Pro {

    private static $instance = null;

    public static function get_instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_hooks();
    }

    private function init_hooks() {
        // Note: activation/deactivation hooks are registered at file-load time
        // below the class definition — they cannot fire from inside plugins_loaded.
        add_action( 'plugins_loaded', array( $this, 'init_plugin' ) );
    }

    public function activate() {
        // Ensure WooCommerce is active.
        if ( ! class_exists( 'WooCommerce' ) ) {
            deactivate_plugins( plugin_basename( WC_POS_FILE ) );
            wp_die( esc_html__( 'WooCommerce POS Pro requires WooCommerce to be installed and active.', 'wc-pos-pro' ) );
        }

        // Install Custom Database Tables.
        WCPOS\Database\Tables::create_tables();

        // Register Capabilities.
        WCPOS\Admin\Permissions::register_capabilities();

        $this->register_rewrite_rules();
        flush_rewrite_rules();
    }

    public function deactivate() {
        flush_rewrite_rules();
    }

    public function init_plugin() {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return;
        }

        // Register Rewrite Rules for /pos route.
        add_action( 'init', array( $this, 'register_rewrite_rules' ) );
        add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
        add_action( 'template_redirect', array( $this, 'handle_terminal_template' ) );

        // Initialize API Endpoints.
        WCPOS\API\REST_Server::get_instance();

        // Initialize Admin Submenu.
        if ( is_admin() ) {
            WCPOS\Admin\AdminMenu::get_instance();
        }

        // Register POS Order Hooks.
        WCPOS\Orders\SalesEngine::init_hooks();

        // Register Inventory Integrity Listeners.
        WCPOS\POS\Inventory::init_hooks();
    }

    public function register_rewrite_rules() {
        add_rewrite_rule( '^pos/?$', 'index.php?wc_pos_terminal=1', 'top' );
    }

    public function register_query_vars( $vars ) {
        $vars[] = 'wc_pos_terminal';
        return $vars;
    }

    public function handle_terminal_template() {
        // Only respond to the registered query var — drop the raw $_GET fallbacks
        // that bypassed the rewrite system and introduced an undocumented access path.
        if ( ! get_query_var( 'wc_pos_terminal' ) ) {
            return;
        }

        if ( ! is_user_logged_in() || ( ! current_user_can( 'read_private_shop_orders' ) && ! current_user_can( 'manage_woocommerce' ) ) ) {
            auth_redirect();
        }

        $template_file = WC_POS_PATH . 'templates/pos-terminal.php';
        if ( file_exists( $template_file ) ) {
            include $template_file;
            exit;
        }
    }
}

function wc_pos_pro() {
    return WC_POS_Pro::get_instance();
}

// Activation / deactivation hooks must be registered at file-load time,
// not inside a plugins_loaded callback — WordPress fires them before that hook.
register_activation_hook( WC_POS_FILE, array( wc_pos_pro(), 'activate' ) );
register_deactivation_hook( WC_POS_FILE, array( wc_pos_pro(), 'deactivate' ) );

wc_pos_pro();