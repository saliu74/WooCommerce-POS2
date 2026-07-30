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

        // Full detail for a single past order — used by the Order History
        // screen's "Reprint Receipt" action to rebuild the actual receipt
        // (this previously didn't exist; the button was a placeholder that
        // never fetched anything real — see get_order_detail()).
        register_rest_route( $namespace, '/orders/(?P<id>\d+)', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_order_detail' ),
            'permission_callback' => array( $this, 'check_pos_permission' ),
        ) );

        // Register Shift Control (open / close)
        register_rest_route( $namespace, '/registers/shift', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle_register_shift' ),
            'permission_callback' => array( $this, 'check_pos_permission' ),
        ) );

        // Multi-branch feature build-out: lightweight list endpoint so the POS
        // frontend can populate a register picker for the selected branch.
        // (Branches themselves are already listable via Branches_Controller's
        // GET /branches — registering that route again here would collide
        // with it on the same namespace.)
        register_rest_route( $namespace, '/registers', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'list_registers' ),
            'permission_callback' => array( $this, 'check_pos_permission' ),
        ) );

        // Whole-order coupon discount (separate from the per-item discount
        // feature, which remains manager-PIN/capability gated). Applying a
        // coupon is a standard cashier-level checkout action — the code
        // itself is the authorization (a cashier can't apply a coupon they
        // don't know), unlike an arbitrary discretionary discount.
        register_rest_route( $namespace, '/coupons/validate', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'validate_coupon' ),
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
        // Wire up the previously-unused 'process_wc_pos_sales' capability
        // (registered in Permissions.php but never actually checked anywhere)
        // as the primary gate for terminal/sales operations. Kept additive
        // with the prior checks so no existing install loses access.
        return current_user_can( 'process_wc_pos_sales' )
            || current_user_can( 'read_private_shop_orders' )
            || current_user_can( 'manage_woocommerce' );
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
        // Multi-branch feature build-out: optional branch context. When
        // provided and a product has branch-specific stock allocated (via
        // wc_pos_branch_stock), that overrides the global stock figure in the
        // response. Products with no branch allocation still show global
        // stock, unchanged — this is fully backward compatible.
        $branch_id = sanitize_text_field( $request->get_param( 'branchId' ) ?? '' );
        $branch_id = $branch_id ?: null;
        $page      = max( 1, absint( $request->get_param( 'page' ) ?: 1 ) );

        // Bug fix: this previously hard-capped every request at 100 products
        // with no pagination and no explicit ordering — meaning it silently
        // fell back to WooCommerce's newest-first default. In a catalog of
        // any real size, that meant only the ~100 most recently created
        // products could ever appear (anything older was completely
        // unreachable, since there was no way to request a further page),
        // and the terminal's search made this worse by only ever filtering
        // that same capped, recency-biased batch client-side rather than
        // querying the full database. Fixed with real pagination for
        // browsing, alphabetical ordering (not recency-biased), and a
        // generous, non-paginated cap specifically for search results —
        // when someone is actively searching for a product, they expect
        // every match, not a paginated subset of matches.
        $per_page = $search ? 250 : 60;

        $args = array(
            'limit'    => $per_page,
            'page'     => $page,
            'status'   => 'publish',
            'orderby'  => 'title',
            'order'    => 'ASC',
            'paginate' => true,
        );
        if ( $search ) {
            $args['s'] = sanitize_text_field( $search );
        }
        if ( $category ) {
            $args['category'] = array( sanitize_text_field( $category ) );
        }

        $result    = wc_get_products( $args );
        $products  = $result->products;
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
                    if ( ! $v || ! $v->exists() ) {
                        continue;
                    }
                    // Skip variations that are disabled/not purchasable — they
                    // shouldn't count toward "in stock" and shouldn't be sellable at POS.
                    if ( ! $v->is_purchasable() && ! current_user_can( 'manage_woocommerce' ) ) {
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
                    $v_stock = $this->resolve_stock( $v, null, $branch_id );

                    $variations_data[] = array(
                        'id'            => $v->get_id(),
                        'name'          => $v->get_name(),
                        'sku'           => $v->get_sku(),
                        'price'         => $v_price,
                        'regularPrice'  => (float) $v->get_regular_price(),
                        'stockQuantity' => $v_stock['quantity'],
                        'stockStatus'   => $v_stock['status'],
                        'attributes'    => $v->get_attributes(),
                        'imageUrl'      => $v_img,
                    );
                }
            }

            // Bug fix (#4): a variable product's own stock fields are meaningless
            // when "Manage stock" is disabled at the parent level — the real
            // availability lives on its variations. resolve_stock() aggregates
            // across variations for 'variable' products and falls back to the
            // product's own fields for everything else.
            $stock = $this->resolve_stock( $p, $variations_data, $branch_id );

            $formatted[] = array(
                'id'            => $p->get_id(),
                'name'          => $p->get_name(),
                'sku'           => $p->get_sku(),
                'type'          => $p->get_type(),
                'price'         => $price,
                'regularPrice'  => $regular_price,
                'stockQuantity' => $stock['quantity'],
                'stockStatus'   => $stock['status'],
                'imageUrl'      => $image_url,
                'variations'    => $variations_data,
            );
        }

        return rest_ensure_response( array(
            'products'   => $formatted,
            'page'       => $page,
            'totalPages' => (int) $result->max_num_pages,
            'total'      => (int) $result->total,
        ) );
    }

    /**
     * Resolve accurate stock status/quantity for any product type.
     *
     * For 'variable' products, the parent's own _stock / _stock_status meta is
     * unreliable when "Manage stock" is off at the parent (the common case) —
     * so we aggregate across the already-formatted variation data (or, if not
     * supplied, load the variations directly) and treat the parent as in-stock
     * if at least one variation is in stock or carries positive quantity.
     *
     * @param WC_Product  $product
     * @param array|null  $formatted_variations Optional pre-built variation rows
     *                                          (each with stockQuantity/stockStatus)
     *                                          to avoid re-querying variations.
     * @param string|null $branch_id Multi-branch feature: when given and a
     *                                wc_pos_branch_stock row exists for this
     *                                product/branch, that quantity overrides
     *                                the global WooCommerce figure. No row =
     *                                falls through to global stock, unchanged.
     * @return array{status:string, quantity:int|null}
     */
    private function resolve_stock( $product, $formatted_variations = null, $branch_id = null ) {
        if ( ! $product->is_type( 'variable' ) ) {
            $status   = $product->get_stock_status() ?: 'instock';
            $quantity = $product->managing_stock() ? (int) $product->get_stock_quantity() : null;

            $branch_override = $this->get_branch_stock_override( $product, $branch_id );
            if ( null !== $branch_override ) {
                $quantity = $branch_override;
                if ( $product->managing_stock() && ! $product->backorders_allowed() ) {
                    $status = $branch_override > 0 ? 'instock' : 'outofstock';
                }
            }

            return array(
                'status'   => $status,
                'quantity' => $quantity,
            );
        }

        // Build the variation list if the caller didn't already hand us one.
        if ( null === $formatted_variations ) {
            $formatted_variations = array();
            foreach ( $product->get_children() as $child_id ) {
                $v = wc_get_product( $child_id );
                if ( ! $v || ! $v->exists() ) {
                    continue;
                }
                $formatted_variations[] = array(
                    'stockQuantity' => $v->managing_stock() ? (int) $v->get_stock_quantity() : null,
                    'stockStatus'   => $v->get_stock_status(),
                );
            }
        }

        if ( empty( $formatted_variations ) ) {
            return array( 'status' => 'outofstock', 'quantity' => 0 );
        }

        $total_qty        = 0;
        $has_qty_data      = false;
        $any_in_stock     = false;
        $any_on_backorder = false;

        foreach ( $formatted_variations as $v_row ) {
            $qty    = $v_row['stockQuantity'];
            $status = $v_row['stockStatus'];

            if ( $qty !== null ) {
                $has_qty_data = true;
                $total_qty   += max( 0, (int) $qty );
                if ( $qty > 0 ) {
                    $any_in_stock = true;
                }
            } elseif ( 'instock' === $status ) {
                $any_in_stock = true;
            }

            if ( 'onbackorder' === $status ) {
                $any_on_backorder = true;
            }
        }

        if ( $any_in_stock ) {
            $status = 'instock';
        } elseif ( $any_on_backorder ) {
            $status = 'onbackorder';
        } else {
            $status = 'outofstock';
        }

        return array(
            'status'   => $status,
            'quantity' => $has_qty_data ? $total_qty : null,
        );
    }

    /**
     * Multi-branch feature build-out: return the branch-specific stock
     * quantity for a product if one has been allocated, or null if this
     * product isn't branch-tracked (caller should keep using global stock).
     */
    private function get_branch_stock_override( $product, $branch_id ) {
        if ( ! $branch_id ) {
            return null;
        }

        global $wpdb;
        $is_variation    = $product->is_type( 'variation' );
        $bs_product_id   = $is_variation ? $product->get_parent_id() : $product->get_id();
        $bs_variation_id = $is_variation ? $product->get_id() : 0;

        $table = $wpdb->prefix . 'wc_pos_branch_stock';
        $qty   = $wpdb->get_var( $wpdb->prepare(
            "SELECT stock_quantity FROM {$table} WHERE branch_id = %s AND product_id = %d AND variation_id = %d",
            $branch_id, $bs_product_id, $bs_variation_id
        ) );

        return null === $qty ? null : (int) $qty;
    }

    // -------------------------------------------------------------------------
    // Customers
    // -------------------------------------------------------------------------

    public function get_customers( $request ) {
        $search = sanitize_text_field( $request->get_param( 's' ) ?? '' );

        $base_args = array(
            'number'   => 30,
            'orderby'  => 'display_name',
            'order'    => 'ASC',
            // Only return actual customers/subscribers — never expose admin accounts.
            'role__in' => array( 'customer', 'subscriber' ),
        );

        if ( ! $search ) {
            $users     = get_users( $base_args );
            $formatted = array();
            foreach ( $users as $u ) {
                $formatted[] = $this->format_customer_row( $u );
            }
            return rest_ensure_response( $formatted );
        }

        // Bug fix (#2): the previous query passed 'display_name' into
        // WP_User_Query's search_columns, but display_name is NOT one of the
        // columns WP_User_Query is able to search against (only user_login,
        // user_nicename, user_email, user_url, ID are valid) — so it was
        // silently dropped, and first_name/last_name (which live in usermeta,
        // not the wp_users table) were never searched at all. That let
        // wildcard-less/incidental substring matches on login or email surface
        // unrelated accounts (e.g. "ruth" matching something inside an
        // autogenerated POS guest email) while genuine name matches were missed.
        //
        // Fix: run two explicit WP_User_Query passes — one against the real
        // core search_columns, one against first_name/last_name meta — then
        // merge + de-duplicate by user ID. Both use wildcarded LIKE ('*term*')
        // so partial matches work as the cashier types.
        $wildcard = '*' . $search . '*';

        $core_args                    = $base_args;
        $core_args['search']          = $wildcard;
        $core_args['search_columns']  = array( 'user_login', 'user_email', 'user_nicename' );
        $core_query                   = new \WP_User_Query( $core_args );

        $meta_args               = $base_args;
        $meta_args['meta_query'] = array(
            'relation' => 'OR',
            array(
                'key'     => 'first_name',
                'value'   => $search,
                'compare' => 'LIKE',
            ),
            array(
                'key'     => 'last_name',
                'value'   => $search,
                'compare' => 'LIKE',
            ),
        );
        $meta_query = new \WP_User_Query( $meta_args );

        // Also match directly against display_name in PHP, since WP_User_Query
        // can't search that column at the SQL level.
        $display_name_matches = get_users( array_merge( $base_args, array( 'fields' => 'all' ) ) );
        $display_name_matches = array_filter( $display_name_matches, function ( $u ) use ( $search ) {
            return false !== stripos( $u->display_name, $search );
        } );

        $merged = array();
        foreach ( array_merge( $core_query->get_results(), $meta_query->get_results(), $display_name_matches ) as $u ) {
            $merged[ $u->ID ] = $u;
        }

        // Relevance sort: names that start with the search term first, then
        // alphabetical — so "Ruth Adeyemi" outranks someone who merely
        // contains "ruth" inside an email address.
        $merged = array_values( $merged );
        usort( $merged, function ( $a, $b ) use ( $search ) {
            $a_prefix = 0 === stripos( $a->display_name, $search );
            $b_prefix = 0 === stripos( $b->display_name, $search );
            if ( $a_prefix !== $b_prefix ) {
                return $b_prefix <=> $a_prefix;
            }
            return strcasecmp( $a->display_name, $b->display_name );
        } );

        $formatted = array();
        foreach ( $merged as $u ) {
            $formatted[] = $this->format_customer_row( $u );
        }

        return rest_ensure_response( $formatted );
    }

    /**
     * Shape a WP_User object into the customer row the POS frontend expects.
     */
    private function format_customer_row( $u ) {
        $first = get_user_meta( $u->ID, 'first_name', true );
        $last  = get_user_meta( $u->ID, 'last_name', true );
        $name  = trim( $first . ' ' . $last ) ?: $u->display_name;

        return array(
            'id'    => $u->ID,
            'name'  => $name,
            'email' => $u->user_email,
            'phone' => get_user_meta( $u->ID, 'billing_phone', true ) ?: '',
        );
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

    /**
     * GET /wc-pos/v1/orders/{id}
     * Full order detail shaped to match exactly what the terminal's
     * buildReceipt() expects, so "Reprint Receipt" in Order History can
     * reconstruct the real receipt instead of the placeholder alert() it
     * showed before this existed.
     */
    public function get_order_detail( $request ) {
        $order_id = absint( $request->get_param( 'id' ) );
        $order    = wc_get_order( $order_id );

        if ( ! $order || 'wc_pos_pro' !== $order->get_created_via() ) {
            return new \WP_REST_Response( array( 'success' => false, 'message' => __( 'Order not found.', 'wc-pos-pro' ) ), 404 );
        }

        $items       = array();
        $subtotal    = 0.0;
        $item_discount_total = 0.0;

        foreach ( $order->get_items() as $item ) {
            $quantity      = $item->get_quantity() ?: 1;
            $line_subtotal = (float) $item->get_subtotal();
            $line_total    = (float) $item->get_total();
            $unit_price    = $line_subtotal / $quantity;
            $discount      = max( 0, $line_subtotal - $line_total );
            $product       = $item->get_product();

            $items[] = array(
                'name'           => $item->get_name(),
                'sku'            => $product ? $product->get_sku() : '',
                'unitPrice'      => $unit_price,
                'quantity'       => $quantity,
                'discountAmount' => $discount,
            );

            $subtotal            += $line_subtotal;
            $item_discount_total += $discount;
        }

        // Whole-order discounts: coupon-based discount is tracked natively
        // by WooCommerce; a manual percent/fixed order discount was applied
        // as a negative fee line (see SalesEngine::create_pos_order()) and
        // isn't part of get_total_discount() — both are folded in here so
        // the reprinted receipt's discount total matches what the customer
        // actually paid.
        $fee_discount_total = 0.0;
        foreach ( $order->get_items( 'fee' ) as $fee ) {
            $fee_total = (float) $fee->get_total();
            if ( $fee_total < 0 ) {
                $fee_discount_total += abs( $fee_total );
            }
        }

        $total_discount = $item_discount_total + (float) $order->get_total_discount() + $fee_discount_total;

        return rest_ensure_response( array(
            'success'      => true,
            'orderId'      => $order->get_id(),
            'items'        => $items,
            'subtotal'     => $subtotal,
            'totalDiscount' => $total_discount,
            'tax'          => (float) $order->get_total_tax(),
            'grandTotal'   => (float) $order->get_total(),
            'payments'     => $order->get_meta( '_wc_pos_payments' ) ?: array(),
            'changeDue'    => 0, // not stored historically; omitted on reprint
            'cashierName'  => $order->get_meta( '_wc_pos_cashier_name' ) ?: __( 'Staff', 'wc-pos-pro' ),
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
    /**
     * GET /wc-pos/v1/registers?branchId=default
     * Multi-branch feature build-out: list registers, optionally filtered to
     * one branch, so the POS terminal can offer a register picker. Registers
     * themselves are created/managed in wp-admin (POS > Registers).
     */
    public function list_registers( $request ) {
        global $wpdb;
        $table     = $wpdb->prefix . 'wc_pos_registers';
        $branch_id = sanitize_text_field( $request->get_param( 'branchId' ) ?? '' );

        if ( $branch_id ) {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, name, location, status, branch_id FROM {$table} WHERE branch_id = %s ORDER BY name ASC",
                $branch_id
            ) );
        } else {
            $rows = $wpdb->get_results( "SELECT id, name, location, status, branch_id FROM {$table} ORDER BY name ASC" );
        }

        $registers = array();
        foreach ( $rows as $r ) {
            $registers[] = array(
                'id'       => $r->id,
                'name'     => $r->name,
                'location' => $r->location,
                'status'   => $r->status,
                'branchId' => $r->branch_id,
            );
        }

        return rest_ensure_response( $registers );
    }

    /**
     * POST /wc-pos/v1/coupons/validate
     * Best-effort preview of a coupon code against the current cart
     * subtotal — reuses SalesEngine's shared validation so the frontend and
     * the actual order-creation step agree on what counts as valid. Returns
     * an estimated discount amount for display; the authoritative amount is
     * whatever WooCommerce computes when the coupon is actually applied to
     * the order at checkout.
     */
    public function validate_coupon( $request ) {
        $params   = $request->get_json_params();
        $code     = sanitize_text_field( $params['code'] ?? '' );
        $subtotal = floatval( $params['subtotal'] ?? 0 );

        if ( empty( $code ) ) {
            return new \WP_REST_Response( array( 'success' => false, 'message' => __( 'Enter a coupon code.', 'wc-pos-pro' ) ), 400 );
        }

        $result = \WCPOS\Orders\SalesEngine::validate_coupon_code( $code, $subtotal );
        if ( is_wp_error( $result ) ) {
            return new \WP_REST_Response( array( 'success' => false, 'message' => $result->get_error_message() ), 400 );
        }

        $coupon          = $result;
        $discount_type   = $coupon->get_discount_type();
        $coupon_amount   = floatval( $coupon->get_amount() );
        $discount_amount = 0.0;

        if ( 'percent' === $discount_type ) {
            $discount_amount = $subtotal * ( $coupon_amount / 100 );
        } else {
            // fixed_cart and fixed_product coupons both carry a flat amount;
            // fixed_product coupons don't map perfectly onto a whole-cart
            // preview without full line-item application, so this figure is
            // a reasonable estimate — the real amount is computed by
            // WooCommerce itself when the coupon is actually applied.
            $discount_amount = $coupon_amount;
        }

        $discount_amount = min( $discount_amount, $subtotal );

        return rest_ensure_response( array(
            'success'        => true,
            'code'           => $coupon->get_code(),
            'discountType'   => $discount_type,
            'discountAmount' => round( $discount_amount, 2 ),
            'message'        => __( 'Coupon applied.', 'wc-pos-pro' ),
        ) );
    }

    public function handle_register_shift( $request ) {
        global $wpdb;

        $params      = $request->get_json_params();
        $action      = sanitize_text_field( $params['action'] ?? '' );
        $register_id = sanitize_text_field( $params['registerId'] ?? 'REG-MAIN' );
        // Multi-branch support: optional, defaults to the seeded "default"
        // branch so single-location stores (or any request sent before the
        // frontend has a branch selector) keep working unchanged.
        $branch_id   = sanitize_text_field( $params['branchId'] ?? 'default' );
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
                    'branch_id'      => $branch_id,
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
        // Bug fix: POS orders now land in "processing" rather than
        // auto-completing (see SalesEngine::create_pos_order()) — this filter
        // still looked for "completed" orders only, which meant every shift
        // close would report zero sales even though the register had real
        // transactions. Checking both statuses covers orders a staff member
        // may have manually marked completed afterward too.
        $orders = wc_get_orders( array(
            'created_via' => 'wc_pos_pro',
            'date_after'  => $shift->opened_at,
            'status'      => array( 'wc-processing', 'wc-completed' ),
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
     * Maximum failed PIN attempts allowed before a temporary lockout kicks in.
     */
    const PIN_MAX_ATTEMPTS = 5;

    /**
     * Lockout duration, in seconds, once PIN_MAX_ATTEMPTS is reached.
     */
    const PIN_LOCKOUT_SECONDS = 300; // 5 minutes

    /**
     * Verify a cashier's PIN server-side.
     * Payload: { "pin": "1234" }
     * Returns: { "success": true } or HTTP 401 / 429.
     *
     * Security fix: PINs are 4–8 digits, and the fallback "1234" default is
     * accepted for any user who hasn't set a personal PIN yet — both of which
     * make this endpoint brute-forceable with unlimited attempts. This adds a
     * per-user attempt counter with a temporary lockout once PIN_MAX_ATTEMPTS
     * is exceeded, tracked in user meta so it survives across requests without
     * needing a new table.
     */
    public function verify_pin( $request ) {
        $params  = $request->get_json_params();
        $raw_pin = sanitize_text_field( $params['pin'] ?? '' );

        if ( empty( $raw_pin ) ) {
            return new \WP_REST_Response( array( 'success' => false, 'message' => 'PIN is required.' ), 400 );
        }

        $user_id = get_current_user_id();

        $lockout_check = $this->check_pin_lockout( $user_id );
        if ( is_wp_error( $lockout_check ) ) {
            return new \WP_REST_Response( array(
                'success' => false,
                'message' => $lockout_check->get_error_message(),
                'lockedOutSeconds' => $lockout_check->get_error_data(),
            ), 429 );
        }

        $stored_pin = get_user_meta( $user_id, '_wc_pos_pin_hash', true );

        // If no PIN has been set yet, accept the default "1234" and prompt setup.
        if ( empty( $stored_pin ) ) {
            if ( '1234' === $raw_pin ) {
                $this->reset_pin_attempts( $user_id );
                return rest_ensure_response( array(
                    'success'     => true,
                    'requiresSetup' => true,
                    'message'     => __( 'Default PIN accepted. Please set a personal PIN.', 'wc-pos-pro' ),
                ) );
            }
            $this->record_failed_pin_attempt( $user_id );
            return new \WP_REST_Response( array( 'success' => false, 'message' => __( 'Incorrect PIN.', 'wc-pos-pro' ) ), 401 );
        }

        if ( wp_check_password( $raw_pin, $stored_pin, $user_id ) ) {
            $this->reset_pin_attempts( $user_id );
            return rest_ensure_response( array( 'success' => true, 'requiresSetup' => false ) );
        }

        $this->record_failed_pin_attempt( $user_id );
        return new \WP_REST_Response( array( 'success' => false, 'message' => __( 'Incorrect PIN.', 'wc-pos-pro' ) ), 401 );
    }

    /**
     * Returns a WP_Error (with seconds-remaining as error data) if this user is
     * currently locked out, or true if they're clear to attempt verification.
     */
    private function check_pin_lockout( $user_id ) {
        $locked_until = (int) get_user_meta( $user_id, '_wc_pos_pin_locked_until', true );

        if ( $locked_until && $locked_until > time() ) {
            $remaining = $locked_until - time();
            return new \WP_Error(
                'pin_locked_out',
                sprintf(
                    /* translators: %d: seconds remaining */
                    __( 'Too many incorrect PIN attempts. Try again in %d seconds.', 'wc-pos-pro' ),
                    $remaining
                ),
                $remaining
            );
        }

        return true;
    }

    /**
     * Increment the failed-attempt counter for a user and, once
     * PIN_MAX_ATTEMPTS is reached, set a lockout timestamp and reset the
     * counter so the next window starts clean.
     */
    private function record_failed_pin_attempt( $user_id ) {
        $attempts = (int) get_user_meta( $user_id, '_wc_pos_pin_failed_attempts', true ) + 1;

        if ( $attempts >= self::PIN_MAX_ATTEMPTS ) {
            update_user_meta( $user_id, '_wc_pos_pin_locked_until', time() + self::PIN_LOCKOUT_SECONDS );
            update_user_meta( $user_id, '_wc_pos_pin_failed_attempts', 0 );
            return;
        }

        update_user_meta( $user_id, '_wc_pos_pin_failed_attempts', $attempts );
    }

    /**
     * Clear the failed-attempt counter and any active lockout for a user,
     * called after a successful PIN verification or a PIN change.
     */
    private function reset_pin_attempts( $user_id ) {
        delete_user_meta( $user_id, '_wc_pos_pin_failed_attempts' );
        delete_user_meta( $user_id, '_wc_pos_pin_locked_until' );
    }

    /**
     * Set or change a cashier's PIN.
     * Payload: { "pin": "5678" }
     * PIN must be 4–8 numeric digits.
     */
    public function set_pin( $request ) {
        $params      = $request->get_json_params();
        $raw_pin     = sanitize_text_field( $params['pin'] ?? '' );
        $current_pin = sanitize_text_field( $params['currentPin'] ?? '' );

        if ( ! preg_match( '/^\d{4,8}$/', $raw_pin ) ) {
            return new \WP_REST_Response( array(
                'success' => false,
                'message' => __( 'New PIN must be 4 to 8 numeric digits.', 'wc-pos-pro' ),
            ), 400 );
        }

        $user_id = get_current_user_id();

        // Security fix: this previously set a new PIN with no verification
        // of the existing one at all — anyone at an unlocked, logged-in
        // terminal could silently change another cashier's PIN, locking
        // them out of their own account. A voluntary PIN change now
        // requires re-entering the current PIN first (rate-limited the same
        // way as normal PIN verification). The one-time forced setup right
        // after a successful default-PIN unlock passes '1234' as the
        // current PIN automatically — see unlockTerminal() — since that
        // flow already just verified it moments before.
        $lockout_check = $this->check_pin_lockout( $user_id );
        if ( is_wp_error( $lockout_check ) ) {
            return new \WP_REST_Response( array(
                'success'          => false,
                'message'          => $lockout_check->get_error_message(),
                'lockedOutSeconds' => $lockout_check->get_error_data(),
            ), 429 );
        }

        $stored_pin         = get_user_meta( $user_id, '_wc_pos_pin_hash', true );
        $current_pin_valid  = empty( $stored_pin )
            ? ( '1234' === $current_pin )
            : wp_check_password( $current_pin, $stored_pin, $user_id );

        if ( ! $current_pin_valid ) {
            $this->record_failed_pin_attempt( $user_id );
            return new \WP_REST_Response( array(
                'success' => false,
                'message' => __( 'Current PIN is incorrect.', 'wc-pos-pro' ),
            ), 401 );
        }

        $pin_hash = wp_hash_password( $raw_pin );
        update_user_meta( $user_id, '_wc_pos_pin_hash', $pin_hash );

        // A PIN change is also a clean slate for the lockout counter.
        $this->reset_pin_attempts( $user_id );

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
        // Wire up the previously-unused 'manage_wc_pos' capability as an
        // alternative to full 'manage_woocommerce' — lets a store grant
        // POS-management access to a custom role without full WooCommerce
        // admin rights.
        return current_user_can( 'manage_wc_pos' ) || current_user_can( 'manage_woocommerce' );
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
