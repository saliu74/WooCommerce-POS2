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

        // --- Shift enforcement: disabled per client decision (2026-08) ---
        // The open-shift requirement was causing real sales disruption due
        // to reliability issues in the shift close flow that couldn't be
        // resolved quickly enough to justify blocking live sales on it.
        // require_open_shift() itself, the shifts table, the Shift History
        // report, and the admin Force Close tooling are all left fully
        // intact — only the hard block on create_pos_order() is removed —
        // so this can be re-enabled with a single line change later.
        $register_id = sanitize_text_field( $payload['registerId'] ?? 'REG-MAIN' );

        // --- Delivery requires an address (defense in depth — the
        // terminal already checks this before submitting) ---
        if ( 'delivery' === ( $payload['fulfillmentType'] ?? 'pickup' )
            && empty( trim( $payload['deliveryAddress'] ?? '' ) ) ) {
            return new \WP_Error(
                'delivery_address_required',
                __( 'A delivery address is required for delivery orders.', 'wc-pos-pro' )
            );
        }

        // --- Whole-order discount pre-check (separate from the per-item
        // discount feature above, which stays unchanged) ---
        // Three modes, mirroring the flexibility already offered per-item:
        //  - 'coupon': a real WooCommerce coupon code — self-authorizing
        //    (the code itself is the authorization; no manager gate needed).
        //  - 'percent' / 'fixed': an ad-hoc whole-order discount typed in at
        //    checkout — this is the same kind of arbitrary judgment call as
        //    a per-item discount, so it's held to the same authorization
        //    bar (checked together with per-item discounts below).
        // Checked before any stock is touched, so an invalid/expired/
        // exhausted coupon (or an unauthorized manual discount) can never
        // leave stock deducted with nothing to show for it.
        $order_discount = is_array( $payload['orderDiscount'] ?? null ) ? $payload['orderDiscount'] : null;
        $discount_mode   = $order_discount ? sanitize_text_field( $order_discount['mode'] ?? '' ) : '';
        $coupon_code     = ( 'coupon' === $discount_mode ) ? sanitize_text_field( $order_discount['code'] ?? '' ) : '';
        $manual_value    = ( in_array( $discount_mode, array( 'percent', 'fixed' ), true ) ) ? floatval( $order_discount['value'] ?? 0 ) : 0;

        if ( $coupon_code ) {
            $coupon_subtotal = 0.0;
            foreach ( $payload['items'] as $item_data ) {
                $coupon_subtotal += ( floatval( $item_data['unitPrice'] ) * intval( $item_data['quantity'] ) )
                    - floatval( $item_data['discountTotal'] ?? 0 );
            }
            $coupon_check = self::validate_coupon_code( $coupon_code, $coupon_subtotal );
            if ( is_wp_error( $coupon_check ) ) {
                return $coupon_check;
            }
        }

        // --- Server-side discount authorization ---
        // Any non-zero per-item discount OR a manual (percent/fixed)
        // whole-order discount requires the account processing the sale to
        // hold 'override_wc_pos_prices' (or 'manage_woocommerce'). A coupon
        // code is deliberately NOT included here — see above. The
        // terminal's "manager PIN" confirmation is a UI friction step for
        // the currently logged-in account — it does not establish a
        // separate manager identity — so the real authorization boundary
        // has to be this capability check, not the PIN alone.
        $discount_check = self::check_discount_authorization( $payload['items'], $manual_value > 0 );
        if ( is_wp_error( $discount_check ) ) {
            return $discount_check;
        }

        // Bug fix (traced by the client's activity-log plugin developer):
        // customer_id was previously stored on the order with zero
        // validation that it actually corresponds to a real WordPress user,
        // and billing name fields were never populated on any POS order at
        // all — regardless of whether a real customer was attached.
        // Together, that let an order end up with BOTH a blank billing name
        // AND a customer_id that doesn't resolve via get_user_by() — exactly
        // the combination that crashed their logging plugin, which assumes
        // any order with a truthy customer_id has a real, resolvable user
        // behind it. True walk-in sales (no customer selected at all) are
        // unaffected either way, since their customer_id is already 0.
        $customer_id = intval( $payload['customerId'] ?? 0 );
        $customer    = $customer_id ? get_user_by( 'id', $customer_id ) : false;
        if ( $customer_id && ! $customer ) {
            // Stale/invalid reference (e.g. the customer account was
            // deleted after being selected) — fall back to guest rather
            // than store a dangling ID.
            $customer_id = 0;
        }

        $order = wc_create_order( array(
            'status'      => 'pending',   // Start pending; move to completed after stock is confirmed.
            'customer_id' => $customer_id,
            'created_via' => 'wc_pos_pro',
        ) );

        if ( is_wp_error( $order ) ) {
            return $order;
        }

        if ( $customer ) {
            $first_name = get_user_meta( $customer->ID, 'first_name', true ) ?: $customer->display_name;
            $last_name  = get_user_meta( $customer->ID, 'last_name', true );
            $order->set_billing_first_name( $first_name );
            $order->set_billing_last_name( $last_name );
            // The POS auto-generates a placeholder @pos.local email for
            // walk-ins with no email given — don't carry that onto the
            // order's billing email as if it were real.
            if ( $customer->user_email && false === strpos( $customer->user_email, '@pos.local' ) ) {
                $order->set_billing_email( $customer->user_email );
            }
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

        // Bug fix: WooCommerce's own core stock-reduction hook
        // (wc_maybe_reduce_stock_levels) fires automatically on certain
        // order status transitions — including pending -> processing, which
        // is exactly the transition this order goes through a few lines
        // below. Core has no way to know Inventory::reduce_stock_atomic()
        // above already reduced stock directly via SQL, so without this it
        // silently reduces the SAME stock a second time — a single sale of
        // 1 unit was dropping stock by 2. Marking stock as already reduced
        // here (the same flag core itself sets after its own reduction path)
        // tells that hook to skip, since we've already handled it.
        $order->get_data_store()->set_stock_reduced( $order->get_id(), true );

        // --- Attach POS metadata ---
        $order->update_meta_data( '_wc_pos_order_id',       sanitize_text_field( $payload['id'] ?? '' ) );
        $order->update_meta_data( '_wc_pos_register_id',    sanitize_text_field( $payload['registerId'] ?? '' ) );
        $order->update_meta_data( '_wc_pos_branch_id',      $branch_id );
        $order->update_meta_data( '_wc_pos_cashier_id',     intval( $payload['cashierId'] ?? 0 ) );
        $order->update_meta_data( '_wc_pos_cashier_name',   sanitize_text_field( $payload['cashierName'] ?? '' ) );
        $order->update_meta_data( '_wc_pos_payments',       $payload['payments'] ?? array() );
        $order->update_meta_data( '_wc_pos_idempotency_key', $idempotency_key );

        // Delivery feature: the terminal UI (Pickup/Delivery toggle +
        // address field) already existed but was never actually wired to
        // anything server-side — the address was captured in the browser
        // and then silently discarded on submit. Stored as dedicated meta,
        // and — for delivery orders — also written to WooCommerce's normal
        // shipping-address field so it shows up in the standard admin
        // order screen without needing a custom admin UI.
        $fulfillment_type = in_array( $payload['fulfillmentType'] ?? 'pickup', array( 'pickup', 'delivery' ), true )
            ? $payload['fulfillmentType']
            : 'pickup';
        $order->update_meta_data( '_wc_pos_fulfillment_type', $fulfillment_type );

        if ( 'delivery' === $fulfillment_type ) {
            $delivery_address = sanitize_textarea_field( $payload['deliveryAddress'] ?? '' );
            $order->update_meta_data( '_wc_pos_delivery_address', $delivery_address );
            if ( $delivery_address ) {
                $order->set_shipping_address_1( $delivery_address );
            }

            // Delivery fee: added as a positive fee line item (the same
            // mechanism used for the manual whole-order discount, just
            // adding to the total instead of subtracting from it) — there
            // was previously no way to charge a delivery fee at all, only
            // capture the address, which meant deliveries to more distant
            // zones couldn't be charged for at the point of sale.
            $delivery_fee = floatval( $payload['deliveryFee'] ?? 0 );
            if ( $delivery_fee > 0 ) {
                $fee = new \WC_Order_Item_Fee();
                $fee->set_name( __( 'Delivery Fee', 'wc-pos-pro' ) );
                $fee->set_amount( $delivery_fee );
                $fee->set_total( $delivery_fee );
                $fee->set_tax_status( 'none' );
                $order->add_item( $fee );
                $order->update_meta_data( '_wc_pos_delivery_fee', $delivery_fee );
            }
        }

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

        // Bug fix: this previously always set the same generic payment
        // method/title regardless of what the cashier actually selected —
        // Cash, Card, and Bank Transfer all showed identically, and since
        // WooCommerce didn't recognize the fixed slug used here, most order
        // views displayed it simply as "Other" with no indication of the
        // real method used. Now derived from the actual payments array.
        $payment_info = self::derive_payment_method_info( $payload['payments'] ?? array() );
        $order->set_payment_method( $payment_info['slug'] );
        $order->set_payment_method_title( $payment_info['title'] );

        // Apply the whole-order discount now that all line items exist —
        // WooCommerce calculates the actual amounts against whatever's on
        // the order when calculate_totals() runs next, so this must happen
        // before that call.
        if ( $coupon_code ) {
            $coupon_applied = $order->apply_coupon( $coupon_code );
            if ( is_wp_error( $coupon_applied ) ) {
                // Stock has already been deducted at this point. Rather than
                // reversing it (a real risk of its own) over what should be
                // an extremely rare race, complete the sale without the
                // coupon and leave a clear, auditable note rather than
                // silently dropping it.
                $order->add_order_note( sprintf(
                    /* translators: 1: coupon code, 2: error message */
                    __( 'Coupon "%1$s" could not be applied at final checkout and was skipped: %2$s', 'wc-pos-pro' ),
                    $coupon_code,
                    $coupon_applied->get_error_message()
                ) );
            }
        } elseif ( $manual_value > 0 && in_array( $discount_mode, array( 'percent', 'fixed' ), true ) ) {
            // Manual whole-order percentage/fixed discount — there's no
            // WooCommerce coupon entity behind this, so it's applied as a
            // negative fee line item (the standard WC approach for an
            // ad-hoc order-level adjustment). Already authorized above via
            // check_discount_authorization().
            $order_subtotal = 0.0;
            foreach ( $order->get_items() as $line_item ) {
                $order_subtotal += (float) $line_item->get_total();
            }

            if ( 'percent' === $discount_mode ) {
                $discount_amount = $order_subtotal * ( min( $manual_value, 100 ) / 100 );
                $fee_label       = sprintf(
                    /* translators: %s: discount percentage */
                    __( 'Order Discount (%s%%)', 'wc-pos-pro' ),
                    $manual_value
                );
            } else {
                $discount_amount = $manual_value;
                $fee_label       = __( 'Order Discount (Fixed)', 'wc-pos-pro' );
            }

            // Never let a fixed discount exceed what's actually in the cart.
            $discount_amount = min( $discount_amount, $order_subtotal );

            if ( $discount_amount > 0 ) {
                $fee = new \WC_Order_Item_Fee();
                $fee->set_name( $fee_label );
                $fee->set_amount( -$discount_amount );
                $fee->set_total( -$discount_amount );
                $fee->set_tax_status( 'none' );
                $order->add_item( $fee );
            }
        }

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
     * Hard stop: reject the sale if this register has no active shift, OR
     * if its active shift is stale (left open from a previous day, past the
     * 8:00 AM cutoff for today). Enforced server-side so it can't be
     * bypassed by a stale frontend state.
     *
     * Bug fix: previously this only checked that SOME active shift existed
     * — indefinitely. That meant a shift opened once and never closed would
     * keep authorizing sales forever, so a cashier who simply never bothered
     * to open a fresh shift each morning never hit any block at all (the
     * "already open" gate at shift-OPEN time only fires if someone actually
     * tries to open a new one). Now checked at the point of SALE too: once
     * it's 8:00 AM local time on any day after the shift was opened, that
     * shift no longer authorizes new sales — it must be closed (and a new
     * one opened for today) before selling can continue.
     */
    private static function require_open_shift( $register_id ) {
        global $wpdb;

        $shifts_table = $wpdb->prefix . 'wc_pos_shifts';
        $shift        = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, opened_at FROM {$shifts_table} WHERE register_id = %s AND status = 'active' LIMIT 1",
            $register_id
        ) );

        if ( ! $shift ) {
            return new \WP_Error(
                'shift_not_open',
                __( 'This register does not have an open shift. Open a shift before processing sales.', 'wc-pos-pro' )
            );
        }

        $opened_at_local   = get_date_from_gmt( $shift->opened_at, 'Y-m-d H:i:s' );
        $opened_date_local = substr( $opened_at_local, 0, 10 );
        $today_local       = current_time( 'Y-m-d' );

        if ( $opened_date_local !== $today_local ) {
            $eight_am_today = strtotime( $today_local . ' 08:00:00' );
            if ( current_time( 'timestamp' ) >= $eight_am_today ) {
                return new \WP_Error(
                    'stale_shift',
                    sprintf(
                        /* translators: %s: date/time the stale shift was opened */
                        __( 'The shift from %s is still open. It must be closed, and a new shift opened for today, before any further sales can be made.', 'wc-pos-pro' ),
                        date_i18n( 'M j, g:i A', strtotime( $opened_at_local ) )
                    )
                );
            }
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
    private static function check_discount_authorization( $items, $has_manual_order_discount = false ) {
        $has_discount = $has_manual_order_discount;
        if ( ! $has_discount ) {
            foreach ( $items as $item_data ) {
                if ( floatval( $item_data['discountTotal'] ?? 0 ) > 0 ) {
                    $has_discount = true;
                    break;
                }
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
     * Map the terminal's payments array (each entry like
     * { method: 'cash'|'card'|'transfer', amount: 12.5 }) to a proper
     * WooCommerce payment method slug + human-readable title, so orders
     * actually reflect what the cashier used instead of a fixed generic
     * value that WooCommerce doesn't recognize and displays as "Other."
     * A split payment lists every method actually used, e.g.
     * "Split (Cash + Card)".
     */
    private static function derive_payment_method_info( $payments ) {
        $labels = array(
            'cash'     => __( 'Cash', 'wc-pos-pro' ),
            'card'     => __( 'Card', 'wc-pos-pro' ),
            'transfer' => __( 'Bank Transfer', 'wc-pos-pro' ),
        );

        $fallback = array( 'slug' => 'wc_pos_custom', 'title' => __( 'POS Sale', 'wc-pos-pro' ) );

        if ( ! is_array( $payments ) || empty( $payments ) ) {
            return $fallback;
        }

        $methods_used = array();
        foreach ( $payments as $payment ) {
            $method = $payment['method'] ?? '';
            if ( isset( $labels[ $method ] ) && ! in_array( $method, $methods_used, true ) ) {
                $methods_used[] = $method;
            }
        }

        if ( empty( $methods_used ) ) {
            return $fallback;
        }

        if ( 1 === count( $methods_used ) ) {
            return array(
                'slug'  => 'wc_pos_' . $methods_used[0],
                'title' => $labels[ $methods_used[0] ],
            );
        }

        $used_labels = array_map( function ( $method ) use ( $labels ) {
            return $labels[ $method ];
        }, $methods_used );

        return array(
            'slug'  => 'wc_pos_split',
            'title' => sprintf(
                /* translators: %s: payment methods used in the split, e.g. "Cash + Card" */
                __( 'Split (%s)', 'wc-pos-pro' ),
                implode( ' + ', $used_labels )
            ),
        );
    }

    /**
     * Validate a coupon code against the core checks WooCommerce itself
     * applies (existence, status, expiry, usage limit, min/max spend).
     * Shared between the pre-flight check in create_pos_order() and
     * REST_Server's coupon preview endpoint, so the "does this coupon look
     * valid" logic only lives in one place. Returns the WC_Coupon object on
     * success, or a WP_Error describing exactly why it isn't usable.
     *
     * This is a best-effort check for fast, friendly UI feedback — the
     * authoritative check is WooCommerce's own $order->apply_coupon(),
     * called later once the coupon is actually applied to a real order.
     */
    public static function validate_coupon_code( $code, $subtotal ) {
        $coupon_id = wc_get_coupon_id_by_code( $code );
        if ( ! $coupon_id ) {
            return new \WP_Error( 'coupon_not_found', __( 'Coupon code not found.', 'wc-pos-pro' ) );
        }

        if ( 'publish' !== get_post_status( $coupon_id ) ) {
            return new \WP_Error( 'coupon_inactive', __( 'This coupon is no longer active.', 'wc-pos-pro' ) );
        }

        $coupon = new \WC_Coupon( $code );

        $expiry = $coupon->get_date_expires();
        if ( $expiry && $expiry->getTimestamp() < time() ) {
            return new \WP_Error( 'coupon_expired', __( 'This coupon has expired.', 'wc-pos-pro' ) );
        }

        $usage_limit = $coupon->get_usage_limit();
        if ( $usage_limit && $coupon->get_usage_count() >= $usage_limit ) {
            return new \WP_Error( 'coupon_usage_limit', __( 'This coupon has reached its usage limit.', 'wc-pos-pro' ) );
        }

        $min_amount = floatval( $coupon->get_minimum_amount() );
        if ( $min_amount && $subtotal < $min_amount ) {
            return new \WP_Error(
                'coupon_min_spend',
                sprintf(
                    /* translators: %s: formatted minimum spend amount */
                    __( 'This coupon requires a minimum spend of %s.', 'wc-pos-pro' ),
                    wc_price( $min_amount )
                )
            );
        }

        $max_amount = floatval( $coupon->get_maximum_amount() );
        if ( $max_amount && $subtotal > $max_amount ) {
            return new \WP_Error(
                'coupon_max_spend',
                sprintf(
                    /* translators: %s: formatted maximum spend amount */
                    __( 'This coupon can only be used on orders up to %s.', 'wc-pos-pro' ),
                    wc_price( $max_amount )
                )
            );
        }

        return $coupon;
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
