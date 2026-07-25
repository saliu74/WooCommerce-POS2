<?php
namespace WCPOS\Database;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Tables {

    public static function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // 1. Registers Table
        $table_registers = $wpdb->prefix . 'wc_pos_registers';
        $sql_registers   = "CREATE TABLE $table_registers (
            id varchar(64) NOT NULL,
            name varchar(191) NOT NULL,
            location varchar(191) DEFAULT '',
            status varchar(32) DEFAULT 'closed',
            current_shift_id varchar(64) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        // 2. Register Shifts Table
        $table_shifts = $wpdb->prefix . 'wc_pos_shifts';
        $sql_shifts   = "CREATE TABLE $table_shifts (
            id varchar(64) NOT NULL,
            register_id varchar(64) NOT NULL,
            cashier_id bigint(20) UNSIGNED NOT NULL,
            cashier_name varchar(191) NOT NULL,
            opened_at datetime NOT NULL,
            closed_at datetime DEFAULT NULL,
            opening_float decimal(12,2) NOT NULL DEFAULT 0.00,
            expected_cash decimal(12,2) DEFAULT 0.00,
            actual_cash decimal(12,2) DEFAULT 0.00,
            cash_difference decimal(12,2) DEFAULT 0.00,
            total_sales decimal(12,2) DEFAULT 0.00,
            cash_sales decimal(12,2) DEFAULT 0.00,
            card_sales decimal(12,2) DEFAULT 0.00,
            status varchar(32) DEFAULT 'active',
            opening_notes text DEFAULT NULL,
            closing_notes text DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY register_id (register_id),
            KEY cashier_id (cashier_id)
        ) $charset_collate;";

        // 3. Inventory Logs Table (Audit Trail)
        $table_logs = $wpdb->prefix . 'wc_pos_inventory_logs';
        $sql_logs   = "CREATE TABLE $table_logs (
            id varchar(64) NOT NULL,
            product_id bigint(20) UNSIGNED NOT NULL,
            variation_id bigint(20) UNSIGNED DEFAULT 0,
            product_name varchar(255) NOT NULL,
            sku varchar(100) DEFAULT '',
            action varchar(50) NOT NULL,
            quantity_change int(11) NOT NULL,
            previous_stock int(11) NOT NULL,
            new_stock int(11) NOT NULL,
            reference_id varchar(64) DEFAULT '',
            user_id bigint(20) UNSIGNED NOT NULL,
            user_name varchar(191) NOT NULL,
            register_id varchar(64) DEFAULT '',
            reason text NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY product_id (product_id),
            KEY action (action),
            KEY created_at (created_at)
        ) $charset_collate;";

        // 4. Stock Transfers Table
        $table_transfers = $wpdb->prefix . 'wc_pos_transfers';
        $sql_transfers   = "CREATE TABLE $table_transfers (
            id varchar(64) NOT NULL,
            source_location varchar(191) NOT NULL,
            destination_location varchar(191) NOT NULL,
            product_id bigint(20) UNSIGNED NOT NULL,
            sku varchar(100) DEFAULT '',
            quantity int(11) NOT NULL,
            status varchar(32) DEFAULT 'pending',
            requested_by varchar(191) NOT NULL,
            approved_by varchar(191) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            completed_at datetime DEFAULT NULL,
            notes text DEFAULT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        // 5. Gift Cards Table
        $table_gift_cards = $wpdb->prefix . 'wc_pos_gift_cards';
        $sql_gift_cards   = "CREATE TABLE $table_gift_cards (
            id varchar(64) NOT NULL,
            code varchar(100) NOT NULL,
            initial_balance decimal(12,2) NOT NULL,
            current_balance decimal(12,2) NOT NULL,
            status varchar(32) DEFAULT 'active',
            expiry_date datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY code (code)
        ) $charset_collate;";

        // 6. POS Tax Rates Table — store-owner-defined POS-specific rates that
        //    supplement or override the default WooCommerce tax table.
        $table_tax_rates = $wpdb->prefix . 'wc_pos_tax_rates';
        $sql_tax_rates   = "CREATE TABLE $table_tax_rates (
            id int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            name varchar(191) NOT NULL,
            rate decimal(10,4) NOT NULL DEFAULT 0.0000,
            is_inclusive tinyint(1) NOT NULL DEFAULT 0,
            applies_to varchar(32) NOT NULL DEFAULT 'all',
            priority int(11) NOT NULL DEFAULT 1,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY is_active (is_active)
        ) $charset_collate;";

        dbDelta( $sql_registers );
        dbDelta( $sql_shifts );
        dbDelta( $sql_logs );
        dbDelta( $sql_transfers );
        dbDelta( $sql_gift_cards );
        dbDelta( $sql_tax_rates );
    }
}
