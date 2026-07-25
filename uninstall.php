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
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wc_pos_gift_cards" );
}

delete_option( 'wc_pos_version' );
