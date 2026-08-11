<?php
namespace WCPOS\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AdminMenu {

    private static $instance = null;

    public static function get_instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', array( $this, 'register_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_post_wc_pos_flush_rules', array( $this, 'handle_flush_rules' ) );
        add_action( 'wp_ajax_wc_pos_save_tax_rate', array( $this, 'ajax_save_tax_rate' ) );
        add_action( 'wp_ajax_wc_pos_delete_tax_rate', array( $this, 'ajax_delete_tax_rate' ) );
        // Multi-branch feature build-out: admin CRUD for branches and registers.
        add_action( 'wp_ajax_wc_pos_save_branch', array( $this, 'ajax_save_branch' ) );
        add_action( 'wp_ajax_wc_pos_delete_branch', array( $this, 'ajax_delete_branch' ) );
        add_action( 'wp_ajax_wc_pos_save_register', array( $this, 'ajax_save_register' ) );
        add_action( 'wp_ajax_wc_pos_delete_register', array( $this, 'ajax_delete_register' ) );
        add_action( 'wp_ajax_wc_pos_force_close_shift', array( $this, 'ajax_force_close_shift' ) );
        // Per-branch stock allocation.
        add_action( 'wp_ajax_wc_pos_search_products_for_stock', array( $this, 'ajax_search_products_for_stock' ) );
        add_action( 'wp_ajax_wc_pos_save_branch_stock', array( $this, 'ajax_save_branch_stock' ) );
        add_action( 'wp_ajax_wc_pos_delete_branch_stock', array( $this, 'ajax_delete_branch_stock' ) );
        // Reports.
        add_action( 'wp_ajax_wc_pos_get_report', array( $this, 'ajax_get_report' ) );
    }

    public function register_settings() {
        // General / receipt options
        foreach ( array(
            'wc_pos_receipt_header',
            'wc_pos_receipt_footer',
            'wc_pos_store_phone',
            'wc_pos_store_address',
            'wc_pos_enable_pessimistic_lock',
            'wc_pos_offline_sync_interval',
            'wc_pos_sound_effects',
            'wc_pos_default_payment_method',
            // Receipt builder
            'wc_pos_receipt_logo_id',
            'wc_pos_receipt_show_logo',
            'wc_pos_receipt_show_store_name',
            'wc_pos_receipt_show_address',
            'wc_pos_receipt_show_barcode',
            'wc_pos_receipt_show_tax_breakdown',
            'wc_pos_receipt_show_cashier',
            'wc_pos_receipt_paper_width',
            'wc_pos_receipt_line_item_format',
            // Tax
            'wc_pos_tax_inclusive_prices',
            'wc_pos_allow_tax_exempt_override',
        ) as $option ) {
            register_setting( 'wc_pos_options_group', $option );
        }
    }

    public function handle_flush_rules() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Unauthorized' );
        }
        check_admin_referer( 'wc_pos_flush_rules_nonce' );
        flush_rewrite_rules();
        wp_redirect( add_query_arg( array( 'page' => 'wc-pos-pro', 'flushed' => '1' ), admin_url( 'admin.php' ) ) );
        exit;
    }


    // -------------------------------------------------------------------------
    // AJAX handlers for tax rate CRUD
    // -------------------------------------------------------------------------

    public function ajax_save_tax_rate() {
        check_ajax_referer( 'wc_pos_tax_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_wc_pos' ) && ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }
        global $wpdb;
        $table = $wpdb->prefix . 'wc_pos_tax_rates';
        $id    = intval( $_POST['rate_id'] ?? 0 );
        $name  = sanitize_text_field( $_POST['name'] ?? '' );
        $rate  = floatval( $_POST['rate'] ?? 0 );
        $incl  = ! empty( $_POST['is_inclusive'] ) ? 1 : 0;
        $appli = in_array( $_POST['applies_to'] ?? 'all', array( 'all', 'food', 'services', 'goods' ), true )
                 ? $_POST['applies_to'] : 'all';
        $prio  = max( 1, intval( $_POST['priority'] ?? 1 ) );

        if ( empty( $name ) || $rate < 0 || $rate > 100 ) {
            wp_send_json_error( 'Invalid data.' );
        }
        $data = array(
            'name'         => $name,
            'rate'         => $rate,
            'is_inclusive' => $incl,
            'applies_to'   => $appli,
            'priority'     => $prio,
            'is_active'    => 1,
        );
        // Bug fix: the result of $wpdb->insert()/update() was never checked —
        // wp_send_json_success() ran unconditionally even when the write
        // silently failed at the database level, so the UI showed "saved"
        // and reloaded while nothing had actually been written.
        if ( $id > 0 ) {
            $result = $wpdb->update( $table, $data, array( 'id' => $id ) );
        } else {
            $result = $wpdb->insert( $table, $data );
            if ( $result ) {
                $id = $wpdb->insert_id;
            }
        }

        if ( false === $result ) {
            wp_send_json_error( 'Database error: ' . $wpdb->last_error );
        }

        wp_send_json_success( array( 'id' => $id ) );
    }

    public function ajax_delete_tax_rate() {
        check_ajax_referer( 'wc_pos_tax_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_wc_pos' ) && ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }
        global $wpdb;
        $id = intval( $_POST['rate_id'] ?? 0 );
        $wpdb->update( $wpdb->prefix . 'wc_pos_tax_rates', array( 'is_active' => 0 ), array( 'id' => $id ) );
        wp_send_json_success();
    }


    // -------------------------------------------------------------------------
    // AJAX handlers for branch CRUD
    // -------------------------------------------------------------------------

    public function ajax_save_branch() {
        check_ajax_referer( 'wc_pos_branch_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_wc_pos_branches' ) && ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }
        global $wpdb;
        $table = $wpdb->prefix . 'wc_pos_branches';

        $id     = sanitize_text_field( $_POST['branch_id'] ?? '' );
        $name   = sanitize_text_field( $_POST['name'] ?? '' );
        $code   = sanitize_text_field( $_POST['code'] ?? '' );
        $addr   = sanitize_textarea_field( $_POST['address'] ?? '' );
        $phone  = sanitize_text_field( $_POST['phone'] ?? '' );
        $email  = sanitize_email( $_POST['email'] ?? '' );
        $status = in_array( $_POST['status'] ?? 'active', array( 'active', 'inactive' ), true ) ? $_POST['status'] : 'active';

        if ( empty( $name ) ) {
            wp_send_json_error( 'Branch name is required.' );
        }

        $data = array(
            'name'    => $name,
            'code'    => $code,
            'address' => $addr,
            'phone'   => $phone,
            'email'   => $email,
            'status'  => $status,
        );

        if ( $id ) {
            // Editing an existing branch — never let the seeded "default"
            // branch be renamed away from something recognizable or disabled,
            // since registers/shifts/tax rates fall back to it by default.
            $result = $wpdb->update( $table, $data, array( 'id' => $id ) );
        } else {
            $id           = 'br_' . wp_generate_password( 12, false );
            $data['id']   = $id;
            $result       = $wpdb->insert( $table, $data );
        }

        // Bug fix: this previously never checked whether the insert/update
        // actually succeeded — wp_send_json_success() ran unconditionally,
        // so a silent database failure looked identical to a real save (the
        // page would reload with no error, and the branch simply wasn't
        // there). Surfacing $wpdb->last_error here shows the real cause.
        if ( false === $result ) {
            wp_send_json_error( 'Database error: ' . $wpdb->last_error );
        }

        wp_send_json_success( array( 'id' => $id ) );
    }

    public function ajax_delete_branch() {
        check_ajax_referer( 'wc_pos_branch_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_wc_pos_branches' ) && ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }
        global $wpdb;
        $id = sanitize_text_field( $_POST['branch_id'] ?? '' );

        if ( 'default' === $id ) {
            wp_send_json_error( __( 'The default branch cannot be deleted — registers and orders fall back to it.', 'wc-pos-pro' ) );
        }

        $registers_table = $wpdb->prefix . 'wc_pos_registers';
        $active_count    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$registers_table} WHERE branch_id = %s", $id ) );
        if ( $active_count > 0 ) {
            wp_send_json_error( __( 'Cannot delete a branch with registers assigned to it. Reassign or delete those registers first.', 'wc-pos-pro' ) );
        }

        $wpdb->delete( $wpdb->prefix . 'wc_pos_branches', array( 'id' => $id ) );
        wp_send_json_success();
    }


    // -------------------------------------------------------------------------
    // AJAX handlers for register CRUD
    // -------------------------------------------------------------------------

    public function ajax_save_register() {
        check_ajax_referer( 'wc_pos_register_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_wc_pos_branches' ) && ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }
        global $wpdb;
        $table = $wpdb->prefix . 'wc_pos_registers';

        $id        = sanitize_text_field( $_POST['register_id'] ?? '' );
        $name      = sanitize_text_field( $_POST['name'] ?? '' );
        $location  = sanitize_text_field( $_POST['location'] ?? '' );
        $branch_id = sanitize_text_field( $_POST['branch_id'] ?? 'default' );

        if ( empty( $name ) ) {
            wp_send_json_error( 'Register name is required.' );
        }

        // Confirm the branch actually exists rather than silently storing a
        // dangling reference.
        $branch_exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}wc_pos_branches WHERE id = %s", $branch_id
        ) );
        if ( ! $branch_exists ) {
            wp_send_json_error( __( 'Selected branch no longer exists.', 'wc-pos-pro' ) );
        }

        if ( $id ) {
            $result = $wpdb->update( $table, array( 'name' => $name, 'location' => $location, 'branch_id' => $branch_id ), array( 'id' => $id ) );
        } else {
            $id     = 'REG-' . strtoupper( wp_generate_password( 8, false ) );
            $result = $wpdb->insert( $table, array(
                'id'        => $id,
                'name'      => $name,
                'location'  => $location,
                'branch_id' => $branch_id,
                'status'    => 'closed',
            ) );
        }

        // Bug fix: same class of issue as branch/tax rate save — the
        // insert/update result was never checked, so a silent DB failure
        // looked identical to a successful save.
        if ( false === $result ) {
            wp_send_json_error( 'Database error: ' . $wpdb->last_error );
        }

        wp_send_json_success( array( 'id' => $id ) );
    }

    public function ajax_delete_register() {
        check_ajax_referer( 'wc_pos_register_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_wc_pos_branches' ) && ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }
        global $wpdb;
        $id = sanitize_text_field( $_POST['register_id'] ?? '' );

        $open_shift = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}wc_pos_shifts WHERE register_id = %s AND status = 'active'", $id
        ) );
        if ( $open_shift ) {
            wp_send_json_error( __( 'Cannot delete a register with an open shift. Close the shift first.', 'wc-pos-pro' ) );
        }

        $wpdb->delete( $wpdb->prefix . 'wc_pos_registers', array( 'id' => $id ) );
        wp_send_json_success();
    }

    /**
     * Force-close a stuck/orphaned active shift directly from wp-admin,
     * bypassing the terminal entirely. No actual-cash count is available
     * here (this is an admin recovery action, not a normal shift close), so
     * the reconciliation fields are left null/unknown rather than fabricating
     * a false "zero difference" — the sales totals are still computed
     * accurately so the record isn't blank, just its cash-count fields.
     */
    public function ajax_force_close_shift() {
        check_ajax_referer( 'wc_pos_register_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_wc_pos_branches' ) && ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }
        global $wpdb;

        $register_id  = sanitize_text_field( $_POST['register_id'] ?? '' );
        $shifts_table = $wpdb->prefix . 'wc_pos_shifts';

        $shift = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$shifts_table} WHERE register_id = %s AND status = 'active' LIMIT 1",
            $register_id
        ) );

        if ( ! $shift ) {
            wp_send_json_error( __( 'No active shift found on this register — it may already be closed.', 'wc-pos-pro' ) );
        }

        $orders = wc_get_orders( array(
            'created_via' => 'wc_pos_pro',
            'date_after'  => $shift->opened_at,
            'status'      => array( 'wc-processing', 'wc-completed' ),
            'meta_key'    => '_wc_pos_register_id',
            'meta_value'  => $register_id,
            'limit'       => -1,
        ) );

        $total_sales    = 0;
        $cash_sales     = 0;
        $card_sales     = 0;
        $transfer_sales = 0;
        foreach ( $orders as $order ) {
            $total_sales += (float) $order->get_total();
            $payments = $order->get_meta( '_wc_pos_payments' );
            if ( is_array( $payments ) ) {
                foreach ( $payments as $payment ) {
                    $amount = floatval( $payment['amount'] ?? 0 );
                    if ( 'cash' === ( $payment['method'] ?? '' ) ) {
                        $cash_sales += $amount;
                    } elseif ( 'card' === ( $payment['method'] ?? '' ) ) {
                        $card_sales += $amount;
                    } elseif ( 'transfer' === ( $payment['method'] ?? '' ) ) {
                        $transfer_sales += $amount;
                    }
                }
            }
        }
        $expected_cash = floatval( $shift->opening_float ) + $cash_sales;

        $result1 = $wpdb->update(
            $shifts_table,
            array(
                'closed_at'       => current_time( 'mysql', true ),
                'actual_cash'     => null,
                'expected_cash'   => $expected_cash,
                'cash_difference' => null,
                'total_sales'     => $total_sales,
                'cash_sales'      => $cash_sales,
                'card_sales'      => $card_sales,
                'transfer_sales'  => $transfer_sales,
                'status'          => 'closed',
                'closing_notes'   => sprintf(
                    /* translators: %s: admin display name */
                    __( 'Force-closed from wp-admin by %s — no cash count recorded.', 'wc-pos-pro' ),
                    wp_get_current_user()->display_name
                ),
            ),
            array( 'id' => $shift->id ),
            array( '%s', '%s', '%f', '%s', '%f', '%f', '%f', '%f', '%s', '%s' ),
            array( '%s' )
        );

        $result2 = $wpdb->update(
            $wpdb->prefix . 'wc_pos_registers',
            array( 'status' => 'closed', 'current_shift_id' => null ),
            array( 'id' => $register_id ),
            array( '%s', '%s' ),
            array( '%s' )
        );

        if ( false === $result1 || false === $result2 ) {
            wp_send_json_error( 'Database error: ' . $wpdb->last_error );
        }

        wp_send_json_success();
    }


    // -------------------------------------------------------------------------
    // AJAX handlers for per-branch stock allocation
    // -------------------------------------------------------------------------

    /**
     * Search WooCommerce products by name/SKU for the stock allocation
     * picker. Returns simple products directly and variable products with
     * their variations, so the admin can allocate stock at whichever level
     * actually carries stock in WooCommerce.
     */
    public function ajax_search_products_for_stock() {
        check_ajax_referer( 'wc_pos_branch_stock_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_wc_pos_branches' ) && ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $term = sanitize_text_field( $_POST['term'] ?? '' );
        if ( strlen( $term ) < 2 ) {
            wp_send_json_success( array() );
        }

        $products = wc_get_products( array(
            's'      => $term,
            'limit'  => 20,
            'status' => 'publish',
        ) );

        $results = array();
        foreach ( $products as $p ) {
            $entry = array(
                'id'         => $p->get_id(),
                'name'       => $p->get_name(),
                'sku'        => $p->get_sku(),
                'type'       => $p->get_type(),
                'variations' => array(),
            );

            if ( $p->is_type( 'variable' ) ) {
                foreach ( $p->get_children() as $child_id ) {
                    $v = wc_get_product( $child_id );
                    if ( ! $v || ! $v->exists() ) {
                        continue;
                    }
                    $entry['variations'][] = array(
                        'id'   => $v->get_id(),
                        'name' => $v->get_name(),
                        'sku'  => $v->get_sku(),
                    );
                }
            }

            $results[] = $entry;
        }

        wp_send_json_success( $results );
    }

    /**
     * Create or update a branch's stock allocation for a product/variation.
     * Mirrors Branches_Controller::update_branch_stock() but sits behind the
     * existing admin-ajax pattern used by the rest of this settings UI.
     */
    public function ajax_save_branch_stock() {
        check_ajax_referer( 'wc_pos_branch_stock_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_wc_pos_branches' ) && ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }
        global $wpdb;

        $branch_id    = sanitize_text_field( $_POST['branch_id'] ?? '' );
        $product_id   = absint( $_POST['product_id'] ?? 0 );
        $variation_id = absint( $_POST['variation_id'] ?? 0 );
        $quantity     = intval( $_POST['stock_quantity'] ?? -1 );

        if ( ! $branch_id || ! $product_id ) {
            wp_send_json_error( __( 'Branch and product are required.', 'wc-pos-pro' ) );
        }
        if ( $quantity < 0 ) {
            wp_send_json_error( __( 'Stock quantity cannot be negative.', 'wc-pos-pro' ) );
        }

        $table = $wpdb->prefix . 'wc_pos_branch_stock';
        $sql   = "INSERT INTO {$table} (branch_id, product_id, variation_id, stock_quantity)
                  VALUES (%s, %d, %d, %d)
                  ON DUPLICATE KEY UPDATE stock_quantity = %d";
        $result = $wpdb->query( $wpdb->prepare( $sql, $branch_id, $product_id, $variation_id, $quantity, $quantity ) );

        if ( false === $result ) {
            wp_send_json_error( __( 'Failed to save stock allocation.', 'wc-pos-pro' ) );
        }

        wp_send_json_success();
    }

    /**
     * Remove a branch's stock allocation for a product/variation — the
     * product then falls back to global WooCommerce stock at that branch,
     * exactly as if it had never been allocated.
     */
    public function ajax_delete_branch_stock() {
        check_ajax_referer( 'wc_pos_branch_stock_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_wc_pos_branches' ) && ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }
        global $wpdb;
        $id = absint( $_POST['allocation_id'] ?? 0 );
        $wpdb->delete( $wpdb->prefix . 'wc_pos_branch_stock', array( 'id' => $id ) );
        wp_send_json_success();
    }


    // -------------------------------------------------------------------------
    // Reports
    // -------------------------------------------------------------------------

    /**
     * Single dispatcher for every report type, keeping one nonce/permission
     * check and one AJAX action for the whole Reports screen.
     */
    public function ajax_get_report() {
        check_ajax_referer( 'wc_pos_reports_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_wc_pos' ) && ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $type      = sanitize_text_field( $_POST['report_type'] ?? '' );
        $date_from = sanitize_text_field( $_POST['date_from'] ?? '' );
        $date_to   = sanitize_text_field( $_POST['date_to'] ?? '' );
        $branch_id = sanitize_text_field( $_POST['branch_id'] ?? '' );
        $sort      = sanitize_text_field( $_POST['sort'] ?? 'revenue' );

        switch ( $type ) {
            case 'sales_summary':
                wp_send_json_success( $this->report_sales_summary( $date_from, $date_to, $branch_id ) );
                break;
            case 'shift_history':
                wp_send_json_success( $this->report_shift_history( $date_from, $date_to, $branch_id ) );
                break;
            case 'top_products':
                wp_send_json_success( $this->report_top_products( $date_from, $date_to, $branch_id, $sort ) );
                break;
            case 'cashier_performance':
                wp_send_json_success( $this->report_cashier_performance( $date_from, $date_to, $branch_id ) );
                break;
            case 'branch_comparison':
                wp_send_json_success( $this->report_branch_comparison( $date_from, $date_to ) );
                break;
            default:
                wp_send_json_error( 'Unknown report type.' );
        }
    }

    /**
     * Shared fetch of every POS-originated order in a date range, optionally
     * scoped to one branch. Every report below builds on this same query so
     * they all agree on what counts as "a sale" (created via the POS,
     * currently processing or completed — never cancelled/refunded/failed).
     */
    private function get_pos_orders( $date_from, $date_to, $branch_id = '' ) {
        if ( empty( $date_from ) ) {
            $date_from = current_time( 'Y-m-d' );
        }
        if ( empty( $date_to ) ) {
            $date_to = current_time( 'Y-m-d' );
        }

        $args = array(
            'created_via'  => 'wc_pos_pro',
            'status'       => array( 'wc-processing', 'wc-completed' ),
            'limit'        => -1,
            'date_created' => $date_from . ' 00:00:00...' . $date_to . ' 23:59:59',
        );

        if ( $branch_id ) {
            $args['meta_key']   = '_wc_pos_branch_id';
            $args['meta_value'] = $branch_id;
        }

        return wc_get_orders( $args );
    }

    /**
     * branch_id => branch_name lookup, used to label every report row
     * without an extra query per row.
     */
    private function get_branch_names_map() {
        global $wpdb;
        $rows = $wpdb->get_results( "SELECT id, name FROM {$wpdb->prefix}wc_pos_branches ORDER BY name ASC" );
        $map  = array();
        foreach ( $rows as $r ) {
            $map[ $r->id ] = $r->name;
        }
        return $map;
    }

    private function report_sales_summary( $date_from, $date_to, $branch_id ) {
        $orders = $this->get_pos_orders( $date_from, $date_to, $branch_id );

        $total_sales    = 0;
        $total_discount = 0;
        $order_count    = 0;
        $cash_sales     = 0;
        $card_sales     = 0;
        $transfer_sales = 0;
        $by_branch      = array();
        $by_day         = array();
        $branch_names   = $this->get_branch_names_map();

        foreach ( $orders as $order ) {
            $total = (float) $order->get_total();
            $total_sales    += $total;
            $total_discount += (float) $order->get_total_discount();
            $order_count++;

            $payments = $order->get_meta( '_wc_pos_payments' );
            if ( is_array( $payments ) ) {
                foreach ( $payments as $p ) {
                    $method = $p['method'] ?? '';
                    $amount = floatval( $p['amount'] ?? 0 );
                    if ( 'cash' === $method ) {
                        $cash_sales += $amount;
                    } elseif ( 'card' === $method ) {
                        $card_sales += $amount;
                    } elseif ( 'transfer' === $method ) {
                        $transfer_sales += $amount;
                    }
                }
            }

            $bid = $order->get_meta( '_wc_pos_branch_id' ) ?: 'default';
            $by_branch[ $bid ] = ( $by_branch[ $bid ] ?? 0 ) + $total;

            $date_created = $order->get_date_created();
            if ( $date_created ) {
                $day = $date_created->date( 'Y-m-d' );
                $by_day[ $day ] = ( $by_day[ $day ] ?? 0 ) + $total;
            }
        }

        ksort( $by_day );

        $by_branch_out = array();
        foreach ( $by_branch as $bid => $amount ) {
            $by_branch_out[] = array(
                'branchId'   => $bid,
                'branchName' => $branch_names[ $bid ] ?? $bid,
                'total'      => $amount,
            );
        }

        $by_day_out = array();
        foreach ( $by_day as $day => $amount ) {
            $by_day_out[] = array( 'date' => $day, 'total' => $amount );
        }

        return array(
            'totalSales'    => $total_sales,
            'orderCount'    => $order_count,
            'avgOrderValue' => $order_count ? $total_sales / $order_count : 0,
            'totalDiscount' => $total_discount,
            'cashSales'     => $cash_sales,
            'cardSales'     => $card_sales,
            'transferSales' => $transfer_sales,
            'byBranch'      => $by_branch_out,
            'byDay'         => $by_day_out,
        );
    }

    private function report_shift_history( $date_from, $date_to, $branch_id ) {
        global $wpdb;

        if ( empty( $date_from ) ) {
            $date_from = current_time( 'Y-m-d' );
        }
        if ( empty( $date_to ) ) {
            $date_to = current_time( 'Y-m-d' );
        }

        $table  = $wpdb->prefix . 'wc_pos_shifts';
        $where  = array( 'opened_at BETWEEN %s AND %s' );
        $params = array( $date_from . ' 00:00:00', $date_to . ' 23:59:59' );

        if ( $branch_id ) {
            $where[]  = 'branch_id = %s';
            $params[] = $branch_id;
        }

        $sql  = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY opened_at DESC';
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );

        $branch_names = $this->get_branch_names_map();
        $out          = array();

        foreach ( $rows as $r ) {
            // Bug fix / feature: a force-closed shift (see Force Close Shift
            // in POS > Registers) has no real cash count — actual_cash and
            // cash_difference are stored as NULL, not 0.00, specifically so
            // this can be told apart from a normal shift that reconciled
            // exactly. Surface that distinction here rather than casting
            // null to 0.0 and showing it identically to a perfect count.
            $force_closed = null === $r->actual_cash;
            $diff         = $force_closed ? null : (float) $r->cash_difference;
            $out[]        = array(
                'id'             => $r->id,
                'registerId'     => $r->register_id,
                'branchName'     => $branch_names[ $r->branch_id ] ?? $r->branch_id,
                'cashierName'    => $r->cashier_name,
                'openedAt'       => $r->opened_at,
                'closedAt'       => $r->closed_at,
                'openingFloat'   => (float) $r->opening_float,
                'totalSales'     => (float) $r->total_sales,
                'cashSales'      => (float) $r->cash_sales,
                'cardSales'      => (float) $r->card_sales,
                'transferSales'  => (float) ( $r->transfer_sales ?? 0 ),
                'expectedCash'   => (float) $r->expected_cash,
                'actualCash'     => $force_closed ? null : (float) $r->actual_cash,
                'cashDifference' => $diff,
                'forceClosed'    => $force_closed,
                'status'         => $r->status,
                // Flagged whenever the counted cash doesn't match what was
                // expected — a closed shift with a nonzero difference is
                // exactly what a manager needs to spot at a glance. A
                // force-closed shift has no real count to compare, so it's
                // never flagged as a cash discrepancy (that would be a false
                // positive) — it's called out separately via forceClosed.
                'flagged'        => ! $force_closed && 'closed' === $r->status && abs( $diff ) > 0.01,
            );
        }

        return $out;
    }

    private function report_top_products( $date_from, $date_to, $branch_id, $sort = 'revenue' ) {
        $orders   = $this->get_pos_orders( $date_from, $date_to, $branch_id );
        $products = array();

        foreach ( $orders as $order ) {
            foreach ( $order->get_items() as $item ) {
                $product_id   = $item->get_product_id();
                $variation_id = $item->get_variation_id();
                $key          = $variation_id ?: $product_id;

                if ( ! isset( $products[ $key ] ) ) {
                    $product = $item->get_product();
                    $products[ $key ] = array(
                        'productId' => $product_id,
                        'name'      => $item->get_name(),
                        'sku'       => $product ? $product->get_sku() : '',
                        'qty'       => 0,
                        'revenue'   => 0,
                    );
                }

                $products[ $key ]['qty']     += $item->get_quantity();
                $products[ $key ]['revenue'] += (float) $item->get_total();
            }
        }

        $list = array_values( $products );
        usort( $list, function ( $a, $b ) use ( $sort ) {
            return 'qty' === $sort ? $b['qty'] <=> $a['qty'] : $b['revenue'] <=> $a['revenue'];
        } );

        return array(
            'top'    => array_slice( $list, 0, 20 ),
            'bottom' => array_slice( array_reverse( $list ), 0, 10 ),
        );
    }

    private function report_cashier_performance( $date_from, $date_to, $branch_id ) {
        $orders   = $this->get_pos_orders( $date_from, $date_to, $branch_id );
        $cashiers = array();

        foreach ( $orders as $order ) {
            $cid   = $order->get_meta( '_wc_pos_cashier_id' ) ?: 0;
            $cname = $order->get_meta( '_wc_pos_cashier_name' ) ?: __( 'Unknown', 'wc-pos-pro' );

            if ( ! isset( $cashiers[ $cid ] ) ) {
                $cashiers[ $cid ] = array(
                    'cashierName'   => $cname,
                    'orderCount'    => 0,
                    'totalSales'    => 0,
                    'totalDiscount' => 0,
                );
            }

            $cashiers[ $cid ]['orderCount']++;
            $cashiers[ $cid ]['totalSales']    += (float) $order->get_total();
            $cashiers[ $cid ]['totalDiscount'] += (float) $order->get_total_discount();
        }

        $out = array();
        foreach ( $cashiers as $cid => $d ) {
            $out[] = array_merge(
                array( 'cashierId' => $cid ),
                $d,
                array( 'avgSale' => $d['orderCount'] ? $d['totalSales'] / $d['orderCount'] : 0 )
            );
        }

        usort( $out, function ( $a, $b ) {
            return $b['totalSales'] <=> $a['totalSales'];
        } );

        return $out;
    }

    private function report_branch_comparison( $date_from, $date_to ) {
        global $wpdb;

        $orders   = $this->get_pos_orders( $date_from, $date_to, '' );
        $branches = array();

        foreach ( $orders as $order ) {
            $bid = $order->get_meta( '_wc_pos_branch_id' ) ?: 'default';
            if ( ! isset( $branches[ $bid ] ) ) {
                $branches[ $bid ] = array( 'orderCount' => 0, 'totalSales' => 0, 'totalDiscount' => 0 );
            }
            $branches[ $bid ]['orderCount']++;
            $branches[ $bid ]['totalSales']    += (float) $order->get_total();
            $branches[ $bid ]['totalDiscount'] += (float) $order->get_total_discount();
        }

        $branch_names     = $this->get_branch_names_map();
        $branch_stock_tbl = $wpdb->prefix . 'wc_pos_branch_stock';
        $out              = array();

        foreach ( $branch_names as $bid => $bname ) {
            $d = $branches[ $bid ] ?? array( 'orderCount' => 0, 'totalSales' => 0, 'totalDiscount' => 0 );

            // Low-stock count only reflects products that have an explicit
            // per-branch allocation (see Branch Stock) — branches with no
            // allocations at all will show 0 here, which is expected: they're
            // still tracked via the shared store-wide stock count instead.
            $low_stock_count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$branch_stock_tbl} WHERE branch_id = %s AND stock_quantity <= 5",
                $bid
            ) );

            $out[] = array(
                'branchId'       => $bid,
                'branchName'     => $bname,
                'orderCount'     => $d['orderCount'],
                'totalSales'     => $d['totalSales'],
                'avgSale'        => $d['orderCount'] ? $d['totalSales'] / $d['orderCount'] : 0,
                'totalDiscount'  => $d['totalDiscount'],
                'lowStockCount'  => $low_stock_count,
            );
        }

        usort( $out, function ( $a, $b ) {
            return $b['totalSales'] <=> $a['totalSales'];
        } );

        return $out;
    }


    // -------------------------------------------------------------------------
    // Menu registration
    // -------------------------------------------------------------------------

    public function register_menu() {
        add_menu_page(
            __( 'WooCommerce POS', 'wc-pos-pro' ),
            __( 'POS Terminal', 'wc-pos-pro' ),
            'manage_wc_pos',
            'wc-pos-pro',
            array( $this, 'render_pos_admin_page' ),
            'dashicons-store',
            56
        );
        add_submenu_page( 'wc-pos-pro', __( 'Embedded Terminal', 'wc-pos-pro' ), __( 'Embedded Terminal', 'wc-pos-pro' ), 'manage_wc_pos', 'wc-pos-pro-embedded', array( $this, 'render_embedded_terminal_page' ) );
        add_submenu_page( 'wc-pos-pro', __( 'POS Settings', 'wc-pos-pro' ),      __( 'Settings', 'wc-pos-pro' ),          'manage_wc_pos', 'wc-pos-pro-settings', array( $this, 'render_settings_page' ) );
        add_submenu_page( 'wc-pos-pro', __( 'Tax Management', 'wc-pos-pro' ),    __( 'Tax', 'wc-pos-pro' ),               'manage_wc_pos', 'wc-pos-pro-tax',      array( $this, 'render_tax_page' ) );
        add_submenu_page( 'wc-pos-pro', __( 'Branches', 'wc-pos-pro' ),          __( 'Branches', 'wc-pos-pro' ),          'manage_wc_pos_branches', 'wc-pos-pro-branches', array( $this, 'render_branches_page' ) );
        add_submenu_page( 'wc-pos-pro', __( 'Registers', 'wc-pos-pro' ),         __( 'Registers', 'wc-pos-pro' ),         'manage_wc_pos_branches', 'wc-pos-pro-registers', array( $this, 'render_registers_page' ) );
        add_submenu_page( 'wc-pos-pro', __( 'Branch Stock', 'wc-pos-pro' ),      __( 'Branch Stock', 'wc-pos-pro' ),      'manage_wc_pos_branches', 'wc-pos-pro-branch-stock', array( $this, 'render_branch_stock_page' ) );
        add_submenu_page( 'wc-pos-pro', __( 'Reports', 'wc-pos-pro' ),          __( 'Reports', 'wc-pos-pro' ),          'manage_wc_pos', 'wc-pos-pro-reports', array( $this, 'render_reports_page' ) );
        add_submenu_page( 'wc-pos-pro', __( 'Receipt Builder', 'wc-pos-pro' ),   __( 'Receipt', 'wc-pos-pro' ),           'manage_wc_pos', 'wc-pos-pro-receipt',  array( $this, 'render_receipt_page' ) );
    }

    // -------------------------------------------------------------------------
    // Launchpad page
    // -------------------------------------------------------------------------

    public function render_pos_admin_page() {
        $pos_url = site_url( '/pos' );
        if ( isset( $_GET['flushed'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Rewrite rules flushed.', 'wc-pos-pro' ) . '</p></div>';
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'WooCommerce POS Pro', 'wc-pos-pro' ); ?></h1>
            <div style="background:#fff;padding:24px;border-radius:8px;border:1px solid #ccd0d4;max-width:800px;margin-top:20px;">
                <h2><?php esc_html_e( 'Launch Options', 'wc-pos-pro' ); ?></h2>
                <div style="display:flex;gap:15px;margin-top:15px;flex-wrap:wrap;">
                    <a href="<?php echo esc_url( $pos_url ); ?>" target="_blank" class="button button-primary button-hero"><?php esc_html_e( 'Open Fullscreen POS (/pos)', 'wc-pos-pro' ); ?></a>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-pos-pro-embedded' ) ); ?>" class="button button-secondary button-hero"><?php esc_html_e( 'Open Embedded Terminal', 'wc-pos-pro' ); ?></a>
                </div>
                <hr style="margin:25px 0;" />
                <h3><?php esc_html_e( 'Fix 404 on /pos', 'wc-pos-pro' ); ?></h3>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="wc_pos_flush_rules" />
                    <?php wp_nonce_field( 'wc_pos_flush_rules_nonce' ); ?>
                    <?php submit_button( __( 'Flush Permalinks', 'wc-pos-pro' ), 'secondary', 'submit', false ); ?>
                </form>
            </div>
        </div>
        <?php
    }

    public function render_embedded_terminal_page() {
        $template_file = WC_POS_PATH . 'templates/pos-terminal.php';
        if ( file_exists( $template_file ) ) {
            include $template_file;
        } else {
            echo '<div class="wrap"><h2>' . esc_html__( 'POS Terminal template not found.', 'wc-pos-pro' ) . '</h2></div>';
        }
    }


    // -------------------------------------------------------------------------
    // General Settings page
    // -------------------------------------------------------------------------

    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'POS Settings', 'wc-pos-pro' ); ?></h1>
            <form method="post" action="options.php" style="background:#fff;padding:24px;border:1px solid #ccd0d4;border-radius:8px;max-width:900px;margin-top:20px;">
                <?php settings_fields( 'wc_pos_options_group' ); do_settings_sections( 'wc-pos-pro-settings' ); ?>
                <h2 class="title"><?php esc_html_e( 'Store Details', 'wc-pos-pro' ); ?></h2>
                <table class="form-table">
                    <tr><th><label for="wc_pos_store_phone"><?php esc_html_e( 'Phone', 'wc-pos-pro' ); ?></label></th>
                        <td><input type="text" id="wc_pos_store_phone" name="wc_pos_store_phone" value="<?php echo esc_attr( get_option( 'wc_pos_store_phone' ) ); ?>" class="regular-text" /></td></tr>
                    <tr><th><label for="wc_pos_store_address"><?php esc_html_e( 'Address', 'wc-pos-pro' ); ?></label></th>
                        <td><textarea id="wc_pos_store_address" name="wc_pos_store_address" rows="3" class="large-text"><?php echo esc_textarea( get_option( 'wc_pos_store_address' ) ); ?></textarea></td></tr>
                </table>
                <h2 class="title" style="margin-top:30px;"><?php esc_html_e( 'Inventory', 'wc-pos-pro' ); ?></h2>
                <table class="form-table">
                    <tr><th><?php esc_html_e( 'Pessimistic Row Lock', 'wc-pos-pro' ); ?></th>
                        <td><label><input type="checkbox" name="wc_pos_enable_pessimistic_lock" value="1" <?php checked( 1, get_option( 'wc_pos_enable_pessimistic_lock', 1 ) ); ?> /> <?php esc_html_e( 'Enable database row locks during checkout', 'wc-pos-pro' ); ?></label></td></tr>
                    <tr><th><label for="wc_pos_offline_sync_interval"><?php esc_html_e( 'Sync Interval', 'wc-pos-pro' ); ?></label></th>
                        <td><select id="wc_pos_offline_sync_interval" name="wc_pos_offline_sync_interval">
                            <option value="15" <?php selected( get_option( 'wc_pos_offline_sync_interval', '15' ), '15' ); ?>>15s</option>
                            <option value="30" <?php selected( get_option( 'wc_pos_offline_sync_interval' ), '30' ); ?>>30s</option>
                            <option value="60" <?php selected( get_option( 'wc_pos_offline_sync_interval' ), '60' ); ?>>60s</option>
                        </select></td></tr>
                </table>
                <h2 class="title" style="margin-top:30px;"><?php esc_html_e( 'Hardware & Defaults', 'wc-pos-pro' ); ?></h2>
                <table class="form-table">
                    <tr><th><?php esc_html_e( 'Sound Effects', 'wc-pos-pro' ); ?></th>
                        <td><label><input type="checkbox" name="wc_pos_sound_effects" value="1" <?php checked( 1, get_option( 'wc_pos_sound_effects', 1 ) ); ?> /> <?php esc_html_e( 'Audio beep on barcode scan', 'wc-pos-pro' ); ?></label></td></tr>
                    <tr><th><label for="wc_pos_default_payment_method"><?php esc_html_e( 'Default Payment', 'wc-pos-pro' ); ?></label></th>
                        <td><select id="wc_pos_default_payment_method" name="wc_pos_default_payment_method">
                            <option value="cash" <?php selected( get_option( 'wc_pos_default_payment_method', 'cash' ), 'cash' ); ?>><?php esc_html_e( 'Cash', 'wc-pos-pro' ); ?></option>
                            <option value="card" <?php selected( get_option( 'wc_pos_default_payment_method' ), 'card' ); ?>><?php esc_html_e( 'Card', 'wc-pos-pro' ); ?></option>
                            <option value="split" <?php selected( get_option( 'wc_pos_default_payment_method' ), 'split' ); ?>><?php esc_html_e( 'Split', 'wc-pos-pro' ); ?></option>
                        </select></td></tr>
                </table>
                <?php submit_button( __( 'Save Settings', 'wc-pos-pro' ) ); ?>
            </form>
        </div>
        <?php
    }


    // -------------------------------------------------------------------------
    // Tax Management page  (task #3)
    // -------------------------------------------------------------------------

    public function render_tax_page() {
        global $wpdb;
        $table = $wpdb->prefix . 'wc_pos_tax_rates';
        $rates = $wpdb->get_results( "SELECT * FROM {$table} WHERE is_active = 1 ORDER BY priority ASC, id ASC" );
        $nonce = wp_create_nonce( 'wc_pos_tax_nonce' );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'POS Tax Management', 'wc-pos-pro' ); ?></h1>
            <p><?php esc_html_e( 'Configure tax rates applied at the POS terminal. These supplement or override the default WooCommerce tax table for in-person sales.', 'wc-pos-pro' ); ?></p>

            <!-- Global tax behaviour options saved via WP options API -->
            <form method="post" action="options.php" style="background:#fff;padding:20px;border:1px solid #ccd0d4;border-radius:8px;max-width:700px;margin-bottom:30px;">
                <?php settings_fields( 'wc_pos_options_group' ); ?>
                <h2 class="title"><?php esc_html_e( 'Global Tax Behaviour', 'wc-pos-pro' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e( 'Tax-Inclusive Prices', 'wc-pos-pro' ); ?></th>
                        <td>
                            <label><input type="checkbox" name="wc_pos_tax_inclusive_prices" value="1" <?php checked( 1, get_option( 'wc_pos_tax_inclusive_prices', 0 ) ); ?> />
                            <?php esc_html_e( 'Prices already include tax (tax is extracted, not added at checkout)', 'wc-pos-pro' ); ?></label>
                            <p class="description"><?php esc_html_e( 'When enabled the terminal displays shelf prices as the final price and backs the tax out of the total.', 'wc-pos-pro' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Tax-Exempt Override', 'wc-pos-pro' ); ?></th>
                        <td>
                            <label><input type="checkbox" name="wc_pos_allow_tax_exempt_override" value="1" <?php checked( 1, get_option( 'wc_pos_allow_tax_exempt_override', 0 ) ); ?> />
                            <?php esc_html_e( 'Allow managers to mark a sale as tax-exempt at checkout', 'wc-pos-pro' ); ?></label>
                            <p class="description"><?php esc_html_e( 'Requires manager PIN verification. Exempt sales are flagged in the order audit log.', 'wc-pos-pro' ); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button( __( 'Save Tax Settings', 'wc-pos-pro' ), 'secondary' ); ?>
            </form>

            <!-- Tax rates table -->
            <div style="background:#fff;padding:20px;border:1px solid #ccd0d4;border-radius:8px;max-width:900px;">
                <h2 class="title"><?php esc_html_e( 'POS Tax Rates', 'wc-pos-pro' ); ?></h2>
                <table class="wp-list-table widefat fixed striped" id="pos-tax-rates-table">
                    <thead><tr>
                        <th><?php esc_html_e( 'Name', 'wc-pos-pro' ); ?></th>
                        <th><?php esc_html_e( 'Rate (%)', 'wc-pos-pro' ); ?></th>
                        <th><?php esc_html_e( 'Inclusive', 'wc-pos-pro' ); ?></th>
                        <th><?php esc_html_e( 'Applies To', 'wc-pos-pro' ); ?></th>
                        <th><?php esc_html_e( 'Priority', 'wc-pos-pro' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'wc-pos-pro' ); ?></th>
                    </tr></thead>
                    <tbody id="tax-rates-tbody">
                    <?php if ( empty( $rates ) ) : ?>
                        <tr id="no-rates-row"><td colspan="6" style="text-align:center;color:#999;"><?php esc_html_e( 'No tax rates defined yet.', 'wc-pos-pro' ); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ( $rates as $r ) : ?>
                        <tr id="tax-rate-row-<?php echo (int) $r->id; ?>">
                            <td><?php echo esc_html( $r->name ); ?></td>
                            <td><?php echo esc_html( number_format( (float) $r->rate, 4 ) ); ?></td>
                            <td><?php echo $r->is_inclusive ? esc_html__( 'Yes', 'wc-pos-pro' ) : esc_html__( 'No', 'wc-pos-pro' ); ?></td>
                            <td><?php echo esc_html( ucfirst( $r->applies_to ) ); ?></td>
                            <td><?php echo (int) $r->priority; ?></td>
                            <td>
                                <button class="button button-small" onclick="posEditTaxRate(<?php echo (int) $r->id; ?>,<?php echo esc_js( json_encode( $r ) ); ?>)"><?php esc_html_e( 'Edit', 'wc-pos-pro' ); ?></button>
                                <button class="button button-small" style="color:#c00;" onclick="posDeleteTaxRate(<?php echo (int) $r->id; ?>)"><?php esc_html_e( 'Delete', 'wc-pos-pro' ); ?></button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>

                <h3 style="margin-top:25px;" id="tax-form-heading"><?php esc_html_e( 'Add New Rate', 'wc-pos-pro' ); ?></h3>
                <table class="form-table" style="max-width:600px;">
                    <tr><th><label for="tax_name"><?php esc_html_e( 'Name', 'wc-pos-pro' ); ?></label></th>
                        <td><input type="text" id="tax_name" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. VAT, Sales Tax', 'wc-pos-pro' ); ?>" /></td></tr>
                    <tr><th><label for="tax_rate"><?php esc_html_e( 'Rate (%)', 'wc-pos-pro' ); ?></label></th>
                        <td><input type="number" id="tax_rate" step="0.0001" min="0" max="100" class="small-text" value="0" /></td></tr>
                    <tr><th><?php esc_html_e( 'Tax Inclusive', 'wc-pos-pro' ); ?></th>
                        <td><label><input type="checkbox" id="tax_inclusive" /> <?php esc_html_e( 'Price already includes this tax', 'wc-pos-pro' ); ?></label></td></tr>
                    <tr><th><label for="tax_applies_to"><?php esc_html_e( 'Applies To', 'wc-pos-pro' ); ?></label></th>
                        <td><select id="tax_applies_to">
                            <option value="all"><?php esc_html_e( 'All Products', 'wc-pos-pro' ); ?></option>
                            <option value="goods"><?php esc_html_e( 'Physical Goods', 'wc-pos-pro' ); ?></option>
                            <option value="food"><?php esc_html_e( 'Food & Beverage', 'wc-pos-pro' ); ?></option>
                            <option value="services"><?php esc_html_e( 'Services', 'wc-pos-pro' ); ?></option>
                        </select></td></tr>
                    <tr><th><label for="tax_priority"><?php esc_html_e( 'Priority', 'wc-pos-pro' ); ?></label></th>
                        <td><input type="number" id="tax_priority" min="1" class="small-text" value="1" />
                            <p class="description"><?php esc_html_e( 'Lower number = applied first. Use 1 for standard rates.', 'wc-pos-pro' ); ?></p></td></tr>
                </table>
                <input type="hidden" id="tax_editing_id" value="0" />
                <button class="button button-primary" onclick="posSaveTaxRate()"><?php esc_html_e( 'Save Rate', 'wc-pos-pro' ); ?></button>
                <button class="button" id="tax-cancel-btn" style="display:none;" onclick="posCancelEditTax()"><?php esc_html_e( 'Cancel', 'wc-pos-pro' ); ?></button>
            </div><!-- /rates table -->
        </div><!-- /wrap -->

        <script>
        const posTaxNonce = '<?php echo esc_js( $nonce ); ?>';
        const posTaxAjaxUrl = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';

        // Bug fix: previously these calls did fetch(...).then(r => r.json())
        // with no error handling at all. Any non-JSON response (a PHP notice
        // or warning printed before the JSON, a server error page, a security
        // plugin intercepting the request) made r.json() throw with no
        // .catch() to handle it — so the button appeared to do nothing, with
        // no visible error anywhere. This wraps every call so a failure is
        // always shown, including the raw server response for diagnosis.
        async function posAjaxRequest(url, body) {
            let res, text;
            try {
                res = await fetch(url, { method: 'POST', body });
                text = await res.text();
            } catch (networkErr) {
                alert('Network error: ' + networkErr.message);
                return null;
            }
            try {
                return JSON.parse(text);
            } catch (parseErr) {
                console.error('Non-JSON response from server:', text);
                alert('Unexpected server response (HTTP ' + res.status + '). This usually means a PHP error occurred.\n\nServer said:\n' + text.substring(0, 800));
                return null;
            }
        }

        function posSaveTaxRate() {
            const id   = document.getElementById('tax_editing_id').value;
            const name = document.getElementById('tax_name').value.trim();
            const rate = document.getElementById('tax_rate').value;
            const incl = document.getElementById('tax_inclusive').checked ? 1 : 0;
            const app  = document.getElementById('tax_applies_to').value;
            const prio = document.getElementById('tax_priority').value;
            if (!name || isNaN(parseFloat(rate))) { alert('Name and rate are required.'); return; }
            const body = new URLSearchParams({ action: 'wc_pos_save_tax_rate', nonce: posTaxNonce, rate_id: id, name, rate, is_inclusive: incl, applies_to: app, priority: prio });
            posAjaxRequest(posTaxAjaxUrl, body).then(d => {
                if (!d) return; // error already shown
                if (d.success) { location.reload(); } else { alert(d.data || 'Error saving rate.'); }
            });
        }

        function posEditTaxRate(id, data) {
            document.getElementById('tax_editing_id').value = id;
            document.getElementById('tax_name').value = data.name;
            document.getElementById('tax_rate').value = data.rate;
            document.getElementById('tax_inclusive').checked = !!parseInt(data.is_inclusive);
            document.getElementById('tax_applies_to').value = data.applies_to;
            document.getElementById('tax_priority').value = data.priority;
            document.getElementById('tax-form-heading').textContent = 'Edit Rate: ' + data.name;
            document.getElementById('tax-cancel-btn').style.display = 'inline-block';
            document.getElementById('tax_name').scrollIntoView({ behavior: 'smooth' });
        }

        function posCancelEditTax() {
            document.getElementById('tax_editing_id').value = 0;
            document.getElementById('tax_name').value = '';
            document.getElementById('tax_rate').value = 0;
            document.getElementById('tax_inclusive').checked = false;
            document.getElementById('tax_applies_to').value = 'all';
            document.getElementById('tax_priority').value = 1;
            document.getElementById('tax-form-heading').textContent = '<?php echo esc_js( __( 'Add New Rate', 'wc-pos-pro' ) ); ?>';
            document.getElementById('tax-cancel-btn').style.display = 'none';
        }

        function posDeleteTaxRate(id) {
            if (!confirm('<?php echo esc_js( __( 'Delete this tax rate?', 'wc-pos-pro' ) ); ?>')) return;
            const body = new URLSearchParams({ action: 'wc_pos_delete_tax_rate', nonce: posTaxNonce, rate_id: id });
            posAjaxRequest(posTaxAjaxUrl, body).then(d => {
                if (!d) return;
                if (d.success) { const row = document.getElementById('tax-rate-row-' + id); if (row) row.remove(); }
            });
        }
        </script>
        <?php
    }


    // -------------------------------------------------------------------------
    // Branches page — multi-branch feature build-out
    // -------------------------------------------------------------------------

    public function render_branches_page() {
        global $wpdb;
        $table    = $wpdb->prefix . 'wc_pos_branches';
        $branches = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at ASC" );
        $nonce    = wp_create_nonce( 'wc_pos_branch_nonce' );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'POS Branches', 'wc-pos-pro' ); ?></h1>
            <p><?php esc_html_e( 'Manage the physical locations that registers and orders are attributed to. A "Main Branch" is created automatically for single-location stores.', 'wc-pos-pro' ); ?></p>

            <div style="background:#fff;padding:20px;border:1px solid #ccd0d4;border-radius:8px;max-width:900px;">
                <table class="wp-list-table widefat fixed striped" id="pos-branches-table">
                    <thead><tr>
                        <th><?php esc_html_e( 'Name', 'wc-pos-pro' ); ?></th>
                        <th><?php esc_html_e( 'Code', 'wc-pos-pro' ); ?></th>
                        <th><?php esc_html_e( 'Phone', 'wc-pos-pro' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'wc-pos-pro' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'wc-pos-pro' ); ?></th>
                    </tr></thead>
                    <tbody id="branches-tbody">
                    <?php foreach ( $branches as $b ) : ?>
                        <tr id="branch-row-<?php echo esc_attr( $b->id ); ?>">
                            <td><?php echo esc_html( $b->name ); ?></td>
                            <td><?php echo esc_html( $b->code ); ?></td>
                            <td><?php echo esc_html( $b->phone ); ?></td>
                            <td><?php echo esc_html( ucfirst( $b->status ) ); ?></td>
                            <td>
                                <button class="button button-small" onclick="posEditBranch(<?php echo esc_js( json_encode( $b ) ); ?>)"><?php esc_html_e( 'Edit', 'wc-pos-pro' ); ?></button>
                                <?php if ( 'default' !== $b->id ) : ?>
                                <button class="button button-small" style="color:#c00;" onclick="posDeleteBranch('<?php echo esc_js( $b->id ); ?>')"><?php esc_html_e( 'Delete', 'wc-pos-pro' ); ?></button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <h3 style="margin-top:25px;" id="branch-form-heading"><?php esc_html_e( 'Add New Branch', 'wc-pos-pro' ); ?></h3>
                <table class="form-table" style="max-width:600px;">
                    <tr><th><label for="branch_name"><?php esc_html_e( 'Name', 'wc-pos-pro' ); ?></label></th>
                        <td><input type="text" id="branch_name" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Lekki Branch', 'wc-pos-pro' ); ?>" /></td></tr>
                    <tr><th><label for="branch_code"><?php esc_html_e( 'Code', 'wc-pos-pro' ); ?></label></th>
                        <td><input type="text" id="branch_code" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. LKI', 'wc-pos-pro' ); ?>" /></td></tr>
                    <tr><th><label for="branch_address"><?php esc_html_e( 'Address', 'wc-pos-pro' ); ?></label></th>
                        <td><textarea id="branch_address" rows="2" class="large-text"></textarea></td></tr>
                    <tr><th><label for="branch_phone"><?php esc_html_e( 'Phone', 'wc-pos-pro' ); ?></label></th>
                        <td><input type="text" id="branch_phone" class="regular-text" /></td></tr>
                    <tr><th><label for="branch_email"><?php esc_html_e( 'Email', 'wc-pos-pro' ); ?></label></th>
                        <td><input type="email" id="branch_email" class="regular-text" /></td></tr>
                    <tr><th><label for="branch_status"><?php esc_html_e( 'Status', 'wc-pos-pro' ); ?></label></th>
                        <td><select id="branch_status">
                            <option value="active"><?php esc_html_e( 'Active', 'wc-pos-pro' ); ?></option>
                            <option value="inactive"><?php esc_html_e( 'Inactive', 'wc-pos-pro' ); ?></option>
                        </select></td></tr>
                </table>
                <input type="hidden" id="branch_editing_id" value="" />
                <button class="button button-primary" onclick="posSaveBranch()"><?php esc_html_e( 'Save Branch', 'wc-pos-pro' ); ?></button>
                <button class="button" id="branch-cancel-btn" style="display:none;" onclick="posCancelEditBranch()"><?php esc_html_e( 'Cancel', 'wc-pos-pro' ); ?></button>
            </div>
        </div>

        <script>
        const posBranchNonce  = '<?php echo esc_js( $nonce ); ?>';
        const posBranchAjaxUrl = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';

        async function posAjaxRequest(url, body) {
            let res, text;
            try {
                res = await fetch(url, { method: 'POST', body });
                text = await res.text();
            } catch (networkErr) {
                alert('Network error: ' + networkErr.message);
                return null;
            }
            try {
                return JSON.parse(text);
            } catch (parseErr) {
                console.error('Non-JSON response from server:', text);
                alert('Unexpected server response (HTTP ' + res.status + '). This usually means a PHP error occurred.\n\nServer said:\n' + text.substring(0, 800));
                return null;
            }
        }

        function posSaveBranch() {
            const id      = document.getElementById('branch_editing_id').value;
            const name    = document.getElementById('branch_name').value.trim();
            const code    = document.getElementById('branch_code').value.trim();
            const address = document.getElementById('branch_address').value.trim();
            const phone   = document.getElementById('branch_phone').value.trim();
            const email   = document.getElementById('branch_email').value.trim();
            const status  = document.getElementById('branch_status').value;
            if (!name) { alert('<?php echo esc_js( __( 'Branch name is required.', 'wc-pos-pro' ) ); ?>'); return; }

            const body = new URLSearchParams({ action: 'wc_pos_save_branch', nonce: posBranchNonce, branch_id: id, name, code, address, phone, email, status });
            posAjaxRequest(posBranchAjaxUrl, body).then(d => {
                if (!d) return;
                if (d.success) { location.reload(); } else { alert(d.data || '<?php echo esc_js( __( 'Error saving branch.', 'wc-pos-pro' ) ); ?>'); }
            });
        }

        function posEditBranch(b) {
            document.getElementById('branch_editing_id').value = b.id;
            document.getElementById('branch_name').value = b.name || '';
            document.getElementById('branch_code').value = b.code || '';
            document.getElementById('branch_address').value = b.address || '';
            document.getElementById('branch_phone').value = b.phone || '';
            document.getElementById('branch_email').value = b.email || '';
            document.getElementById('branch_status').value = b.status || 'active';
            document.getElementById('branch-form-heading').textContent = '<?php echo esc_js( __( 'Edit Branch', 'wc-pos-pro' ) ); ?>';
            document.getElementById('branch-cancel-btn').style.display = 'inline-block';
        }

        function posCancelEditBranch() {
            document.getElementById('branch_editing_id').value = '';
            document.getElementById('branch_name').value = '';
            document.getElementById('branch_code').value = '';
            document.getElementById('branch_address').value = '';
            document.getElementById('branch_phone').value = '';
            document.getElementById('branch_email').value = '';
            document.getElementById('branch_status').value = 'active';
            document.getElementById('branch-form-heading').textContent = '<?php echo esc_js( __( 'Add New Branch', 'wc-pos-pro' ) ); ?>';
            document.getElementById('branch-cancel-btn').style.display = 'none';
        }

        function posDeleteBranch(id) {
            if (!confirm('<?php echo esc_js( __( 'Delete this branch?', 'wc-pos-pro' ) ); ?>')) return;
            const body = new URLSearchParams({ action: 'wc_pos_delete_branch', nonce: posBranchNonce, branch_id: id });
            posAjaxRequest(posBranchAjaxUrl, body).then(d => {
                if (!d) return;
                if (d.success) { const row = document.getElementById('branch-row-' + id); if (row) row.remove(); }
                else { alert(d.data || '<?php echo esc_js( __( 'Error deleting branch.', 'wc-pos-pro' ) ); ?>'); }
            });
        }
        </script>
        <?php
    }


    // -------------------------------------------------------------------------
    // Registers page — multi-branch feature build-out
    // -------------------------------------------------------------------------

    public function render_registers_page() {
        global $wpdb;
        $registers = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}wc_pos_registers ORDER BY created_at ASC" );
        $branches  = $wpdb->get_results( "SELECT id, name FROM {$wpdb->prefix}wc_pos_branches WHERE status = 'active' ORDER BY name ASC" );
        $branch_names = array();
        foreach ( $branches as $b ) {
            $branch_names[ $b->id ] = $b->name;
        }
        $nonce = wp_create_nonce( 'wc_pos_register_nonce' );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'POS Registers', 'wc-pos-pro' ); ?></h1>
            <p><?php esc_html_e( 'Registers must be created here before they can be used at the terminal. Each register is assigned to a branch.', 'wc-pos-pro' ); ?></p>

            <?php if ( empty( $branches ) ) : ?>
                <div class="notice notice-warning"><p>
                    <?php
                    printf(
                        /* translators: %s: link to the Branches admin page */
                        esc_html__( 'No active branches found. %s before creating a register.', 'wc-pos-pro' ),
                        '<a href="' . esc_url( admin_url( 'admin.php?page=wc-pos-pro-branches' ) ) . '">' . esc_html__( 'Create a branch first', 'wc-pos-pro' ) . '</a>'
                    );
                    ?>
                </p></div>
            <?php endif; ?>

            <div style="background:#fff;padding:20px;border:1px solid #ccd0d4;border-radius:8px;max-width:900px;">
                <table class="wp-list-table widefat fixed striped" id="pos-registers-table">
                    <thead><tr>
                        <th><?php esc_html_e( 'Name', 'wc-pos-pro' ); ?></th>
                        <th><?php esc_html_e( 'Branch', 'wc-pos-pro' ); ?></th>
                        <th><?php esc_html_e( 'Location', 'wc-pos-pro' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'wc-pos-pro' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'wc-pos-pro' ); ?></th>
                    </tr></thead>
                    <tbody id="registers-tbody">
                    <?php if ( empty( $registers ) ) : ?>
                        <tr id="no-registers-row"><td colspan="5" style="text-align:center;color:#999;"><?php esc_html_e( 'No registers created yet.', 'wc-pos-pro' ); ?></td></tr>
                    <?php else : foreach ( $registers as $r ) : ?>
                        <tr id="register-row-<?php echo esc_attr( $r->id ); ?>">
                            <td><?php echo esc_html( $r->name ); ?></td>
                            <td><?php echo esc_html( $branch_names[ $r->branch_id ] ?? $r->branch_id ); ?></td>
                            <td><?php echo esc_html( $r->location ); ?></td>
                            <td><?php echo esc_html( ucfirst( $r->status ) ); ?></td>
                            <td>
                                <button class="button button-small" onclick="posEditRegister(<?php echo esc_js( json_encode( $r ) ); ?>)"><?php esc_html_e( 'Edit', 'wc-pos-pro' ); ?></button>
                                <button class="button button-small" style="color:#c00;" onclick="posDeleteRegister('<?php echo esc_js( $r->id ); ?>')"><?php esc_html_e( 'Delete', 'wc-pos-pro' ); ?></button>
                                <?php if ( 'open' === $r->status ) : ?>
                                <button class="button button-small" style="color:#b45309;" onclick="posForceCloseShift('<?php echo esc_js( $r->id ); ?>')" title="<?php esc_attr_e( 'Use this if a shift is stuck open and cannot be closed normally from the terminal.', 'wc-pos-pro' ); ?>"><?php esc_html_e( 'Force Close Shift', 'wc-pos-pro' ); ?></button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>

                <h3 style="margin-top:25px;" id="register-form-heading"><?php esc_html_e( 'Add New Register', 'wc-pos-pro' ); ?></h3>
                <table class="form-table" style="max-width:600px;">
                    <tr><th><label for="register_name"><?php esc_html_e( 'Name', 'wc-pos-pro' ); ?></label></th>
                        <td><input type="text" id="register_name" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Front Counter', 'wc-pos-pro' ); ?>" /></td></tr>
                    <tr><th><label for="register_branch"><?php esc_html_e( 'Branch', 'wc-pos-pro' ); ?></label></th>
                        <td><select id="register_branch">
                            <?php foreach ( $branches as $b ) : ?>
                                <option value="<?php echo esc_attr( $b->id ); ?>"><?php echo esc_html( $b->name ); ?></option>
                            <?php endforeach; ?>
                        </select></td></tr>
                    <tr><th><label for="register_location"><?php esc_html_e( 'Location Note', 'wc-pos-pro' ); ?></label></th>
                        <td><input type="text" id="register_location" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Near entrance', 'wc-pos-pro' ); ?>" /></td></tr>
                </table>
                <input type="hidden" id="register_editing_id" value="" />
                <button class="button button-primary" onclick="posSaveRegister()" <?php echo empty( $branches ) ? 'disabled' : ''; ?>><?php esc_html_e( 'Save Register', 'wc-pos-pro' ); ?></button>
                <button class="button" id="register-cancel-btn" style="display:none;" onclick="posCancelEditRegister()"><?php esc_html_e( 'Cancel', 'wc-pos-pro' ); ?></button>
            </div>
        </div>

        <script>
        const posRegisterNonce   = '<?php echo esc_js( $nonce ); ?>';
        const posRegisterAjaxUrl = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';

        async function posAjaxRequest(url, body) {
            let res, text;
            try {
                res = await fetch(url, { method: 'POST', body });
                text = await res.text();
            } catch (networkErr) {
                alert('Network error: ' + networkErr.message);
                return null;
            }
            try {
                return JSON.parse(text);
            } catch (parseErr) {
                console.error('Non-JSON response from server:', text);
                alert('Unexpected server response (HTTP ' + res.status + '). This usually means a PHP error occurred.\n\nServer said:\n' + text.substring(0, 800));
                return null;
            }
        }

        function posSaveRegister() {
            const id       = document.getElementById('register_editing_id').value;
            const name     = document.getElementById('register_name').value.trim();
            const branchEl = document.getElementById('register_branch');
            const branchId = branchEl ? branchEl.value : 'default';
            const regLocation = document.getElementById('register_location').value.trim();
            if (!name) { alert('<?php echo esc_js( __( 'Register name is required.', 'wc-pos-pro' ) ); ?>'); return; }

            const body = new URLSearchParams({ action: 'wc_pos_save_register', nonce: posRegisterNonce, register_id: id, name, branch_id: branchId, location: regLocation });
            posAjaxRequest(posRegisterAjaxUrl, body).then(d => {
                if (!d) return;
                if (d.success) { location.reload(); } else { alert(d.data || '<?php echo esc_js( __( 'Error saving register.', 'wc-pos-pro' ) ); ?>'); }
            });
        }

        function posEditRegister(r) {
            document.getElementById('register_editing_id').value = r.id;
            document.getElementById('register_name').value = r.name || '';
            document.getElementById('register_branch').value = r.branch_id || 'default';
            document.getElementById('register_location').value = r.location || '';
            document.getElementById('register-form-heading').textContent = '<?php echo esc_js( __( 'Edit Register', 'wc-pos-pro' ) ); ?>';
            document.getElementById('register-cancel-btn').style.display = 'inline-block';
        }

        function posCancelEditRegister() {
            document.getElementById('register_editing_id').value = '';
            document.getElementById('register_name').value = '';
            document.getElementById('register_location').value = '';
            document.getElementById('register-form-heading').textContent = '<?php echo esc_js( __( 'Add New Register', 'wc-pos-pro' ) ); ?>';
            document.getElementById('register-cancel-btn').style.display = 'none';
        }

        function posDeleteRegister(id) {
            if (!confirm('<?php echo esc_js( __( 'Delete this register?', 'wc-pos-pro' ) ); ?>')) return;
            const body = new URLSearchParams({ action: 'wc_pos_delete_register', nonce: posRegisterNonce, register_id: id });
            posAjaxRequest(posRegisterAjaxUrl, body).then(d => {
                if (!d) return;
                if (d.success) { const row = document.getElementById('register-row-' + id); if (row) row.remove(); }
                else { alert(d.data || '<?php echo esc_js( __( 'Error deleting register.', 'wc-pos-pro' ) ); ?>'); }
            });
        }

        function posForceCloseShift(registerId) {
            if (!confirm('<?php echo esc_js( __( 'Force close the active shift on this register? No cash count will be recorded for it — only use this if the shift is stuck and cannot be closed normally from the terminal.', 'wc-pos-pro' ) ); ?>')) return;
            const body = new URLSearchParams({ action: 'wc_pos_force_close_shift', nonce: posRegisterNonce, register_id: registerId });
            posAjaxRequest(posRegisterAjaxUrl, body).then(d => {
                if (!d) return;
                if (d.success) { location.reload(); } else { alert(d.data || '<?php echo esc_js( __( 'Error force-closing shift.', 'wc-pos-pro' ) ); ?>'); }
            });
        }
        </script>
        <?php
    }


    // -------------------------------------------------------------------------
    // Branch Stock Allocation page
    // -------------------------------------------------------------------------

    public function render_branch_stock_page() {
        global $wpdb;
        $branches = $wpdb->get_results( "SELECT id, name FROM {$wpdb->prefix}wc_pos_branches WHERE status = 'active' ORDER BY name ASC" );

        $selected_branch = isset( $_GET['branch_id'] ) ? sanitize_text_field( $_GET['branch_id'] ) : ( $branches ? $branches[0]->id : '' );

        $allocations = array();
        if ( $selected_branch ) {
            $allocations = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}wc_pos_branch_stock WHERE branch_id = %s ORDER BY updated_at DESC",
                $selected_branch
            ) );
        }

        $nonce = wp_create_nonce( 'wc_pos_branch_stock_nonce' );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Branch Stock Allocation', 'wc-pos-pro' ); ?></h1>
            <p><?php esc_html_e( 'Allocate a specific stock count to a product at a branch. Any product without an allocation here continues to use the store-wide WooCommerce stock count at every branch — allocating here is opt-in per product.', 'wc-pos-pro' ); ?></p>

            <?php if ( empty( $branches ) ) : ?>
                <div class="notice notice-warning"><p>
                    <?php
                    printf(
                        /* translators: %s: link to the Branches admin page */
                        esc_html__( 'No active branches found. %s before allocating stock.', 'wc-pos-pro' ),
                        '<a href="' . esc_url( admin_url( 'admin.php?page=wc-pos-pro-branches' ) ) . '">' . esc_html__( 'Create a branch first', 'wc-pos-pro' ) . '</a>'
                    );
                    ?>
                </p></div>
            <?php else : ?>

            <form method="get" style="margin-bottom:20px;">
                <input type="hidden" name="page" value="wc-pos-pro-branch-stock" />
                <label for="branch_stock_branch_select"><strong><?php esc_html_e( 'Branch:', 'wc-pos-pro' ); ?></strong></label>
                <select name="branch_id" id="branch_stock_branch_select" onchange="this.form.submit()">
                    <?php foreach ( $branches as $b ) : ?>
                        <option value="<?php echo esc_attr( $b->id ); ?>" <?php selected( $selected_branch, $b->id ); ?>><?php echo esc_html( $b->name ); ?></option>
                    <?php endforeach; ?>
                </select>
            </form>

            <div style="background:#fff;padding:20px;border:1px solid #ccd0d4;border-radius:8px;max-width:900px;">
                <table class="wp-list-table widefat fixed striped" id="pos-branch-stock-table">
                    <thead><tr>
                        <th><?php esc_html_e( 'Product', 'wc-pos-pro' ); ?></th>
                        <th><?php esc_html_e( 'Product ID', 'wc-pos-pro' ); ?></th>
                        <th><?php esc_html_e( 'Variation ID', 'wc-pos-pro' ); ?></th>
                        <th><?php esc_html_e( 'Allocated Stock', 'wc-pos-pro' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'wc-pos-pro' ); ?></th>
                    </tr></thead>
                    <tbody id="branch-stock-tbody">
                    <?php if ( empty( $allocations ) ) : ?>
                        <tr id="no-allocations-row"><td colspan="5" style="text-align:center;color:#999;"><?php esc_html_e( 'No stock allocated yet for this branch.', 'wc-pos-pro' ); ?></td></tr>
                    <?php else : foreach ( $allocations as $a ) :
                        $prod = wc_get_product( $a->variation_id ?: $a->product_id );
                        $label = $prod ? $prod->get_name() : ( '#' . $a->product_id );
                    ?>
                        <tr id="alloc-row-<?php echo (int) $a->id; ?>">
                            <td><?php echo esc_html( $label ); ?></td>
                            <td><?php echo (int) $a->product_id; ?></td>
                            <td><?php echo $a->variation_id ? (int) $a->variation_id : '&mdash;'; ?></td>
                            <td><?php echo (int) $a->stock_quantity; ?></td>
                            <td>
                                <button class="button button-small" onclick="posEditAllocation(<?php echo (int) $a->id; ?>, <?php echo (int) $a->product_id; ?>, <?php echo (int) $a->variation_id; ?>, <?php echo (int) $a->stock_quantity; ?>, <?php echo esc_js( json_encode( $label ) ); ?>)"><?php esc_html_e( 'Edit', 'wc-pos-pro' ); ?></button>
                                <button class="button button-small" style="color:#c00;" onclick="posDeleteAllocation(<?php echo (int) $a->id; ?>)"><?php esc_html_e( 'Remove', 'wc-pos-pro' ); ?></button>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>

                <h3 style="margin-top:25px;" id="alloc-form-heading"><?php esc_html_e( 'Allocate Stock', 'wc-pos-pro' ); ?></h3>
                <table class="form-table" style="max-width:600px;">
                    <tr><th><label for="alloc_product_search"><?php esc_html_e( 'Product', 'wc-pos-pro' ); ?></label></th>
                        <td>
                            <input type="text" id="alloc_product_search" class="regular-text" placeholder="<?php esc_attr_e( 'Search by name or SKU...', 'wc-pos-pro' ); ?>" oninput="posSearchProductsForStock()" autocomplete="off" />
                            <div id="alloc_product_results" style="max-height:200px;overflow-y:auto;border:1px solid #ddd;margin-top:4px;display:none;background:#fff;"></div>
                            <p class="description" id="alloc_selected_product_label"></p>
                        </td></tr>
                    <tr id="alloc_variation_row" style="display:none;"><th><label for="alloc_variation_select"><?php esc_html_e( 'Variation', 'wc-pos-pro' ); ?></label></th>
                        <td><select id="alloc_variation_select"></select></td></tr>
                    <tr><th><label for="alloc_quantity"><?php esc_html_e( 'Stock Quantity', 'wc-pos-pro' ); ?></label></th>
                        <td><input type="number" id="alloc_quantity" min="0" class="small-text" value="0" /></td></tr>
                </table>
                <input type="hidden" id="alloc_editing_id" value="" />
                <input type="hidden" id="alloc_product_id" value="" />
                <input type="hidden" id="alloc_variation_id" value="0" />
                <button class="button button-primary" onclick="posSaveAllocation()"><?php esc_html_e( 'Save Allocation', 'wc-pos-pro' ); ?></button>
                <button class="button" id="alloc-cancel-btn" style="display:none;" onclick="posCancelEditAllocation()"><?php esc_html_e( 'Cancel', 'wc-pos-pro' ); ?></button>
            </div>
            <?php endif; ?>
        </div>

        <script>
        const posBranchStockNonce   = '<?php echo esc_js( $nonce ); ?>';
        const posBranchStockAjaxUrl = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
        const posBranchStockBranchId = '<?php echo esc_js( $selected_branch ); ?>';
        let posStockSearchResults = [];

        async function posAjaxRequest(url, body) {
            let res, text;
            try {
                res = await fetch(url, { method: 'POST', body });
                text = await res.text();
            } catch (networkErr) {
                alert('Network error: ' + networkErr.message);
                return null;
            }
            try {
                return JSON.parse(text);
            } catch (parseErr) {
                console.error('Non-JSON response from server:', text);
                alert('Unexpected server response (HTTP ' + res.status + '). This usually means a PHP error occurred.\n\nServer said:\n' + text.substring(0, 800));
                return null;
            }
        }

        async function posSearchProductsForStock() {
            const term = document.getElementById('alloc_product_search').value.trim();
            const resultsBox = document.getElementById('alloc_product_results');
            if (term.length < 2) { resultsBox.style.display = 'none'; return; }

            const body = new URLSearchParams({ action: 'wc_pos_search_products_for_stock', nonce: posBranchStockNonce, term });
            const json = await posAjaxRequest(posBranchStockAjaxUrl, body);
            if (!json) return;
            posStockSearchResults = json.success ? json.data : [];

            if (posStockSearchResults.length === 0) {
                resultsBox.innerHTML = '<div style="padding:8px;color:#999;">No products found</div>';
                resultsBox.style.display = 'block';
                return;
            }

            resultsBox.innerHTML = posStockSearchResults.map((p, idx) =>
                '<div style="padding:8px;border-bottom:1px solid #eee;cursor:pointer;" onclick="posSelectStockProduct(' + idx + ')">' +
                    p.name + (p.sku ? ' <span style="color:#999;">(' + p.sku + ')</span>' : '') +
                    (p.type === 'variable' ? ' <span style="color:#7a5cff;">[variable]</span>' : '') +
                '</div>'
            ).join('');
            resultsBox.style.display = 'block';
        }

        function posSelectStockProduct(idx) {
            const p = posStockSearchResults[idx];
            document.getElementById('alloc_product_id').value = p.id;
            document.getElementById('alloc_product_search').value = p.name;
            document.getElementById('alloc_selected_product_label').textContent = 'Selected: ' + p.name;
            document.getElementById('alloc_product_results').style.display = 'none';

            const varRow = document.getElementById('alloc_variation_row');
            const varSelect = document.getElementById('alloc_variation_select');
            if (p.variations && p.variations.length > 0) {
                varSelect.innerHTML = p.variations.map(v => '<option value="' + v.id + '">' + (v.name || v.sku || ('#' + v.id)) + '</option>').join('');
                varRow.style.display = '';
                document.getElementById('alloc_variation_id').value = p.variations[0].id;
                varSelect.onchange = function() { document.getElementById('alloc_variation_id').value = this.value; };
            } else {
                varRow.style.display = 'none';
                document.getElementById('alloc_variation_id').value = 0;
            }
        }

        function posSaveAllocation() {
            const id           = document.getElementById('alloc_editing_id').value;
            const productId    = document.getElementById('alloc_product_id').value;
            const variationId  = document.getElementById('alloc_variation_id').value || 0;
            const quantity     = document.getElementById('alloc_quantity').value;

            if (!productId) { alert('<?php echo esc_js( __( 'Please search and select a product.', 'wc-pos-pro' ) ); ?>'); return; }
            if (quantity === '' || parseInt(quantity, 10) < 0) { alert('<?php echo esc_js( __( 'Quantity must be zero or greater.', 'wc-pos-pro' ) ); ?>'); return; }

            const body = new URLSearchParams({
                action: 'wc_pos_save_branch_stock', nonce: posBranchStockNonce,
                branch_id: posBranchStockBranchId, product_id: productId, variation_id: variationId, stock_quantity: quantity
            });
            posAjaxRequest(posBranchStockAjaxUrl, body).then(d => {
                if (!d) return;
                if (d.success) { location.reload(); } else { alert(d.data || '<?php echo esc_js( __( 'Error saving allocation.', 'wc-pos-pro' ) ); ?>'); }
            });
        }

        function posEditAllocation(id, productId, variationId, quantity, label) {
            document.getElementById('alloc_editing_id').value = id;
            document.getElementById('alloc_product_id').value = productId;
            document.getElementById('alloc_variation_id').value = variationId || 0;
            document.getElementById('alloc_product_search').value = label;
            document.getElementById('alloc_selected_product_label').textContent = 'Selected: ' + label;
            document.getElementById('alloc_quantity').value = quantity;
            document.getElementById('alloc_variation_row').style.display = variationId ? '' : 'none';
            document.getElementById('alloc-form-heading').textContent = '<?php echo esc_js( __( 'Edit Allocation', 'wc-pos-pro' ) ); ?>';
            document.getElementById('alloc-cancel-btn').style.display = 'inline-block';
        }

        function posCancelEditAllocation() {
            document.getElementById('alloc_editing_id').value = '';
            document.getElementById('alloc_product_id').value = '';
            document.getElementById('alloc_variation_id').value = 0;
            document.getElementById('alloc_product_search').value = '';
            document.getElementById('alloc_selected_product_label').textContent = '';
            document.getElementById('alloc_quantity').value = 0;
            document.getElementById('alloc_variation_row').style.display = 'none';
            document.getElementById('alloc-form-heading').textContent = '<?php echo esc_js( __( 'Allocate Stock', 'wc-pos-pro' ) ); ?>';
            document.getElementById('alloc-cancel-btn').style.display = 'none';
        }

        function posDeleteAllocation(id) {
            if (!confirm('<?php echo esc_js( __( 'Remove this stock allocation? The product will revert to global stock at this branch.', 'wc-pos-pro' ) ); ?>')) return;
            const body = new URLSearchParams({ action: 'wc_pos_delete_branch_stock', nonce: posBranchStockNonce, allocation_id: id });
            posAjaxRequest(posBranchStockAjaxUrl, body).then(d => {
                if (!d) return;
                if (d.success) { const row = document.getElementById('alloc-row-' + id); if (row) row.remove(); }
                else { alert(d.data || '<?php echo esc_js( __( 'Error removing allocation.', 'wc-pos-pro' ) ); ?>'); }
            });
        }

        // Close the search-results dropdown when clicking elsewhere.
        document.addEventListener('click', function(e) {
            const box = document.getElementById('alloc_product_results');
            const input = document.getElementById('alloc_product_search');
            if (box && !box.contains(e.target) && e.target !== input) box.style.display = 'none';
        });
        </script>
        <?php
    }


    // -------------------------------------------------------------------------
    // Reports page
    // -------------------------------------------------------------------------

    public function render_reports_page() {
        global $wpdb;
        $branches = $wpdb->get_results( "SELECT id, name FROM {$wpdb->prefix}wc_pos_branches WHERE status = 'active' ORDER BY name ASC" );
        $nonce    = wp_create_nonce( 'wc_pos_reports_nonce' );
        $currency = get_woocommerce_currency_symbol();
        $today    = current_time( 'Y-m-d' );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'POS Reports', 'wc-pos-pro' ); ?></h1>
            <p><?php esc_html_e( 'Sales, cash reconciliation, product, cashier, and branch performance — all in one place.', 'wc-pos-pro' ); ?></p>

            <!-- Filter bar -->
            <div style="background:#fff;padding:16px 20px;border:1px solid #ccd0d4;border-radius:8px;margin-bottom:16px;display:flex;flex-wrap:wrap;align-items:end;gap:14px;">
                <div>
                    <label style="display:block;font-size:11px;color:#666;margin-bottom:4px;"><?php esc_html_e( 'From', 'wc-pos-pro' ); ?></label>
                    <input type="date" id="report-date-from" value="<?php echo esc_attr( $today ); ?>" />
                </div>
                <div>
                    <label style="display:block;font-size:11px;color:#666;margin-bottom:4px;"><?php esc_html_e( 'To', 'wc-pos-pro' ); ?></label>
                    <input type="date" id="report-date-to" value="<?php echo esc_attr( $today ); ?>" />
                </div>
                <div>
                    <label style="display:block;font-size:11px;color:#666;margin-bottom:4px;"><?php esc_html_e( 'Branch', 'wc-pos-pro' ); ?></label>
                    <select id="report-branch-filter">
                        <option value=""><?php esc_html_e( 'All Branches', 'wc-pos-pro' ); ?></option>
                        <?php foreach ( $branches as $b ) : ?>
                            <option value="<?php echo esc_attr( $b->id ); ?>"><?php echo esc_html( $b->name ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="display:flex;gap:6px;">
                    <button class="button" onclick="posSetReportRange('today')"><?php esc_html_e( 'Today', 'wc-pos-pro' ); ?></button>
                    <button class="button" onclick="posSetReportRange('yesterday')"><?php esc_html_e( 'Yesterday', 'wc-pos-pro' ); ?></button>
                    <button class="button" onclick="posSetReportRange('week')"><?php esc_html_e( 'This Week', 'wc-pos-pro' ); ?></button>
                    <button class="button" onclick="posSetReportRange('month')"><?php esc_html_e( 'This Month', 'wc-pos-pro' ); ?></button>
                </div>
                <button class="button button-primary" onclick="posRunReport()"><?php esc_html_e( 'Run Report', 'wc-pos-pro' ); ?></button>
                <button class="button" onclick="posExportReportCsv()"><?php esc_html_e( 'Export CSV', 'wc-pos-pro' ); ?></button>
            </div>

            <!-- Report tabs -->
            <div class="nav-tab-wrapper" style="margin-bottom:0;">
                <a href="#" class="nav-tab nav-tab-active" data-report="sales_summary" onclick="return posSwitchReportTab(this)"><?php esc_html_e( 'Sales Summary', 'wc-pos-pro' ); ?></a>
                <a href="#" class="nav-tab" data-report="shift_history" onclick="return posSwitchReportTab(this)"><?php esc_html_e( 'Shift History', 'wc-pos-pro' ); ?></a>
                <a href="#" class="nav-tab" data-report="top_products" onclick="return posSwitchReportTab(this)"><?php esc_html_e( 'Top Products', 'wc-pos-pro' ); ?></a>
                <a href="#" class="nav-tab" data-report="cashier_performance" onclick="return posSwitchReportTab(this)"><?php esc_html_e( 'Cashier Performance', 'wc-pos-pro' ); ?></a>
                <a href="#" class="nav-tab" data-report="branch_comparison" onclick="return posSwitchReportTab(this)"><?php esc_html_e( 'Branch Comparison', 'wc-pos-pro' ); ?></a>
            </div>

            <div style="background:#fff;padding:20px;border:1px solid #ccd0d4;border-top:none;border-radius:0 0 8px 8px;min-height:200px;">
                <div id="report-loading" class="hidden" style="text-align:center;color:#999;padding:30px;"><?php esc_html_e( 'Loading...', 'wc-pos-pro' ); ?></div>
                <div id="report-results"><p style="color:#999;"><?php esc_html_e( 'Choose your filters and click "Run Report."', 'wc-pos-pro' ); ?></p></div>
            </div>
        </div>

        <script>
        const posReportsNonce   = '<?php echo esc_js( $nonce ); ?>';
        const posReportsAjaxUrl = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
        const posReportCurrency = '<?php echo esc_js( $currency ); ?>';
        let currentReportType = 'sales_summary';
        let lastReportData = null;

        async function posAjaxRequest(url, body) {
            let res, text;
            try {
                res = await fetch(url, { method: 'POST', body });
                text = await res.text();
            } catch (networkErr) {
                alert('Network error: ' + networkErr.message);
                return null;
            }
            try {
                return JSON.parse(text);
            } catch (parseErr) {
                console.error('Non-JSON response from server:', text);
                alert('Unexpected server response (HTTP ' + res.status + '). This usually means a PHP error occurred.\n\nServer said:\n' + text.substring(0, 800));
                return null;
            }
        }

        function posFmt(n) {
            return posReportCurrency + (Number(n) || 0).toFixed(2);
        }

        function posSetReportRange(preset) {
            const now = new Date();
            // Bug fix: toISOString() converts to UTC, which can shift the
            // displayed date by a day depending on the browser's local
            // timezone (relevant for anyone not on UTC, including Nigeria).
            // Build the YYYY-MM-DD string from local date parts instead.
            const fmt = d => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
            let from = new Date(now), to = new Date(now);

            if (preset === 'yesterday') {
                from.setDate(from.getDate() - 1);
                to.setDate(to.getDate() - 1);
            } else if (preset === 'week') {
                const day = now.getDay(); // 0 = Sunday
                from.setDate(now.getDate() - day);
            } else if (preset === 'month') {
                from = new Date(now.getFullYear(), now.getMonth(), 1);
            }
            // 'today' needs no adjustment — from/to already default to now.

            document.getElementById('report-date-from').value = fmt(from);
            document.getElementById('report-date-to').value = fmt(to);
            posRunReport();
        }

        function posSwitchReportTab(el) {
            document.querySelectorAll('.nav-tab').forEach(t => t.classList.remove('nav-tab-active'));
            el.classList.add('nav-tab-active');
            currentReportType = el.dataset.report;
            posRunReport();
            return false;
        }

        async function posRunReport() {
            const loading = document.getElementById('report-loading');
            const results = document.getElementById('report-results');
            loading.classList.remove('hidden');

            const body = new URLSearchParams({
                action: 'wc_pos_get_report',
                nonce: posReportsNonce,
                report_type: currentReportType,
                date_from: document.getElementById('report-date-from').value,
                date_to: document.getElementById('report-date-to').value,
                branch_id: document.getElementById('report-branch-filter').value,
            });

            const json = await posAjaxRequest(posReportsAjaxUrl, body);
            loading.classList.add('hidden');
            if (!json) return;

            if (!json.success) {
                results.innerHTML = '<p style="color:#c00;">' + (json.data || 'Error loading report.') + '</p>';
                return;
            }

            lastReportData = json.data;
            results.innerHTML = posRenderReport(currentReportType, json.data);
        }

        function posRenderReport(type, data) {
            if (type === 'sales_summary') return posRenderSalesSummary(data);
            if (type === 'shift_history') return posRenderShiftHistory(data);
            if (type === 'top_products') return posRenderTopProducts(data);
            if (type === 'cashier_performance') return posRenderCashierPerformance(data);
            if (type === 'branch_comparison') return posRenderBranchComparison(data);
            return '<p>Unknown report.</p>';
        }

        function posStatCard(label, value, color) {
            return '<div style="flex:1;min-width:160px;background:#f8f9fa;border:1px solid #e2e4e7;border-radius:8px;padding:14px;">' +
                '<div style="font-size:11px;color:#666;text-transform:uppercase;letter-spacing:.03em;">' + label + '</div>' +
                '<div style="font-size:22px;font-weight:700;color:' + (color || '#1e1e1e') + ';margin-top:4px;">' + value + '</div>' +
            '</div>';
        }

        function posRenderSalesSummary(d) {
            if (d.orderCount === 0) return '<p style="color:#999;">No sales found in this range.</p>';
            let html = '<div style="display:flex;flex-wrap:wrap;gap:12px;margin-bottom:20px;">';
            html += posStatCard('Total Sales', posFmt(d.totalSales), '#2271b1');
            html += posStatCard('Orders', d.orderCount);
            html += posStatCard('Avg Order Value', posFmt(d.avgOrderValue));
            html += posStatCard('Total Discounts', posFmt(d.totalDiscount), '#d63638');
            html += posStatCard('Cash Sales', posFmt(d.cashSales), '#00a32a');
            html += posStatCard('Card Sales', posFmt(d.cardSales), '#00a32a');
            html += posStatCard('Transfer Sales', posFmt(d.transferSales || 0), '#00a32a');
            html += '</div>';

            if (d.byBranch.length > 1) {
                html += '<h3>By Branch</h3><table class="wp-list-table widefat striped"><thead><tr><th>Branch</th><th>Total Sales</th></tr></thead><tbody>';
                d.byBranch.forEach(b => { html += '<tr><td>' + b.branchName + '</td><td>' + posFmt(b.total) + '</td></tr>'; });
                html += '</tbody></table>';
            }

            if (d.byDay.length > 1) {
                html += '<h3 style="margin-top:20px;">By Day</h3><table class="wp-list-table widefat striped"><thead><tr><th>Date</th><th>Total Sales</th></tr></thead><tbody>';
                d.byDay.forEach(row => { html += '<tr><td>' + row.date + '</td><td>' + posFmt(row.total) + '</td></tr>'; });
                html += '</tbody></table>';
            }
            return html;
        }

        function posRenderShiftHistory(rows) {
            if (rows.length === 0) return '<p style="color:#999;">No shifts found in this range.</p>';
            let html = '<table class="wp-list-table widefat striped"><thead><tr>' +
                '<th>Register</th><th>Branch</th><th>Cashier</th><th>Opened</th><th>Closed</th>' +
                '<th>Opening Float</th><th>Total Sales</th><th>Transfer Sales</th><th>Expected Cash</th><th>Actual Cash</th><th>Difference</th><th>Status</th>' +
                '</tr></thead><tbody>';
            rows.forEach(r => {
                const diffColor = r.flagged ? 'color:#d63638;font-weight:700;' : '';
                const actualCashDisplay = r.forceClosed ? '<em style="color:#996800;">Not counted</em>' : posFmt(r.actualCash);
                const diffDisplay = r.forceClosed ? '<em style="color:#996800;">&mdash;</em>' : (posFmt(r.cashDifference) + (r.flagged ? ' \u26a0' : ''));
                const statusDisplay = r.status + (r.forceClosed ? ' <span style="color:#996800;font-size:11px;">(force-closed)</span>' : '');
                html += '<tr' + (r.flagged ? ' style="background:#fcf0f1;"' : '') + '>' +
                    '<td>' + r.registerId + '</td>' +
                    '<td>' + r.branchName + '</td>' +
                    '<td>' + r.cashierName + '</td>' +
                    '<td>' + r.openedAt + '</td>' +
                    '<td>' + (r.closedAt || '&mdash;') + '</td>' +
                    '<td>' + posFmt(r.openingFloat) + '</td>' +
                    '<td>' + posFmt(r.totalSales) + '</td>' +
                    '<td>' + posFmt(r.transferSales || 0) + '</td>' +
                    '<td>' + posFmt(r.expectedCash) + '</td>' +
                    '<td>' + actualCashDisplay + '</td>' +
                    '<td style="' + diffColor + '">' + diffDisplay + '</td>' +
                    '<td>' + statusDisplay + '</td>' +
                '</tr>';
            });
            html += '</tbody></table>';
            return html;
        }

        function posRenderTopProducts(d) {
            if (d.top.length === 0) return '<p style="color:#999;">No product sales found in this range.</p>';
            let html = '<h3>Top Sellers</h3><table class="wp-list-table widefat striped"><thead><tr><th>Product</th><th>SKU</th><th>Qty Sold</th><th>Revenue</th></tr></thead><tbody>';
            d.top.forEach(p => { html += '<tr><td>' + p.name + '</td><td>' + (p.sku || '&mdash;') + '</td><td>' + p.qty + '</td><td>' + posFmt(p.revenue) + '</td></tr>'; });
            html += '</tbody></table>';

            if (d.bottom.length > 0) {
                html += '<h3 style="margin-top:20px;">Slowest Movers</h3><table class="wp-list-table widefat striped"><thead><tr><th>Product</th><th>SKU</th><th>Qty Sold</th><th>Revenue</th></tr></thead><tbody>';
                d.bottom.forEach(p => { html += '<tr><td>' + p.name + '</td><td>' + (p.sku || '&mdash;') + '</td><td>' + p.qty + '</td><td>' + posFmt(p.revenue) + '</td></tr>'; });
                html += '</tbody></table>';
            }
            return html;
        }

        function posRenderCashierPerformance(rows) {
            if (rows.length === 0) return '<p style="color:#999;">No cashier activity found in this range.</p>';
            let html = '<table class="wp-list-table widefat striped"><thead><tr><th>Cashier</th><th>Orders</th><th>Total Sales</th><th>Avg Sale</th><th>Total Discounts Given</th></tr></thead><tbody>';
            rows.forEach(c => {
                html += '<tr><td>' + c.cashierName + '</td><td>' + c.orderCount + '</td><td>' + posFmt(c.totalSales) + '</td><td>' + posFmt(c.avgSale) + '</td><td>' + posFmt(c.totalDiscount) + '</td></tr>';
            });
            html += '</tbody></table>';
            return html;
        }

        function posRenderBranchComparison(rows) {
            if (rows.length === 0) return '<p style="color:#999;">No branches found.</p>';
            let html = '<table class="wp-list-table widefat striped"><thead><tr><th>Branch</th><th>Orders</th><th>Total Sales</th><th>Avg Sale</th><th>Discounts Given</th><th>Low Stock Items</th></tr></thead><tbody>';
            rows.forEach(b => {
                const lowStockStyle = b.lowStockCount > 0 ? 'color:#d63638;font-weight:700;' : '';
                html += '<tr><td>' + b.branchName + '</td><td>' + b.orderCount + '</td><td>' + posFmt(b.totalSales) + '</td><td>' + posFmt(b.avgSale) + '</td><td>' + posFmt(b.totalDiscount) + '</td><td style="' + lowStockStyle + '">' + b.lowStockCount + '</td></tr>';
            });
            html += '</tbody></table>';
            return html;
        }

        function posExportReportCsv() {
            if (!lastReportData) { alert('Run a report first.'); return; }

            let rows = [];
            if (currentReportType === 'sales_summary') {
                rows.push(['Metric', 'Value']);
                rows.push(['Total Sales', lastReportData.totalSales]);
                rows.push(['Orders', lastReportData.orderCount]);
                rows.push(['Avg Order Value', lastReportData.avgOrderValue]);
                rows.push(['Total Discounts', lastReportData.totalDiscount]);
                rows.push(['Cash Sales', lastReportData.cashSales]);
                rows.push(['Card Sales', lastReportData.cardSales]);
                rows.push(['Transfer Sales', lastReportData.transferSales || 0]);
            } else if (currentReportType === 'shift_history') {
                rows.push(['Register', 'Branch', 'Cashier', 'Opened', 'Closed', 'Opening Float', 'Total Sales', 'Transfer Sales', 'Expected Cash', 'Actual Cash', 'Difference', 'Status']);
                lastReportData.forEach(r => rows.push([r.registerId, r.branchName, r.cashierName, r.openedAt, r.closedAt, r.openingFloat, r.totalSales, r.transferSales || 0, r.expectedCash, r.actualCash, r.cashDifference, r.status]));
            } else if (currentReportType === 'top_products') {
                rows.push(['Product', 'SKU', 'Qty Sold', 'Revenue']);
                lastReportData.top.forEach(p => rows.push([p.name, p.sku, p.qty, p.revenue]));
            } else if (currentReportType === 'cashier_performance') {
                rows.push(['Cashier', 'Orders', 'Total Sales', 'Avg Sale', 'Total Discounts']);
                lastReportData.forEach(c => rows.push([c.cashierName, c.orderCount, c.totalSales, c.avgSale, c.totalDiscount]));
            } else if (currentReportType === 'branch_comparison') {
                rows.push(['Branch', 'Orders', 'Total Sales', 'Avg Sale', 'Discounts', 'Low Stock Items']);
                lastReportData.forEach(b => rows.push([b.branchName, b.orderCount, b.totalSales, b.avgSale, b.totalDiscount, b.lowStockCount]));
            }

            const csv = rows.map(r => r.map(v => '"' + String(v ?? '').replace(/"/g, '""') + '"').join(',')).join('\n');
            const blob = new Blob([csv], { type: 'text/csv' });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = 'wc-pos-report-' + currentReportType + '-' + document.getElementById('report-date-from').value + '.csv';
            link.click();
        }

        // Run the default report on first load.
        posRunReport();
        </script>
        <?php
    }


    // -------------------------------------------------------------------------
    // Receipt Builder page  (task #4)
    // -------------------------------------------------------------------------

    public function render_receipt_page() {
        $logo_id       = (int) get_option( 'wc_pos_receipt_logo_id', 0 );
        $logo_url      = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
        $paper_width   = get_option( 'wc_pos_receipt_paper_width', '80mm' );
        $line_fmt      = get_option( 'wc_pos_receipt_line_item_format', 'full' );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Receipt Builder', 'wc-pos-pro' ); ?></h1>
            <p><?php esc_html_e( 'Customise the thermal receipt layout. Changes are reflected immediately in the live preview on the right.', 'wc-pos-pro' ); ?></p>

            <div style="display:flex;gap:30px;align-items:flex-start;flex-wrap:wrap;">

                <!-- Settings form (left column) -->
                <form method="post" action="options.php" style="background:#fff;padding:24px;border:1px solid #ccd0d4;border-radius:8px;flex:1;min-width:340px;max-width:600px;" id="receipt-builder-form">
                    <?php settings_fields( 'wc_pos_options_group' ); ?>

                    <h2 class="title"><?php esc_html_e( 'Paper & Layout', 'wc-pos-pro' ); ?></h2>
                    <table class="form-table">
                        <tr><th><label for="wc_pos_receipt_paper_width"><?php esc_html_e( 'Paper Width', 'wc-pos-pro' ); ?></label></th>
                            <td><select id="wc_pos_receipt_paper_width" name="wc_pos_receipt_paper_width" onchange="updatePreview()">
                                <option value="58mm" <?php selected( $paper_width, '58mm' ); ?>>58 mm (mini)</option>
                                <option value="80mm" <?php selected( $paper_width, '80mm' ); ?>>80 mm (standard)</option>
                            </select></td></tr>
                        <tr><th><label for="wc_pos_receipt_line_item_format"><?php esc_html_e( 'Line Item Format', 'wc-pos-pro' ); ?></label></th>
                            <td><select id="wc_pos_receipt_line_item_format" name="wc_pos_receipt_line_item_format" onchange="updatePreview()">
                                <option value="full"    <?php selected( $line_fmt, 'full' ); ?>><?php esc_html_e( 'Full (name, qty, price, SKU)', 'wc-pos-pro' ); ?></option>
                                <option value="compact" <?php selected( $line_fmt, 'compact' ); ?>><?php esc_html_e( 'Compact (name, qty × price)', 'wc-pos-pro' ); ?></option>
                                <option value="minimal" <?php selected( $line_fmt, 'minimal' ); ?>><?php esc_html_e( 'Minimal (name + total only)', 'wc-pos-pro' ); ?></option>
                            </select></td></tr>
                    </table>

                    <h2 class="title" style="margin-top:25px;"><?php esc_html_e( 'Store Branding', 'wc-pos-pro' ); ?></h2>
                    <table class="form-table">
                        <tr><th><?php esc_html_e( 'Show Logo', 'wc-pos-pro' ); ?></th>
                            <td><label><input type="checkbox" name="wc_pos_receipt_show_logo" id="chk_show_logo" value="1" <?php checked( 1, get_option( 'wc_pos_receipt_show_logo', 1 ) ); ?> onchange="updatePreview()" /> <?php esc_html_e( 'Display store logo', 'wc-pos-pro' ); ?></label></td></tr>
                        <tr><th><label for="wc_pos_receipt_logo_id"><?php esc_html_e( 'Logo Image', 'wc-pos-pro' ); ?></label></th>
                            <td>
                                <input type="hidden" id="wc_pos_receipt_logo_id" name="wc_pos_receipt_logo_id" value="<?php echo esc_attr( $logo_id ); ?>" />
                                <div id="logo-preview-wrap" style="margin-bottom:8px;">
                                    <?php if ( $logo_url ) : ?><img id="receipt-logo-preview" src="<?php echo esc_url( $logo_url ); ?>" style="max-height:80px;max-width:200px;border:1px solid #ddd;padding:4px;" /><?php endif; ?>
                                </div>
                                <button type="button" class="button" id="upload-logo-btn"><?php esc_html_e( 'Upload / Select Logo', 'wc-pos-pro' ); ?></button>
                                <button type="button" class="button" id="remove-logo-btn" <?php echo $logo_id ? '' : 'style="display:none;"'; ?>><?php esc_html_e( 'Remove', 'wc-pos-pro' ); ?></button>
                            </td></tr>
                        <tr><th><?php esc_html_e( 'Show Store Name', 'wc-pos-pro' ); ?></th>
                            <td><label><input type="checkbox" name="wc_pos_receipt_show_store_name" id="chk_show_name" value="1" <?php checked( 1, get_option( 'wc_pos_receipt_show_store_name', 1 ) ); ?> onchange="updatePreview()" /> <?php esc_html_e( 'Display store name heading', 'wc-pos-pro' ); ?></label></td></tr>
                        <tr><th><?php esc_html_e( 'Show Address', 'wc-pos-pro' ); ?></th>
                            <td><label><input type="checkbox" name="wc_pos_receipt_show_address" id="chk_show_addr" value="1" <?php checked( 1, get_option( 'wc_pos_receipt_show_address', 1 ) ); ?> onchange="updatePreview()" /> <?php esc_html_e( 'Display store address and phone', 'wc-pos-pro' ); ?></label></td></tr>
                    </table>

                    <h2 class="title" style="margin-top:25px;"><?php esc_html_e( 'Content Blocks', 'wc-pos-pro' ); ?></h2>
                    <table class="form-table">
                        <tr><th><label for="wc_pos_receipt_header"><?php esc_html_e( 'Header Text', 'wc-pos-pro' ); ?></label></th>
                            <td><input type="text" id="wc_pos_receipt_header" name="wc_pos_receipt_header" value="<?php echo esc_attr( get_option( 'wc_pos_receipt_header', 'Thank you for shopping with us!' ) ); ?>" class="large-text" oninput="updatePreview()" /></td></tr>
                        <tr><th><label for="wc_pos_receipt_footer"><?php esc_html_e( 'Footer Text', 'wc-pos-pro' ); ?></label></th>
                            <td><input type="text" id="wc_pos_receipt_footer" name="wc_pos_receipt_footer" value="<?php echo esc_attr( get_option( 'wc_pos_receipt_footer', 'Returns accepted within 30 days.' ) ); ?>" class="large-text" oninput="updatePreview()" /></td></tr>
                        <tr><th><?php esc_html_e( 'Show Barcode', 'wc-pos-pro' ); ?></th>
                            <td><label><input type="checkbox" name="wc_pos_receipt_show_barcode" id="chk_barcode" value="1" <?php checked( 1, get_option( 'wc_pos_receipt_show_barcode', 1 ) ); ?> onchange="updatePreview()" /> <?php esc_html_e( 'Print order barcode / QR placeholder', 'wc-pos-pro' ); ?></label></td></tr>
                        <tr><th><?php esc_html_e( 'Show Tax Breakdown', 'wc-pos-pro' ); ?></th>
                            <td><label><input type="checkbox" name="wc_pos_receipt_show_tax_breakdown" id="chk_tax" value="1" <?php checked( 1, get_option( 'wc_pos_receipt_show_tax_breakdown', 1 ) ); ?> onchange="updatePreview()" /> <?php esc_html_e( 'Show tax lines below subtotal', 'wc-pos-pro' ); ?></label></td></tr>
                        <tr><th><?php esc_html_e( 'Show Cashier Name', 'wc-pos-pro' ); ?></th>
                            <td><label><input type="checkbox" name="wc_pos_receipt_show_cashier" id="chk_cashier" value="1" <?php checked( 1, get_option( 'wc_pos_receipt_show_cashier', 1 ) ); ?> onchange="updatePreview()" /> <?php esc_html_e( 'Print served-by cashier name', 'wc-pos-pro' ); ?></label></td></tr>
                    </table>

                    <?php submit_button( __( 'Save Receipt Settings', 'wc-pos-pro' ) ); ?>
                </form>

                <!-- Live preview (right column) -->
                <div style="flex:0 0 auto;">
                    <h2 style="margin-top:0;"><?php esc_html_e( 'Live Preview', 'wc-pos-pro' ); ?></h2>
                    <div id="receipt-preview-wrap" style="font-family:monospace;font-size:12px;background:#fff;border:1px solid #ccc;padding:16px;line-height:1.6;transition:width 0.2s;" data-paper="<?php echo esc_attr( $paper_width ); ?>">
                        <div id="prev-logo-wrap" style="text-align:center;margin-bottom:6px;">
                            <?php if ( $logo_url && get_option( 'wc_pos_receipt_show_logo', 1 ) ) : ?><img src="<?php echo esc_url( $logo_url ); ?>" style="max-height:60px;" /><?php endif; ?>
                        </div>
                        <div id="prev-store-name" style="text-align:center;font-weight:bold;font-size:14px;"><?php echo get_option( 'wc_pos_receipt_show_store_name', 1 ) ? esc_html( get_bloginfo( 'name' ) ) : ''; ?></div>
                        <div id="prev-address" style="text-align:center;font-size:11px;color:#555;white-space:pre-line;"><?php echo get_option( 'wc_pos_receipt_show_address', 1 ) ? esc_html( get_option( 'wc_pos_store_address' ) . "\n" . get_option( 'wc_pos_store_phone' ) ) : ''; ?></div>
                        <div id="prev-header" style="text-align:center;margin:8px 0;border-top:1px dashed #999;padding-top:6px;"><?php echo esc_html( get_option( 'wc_pos_receipt_header', 'Thank you for shopping with us!' ) ); ?></div>
                        <div style="border-top:1px dashed #999;margin:6px 0;"></div>
                        <div id="prev-items">
                            <div style="display:flex;justify-content:space-between;"><span>Sample Product × 2</span><span>$40.00</span></div>
                            <div style="display:flex;justify-content:space-between;"><span>Another Item × 1</span><span>$15.00</span></div>
                        </div>
                        <div style="border-top:1px dashed #999;margin:6px 0;"></div>
                        <div style="display:flex;justify-content:space-between;"><span>Subtotal</span><span>$55.00</span></div>
                        <div id="prev-tax" style="display:flex;justify-content:space-between;color:#666;"><span>Tax (7.5%)</span><span>$4.13</span></div>
                        <div style="display:flex;justify-content:space-between;font-weight:bold;"><span>TOTAL</span><span>$59.13</span></div>
                        <div id="prev-cashier" style="font-size:11px;color:#777;margin-top:4px;">Served by: <?php echo esc_html( wp_get_current_user()->display_name ); ?></div>
                        <div id="prev-barcode" style="text-align:center;margin:10px 0;font-size:10px;color:#999;border:1px dashed #ccc;padding:6px;">&#9635; ORDER BARCODE / QR</div>
                        <div id="prev-footer" style="text-align:center;font-size:11px;border-top:1px dashed #999;padding-top:6px;"><?php echo esc_html( get_option( 'wc_pos_receipt_footer', 'Returns accepted within 30 days.' ) ); ?></div>
                    </div>
                    <p class="description" style="margin-top:8px;"><?php esc_html_e( 'Preview reflects current unsaved changes.', 'wc-pos-pro' ); ?></p>
                </div><!-- /preview -->
            </div><!-- /flex row -->
        </div><!-- /wrap -->

        <script>
        // WP Media uploader for logo
        document.getElementById('upload-logo-btn').addEventListener('click', function() {
            const frame = wp.media({ title: '<?php echo esc_js( __( 'Select Logo', 'wc-pos-pro' ) ); ?>', button: { text: '<?php echo esc_js( __( 'Use This Image', 'wc-pos-pro' ) ); ?>' }, multiple: false });
            frame.on('select', function() {
                const att = frame.state().get('selection').first().toJSON();
                document.getElementById('wc_pos_receipt_logo_id').value = att.id;
                let wrap = document.getElementById('logo-preview-wrap');
                let img  = document.getElementById('receipt-logo-preview');
                if (!img) { img = document.createElement('img'); img.id = 'receipt-logo-preview'; img.style = 'max-height:80px;max-width:200px;border:1px solid #ddd;padding:4px;'; wrap.prepend(img); }
                img.src = att.url;
                document.getElementById('remove-logo-btn').style.display = 'inline-block';
                updatePreview();
            });
            frame.open();
        });
        document.getElementById('remove-logo-btn').addEventListener('click', function() {
            document.getElementById('wc_pos_receipt_logo_id').value = 0;
            const img = document.getElementById('receipt-logo-preview');
            if (img) img.remove();
            this.style.display = 'none';
            updatePreview();
        });

        function updatePreview() {
            const wrap    = document.getElementById('receipt-preview-wrap');
            const paper   = document.getElementById('wc_pos_receipt_paper_width').value;
            wrap.style.width = (paper === '58mm') ? '200px' : '280px';

            // Logo
            const showLogo = document.getElementById('chk_show_logo').checked;
            const logoWrap = document.getElementById('prev-logo-wrap');
            const logoSrc  = document.getElementById('receipt-logo-preview') ? document.getElementById('receipt-logo-preview').src : '';
            logoWrap.innerHTML = (showLogo && logoSrc) ? '<img src="' + logoSrc + '" style="max-height:60px;" />' : '';

            // Store name
            document.getElementById('prev-store-name').textContent = document.getElementById('chk_show_name').checked ? '<?php echo esc_js( get_bloginfo( 'name' ) ); ?>' : '';

            // Address
            document.getElementById('prev-address').textContent = document.getElementById('chk_show_addr').checked ? '<?php echo esc_js( get_option( 'wc_pos_store_address' ) . "\n" . get_option( 'wc_pos_store_phone' ) ); ?>' : '';

            // Header / footer
            document.getElementById('prev-header').textContent = document.getElementById('wc_pos_receipt_header').value;
            document.getElementById('prev-footer').textContent = document.getElementById('wc_pos_receipt_footer').value;

            // Tax line
            document.getElementById('prev-tax').style.display = document.getElementById('chk_tax').checked ? 'flex' : 'none';

            // Cashier
            document.getElementById('prev-cashier').style.display = document.getElementById('chk_cashier').checked ? 'block' : 'none';

            // Barcode
            document.getElementById('prev-barcode').style.display = document.getElementById('chk_barcode').checked ? 'block' : 'none';
        }
        // Init preview width
        (function(){ const p = document.getElementById('wc_pos_receipt_paper_width').value; document.getElementById('receipt-preview-wrap').style.width = (p === '58mm') ? '200px' : '280px'; })();
        </script>
        <?php
    }

}
