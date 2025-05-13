<?php
/**
 * Plugin Name: JFB WC Quotes Advanced
 * Plugin URI:  https://legworkmedia.ca
 * Description: Advanced integration for JetFormBuilder & WooCommerce. Map fields (incl. JE meta), custom "Estimate Request" email configured in plugin settings and triggered via Order Action, dynamic cart shortcode, custom order status. Admin settings page with integrated field mapping UI.
 * Version:     1.17
 * Author:      legworkmedia
 * Author URI:  https://legworkmedia.ca
 * License:     GPL2
 * Text Domain: jfb-wc-quotes-advanced
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // No direct access.
}

define( 'JFBWQA_VERSION', '1.17' );
define( 'JFBWQA_OPTION_NAME', 'jfbwqa_options' ); // Option key for general settings
define( 'JFBWQA_SETTINGS_SLUG', 'jfbwqa-settings' ); // Menu slug for settings page

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
        'consumer_key'       => '',
        'consumer_secret'    => '',
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
        'quote_email_default_body' => "Hello {customer_first_name},\n\nYour quote is ready! Please find the details below:\n\n[Order Details Table]\n\n{additional_message_from_admin}\n\nIf you have any questions, please let us know.\n\nRegards,\n{site_title}"
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

jfbwqa_write_log("Plugin file loaded (v" . JFBWQA_VERSION . " - Admin Settings + Mapping UI).", true);

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

// --- Form Submission Handler (Reads options, uses mapping JSON) ---
function jfbwqa_handle_form_submission( $result, $request, $action_handler ) {
    jfbwqa_write_log("JFB form submission handling started.");
    $options = jfbwqa_get_options();
    $consumer_key = $options['consumer_key'];
    $consumer_secret = $options['consumer_secret'];

    if ( empty($consumer_key) || empty($consumer_secret) ) {
        jfbwqa_write_log("ERROR: WooCommerce API Credentials missing in plugin settings.");
        return new WP_Error('jfbwqa_config_error', __('Configuration error: WooCommerce API credentials are required.', 'jfb-wc-quotes-advanced'));
    }

    $mapping = jfbwqa_read_mapping(); // Read mapping from field-mapping.json
    if ( empty( $mapping ) ) {
         jfbwqa_write_log("WARNING: Field mapping (field-mapping.json) is empty. Cannot map fields.");
         // Optional: Decide if this is fatal
         // return new WP_Error('jfbwqa_mapping_error', __('Configuration error: Field mapping is not configured.', 'jfb-wc-quotes-advanced'));
    }

    $order_data_rest = [ /* ... structure ... */
        'payment_method' => 'bacs', 'payment_method_title' => __('Request a Quote', 'jfb-wc-quotes-advanced'),
        'status' => 'estimate-request', 'set_paid' => false,
        'billing' => [], 'shipping' => [], 'meta_data' => [], 'line_items' => [], 'customer_note' => ''
    ];
    $jetengine_meta_to_save = [];
    $cart_items_json = '';

    // Process form fields based on mapping from JSON file
    foreach ( $mapping as $jfb_field_id => $wc_targets ) {
        if ( isset($request[$jfb_field_id]) ) {
            $jfb_field_value = $request[$jfb_field_id];
            $sanitized_value = is_array($jfb_field_value) ? array_map('sanitize_text_field', $jfb_field_value) : sanitize_text_field($jfb_field_value);

            foreach ( (array) $wc_targets as $wc_field_key ) {
                if ( empty($wc_field_key) ) continue;
                // ... (Mapping logic as in v1.14 - maps to billing, shipping, meta_data, JE meta, cart) ...
                 if ( $wc_field_key === '*Cart items list*' ) {
                    if ( is_string($sanitized_value) ) {
                        $cart_items_json = $sanitized_value;
                        $order_data_rest['meta_data'][] = ['key' => '_jfbwqa_raw_cart_items_json', 'value' => $cart_items_json];
                    }
                } elseif ( strpos($wc_field_key, '*JE_meta*.') === 0 ) {
                    $meta_key = substr($wc_field_key, strlen('*JE_meta*.'));
                    if ( ! empty($meta_key) ) $jetengine_meta_to_save[$meta_key] = $sanitized_value;
                } elseif ( strpos($wc_field_key, 'meta_data.') === 0 ) {
                    $meta_key = substr($wc_field_key, strlen('meta_data.'));
                     if ( ! empty($meta_key) ) $order_data_rest['meta_data'][] = ['key' => $meta_key, 'value' => $sanitized_value];
                } else {
                    $parts = explode('.', $wc_field_key, 2);
                    $section = strtolower($parts[0]); $field_key = $parts[1] ?? '';
                    if ( ($section === 'billing' || $section === 'shipping') && ! empty($field_key) ) {
                        $order_data_rest[$section][$field_key] = $sanitized_value;
                    } elseif ( count($parts) === 1 && $section === 'customer_note' ) {
                         $order_data_rest['customer_note'] = sanitize_textarea_field($jfb_field_value);
                    }
                }
            }
        }
    }

    // Process Cart Items JSON
    if ( ! empty( $cart_items_json ) ) {
        $decoded_cart = json_decode( $cart_items_json, true );
        if ( json_last_error() === JSON_ERROR_NONE && is_array($decoded_cart) ) {
            foreach ( $decoded_cart as $item ) {
                $product_id = absint($item['id'] ?? 0); $quantity = absint($item['qty'] ?? 1);
                if ( $product_id > 0 && $quantity > 0 ) $order_data_rest['line_items'][] = ['product_id' => $product_id, 'quantity' => $quantity];
            }
        } else {
            jfbwqa_write_log("ERROR: Failed decoding cart items JSON. Error: " . json_last_error_msg());
            // return new WP_Error('jfbwqa_cart_error', __('Error processing cart items.', 'jfb-wc-quotes-advanced'));
        }
    }

    // Validate Essential Data
    if ( empty($order_data_rest['line_items']) ) return new WP_Error('jfbwqa_items_error', __('Cannot create estimate: No products were included.', 'jfb-wc-quotes-advanced'));
    if ( empty($order_data_rest['billing']['email']) || !is_email($order_data_rest['billing']['email']) ) return new WP_Error('jfbwqa_billing_error', __('Cannot create estimate: Billing email is required.', 'jfb-wc-quotes-advanced'));

    // --- Call WC REST API ---
    jfbwqa_write_log("Prepared Order Data (excluding JE meta): " . substr(print_r($order_data_rest, true), 0, 500));
    $api_endpoint = get_site_url(null, '/wp-json/wc/v3/orders');
    $auth_header = 'Basic ' . base64_encode( "{$consumer_key}:{$consumer_secret}" );
    $response = wp_remote_post($api_endpoint, [
        'method' => 'POST', 'headers' => ['Authorization' => $auth_header, 'Content-Type' => 'application/json'],
        'body' => wp_json_encode($order_data_rest), 'timeout' => 30
    ]);

    // --- Handle REST API Response ---
    if ( is_wp_error( $response ) ) { /* ... error handling ... */
        $error_message = $response->get_error_message();
        jfbwqa_write_log("ERROR: WP HTTP API Error: " . $error_message);
        return new WP_Error('jfbwqa_api_error', __('Error communicating with WooCommerce API.', 'jfb-wc-quotes-advanced') . ' ' . esc_html($error_message));
    }
    $response_code = wp_remote_retrieve_response_code($response);
    $response_body = wp_remote_retrieve_body($response);
    jfbwqa_write_log("WooCommerce API Response Code: {$response_code}");
    if ( $response_code < 200 || $response_code > 299 ) { /* ... error handling ... */
        $error_message = "WooCommerce REST API Error ({$response_code})";
        $decoded_body = json_decode($response_body, true);
        if ( $decoded_body && isset($decoded_body['message']) ) $error_message .= ': ' . $decoded_body['message'];
        jfbwqa_write_log("ERROR: " . $error_message);
        return new WP_Error('jfbwqa_wc_error', __('Error creating order via API.', 'jfb-wc-quotes-advanced') . ' ' . esc_html($error_message));
    }

    // --- Order Created Successfully ---
    $created_order_data = json_decode($response_body, true);
    $new_order_id = intval($created_order_data['id'] ?? 0);
    if ( $new_order_id <= 0 ) return new WP_Error('jfbwqa_id_error', __('Order created, but could not confirm details.', 'jfb-wc-quotes-advanced'));
    jfbwqa_write_log("SUCCESS: Created WC order #{$new_order_id}.");

    // --- Save JetEngine Meta via update_post_meta (as before) ---
    if ( ! empty($jetengine_meta_to_save) ) {
        jfbwqa_write_log("Saving " . count($jetengine_meta_to_save) . " JE meta fields for Order #{$new_order_id}...");
        $meta_save_success = true;
        foreach ( $jetengine_meta_to_save as $meta_key => $meta_value ) {
            if ( update_post_meta($new_order_id, $meta_key, $meta_value) === false ) {
                 jfbwqa_write_log("ERROR: update_post_meta failed for Order #{$new_order_id}, Key: '{$meta_key}'.");
                 $meta_save_success = false;
            }
        }
        if (!$meta_save_success) {
            jfbwqa_write_log("WARNING: One or more JE meta fields failed to save for order #{$new_order_id}.");
            if ($order = wc_get_order($new_order_id)) $order->add_order_note(__('Warning: Some custom field data may not have saved correctly.', 'jfb-wc-quotes-advanced'));
        }
    }

    jfbwqa_write_log("Overall JFB submission processing completed successfully for order #{$new_order_id}.");
    // $result['order_id'] = $new_order_id; // Optionally pass ID back
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

function jfbwqa_handle_send_prepared_quote_action( $order ) {
    if ( ! is_a( $order, 'WC_Order' ) ) {
        $order_id = absint($order);
        $order = wc_get_order($order_id);
        if ( ! $order ) { 
            jfbwqa_write_log("ERROR: Send Prepared Quote Action - Invalid order ID {$order_id}"); 
            return; 
        }
    }
    $order_id = $order->get_id();
    jfbwqa_write_log("Order action 'jfbwqa_send_prepared_quote' triggered for order ID: {$order_id}");
    
    $options = jfbwqa_get_options();

    // Quote Email Config from Settings
    $subject_template = $options['quote_email_subject'];
    $heading_template = $options['quote_email_heading'];
    $body_template    = $options['quote_email_default_body'];
    $reply_to_email   = sanitize_email($options['email_reply_to']); // Use general reply-to
    $cc_email         = sanitize_email($options['email_cc']);       // Use general CC

    // Get custom message and pricing flag from order meta
    $quote_custom_message = get_post_meta( $order_id, '_jfbwqa_quote_custom_message', true );
    $include_pricing_flag = get_post_meta( $order_id, '_jfbwqa_quote_include_pricing', true ) === 'yes'; // Converts to boolean

    jfbwqa_write_log("DEBUG: Send Prepared Quote - Custom Message for order #{$order_id}: " . $quote_custom_message);
    jfbwqa_write_log("DEBUG: Send Prepared Quote - Include Pricing for order #{$order_id}: " . ($include_pricing_flag ? 'Yes' : 'No'));

    $recipient_email = $order->get_billing_email();
    if ( ! is_email( $recipient_email ) ) {
        $error_msg = sprintf(__('Failed to send Prepared Quote Email for Order #%s: Invalid billing email.', 'jfb-wc-quotes-advanced'), $order->get_order_number());
        jfbwqa_write_log("ERROR: " . str_replace('#'.$order->get_order_number(), $order_id, $error_msg));
        $order->add_order_note( $error_msg, false, false );
        return;
    }

    $base_replacements = [
        '{order_number}' => $order->get_order_number(), 
        '{site_title}' => wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
        '{customer_first_name}' => $order->get_billing_first_name(),
        '{customer_last_name}' => $order->get_billing_last_name(),
        '{customer_name}' => $order->get_formatted_billing_full_name(),
        // Add a placeholder for the custom message from the metabox
        '{additional_message_from_admin}' => wpautop(wptexturize($quote_custom_message))
    ];

    $subject = str_replace(array_keys($base_replacements), array_values($base_replacements), $subject_template);
    $heading = str_replace(array_keys($base_replacements), array_values($base_replacements), $heading_template);
    
    // First, replace basic placeholders in the main body template, including the custom message placeholder
    $email_body_intermediate = str_replace(array_keys($base_replacements), array_values($base_replacements), $body_template);
    
    // Then, process all other placeholders including [Order Details Table] with pricing flag
    $email_body_final = jfbwqa_replace_email_placeholders( $email_body_intermediate, $order, $include_pricing_flag );

    jfbwqa_write_log("DEBUG: Send Prepared Quote - Final Email Body for order #{$order_id}: " . $email_body_final);

    $template_name = 'emails/customer-estimate-request.php'; // We can reuse the main wrapper template
    $default_plugin_path = jfbwqa_plugin_dir() . 'woocommerce/';

    $mailer = WC()->mailer();
    ob_start();
    $template_args = [
           'order' => $order,
           'email_heading' => $heading,
           'email_body_content' => $email_body_final, 
           'additional_content' => '', // Custom message is now part of email_body_final via placeholder
           'sent_to_admin' => false,
           'plain_text' => false,
           'email' => $mailer
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
    if ( !empty($cc_email) && is_email($cc_email) ) $headers[] = "Cc: <{$cc_email}>";

    jfbwqa_write_log("Sending Prepared Quote email to {$recipient_email} for order #{$order_id}.");
    $sent = wp_mail( $recipient_email, $subject, $email_html_content, $headers );

    if ( $sent ) {
        $note = __('Prepared Quote email sent to customer.', 'jfb-wc-quotes-advanced');
        if (!empty($quote_custom_message)) {
            $note .= ' ' . __('Custom message included.', 'jfb-wc-quotes-advanced');
        }
        if ($include_pricing_flag) {
            $note .= ' ' . __('Pricing was included.', 'jfb-wc-quotes-advanced');
        }
        $order->add_order_note( $note, false, false );
        jfbwqa_write_log("Prepared Quote email SENT successfully for order #{$order_id}.");
    } else {
        $error_msg = sprintf(__('Failed sending Prepared Quote email for Order #%s via wp_mail().', 'jfb-wc-quotes-advanced'), $order->get_order_number());
        $order->add_order_note( $error_msg, false, false );
        jfbwqa_write_log("ERROR: wp_mail() failed for Prepared Quote email, order #{$order_id}. Check mail server.");
        global $phpmailer; if ( isset($phpmailer) && !empty($phpmailer->ErrorInfo) ) jfbwqa_write_log("PHPMailer Error: " . $phpmailer->ErrorInfo);
    }
}

function jfbwqa_handle_order_action( $order ) {
    // Ensure $order is WC_Order object
    if ( ! is_a( $order, 'WC_Order' ) ) {
        $order_id = absint($order);
        $order = wc_get_order($order_id);
        if ( ! $order ) { jfbwqa_write_log("Error in order action handler: Invalid order ID {$order_id}"); return; }
    }
    $order_id = $order->get_id();
    jfbwqa_write_log("Order action 'jfbwqa_send_estimate_email' triggered for order ID: {$order_id}");
    $options = jfbwqa_get_options(); // Read general settings

    // Log the raw email_default_body from options now that logger is safe
    jfbwqa_write_log("DEBUG: jfbwqa_handle_order_action() - Raw email_default_body from options for order #{$order_id}: " . ($options['email_default_body'] ?? 'NOT SET'));

    // Email Config from Settings
    $subject_template = $options['email_subject']; $heading_template = $options['email_heading'];
    $reply_to_email = sanitize_email($options['email_reply_to']); $cc_email = sanitize_email($options['email_cc']);
    $body_template = $options['email_default_body'];

    // *** DEBUG LOGGING START ***
    jfbwqa_write_log("DEBUG: jfbwqa_handle_order_action() - \$body_template (from settings) BEFORE placeholder replacement for order #{$order_id}: " . $body_template);
    // *** DEBUG LOGGING END ***

    // *** NEW: Get the custom message from order meta ***
    $custom_admin_message = get_post_meta( $order_id, '_jfbwqa_custom_email_message', true );
    // *** END NEW ***

    // Get Recipient & Validate
    $recipient_email = $order->get_billing_email();
    if ( ! is_email( $recipient_email ) ) { /* ... error handling ... */
        $error_msg = sprintf(__('Failed send estimate email Order #%s: Invalid billing email.', 'jfb-wc-quotes-advanced'), $order->get_order_number());
        jfbwqa_write_log("ERROR: " . str_replace('#'.$order->get_order_number(), $order_id, $error_msg));
        $order->add_order_note( $error_msg, false, false );
        add_action('admin_notices', function() use ($error_msg) { printf('<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html($error_msg)); });
        return;
    }

    // Prepare Subject/Heading
    $replacements = ['{order_number}' => $order->get_order_number(), '{site_title}' => wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES)];
    $subject = str_replace(array_keys($replacements), array_values($replacements), $subject_template);
    $heading = str_replace(array_keys($replacements), array_values($replacements), $heading_template);

    // Process body placeholders (Make sure to remove the [Order Details Table] replacement if using the template action)
    $email_body = jfbwqa_replace_email_placeholders( $body_template, $order );
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
           'email' => $mailer
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
   8) Placeholder Replacement Function (Reads options, uses mapping JSON)
   ============================================================================= */
function jfbwqa_replace_email_placeholders( $content, $order, $show_prices = false ) { // Added $show_prices argument, default false
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
        jfbwqa_write_log("DEBUG: jfbwqa_replace_email_placeholders() - Found '{$order_details_table_placeholder}' for order #{$order_id_for_log}. Attempting to generate table.");
        ob_start();
        // Using wc_get_template_html to render the standard items table part
        $order_items = $order->get_items();
        $table_args = array(
            'order'                 => $order,
            'items'                 => $order_items,
            'show_sku'              => false, // Adjust as needed
            'show_image'            => true, // ENABLED product images
            'image_size'            => array( 32, 32 ), // Adjust as needed
            'plain_text'            => false,
            'sent_to_admin'         => false,
            'show_purchase_note'    => false, // Adjust as needed
            // 'show_prices'        => $show_prices, // Pass this to the template if it supports it directly - WC default doesn't, we control via template choice
        );
        jfbwqa_write_log("DEBUG: jfbwqa_replace_email_placeholders() - Args for email-order-items.php (order #{$order_id_for_log}): Order ID = " . $order->get_id() . ", Item count = " . count($order_items) . ", Plain text = false, Show Prices Flag = " . ($show_prices ? 'Yes' : 'No'));
        
        $template_path_override = '';
        if ( ! $show_prices ) {
            // If NOT showing prices, use our custom template that hides them.
            $template_path_override = jfbwqa_plugin_dir() . 'woocommerce/';
            jfbwqa_write_log("DEBUG: Using overridden email-order-items.php (no prices) for order #{$order_id_for_log}.");
        } else {
            jfbwqa_write_log("DEBUG: Using default email-order-items.php (with prices) for order #{$order_id_for_log}.");
        }
        // If $template_path_override is empty, wc_get_template_html will use its default lookup (theme then WC plugin).
        $order_table_html = wc_get_template_html( 'emails/email-order-items.php', $table_args, '', $template_path_override );
        
        $buffered_output = ob_get_clean(); // Get any other buffered output
        if (!empty($buffered_output)) {
            jfbwqa_write_log("DEBUG: jfbwqa_replace_email_placeholders() - Unexpected buffered output during table generation for order #{$order_id_for_log}: " . substr($buffered_output, 0, 200));
        }

        jfbwqa_write_log("DEBUG: jfbwqa_replace_email_placeholders() - HTML for order table (from wc_get_template_html) for order #{$order_id_for_log} (length: " . strlen($order_table_html) . "): " . substr($order_table_html, 0, 500) . (strlen($order_table_html) > 500 ? "..." : ""));
        
        if (empty($order_table_html)) {
            jfbwqa_write_log("WARNING: jfbwqa_replace_email_placeholders() - wc_get_template_html for 'emails/email-order-items.php' returned EMPTY for order #{$order_id_for_log}. Order items count: " . count($order_items) . ". Check if the 'emails/email-order-items.php' template exists and is readable.");
        }
        
        $content_before_table_replace = $content;
        $content = str_replace($order_details_table_placeholder, $order_table_html, $content);
        if ($content === $content_before_table_replace && !empty($order_table_html)) {
             jfbwqa_write_log("WARNING: jfbwqa_replace_email_placeholders() - str_replace for '{$order_details_table_placeholder}' did NOT change content, but table HTML was generated for order #{$order_id_for_log}. Placeholder typo or issue in original content?");
        } elseif ($content !== $content_before_table_replace) {
             jfbwqa_write_log("DEBUG: jfbwqa_replace_email_placeholders() - Successfully replaced '{$order_details_table_placeholder}' for order #{$order_id_for_log}.");
        }


    } else {
        jfbwqa_write_log("DEBUG: jfbwqa_replace_email_placeholders() - Did NOT find '{$order_details_table_placeholder}' in content for order #{$order_id_for_log}.");
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
    add_settings_section('jfbwqa_section_general', __('General Settings', 'jfb-wc-quotes-advanced'), null, JFBWQA_SETTINGS_SLUG);
    add_settings_field( 'consumer_key', __('WooCommerce Consumer Key', 'jfb-wc-quotes-advanced'), 'jfbwqa_render_field_text', JFBWQA_SETTINGS_SLUG, 'jfbwqa_section_general', ['key' => 'consumer_key', 'type' => 'text'] );
    add_settings_field( 'consumer_secret', __('WooCommerce Consumer Secret', 'jfb-wc-quotes-advanced'), 'jfbwqa_render_field_text', JFBWQA_SETTINGS_SLUG, 'jfbwqa_section_general', ['key' => 'consumer_secret', 'type' => 'password'] );
    add_settings_field( 'hook_name', __('JetFormBuilder Hook Name', 'jfb-wc-quotes-advanced'), 'jfbwqa_render_field_text', JFBWQA_SETTINGS_SLUG, 'jfbwqa_section_general', ['key' => 'hook_name', 'type' => 'text', 'desc' => __('Custom filter hook used in JFB form.', 'jfb-wc-quotes-advanced')] );
    add_settings_field( 'shortcode_name', __('Cart JSON Shortcode Tag', 'jfb-wc-quotes-advanced'), 'jfbwqa_render_field_text', JFBWQA_SETTINGS_SLUG, 'jfbwqa_section_general', ['key' => 'shortcode_name', 'type' => 'text', 'desc' => sprintf(__('Tag for shortcode like %s.', 'jfb-wc-quotes-advanced'), '<code>[your_tag_here]</code>')] );
    add_settings_field( 'jetengine_keys', __('JetEngine Meta Keys (for mapping/placeholders)', 'jfb-wc-quotes-advanced'), 'jfbwqa_render_field_textarea', JFBWQA_SETTINGS_SLUG, 'jfbwqa_section_general', ['key' => 'jetengine_keys', 'desc' => __('One key per line. Makes them available as *JE_meta*.key_name in mapping dropdowns and {[key_name]} in emails.', 'jfb-wc-quotes-advanced')] );
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
    add_settings_field( 'quote_email_default_body', __('Quote Email Default Body', 'jfb-wc-quotes-advanced'), 'jfbwqa_render_field_wp_editor', JFBWQA_SETTINGS_SLUG, 'jfbwqa_section_quote_email', ['key' => 'quote_email_default_body'] );

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
function jfbwqa_render_section_email_desc() {
     echo '<p>' . esc_html__('Customize the email sent via the order action for the initial estimate request confirmation.', 'jfb-wc-quotes-advanced') . '</p>';
     // Add placeholders list here if desired (as in v1.14 render_section_email)
}
function jfbwqa_render_section_quote_email_desc() {
    echo '<p>' . esc_html__('Customize the default content for the email sent when a quote is prepared and sent to the customer (typically from the order edit screen).', 'jfb-wc-quotes-advanced') . '</p>';
    echo '<p>' . esc_html__('Placeholders like {order_number}, {[your_jetengine_field]}, and [Order Details Table] can be used. The actual message sent can be further customized on the order edit page.', 'jfb-wc-quotes-advanced') . '</p>';
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
    // Sanitize each field registered with Settings API
    $output['consumer_key']    = sanitize_text_field($input['consumer_key'] ?? '');
    $output['consumer_secret'] = sanitize_text_field($input['consumer_secret'] ?? ''); // Basic sanitize
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
    if (isset($input['quote_email_default_body'])) $output['quote_email_default_body'] = wp_kses_post(wp_unslash($input['quote_email_default_body']));

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

/**
 * Add Custom Email Message Metabox to Order Edit Screen
 */
add_action( 'add_meta_boxes', 'jfbwqa_add_custom_email_message_metabox' );
function jfbwqa_add_custom_email_message_metabox() {
    add_meta_box(
        'jfbwqa_custom_email_message',                 // ID
        __('Custom Estimate Email Message', 'jfb-wc-quotes-advanced'), // Title
        'jfbwqa_render_custom_email_message_metabox', // Callback function
        'shop_order',                                  // Post type
        'side',                                        // Context (normal, side, advanced)
        'low'                                          // Priority
    );
}

/**
 * Render the Custom Email Message Metabox Content
 */
function jfbwqa_render_custom_email_message_metabox( $post ) {
    // Add nonce for security
    wp_nonce_field( 'jfbwqa_save_custom_message_meta', 'jfbwqa_custom_message_nonce' );

    $custom_message = get_post_meta( $post->ID, '_jfbwqa_custom_email_message', true );

    echo '<textarea id="jfbwqa_custom_email_textarea" name="jfbwqa_custom_email_message" style="width:100%; height: 150px;" placeholder="' . esc_attr__('Enter an optional custom message to include in the estimate email...', 'jfb-wc-quotes-advanced') . '">' . esc_textarea( $custom_message ) . '</textarea>';
    echo '<p class="description">' . esc_html__('This message will be included in the email sent via the "Send Estimate Request Email" order action. Leave blank to use only the default template body.', 'jfb-wc-quotes-advanced') . '</p>';
     // Optional: Add a button to clear the message after sending?
     // echo '<button type="button" id="jfbwqa_clear_custom_message" class="button button-secondary">' . __('Clear Message', 'jfb-wc-quotes-advanced') . '</button>';
}

/**
 * Save the Custom Email Message Metabox Data
 */
add_action( 'save_post_shop_order', 'jfbwqa_save_custom_email_message_meta', 10, 1 );
function jfbwqa_save_custom_email_message_meta( $post_id ) {
    // Check nonce
    if ( ! isset( $_POST['jfbwqa_custom_message_nonce'] ) || ! wp_verify_nonce( $_POST['jfbwqa_custom_message_nonce'], 'jfbwqa_save_custom_message_meta' ) ) {
        return $post_id;
    }

    // Check user permissions
    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        return $post_id;
    }

    // Check if it's an autosave
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return $post_id;
    }

    // Sanitize and save the data
    $custom_message = isset( $_POST['jfbwqa_custom_email_message'] ) ? wp_kses_post( $_POST['jfbwqa_custom_email_message'] ) : '';
    update_post_meta( $post_id, '_jfbwqa_custom_email_message', $custom_message );

    // Optional: Clear the message after saving if a flag is set (e.g., by a clear button click)
    // if (isset($_POST['jfbwqa_clear_message_flag']) && $_POST['jfbwqa_clear_message_flag'] == '1') {
    //     update_post_meta( $post_id, '_jfbwqa_custom_email_message', '');
    // }
}

/**
 * Add Meta Box for Sending Prepared Quote
 */
// add_action( 'add_meta_boxes', 'jfbwqa_add_prepared_quote_metabox' ); // Temporarily disabled
function jfbwqa_add_prepared_quote_metabox() {
    // This function is currently not used due to the action being disabled.
    // The rendering logic will be moved to jfbwqa_render_quote_controls_section
}

// Function to render the new controls area (replaces the old metabox render content)
function jfbwqa_render_quote_controls_section( $order ) {
    if ( ! $order instanceof WC_Order ) {
        // If $order is just an ID, get the order object
        $order = wc_get_order( $order );
        if ( ! $order ) {
            jfbwqa_write_log('ERROR: jfbwqa_render_quote_controls_section - Could not get order object.');
            echo '<p>Error: Could not load order data.</p>';
            return;
        }
    }
    $order_id = $order->get_id();
    jfbwqa_write_log("DEBUG: jfbwqa_render_quote_controls_section called for order ID: {$order_id}");

    // Nonce for saving these fields separately if needed, or for AJAX security
    wp_nonce_field( 'jfbwqa_save_quote_meta', 'jfbwqa_quote_meta_nonce' );

    $custom_message = get_post_meta( $order_id, '_jfbwqa_quote_custom_message', true );
    $include_pricing_value = get_post_meta( $order_id, '_jfbwqa_quote_include_pricing', true );
    // Default to 'yes' (checked) if not previously saved or if explicitly set to an empty string by previous logic
    $include_pricing = ( $include_pricing_value === '' || $include_pricing_value === 'yes' ) ? 'yes' : 'no';

    ob_start(); // Start output buffering to capture HTML for logging
    ?>
    <div class="postbox" id="jfbwqa_quote_controls_section_box">
        <h2 class="hndle ui-sortable-handle"><span><?php esc_html_e('Prepare & Send Quote Email', 'jfb-wc-quotes-advanced'); ?></span></h2>
        <div class="inside">
            <p><?php esc_html_e('Configure and send the prepared quote to the customer.', 'jfb-wc-quotes-advanced'); ?></p>

            <div id="jfbwqa_custom_quote_message_area" style="margin-bottom:15px;">
                <h4><?php esc_html_e('Custom Message (Optional)', 'jfb-wc-quotes-advanced'); ?></h4>
                <textarea id="jfbwqa_custom_quote_message" name="jfbwqa_custom_quote_message" style="width:100%; height: 100px;" placeholder="<?php esc_attr_e('This message will be added to the quote email...', 'jfb-wc-quotes-advanced'); ?>"><?php echo esc_textarea( $custom_message ); ?></textarea>
            </div>

            <div id="jfbwqa_include_pricing_area" style="margin-bottom:15px;">
                <label><input type="checkbox" id="jfbwqa_include_pricing" name="jfbwqa_include_pricing" value="yes" <?php checked( $include_pricing, 'yes' ); ?> /> <?php esc_html_e('Include Pricing in this Quote', 'jfb-wc-quotes-advanced'); ?></label>
            </div>

            <div id="jfbwqa_available_placeholders_info" style="margin-bottom:15px; padding: 10px; background-color: #f8f8f8; border: 1px solid #e5e5e5;">
                <strong><?php esc_html_e('Available Placeholders:', 'jfb-wc-quotes-advanced'); ?></strong><br>
                <code>{order_number}</code>, <code>{customer_name}</code>, <code>{customer_first_name}</code>, <code>{site_title}</code>, etc.<br>
                <?php esc_html_e('JetEngine fields: ', 'jfb-wc-quotes-advanced'); ?><code>{[your_jet_engine_field_key]}</code><br>
                <code>[Order Details Table]</code> - inserts the items table.<br>
                <code>{additional_message_from_admin}</code> - used for this custom message.
            </div>

            <div style="margin-top: 15px;">
                <button type="button" id="jfbwqa_send_quote_button" class="button button-primary"><?php esc_html_e('Send Prepared Quote Email', 'jfb-wc-quotes-advanced'); ?></button>
                <span id="jfbwqa_spinner" class="spinner" style="float:none; vertical-align: middle;"></span>
                <p class="description"><?php esc_html_e('Clicking this will save the message/settings above and trigger the email.', 'jfb-wc-quotes-advanced'); ?></p>
            </div>
             <div id="jfbwqa_send_status_message" style="margin-top: 10px; padding: 10px; display:none;"></div>
        </div>
    </div>
    <?php
    $html_output = ob_get_clean();
    echo $html_output; // Echo the actual content to the page
    jfbwqa_write_log("DEBUG: jfbwqa_render_quote_controls_section outputting HTML (approx length " . strlen($html_output) . "). Starts with: " . substr(preg_replace('/\s+/', ' ', $html_output), 0, 200));
}
// add_action( 'woocommerce_admin_order_data_after_order_details', 'jfbwqa_render_quote_controls_section', 20, 1 ); // Previous Hook
add_action( 'woocommerce_admin_order_totals_after_total', 'jfbwqa_render_quote_controls_section', 10, 1 ); // New Hook

/**
 * Save Meta Box Data for Sending Prepared Quote
 */
add_action( 'save_post_shop_order', 'jfbwqa_save_prepared_quote_meta', 10, 1 );
function jfbwqa_save_prepared_quote_meta( $post_id ) {
    // Check nonce
    if ( ! isset( $_POST['jfbwqa_quote_meta_nonce'] ) || ! wp_verify_nonce( $_POST['jfbwqa_quote_meta_nonce'], 'jfbwqa_save_quote_meta' ) ) {
        return $post_id;
    }

    // Check user permissions
    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        return $post_id;
    }

    // Check if it's an autosave
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return $post_id;
    }

    // Sanitize and save Custom Message
    $custom_message = isset( $_POST['jfbwqa_custom_quote_message'] ) ? wp_kses_post( $_POST['jfbwqa_custom_quote_message'] ) : '';
    update_post_meta( $post_id, '_jfbwqa_quote_custom_message', $custom_message );

    // Sanitize and save "Include Pricing" - store 'yes' or 'no'
    $include_pricing = isset( $_POST['jfbwqa_include_pricing'] ) ? 'yes' : 'no';
    update_post_meta( $post_id, '_jfbwqa_quote_include_pricing', $include_pricing );
}

/**
 * AJAX handler for sending the prepared quote email from the meta box button.
 */
add_action( 'wp_ajax_jfbwqa_send_quote_via_metabox', 'jfbwqa_ajax_send_quote_handler' );
function jfbwqa_ajax_send_quote_handler() {
    check_ajax_referer( 'jfbwqa_send_quote_nonce', 'security' );

    $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
    if ( !$order_id || !current_user_can('edit_shop_order', $order_id) ) {
        wp_send_json_error( ['message' => __('Error: Invalid order ID or insufficient permissions.', 'jfb-wc-quotes-advanced')] );
        return;
    }

    $order = wc_get_order($order_id);
    if ( !$order ) {
        wp_send_json_error( ['message' => __('Error: Could not retrieve order.', 'jfb-wc-quotes-advanced')] );
        return;
    }

    // Save/Update meta from the AJAX post data before sending
    $custom_message = isset( $_POST['custom_message'] ) ? wp_kses_post( stripslashes($_POST['custom_message']) ) : '';
    update_post_meta( $order_id, '_jfbwqa_quote_custom_message', $custom_message );

    $include_pricing = isset( $_POST['include_pricing'] ) && $_POST['include_pricing'] === 'true' ? 'yes' : 'no';
    update_post_meta( $order_id, '_jfbwqa_quote_include_pricing', $include_pricing );
    
    jfbwqa_write_log("AJAX: Meta updated for order #{$order_id}. Custom Msg: {$custom_message}, Pricing: {$include_pricing}");

    // Execute the order action
    // Note: WC_Order_Action_Manager->process_action() might be an alternative, but directly calling our handler is simpler here.
    jfbwqa_handle_send_prepared_quote_action($order);

    // Check for notes added by the handler to infer success/failure if direct return isn't feasible
    // For simplicity, we assume success if no WP_Error was thrown by the handler (which would terminate execution).
    // A more robust solution might involve the handler returning a status.
    wp_send_json_success( ['message' => __('Prepared Quote Email processing triggered.', 'jfb-wc-quotes-advanced')] );
}


/**
 * Enqueue admin scripts for the order edit page meta box.
 */
add_action( 'admin_enqueue_scripts', 'jfbwqa_enqueue_order_edit_scripts' );
function jfbwqa_enqueue_order_edit_scripts( $hook ) {
    global $post_type;
    if ( 'post.php' == $hook && 'shop_order' == $post_type ) {
        wp_enqueue_script(
            'jfbwqa-order-metabox-js',
            plugin_dir_url( __FILE__ ) . 'assets/js/admin-order-metabox.js',
            ['jquery'],
            JFBWQA_VERSION, // Use plugin version for cache busting
            true
        );
        wp_localize_script( 'jfbwqa-order-metabox-js', 'jfbwqa_metabox_params', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'send_quote_nonce' => wp_create_nonce( 'jfbwqa_send_quote_nonce' ),
            'sending_text' => __('Sending...', 'jfb-wc-quotes-advanced'),
            'error_text' => __('Error. See console or debug log.', 'jfb-wc-quotes-advanced'),
        ));
    }
}

?>