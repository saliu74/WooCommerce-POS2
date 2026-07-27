<?php
/**
 * Plugin Name: WooCommerce POS Pro (Enterprise Edition)
 * Plugin URI: https://github.com/saliu75/WooCommerce-pos-pro/
 * Description: Enterprise-grade, atomic inventory protected Point of Sale system built specifically for WooCommerce.
 * Version: 1.5.1
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

define( 'WC_POS_VERSION', '1.5.1' );
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

        // Seed a "default" branch so registers/shifts/orders that default to
        // branch_id = 'default' (the schema default) point at a real row
        // instead of an orphaned reference — this matters for single-location
        // stores that never explicitly create a branch of their own.
        $this->seed_default_branch();

        // Register Capabilities.
        WCPOS\Admin\Permissions::register_capabilities();

        $this->register_rewrite_rules();
        flush_rewrite_rules();
    }

    /**
     * Insert a "default" branch row if one doesn't already exist. Idempotent —
     * safe to call on every activation (e.g. after a deactivate/reactivate).
     */
    private function seed_default_branch() {
        global $wpdb;
        $table = $wpdb->prefix . 'wc_pos_branches';

        $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %s", 'default' ) );
        if ( $exists ) {
            return;
        }

        $wpdb->insert( $table, array(
            'id'     => 'default',
            'name'   => __( 'Main Branch', 'wc-pos-pro' ),
            'status' => 'active',
        ) );
    }

    /**
     * Cheap existence check so init_plugin() doesn't error out on a site
     * where the plugin was just updated but hasn't been reactivated yet
     * (i.e. the branches table may not exist for a brief window).
     */
    private function table_exists( $table_name ) {
        global $wpdb;
        $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
        return $found === $table_name;
    }

    public function deactivate() {
        flush_rewrite_rules();
    }

    public function init_plugin() {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return;
        }

        global $wpdb;

        // Register Rewrite Rules for /pos route.
        add_action( 'init', array( $this, 'register_rewrite_rules' ) );
        add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
        add_action( 'template_redirect', array( $this, 'handle_terminal_template' ) );

        // Initialize API Endpoints.
        WCPOS\API\REST_Server::get_instance();

        // Initialize Branch Management API. (Previously defined but never
        // instantiated — its REST routes were unreachable/404 until this call.)
        WCPOS\API\Branches_Controller::init();

        // Self-healing table check. activate() runs Tables::create_tables()
        // once, but dbDelta() can occasionally fail to create a table on some
        // hosting setups without throwing a visible error — as happened here
        // with wc_pos_branches. Rather than require a manual deactivate/
        // reactivate to retry, re-verify all expected tables whenever the
        // plugin version changes (covers a fresh activation gap immediately,
        // and re-checks again on every future update) and recreate anything
        // missing. dbDelta() is always safe to re-run — it won't touch
        // existing data, only creates what's missing or brings schema in line.
        $verified_version = get_option( 'wc_pos_tables_verified_version', '' );
        if ( $verified_version !== WC_POS_VERSION ) {
            $expected_tables = array(
                'wc_pos_branches',
                'wc_pos_branch_stock',
                'wc_pos_registers',
                'wc_pos_shifts',
                'wc_pos_inventory_logs',
                'wc_pos_transfers',
                'wc_pos_tax_rates',
            );
            $missing = array();
            foreach ( $expected_tables as $t ) {
                if ( ! $this->table_exists( $wpdb->prefix . $t ) ) {
                    $missing[] = $t;
                }
            }
            if ( ! empty( $missing ) ) {
                WCPOS\Database\Tables::create_tables();
            }
            update_option( 'wc_pos_tables_verified_version', WC_POS_VERSION );
        }

        // Belt-and-braces: also seed the default branch here, not just in
        // activate(). This plugin may already be active on existing sites,
        // where activate() won't re-fire just because this code was added —
        // it only runs on a fresh activation or a manual deactivate/reactivate.
        // seed_default_branch() is idempotent (one indexed lookup), so this
        // is safe to call on every load.
        if ( $this->table_exists( $wpdb->prefix . 'wc_pos_branches' ) ) {
            $this->seed_default_branch();
        }

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

        if ( ! is_user_logged_in() || ( ! current_user_can( 'process_wc_pos_sales' ) && ! current_user_can( 'read_private_shop_orders' ) && ! current_user_can( 'manage_woocommerce' ) ) ) {
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