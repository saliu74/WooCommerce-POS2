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
     *
     * @param int         $product_id   Product or variation ID being sold.
     * @param int         $quantity     Quantity sold.
     * @param string      $order_id     POS/WC order reference for the audit log.
     * @param int         $user_id      Cashier user ID.
     * @param string      $user_name    Cashier display name.
     * @param string      $register_id  Register identifier.
     * @param string      $reason       Audit log reason text.
     * @param string|null $branch_id    Multi-branch feature: optional branch this
     *                                  sale belongs to. When a wc_pos_branch_stock
     *                                  row exists for this product at this branch,
     *                                  that row is checked and deducted alongside
     *                                  the global WooCommerce stock, inside the
     *                                  same transaction/lock. Products that have
     *                                  never had branch-specific stock allocated
     *                                  fall through to the original global-only
     *                                  behavior — this is fully backward compatible
     *                                  with single-location stores.
     */
    public static function reduce_stock_atomic( $product_id, $quantity, $order_id, $user_id, $user_name, $register_id, $reason = 'POS Sale', $branch_id = null ) {
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
            $product       = wc_get_product( $product_id );

            // Determine whether $product_id is a variation or a simple product,
            // so both the audit log and branch-stock lookups use the correct
            // parent/variation ID pairing (wc_pos_branch_stock keys on the
            // parent product_id + a separate variation_id column).
            $is_variation     = $product && $product->is_type( 'variation' );
            $log_product_id   = $is_variation ? $product->get_parent_id() : $product_id;
            $log_variation_id = $is_variation ? $product_id : 0;

            // --- Multi-branch: check + lock branch-specific stock, if allocated ---
            $branch_stock_row   = null;
            $branch_stock_table = $wpdb->prefix . 'wc_pos_branch_stock';

            if ( $branch_id ) {
                $branch_stock_row = $wpdb->get_row( $wpdb->prepare(
                    "SELECT id, stock_quantity FROM {$branch_stock_table}
                     WHERE branch_id = %s AND product_id = %d AND variation_id = %d FOR UPDATE",
                    $branch_id, $log_product_id, $log_variation_id
                ) );
            }

            if ( $branch_stock_row && $product && $product->managing_stock() && ! $product->backorders_allowed() ) {
                if ( (int) $branch_stock_row->stock_quantity < $quantity ) {
                    $wpdb->query( 'ROLLBACK' );
                    return new \WP_Error(
                        'insufficient_branch_stock',
                        sprintf(
                            /* translators: 1: product name, 2: available branch stock, 3: requested quantity */
                            __( 'Insufficient stock for %1$s at this branch. Available: %2$d, Requested: %3$d', 'wc-pos-pro' ),
                            $product->get_name(),
                            (int) $branch_stock_row->stock_quantity,
                            $quantity
                        )
                    );
                }
            }

            // Check global stock sufficiency (unchanged from original behavior —
            // global stock is always deducted regardless of branch allocation,
            // since it represents the company-wide total).
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

            // Deduct the branch-specific allocation too, if one exists.
            if ( $branch_stock_row ) {
                $new_branch_stock = max( 0, (int) $branch_stock_row->stock_quantity - $quantity );
                $wpdb->update(
                    $branch_stock_table,
                    array( 'stock_quantity' => $new_branch_stock ),
                    array( 'id' => $branch_stock_row->id ),
                    array( '%d' ),
                    array( '%d' )
                );
            }

            // Record Immutable Inventory Audit Log.
            $log_table = $wpdb->prefix . 'wc_pos_inventory_logs';
            $wpdb->insert(
                $log_table,
                array(
                    'id'               => 'INV-' . wp_generate_uuid4(),
                    'branch_id'        => $branch_id ?: '',
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
