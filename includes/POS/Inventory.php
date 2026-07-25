<?php
namespace WCPOS\POS;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Inventory {

    public static function init_hooks() {
        // Stock deduction is handled entirely by reduce_stock_atomic() below.
        // We prevent WooCommerce from deducting stock a second time when a POS
        // order transitions to completed status.
        add_filter( 'woocommerce_prevent_adjust_line_item_product_stock', '__return_true' );
    }

    /**
     * Atomically reduces product stock with database row locking.
     * Prevents race conditions during simultaneous sales.
     */
    public static function reduce_stock_atomic( $product_id, $quantity, $order_id, $user_id, $user_name, $register_id, $reason = 'POS Sale' ) {
        global $wpdb;

        if ( $quantity <= 0 ) {
            return new \WP_Error( 'invalid_qty', __( 'Stock deduction quantity must be greater than zero.', 'wc-pos-pro' ) );
        }

        $wpdb->query( 'START TRANSACTION' );

        try {
            // Pessimistic Lock on WooCommerce stock meta.
            $post_meta_table = $wpdb->postmeta;
            $stock_row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT meta_value FROM {$post_meta_table} WHERE post_id = %d AND meta_key = '_stock' FOR UPDATE",
                    $product_id
                )
            );

            if ( ! $stock_row ) {
                $wpdb->query( 'ROLLBACK' );
                return new \WP_Error( 'missing_stock', __( 'Product stock record not found.', 'wc-pos-pro' ) );
            }

            $current_stock = (int) $stock_row->meta_value;

            // Check stock sufficiency.
            $product = wc_get_product( $product_id );
            if ( $product && $product->managing_stock() && ! $product->backorders_allowed() ) {
                if ( $current_stock < $quantity ) {
                    $wpdb->query( 'ROLLBACK' );
                    return new \WP_Error(
                        'insufficient_stock',
                        sprintf( __( 'Insufficient stock for product %s. Available: %d, Requested: %d', 'wc-pos-pro' ), $product->get_name(), $current_stock, $quantity )
                    );
                }
            }

            $new_stock = $current_stock - $quantity;

            // Update stock directly via $wpdb to stay inside the transaction.
            // Using wc_update_product_stock() here would trigger WC hooks that can
            // issue their own queries and implicitly commit the transaction.
            $updated = $wpdb->update(
                $wpdb->postmeta,
                array( 'meta_value' => $new_stock ),
                array( 'post_id' => $product_id, 'meta_key' => '_stock' ),
                array( '%d' ),
                array( '%d', '%s' )
            );

            if ( false === $updated ) {
                $wpdb->query( 'ROLLBACK' );
                return new \WP_Error( 'stock_update_failed', __( 'Failed to update product stock.', 'wc-pos-pro' ) );
            }

            // Determine whether $product_id is a variation or a simple product,
            // so the audit log captures the correct parent/variation IDs.
            $is_variation  = $product && $product->is_type( 'variation' );
            $log_product_id   = $is_variation ? $product->get_parent_id() : $product_id;
            $log_variation_id = $is_variation ? $product_id : 0;

            // Record Immutable Inventory Audit Log.
            $log_table = $wpdb->prefix . 'wc_pos_inventory_logs';
            $wpdb->insert(
                $log_table,
                array(
                    'id'               => 'INV-' . wp_generate_uuid4(),
                    'product_id'       => $log_product_id,
                    'variation_id'     => $log_variation_id,
                    'product_name'     => $product ? $product->get_name() : 'Unknown Product',
                    'sku'              => $product ? $product->get_sku() : '',
                    'action'           => 'sale',
                    'quantity_change'  => -$quantity,
                    'previous_stock'   => $current_stock,
                    'new_stock'        => $new_stock,
                    'reference_id'     => $order_id,
                    'user_id'          => $user_id,
                    'user_name'        => $user_name,
                    'register_id'      => $register_id,
                    'reason'           => $reason,
                    'created_at'       => current_time( 'mysql', true )
                )
            );

            $wpdb->query( 'COMMIT' );

            // Bust WC's object cache for this product so subsequent reads see the new stock.
            clean_post_cache( $product_id );
            wc_delete_product_transients( $product_id );

            return $new_stock;

        } catch ( \Exception $e ) {
            $wpdb->query( 'ROLLBACK' );
            return new \WP_Error( 'inventory_exception', $e->getMessage() );
        }
    }
}
