<?php
namespace WCPOS\API;

use WCPOS\Orders\SalesEngine;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class REST_Server {

    private static $instance = null;

    public static function get_instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    public function register_routes() {
        $namespace = 'wc-pos/v1';

        // POS Health & Session
        register_rest_route( $namespace, '/health', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_health' ),
            'permission_callback' => '__return_true',
        ) );

        // Products Search & Variations
        register_rest_route( $namespace, '/products', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_products' ),
            'permission_callback' => array( $this, 'check_pos_permission' ),
        ) );

        // Categories List
        register_rest_route( $namespace, '/categories', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_categories' ),
            'permission_callback' => array( $this, 'check_pos_permission' ),
        ) );

        // Customers Search
        register_rest_route( $namespace, '/customers', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_customers' ),
            'permission_callback' => array( $this, 'check_pos_permission' ),
        ) );

        // Create Customer
        register_rest_route( $namespace, '/customers', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'create_customer' ),
            'permission_callback' => array( $this, 'check_pos_permission' ),
        ) );

        // Orders Listing & Creation
        register_rest_route( $namespace, '/orders', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_orders' ),
            'permission_callback' => array( $this, 'check_pos_permission' ),
        ) );

        register_rest_route( $namespace, '/orders', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'create_order' ),
            'permission_callback' => array( $this, 'check_pos_permission' ),
        ) );

        // Register Shift Control (open / close)
        register_rest_route( $namespace, '/registers/shift', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle_register_shift' ),
            'permission_callback' => array( $this, 'check_pos_permission' ),
        ) );

        // Tax Rates — list and upsert POS-specific rates
        register_rest_route( $namespace, '/tax-rates', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_tax_rates' ),
            'permission_callback' => array( $this, 'check_pos_permission' ),
        ) );

        register_rest_route( $namespace, '/tax-rates', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'save_tax_rate' ),
            'permission_callback' => array( $this, 'check_manager_permission' ),
        ) );

        register_rest_route( $namespace, '/tax-rates/(?P<id>\d+)', array(
            'methods'             => 'DELETE',
            'callback'            => array( $this, 'delete_tax_rate' ),
            'permission_callback' => array( $this, 'check_manager_permission' ),
        ) );

        // Receipt Config — read the stored builder configuration
        register_rest_route( $namespace, '/receipt-config', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_receipt_config' ),
            'permission_callback' => array( $this, 'check_pos_permission' ),
        ) );

        // PIN Management — set and verify cashier PINs server-side
        register_rest_route( $namespace, '/pin/verify', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'verify_pin' ),
            'permission_callback' => array( $this, 'check_pos_permission' ),
        ) );

        register_rest_route( $namespace, '/pin/set', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'set_pin' ),
            'permission_callback' => array( $this, 'check_pos_permission' ),
        ) );
    }

    // -------------------------------------------------------------------------
    // Permission
    // -------------------------------------------------------------------------

    public function check_pos_permission() {
        return current_user_can( 'read_private_shop_orders' ) || current_user_can( 'manage_woocommerce' );
    }

    // -------------------------------------------------------------------------
    // Health
    // -------------------------------------------------------------------------

    public function get_health() {
        return rest_ensure_response( array(
            'status'  => 'ok',
            'version' => WC_POS_VERSION,
            'wc_ver'  => WC()->version,
        ) );
    }

    // -------------------------------------------------------------------------
    // Categories
    // -------------------------------------------------------------------------

    public function get_categories() {
        $terms = get_terms( array(
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
        ) );
        $categories = array();
        if ( ! is_wp_error( $terms ) && is_array( $terms ) ) {
            foreach ( $terms as $term ) {
                $categories[] = array(
                    'id'    => $term->term_id,
                    'name'  => $term->name,
                    'slug'  => $term->slug,
                    'count' => $term->count,
                );
            }
        }
        return rest_ensure_response( $categories );
    }

    // -------------------------------------------------------------------------
    // Products
    // -------------------------------------------------------------------------

    public function get_products( $request ) {
        $search   = $request->get_param( 's' );
        $category = $request->get_param( 'category' );
        $args = array(
            'limit'  => 100,
            'status' => 'publish',
        );
        if ( $search ) {
            $args['s'] = sanitize_text_field( $search );
        }
        if ( $category ) {
            $args['category'] = array( sanitize_text_field( $category ) );
        }

        $products  = wc_get_products( $args );
        $formatted = array();

        foreach ( $products as $p ) {
            $image_id  = $p->get_image_id();
            $image_url = '';
            if ( $image_id ) {
                $img_src = wp_get_attachment_image_src( $image_id, 'medium' );
                if ( $img_src && ! empty( $img_src[0] ) ) {
                    $image_url = $img_src[0];
                } else {
                    $image_url = wp_get_attachment_image_url( $image_id, 'full' );
                }
            }
            if ( empty( $image_url ) && function_exists( 'wc_placeholder_img_src' ) ) {
                $image_url = wc_placeholder_img_src( 'medium' );
            }

            $price         = (float) $p->get_price();
            $regular_price = (float) $p->get_regular_price();
            if ( ! $price && $regular_price ) {
                $price = $regular_price;
            }

            $variations_data = array();
            if ( $p->is_type( 'variable' ) ) {
                foreach ( $p->get_children() as $child_id ) {
                    $v = wc_get_product( $child_id );
                    if ( ! $v ) {
                        continue;
                    }
                    $v_img = '';
                    $v_img_id = $v->get_image_id();
                    if ( $v_img_id ) {
                        $v_img_src = wp_get_attachment_image_src( $v_img_id, 'medium' );
                        $v_img     = ( $v_img_src && ! empty( $v_img_src[0] ) ) ? $v_img_src[0] : '';
                    }
                    if ( empty( $v_img ) ) {
                        $v_img = $image_url;
                    }

                    $v_price = (float) $v->get_price() ?: (float) $v->get_regular_price();

                    $variations_data[] = array(
                        'id'            => $v->get_id(),
                        'name'          => $v->get_name(),
                        'sku'           => $v->get_sku(),
                        'price'         => $v_price,
                        'regularPrice'  => (float) $v->get_regular_price(),
                        'stockQuantity' => (int) $v->get_stock_quantity(),
                        'attributes'    => $v->get_attributes(),
                        'imageUrl'      => $v_img,
                    );
                }
            }

            $formatted[] = array(
                'id'            => $p->get_id(),
                'name'          => $p->get_name(),
                'sku'           => $p->get_sku(),
                'type'          => $p->get_type(),
                'price'         => $price,
                'regularPrice'  => $regular_price,
                'stockQuantity' => (int) $p->get_stock_quantity(),
                'imageUrl'      => $image_url,
                'variations'    => $variations_data,
            );
        }

        return rest_ensure_response( $formatted );
    }

    // -------------------------------------------------------------------------
    // Customers
    // -------------------------------------------------------------------------

    public function get_customers( $request ) {
        $search = sanitize_text_field( $request->get_param( 's' ) ?? '' );
        $args   = array(
            'number'   => 30,
            'orderby'  => 'display_name',
            'order'    => 'ASC',
            // Only return actual customers/subscribers — never expose admin accounts.
            'role__in' => array( 'customer', 'subscriber' ),
        );
        if ( $search ) {
            $args['search']         = '*' . $search . '*';
            $args['search_columns'] = array( 'user_login', 'user_email', 'display_name' );
        }

        $users     = get_users( $args );
        $formatted = array();
        foreach ( $users as $u ) {
            $formatted[] = array(
                'id'    => $u->ID,
                'name'  => $u->display_name,
                'email' => $u->user_email,
                'phone' => get_user_meta( $u->ID, 'billing_phone', true ) ?: '',
            );
        }

        return rest_ensure_response( $formatted );
    }

    public function create_customer( $request ) {
        $params = $request->get_json_params();
        $name   = sanitize_text_field( $params['name'] ?? '' );
        $email  = sanitize_email( $params['email'] ?? '' );
        $phone  = sanitize_text_field( $params['phone'] ?? '' );

        if ( empty( $name ) ) {
            return new \WP_REST_Response( array( 'success' => false, 'message' => 'Customer name is required.' ), 400 );
        }

        if ( empty( $email ) ) {
            $email = 'customer_' . time() . '@pos.local';
        }

        if ( email_exists( $email ) ) {
            $user = get_user_by( 'email', $email );
            return rest_ensure_response( array(
                'success'  => true,
                'customer' => array(
                    'id'    => $user->ID,
                    'name'  => $user->display_name,
                    'email' => $user->user_email,
                    'phone' => $phone,
                ),
            ) );
        }

        $password = wp_generate_password();
        $user_id  = wp_create_user( $email, $password, $email );

        if ( is_wp_error( $user_id ) ) {
            return new \WP_REST_Response( array( 'success' => false, 'message' => $user_id->get_error_message() ), 400 );
        }

        wp_update_user( array(
            'ID'           => $user_id,
            'display_name' => $name,
            'first_name'   => $name,
            'role'         => 'customer',
        ) );

        if ( $phone ) {
            update_user_meta( $user_id, 'billing_phone', $phone );
        }

        return rest_ensure_response( array(
            'success'  => true,
            'customer' => array(
                'id'    => $user_id,
                'name'  => $name,
                'email' => $email,
                'phone' => $phone,
            ),
        ) );
    }

    // -------------------------------------------------------------------------
    // Orders
    // -------------------------------------------------------------------------

    public function get_orders( $request ) {
        $orders    = wc_get_orders( array(
            'limit'       => 20,
            'created_via' => 'wc_pos_pro',
            'orderby'     => 'date',
            'order'       => 'DESC',
        ) );
        $formatted = array();
        foreach ( $orders as $o ) {
            $formatted[] = array(
                'id'          => $o->get_id(),
                'orderNumber' => $o->get_order_number(),
                'total'       => (float) $o->get_total(),
                'status'      => $o->get_status(),
                'dateCreated' => $o->get_date_created() ? $o->get_date_created()->date( 'Y-m-d H:i:s' ) : '',
                'cashierName' => $o->get_meta( '_wc_pos_cashier_name' ) ?: 'Admin',
                'itemCount'   => $o->get_item_count(),
            );
        }
        return rest_ensure_response( $formatted );
    }

    public function create_order( $request ) {
        $payload       = $request->get_json_params();
        $order_or_error = SalesEngine::create_pos_order( $payload );

        if ( is_wp_error( $order_or_error ) ) {
            return new \WP_REST_Response( array(
                'success' => false,
                'message' => $order_or_error->get_error_message(),
            ), 400 );
        }

        return rest_ensure_response( array(
            'success' => true,
            'orderId' => $order_or_error->get_id(),
            'status'  => $order_or_error->get_status(),
        ) );
    }

    // -------------------------------------------------------------------------
    // Register Shifts
    // -------------------------------------------------------------------------

    /**
     * Open or close a cashier shift.
     *
     * Expected payload:
     *  {
     *    "action":       "open" | "close",
     *    "registerId":   "REG-MAIN",
     *    "openingFloat": 500.00,     // required for "open"
     *    "actualCash":   480.00,     // required for "close"
     *    "notes":        "..."       // optional
     *  }
     */
    public function handle_register_shift( $request ) {
        global $wpdb;

        $params      = $request->get_json_params();
        $action      = sanitize_text_field( $params['action'] ?? '' );
        $register_id = sanitize_text_field( $params['registerId'] ?? 'REG-MAIN' );
        $user        = wp_get_current_user();

        if ( ! in_array( $action, array( 'open', 'close' ), true ) ) {
            return new \WP_REST_Response( array(
                'success' => false,
                'message' => __( 'Invalid shift action. Must be "open" or "close".', 'wc-pos-pro' ),
            ), 400 );
        }

        $shifts_table    = $wpdb->prefix . 'wc_pos_shifts';
        $registers_table = $wpdb->prefix . 'wc_pos_registers';

        if ( 'open' === $action ) {
            // Check for an already-open shift on this register.
            $open_shift = $wpdb->get_row( $wpdb->prepare(
                "SELECT id FROM {$shifts_table} WHERE register_id = %s AND status = 'active' LIMIT 1",
                $register_id
            ) );

            if ( $open_shift ) {
                return new \WP_REST_Response( array(
                    'success'  => false,
                    'message'  => __( 'A shift is already open on this register. Close it before opening a new one.', 'wc-pos-pro' ),
                    'shiftId'  => $open_shift->id,
                ), 409 );
            }

            $shift_id      = 'SHF-' . wp_generate_uuid4();
            $opening_float = floatval( $params['openingFloat'] ?? 0 );
            $notes         = sanitize_textarea_field( $params['notes'] ?? '' );

            $wpdb->insert(
                $shifts_table,
                array(
                    'id'             => $shift_id,
                    'register_id'    => $register_id,
                    'cashier_id'     => $user->ID,
                    'cashier_name'   => $user->display_name,
                    'opened_at'      => current_time( 'mysql', true ),
                    'opening_float'  => $opening_float,
                    'status'         => 'active',
                    'opening_notes'  => $notes,
                )
            );

            // Mark register as open.
            $wpdb->update(
                $registers_table,
                array( 'status' => 'open', 'current_shift_id' => $shift_id ),
                array( 'id' => $register_id ),
                array( '%s', '%s' ),
                array( '%s' )
            );

            return rest_ensure_response( array(
                'success' => true,
                'action'  => 'opened',
                'shiftId' => $shift_id,
            ) );
        }

        // --- Close shift ---
        $shift = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$shifts_table} WHERE register_id = %s AND status = 'active' LIMIT 1",
            $register_id
        ) );

        if ( ! $shift ) {
            return new \WP_REST_Response( array(
                'success' => false,
                'message' => __( 'No active shift found on this register.', 'wc-pos-pro' ),
            ), 404 );
        }

        // Sum up POS sales created during this shift period.
        $orders = wc_get_orders( array(
            'created_via' => 'wc_pos_pro',
            'date_after'  => $shift->opened_at,
            'status'      => array( 'wc-completed' ),
            'meta_key'    => '_wc_pos_register_id',
            'meta_value'  => $register_id,
            'limit'       => -1,
        ) );

        $total_sales = 0;
        $cash_sales  = 0;
        $card_sales  = 0;

        foreach ( $orders as $order ) {
            $total = (float) $order->get_total();
            $total_sales += $total;
            $payments = $order->get_meta( '_wc_pos_payments' );
            if ( is_array( $payments ) ) {
                foreach ( $payments as $payment ) {
                    $method = $payment['method'] ?? '';
                    $amount = floatval( $payment['amount'] ?? 0 );
                    if ( 'cash' === $method ) {
                        $cash_sales += $amount;
                    } elseif ( 'card' === $method ) {
                        $card_sales += $amount;
                    }
                }
            }
        }

        $actual_cash    = floatval( $params['actualCash'] ?? 0 );
        $expected_cash  = floatval( $shift->opening_float ) + $cash_sales;
        $cash_diff      = $actual_cash - $expected_cash;
        $notes          = sanitize_textarea_field( $params['notes'] ?? '' );

        $wpdb->update(
            $shifts_table,
            array(
                'closed_at'       => current_time( 'mysql', true ),
                'actual_cash'     => $actual_cash,
                'expected_cash'   => $expected_cash,
                'cash_difference' => $cash_diff,
                'total_sales'     => $total_sales,
                'cash_sales'      => $cash_sales,
                'card_sales'      => $card_sales,
                'status'          => 'closed',
                'closing_notes'   => $notes,
            ),
            array( 'id' => $shift->id ),
            array( '%s', '%f', '%f', '%f', '%f', '%f', '%f', '%s', '%s' ),
            array( '%s' )
        );

        // Mark register as closed.
        $wpdb->update(
            $registers_table,
            array( 'status' => 'closed', 'current_shift_id' => null ),
            array( 'id' => $register_id ),
            array( '%s', '%s' ),
            array( '%s' )
        );

        return rest_ensure_response( array(
            'success'         => true,
            'action'          => 'closed',
            'shiftId'         => $shift->id,
            'summary'         => array(
                'totalSales'     => $total_sales,
                'cashSales'      => $cash_sales,
                'cardSales'      => $card_sales,
                'openingFloat'   => floatval( $shift->opening_float ),
                'expectedCash'   => $expected_cash,
                'actualCash'     => $actual_cash,
                'cashDifference' => $cash_diff,
            ),
        ) );
    }

    // -------------------------------------------------------------------------
    // PIN Management
    // -------------------------------------------------------------------------

    /**
     * Verify a cashier's PIN server-side.
     * Payload: { "pin": "1234" }
     * Returns: { "success": true } or HTTP 401.
     */
    public function verify_pin( $request ) {
        $params  = $request->get_json_params();
        $raw_pin = sanitize_text_field( $params['pin'] ?? '' );

        if ( empty( $raw_pin ) ) {
            return new \WP_REST_Response( array( 'success' => false, 'message' => 'PIN is required.' ), 400 );
        }

        $user_id    = get_current_user_id();
        $stored_pin = get_user_meta( $user_id, '_wc_pos_pin_hash', true );

        // If no PIN has been set yet, accept the default "1234" and prompt setup.
        if ( empty( $stored_pin ) ) {
            if ( '1234' === $raw_pin ) {
                return rest_ensure_response( array(
                    'success'     => true,
                    'requiresSetup' => true,
                    'message'     => __( 'Default PIN accepted. Please set a personal PIN.', 'wc-pos-pro' ),
                ) );
            }
            return new \WP_REST_Response( array( 'success' => false, 'message' => __( 'Incorrect PIN.', 'wc-pos-pro' ) ), 401 );
        }

        if ( wp_check_password( $raw_pin, $stored_pin, $user_id ) ) {
            return rest_ensure_response( array( 'success' => true, 'requiresSetup' => false ) );
        }

        return new \WP_REST_Response( array( 'success' => false, 'message' => __( 'Incorrect PIN.', 'wc-pos-pro' ) ), 401 );
    }

    /**
     * Set or change a cashier's PIN.
     * Payload: { "pin": "5678" }
     * PIN must be 4–8 numeric digits.
     */
    public function set_pin( $request ) {
        $params  = $request->get_json_params();
        $raw_pin = sanitize_text_field( $params['pin'] ?? '' );

        if ( ! preg_match( '/^\d{4,8}$/', $raw_pin ) ) {
            return new \WP_REST_Response( array(
                'success' => false,
                'message' => __( 'PIN must be 4 to 8 numeric digits.', 'wc-pos-pro' ),
            ), 400 );
        }

        $user_id    = get_current_user_id();
        $pin_hash   = wp_hash_password( $raw_pin );
        update_user_meta( $user_id, '_wc_pos_pin_hash', $pin_hash );

        return rest_ensure_response( array(
            'success' => true,
            'message' => __( 'PIN updated successfully.', 'wc-pos-pro' ),
        ) );
    }

    // -------------------------------------------------------------------------
    // Permission helpers
    // -------------------------------------------------------------------------

    /**
     * Managers-only gate: used for mutating tax rates and other privileged ops.
     */
    public function check_manager_permission() {
        return current_user_can( 'manage_woocommerce' );
    }

    // -------------------------------------------------------------------------
    // Tax Rates
    // -------------------------------------------------------------------------

    /**
     * GET /wc-pos/v1/tax-rates
     * Returns all active POS tax rates plus the global tax settings.
     */
    public function get_tax_rates() {
        global $wpdb;

        $table = $wpdb->prefix . 'wc_pos_tax_rates';
        $rows  = $wpdb->get_results(
            "SELECT * FROM {$table} WHERE is_active = 1 ORDER BY priority ASC, id ASC"
        );

        $rates = array();
        foreach ( $rows as $row ) {
            $rates[] = array(
                'id'          => (int) $row->id,
                'name'        => $row->name,
                'rate'        => (float) $row->rate,
                'isInclusive' => (bool) $row->is_inclusive,
                'appliesTo'   => $row->applies_to,
                'priority'    => (int) $row->priority,
            );
        }

        return rest_ensure_response( array(
            'rates'             => $rates,
            'taxInclusivePrices'=> (bool) get_option( 'wc_pos_tax_inclusive_prices', false ),
            'allowExemptOverride' => (bool) get_option( 'wc_pos_allow_tax_exempt_override', false ),
        ) );
    }

    /**
     * POST /wc-pos/v1/tax-rates
     * Create or update a POS tax rate.
     * Payload: { "id": 3, "name": "VAT", "rate": 7.5, "isInclusive": false, "appliesTo": "all", "priority": 1 }
     * Omit "id" to create a new rate.
     */
    public function save_tax_rate( $request ) {
        global $wpdb;

        $params = $request->get_json_params();
        $name   = sanitize_text_field( $params['name'] ?? '' );
        $rate   = floatval( $params['rate'] ?? 0 );

        if ( empty( $name ) ) {
            return new \WP_REST_Response( array( 'success' => false, 'message' => 'Tax name is required.' ), 400 );
        }
        if ( $rate < 0 || $rate > 100 ) {
            return new \WP_REST_Response( array( 'success' => false, 'message' => 'Rate must be between 0 and 100.' ), 400 );
        }

        $applies_to = in_array( $params['appliesTo'] ?? 'all', array( 'all', 'food', 'services', 'goods' ), true )
            ? $params['appliesTo']
            : 'all';

        $data = array(
            'name'         => $name,
            'rate'         => $rate,
            'is_inclusive' => ! empty( $params['isInclusive'] ) ? 1 : 0,
            'applies_to'   => $applies_to,
            'priority'     => max( 1, intval( $params['priority'] ?? 1 ) ),
            'is_active'    => 1,
        );

        $table = $wpdb->prefix . 'wc_pos_tax_rates';

        if ( ! empty( $params['id'] ) ) {
            $wpdb->update( $table, $data, array( 'id' => intval( $params['id'] ) ), null, array( '%d' ) );
            $rate_id = intval( $params['id'] );
        } else {
            $wpdb->insert( $table, $data );
            $rate_id = $wpdb->insert_id;
        }

        return rest_ensure_response( array( 'success' => true, 'id' => $rate_id ) );
    }

    /**
     * DELETE /wc-pos/v1/tax-rates/{id}
     * Soft-deletes a tax rate (sets is_active = 0).
     */
    public function delete_tax_rate( $request ) {
        global $wpdb;

        $id    = intval( $request->get_param( 'id' ) );
        $table = $wpdb->prefix . 'wc_pos_tax_rates';

        $wpdb->update( $table, array( 'is_active' => 0 ), array( 'id' => $id ), array( '%d' ), array( '%d' ) );

        return rest_ensure_response( array( 'success' => true ) );
    }

    // -------------------------------------------------------------------------
    // Receipt Config
    // -------------------------------------------------------------------------

    /**
     * GET /wc-pos/v1/receipt-config
     * Returns the full receipt builder configuration stored in WP options.
     */
    public function get_receipt_config() {
        $logo_id  = (int) get_option( 'wc_pos_receipt_logo_id', 0 );
        $logo_url = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';

        return rest_ensure_response( array(
            'storeName'       => get_bloginfo( 'name' ),
            'logoUrl'         => $logo_url ?: '',
            'showLogo'        => (bool) get_option( 'wc_pos_receipt_show_logo', true ),
            'showStoreName'   => (bool) get_option( 'wc_pos_receipt_show_store_name', true ),
            'showAddress'     => (bool) get_option( 'wc_pos_receipt_show_address', true ),
            'storeAddress'    => get_option( 'wc_pos_store_address', '' ),
            'storePhone'      => get_option( 'wc_pos_store_phone', '' ),
            'headerText'      => get_option( 'wc_pos_receipt_header', '' ),
            'footerText'      => get_option( 'wc_pos_receipt_footer', '' ),
            'showBarcode'     => (bool) get_option( 'wc_pos_receipt_show_barcode', true ),
            'showTaxBreakdown'=> (bool) get_option( 'wc_pos_receipt_show_tax_breakdown', true ),
            'showCashier'     => (bool) get_option( 'wc_pos_receipt_show_cashier', true ),
            'paperWidth'      => get_option( 'wc_pos_receipt_paper_width', '80mm' ),
            'lineItemFormat'  => get_option( 'wc_pos_receipt_line_item_format', 'full' ),
        ) );
    }
}
