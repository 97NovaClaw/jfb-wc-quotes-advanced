<?php
/**
 * Plugin Name: JFB WC Quotes Advanced
 * Plugin URI:  https://legworkmedia.ca
 * Description: Advanced integration for JetFormBuilder & WooCommerce. Map fields (incl. JE meta), custom "Estimate Request" email configured in plugin settings and triggered via Order Action, dynamic cart shortcode, custom order status. Admin settings page with integrated field mapping UI. HPOS-compatible; creates orders in-process via wc_create_order() (no REST credentials required).
 * Version:     1.28.1
 * Author:      legworkmedia
 * Author URI:  https://legworkmedia.ca
 * License:     GPL2
 * Text Domain: jfb-wc-quotes-advanced
 * Domain Path: /languages
 * Requires Plugins: woocommerce, jetformbuilder
 * WC requires at least: 7.1
 * WC tested up to: 10.7
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // No direct access.
}

define( 'JFBWQA_VERSION', '1.28.1' );
define( 'JFBWQA_OPTION_NAME', 'jfbwqa_options' ); // Option key for general settings
define( 'JFBWQA_SETTINGS_SLUG', 'jfbwqa-settings' ); // Menu slug for settings page

/* =============================================================================
   0) HPOS Compatibility Declaration & Screen Helpers
   ============================================================================= */

// Declare compatibility with WooCommerce HPOS (Custom Order Tables).
// Without this, WC marks the plugin as "uncertain" in Settings -> Advanced -> Features.
add_action( 'before_woocommerce_init', function() {
    if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
    }
} );

/**
 * Returns true when WooCommerce HPOS (Custom Orders Table) is the active order store.
 */
function jfbwqa_is_hpos_enabled() {
    return class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil' )
        && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
}

/**
 * Returns the admin screen ID for the order edit page on the active store.
 * - HPOS: 'woocommerce_page_wc-orders'
 * - Legacy: 'shop_order'
 *
 * Used to register meta boxes against the correct screen.
 */
function jfbwqa_get_order_screen_id() {
    if ( jfbwqa_is_hpos_enabled() && function_exists( 'wc_get_page_screen_id' ) ) {
        return wc_get_page_screen_id( 'shop-order' );
    }
    return 'shop_order';
}

/**
 * Resolves the current admin screen to a WC_Order, if applicable.
 * Handles both legacy (post.php?post=ID) and HPOS (admin.php?page=wc-orders&id=ID&action=edit).
 * Returns null when not on the order edit screen or when the order can't be loaded.
 */
function jfbwqa_get_current_admin_order() {
    if ( ! is_admin() || ! function_exists( 'get_current_screen' ) ) {
        return null;
    }
    $screen = get_current_screen();
    if ( ! $screen ) {
        return null;
    }

    $order_id = 0;

    // Legacy: post edit screen for shop_order
    if ( $screen->base === 'post' && $screen->post_type === 'shop_order' ) {
        $order_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
        if ( ! $order_id ) {
            global $post;
            if ( $post && get_post_type( $post ) === 'shop_order' ) {
                $order_id = (int) $post->ID;
            }
        }
    }

    // HPOS: woocommerce_page_wc-orders with action=edit&id=N
    if ( ! $order_id && strpos( $screen->id, 'woocommerce_page_wc-orders' ) !== false ) {
        if ( isset( $_GET['action'] ) && $_GET['action'] === 'edit' && isset( $_GET['id'] ) ) {
            $order_id = absint( $_GET['id'] );
        }
    }

    if ( ! $order_id ) {
        return null;
    }

    return function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
}

/* =============================================================================
   1) Basic Paths & Utility Functions
   ============================================================================= */

function jfbwqa_plugin_dir() {
    return plugin_dir_path( __FILE__ );
}
// Path for the mapping configuration file
function jfbwqa_mapping_path() {
    return jfbwqa_plugin_dir() . 'field-mapping.json';
}
// Path for the temporarily uploaded JetForm export
function jfbwqa_jetform_path() {
    return jfbwqa_plugin_dir() . 'jetform-latest.json';
}

// --- Get Plugin General Options (Uses WP Options API) ---
function jfbwqa_get_options() {
    $defaults = [
        // NOTE (v1.25): consumer_key/consumer_secret were removed when the
        // form handler stopped using the WC REST API self-loopback. Order
        // creation is now in-process via wc_create_order(); no credentials
        // are required by this plugin. Old DB values are surfaced via an
        // admin notice (see jfbwqa_legacy_credentials_notice) and can be
        // safely deleted from wp_options once acknowledged.
        'hook_name'          => 'my_jfb_wc_estimate_form',
        'shortcode_name'     => 'my_cart_json',
        'jetengine_keys'     => '', // Stored here, used for mapping options & placeholders
        'enable_debug'       => false,
        'email_subject'      => 'Your Estimate Request #{order_number}',
        'email_heading'      => 'Estimate Request Details',
        'email_reply_to'     => get_option('admin_email'),
        'email_cc'           => '',
        'email_default_body' => "Thank you for your estimate request. We have received the following details:\n\n[Order Details Table]\n\nWe will review your request and get back to you shortly.\n\nRegards,\n{site_title}",
        // Defaults for the new Quote Email
        'quote_email_subject'      => 'Your Quote #{order_number} is Ready',
        'quote_email_heading'      => 'Your Prepared Quote',
        'quote_email_reply_to'   => get_option('admin_email'), // Default for new field
        'quote_email_cc'         => '', // Default for new field
        'quote_email_default_body' => "Hello {customer_first_name},\n\nYour quote is ready! Please find the details below:\n\n[Order Details Table]\n\nIf you have any questions, please let us know.\n\nRegards,\n{site_title}", // Removed {additional_message_from_admin}
        'display_discount_in_quote' => false, // [DEPRECATED v1.27] superseded by quote_table_show_discount, kept for back-compat reads.

        // v1.27: Estimate Request email - Order Details Table defaults.
        // Conservative (nothing surfaced) so the acknowledgement email
        // stays free of price/total info unless the admin opts in.
        'est_table_show_image'       => true,
        'est_table_show_unit_price'  => false,
        'est_table_show_line_total'  => false,
        'est_table_show_subtotal'    => false,
        'est_table_show_shipping'    => false,
        'est_table_show_fees'        => false,
        'est_table_show_discount'    => false,
        'est_table_show_tax'         => false,
        'est_table_show_grand_total' => false,

        // v1.27: Prepared Quote email - Order Details Table defaults.
        // Liberal (everything surfaced) - a prepared quote is supposed
        // to include pricing, otherwise it isn't a quote. Modal can
        // still override per-send.
        'quote_table_show_image'       => true,
        'quote_table_show_unit_price'  => true,
        'quote_table_show_line_total'  => true,
        'quote_table_show_subtotal'    => true,
        'quote_table_show_shipping'    => true,
        'quote_table_show_fees'        => true,
        'quote_table_show_discount'    => false, // off by default; admin opts in (parity with old display_discount_in_quote)
        'quote_table_show_tax'         => true,
        'quote_table_show_grand_total' => true,
    ];
    $options = get_option( JFBWQA_OPTION_NAME, [] );

    // Ensure boolean is correctly typed
    $options['enable_debug'] = isset($options['enable_debug']) ? filter_var($options['enable_debug'], FILTER_VALIDATE_BOOLEAN) : $defaults['enable_debug'];

    // Ensure default body is present if empty after save
    if ( empty( $options['email_default_body'] ) ) {
         $options['email_default_body'] = $defaults['email_default_body'];
    }
    // Ensure default quote body is present if empty after save
    if ( empty( $options['quote_email_default_body'] ) ) {
        $options['quote_email_default_body'] = $defaults['quote_email_default_body'];
   }

    // *** DEBUG LOGGING START ***
    jfbwqa_write_log("DEBUG: jfbwqa_get_options() - Raw email_default_body from DB: " . ($options['email_default_body'] ?? 'NOT SET'));
    // *** DEBUG LOGGING END ***

    return wp_parse_args( $options, $defaults );
}

// --- Read Mapping File (Reads field-mapping.json) ---
function jfbwqa_read_mapping() {
    $path = jfbwqa_mapping_path();
    if ( ! file_exists( $path ) ) return [];
    $raw = @file_get_contents( $path );
    if ( $raw === false ) {
        error_log("JFBWQA Error: Cannot read mapping $path");
        return [];
    }
    $decoded = json_decode( $raw, true );
    if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $decoded ) ) {
        error_log("JFBWQA Error: Cannot decode mapping $path");
        return [];
    }
    return $decoded;
}

// --- Write Mapping File (Writes to field-mapping.json) ---
function jfbwqa_write_mapping( $mapping ) {
    $path = jfbwqa_mapping_path();
    $dir = dirname( $path );

    if ( ! is_writable( $dir ) ) {
        error_log("JFBWQA Error: Mapping dir not writable " . $dir);
        add_settings_error('jfbwqa_mapping', 'mapping_write_error', __('Error: Mapping directory is not writable.', 'jfb-wc-quotes-advanced'), 'error');
        return false;
    }
     if ( is_file( $path ) && ! is_writable( $path ) ) {
         error_log("JFBWQA Error: Mapping file not writable " . $path);
          add_settings_error('jfbwqa_mapping', 'mapping_file_write_error', __('Error: Mapping file (field-mapping.json) is not writable.', 'jfb-wc-quotes-advanced'), 'error');
         return false;
     }

    $result = file_put_contents( $path, json_encode( $mapping, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
    if ( $result === false ) {
        error_log("JFBWQA Error: Failed writing mapping $path");
         add_settings_error('jfbwqa_mapping', 'mapping_save_error', __('Error: Failed to save mapping data to field-mapping.json.', 'jfb-wc-quotes-advanced'), 'error');
    } else {
        // Add success message specifically for mapping if saved successfully this way
        // Note: This might appear alongside the main "Settings saved." message if both happen.
        add_settings_error('jfbwqa_mapping', 'mapping_saved', __('Field mapping saved successfully to field-mapping.json.', 'jfb-wc-quotes-advanced'), 'updated');
    }
    return $result !== false;
}

// --- Debug Logger (Reads 'enable_debug' option) ---
function jfbwqa_write_log( $msg, $force = false ) {
    static $cached_options = null;
    static $is_currently_writing = false; // Guard against file write recursion

    if ($is_currently_writing && !$force) { // If already writing and not forcing, prevent recursion
        error_log("JFBWQA Log Recursion Guard (file write): Attempted to log while already writing. Message: $msg");
        return;
    }

    if ($cached_options === null) {
        // Simplified get_option call to avoid full jfbwqa_get_options() recursion here
        // This ensures we only fetch options needed for the logger to decide if it should log.
        $raw_plugin_options = get_option(JFBWQA_OPTION_NAME, []);
        $default_enable_debug = false; // Default for enable_debug if not set in DB
        $cached_options = [
            'enable_debug' => isset($raw_plugin_options['enable_debug']) ? filter_var($raw_plugin_options['enable_debug'], FILTER_VALIDATE_BOOLEAN) : $default_enable_debug
        ];
    }

    $enabled = isset( $cached_options['enable_debug'] ) ? $cached_options['enable_debug'] : false;

    if ( ! $enabled && ! $force ) {
        return;
    }

    $log_dir = jfbwqa_plugin_dir() . 'debug';
    $log_file = $log_dir . '/debug.log';

    if ( ! is_dir( $log_dir ) ) {
        // Try to create, suppress errors if it fails (e.g. permissions)
        @mkdir( $log_dir, 0755, true );
    }

    // Check if dir is writable OR if the file itself is writable (helpful if dir perms are strict but file exists)
    if ( is_writable( $log_dir ) || (file_exists($log_file) && is_writable($log_file)) || (!file_exists($log_file) && is_writable(dirname($log_file))) ) {
        $timestamp = date('Y-m-d H:i:s');
        $is_currently_writing = true;
        // Use JSON_INVALID_UTF8_SUBSTITUTE for print_r to avoid issues with non-UTF8 chars
        $processed_msg = is_string($msg) ? $msg : print_r($msg, true);
        if (json_encode($processed_msg) === false && json_last_error() === JSON_ERROR_UTF8) {
            $processed_msg = print_r($msg, true); // Fallback if specific UTF8 issue
            $processed_msg = mb_convert_encoding($processed_msg, 'UTF-8', 'UTF-8'); // Ensure it's UTF-8
        }

        @file_put_contents( $log_file, "[$timestamp] " . $processed_msg . "\n", FILE_APPEND | LOCK_EX );
        $is_currently_writing = false;
    } else {
        // Fallback to error_log if debug file not writable and debug is forced or explicitly enabled
        if ($enabled || $force) {
            error_log("JFBWQA Plugin - Debug Log Target Not Writable ($log_file). Message: " . (is_string($msg) ? $msg : print_r($msg, true)));
        }
    }
}

// v1.25: removed the unconditional 'Plugin file loaded' log line that
// previously fired on every request regardless of the enable_debug flag.
// The plugin now only logs when debug is explicitly enabled in settings.
jfbwqa_write_log( 'Plugin file loaded (v' . JFBWQA_VERSION . ').' );

/* =============================================================================
   1.5) One-time admin notice for legacy credentials (post-v1.25 migration)
   ============================================================================= */

/**
 * If consumer_key / consumer_secret are still present in wp_options from a
 * pre-v1.25 install, surface a dismissible admin notice on the JFBWQA settings
 * page so the admin knows they can be removed and that the plugin no longer
 * needs them.
 *
 * The notice is purely informational; it does not auto-delete the values to
 * avoid surprising anyone who might be reading them outside this plugin.
 */
add_action( 'admin_notices', 'jfbwqa_legacy_credentials_notice' );
function jfbwqa_legacy_credentials_notice() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( ! $screen || strpos( $screen->id, JFBWQA_SETTINGS_SLUG ) === false ) {
        return; // Only on our own settings page.
    }
    $raw = get_option( JFBWQA_OPTION_NAME, [] );
    $has_legacy = ! empty( $raw['consumer_key'] ) || ! empty( $raw['consumer_secret'] );
    if ( ! $has_legacy ) {
        return;
    }
    if ( isset( $_GET['jfbwqa_clear_credentials'] ) && check_admin_referer( 'jfbwqa_clear_credentials' ) ) {
        unset( $raw['consumer_key'], $raw['consumer_secret'] );
        update_option( JFBWQA_OPTION_NAME, $raw );
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Legacy WooCommerce REST API credentials removed from plugin options.', 'jfb-wc-quotes-advanced' ) . '</p></div>';
        return;
    }
    $clear_url = wp_nonce_url(
        add_query_arg( 'jfbwqa_clear_credentials', '1' ),
        'jfbwqa_clear_credentials'
    );
    ?>
    <div class="notice notice-warning">
        <p>
            <strong><?php esc_html_e( 'JFB WC Quotes Advanced:', 'jfb-wc-quotes-advanced' ); ?></strong>
            <?php esc_html_e( 'Legacy WooCommerce REST API credentials were detected in this plugin\'s options.', 'jfb-wc-quotes-advanced' ); ?>
            <?php esc_html_e( 'As of v1.25, orders are created in-process via wc_create_order() and these credentials are no longer used.', 'jfb-wc-quotes-advanced' ); ?>
            <?php esc_html_e( 'They can be safely removed.', 'jfb-wc-quotes-advanced' ); ?>
        </p>
        <p>
            <a class="button button-secondary" href="<?php echo esc_url( $clear_url ); ?>"><?php esc_html_e( 'Remove legacy credentials', 'jfb-wc-quotes-advanced' ); ?></a>
            <em><?php esc_html_e( 'Note: this only clears the values from this plugin\'s settings. To revoke the API key itself, go to WooCommerce -> Settings -> Advanced -> REST API.', 'jfb-wc-quotes-advanced' ); ?></em>
        </p>
    </div>
    <?php
}

/* =============================================================================
   1.6) Email Order-Details Table Config (v1.27)
   -----------------------------------------------------------------------------
   Three layers:
     1. jfbwqa_default_table_config()
        Hardcoded defaults used when nothing else applies. Conservative -
        nothing surfaced.
     2. jfbwqa_get_table_config_from_settings( 'estimate' | 'quote' )
        Reads admin-configured defaults from wp_options for the given
        email type. Used as the baseline at send time.
     3. jfbwqa_normalize_legacy_table_args( $show_prices, $show_grand, $discount )
        Translates the v1.25/v1.26 positional argument shape used by
        jfbwqa_replace_email_placeholders() into the new config-array
        shape. Lets callers that haven't been updated yet keep working.
   ============================================================================= */

/**
 * Default table config (everything hidden). Used as a base layer that
 * higher layers (settings, modal overrides) merge into.
 */
function jfbwqa_default_table_config() {
    return [
        'show_image'       => true,  // images on by default - they're cheap and friendly
        'show_unit_price'  => false,
        'show_line_total'  => false,
        'show_subtotal'    => false,
        'show_shipping'    => false,
        'show_fees'        => false,
        'show_discount'    => false,
        'show_tax'         => false,
        'show_grand_total' => false,
    ];
}

/**
 * Read the per-email-type table config from wp_options.
 *
 * @param string $type 'estimate' or 'quote'.
 * @return array Config array with all 9 toggles populated.
 */
function jfbwqa_get_table_config_from_settings( $type ) {
    $type    = ( $type === 'quote' ) ? 'quote' : 'estimate';
    $prefix  = ( $type === 'quote' ) ? 'quote_table_' : 'est_table_';
    $opts    = jfbwqa_get_options();
    $defaults = jfbwqa_default_table_config();
    $config  = [];
    foreach ( $defaults as $k => $default_value ) {
        $opt_key       = $prefix . $k;
        $config[ $k ]  = isset( $opts[ $opt_key ] )
            ? (bool) $opts[ $opt_key ]
            : (bool) $default_value;
    }
    return $config;
}

/**
 * Translate the legacy positional ($show_prices, $show_grand_total_with_tax,
 * $display_discount) argument shape into the new config-array shape.
 *
 * Mapping rationale: v1.25/v1.26 only had three knobs; "show prices" was a
 * blanket switch covering unit price + line total + subtotal + shipping +
 * fees + tax (the totals footer was all-or-nothing on prices). The two
 * extra flags layered grand-total and discount on top of that. This helper
 * preserves that semantics so callers that haven't migrated still produce
 * identical output.
 */
function jfbwqa_normalize_legacy_table_args( $show_prices, $show_grand_total_with_tax = false, $display_discount = null ) {
    $show_prices          = (bool) $show_prices;
    $show_grand           = (bool) $show_grand_total_with_tax;
    $display_discount     = ( $display_discount === null ) ? false : (bool) $display_discount;
    return [
        'show_image'       => true,
        'show_unit_price'  => $show_prices,
        'show_line_total'  => $show_prices,
        'show_subtotal'    => $show_prices,
        'show_shipping'    => $show_prices,
        'show_fees'        => $show_prices,
        'show_discount'    => $show_prices && $display_discount,
        'show_tax'         => $show_prices,
        'show_grand_total' => $show_prices && $show_grand,
    ];
}

/**
 * Reduce a config array down to "any pricing visible" - useful for the
 * email-order-items template to know whether to render the price columns
 * at all (controls the colspan and the table header row width).
 */
function jfbwqa_table_config_has_prices( $config ) {
    return ! empty( $config['show_unit_price'] ) || ! empty( $config['show_line_total'] );
}

/* =============================================================================
   2) Register Custom Order Status "wc-estimate-request" (Unchanged)
   ============================================================================= */
add_action( 'init', 'jfbwqa_register_estimate_request_status', 1 );
function jfbwqa_register_estimate_request_status() {
    if (!function_exists('register_post_status')) return;
    register_post_status( 'wc-estimate-request', [
        'label' => _x('Estimate Request','order status','jfb-wc-quotes-advanced'),
        'public' => true, 'exclude_from_search' => false,
        'show_in_admin_all_list' => true, 'show_in_admin_status_list' => true,
        'label_count' => _n_noop('Estimate Request (%s)','Estimate Requests (%s)','jfb-wc-quotes-advanced'),
    ]);
    jfbwqa_write_log("Registered status: wc-estimate-request");
}
add_filter( 'wc_order_statuses', 'jfbwqa_add_estimate_request_status' );
function jfbwqa_add_estimate_request_status( $statuses ) {
    if (!isset($statuses['wc-estimate-request'])) {
        $statuses['wc-estimate-request'] = _x('Estimate Request', 'order status', 'jfb-wc-quotes-advanced');
    }
    return $statuses;
}

// Make Estimate Request status editable like Pending Payment
add_filter( 'wc_order_is_editable', 'jfbwqa_make_estimate_request_editable', 10, 2 );
function jfbwqa_make_estimate_request_editable( $is_editable, $order ) {
    if ( $order->get_status() === 'estimate-request' ) {
        $is_editable = true;
    }
    return $is_editable;
}

// Add Estimate Request to the list of statuses that allow editing
add_filter( 'woocommerce_valid_order_statuses_for_payment', 'jfbwqa_add_estimate_to_valid_statuses', 10, 2 );
function jfbwqa_add_estimate_to_valid_statuses( $statuses, $order ) {
    $statuses[] = 'estimate-request';
    return $statuses;
}

// Ensure line items can be edited for Estimate Request orders
add_filter( 'woocommerce_order_item_add_action_buttons', 'jfbwqa_enable_item_editing_for_estimates', 10, 1 );
function jfbwqa_enable_item_editing_for_estimates( $order ) {
    if ( $order && $order->get_status() === 'estimate-request' ) {
        // This ensures the order is treated as editable
        add_filter( 'woocommerce_order_is_editable', '__return_true' );
    }
}

// Register Quote Sent status
add_action( 'init', 'jfbwqa_register_quote_sent_status', 1 );
function jfbwqa_register_quote_sent_status() {
    if (!function_exists('register_post_status')) return;
    register_post_status( 'wc-quote-sent', [
        'label' => _x('Quote Sent','order status','jfb-wc-quotes-advanced'),
        'public' => true, 'exclude_from_search' => false,
        'show_in_admin_all_list' => true, 'show_in_admin_status_list' => true,
        'label_count' => _n_noop('Quote Sent (%s)','Quotes Sent (%s)','jfb-wc-quotes-advanced'),
    ]);
    jfbwqa_write_log("Registered status: wc-quote-sent");
}
add_filter( 'wc_order_statuses', 'jfbwqa_add_quote_sent_status' );
function jfbwqa_add_quote_sent_status( $statuses ) {
    if (!isset($statuses['wc-quote-sent'])) {
        $statuses['wc-quote-sent'] = _x('Quote Sent', 'order status', 'jfb-wc-quotes-advanced');
    }
    return $statuses;
}

// Make Quote Sent status editable too
add_filter( 'wc_order_is_editable', 'jfbwqa_make_quote_sent_editable', 10, 2 );
function jfbwqa_make_quote_sent_editable( $is_editable, $order ) {
    if ( $order->get_status() === 'quote-sent' ) {
        $is_editable = true;
    }
    return $is_editable;
}

/* =============================================================================
   3) Get Combined WC & Manually Entered JE Order Fields (Reads option for JE Keys)
   ============================================================================= */
// Used to populate the mapping dropdowns
function jfbwqa_get_combined_order_fields() {
    $options = jfbwqa_get_options();
    $combined_fields = [];
    $core_fields = [ /* ... core fields ... */
        'billing.first_name','billing.last_name','billing.company','billing.address_1',
        'billing.address_2','billing.city','billing.state','billing.postcode','billing.country',
        'billing.email','billing.phone','shipping.first_name','shipping.last_name',
        'shipping.company','shipping.address_1','shipping.address_2','shipping.city',
        'shipping.state','shipping.postcode','shipping.country','customer_note'
    ];
    $combined_fields = array_merge( $combined_fields, $core_fields );

    if ( ! empty( $options['jetengine_keys'] ) ) {
        $meta_keys = preg_split( '/\r\n|\r|\n/', trim( $options['jetengine_keys'] ) );
        foreach ( $meta_keys as $key ) {
            $trimmed_key = trim( $key );
            if ( ! empty( $trimmed_key ) ) {
                $combined_fields[] = '*JE_meta*.' . $trimmed_key;
            }
        }
    }
    $combined_fields[] = '*Cart items list*';
    // Example: Add other non-standard meta if needed, maybe via another setting?
    // $combined_fields[] = 'meta_data.some_other_plugin_meta';
    $combined_fields = array_values( array_unique( $combined_fields ) );
    sort( $combined_fields );
    return $combined_fields;
}


/* =============================================================================
   4) Extract Fields from JetForm JSON Content (Parses uploaded file)
   ============================================================================= */
// Helper used by the mapping UI generation
function jfbwqa_extract_fields_from_post_content( $post_content ) {
    $fields = [];
    if ( ! is_string( $post_content ) || empty( $post_content ) ) return $fields;

    // Use the regex from v1.13 as it targets the block comment format
    $pattern = '/<!--\s*wp:jet-forms\/([a-zA-Z0-9\-]+)\s+({.*?})\s*\/-->/s';
    if ( preg_match_all( $pattern, $post_content, $matches, PREG_SET_ORDER ) ) {
        foreach ( $matches as $match ) {
            $block_type = $match[1] ?? '';
            $json_attrs = $match[2] ?? '';
            if ( empty( $json_attrs ) ) continue;

            // Need to handle potential encoding issues if copy/pasted
            $json_attrs_decoded = mb_convert_encoding( $json_attrs, 'UTF-8', 'UTF-8' );
            $attrs = json_decode( $json_attrs_decoded, true );

            if ( json_last_error() === JSON_ERROR_NONE && is_array( $attrs ) && isset( $attrs['name'] ) && ! empty( $attrs['name'] ) ) {
                $field_id = $attrs['name'];
                $field_label = isset( $attrs['label'] ) && trim( $attrs['label'] ) !== '' ? trim( $attrs['label'] ) : $field_id;
                // Use field ID as key to prevent duplicates if name appears twice
                if ( ! isset( $fields[$field_id] ) ) {
                    $fields[$field_id] = ['id' => $field_id, 'name' => $field_label];
                }
            } else {
                 jfbwqa_write_log("Debug: Failed decoding attributes or finding name for block type '{$block_type}'. JSON: " . substr($json_attrs, 0, 100));
            }
        }
    } else {
        jfbwqa_write_log("Debug: No JetForm block comments found in provided post_content via regex.");
    }

    return array_values( $fields ); // Return indexed array
}


/* =============================================================================
   5) Register Dynamic Cart Shortcode (Reads option)
   ============================================================================= */
add_action( 'init', 'jfbwqa_register_dynamic_shortcode' );
function jfbwqa_register_dynamic_shortcode() {
    $options = jfbwqa_get_options();
    $shortcode_tag = ! empty( $options['shortcode_name'] ) ? sanitize_key( $options['shortcode_name'] ) : 'my_cart_json';
    add_shortcode( $shortcode_tag, 'jfbwqa_cart_shortcode_callback' );
    // Log only if debug enabled?
    jfbwqa_write_log("Registered shortcode: [{$shortcode_tag}]");
}
function jfbwqa_cart_shortcode_callback() {
    if ( ! function_exists( 'WC' ) || ! WC()->cart ) return '[]';
    $cart_items = WC()->cart->get_cart();
    $items_data = [];
    foreach ( $cart_items as $cart_item ) {
        $items_data[] = [
            'id'  => absint( $cart_item['product_id'] ?? 0 ),
            'qty' => absint( $cart_item['quantity'] ?? 1 )
        ];
    }
    return wp_json_encode( $items_data );
}

/* =============================================================================
   6) Hook into JetFormBuilder Submission (Reads option)
   ============================================================================= */
add_action( 'init', 'jfbwqa_init_form_hook' );
function jfbwqa_init_form_hook() {
    $options = jfbwqa_get_options();
    $hook_tag = ! empty($options['hook_name']) ? sanitize_key($options['hook_name']) : 'my_jfb_wc_estimate_form';
    if ( function_exists('jet_form_builder') ) {
        add_filter("jet-form-builder/custom-filter/{$hook_tag}", 'jfbwqa_handle_form_submission', 10, 3);
        jfbwqa_write_log("Initialized JFB custom filter hook: .../{$hook_tag}");
    } else {
         add_action('admin_notices', function() { echo '<div class="notice notice-warning"><p>' . esc_html__('JFB WC Quotes Advanced requires JetFormBuilder to be active.', 'jfb-wc-quotes-advanced') . '</p></div>'; });
    }
}

// --- Form Submission Handler (uses mapping JSON, creates order in-process) ---
//
// v1.25 architecture change:
// Previously this handler made a self-loopback HTTP call to /wp-json/wc/v3/orders
// using a stored consumer_key/consumer_secret pair. That required REST credentials
// to live in wp_options (or, originally, in plugin-config.json) and added an HTTP
// roundtrip + an authentication surface for no benefit on a single-site install.
//
// The handler now creates the order in-process via wc_create_order() and the
// native WC_Order setters. This:
//   - removes the credential dependency entirely (settings page no longer asks
//     for ck_/cs_ keys)
//   - removes the HTTP roundtrip (~200-1000ms saved per submission)
//   - is HPOS-safe out of the box (uses CRUD methods, never writes directly to
//     wp_postmeta for order data)
//   - preserves the same error semantics for the JFB filter chain (returns
//     WP_Error on validation failure, returns the original $result on success)
//
// The mapping schema (field-mapping.json) is unchanged. Same target tokens
// (billing.x, shipping.x, *JE_meta*.x, meta_data.x, customer_note,
// *Cart items list*) work exactly as before.
function jfbwqa_handle_form_submission( $result, $request, $action_handler ) {
    jfbwqa_write_log( 'JFB form submission handling started.' );

    if ( ! function_exists( 'wc_create_order' ) ) {
        jfbwqa_write_log( 'ERROR: WooCommerce is not active. Cannot create estimate order.' );
        return new WP_Error( 'jfbwqa_config_error', __( 'Configuration error: WooCommerce must be active to create estimate orders.', 'jfb-wc-quotes-advanced' ) );
    }

    $mapping = jfbwqa_read_mapping(); // Read mapping from field-mapping.json
    if ( empty( $mapping ) ) {
        jfbwqa_write_log( 'WARNING: Field mapping (field-mapping.json) is empty. Cannot map fields.' );
    }

    // Intermediate buffer that mirrors the previous REST payload shape so
    // the mapping logic below stays untouched. We translate this buffer
    // into native WC_Order setter calls right before save().
    $order_buffer = [
        'billing'       => [],
        'shipping'      => [],
        'meta_data'     => [],
        'line_items'    => [],
        'customer_note' => '',
    ];
    $jetengine_meta_to_save = [];
    $cart_items_json        = '';

    // Process form fields based on mapping from JSON file
    foreach ( $mapping as $jfb_field_id => $wc_targets ) {
        if ( ! isset( $request[ $jfb_field_id ] ) ) {
            continue;
        }
        $jfb_field_value = $request[ $jfb_field_id ];
        $sanitized_value = is_array( $jfb_field_value )
            ? array_map( 'sanitize_text_field', $jfb_field_value )
            : sanitize_text_field( $jfb_field_value );

        foreach ( (array) $wc_targets as $wc_field_key ) {
            if ( empty( $wc_field_key ) ) {
                continue;
            }
            if ( $wc_field_key === '*Cart items list*' ) {
                if ( is_string( $sanitized_value ) ) {
                    $cart_items_json = $sanitized_value;
                    $order_buffer['meta_data'][] = [ 'key' => '_jfbwqa_raw_cart_items_json', 'value' => $cart_items_json ];
                }
            } elseif ( strpos( $wc_field_key, '*JE_meta*.' ) === 0 ) {
                $meta_key = substr( $wc_field_key, strlen( '*JE_meta*.' ) );
                if ( ! empty( $meta_key ) ) {
                    $jetengine_meta_to_save[ $meta_key ] = $sanitized_value;
                }
            } elseif ( strpos( $wc_field_key, 'meta_data.' ) === 0 ) {
                $meta_key = substr( $wc_field_key, strlen( 'meta_data.' ) );
                if ( ! empty( $meta_key ) ) {
                    $order_buffer['meta_data'][] = [ 'key' => $meta_key, 'value' => $sanitized_value ];
                }
            } else {
                $parts     = explode( '.', $wc_field_key, 2 );
                $section   = strtolower( $parts[0] );
                $field_key = $parts[1] ?? '';
                if ( ( $section === 'billing' || $section === 'shipping' ) && ! empty( $field_key ) ) {
                    $order_buffer[ $section ][ $field_key ] = $sanitized_value;
                } elseif ( count( $parts ) === 1 && $section === 'customer_note' ) {
                    // Use textarea sanitizer for free-form messages so newlines survive.
                    $order_buffer['customer_note'] = sanitize_textarea_field( $jfb_field_value );
                }
            }
        }
    }

    // Process Cart Items JSON into line item descriptors.
    if ( ! empty( $cart_items_json ) ) {
        $decoded_cart = json_decode( $cart_items_json, true );
        if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded_cart ) ) {
            foreach ( $decoded_cart as $item ) {
                $product_id = absint( $item['id'] ?? 0 );
                $quantity   = absint( $item['qty'] ?? 1 );
                if ( $product_id > 0 && $quantity > 0 ) {
                    $order_buffer['line_items'][] = [ 'product_id' => $product_id, 'quantity' => $quantity ];
                }
            }
        } else {
            jfbwqa_write_log( 'ERROR: Failed decoding cart items JSON. Error: ' . json_last_error_msg() );
        }
    }

    // Validate essential data BEFORE creating the order so we don't leak
    // half-built orders into wp_wc_orders on validation failure.
    if ( empty( $order_buffer['line_items'] ) ) {
        return new WP_Error( 'jfbwqa_items_error', __( 'Cannot create estimate: No products were included.', 'jfb-wc-quotes-advanced' ) );
    }
    if ( empty( $order_buffer['billing']['email'] ) || ! is_email( $order_buffer['billing']['email'] ) ) {
        return new WP_Error( 'jfbwqa_billing_error', __( 'Cannot create estimate: Billing email is required.', 'jfb-wc-quotes-advanced' ) );
    }

    jfbwqa_write_log( 'Prepared Order Data (in-process): ' . substr( print_r( $order_buffer, true ), 0, 500 ) );

    // --- Create the order in-process ---
    $order = wc_create_order( [ 'created_via' => 'jfb-wc-quotes-advanced' ] );
    if ( is_wp_error( $order ) ) {
        $err = $order->get_error_message();
        jfbwqa_write_log( 'ERROR: wc_create_order() failed: ' . $err );
        return new WP_Error( 'jfbwqa_create_error', __( 'Error creating estimate order.', 'jfb-wc-quotes-advanced' ) . ' ' . esc_html( $err ) );
    }

    try {
        $order->set_payment_method( 'bacs' );
        $order->set_payment_method_title( __( 'Request a Quote', 'jfb-wc-quotes-advanced' ) );

        if ( ! empty( $order_buffer['billing'] ) ) {
            $order->set_address( $order_buffer['billing'], 'billing' );
        }
        if ( ! empty( $order_buffer['shipping'] ) ) {
            $order->set_address( $order_buffer['shipping'], 'shipping' );
        }

        if ( ! empty( $order_buffer['customer_note'] ) ) {
            $order->set_customer_note( $order_buffer['customer_note'] );
        }

        // Line items: resolve product, skip silently if missing/unpublished.
        // (Variations should be passed as their own product IDs from the form;
        // wc_get_product() handles both products and variations transparently.)
        foreach ( $order_buffer['line_items'] as $li ) {
            $product = wc_get_product( $li['product_id'] );
            if ( ! $product ) {
                jfbwqa_write_log( "WARNING: Skipping unknown product_id {$li['product_id']} on estimate submission." );
                continue;
            }
            $order->add_product( $product, $li['quantity'] );
        }

        // Generic order meta (mapped via meta_data.* targets).
        foreach ( $order_buffer['meta_data'] as $md ) {
            if ( ! empty( $md['key'] ) ) {
                $order->update_meta_data( $md['key'], $md['value'] );
            }
        }

        // JetEngine meta. Stored on the order via WC_Order::update_meta_data
        // which is HPOS-safe; do not call update_post_meta() here, it bypasses
        // the order data store on HPOS sites.
        foreach ( $jetengine_meta_to_save as $meta_key => $meta_value ) {
            $order->update_meta_data( $meta_key, $meta_value );
        }

        $order->calculate_totals();
        // Set status last so transitions don't fire mid-build. set_status()
        // (vs update_status()) avoids triggering "order created" transactional
        // emails; the user explicitly fires emails via the order action.
        $order->set_status( 'estimate-request', __( 'Estimate request submitted via JFB form.', 'jfb-wc-quotes-advanced' ) );
        $new_order_id = $order->save();

    } catch ( Exception $e ) {
        jfbwqa_write_log( 'ERROR: Exception while building estimate order: ' . $e->getMessage() );
        return new WP_Error( 'jfbwqa_create_error', __( 'Error creating estimate order.', 'jfb-wc-quotes-advanced' ) . ' ' . esc_html( $e->getMessage() ) );
    }

    if ( ! $new_order_id || $new_order_id < 1 ) {
        jfbwqa_write_log( 'ERROR: order->save() returned no order ID.' );
        return new WP_Error( 'jfbwqa_save_error', __( 'Order built but could not be saved.', 'jfb-wc-quotes-advanced' ) );
    }

    jfbwqa_write_log( "SUCCESS: Created WC order #{$new_order_id} via wc_create_order()." );

    // v1.28.1: Empty the user's WC cart now that the request has been
    // captured as an order. Without this, the next time the customer opens
    // the popup or visits the cart page they'll see the same items still
    // in their cart, even though they were "submitted" already.
    //
    // Guards:
    //   - WC() must be loaded (admin-ajax requests originating from a
    //     frontend page have it; backend cron-style invocations don't).
    //   - WC()->cart must be a real WC_Cart instance (it can be null in
    //     non-frontend contexts).
    //   - empty_cart( $clear_persistent_cart=true ) clears the persistent
    //     cart row for logged-in users too, so subsequent sessions don't
    //     resurrect the items.
    //
    // The browser's cart fragments are stale at this point - they'll
    // refresh on the next wc_fragment_refresh trigger, which the front-end
    // wiring (BBHQ snippet 15) fires on jet-form-builder/ajax/on-success.
    if ( function_exists( 'WC' ) && WC()->cart instanceof WC_Cart ) {
        try {
            WC()->cart->empty_cart( true );
            jfbwqa_write_log( "Cleared WC cart after estimate order #{$new_order_id} creation." );
        } catch ( Exception $e ) {
            // Non-fatal. Order is already saved; leave a note for the
            // admin so they know the cart wasn't cleared automatically.
            jfbwqa_write_log( 'WARNING: WC()->cart->empty_cart() threw after estimate order #' . $new_order_id . ': ' . $e->getMessage() );
            $order->add_order_note( __( 'Note: WC cart could not be auto-emptied after order creation. Customer may see stale cart on next visit.', 'jfb-wc-quotes-advanced' ), false, false );
        }
    } else {
        jfbwqa_write_log( "DEBUG: WC()->cart not available during submission for order #{$new_order_id}; cart not auto-emptied." );
    }

    // Optional: surface the order ID back to JFB action chain so downstream
    // actions (e.g., redirects, additional emails) can reference it.
    if ( is_array( $result ) ) {
        $result['order_id'] = $new_order_id;
    }
    return $result;
}


/* =============================================================================
   7) Custom Order Action to Trigger Estimate Email (Reads options)
   ============================================================================= */
add_filter( 'woocommerce_order_actions', 'jfbwqa_add_order_action' );
function jfbwqa_add_order_action( $actions ) {
    $actions['jfbwqa_send_estimate_email'] = __( 'Send Estimate Request Email', 'jfb-wc-quotes-advanced' );
    $actions['jfbwqa_send_prepared_quote'] = __( 'Send Prepared Quote Email', 'jfb-wc-quotes-advanced' ); // New Action
    return $actions;
}
add_action( 'woocommerce_order_action_jfbwqa_send_estimate_email', 'jfbwqa_handle_order_action' );
add_action( 'woocommerce_order_action_jfbwqa_send_prepared_quote', 'jfbwqa_handle_send_prepared_quote_action' ); // Handler now active

/**
 * Send the Prepared Quote email for the given order.
 *
 * v1.28: Signature simplified. The handler used to accept ten positional
 * args because it was double-purposed for both the WC order-action dropdown
 * AND a separate AJAX endpoint. The AJAX path was retired; this handler is
 * now only called by WC's woocommerce_order_action_jfbwqa_send_prepared_quote
 * hook, so it reads everything from order meta (saved by the Email Action
 * Composer metabox).
 *
 * Returns true on send success, false on failure (compatible with the old
 * boolean return contract).
 */
function jfbwqa_handle_send_prepared_quote_action( $order ) {
    if ( ! is_a( $order, 'WC_Order' ) ) {
        $order_id_val = absint( $order );
        $order = wc_get_order( $order_id_val );
        if ( ! $order ) {
            jfbwqa_write_log( "ERROR: Send Prepared Quote Action - Invalid order ID {$order_id_val}" );
            return false;
        }
    }
    $order_id = $order->get_id();
    jfbwqa_write_log( "Order action 'jfbwqa_send_prepared_quote' triggered for order ID: {$order_id}" );

    $options = jfbwqa_get_options();
    $over    = jfbwqa_get_email_overrides( $order, 'quote' );

    // Per-order overrides win when non-empty; settings provide the defaults.
    $subject_template = ( $over['subject']  !== '' ) ? $over['subject']  : (string) ( $options['quote_email_subject']      ?? '' );
    $heading_template = ( $over['heading']  !== '' ) ? $over['heading']  : (string) ( $options['quote_email_heading']      ?? '' );
    $body_content     = ( $over['body']     !== '' ) ? $over['body']     : (string) ( $options['quote_email_default_body'] ?? '' );
    $reply_to_email   = sanitize_email( $over['reply_to'] !== '' ? $over['reply_to'] : (string) ( $options['quote_email_reply_to'] ?? '' ) );
    $cc_email         = sanitize_email( $over['cc']       !== '' ? $over['cc']       : (string) ( $options['quote_email_cc']       ?? '' ) );

    $recipient_email = $order->get_billing_email();
    if ( ! is_email( $recipient_email ) ) {
        $error_msg = sprintf( __( 'Failed to send Prepared Quote Email for Order #%s: Invalid billing email.', 'jfb-wc-quotes-advanced' ), $order->get_order_number() );
        jfbwqa_write_log( 'ERROR (Prepared Quote): ' . str_replace( '#' . $order->get_order_number(), $order_id, $error_msg ) );
        $order->add_order_note( $error_msg, false, false );
        return false;
    }

    $base_replacements = [
        '{order_number}'        => $order->get_order_number(),
        '{site_title}'          => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
        '{customer_first_name}' => $order->get_billing_first_name(),
        '{customer_last_name}'  => $order->get_billing_last_name(),
        '{customer_name}'       => $order->get_formatted_billing_full_name(),
    ];

    $subject = str_replace( array_keys( $base_replacements ), array_values( $base_replacements ), $subject_template );
    $heading = str_replace( array_keys( $base_replacements ), array_values( $base_replacements ), $heading_template );

    // Apply wpautop to the raw body content (from override or settings).
    $body_content_with_html_breaks = wpautop( wptexturize( $body_content ) );

    // v1.28: resolve table config via the new helper - settings provide
    // the baseline; per-order override_table flips them when set.
    $quote_table_config = jfbwqa_resolve_table_config_for_send( $order, 'quote' );
    $email_body_final   = jfbwqa_replace_email_placeholders( $body_content_with_html_breaks, $order, $quote_table_config );

    jfbwqa_write_log("DEBUG: Send Prepared Quote - Final Email Body for order #{$order_id} (length: " . strlen($email_body_final) . "): " . substr($email_body_final, 0, 500) . "...");

    $template_name = 'emails/customer-estimate-request.php'; // Main email wrapper
    $default_plugin_path = jfbwqa_plugin_dir() . 'woocommerce/';

    $mailer = WC()->mailer();
    ob_start();
    $template_args = [
           'order' => $order,
           'email_heading' => $heading,
           'email_body_content' => $email_body_final, 
           'additional_content' => '', // All content is in $email_body_final now
           'sent_to_admin' => false,
           'plain_text' => false,
           'email' => $mailer,
           'show_customer_details' => false // Hide for prepared quote email
       ];
    wc_get_template( $template_name, $template_args, 'jfb-wc-quotes-advanced/', $default_plugin_path );
    $email_html_content = ob_get_clean();

    if (strpos($email_html_content, '</html>') === false) {
        $email_html_content = $mailer ? $mailer->wrap_message($heading, $email_html_content) : $email_html_content;
    }

    $site_domain = wp_parse_url(get_site_url(), PHP_URL_HOST);
    if (substr($site_domain, 0, 4) === 'www.') {
        $site_domain = substr($site_domain, 4);
    }
    $from_email_override = 'noreply@' . $site_domain;
    $from_name_override = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);

    $headers = ["Content-Type: text/html; charset=UTF-8"];
    $headers[] = "From: " . $from_name_override . " <" . $from_email_override . ">";
    if ( !empty($reply_to_email) && is_email($reply_to_email) ) $headers[] = "Reply-To: <{$reply_to_email}>";
    if ( !empty($cc_email) && is_email($cc_email) ) $headers[] = "Cc: <{$cc_email}>"; // Use $cc_email which now holds value from modal or settings

    jfbwqa_write_log("Sending Prepared Quote email to {$recipient_email} for order #{$order_id} with Subject: {$subject}");
    $sent = wp_mail( $recipient_email, $subject, $email_html_content, $headers );

    if ( $sent ) {
        $note = __( 'Prepared Quote email sent to customer.', 'jfb-wc-quotes-advanced' );
        // v1.28: order note records pricing visibility based on the resolved
        // table config (which already merged settings + per-order overrides).
        if ( ! empty( $quote_table_config['show_unit_price'] ) || ! empty( $quote_table_config['show_line_total'] ) ) {
            $note .= ' ' . __( 'Pricing was included.', 'jfb-wc-quotes-advanced' );
        } else {
            $note .= ' ' . __( 'Pricing was hidden.', 'jfb-wc-quotes-advanced' );
        }
        $order->add_order_note( $note, false, false );
        jfbwqa_write_log( "Prepared Quote email SENT successfully for order #{$order_id}." );
        // v1.28: When the action is fired via WC's order-action dropdown,
        // we also flip the order status to 'quote-sent' so the order's
        // pipeline progresses. The old AJAX handler used to do this
        // separately - now it lives here for both code paths.
        if ( $order->get_status() !== 'quote-sent' ) {
            $order->update_status( 'quote-sent', __( 'Quote email sent to customer.', 'jfb-wc-quotes-advanced' ) );
        }
    } else {
        $error_msg = sprintf( __( 'Failed sending Prepared Quote email for Order #%s via wp_mail().', 'jfb-wc-quotes-advanced' ), $order->get_order_number() );
        $order->add_order_note( $error_msg, false, false );
        jfbwqa_write_log( "ERROR: wp_mail() failed for Prepared Quote email, order #{$order_id}. Check mail server." );
        global $phpmailer; if ( isset( $phpmailer ) && ! empty( $phpmailer->ErrorInfo ) ) jfbwqa_write_log( 'PHPMailer Error (Prepared Quote): ' . $phpmailer->ErrorInfo );
        return false;
    }

    return true;
}

// Original handler for the first email (Estimate Request Confirmation).
//
// v1.28: Reads per-order overrides from the new Email Action Composer
// metabox (saved to order meta on each form save). Empty overrides fall
// back to plugin settings, so existing behavior is preserved for orders
// that don't have any overrides. The legacy "_jfbwqa_custom_email_message"
// meta key is still surfaced as the email's "Message from Admin" appendix
// when no body override is set, for back-compat with v1.27 and earlier.
function jfbwqa_handle_order_action( $order ) {
    if ( ! is_a( $order, 'WC_Order' ) ) {
        $order_id = absint( $order );
        $order = wc_get_order( $order_id );
        if ( ! $order ) { jfbwqa_write_log( "Error in order action handler: Invalid order ID {$order_id}" ); return; }
    }
    $order_id = $order->get_id();
    jfbwqa_write_log( "Order action 'jfbwqa_send_estimate_email' triggered for order ID: {$order_id}" );
    $options = jfbwqa_get_options();
    $over    = jfbwqa_get_email_overrides( $order, 'estimate' );

    // Per-order overrides win when non-empty; settings provide the defaults.
    $subject_template = ( $over['subject']  !== '' ) ? $over['subject']  : (string) ( $options['email_subject']     ?? '' );
    $heading_template = ( $over['heading']  !== '' ) ? $over['heading']  : (string) ( $options['email_heading']     ?? '' );
    $reply_to_email   = sanitize_email( $over['reply_to'] !== '' ? $over['reply_to'] : (string) ( $options['email_reply_to'] ?? '' ) );
    $cc_email         = sanitize_email( $over['cc']       !== '' ? $over['cc']       : (string) ( $options['email_cc']       ?? '' ) );
    $body_template    = ( $over['body']     !== '' ) ? $over['body']     : (string) ( $options['email_default_body'] ?? '' );

    jfbwqa_write_log( "DEBUG: jfbwqa_handle_order_action() - body source: " . ( $over['body'] !== '' ? 'per-order override' : 'settings default' ) . ' for order #' . $order_id );

    // Legacy back-compat: when the admin hasn't supplied a body override,
    // we still honor the v1.21-era "_jfbwqa_custom_email_message" meta as
    // the email's "Message from Admin" appendix. Once the admin starts
    // using the new body field, this appendix is suppressed (because the
    // override body presumably already contains whatever they want to say).
    $custom_admin_message = ( $over['body'] === '' )
        ? (string) $order->get_meta( '_jfbwqa_custom_email_message', true )
        : '';

    $recipient_email = $order->get_billing_email();
    if ( ! is_email( $recipient_email ) ) {
        $error_msg = sprintf( __( 'Failed send estimate email Order #%s: Invalid billing email.', 'jfb-wc-quotes-advanced' ), $order->get_order_number() );
        jfbwqa_write_log( 'ERROR: ' . str_replace( '#' . $order->get_order_number(), $order_id, $error_msg ) );
        $order->add_order_note( $error_msg, false, false );
        add_action( 'admin_notices', function() use ( $error_msg ) { printf( '<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html( $error_msg ) ); } );
        return;
    }

    $replacements = [ '{order_number}' => $order->get_order_number(), '{site_title}' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ];
    $subject      = str_replace( array_keys( $replacements ), array_values( $replacements ), $subject_template );
    $heading      = str_replace( array_keys( $replacements ), array_values( $replacements ), $heading_template );

    // v1.28: resolve table config via the new helper - settings provide
    // the baseline, per-order override_table flips them out when set.
    $est_table_config = jfbwqa_resolve_table_config_for_send( $order, 'estimate' );
    $email_body       = jfbwqa_replace_email_placeholders( $body_template, $order, $est_table_config );
    // *** DEBUG LOGGING START ***
    jfbwqa_write_log("DEBUG: jfbwqa_handle_order_action() - \$email_body AFTER placeholder replacement for order #{$order_id}: " . $email_body);
    // *** DEBUG LOGGING END ***


    // Get Email HTML using WC Template System
    $template_name = 'emails/customer-estimate-request.php';
    // *** CORRECTED DEFAULT PATH ***
    $default_plugin_path = jfbwqa_plugin_dir() . 'woocommerce/'; // Directory containing the 'emails' folder

    // Check if the template file physically exists at the expected plugin location
    if ( ! file_exists( $default_plugin_path . $template_name ) ) {
         $error_msg = sprintf(__('Failed send estimate email Order #%s: Email template missing at plugin path.', 'jfb-wc-quotes-advanced'), $order->get_order_number());
         jfbwqa_write_log("ERROR: Template missing at {$default_plugin_path}{$template_name}. Please check plugin files.");
         $order->add_order_note( $error_msg, false, false );
         add_action('admin_notices', function() use ($error_msg) { printf('<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html($error_msg)); });
         return;
    }

    $mailer = WC()->mailer();
    ob_start();
    // *** MODIFIED: Pass the custom message and processed default body to the template ***
    $template_args = [
           'order' => $order,
           'email_heading' => $heading,
           'email_body_content' => $email_body, // Default body with placeholders replaced
           'additional_content' => wpautop(wptexturize($custom_admin_message)), // Pass the custom message here (rename if you prefer)
           'sent_to_admin' => false,
           'plain_text' => false,
           'email' => $mailer,
           'show_customer_details' => true // Explicitly show for initial email
       ];
    // *** DEBUG LOGGING START ***
    // Log only essential parts of template_args to avoid memory issues
    $loggable_template_args = [
        'order_id' => $order->get_id(),
        'email_heading' => $heading,
        'email_body_content_length' => strlen($email_body),
        'additional_content_length' => strlen($custom_admin_message),
        'has_mailer' => ($mailer instanceof WC_Emails)
    ];
    jfbwqa_write_log("DEBUG: jfbwqa_handle_order_action() - Args for wc_get_template (order #{$order_id}): " . print_r($loggable_template_args, true));
    // *** DEBUG LOGGING END ***
    // *** Corrected wc_get_template call with the right default path ***
    wc_get_template( $template_name, $template_args, 'jfb-wc-quotes-advanced/', $default_plugin_path );

    $email_html_content = ob_get_clean();
    // *** DEBUG LOGGING START ***
    jfbwqa_write_log("DEBUG: jfbwqa_handle_order_action() - \$email_html_content (raw from template) for order #{$order_id}: " . substr($email_html_content, 0, 1000) . (strlen($email_html_content) > 1000 ? '...' : ''));
    // *** DEBUG LOGGING END ***
    // Conditionally wrap the message: only if it doesn't already seem to be a full HTML document.
    if (strpos($email_html_content, '</html>') === false) {
        $email_html_content = $mailer ? $mailer->wrap_message($heading, $email_html_content) : $email_html_content;
    }
    // *** DEBUG LOGGING START ***
    jfbwqa_write_log("DEBUG: jfbwqa_handle_order_action() - \$email_html_content (after conditional wrap) for order #{$order_id}: " . substr($email_html_content, 0, 1000) . (strlen($email_html_content) > 1000 ? '...' : ''));
    // *** DEBUG LOGGING END ***


    // Prepare Headers
    $headers = ["Content-Type: text/html; charset=UTF-8"];
    
    // Dynamically set From address to noreply@current_domain
    $site_domain = wp_parse_url(get_site_url(), PHP_URL_HOST);
    // Remove www. if it exists to keep the domain cleaner for the email, though it usually doesn't matter for the local part
    if (substr($site_domain, 0, 4) === 'www.') {
        $site_domain = substr($site_domain, 4);
    }
    $from_email_override = 'noreply@' . $site_domain;
    $from_name_override = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES); // Use Site Title as From Name

    $headers[] = "From: " . $from_name_override . " <" . $from_email_override . ">";

    // Use original Reply-To and CC from settings if they are valid
    if ( !empty($reply_to_email) && is_email($reply_to_email) ) $headers[] = "Reply-To: <{$reply_to_email}>";
    if ( !empty($cc_email) && is_email($cc_email) ) $headers[] = "Cc: <{$cc_email}>";

    // Send Email
    jfbwqa_write_log("Sending estimate email to {$recipient_email} for order #{$order_id}. Custom message included: " . (!empty($custom_admin_message) ? 'Yes' : 'No'));
    $sent = wp_mail( $recipient_email, $subject, $email_html_content, $headers );

    // Log Result
    if ( $sent ) { /* ... success note & log ... */
        // *** Optional: Clear the custom message meta after sending ***
        // update_post_meta( $order_id, '_jfbwqa_custom_email_message', '' );
        // jfbwqa_write_log("Cleared custom email message for order #{$order_id} after sending.");
        // *** End Optional ***

        $note = __('Estimate Request email sent to customer via order action.', 'jfb-wc-quotes-advanced');
        if (!empty($custom_admin_message)) {
            $note .= ' ' . __('Custom message included.', 'jfb-wc-quotes-advanced');
        }
        $order->add_order_note( $note, false, false );
        jfbwqa_write_log("Email SENT successfully for order #{$order_id}.");
        add_action('admin_notices', function() use ($order) { printf('<div class="notice notice-success is-dismissible"><p>%s</p></div>', sprintf(esc_html__('Estimate Request email sent successfully for Order #%s.', 'jfb-wc-quotes-advanced'), esc_html($order->get_order_number()))); });
    } else { /* ... failure note & log ... */
        $error_msg = sprintf(__('Failed sending estimate email Order #%s via wp_mail().', 'jfb-wc-quotes-advanced'), $order->get_order_number());
        $order->add_order_note( $error_msg, false, false );
        jfbwqa_write_log("ERROR: wp_mail() failed for order #{$order_id}. Check mail server.");
        add_action('admin_notices', function() use ($error_msg) { printf('<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html($error_msg)); });
        global $phpmailer; if ( isset($phpmailer) && !empty($phpmailer->ErrorInfo) ) jfbwqa_write_log("PHPMailer Error: " . $phpmailer->ErrorInfo);
    }
}

/* =============================================================================
   7.5) Order Details Table Renderer (v1.27)
   -----------------------------------------------------------------------------
   Builds the HTML table that replaces the [Order Details Table] placeholder
   in email bodies. Honors the table_config dict produced by either
   jfbwqa_get_table_config_from_settings() or
   jfbwqa_normalize_legacy_table_args().

   Layout:
     thead   - Image | Product | Qty [ | Unit Price ] [ | Total ]
     tbody   - line items via emails/email-order-items.php template
             - + fee rows (when show_fees)
             - + shipping rows (when show_shipping)
     tfoot   - subtotal | discount | tax | grand total
               (each gated by its own toggle; payment_method ALWAYS hidden)
   ============================================================================= */

/**
 * @param WC_Order $order
 * @param array    $config Output of jfbwqa_default_table_config() merged
 *                         with site-specific overrides.
 * @return string Full <table>...</table> HTML.
 */
function jfbwqa_render_order_details_table( $order, $config ) {
    $config     = wp_parse_args( $config, jfbwqa_default_table_config() );
    $text_align = is_rtl() ? 'right' : 'left';
    $td_styles  = 'text-align:' . esc_attr( $text_align ) . '; border: 1px solid #eee; padding: 12px;';
    $th_styles  = $td_styles;
    $th_label_styles = $th_styles . 'font-family: \'Helvetica Neue\', Helvetica, Roboto, Arial, sans-serif;';

    $html  = '<table class="td" cellspacing="0" cellpadding="6" style="width: 100%; font-family: \'Helvetica Neue\', Helvetica, Roboto, Arial, sans-serif; border: 1px solid #eee; margin-bottom: 40px;" border="1">';

    // --- THEAD --- columns depend on which price columns are enabled
    $html .= '<thead><tr>';
    if ( $config['show_image'] ) {
        $html .= '<th class="td" scope="col" style="' . $th_styles . '">' . esc_html__( 'Image', 'woocommerce' ) . '</th>';
    }
    $html .= '<th class="td" scope="col" style="' . $th_styles . '">' . esc_html__( 'Product', 'woocommerce' ) . '</th>';
    $html .= '<th class="td" scope="col" style="' . $th_styles . '">' . esc_html__( 'Quantity', 'woocommerce' ) . '</th>';
    if ( $config['show_unit_price'] ) {
        $html .= '<th class="td" scope="col" style="' . $th_styles . '">' . esc_html__( 'Unit Price', 'woocommerce' ) . '</th>';
    }
    if ( $config['show_line_total'] ) {
        $html .= '<th class="td" scope="col" style="' . $th_styles . '">' . esc_html__( 'Total', 'woocommerce' ) . '</th>';
    }
    $html .= '</tr></thead>';

    // --- TBODY --- line items via the existing template, then fee/shipping
    // rows appended below.
    $line_items_html = wc_get_template_html(
        'emails/email-order-items.php',
        [
            'order'              => $order,
            'items'              => $order->get_items( 'line_item' ),
            'show_sku'           => false,
            'show_image'         => (bool) $config['show_image'],
            'image_size'         => [ 64, 64 ],
            'plain_text'         => false,
            'sent_to_admin'      => false,
            'show_purchase_note' => false,
            // Internal flags consumed by our overridden template:
            'jfbwqa_config'      => $config,
        ],
        '',
        jfbwqa_plugin_dir() . 'woocommerce/'
    );

    $html .= '<tbody>' . $line_items_html;
    $html .= jfbwqa_render_fee_rows_html( $order, $config );
    $html .= jfbwqa_render_shipping_rows_html( $order, $config );
    $html .= '</tbody>';

    // --- TFOOT --- granular per-row gating; payment_method is ALWAYS skipped.
    $totals = $order->get_order_item_totals();
    if ( $totals ) {
        $foot_rows  = '';
        $num_cols   = ( $config['show_image'] ? 1 : 0 ) + 2  /* product + qty */
                    + ( $config['show_unit_price'] ? 1 : 0 )
                    + ( $config['show_line_total'] ? 1 : 0 );
        $label_cs   = max( 1, $num_cols - 1 );

        // Map of footer row keys -> config flag that gates them.
        $row_gates = [
            'cart_subtotal' => 'show_subtotal',
            'discount'      => 'show_discount',
            'tax'           => 'show_tax',
            'order_total'   => 'show_grand_total',
        ];

        foreach ( $totals as $key => $total_data ) {
            // Always-hide rows: payment_method (UI scaffolding, not customer info).
            if ( $key === 'payment_method' ) {
                continue;
            }
            // Shipping/fees were already rendered as table-body rows in the
            // current design, so we don't double them up in the footer.
            if ( $key === 'shipping' || $key === 'fee' || strpos( $key, 'fee_' ) === 0 ) {
                continue;
            }
            // Tax rows from get_order_item_totals look like 'tax_<id>' OR a
            // single 'tax' line - treat all as the same gate.
            $gate_key = ( strpos( $key, 'tax' ) === 0 ) ? 'show_tax' : ( $row_gates[ $key ] ?? null );
            if ( ! $gate_key || empty( $config[ $gate_key ] ) ) {
                continue;
            }
            $foot_rows .= '<tr>'
                . '<th class="td" scope="row" colspan="' . esc_attr( $label_cs ) . '" style="' . $th_label_styles . 'border-top-width: 1px;">'
                . esc_html( $total_data['label'] )
                . '</th>'
                . '<td class="td" style="' . $td_styles . 'border-top-width: 1px;">'
                . wp_kses_post( $total_data['value'] )
                . '</td>'
                . '</tr>';
        }
        if ( $foot_rows !== '' ) {
            $html .= '<tfoot>' . $foot_rows . '</tfoot>';
        }
    }

    $html .= '</table>';
    return $html;
}

/**
 * Render fee items as table-body rows (one row per fee).
 * Returns '' when show_fees is off or there are no fees.
 *
 * Row shape mirrors a line-item row: [image?] [name] [qty=1] [unit_price?] [line_total?]
 */
function jfbwqa_render_fee_rows_html( $order, $config ) {
    if ( empty( $config['show_fees'] ) ) {
        return '';
    }
    $fees = $order->get_items( 'fee' );
    if ( empty( $fees ) ) {
        return '';
    }
    $rows = '';
    foreach ( $fees as $fee ) {
        if ( ! $fee instanceof WC_Order_Item_Fee ) {
            continue;
        }
        $rows .= jfbwqa_render_synthetic_row_html(
            $fee->get_name(),
            1,
            (float) $fee->get_total(),
            $config,
            __( 'Fee', 'jfb-wc-quotes-advanced' )
        );
    }
    return $rows;
}

/**
 * Render shipping items as table-body rows (one row per shipping method).
 * Returns '' when show_shipping is off or there are no shipping items.
 */
function jfbwqa_render_shipping_rows_html( $order, $config ) {
    if ( empty( $config['show_shipping'] ) ) {
        return '';
    }
    $shipping_items = $order->get_items( 'shipping' );
    if ( empty( $shipping_items ) ) {
        return '';
    }
    $rows = '';
    foreach ( $shipping_items as $ship ) {
        if ( ! $ship instanceof WC_Order_Item_Shipping ) {
            continue;
        }
        $label = $ship->get_method_title();
        if ( $label === '' ) {
            $label = __( 'Shipping', 'jfb-wc-quotes-advanced' );
        }
        $rows .= jfbwqa_render_synthetic_row_html(
            $label,
            1,
            (float) $ship->get_total(),
            $config,
            __( 'Shipping', 'jfb-wc-quotes-advanced' )
        );
    }
    return $rows;
}

/**
 * Helper: render a single tbody row for a synthetic line (fee or shipping).
 * Honors the same column layout as line items so the table stays aligned.
 *
 * @param string $name         Display name for the Product column (e.g., "Standard Delivery").
 * @param int    $qty          Quantity (always 1 for fees / single shipping line).
 * @param float  $line_total   Amount in store currency.
 * @param array  $config       Table config (drives column visibility).
 * @param string $type_prefix  Italic prefix shown next to the name (e.g., "Shipping" or "Fee").
 * @return string HTML for one <tr>...</tr>.
 */
function jfbwqa_render_synthetic_row_html( $name, $qty, $line_total, $config, $type_prefix = '' ) {
    $text_align = is_rtl() ? 'right' : 'left';
    $cell_base  = 'text-align:' . esc_attr( $text_align ) . '; vertical-align:middle; padding:8px; font-family: \'Helvetica Neue\', Helvetica, Roboto, Arial, sans-serif; border: 1px solid #eee;';
    $unit_price = $qty > 0 ? $line_total / $qty : $line_total;

    $row = '<tr class="order_item jfbwqa-synthetic-row">';
    if ( ! empty( $config['show_image'] ) ) {
        // Empty image cell to keep column alignment with line-item rows.
        $row .= '<td class="td" style="text-align:center; vertical-align:middle; padding:8px; border:1px solid #eee; width:74px;">&nbsp;</td>';
    }
    $row .= '<td class="td" style="' . $cell_base . ' word-wrap:break-word;">';
    if ( $type_prefix !== '' ) {
        $row .= '<em style="color:#666; font-size:0.9em;">' . esc_html( $type_prefix ) . ':</em> ';
    }
    $row .= esc_html( $name );
    $row .= '</td>';
    $row .= '<td class="td" style="' . $cell_base . '">' . esc_html( (string) $qty ) . '</td>';
    if ( ! empty( $config['show_unit_price'] ) ) {
        $row .= '<td class="td" style="' . $cell_base . '">' . wp_kses_post( wc_price( $unit_price ) ) . '</td>';
    }
    if ( ! empty( $config['show_line_total'] ) ) {
        $row .= '<td class="td" style="' . $cell_base . '">' . wp_kses_post( wc_price( $line_total ) ) . '</td>';
    }
    $row .= '</tr>';
    return $row;
}

/* =============================================================================
   8) Placeholder Replacement Function (Reads options, uses mapping JSON)
   -----------------------------------------------------------------------------
   v1.27 signature: 3rd argument now accepts either:
   - An array (new): the explicit table-config dict produced by
     jfbwqa_get_table_config_from_settings() or jfbwqa_default_table_config().
     The 4th and 5th positional args are ignored when this is an array.
   - A bool/null (legacy): the old $show_prices flag. The function will
     also read $show_grand_total_with_tax (4th arg) and $display_discount
     (5th arg) and translate the trio into the new config shape via
     jfbwqa_normalize_legacy_table_args(). Existing callers that haven't
     migrated produce identical output.
   ============================================================================= */
function jfbwqa_replace_email_placeholders( $content, $order, $config_or_show_prices = false, $show_grand_total_with_tax = false, $display_discount = null ) {
    // Add this check for null content
    if ( ! is_string($content) ) {
        jfbwqa_write_log("DEBUG: jfbwqa_replace_email_placeholders() - Initial content is not a string or is null. Order ID: " . ($order instanceof WC_Order ? $order->get_id() : 'N/A') . ". Content Value: " . print_r($content, true));
        $content = ''; // Default to empty string to prevent errors with string functions
    }

    if ( ! is_a( $order, 'WC_Order' ) ) { jfbwqa_write_log("Placeholder Error: Invalid WC_Order object."); return $content; }
    $order_id_for_log = $order->get_id(); // For logging
    jfbwqa_write_log("DEBUG: jfbwqa_replace_email_placeholders() - START for order #{$order_id_for_log}. Initial content length: " . strlen($content));
    if (strlen($content) < 500) { // Log short content fully
        jfbwqa_write_log("DEBUG: jfbwqa_replace_email_placeholders() - Initial content (short): " . $content);
    }

    // Resolve the table config - either passed directly (new style) or
    // translated from the legacy positional args.
    if ( is_array( $config_or_show_prices ) ) {
        $table_config = wp_parse_args( $config_or_show_prices, jfbwqa_default_table_config() );
    } else {
        $table_config = jfbwqa_normalize_legacy_table_args(
            $config_or_show_prices,
            $show_grand_total_with_tax,
            $display_discount
        );
    }
    jfbwqa_write_log( 'DEBUG: jfbwqa_replace_email_placeholders() - Resolved table config: ' . wp_json_encode( $table_config ) );

    $options = jfbwqa_get_options(); $mapping = jfbwqa_read_mapping();

    // Basic Placeholders
    $content = str_replace('{order_number}', $order->get_order_number(), $content);
    $content = str_replace('{order_date}', wc_format_datetime( $order->get_date_created() ), $content);
    $content = str_replace('{customer_name}', $order->get_formatted_billing_full_name(), $content);
    $content = str_replace('{customer_first_name}', $order->get_billing_first_name(), $content);
    $content = str_replace('{customer_last_name}', $order->get_billing_last_name(), $content);
    $content = str_replace('{billing_email}', $order->get_billing_email(), $content);
    $content = str_replace('{billing_phone}', $order->get_billing_phone(), $content);
    $content = str_replace('{site_title}', get_bloginfo('name'), $content);
    $content = str_replace('{site_url}', site_url(), $content);
    jfbwqa_write_log("DEBUG: jfbwqa_replace_email_placeholders() - Content after basic replacements for order #{$order_id_for_log} (length: " . strlen($content) . ")");


    // Special Placeholder: Order Items Table
    $order_details_table_placeholder = '[Order Details Table]';
    if ( strpos( $content, $order_details_table_placeholder ) !== false ) {
        jfbwqa_write_log( "DEBUG: Found '{$order_details_table_placeholder}' for order #{$order_id_for_log}. Building table from config." );
        $full_table_html = jfbwqa_render_order_details_table( $order, $table_config );
        $content         = str_replace( $order_details_table_placeholder, $full_table_html, $content );
        jfbwqa_write_log( "DEBUG: Replaced '{$order_details_table_placeholder}' with rendered table (" . strlen( $full_table_html ) . ' chars) for order #' . $order_id_for_log );
    } else {
        jfbwqa_write_log( "DEBUG: jfbwqa_replace_email_placeholders() - Did NOT find '{$order_details_table_placeholder}' in content for order #{$order_id_for_log}." );
    }

    // Advanced Placeholders: {[field_name]}
    if ( preg_match_all('/{\[(.*?)]}/', $content, $matches) ) {
        jfbwqa_write_log("DEBUG: jfbwqa_replace_email_placeholders() - Found advanced placeholders for order #{$order_id_for_log}: " . implode(', ', $matches[1]));
        $placeholders = array_unique($matches[1]);
        $je_meta_keys = !empty($options['jetengine_keys']) ? preg_split('/\r\n|\r|\n/', trim($options['jetengine_keys'])) : [];
        $je_meta_keys = array_map('trim', $je_meta_keys);

        foreach ( $placeholders as $placeholder_key ) {
            $value = ''; $found = false;
            jfbwqa_write_log("DEBUG: jfbwqa_replace_email_placeholders() - Processing advanced placeholder '{[{$placeholder_key}]}' for order #{$order_id_for_log}.");
            // Lookup logic: JE Meta -> Mapped Fields -> Direct Order Methods -> Generic Meta (same as v1.14)
             // Priority 1: JE meta key from settings
            if ( in_array( $placeholder_key, $je_meta_keys ) ) {
                $meta_value = $order->get_meta( $placeholder_key, true );
                if ( ! empty( $meta_value ) ) { $value = is_array($meta_value) || is_object($meta_value) ? wp_json_encode($meta_value) : $meta_value; $found = true; }
                jfbwqa_write_log("DEBUG: jfbwqa_replace_email_placeholders() - Placeholder '{[{$placeholder_key}]}' (JE Meta Check): Found = " . ($found ? 'Yes' : 'No') . ", Value = " . $value);
            }
            // Priority 2: JFB Field ID mapped to WC field/meta
            if ( ! $found && isset( $mapping[$placeholder_key] ) ) {
                 jfbwqa_write_log("DEBUG: jfbwqa_replace_email_placeholders() - Placeholder '{[{$placeholder_key}]}' (Mapping Check): Found in mapping. Targets: " . print_r($mapping[$placeholder_key], true));
                 foreach ((array) $mapping[$placeholder_key] as $mapped_wc_field) {
                     if ( empty($mapped_wc_field) || $mapped_wc_field === '*Cart items list*' ) continue;
                     if ( strpos($mapped_wc_field, '*JE_meta*.') === 0 ) {
                         $meta_key = substr($mapped_wc_field, strlen('*JE_meta*.')); $meta_value = $order->get_meta( $meta_key, true );
                         if ( ! empty( $meta_value ) ) { $value = is_array($meta_value) || is_object($meta_value) ? wp_json_encode($meta_value) : $meta_value; $found = true; break; }
                     } elseif ( strpos($mapped_wc_field, 'meta_data.') === 0 ) {
                         $meta_key = substr($mapped_wc_field, strlen('meta_data.')); $meta_value = $order->get_meta( $meta_key, true );
                         if ( ! empty( $meta_value ) ) { $value = is_array($meta_value) || is_object($meta_value) ? wp_json_encode($meta_value) : $meta_value; $found = true; break; }
                     } else {
                         $parts = explode('.', $mapped_wc_field, 2); $section = strtolower($parts[0]); $field_key = $parts[1] ?? '';
                         if ( ($section === 'billing' || $section === 'shipping') && !empty($field_key) ) {
                             $method_name = 'get_' . $section . '_' . $field_key;
                             if ( method_exists( $order, $method_name ) ) { $value = $order->$method_name(); $found = true; break; }
                         } elseif ( count($parts) === 1 && $section === 'customer_note' ) { $value = $order->get_customer_note(); $found = true; break; }
                     }
                 }
                 jfbwqa_write_log("DEBUG: jfbwqa_replace_email_placeholders() - Placeholder '{[{$placeholder_key}]}' (Mapping Result): Found = " . ($found ? 'Yes' : 'No') . ", Value = " . $value);
            }
            // Priority 3: Direct method/property or generic meta
            if ( ! $found ) {
                $direct_method = 'get_' . $placeholder_key;
                if ( method_exists( $order, $direct_method ) ) { $value = $order->$direct_method(); $found = true; }
                elseif ( $order->get_meta( $placeholder_key ) ) { $value = $order->get_meta( $placeholder_key, true ); $found = true; }
                jfbwqa_write_log("DEBUG: jfbwqa_replace_email_placeholders() - Placeholder '{[{$placeholder_key}]}' (Direct/Generic Meta Check): Found = " . ($found ? 'Yes' : 'No') . ", Value = " . $value);
            }
            // Replace placeholder
            $replacement_value = $found ? wp_kses_post($value) : ''; // Sanitize output
            $content = str_replace('{[' . $placeholder_key . ']}', $replacement_value, $content);
            if (!$found) jfbwqa_write_log("Placeholder Warning: Could not find value for '{[{$placeholder_key}]}' in email content for order #{$order_id_for_log}.");
        }
    }
    jfbwqa_write_log("DEBUG: jfbwqa_replace_email_placeholders() - END for order #{$order_id_for_log}. Final content: " . $content);
    return $content;
}


/* =============================================================================
   9) Admin Settings Page (Settings API for General + Manual Mapping UI)
   ============================================================================= */

// --- Add Menu Item ---
add_action( 'admin_menu', 'jfbwqa_add_admin_menu' );
function jfbwqa_add_admin_menu() {
    add_options_page(
        __('JFB WC Quotes Advanced Settings', 'jfb-wc-quotes-advanced'),
        __('JFB WC Quotes', 'jfb-wc-quotes-advanced'), // Shorter menu title
        'manage_options',
        JFBWQA_SETTINGS_SLUG,
        'jfbwqa_render_settings_page'
    );
}

// --- Register Settings API Fields for General Settings ---
add_action( 'admin_init', 'jfbwqa_settings_init' );
function jfbwqa_settings_init() {
    // Register the single option array for general settings
    register_setting(
        'jfbwqa_settings_group',      // Group name for settings_fields()
        JFBWQA_OPTION_NAME,           // Option name in db
        'jfbwqa_sanitize_options'     // Sanitization callback
    );

    // General Settings Section
    add_settings_section('jfbwqa_section_general', __('General Settings', 'jfb-wc-quotes-advanced'), 'jfbwqa_render_section_general_desc', JFBWQA_SETTINGS_SLUG);
    add_settings_field( 'hook_name', __('JetFormBuilder Hook Name', 'jfb-wc-quotes-advanced'), 'jfbwqa_render_field_text', JFBWQA_SETTINGS_SLUG, 'jfbwqa_section_general', ['key' => 'hook_name', 'type' => 'text', 'desc' => __('Custom filter hook used in JFB form.', 'jfb-wc-quotes-advanced')] );
    add_settings_field( 'shortcode_name', __('Cart JSON Shortcode Tag', 'jfb-wc-quotes-advanced'), 'jfbwqa_render_field_text', JFBWQA_SETTINGS_SLUG, 'jfbwqa_section_general', ['key' => 'shortcode_name', 'type' => 'text', 'desc' => sprintf(__('Tag for shortcode like %s.', 'jfb-wc-quotes-advanced'), '<code>[your_tag_here]</code>')] );
    add_settings_field( 'jetengine_keys', __('JetEngine Meta Keys (for mapping/placeholders)', 'jfb-wc-quotes-advanced'), 'jfbwqa_render_field_je_keys_textarea', JFBWQA_SETTINGS_SLUG, 'jfbwqa_section_general', ['key' => 'jetengine_keys', 'desc' => __('One key per line. Makes them available as *JE_meta*.key_name in mapping dropdowns and {[key_name]} in emails.', 'jfb-wc-quotes-advanced')] );
    add_settings_field( 'enable_debug', __('Enable Debug Logging', 'jfb-wc-quotes-advanced'), 'jfbwqa_render_field_checkbox', JFBWQA_SETTINGS_SLUG, 'jfbwqa_section_general', ['key' => 'enable_debug', 'desc' => sprintf(__('Log to %s.', 'jfb-wc-quotes-advanced'), '<code>' . esc_html(trailingslashit(jfbwqa_plugin_dir()) . 'debug/debug.log') . '</code>')] );

    // Email Settings Section
     add_settings_section('jfbwqa_section_email', __('Estimate Email Settings', 'jfb-wc-quotes-advanced'), 'jfbwqa_render_section_email_desc', JFBWQA_SETTINGS_SLUG);
    add_settings_field( 'email_subject', __('Email Subject', 'jfb-wc-quotes-advanced'), 'jfbwqa_render_field_text', JFBWQA_SETTINGS_SLUG, 'jfbwqa_section_email', ['key' => 'email_subject', 'type' => 'text'] );
    add_settings_field( 'email_heading', __('Email Heading', 'jfb-wc-quotes-advanced'), 'jfbwqa_render_field_text', JFBWQA_SETTINGS_SLUG, 'jfbwqa_section_email', ['key' => 'email_heading', 'type' => 'text'] );
    add_settings_field( 'email_reply_to', __('Reply-To Email', 'jfb-wc-quotes-advanced'), 'jfbwqa_render_field_text', JFBWQA_SETTINGS_SLUG, 'jfbwqa_section_email', ['key' => 'email_reply_to', 'type' => 'email'] );
    add_settings_field( 'email_cc', __('CC Email', 'jfb-wc-quotes-advanced'), 'jfbwqa_render_field_text', JFBWQA_SETTINGS_SLUG, 'jfbwqa_section_email', ['key' => 'email_cc', 'type' => 'email'] );
    add_settings_field( 'email_default_body', __('Email Body Template', 'jfb-wc-quotes-advanced'), 'jfbwqa_render_field_wp_editor', JFBWQA_SETTINGS_SLUG, 'jfbwqa_section_email', ['key' => 'email_default_body'] ); // Desc rendered in section callback

    // Quote Email Settings Section
    add_settings_section(
        'jfbwqa_section_quote_email',
        __('Prepared Quote Email Settings', 'jfb-wc-quotes-advanced'),
        'jfbwqa_render_section_quote_email_desc',
        JFBWQA_SETTINGS_SLUG
    );
    add_settings_field( 'quote_email_subject', __('Quote Email Subject', 'jfb-wc-quotes-advanced'), 'jfbwqa_render_field_text', JFBWQA_SETTINGS_SLUG, 'jfbwqa_section_quote_email', ['key' => 'quote_email_subject', 'type' => 'text', 'placeholder' => 'Your Quote #{order_number} is Ready'] );
    add_settings_field( 'quote_email_heading', __('Quote Email Heading', 'jfb-wc-quotes-advanced'), 'jfbwqa_render_field_text', JFBWQA_SETTINGS_SLUG, 'jfbwqa_section_quote_email', ['key' => 'quote_email_heading', 'type' => 'text', 'placeholder' => 'Your Prepared Quote'] );
    add_settings_field( 'quote_email_reply_to', __('Quote Email Reply-To', 'jfb-wc-quotes-advanced'), 'jfbwqa_render_field_text', JFBWQA_SETTINGS_SLUG, 'jfbwqa_section_quote_email', ['key' => 'quote_email_reply_to', 'type' => 'email', 'placeholder' => get_option('admin_email')] );
    add_settings_field( 'quote_email_cc', __('Quote Email CC', 'jfb-wc-quotes-advanced'), 'jfbwqa_render_field_text', JFBWQA_SETTINGS_SLUG, 'jfbwqa_section_quote_email', ['key' => 'quote_email_cc', 'type' => 'email', 'placeholder' => 'e.g., sales@example.com'] );
    add_settings_field( 'quote_email_default_body', __('Quote Email Default Body', 'jfb-wc-quotes-advanced'), 'jfbwqa_render_field_wp_editor', JFBWQA_SETTINGS_SLUG, 'jfbwqa_section_quote_email', ['key' => 'quote_email_default_body'] );
    add_settings_field( 'display_discount_in_quote', __('Display Discount Row in Quote', 'jfb-wc-quotes-advanced'), 'jfbwqa_render_field_checkbox', JFBWQA_SETTINGS_SLUG, 'jfbwqa_section_quote_email', ['key' => 'display_discount_in_quote', 'desc' => __('[Deprecated v1.27] Use the dedicated "Show discount row" checkbox in the new Order Details Table sections below.', 'jfb-wc-quotes-advanced')] );

    /* -------------------------------------------------------------------
     * v1.27: Order Details Table sections - one per email type.
     * Each section exposes the same 9 toggles that gate what shows in
     * the rendered table when [Order Details Table] is in the body.
     * Modal-based per-send overrides (prepared quote) layer on top of
     * the quote-side defaults; the estimate side has no modal yet
     * (coming in v1.28) so its settings are the only knobs.
     * ------------------------------------------------------------------- */
    $table_field_specs = [
        'show_image'       => [ __( 'Show product images',           'jfb-wc-quotes-advanced' ) ],
        'show_unit_price'  => [ __( 'Show unit price column',        'jfb-wc-quotes-advanced' ) ],
        'show_line_total'  => [ __( 'Show line total column',        'jfb-wc-quotes-advanced' ) ],
        'show_subtotal'    => [ __( 'Show subtotal row in footer',   'jfb-wc-quotes-advanced' ) ],
        'show_shipping'    => [ __( 'Show shipping row(s) as table rows',  'jfb-wc-quotes-advanced' ) ],
        'show_fees'        => [ __( 'Show fee row(s) as table rows',       'jfb-wc-quotes-advanced' ) ],
        'show_discount'    => [ __( 'Show discount row in footer',   'jfb-wc-quotes-advanced' ) ],
        'show_tax'         => [ __( 'Show tax row in footer',        'jfb-wc-quotes-advanced' ) ],
        'show_grand_total' => [ __( 'Show grand total row in footer','jfb-wc-quotes-advanced' ) ],
    ];

    // Estimate Request - Order Details Table
    add_settings_section(
        'jfbwqa_section_est_table',
        __( 'Estimate Request Email - Order Details Table', 'jfb-wc-quotes-advanced' ),
        'jfbwqa_render_section_est_table_desc',
        JFBWQA_SETTINGS_SLUG
    );
    foreach ( $table_field_specs as $suffix => $spec ) {
        $opt_key = 'est_table_' . $suffix;
        add_settings_field(
            $opt_key,
            $spec[0],
            'jfbwqa_render_field_checkbox',
            JFBWQA_SETTINGS_SLUG,
            'jfbwqa_section_est_table',
            [ 'key' => $opt_key ]
        );
    }

    // Prepared Quote - Order Details Table
    add_settings_section(
        'jfbwqa_section_quote_table',
        __( 'Prepared Quote Email - Order Details Table', 'jfb-wc-quotes-advanced' ),
        'jfbwqa_render_section_quote_table_desc',
        JFBWQA_SETTINGS_SLUG
    );
    foreach ( $table_field_specs as $suffix => $spec ) {
        $opt_key = 'quote_table_' . $suffix;
        add_settings_field(
            $opt_key,
            $spec[0],
            'jfbwqa_render_field_checkbox',
            JFBWQA_SETTINGS_SLUG,
            'jfbwqa_section_quote_table',
            [ 'key' => $opt_key ]
        );
    }

    // Email Deliverability Section
    add_settings_section(
        'jfbwqa_section_deliverability',
        __('Email Deliverability (DNS Setup)', 'jfb-wc-quotes-advanced'),
        'jfbwqa_render_section_deliverability_desc',
        JFBWQA_SETTINGS_SLUG
    );
    add_settings_field(
        'deliverability_info',
        __('Improving Email Delivery', 'jfb-wc-quotes-advanced'),
        'jfbwqa_render_field_deliverability_info',
        JFBWQA_SETTINGS_SLUG,
        'jfbwqa_section_deliverability'
    );
}
function jfbwqa_render_section_general_desc() {
    echo '<p>' . esc_html__( 'Configure how this plugin integrates with JetFormBuilder. As of v1.25, orders are created in-process via wc_create_order(); no WooCommerce REST API credentials are required.', 'jfb-wc-quotes-advanced' ) . '</p>';
}
function jfbwqa_render_section_email_desc() {
     echo '<p>' . esc_html__('Customize the email sent via the order action for the initial estimate request confirmation.', 'jfb-wc-quotes-advanced') . '</p>';
     // Add placeholders list here if desired (as in v1.14 render_section_email)
}
function jfbwqa_render_section_quote_email_desc() {
    echo '<p>' . esc_html__('Customize the default content for the email sent when a quote is prepared and sent to the customer (typically from the order edit screen).', 'jfb-wc-quotes-advanced') . '</p>';
    echo '<p>' . esc_html__('Placeholders like {order_number}, {[your_jetengine_field]}, and [Order Details Table] can be used. The actual message sent can be further customized on the order edit page.', 'jfb-wc-quotes-advanced') . '</p>';
}
function jfbwqa_render_section_est_table_desc() {
    echo '<p>' . esc_html__( 'These checkboxes control what appears in the [Order Details Table] placeholder when the estimate-request acknowledgement email is sent. The acknowledgement email is what the customer sees right after submitting the request form, before you have prepared a real quote.', 'jfb-wc-quotes-advanced' ) . '</p>';
    echo '<p>' . esc_html__( 'Recommended: leave most rows OFF for the acknowledgement email; you will surface pricing in the prepared-quote email instead.', 'jfb-wc-quotes-advanced' ) . '</p>';
}
function jfbwqa_render_section_quote_table_desc() {
    echo '<p>' . esc_html__( 'These checkboxes control what appears in the [Order Details Table] placeholder when the prepared-quote email is sent. They serve as defaults; the per-send modal on the order edit screen can override them on a per-quote basis.', 'jfb-wc-quotes-advanced' ) . '</p>';
    echo '<p>' . esc_html__( 'Recommended: enable line totals, subtotal, shipping, fees, and grand total. Discount and tax can be enabled if your store applies them.', 'jfb-wc-quotes-advanced' ) . '</p>';
}
function jfbwqa_render_section_deliverability_desc() {
    echo '<p>' . esc_html__('To significantly improve the chances of your estimate emails reaching the inbox and not being marked as spam, it is highly recommended to configure certain DNS records for your domain (the domain emails are sent from, e.g., luxeandpetals.com). This plugin now sends emails from "noreply@yourdomain.com".', 'jfb-wc-quotes-advanced') . '</p>';
}

function jfbwqa_render_field_deliverability_info() {
    $site_domain = wp_parse_url(get_site_url(), PHP_URL_HOST);
    if (substr($site_domain, 0, 4) === 'www.') {
        $site_domain = substr($site_domain, 4);
    }
    $from_address_example = 'noreply@' . $site_domain;

    echo '<p>';
    echo '<strong>' . esc_html__('Emails sent by this plugin will use the "From" address:', 'jfb-wc-quotes-advanced') . '</strong> <code>' . esc_html($from_address_example) . '</code><br>';
    echo esc_html__('Your "Reply-To" address from the settings above will still be used if set.', 'jfb-wc-quotes-advanced');
    echo '</p>';

    echo '<h4>' . esc_html__('Key DNS Records:', 'jfb-wc-quotes-advanced') . '</h4>';
    echo '<ol>';
    echo '<li><strong>' . esc_html__('SPF (Sender Policy Framework):', 'jfb-wc-quotes-advanced') . '</strong> ' . esc_html__('An SPF record lists all the servers authorized to send emails on behalf of your domain. If your web host sends emails for you (e.g., via PHP mail), their IP addresses or includes must be in your SPF record. If you use a third-party email service (like SendGrid, Mailgun, Google Workspace), they will provide the SPF details to add.', 'jfb-wc-quotes-advanced') . '<br>';
    echo '<em>' . esc_html__('Example:', 'jfb-wc-quotes-advanced') . '</em> <code>v=spf1 include:mail.yourhost.com include:_spf.google.com ~all</code></li>';

    echo '<li><strong>' . esc_html__('DKIM (DomainKeys Identified Mail):', 'jfb-wc-quotes-advanced') . '</strong> ' . esc_html__('DKIM adds a digital signature to your emails, allowing receiving servers to verify that the email was actually sent by an authorized server and hasn\'t been tampered with. Your email sending service or hosting provider will typically provide a DKIM key (a long string of text) to add as a TXT record in your DNS.', 'jfb-wc-quotes-advanced') . '<br>';
    echo '<em>' . esc_html__('Example (selector and key vary):', 'jfb-wc-quotes-advanced') . '</em> <code>selector._domainkey.' . esc_html($site_domain) . ' IN TXT "v=DKIM1; k=rsa; p=MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQ..."</code></li>';

    echo '<li><strong>' . esc_html__('DMARC (Domain-based Message Authentication, Reporting & Conformance):', 'jfb-wc-quotes-advanced') . '</strong> ' . esc_html__('A DMARC record tells receiving mail servers what to do if an email claims to be from your domain but fails SPF or DKIM checks (e.g., reject it, quarantine it, or do nothing). It also allows you to receive reports on email activity. Start with a monitoring policy (p=none).', 'jfb-wc-quotes-advanced') . '<br>';
    echo '<em>' . esc_html__('Example (for monitoring):', 'jfb-wc-quotes-advanced') . '</em> <code>_dmarc.' . esc_html($site_domain) . ' IN TXT "v=DMARC1; p=none; rua=mailto:dmarcreports@' . esc_html($site_domain) . '"</code></li>';
    echo '</ol>';

    echo '<p><strong>' . esc_html__('Where to Add These Records:', 'jfb-wc-quotes-advanced') . '</strong> ' . esc_html__('You typically add these TXT records in the DNS management zone for your domain, which is usually provided by your domain registrar (e.g., GoDaddy, Namecheap) or your hosting provider if they also manage your DNS.', 'jfb-wc-quotes-advanced') . '</p>';
    echo '<p><strong>' . esc_html__('Using a Transactional Email Service:', 'jfb-wc-quotes-advanced') . '</strong> ' . esc_html__('For best results, consider using a dedicated transactional email service (e.g., SendGrid, Mailgun, Postmark, Amazon SES) via a WordPress SMTP plugin. These services specialize in email deliverability and provide clear instructions for SPF/DKIM setup.', 'jfb-wc-quotes-advanced') . '</p>';
    echo '<p><small>' . esc_html__('Note: DNS changes can take some time to propagate (up to 48 hours, but often much faster). After setting up these records, use online tools to verify their correctness.', 'jfb-wc-quotes-advanced') . '</small></p>';
}

// --- Field Rendering Callbacks (Slightly simplified from v1.14) ---
function jfbwqa_render_field_text( $args ) {
    $options = jfbwqa_get_options(); $key = $args['key']; $type = $args['type'] ?? 'text';
    printf('<input type="%s" id="%s" name="%s[%s]" value="%s" class="regular-text" />', esc_attr($type), esc_attr($key), esc_attr(JFBWQA_OPTION_NAME), esc_attr($key), esc_attr($options[$key] ?? ''));
    if (isset($args['desc'])) printf('<p class="description">%s</p>', wp_kses_post($args['desc']));
}
function jfbwqa_render_field_textarea( $args ) {
    $options = jfbwqa_get_options(); $key = $args['key'];
    printf('<textarea id="%s" name="%s[%s]" rows="5" class="large-text">%s</textarea>', esc_attr($key), esc_attr(JFBWQA_OPTION_NAME), esc_attr($key), esc_textarea($options[$key] ?? ''));
    if (isset($args['desc'])) printf('<p class="description">%s</p>', wp_kses_post($args['desc']));
}

/**
 * Specialized renderer for the JetEngine Meta Keys textarea.
 *
 * Adds an "Auto-derive from current mapping" button that scans
 * field-mapping.json for any *JE_meta*.<key> targets and offers
 * a one-click merge into the textarea. The merge is client-side
 * only; nothing is persisted until the user clicks "Save All Settings".
 *
 * Rationale: the textarea field's contents drive (a) which keys
 * appear as *JE_meta*.<key> options in the mapping dropdown and
 * (b) the email placeholder priority list. If a key is mapped but
 * not listed here, it still works at submit-time (the mapping is
 * the source of truth for routing the form value), but rebuilding
 * the mapping table or expanding {[key]} placeholders is slower /
 * less convenient. This button keeps the two in sync without
 * forcing the admin to retype keys they already used in the mapping.
 */
function jfbwqa_render_field_je_keys_textarea( $args ) {
    $options = jfbwqa_get_options();
    $key     = $args['key'];
    $value   = $options[ $key ] ?? '';

    // Compute derived keys server-side at render time, no AJAX needed.
    $mapping = jfbwqa_read_mapping();
    $derived = [];
    foreach ( (array) $mapping as $form_field => $targets ) {
        foreach ( (array) $targets as $target ) {
            if ( is_string( $target ) && strpos( $target, '*JE_meta*.' ) === 0 ) {
                $key_name = substr( $target, strlen( '*JE_meta*.' ) );
                if ( $key_name !== '' ) {
                    $derived[] = $key_name;
                }
            }
        }
    }
    $derived = array_values( array_unique( $derived ) );
    sort( $derived );

    printf(
        '<textarea id="%s" name="%s[%s]" rows="5" class="large-text">%s</textarea>',
        esc_attr( $key ),
        esc_attr( JFBWQA_OPTION_NAME ),
        esc_attr( $key ),
        esc_textarea( $value )
    );

    if ( ! empty( $derived ) ) {
        $count    = count( $derived );
        $hint_msg = sprintf(
            /* translators: %d: number of JE keys derived from the field mapping */
            _n(
                'Found %d JetEngine meta key in your field mapping.',
                'Found %d JetEngine meta keys in your field mapping.',
                $count,
                'jfb-wc-quotes-advanced'
            ),
            $count
        );

        printf(
            '<p style="margin-top:8px;"><button type="button" class="button" id="jfbwqa-derive-je-keys" data-derived="%s">%s</button> <span style="font-size:0.9em; color:#666;">%s</span></p>',
            esc_attr( wp_json_encode( $derived ) ),
            esc_html__( 'Auto-derive from current mapping', 'jfb-wc-quotes-advanced' ),
            esc_html( $hint_msg )
        );

        $added_label = esc_js( __( 'Added — remember to click "Save All Settings"', 'jfb-wc-quotes-advanced' ) );
        ?>
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            var btn = document.getElementById('jfbwqa-derive-je-keys');
            var ta  = document.getElementById('jetengine_keys');
            if (!btn || !ta) { return; }
            btn.addEventListener('click', function () {
                var derived = [];
                try { derived = JSON.parse(btn.dataset.derived || '[]'); } catch (e) {}
                if (!derived.length) { return; }
                var existing = ta.value
                    .split(/\r?\n/)
                    .map(function (s) { return s.trim(); })
                    .filter(Boolean);
                var seen   = Object.create(null);
                var merged = [];
                existing.concat(derived).forEach(function (k) {
                    if (!seen[k]) { seen[k] = true; merged.push(k); }
                });
                ta.value = merged.join('\n');
                ta.focus();
                btn.disabled    = true;
                btn.textContent = '<?php echo $added_label; ?>';
            });
        });
        </script>
        <?php
    } else {
        echo '<p style="margin-top:8px;"><em>' . esc_html__( 'No *JE_meta*.* targets found in field-mapping.json yet. Map some fields first, then this button will let you populate the textarea in one click.', 'jfb-wc-quotes-advanced' ) . '</em></p>';
    }

    if ( isset( $args['desc'] ) ) {
        printf( '<p class="description">%s</p>', wp_kses_post( $args['desc'] ) );
    }
}
function jfbwqa_render_field_checkbox( $args ) {
    $options = jfbwqa_get_options(); $key = $args['key']; $checked = checked($options[$key] ?? false, true, false);
    printf('<input type="checkbox" id="%s" name="%s[%s]" value="1" %s />', esc_attr($key), esc_attr(JFBWQA_OPTION_NAME), esc_attr($key), $checked);
    if (isset($args['desc'])) printf(' <label for="%s"><span class="description">%s</span></label>', esc_attr($key), wp_kses_post($args['desc']));
}
function jfbwqa_render_field_wp_editor( $args ) {
    $options = jfbwqa_get_options(); $key = $args['key']; $value = $options[$key] ?? '';
    wp_editor($value, esc_attr($key), ['textarea_name' => sprintf('%s[%s]', JFBWQA_OPTION_NAME, $key), 'textarea_rows' => 10, 'media_buttons' => false, 'teeny' => true, 'quicktags' => true]);
    if (isset($args['desc'])) printf('<p class="description">%s</p>', wp_kses_post($args['desc']));
}

// --- Sanitization Callback for General Settings ---
function jfbwqa_sanitize_options( $input ) {
    $output = [];
    // NOTE (v1.25): consumer_key/consumer_secret are no longer sanitized here.
    // The fields were removed from the settings UI when the REST self-loopback
    // was retired in favor of in-process wc_create_order(). Any legacy values
    // still present in wp_options are surfaced by jfbwqa_legacy_credentials_notice
    // and can be safely deleted from there.
    $output['hook_name']       = sanitize_key($input['hook_name'] ?? 'my_jfb_wc_estimate_form');
    $output['shortcode_name']  = sanitize_key($input['shortcode_name'] ?? 'my_cart_json');
    $output['jetengine_keys']  = sanitize_textarea_field($input['jetengine_keys'] ?? '');
    $output['enable_debug']    = isset($input['enable_debug']) ? filter_var($input['enable_debug'], FILTER_VALIDATE_BOOLEAN) : false;
    $output['email_subject']   = sanitize_text_field($input['email_subject'] ?? '');
    $output['email_heading']   = sanitize_text_field($input['email_heading'] ?? '');
    $output['email_reply_to']  = sanitize_email($input['email_reply_to'] ?? '');
    $output['email_cc']        = sanitize_email($input['email_cc'] ?? '');
    if (isset($input['email_default_body'])) $output['email_default_body'] = wp_kses_post(wp_unslash($input['email_default_body']));

    // Sanitize new quote email fields
    $output['quote_email_subject'] = sanitize_text_field($input['quote_email_subject'] ?? '');
    $output['quote_email_heading'] = sanitize_text_field($input['quote_email_heading'] ?? '');
    $output['quote_email_reply_to'] = sanitize_email($input['quote_email_reply_to'] ?? '');
    $output['quote_email_cc'] = sanitize_email($input['quote_email_cc'] ?? '');
    if (isset($input['quote_email_default_body'])) $output['quote_email_default_body'] = wp_kses_post(wp_unslash($input['quote_email_default_body']));
    $output['display_discount_in_quote'] = isset($input['display_discount_in_quote']) ? true : false;

    // v1.27: Per-email-type Order Details Table toggles.
    // All 18 keys (9 estimate + 9 quote) are simple checkboxes; if not
    // posted, the field was unchecked - store false. We don't fall back
    // to the existing DB value here because the sanitize callback receives
    // the full POST array, and an unchecked checkbox means "off".
    foreach ( [ 'est_table_', 'quote_table_' ] as $prefix ) {
        foreach ( [ 'show_image', 'show_unit_price', 'show_line_total', 'show_subtotal',
                   'show_shipping', 'show_fees', 'show_discount', 'show_tax', 'show_grand_total' ] as $suffix ) {
            $key            = $prefix . $suffix;
            $output[ $key ] = isset( $input[ $key ] ) ? (bool) $input[ $key ] : false;
        }
    }

    jfbwqa_write_log("General plugin settings sanitized.");
    // NOTE: Mapping is saved separately, not via this callback.
    return $output;
}

// --- Render the Main Settings Page ---
function jfbwqa_render_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    // --- Handle File Upload and Mapping Save BEFORE page output ---
    $mapping_message = ''; // Message specific to mapping actions
    $file_upload_message = '';

    // Check if the main form was submitted (saving general settings AND potentially mapping)
    if ( isset( $_POST['option_page'] ) && $_POST['option_page'] == 'jfbwqa_settings_group' ) {
        // Nonce verified by WP `options.php` before calling sanitize callback

        // Handle File Upload (if submitted with the main form)
        if ( isset($_FILES['jfbwqa_jetform_json']) && !empty($_FILES['jfbwqa_jetform_json']['name']) && $_FILES['jfbwqa_jetform_json']['error'] === UPLOAD_ERR_OK ) {
             if ( $_FILES['jfbwqa_jetform_json']['type'] === 'application/json' ) {
                 $tmp_name = $_FILES['jfbwqa_jetform_json']['tmp_name'];
                 $destination = jfbwqa_jetform_path();
                 if ( move_uploaded_file($tmp_name, $destination) ) {
                     $file_upload_message = '<div class="notice notice-success is-dismissible"><p>' . __('JetForm JSON uploaded successfully. Click "Populate Mapping Table" below.', 'jfb-wc-quotes-advanced') . '</p></div>';
                     jfbwqa_write_log("Uploaded jetform-latest.json successfully.");
                 } else {
                     $file_upload_message = '<div class="notice notice-error is-dismissible"><p>' . __('Error: Could not save uploaded JSON file. Check plugin directory permissions.', 'jfb-wc-quotes-advanced') . '</p></div>';
                      jfbwqa_write_log("ERROR: Failed moving uploaded file to " . $destination);
                 }
             } else {
                  $file_upload_message = '<div class="notice notice-error is-dismissible"><p>' . __('Error: Uploaded file must be a JSON file.', 'jfb-wc-quotes-advanced') . '</p></div>';
             }
        } elseif ( isset($_FILES['jfbwqa_jetform_json']['error']) && $_FILES['jfbwqa_jetform_json']['error'] !== UPLOAD_ERR_NO_FILE ) {
             $file_upload_message = '<div class="notice notice-error is-dismissible"><p>' . sprintf(__('File upload error: %s', 'jfb-wc-quotes-advanced'), $_FILES['jfbwqa_jetform_json']['error']) . '</p></div>';
        }

        // Handle Saving Mapping Data (submitted via main form)
        if ( isset( $_POST[JFBWQA_OPTION_NAME]['jfbwqa_mapping'] ) && is_array( $_POST[JFBWQA_OPTION_NAME]['jfbwqa_mapping'] ) ) {
             $new_mapping = [];
             // Sanitize the submitted mapping data (comes nested under main option name now)
             foreach ( $_POST[JFBWQA_OPTION_NAME]['jfbwqa_mapping'] as $fieldId => $wcTargets ) {
                  $sanitized_fieldId = sanitize_text_field( $fieldId );
                  if ( is_array( $wcTargets ) ) {
                      // Allow up to 3 mappings, sanitize each
                      $sanitized_targets = array_values( array_filter( array_map( 'sanitize_text_field', array_slice( $wcTargets, 0, 3 ) ) ) );
                      if ( ! empty( $sanitized_targets ) ) {
                          $new_mapping[$sanitized_fieldId] = $sanitized_targets;
                      }
                  }
             }
             jfbwqa_write_log("Attempting to save mapping: " . print_r($new_mapping, true));
             if ( jfbwqa_write_mapping( $new_mapping ) ) {
                 // Success message added by jfbwqa_write_mapping using add_settings_error
             } else {
                 // Error message added by jfbwqa_write_mapping using add_settings_error
             }
             // Unset from main POST array so it doesn't get processed by options.php incorrectly
             // unset($_POST[JFBWQA_OPTION_NAME]['jfbwqa_mapping']); // May not be needed if sanitize ignores it
        }
    }

    // --- Handle Populate Table Action (Separate form submission) ---
    $populate_table_html = ''; // Store generated table HTML here
    $populate_action_message = ''; // Message specific to the populate action
    if ( isset($_POST['jfbwqa_populate_table']) && isset($_POST['jfbwqa_populate_nonce']) && wp_verify_nonce($_POST['jfbwqa_populate_nonce'], 'jfbwqa_admin_nonce_populate') ) {
        jfbwqa_write_log("Populate table button clicked.");
        $json_path = jfbwqa_jetform_path();
        if ( file_exists($json_path) ) {
            $raw_json = @file_get_contents($json_path);
            if ($raw_json) {
                 $decoded = json_decode($raw_json, true);
                 // Check if decoding worked AND contains the expected structure (e.g., post_content)
                 if ( json_last_error() === JSON_ERROR_NONE && is_array($decoded) && isset($decoded['post_content']) ) {
                     $fields = jfbwqa_extract_fields_from_post_content($decoded['post_content']);
                     if ( ! empty( $fields ) ) {
                         jfbwqa_write_log("Extracted fields for mapping: " . count($fields));
                         $combined_wc_fields = jfbwqa_get_combined_order_fields();
                         $current_mapping = jfbwqa_read_mapping(); // Load existing mapping to pre-populate

                         ob_start();
                         ?>
                         <div id="jfbwqa-mapping-table-dynamic">
                             <h3><?php esc_html_e('Map Fields', 'jfb-wc-quotes-advanced'); ?></h3>
                             <p><?php esc_html_e('Select the corresponding WooCommerce/Quote field(s) for each JetForm field. Changes here are saved when you click the main "Save All Settings" button above.', 'jfb-wc-quotes-advanced'); ?></p>
                             <table class="widefat fixed striped" style="max-width:950px;">
                                 <thead>
                                     <tr>
                                         <th style="width: 20%;"><?php esc_html_e('JetForm Field Label', 'jfb-wc-quotes-advanced'); ?></th>
                                         <th style="width: 20%;"><?php esc_html_e('JetForm Field Name (ID)', 'jfb-wc-quotes-advanced'); ?></th>
                                         <th style="width: 20%;"><?php esc_html_e('Map to Field 1', 'jfb-wc-quotes-advanced'); ?></th>
                                         <th style="width: 20%;"><?php esc_html_e('Map to Field 2', 'jfb-wc-quotes-advanced'); ?></th>
                                         <th style="width: 20%;"><?php esc_html_e('Map to Field 3', 'jfb-wc-quotes-advanced'); ?></th>
                                     </tr>
                                 </thead>
                                 <tbody>
                                     <?php foreach ( $fields as $field ) :
                                         $fieldId = $field['id'];
                                         $fieldName = $field['name'];
                                         $currentMappings = isset($current_mapping[$fieldId]) && is_array($current_mapping[$fieldId]) ? $current_mapping[$fieldId] : [];
                                     ?>
                                     <tr>
                                         <td><?php echo esc_html($fieldName); ?></td>
                                         <td><code><?php echo esc_html($fieldId); ?></code></td>
                                         <?php for ( $i = 0; $i < 3; $i++ ) : ?>
                                         <td>
                                             <?php // IMPORTANT: name attribute must be nested under the main option name for Settings API form ?>
                                             <select class="jfbwqa-select2-search" name="<?php echo esc_attr(JFBWQA_OPTION_NAME); ?>[jfbwqa_mapping][<?php echo esc_attr($fieldId); ?>][<?php echo $i; ?>]" style="width:100%;">
                                                 <option value="">-- <?php esc_html_e('Not Mapped', 'jfb-wc-quotes-advanced'); ?> --</option>
                                                 <?php foreach ( $combined_wc_fields as $wcF ) : ?>
                                                 <option value="<?php echo esc_attr($wcF); ?>" <?php selected( ($currentMappings[$i] ?? ''), $wcF ); ?>>
                                                     <?php echo esc_html($wcF); ?>
                                                 </option>
                                                 <?php endforeach; ?>
                                             </select>
                                         </td>
                                         <?php endfor; ?>
                                     </tr>
                                     <?php endforeach; ?>
                                 </tbody>
                             </table>
                         </div>
                         <?php
                         $populate_table_html = ob_get_clean();
                         $populate_action_message = '<div class="notice notice-success is-dismissible"><p>' . __('Mapping table populated. Review the mappings below and click "Save All Settings" to save them.', 'jfb-wc-quotes-advanced') . '</p></div>';
                     } else {
                          $populate_action_message = '<div class="notice notice-warning is-dismissible"><p>' . __('Could not extract any recognizable JetForm fields from the uploaded JSON\'s post_content.', 'jfb-wc-quotes-advanced') . '</p></div>';
                          jfbwqa_write_log("Warning: No fields extracted from post_content.");
                     }
                 } else {
                     $populate_action_message = '<div class="notice notice-error is-dismissible"><p>' . __('Error: Could not decode the uploaded JSON file or it is missing the required "post_content" key.', 'jfb-wc-quotes-advanced') . ' Error: ' . json_last_error_msg() . '</p></div>';
                     jfbwqa_write_log("ERROR: Failed decoding jetform-latest.json or missing post_content. JSON Error: " . json_last_error_msg());
                 }
            } else {
                 $populate_action_message = '<div class="notice notice-error is-dismissible"><p>' . __('Error: Could not read the content of the uploaded JSON file (jetform-latest.json).', 'jfb-wc-quotes-advanced') . '</p></div>';
                 jfbwqa_write_log("ERROR: Cannot read content of jetform-latest.json.");
            }
        } else {
            $populate_action_message = '<div class="notice notice-warning is-dismissible"><p>' . __('Please upload a JetForm export JSON file first using the "Upload JetForm JSON" field above and click "Save All Settings".', 'jfb-wc-quotes-advanced') . '</p></div>';
            jfbwqa_write_log("Warning: Populate clicked but jetform-latest.json does not exist.");
        }
    }

    // --- Enqueue Select2 ---
    wp_enqueue_style('select2-css', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css', [], '4.1.0');
    wp_enqueue_script('select2-js', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', ['jquery'], '4.1.0', true);
    wp_add_inline_script('select2-js', "
        jQuery(document).ready(function($){
            function initSelect2(){
                if($.fn.select2){
                    $('.jfbwqa-select2-search').select2({ width:'resolve' });
                    console.log('JFBWQA Select2 Initialized');
                } else {
                    console.log('JFBWQA Select2 function not found');
                }
            }
            initSelect2(); // Initial load

            // Re-initialize if table is populated dynamically
            $(document).on('jfbwqa:mappingTablePopulated', function(){
                 console.log('JFBWQA Mapping table populated event caught.');
                 initSelect2();
            });

             // Trigger population event if table HTML is directly embedded on load
             if ( $('#jfbwqa-mapping-table-dynamic').length > 0 ) {
                 console.log('JFBWQA Triggering population event on static load.');
                 $(document).trigger('jfbwqa:mappingTablePopulated');
             }
        });
    ");

    ?>
    <div class="wrap">
        <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

        <?php
            // Display messages from file upload or populate action
            echo $file_upload_message;
            echo $populate_action_message;
            // Display standard Settings API update messages (like "Settings saved.")
            settings_errors();
             // Display messages specifically added for mapping save status
            settings_errors('jfbwqa_mapping');
        ?>

        <form action="options.php" method="post" enctype="multipart/form-data">
            <?php
            // Output security fields for the registered setting group
            settings_fields( 'jfbwqa_settings_group' );

            // Output standard settings sections (General, Email)
            do_settings_sections( JFBWQA_SETTINGS_SLUG );
            ?>

            <hr>
            <h2><?php esc_html_e('Field Mapping Setup', 'jfb-wc-quotes-advanced'); ?></h2>
            <table class="form-table">
                 <tr valign="top">
                    <th scope="row"><?php esc_html_e('Upload JetForm JSON', 'jfb-wc-quotes-advanced'); ?></th>
                    <td>
                        <input type="file" name="jfbwqa_jetform_json" id="jfbwqa_jetform_json" accept=".json">
                        <p class="description"><?php esc_html_e('Export your JetForm (use "Export Form"), upload the JSON file here, and click "Save All Settings". This makes the form available for the "Populate Mapping Table" button below.', 'jfb-wc-quotes-advanced'); ?> <br> <?php printf(__('Current file: %s', 'jfb-wc-quotes-advanced'), '<code>' . esc_html(basename(jfbwqa_jetform_path())) . (file_exists(jfbwqa_jetform_path()) ? ' (exists)' : ' (not found)') . '</code>'); ?></p>
                    </td>
                 </tr>
            </table>

            <?php // The dynamically generated mapping table will be placed here ?>
            <div id="jfbwqa-mapping-table-container">
                 <?php
                 // If the populate button was just clicked, inject the generated HTML
                 if ( ! empty( $populate_table_html ) ) {
                      echo $populate_table_html;
                 } else {
                     // If not populating now, try to display the *current* mapping if it exists
                     $current_mapping = jfbwqa_read_mapping();
                     if (!empty($current_mapping)) {
                         echo '<p>' . __('Mapping table not populated in this request. Displaying previously saved mapping loaded from <code>field-mapping.json</code>. Click "Populate Mapping Table" below to regenerate based on the latest uploaded JSON.', 'jfb-wc-quotes-advanced') . '</p>';
                         // Ideally, render a static view of the current mapping here,
                         // or just instruct user to click Populate. For simplicity, just showing the message.
                         // You could potentially generate the table here based *only* on the mapping file,
                         // but you wouldn't have the JFB field labels without the source JSON.
                     } else {
                          echo '<p>' . __('Upload a JetForm JSON and click "Save All Settings", then click "Populate Mapping Table" below to configure field mapping.', 'jfb-wc-quotes-advanced') . '</p>';
                     }
                 }
                 ?>
            </div>

            <?php // Main Save Button for ALL settings (General + Mapping) ?>
            <?php submit_button( __('Save All Settings', 'jfb-wc-quotes-advanced') ); ?>

        </form>

        <hr>
        <?php // Separate form for the "Populate" action ?>
        <form method="post" id="jfbwqa-populate-form" style="margin-top: 20px;">
             <?php wp_nonce_field('jfbwqa_admin_nonce_populate', 'jfbwqa_populate_nonce'); ?>
             <input type="submit" class="button" name="jfbwqa_populate_table" value="<?php esc_attr_e('Populate Mapping Table', 'jfb-wc-quotes-advanced'); ?>" <?php echo !file_exists(jfbwqa_jetform_path()) ? 'disabled' : ''; ?>>
             <p class="description"><?php esc_html_e('Click this AFTER uploading a JSON file and saving settings. This reads the uploaded file (jetform-latest.json) and generates the mapping table above based on its fields.', 'jfb-wc-quotes-advanced'); ?> <?php if (!file_exists(jfbwqa_jetform_path())) echo '<strong>' . __('(Disabled until a JSON file is uploaded and saved)', 'jfb-wc-quotes-advanced') . '</strong>'; ?></p>
        </form>

    </div>
     <?php
     // Add JS to trigger event if table HTML was embedded directly
     if (!empty($populate_table_html)) {
         echo "<script>document.addEventListener('DOMContentLoaded', function(){ jQuery(document).trigger('jfbwqa:mappingTablePopulated'); });</script>";
     }
     ?>
    <?php
}


/* =============================================================================
   10) Email Template File Placeholder & Text Domain
   ============================================================================= */

// Reminder about the template file needed:
// wp-content/plugins/YOUR-PLUGIN-FOLDER/woocommerce/emails/customer-estimate-request.php
// See v1.14 code comments for example content.

add_action( 'plugins_loaded', 'jfbwqa_load_textdomain' );
function jfbwqa_load_textdomain() {
    load_plugin_textdomain( 'jfb-wc-quotes-advanced', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
}

/* =============================================================================
   11) v1.28 - Email Action Composer Metabox (replaces v1.21-v1.27 modal stack)
   -----------------------------------------------------------------------------
   The previous design relied on:
   - A small textarea metabox ("Custom Estimate Email Message") for the
     estimate-request side.
   - A "Send Estimate Response" button injected into the order-items
     toolbar that opened a fixed-position popup with subject/body/etc
     fields, posting via AJAX to wp_ajax_jfbwqa_send_quote_via_metabox.

   That design had two problems:
   1. The popup never opened on the user's HPOS install. The modal HTML
      was rendered conditionally on screen detection that worked in
      principle but failed in practice; the click handler then couldn't
      find #jfbwqa-quote-response-modal and the button silently did
      nothing.
   2. The two email actions had asymmetric UX: estimate request used a
      simple textarea, prepared quote used a rich modal. Maintenance
      (and admin learning curve) was higher than necessary.

   v1.28 collapses both into a single dynamic metabox in the order
   edit screen's main column. The metabox always renders both
   sections (estreq + quote); JavaScript shows only the section that
   matches whichever option the admin has selected in WooCommerce's
   native "Order actions" dropdown. Clicking WC's `>` arrow submits
   the order edit form, our metabox fields go to $_POST, and the
   action handlers read overrides from there.

   No popups. No AJAX. Pure progressive enhancement on top of WC's
   native form submission.

   Future direction (kanban / admin reordering) is captured in
   woocommerce-build-plan.md section 9.4.1 - explicitly out of scope
   here.
   ============================================================================= */

/**
 * Meta-key prefixes used by the composer to persist per-order overrides.
 * Each key stores a serialized array; see jfbwqa_default_email_overrides()
 * for the shape.
 */
const JFBWQA_META_ESTREQ_OVERRIDES = '_jfbwqa_estreq_overrides';
const JFBWQA_META_QUOTE_OVERRIDES  = '_jfbwqa_quote_overrides';

/**
 * Map of WC order action slug -> ('estimate' | 'quote') email type.
 * Used by the metabox JS to know which section to reveal, and by the
 * action handlers to know which override key to read.
 */
function jfbwqa_action_to_email_type_map() {
    return [
        'jfbwqa_send_estimate_email'  => 'estimate',
        'jfbwqa_send_prepared_quote'  => 'quote',
    ];
}

/**
 * Default shape for an email-overrides array. Empty string means
 * "use settings default" for text fields; null in the table-overrides
 * subarray means "use settings default" for that toggle.
 */
function jfbwqa_default_email_overrides() {
    return [
        'subject'           => '',
        'heading'           => '',
        'reply_to'          => '',
        'cc'                => '',
        'body'              => '',
        'override_table'    => false, // master switch for the 9 table toggles
        'table'             => jfbwqa_default_table_config(),
    ];
}

/**
 * Read the saved override array from order meta, merging with defaults
 * so callers never have to null-check individual keys.
 */
function jfbwqa_get_email_overrides( $order, $email_type ) {
    if ( ! $order instanceof WC_Order ) {
        $order = wc_get_order( (int) $order );
    }
    if ( ! $order ) {
        return jfbwqa_default_email_overrides();
    }
    $key   = ( $email_type === 'quote' ) ? JFBWQA_META_QUOTE_OVERRIDES : JFBWQA_META_ESTREQ_OVERRIDES;
    $saved = $order->get_meta( $key, true );
    if ( ! is_array( $saved ) ) {
        return jfbwqa_default_email_overrides();
    }
    $merged           = wp_parse_args( $saved, jfbwqa_default_email_overrides() );
    $merged['table']  = wp_parse_args( $merged['table'] ?? [], jfbwqa_default_table_config() );
    return $merged;
}

/**
 * Resolve the effective table config for a given email send.
 * Layered:
 *   - Settings default for that email type (estimate/quote).
 *   - If the order's overrides have override_table=true, replace with
 *     the saved per-order values.
 */
function jfbwqa_resolve_table_config_for_send( $order, $email_type ) {
    $config = jfbwqa_get_table_config_from_settings( $email_type );
    $over   = jfbwqa_get_email_overrides( $order, $email_type );
    if ( ! empty( $over['override_table'] ) && is_array( $over['table'] ) ) {
        // Per-order toggles win; defaults from jfbwqa_default_table_config
        // backfill any missing keys.
        $config = wp_parse_args( $over['table'], jfbwqa_default_table_config() );
    }
    return $config;
}

/**
 * Register the Email Action Composer metabox on both legacy and HPOS
 * order edit screens.
 */
add_action( 'add_meta_boxes', 'jfbwqa_register_action_composer_metabox' );
function jfbwqa_register_action_composer_metabox() {
    add_meta_box(
        'jfbwqa_action_composer',
        __( 'JFBWQA: Email Action Composer', 'jfb-wc-quotes-advanced' ),
        'jfbwqa_render_action_composer_metabox',
        jfbwqa_get_order_screen_id(),
        'normal',
        'high'
    );
}

/**
 * Render the metabox: introductory blurb + one section per email type,
 * each with subject/heading/body/reply-to/cc + optional table override
 * checkboxes. JavaScript reveals exactly one section based on the WC
 * "Order actions" dropdown selection.
 */
function jfbwqa_render_action_composer_metabox( $post_or_order ) {
    wp_nonce_field( 'jfbwqa_save_action_composer', 'jfbwqa_action_composer_nonce' );

    $order = ( $post_or_order instanceof WC_Order )
        ? $post_or_order
        : wc_get_order( $post_or_order instanceof WP_Post ? $post_or_order->ID : 0 );

    if ( ! $order ) {
        echo '<p>' . esc_html__( 'Order not found.', 'jfb-wc-quotes-advanced' ) . '</p>';
        return;
    }

    $opts            = jfbwqa_get_options();
    $estreq          = jfbwqa_get_email_overrides( $order, 'estimate' );
    $quote           = jfbwqa_get_email_overrides( $order, 'quote' );
    $est_settings    = jfbwqa_get_table_config_from_settings( 'estimate' );
    $quote_settings  = jfbwqa_get_table_config_from_settings( 'quote' );
    $action_map      = jfbwqa_action_to_email_type_map();
    ?>
    <div id="jfbwqa-action-composer" data-action-map="<?php echo esc_attr( wp_json_encode( $action_map ) ); ?>">
        <p class="description" style="margin-bottom:12px;">
            <?php esc_html_e( 'Pick an action from the "Order actions" dropdown above to compose its email here. Save the order to persist drafts; click the dropdown\'s arrow button to send.', 'jfb-wc-quotes-advanced' ); ?>
        </p>

        <div class="jfbwqa-empty-state" style="padding:14px; background:#f6f7f7; border-left:4px solid #c3c4c7; color:#50575e;">
            <?php esc_html_e( 'No action selected. Pick "Send Estimate Request Email" or "Send Prepared Quote Email" from the Order actions dropdown.', 'jfb-wc-quotes-advanced' ); ?>
        </div>

        <?php
        // Render one section per (email_type, action_slug) pair so JS can
        // show exactly the one that matches the selected dropdown value.
        $sections = [
            'jfbwqa_send_estimate_email' => [
                'email_type'  => 'estimate',
                'overrides'   => $estreq,
                'settings'    => $est_settings,
                'header'      => __( 'Send Estimate Request Email', 'jfb-wc-quotes-advanced' ),
                'subject_def' => $opts['email_subject']      ?? '',
                'heading_def' => $opts['email_heading']      ?? '',
                'reply_def'   => $opts['email_reply_to']     ?? '',
                'cc_def'      => $opts['email_cc']           ?? '',
                'body_def'    => $opts['email_default_body'] ?? '',
                'name_prefix' => 'jfbwqa_estreq',
            ],
            'jfbwqa_send_prepared_quote' => [
                'email_type'  => 'quote',
                'overrides'   => $quote,
                'settings'    => $quote_settings,
                'header'      => __( 'Send Prepared Quote Email', 'jfb-wc-quotes-advanced' ),
                'subject_def' => $opts['quote_email_subject']      ?? '',
                'heading_def' => $opts['quote_email_heading']      ?? '',
                'reply_def'   => $opts['quote_email_reply_to']     ?? '',
                'cc_def'      => $opts['quote_email_cc']           ?? '',
                'body_def'    => $opts['quote_email_default_body'] ?? '',
                'name_prefix' => 'jfbwqa_quote',
            ],
        ];

        foreach ( $sections as $action_slug => $sec ) :
            $over = $sec['overrides'];
            $tbl  = $over['table'];
            $set  = $sec['settings'];
            $np   = $sec['name_prefix'];
            ?>
            <div class="jfbwqa-action-section" data-jfbwqa-action="<?php echo esc_attr( $action_slug ); ?>" style="display:none; margin-top:8px;">
                <h3 style="margin:0 0 8px 0; font-size:14px; padding:8px 12px; background:#f0f6fc; border:1px solid #c5d9ed;">
                    <?php echo esc_html( $sec['header'] ); ?>
                </h3>
                <p class="description" style="margin:0 0 12px 0;">
                    <?php esc_html_e( 'Empty fields fall back to the plugin settings defaults. The selected action fires when you click the arrow button on the Order actions dropdown above.', 'jfb-wc-quotes-advanced' ); ?>
                </p>
                <table class="form-table" style="margin-top:0;">
                    <tr>
                        <th scope="row"><label><?php esc_html_e( 'Subject (override)', 'jfb-wc-quotes-advanced' ); ?></label></th>
                        <td><input type="text" name="<?php echo esc_attr( $np ); ?>[subject]" value="<?php echo esc_attr( $over['subject'] ); ?>" placeholder="<?php echo esc_attr( $sec['subject_def'] ); ?>" style="width:100%;" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label><?php esc_html_e( 'Heading (override)', 'jfb-wc-quotes-advanced' ); ?></label></th>
                        <td><input type="text" name="<?php echo esc_attr( $np ); ?>[heading]" value="<?php echo esc_attr( $over['heading'] ); ?>" placeholder="<?php echo esc_attr( $sec['heading_def'] ); ?>" style="width:100%;" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label><?php esc_html_e( 'Reply-To (override)', 'jfb-wc-quotes-advanced' ); ?></label></th>
                        <td><input type="email" name="<?php echo esc_attr( $np ); ?>[reply_to]" value="<?php echo esc_attr( $over['reply_to'] ); ?>" placeholder="<?php echo esc_attr( $sec['reply_def'] ); ?>" style="width:100%;" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label><?php esc_html_e( 'CC (override)', 'jfb-wc-quotes-advanced' ); ?></label></th>
                        <td><input type="email" name="<?php echo esc_attr( $np ); ?>[cc]" value="<?php echo esc_attr( $over['cc'] ); ?>" placeholder="<?php echo esc_attr( $sec['cc_def'] ); ?>" style="width:100%;" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label><?php esc_html_e( 'Body (override)', 'jfb-wc-quotes-advanced' ); ?></label></th>
                        <td>
                            <textarea name="<?php echo esc_attr( $np ); ?>[body]" rows="8" style="width:100%; font-family: monospace, monospace; font-size: 12px;" placeholder="<?php echo esc_attr( wp_strip_all_tags( $sec['body_def'] ) ); ?>"><?php echo esc_textarea( $over['body'] ); ?></textarea>
                            <p class="description"><?php esc_html_e( 'Placeholders: {order_number}, {customer_first_name}, {customer_name}, {site_title}, {[your_je_field_key]}, [Order Details Table]. Leave empty to use the body template from settings.', 'jfb-wc-quotes-advanced' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Order Details Table', 'jfb-wc-quotes-advanced' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( $np ); ?>[override_table]" value="1" <?php checked( ! empty( $over['override_table'] ) ); ?> class="jfbwqa-override-table-toggle" />
                                <?php esc_html_e( 'Override the plugin-settings table layout for this email', 'jfb-wc-quotes-advanced' ); ?>
                            </label>
                            <fieldset class="jfbwqa-table-overrides" style="margin-top:8px; padding:8px; border:1px solid #ddd; <?php echo empty( $over['override_table'] ) ? 'opacity:0.5;' : ''; ?>">
                                <?php
                                $toggle_specs = [
                                    'show_image'       => __( 'Show product images', 'jfb-wc-quotes-advanced' ),
                                    'show_unit_price'  => __( 'Show unit price column', 'jfb-wc-quotes-advanced' ),
                                    'show_line_total'  => __( 'Show line total column', 'jfb-wc-quotes-advanced' ),
                                    'show_subtotal'    => __( 'Show subtotal row', 'jfb-wc-quotes-advanced' ),
                                    'show_shipping'    => __( 'Show shipping row(s) as table rows', 'jfb-wc-quotes-advanced' ),
                                    'show_fees'        => __( 'Show fee row(s) as table rows', 'jfb-wc-quotes-advanced' ),
                                    'show_discount'    => __( 'Show discount row', 'jfb-wc-quotes-advanced' ),
                                    'show_tax'         => __( 'Show tax row', 'jfb-wc-quotes-advanced' ),
                                    'show_grand_total' => __( 'Show grand total row', 'jfb-wc-quotes-advanced' ),
                                ];
                                foreach ( $toggle_specs as $tk => $tlabel ) :
                                    $current_state = isset( $tbl[ $tk ] ) ? (bool) $tbl[ $tk ] : (bool) ( $set[ $tk ] ?? false );
                                    ?>
                                    <label style="display:block; margin-bottom:4px;">
                                        <input type="checkbox" name="<?php echo esc_attr( $np ); ?>[table][<?php echo esc_attr( $tk ); ?>]" value="1" <?php checked( $current_state ); ?> />
                                        <?php echo esc_html( $tlabel ); ?>
                                        <span style="color:#999; font-size:11px;">
                                            (<?php echo $set[ $tk ] ? esc_html__( 'settings default: ON', 'jfb-wc-quotes-advanced' ) : esc_html__( 'settings default: OFF', 'jfb-wc-quotes-advanced' ); ?>)
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </fieldset>
                        </td>
                    </tr>
                </table>
            </div>
        <?php endforeach; ?>
    </div>
    <?php
}

/**
 * Save composer metabox values into the order's meta on order save
 * (woocommerce_process_shop_order_meta fires for both HPOS and legacy).
 */
add_action( 'woocommerce_process_shop_order_meta', 'jfbwqa_save_action_composer_metabox', 10, 2 );
function jfbwqa_save_action_composer_metabox( $order_id, $order = null ) {
    if ( ! isset( $_POST['jfbwqa_action_composer_nonce'] ) || ! wp_verify_nonce( $_POST['jfbwqa_action_composer_nonce'], 'jfbwqa_save_action_composer' ) ) {
        return;
    }
    if ( ! current_user_can( 'edit_shop_order', $order_id ) ) {
        return;
    }
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }

    $wc_order = ( $order instanceof WC_Order ) ? $order : wc_get_order( $order_id );
    if ( ! $wc_order ) {
        return;
    }

    foreach ( [ 'estreq' => JFBWQA_META_ESTREQ_OVERRIDES, 'quote' => JFBWQA_META_QUOTE_OVERRIDES ] as $prefix => $meta_key ) {
        $field = 'jfbwqa_' . $prefix;
        $raw   = isset( $_POST[ $field ] ) && is_array( $_POST[ $field ] ) ? wp_unslash( $_POST[ $field ] ) : [];

        // Compose the cleaned override structure. Empty strings stay empty
        // (intentional: empty == "use settings default" downstream).
        $clean = [
            'subject'         => isset( $raw['subject'] )  ? sanitize_text_field( $raw['subject'] )      : '',
            'heading'         => isset( $raw['heading'] )  ? sanitize_text_field( $raw['heading'] )      : '',
            'reply_to'        => isset( $raw['reply_to'] ) ? sanitize_email( $raw['reply_to'] )          : '',
            'cc'              => isset( $raw['cc'] )       ? sanitize_email( $raw['cc'] )                : '',
            'body'            => isset( $raw['body'] )     ? wp_kses_post( (string) $raw['body'] )       : '',
            'override_table'  => ! empty( $raw['override_table'] ),
            'table'           => [],
        ];

        foreach ( jfbwqa_default_table_config() as $tk => $default_val ) {
            // When the master "override_table" flag is OFF, individual
            // checkboxes don't matter at send time, but we still record
            // their state so the form re-renders with whatever the admin
            // had checked (better UX than silently resetting).
            $clean['table'][ $tk ] = isset( $raw['table'][ $tk ] ) ? (bool) $raw['table'][ $tk ] : false;
        }

        $wc_order->update_meta_data( $meta_key, $clean );
    }
    $wc_order->save();
}

/**
 * Enqueue the composer JavaScript on order edit screens (legacy + HPOS).
 */
add_action( 'admin_enqueue_scripts', 'jfbwqa_enqueue_action_composer_scripts' );
function jfbwqa_enqueue_action_composer_scripts() {
    if ( ! function_exists( 'get_current_screen' ) ) {
        return;
    }
    $screen = get_current_screen();
    if ( ! $screen ) {
        return;
    }
    $is_legacy_order = ( $screen->base === 'post' && $screen->post_type === 'shop_order' );
    $is_hpos_order   = ( strpos( $screen->id, 'woocommerce_page_wc-orders' ) !== false );
    if ( ! $is_legacy_order && ! $is_hpos_order ) {
        return;
    }
    wp_enqueue_script(
        'jfbwqa-action-composer',
        plugin_dir_url( __FILE__ ) . 'assets/js/admin-action-composer.js',
        [],
        JFBWQA_VERSION,
        true
    );
}

/**
 * Modify the WooCommerce email footer text to remove "Built with WooCommerce" part if present.
 */
add_filter( 'woocommerce_email_footer_text', 'jfbwqa_custom_email_footer_text', 20 );
function jfbwqa_custom_email_footer_text( $footer_text ) {
    // Default WooCommerce footer text can be "{site_title} &mdash; Built with <a href="https://woocommerce.com">WooCommerce</a>."
    // We want to keep the {site_title} part but remove the rest if it matches that pattern.
    $site_title = get_bloginfo( 'name', 'display' );
    $built_with_woocommerce_string = '&mdash; Built with <a href="https://woocommerce.com">WooCommerce</a>';
    
    // Check if the specific string is present
    if ( strpos( $footer_text, $built_with_woocommerce_string ) !== false ) {
        // If you want to ONLY show site title, and nothing else from WC settings:
        // return $site_title;
        
        // If you want to remove just the "Built with..." part from the existing WC setting:
        $footer_text = str_replace( $built_with_woocommerce_string, '', $footer_text );
        // Trim any trailing spaces or mdash that might be left if the original string was just that.
        $footer_text = rtrim(trim($footer_text), '&mdash;'); 
        $footer_text = trim($footer_text);
        return $footer_text;
    } 
    // If the specific string isn't there, return the original footer text from settings
    return $footer_text;
}

// v1.28: The wp_ajax_jfbwqa_send_quote_via_metabox AJAX handler and the
// jfbwqa_enqueue_order_edit_scripts enqueue helper that paired with the
// modal stack have been removed. Order action sends now go through WC's
// native form submit -> woocommerce_order_action_<slug> path. The new
// composer metabox enqueues admin-action-composer.js on its own
// (jfbwqa_enqueue_action_composer_scripts).
