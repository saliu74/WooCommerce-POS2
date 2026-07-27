<?php
/**
 * WooCommerce POS Pro Uninstall Procedure
 *
 * Ensures data integrity: never deletes WooCommerce orders or products.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// Remove custom plugin tables only if configured by store owner
$remove_data = get_option( 'wc_pos_remove_data_on_uninstall', false );

if ( $remove_data ) {
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wc_pos_registers" );
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wc_pos_shifts" );
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wc_pos_inventory_logs" );
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wc_pos_transfers" );
    // Bug fix: these two tables were added for the multi-branch feature but
    // never added here, so they'd be orphaned on a full uninstall.
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wc_pos_branches" );
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wc_pos_branch_stock" );
    // Left in place even though gift cards were removed as a feature — this
    // safely cleans up the table on any site where it was already created
    // by an earlier version of the plugin.
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wc_pos_gift_cards" );
}

delete_option( 'wc_pos_version' );
delete_option( 'wc_pos_tables_verified_version' );
