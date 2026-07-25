<?php
namespace WCPOS\Orders;

use WCPOS\POS\Inventory;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SalesEngine {

    public static function init_hooks() {
        add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'on_order_completed' ) );
    }

    public static function create_pos_order( $payload ) {
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
                sanitize_text_field( $payload['registerId'] ?? 'REG-MAIN' )
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
        $order->update_meta_data( '_wc_pos_cashier_id',     intval( $payload['cashierId'] ?? 0 ) );
        $order->update_meta_data( '_wc_pos_cashier_name',   sanitize_text_field( $payload['cashierName'] ?? '' ) );
        $order->update_meta_data( '_wc_pos_payments',       $payload['payments'] ?? array() );
        $order->update_meta_data( '_wc_pos_idempotency_key', $idempotency_key );

        $order->set_payment_method( 'wc_pos_custom' );
        $order->set_payment_method_title( __( 'POS In-Person Payment', 'wc-pos-pro' ) );
        $order->calculate_totals();

        // Mark completed only after stock is confirmed deducted.
        $order->update_status( 'completed' );
        $order->save();

        do_action( 'wc_pos_order_created', $order->get_id(), $payload );

        return $order;
    }

    /**
     * Fires when a POS order reaches "completed" status.
     * Adds a timestamped order note and lets third-party code hook in.
     */
    public static function on_order_completed( $order_id ) {
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
                __( 'POS sale completed by %1$s on register %2$s.', 'wc-pos-pro' ),
                $cashier_name,
                $register_id
            )
        );

        /**
         * Fires after a POS order is completed.
         *
         * @param int      $order_id     WooCommerce order ID.
         * @param WC_Order $order        Order object.
         * @param string   $cashier_name Name of the cashier who processed the sale.
         * @param string   $register_id  Register identifier.
         */
        do_action( 'wc_pos_sale_completed', $order_id, $order, $cashier_name, $register_id );
    }
}
