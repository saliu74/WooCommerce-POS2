<?php
namespace WCPOS\Orders;

use WCPOS\POS\Inventory;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SalesEngine {

    public static function init_hooks() {
        // Orders now land in "processing" rather than auto-completing (see
        // create_pos_order()), so the post-sale note/hook fires on that
        // transition instead of on "completed".
        add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'on_order_reached_processing' ) );

        // Bug fix (#3): surface the POS terminal reference on the admin order
        // screen so office staff can see it without digging through order meta.
        add_action( 'woocommerce_admin_order_data_after_billing_address', array( __CLASS__, 'render_terminal_reference_admin' ) );
    }

    /**
     * Show the captured POS terminal/payment reference on the wp-admin order
     * edit screen, just under the billing address block.
     *
     * @param \WC_Order $order
     */
    public static function render_terminal_reference_admin( $order ) {
        $ref = $order->get_meta( '_wc_pos_terminal_reference' );
        if ( $ref ) {
            echo '<p><strong>' . esc_html__( 'POS Terminal Reference:', 'wc-pos-pro' ) . '</strong><br>' . esc_html( $ref ) . '</p>';
        }
    }

    public static function create_pos_order( $payload ) {
        // Multi-branch feature build-out: which branch this sale belongs to.
        // Defaults to the seeded "default" branch so existing single-location
        // callers (or any request sent before the frontend has a branch
        // selector) keep working unchanged.
        $branch_id = sanitize_text_field( $payload['branchId'] ?? 'default' );

        // --- Input validation ---
        if ( empty( $payload['items'] ) || ! is_array( $payload['items'] ) ) {
            return new \WP_Error( 'empty_cart', __( 'Cannot process order with empty items list.', 'wc-pos-pro' ) );
        }

        // Enforce a sane maximum to prevent runaway inserts.
        if ( count( $payload['items'] ) > 200 ) {
            return new \WP_Error( 'too_many_items', __( 'Order exceeds maximum item count of 200.', 'wc-pos-pro' ) );
        }

        foreach ( $payload['items'] as $idx => $item_data ) {
            $qty = intval( $item_data['quantity'] ?? 0 );
            if ( $qty <= 0 || $qty > 9999 ) {
                return new \WP_Error(
                    'invalid_quantity',
                    sprintf( __( 'Item #%d has an invalid quantity (%d). Must be 1–9999.', 'wc-pos-pro' ), $idx + 1, $qty )
                );
            }
            $unit_price = floatval( $item_data['unitPrice'] ?? -1 );
            if ( $unit_price < 0 ) {
                return new \WP_Error(
                    'invalid_price',
                    sprintf( __( 'Item #%d has a missing or negative unit price.', 'wc-pos-pro' ), $idx + 1 )
                );
            }
            if ( empty( $item_data['productId'] ) || intval( $item_data['productId'] ) <= 0 ) {
                return new \WP_Error(
                    'invalid_product',
                    sprintf( __( 'Item #%d is missing a valid product ID.', 'wc-pos-pro' ), $idx + 1 )
                );
            }
        }

        // --- Idempotency check: prevent duplicate order submission ---
        $idempotency_key = sanitize_text_field( $payload['idempotencyKey'] ?? '' );
        if ( $idempotency_key ) {
            $existing_orders = wc_get_orders( array(
                'meta_key'   => '_wc_pos_idempotency_key',
                'meta_value' => $idempotency_key,
                'limit'      => 1,
            ) );
            if ( ! empty( $existing_orders ) ) {
                return $existing_orders[0];
            }
        }

        // --- Bug fix (#1): hard stop on out-of-stock items BEFORE any order is
        // created. Previously, stock was only checked inside
        // Inventory::reduce_stock_atomic() *after* wc_create_order() already
        // ran, which meant every rejected sale left behind a cancelled order
        // record, and the check only looked at quantity — never at an
        // explicitly-set 'outofstock' stock_status. This checks both, for
        // every line item, and rejects the whole order up front if any item
        // fails, with no order created and no stock touched.
        $stock_check_errors = array();
        foreach ( $payload['items'] as $item_data ) {
            $product_id   = intval( $item_data['productId'] );
            $variation_id = intval( $item_data['variationId'] ?? 0 );
            $target_id    = $variation_id ? $variation_id : $product_id;
            $quantity     = intval( $item_data['quantity'] ?? 0 );

            $product = wc_get_product( $target_id );
            if ( ! $product ) {
                $stock_check_errors[] = sprintf(
                    __( 'Product ID %d could not be found.', 'wc-pos-pro' ),
                    $target_id
                );
                continue;
            }

            // Explicit stock_status check — catches items manually marked
            // "Out of stock" even if a stale _stock quantity is still positive.
            if ( 'outofstock' === $product->get_stock_status() ) {
                $stock_check_errors[] = sprintf(
                    __( '"%1$s" is out of stock. Contact the inventory manager before selling this item.', 'wc-pos-pro' ),
                    $product->get_name()
                );
                continue;
            }

            if ( 'onbackorder' === $product->get_stock_status() && ! $product->backorders_allowed() ) {
                $stock_check_errors[] = sprintf(
                    __( '"%1$s" is on backorder and cannot be sold at POS.', 'wc-pos-pro' ),
                    $product->get_name()
                );
                continue;
            }

            // Quantity check for stock-managed products.
            if ( $product->managing_stock() && ! $product->backorders_allowed() ) {
                $available = $product->get_stock_quantity();
                if ( $available === null || $available < $quantity ) {
                    $stock_check_errors[] = sprintf(
                        __( '"%1$s" has insufficient stock. Available: %2$d, Requested: %3$d.', 'wc-pos-pro' ),
                        $product->get_name(),
                        (int) $available,
                        $quantity
                    );
                    continue;
                }
            }

            // Multi-branch: if this product has branch-specific stock
            // allocated, also pre-check that count. This is an early,
            // non-locking estimate for a fast fail/good error message —
            // Inventory::reduce_stock_atomic() below still does the
            // authoritative, row-locked check at deduction time, so a race
            // between this check and the actual sale can't oversell.
            $branch_check = self::check_branch_stock( $product, $branch_id, $quantity );
            if ( is_wp_error( $branch_check ) ) {
                $stock_check_errors[] = $branch_check->get_error_message();
            }
        }

        if ( ! empty( $stock_check_errors ) ) {
            return new \WP_Error( 'out_of_stock', implode( ' | ', $stock_check_errors ) );
        }

        // --- Enforce an open register shift before allowing any sale ---
        $register_id = sanitize_text_field( $payload['registerId'] ?? 'REG-MAIN' );
        $shift_check = self::require_open_shift( $register_id );
        if ( is_wp_error( $shift_check ) ) {
            return $shift_check;
        }

        // --- Server-side discount authorization ---
        // Any non-zero discount requires the account processing the sale to
        // hold 'override_wc_pos_prices' (or 'manage_woocommerce'). The
        // terminal's "manager PIN" confirmation is a UI friction step for the
        // currently logged-in account — it does not establish a separate
        // manager identity — so the real authorization boundary has to be
        // this capability check, not the PIN alone.
        $discount_check = self::check_discount_authorization( $payload['items'] );
        if ( is_wp_error( $discount_check ) ) {
            return $discount_check;
        }

        $order = wc_create_order( array(
            'status'      => 'pending',   // Start pending; move to completed after stock is confirmed.
            'customer_id' => intval( $payload['customerId'] ?? 0 ),
            'created_via' => 'wc_pos_pro',
        ) );

        if ( is_wp_error( $order ) ) {
            return $order;
        }

        // --- Add line items and reduce stock atomically ---
        $stock_errors = array();

        foreach ( $payload['items'] as $item_data ) {
            $product_id   = intval( $item_data['productId'] );
            $variation_id = intval( $item_data['variationId'] ?? 0 );
            $target_id    = $variation_id ? $variation_id : $product_id;
            $quantity     = intval( $item_data['quantity'] );
            $unit_price   = floatval( $item_data['unitPrice'] );
            $discount     = floatval( $item_data['discountTotal'] ?? 0 );

            $product = wc_get_product( $target_id );
            if ( ! $product ) {
                // Unknown product — cancel the whole order and report the problem.
                $order->update_status( 'cancelled', __( 'POS: product not found, order auto-cancelled.', 'wc-pos-pro' ) );
                return new \WP_Error(
                    'product_not_found',
                    sprintf( __( 'Product ID %d could not be found.', 'wc-pos-pro' ), $target_id )
                );
            }

            $order->add_product( $product, $quantity, array(
                'subtotal' => $unit_price * $quantity,
                'total'    => ( $unit_price * $quantity ) - $discount,
            ) );

            // Attempt atomic stock deduction.
            $stock_result = Inventory::reduce_stock_atomic(
                $target_id,
                $quantity,
                sanitize_text_field( $payload['id'] ?? ( 'ORD-' . $order->get_id() ) ),
                intval( $payload['cashierId'] ?? 0 ),
                sanitize_text_field( $payload['cashierName'] ?? 'POS' ),
                sanitize_text_field( $payload['registerId'] ?? 'REG-MAIN' ),
                'POS Sale',
                $branch_id
            );

            if ( is_wp_error( $stock_result ) ) {
                $stock_errors[] = $stock_result->get_error_message();
            }
        }

        // If any stock deduction failed, cancel the order and surface the error.
        // This prevents a "sold but not deducted" scenario.
        if ( ! empty( $stock_errors ) ) {
            $order->update_status(
                'cancelled',
                __( 'POS: stock deduction failed — order auto-cancelled.', 'wc-pos-pro' )
            );
            $order->save();
            return new \WP_Error(
                'stock_deduction_failed',
                implode( ' | ', $stock_errors )
            );
        }

        // --- Attach POS metadata ---
        $order->update_meta_data( '_wc_pos_order_id',       sanitize_text_field( $payload['id'] ?? '' ) );
        $order->update_meta_data( '_wc_pos_register_id',    sanitize_text_field( $payload['registerId'] ?? '' ) );
        $order->update_meta_data( '_wc_pos_branch_id',      $branch_id );
        $order->update_meta_data( '_wc_pos_cashier_id',     intval( $payload['cashierId'] ?? 0 ) );
        $order->update_meta_data( '_wc_pos_cashier_name',   sanitize_text_field( $payload['cashierName'] ?? '' ) );
        $order->update_meta_data( '_wc_pos_payments',       $payload['payments'] ?? array() );
        $order->update_meta_data( '_wc_pos_idempotency_key', $idempotency_key );

        // --- Bug fix (#3): capture the cashier's order note / terminal reference ---
        // Stored three ways so it's visible wherever staff might look for it:
        //  1. customer_note   — shows in the standard "Customer provided note" box
        //                       on the admin order screen and in order emails.
        //  2. _wc_pos_terminal_reference meta — queryable/reportable independent
        //                       of customer_note, and won't be clobbered if a
        //                       genuine customer note is ever added separately.
        //  3. An internal (non-customer-facing) order note in the activity log,
        //                       timestamped, for an audit trail.
        $order_note = isset( $payload['orderNote'] ) ? sanitize_textarea_field( $payload['orderNote'] ) : '';
        if ( '' !== $order_note ) {
            $order->set_customer_note( $order_note );
            $order->update_meta_data( '_wc_pos_terminal_reference', $order_note );
        }

        $order->set_payment_method( 'wc_pos_custom' );
        $order->set_payment_method_title( __( 'POS In-Person Payment', 'wc-pos-pro' ) );
        $order->calculate_totals();

        // Mark completed only after stock is confirmed deducted.
        // Move to "processing" (not "completed") once stock is confirmed
        // deducted. Completing automatically skipped the normal fulfillment/
        // review step office staff expect to see orders pass through.
        $order->update_status( 'processing' );
        $order->save();

        do_action( 'wc_pos_order_created', $order->get_id(), $payload );

        return $order;
    }

    /**
     * Multi-branch feature build-out: lightweight, non-locking pre-check of a
     * product's branch-specific stock allocation (wc_pos_branch_stock), used
     * only to fail fast with a clear error before an order is even created.
     * Products with no branch allocation for this branch are treated as
     * "not branch-tracked" and skip this check entirely — they're covered by
     * the existing global stock check already run in create_pos_order().
     * The authoritative, row-locked check happens later in
     * Inventory::reduce_stock_atomic(); this can't itself cause overselling
     * even if stock changes between this check and that one.
     */
    private static function check_branch_stock( $product, $branch_id, $quantity ) {
        global $wpdb;

        if ( ! $branch_id || ! $product->managing_stock() || $product->backorders_allowed() ) {
            return true;
        }

        $is_variation = $product->is_type( 'variation' );
        $bs_product_id   = $is_variation ? $product->get_parent_id() : $product->get_id();
        $bs_variation_id = $is_variation ? $product->get_id() : 0;

        $table = $wpdb->prefix . 'wc_pos_branch_stock';
        $row   = $wpdb->get_row( $wpdb->prepare(
            "SELECT stock_quantity FROM {$table} WHERE branch_id = %s AND product_id = %d AND variation_id = %d",
            $branch_id, $bs_product_id, $bs_variation_id
        ) );

        // No row = this product hasn't been allocated per-branch stock yet;
        // nothing further to check here.
        if ( ! $row ) {
            return true;
        }

        if ( (int) $row->stock_quantity < $quantity ) {
            return new \WP_Error(
                'insufficient_branch_stock',
                sprintf(
                    /* translators: 1: product name, 2: available branch stock, 3: requested quantity */
                    __( '"%1$s" has insufficient stock at this branch. Available: %2$d, Requested: %3$d.', 'wc-pos-pro' ),
                    $product->get_name(),
                    (int) $row->stock_quantity,
                    $quantity
                )
            );
        }

        return true;
    }

    /**
     * Hard stop: reject the sale if this register has no active shift.
     * Enforced server-side so it can't be bypassed by a stale frontend state.
     */
    private static function require_open_shift( $register_id ) {
        global $wpdb;

        $shifts_table = $wpdb->prefix . 'wc_pos_shifts';
        $open_shift   = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$shifts_table} WHERE register_id = %s AND status = 'active' LIMIT 1",
            $register_id
        ) );

        if ( ! $open_shift ) {
            return new \WP_Error(
                'shift_not_open',
                __( 'This register does not have an open shift. Open a shift before processing sales.', 'wc-pos-pro' )
            );
        }

        return true;
    }

    /**
     * Reject the order if any line item carries a discount and the account
     * processing the sale isn't authorized to apply one.
     *
     * Note on the authorization model: the terminal's "manager PIN" prompt
     * verifies the currently logged-in account's own PIN — it does not
     * establish a separate manager identity on a shared-login terminal. The
     * real authorization boundary is therefore this capability check against
     * whichever WordPress account the terminal is logged in as, with the PIN
     * step serving as deliberate-intent friction on top of it. Granting a
     * distinct manager their own login (rather than sharing the terminal's
     * account) is the way to get true per-person authorization; that's a
     * bigger change than this capability wiring and isn't done here.
     */
    private static function check_discount_authorization( $items ) {
        $has_discount = false;
        foreach ( $items as $item_data ) {
            if ( floatval( $item_data['discountTotal'] ?? 0 ) > 0 ) {
                $has_discount = true;
                break;
            }
        }

        if ( ! $has_discount ) {
            return true;
        }

        if ( ! current_user_can( 'override_wc_pos_prices' ) && ! current_user_can( 'manage_woocommerce' ) ) {
            return new \WP_Error(
                'discount_not_authorized',
                __( 'This account is not authorized to apply discounts. Ask a manager to log in or grant the override_wc_pos_prices capability.', 'wc-pos-pro' )
            );
        }

        return true;
    }

    /**
     * Fires when a POS order reaches "processing" status (POS orders no
     * longer auto-complete — see create_pos_order()).
     * Adds a timestamped order note and lets third-party code hook in.
     */
    public static function on_order_reached_processing( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        // Only act on orders created via this POS plugin.
        if ( 'wc_pos_pro' !== $order->get_created_via() ) {
            return;
        }

        $cashier_name = $order->get_meta( '_wc_pos_cashier_name' ) ?: __( 'POS Cashier', 'wc-pos-pro' );
        $register_id  = $order->get_meta( '_wc_pos_register_id' ) ?: 'REG-MAIN';

        $order->add_order_note(
            sprintf(
                /* translators: 1: cashier name, 2: register ID */
                __( 'POS sale processed by %1$s on register %2$s.', 'wc-pos-pro' ),
                $cashier_name,
                $register_id
            )
        );

        $terminal_reference = $order->get_meta( '_wc_pos_terminal_reference' );
        if ( $terminal_reference ) {
            $order->add_order_note(
                sprintf(
                    /* translators: %s: cashier-entered note/terminal reference */
                    __( 'POS terminal/payment reference recorded: %s', 'wc-pos-pro' ),
                    $terminal_reference
                ),
                0,     // private/internal note, not emailed to the customer
                false
            );
        }

        /**
         * Fires once a POS order reaches "processing" (stock deducted, sale
         * finalized). Hook name kept as wc_pos_sale_completed for backwards
         * compatibility with any existing integrations built against it.
         *
         * @param int      $order_id     WooCommerce order ID.
         * @param WC_Order $order        Order object.
         * @param string   $cashier_name Name of the cashier who processed the sale.
         * @param string   $register_id  Register identifier.
         */
        do_action( 'wc_pos_sale_completed', $order_id, $order, $cashier_name, $register_id );
    }
}
