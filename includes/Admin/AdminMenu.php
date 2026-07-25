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
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
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
        if ( $id > 0 ) {
            $wpdb->update( $table, $data, array( 'id' => $id ) );
        } else {
            $wpdb->insert( $table, $data );
            $id = $wpdb->insert_id;
        }
        wp_send_json_success( array( 'id' => $id ) );
    }

    public function ajax_delete_tax_rate() {
        check_ajax_referer( 'wc_pos_tax_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }
        global $wpdb;
        $id = intval( $_POST['rate_id'] ?? 0 );
        $wpdb->update( $wpdb->prefix . 'wc_pos_tax_rates', array( 'is_active' => 0 ), array( 'id' => $id ) );
        wp_send_json_success();
    }


    // -------------------------------------------------------------------------
    // Menu registration
    // -------------------------------------------------------------------------

    public function register_menu() {
        add_menu_page(
            __( 'WooCommerce POS', 'wc-pos-pro' ),
            __( 'POS Terminal', 'wc-pos-pro' ),
            'manage_woocommerce',
            'wc-pos-pro',
            array( $this, 'render_pos_admin_page' ),
            'dashicons-store',
            56
        );
        add_submenu_page( 'wc-pos-pro', __( 'Embedded Terminal', 'wc-pos-pro' ), __( 'Embedded Terminal', 'wc-pos-pro' ), 'manage_woocommerce', 'wc-pos-pro-embedded', array( $this, 'render_embedded_terminal_page' ) );
        add_submenu_page( 'wc-pos-pro', __( 'POS Settings', 'wc-pos-pro' ),      __( 'Settings', 'wc-pos-pro' ),          'manage_woocommerce', 'wc-pos-pro-settings', array( $this, 'render_settings_page' ) );
        add_submenu_page( 'wc-pos-pro', __( 'Tax Management', 'wc-pos-pro' ),    __( 'Tax', 'wc-pos-pro' ),               'manage_woocommerce', 'wc-pos-pro-tax',      array( $this, 'render_tax_page' ) );
        add_submenu_page( 'wc-pos-pro', __( 'Receipt Builder', 'wc-pos-pro' ),   __( 'Receipt', 'wc-pos-pro' ),           'manage_woocommerce', 'wc-pos-pro-receipt',  array( $this, 'render_receipt_page' ) );
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

        function posSaveTaxRate() {
            const id   = document.getElementById('tax_editing_id').value;
            const name = document.getElementById('tax_name').value.trim();
            const rate = document.getElementById('tax_rate').value;
            const incl = document.getElementById('tax_inclusive').checked ? 1 : 0;
            const app  = document.getElementById('tax_applies_to').value;
            const prio = document.getElementById('tax_priority').value;
            if (!name || isNaN(parseFloat(rate))) { alert('Name and rate are required.'); return; }
            const body = new URLSearchParams({ action: 'wc_pos_save_tax_rate', nonce: posTaxNonce, rate_id: id, name, rate, is_inclusive: incl, applies_to: app, priority: prio });
            fetch(posTaxAjaxUrl, { method: 'POST', body }).then(r => r.json()).then(d => {
                if (d.success) { location.reload(); } else { alert('Error saving rate.'); }
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
            fetch(posTaxAjaxUrl, { method: 'POST', body }).then(r => r.json()).then(d => {
                if (d.success) { const row = document.getElementById('tax-rate-row-' + id); if (row) row.remove(); }
            });
        }
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
