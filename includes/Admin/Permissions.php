<?php
namespace WCPOS\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Permissions {

    public static function register_capabilities() {
        // Add custom capabilities to Administrator and Shop Manager
        $roles = array( 'administrator', 'shop_manager' );
        foreach ( $roles as $role_name ) {
            $role = get_role( $role_name );
            if ( $role ) {
                $role->add_cap( 'manage_wc_pos' );
                $role->add_cap( 'process_wc_pos_sales' );
                $role->add_cap( 'override_wc_pos_prices' );
                $role->add_cap( 'manage_wc_pos_branches' );
            }
        }

        // Create or configure dedicated Cashier role
        if ( ! get_role( 'pos_cashier' ) ) {
            add_role( 'pos_cashier', __( 'POS Cashier', 'wc-pos-pro' ), array(
                'read'                  => true,
                'process_wc_pos_sales'  => true,
                'read_private_shop_orders' => true,
            ) );
        }
    }
}
