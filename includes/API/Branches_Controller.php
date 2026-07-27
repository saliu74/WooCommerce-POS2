<?php
namespace WCPOS\API;

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

class Branches_Controller extends WP_REST_Controller {

    protected $namespace = 'wc-pos/v1';
    protected $rest_base = 'branches';

    /**
     * Bug fix: previously called register_routes() immediately, rather than
     * hooking into 'rest_api_init' the way REST_Server.php correctly does.
     * That happened to work by accident of plugins_loaded firing before
     * rest_api_init on every request, but it's not the documented/safe
     * pattern — register_rest_route() is only guaranteed to behave correctly
     * when called from a rest_api_init callback.
     */
    public static function init() {
        add_action( 'rest_api_init', function () {
            $controller = new self();
            $controller->register_routes();
        } );
    }

    public function register_routes() {
        // GET /wp-json/wc-pos/v1/branches - List all branches
        // POST /wp-json/wc-pos/v1/branches - Create a new branch
        register_rest_route( $this->namespace, '/' . $this->rest_base, array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_items' ),
                'permission_callback' => array( $this, 'get_items_permissions_check' ),
            ),
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'create_item' ),
                'permission_callback' => array( $this, 'create_item_permissions_check' ),
            ),
        ) );

        // GET /wp-json/wc-pos/v1/branches/{id} - Get single branch details
        // PUT /wp-json/wc-pos/v1/branches/{id} - Update branch
        // DELETE /wp-json/wc-pos/v1/branches/{id} - Delete branch
        register_rest_route( $this->namespace, '/' . $this->rest_base . '/(?P<id>[a-zA-Z0-9_-]+)', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_item' ),
                'permission_callback' => array( $this, 'get_items_permissions_check' ),
            ),
            array(
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => array( $this, 'update_item' ),
                'permission_callback' => array( $this, 'create_item_permissions_check' ),
            ),
            array(
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => array( $this, 'delete_item' ),
                'permission_callback' => array( $this, 'create_item_permissions_check' ),
            ),
        ) );

        // GET /wp-json/wc-pos/v1/branches/{id}/stock - Get inventory levels for a specific branch
        // POST /wp-json/wc-pos/v1/branches/{id}/stock - Update stock level for a product in a branch
        register_rest_route( $this->namespace, '/' . $this->rest_base . '/(?P<id>[a-zA-Z0-9_-]+)/stock', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_branch_stock' ),
                'permission_callback' => array( $this, 'get_items_permissions_check' ),
            ),
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'update_branch_stock' ),
                'permission_callback' => array( $this, 'create_item_permissions_check' ),
            ),
        ) );
    }

    public function get_items_permissions_check( $request ) {
        return current_user_can( 'process_wc_pos_sales' )
            || current_user_can( 'read_private_shop_orders' )
            || current_user_can( 'manage_woocommerce' );
    }

    public function create_item_permissions_check( $request ) {
        // Wire up the previously-unused 'manage_wc_pos_branches' capability
        // as an alternative to full 'manage_woocommerce'.
        return current_user_can( 'manage_wc_pos_branches' ) || current_user_can( 'manage_woocommerce' );
    }

    /**
     * Retrieve all branches with associated register counts.
     */
    public function get_items( $request ) {
        global $wpdb;
        $table_branches  = $wpdb->prefix . 'wc_pos_branches';
        $table_registers = $wpdb->prefix . 'wc_pos_registers';

        $query = "
            SELECT b.*, COUNT(r.id) AS total_registers
            FROM {$table_branches} b
            LEFT JOIN {$table_registers} r ON b.id = r.branch_id
            GROUP BY b.id
            ORDER BY b.created_at DESC
        ";

        $branches = $wpdb->get_results( $query, ARRAY_A );

        return new WP_REST_Response( array(
            'success' => true,
            'data'    => $branches ?: array(),
        ), 200 );
    }

    /**
     * Get a single branch.
     */
    public function get_item( $request ) {
        global $wpdb;
        $id = sanitize_text_field( $request['id'] );
        $table = $wpdb->prefix . 'wc_pos_branches';

        $branch = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %s", $id ), ARRAY_A );

        if ( ! $branch ) {
            return new WP_Error( 'wc_pos_branch_not_found', __( 'Branch not found.', 'wc-pos-pro' ), array( 'status' => 404 ) );
        }

        return new WP_REST_Response( array(
            'success' => true,
            'data'    => $branch,
        ), 200 );
    }

    /**
     * Create a new branch.
     */
    public function create_item( $request ) {
        global $wpdb;

        $name = sanitize_text_field( $request->get_param( 'name' ) );
        if ( empty( $name ) ) {
            return new WP_Error( 'wc_pos_invalid_name', __( 'Branch name is required.', 'wc-pos-pro' ), array( 'status' => 400 ) );
        }

        $id = 'br_' . wp_generate_password( 12, false );
        $table = $wpdb->prefix . 'wc_pos_branches';

        // Bug fix: restrict status to known values rather than accepting any
        // arbitrary string, which would silently break admin UI status
        // filters/badges built against a fixed set of statuses.
        $status = sanitize_text_field( $request->get_param( 'status' ) ?? 'active' );
        if ( ! in_array( $status, array( 'active', 'inactive' ), true ) ) {
            $status = 'active';
        }

        $data = array(
            'id'             => $id,
            'name'           => $name,
            'code'           => sanitize_text_field( $request->get_param( 'code' ) ?? '' ),
            'address'        => sanitize_textarea_field( $request->get_param( 'address' ) ?? '' ),
            'phone'          => sanitize_text_field( $request->get_param( 'phone' ) ?? '' ),
            'email'          => sanitize_email( $request->get_param( 'email' ) ?? '' ),
            'receipt_header' => sanitize_textarea_field( $request->get_param( 'receipt_header' ) ?? '' ),
            'receipt_footer' => sanitize_textarea_field( $request->get_param( 'receipt_footer' ) ?? '' ),
            'status'         => $status,
        );

        $inserted = $wpdb->insert( $table, $data );

        if ( ! $inserted ) {
            return new WP_Error( 'wc_pos_db_error', __( 'Failed to create branch.', 'wc-pos-pro' ), array( 'status' => 500 ) );
        }

        return new WP_REST_Response( array(
            'success' => true,
            'data'    => $data,
        ), 201 );
    }

    /**
     * Update an existing branch.
     */
    public function update_item( $request ) {
        global $wpdb;
        $id = sanitize_text_field( $request['id'] );
        $table = $wpdb->prefix . 'wc_pos_branches';

        $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %s", $id ) );
        if ( ! $existing ) {
            return new WP_Error( 'wc_pos_branch_not_found', __( 'Branch not found.', 'wc-pos-pro' ), array( 'status' => 404 ) );
        }

        // Bug fix: every field was being run through sanitize_textarea_field(),
        // even single-line fields like name/code/phone/status — harmless in
        // practice (textarea sanitization is a superset of text-field
        // sanitization for these values) but not the right function, and
        // 'status' had no validation against known values at all.
        $single_line_fields = array( 'name', 'code', 'phone', 'status' );
        $textarea_fields    = array( 'address', 'receipt_header', 'receipt_footer' );
        $data = array();

        foreach ( $single_line_fields as $field ) {
            if ( $request->has_param( $field ) ) {
                $value = sanitize_text_field( $request->get_param( $field ) );
                if ( 'status' === $field && ! in_array( $value, array( 'active', 'inactive' ), true ) ) {
                    continue; // ignore invalid status rather than silently storing garbage
                }
                $data[ $field ] = $value;
            }
        }
        foreach ( $textarea_fields as $field ) {
            if ( $request->has_param( $field ) ) {
                $data[ $field ] = sanitize_textarea_field( $request->get_param( $field ) );
            }
        }
        if ( $request->has_param( 'email' ) ) {
            $data['email'] = sanitize_email( $request->get_param( 'email' ) );
        }

        if ( ! empty( $data ) ) {
            $wpdb->update( $table, $data, array( 'id' => $id ) );
        }

        $updated_branch = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %s", $id ), ARRAY_A );

        return new WP_REST_Response( array(
            'success' => true,
            'data'    => $updated_branch,
        ), 200 );
    }

    /**
     * Delete a branch.
     */
    public function delete_item( $request ) {
        global $wpdb;
        $id = sanitize_text_field( $request['id'] );

        $table_branches  = $wpdb->prefix . 'wc_pos_branches';
        $table_registers = $wpdb->prefix . 'wc_pos_registers';

        // Ensure no active registers belong to this branch before deleting
        $active_registers = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table_registers} WHERE branch_id = %s", $id ) );
        if ( $active_registers > 0 ) {
            return new WP_Error( 'wc_pos_branch_has_registers', __( 'Cannot delete branch because registers are currently assigned to it. Reassign or delete the registers first.', 'wc-pos-pro' ), array( 'status' => 400 ) );
        }

        $deleted = $wpdb->delete( $table_branches, array( 'id' => $id ) );

        if ( ! $deleted ) {
            return new WP_Error( 'wc_pos_db_error', __( 'Failed to delete branch.', 'wc-pos-pro' ), array( 'status' => 500 ) );
        }

        return new WP_REST_Response( array(
            'success' => true,
            'message' => __( 'Branch deleted successfully.', 'wc-pos-pro' ),
        ), 200 );
    }

    /**
     * Fetch branch specific product stock levels.
     */
    public function get_branch_stock( $request ) {
        global $wpdb;
        $branch_id = sanitize_text_field( $request['id'] );
        $table     = $wpdb->prefix . 'wc_pos_branch_stock';

        $stock = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE branch_id = %s", $branch_id ), ARRAY_A );

        return new WP_REST_Response( array(
            'success' => true,
            'data'    => $stock ?: array(),
        ), 200 );
    }

    /**
     * Update stock count for a single product/variation within a specific branch.
     */
    public function update_branch_stock( $request ) {
        global $wpdb;
        $branch_id    = sanitize_text_field( $request['id'] );
        $product_id   = absint( $request->get_param( 'product_id' ) );
        $variation_id = absint( $request->get_param( 'variation_id' ) ?? 0 );
        $quantity     = intval( $request->get_param( 'stock_quantity' ) );

        if ( ! $product_id ) {
            return new WP_Error( 'wc_pos_invalid_product', __( 'Product ID is required.', 'wc-pos-pro' ), array( 'status' => 400 ) );
        }

        $table = $wpdb->prefix . 'wc_pos_branch_stock';

        $sql = "INSERT INTO {$table} (branch_id, product_id, variation_id, stock_quantity)
                VALUES (%s, %d, %d, %d)
                ON DUPLICATE KEY UPDATE stock_quantity = %d";

        $prepared = $wpdb->prepare( $sql, $branch_id, $product_id, $variation_id, $quantity, $quantity );
        $result   = $wpdb->query( $prepared );

        if ( $result === false ) {
            return new WP_Error( 'wc_pos_db_error', __( 'Failed to update branch stock.', 'wc-pos-pro' ), array( 'status' => 500 ) );
        }

        return new WP_REST_Response( array(
            'success' => true,
            'message' => __( 'Branch stock updated successfully.', 'wc-pos-pro' ),
        ), 200 );
    }
}