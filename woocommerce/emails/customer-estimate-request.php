<?php
/**
 * Customer Estimate Request Email Template
 *
 * Based on WooCommerce customer-processing-order template.
 * This template can be overridden by copying it to yourtheme/woocommerce/emails/customer-estimate-request.php.
 * 
 * This template handles emails sent to customers when they request a quote/estimate.
 * It supports JetEngine custom fields and dynamic content from the plugin settings.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/*
 * @hooked WC_Emails::email_header() Output the email header
 */
do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<?php /* Custom opening paragraph */ ?>
<p><?php printf( esc_html__( 'Hi %s,', 'jfb-wc-quotes-advanced' ), esc_html( $order->get_billing_first_name() ) ); ?></p>
<?php /* Remove the static text below if it's already in your default body */ ?>
<?php /* <p><?php esc_html_e( 'Here are the details for your estimate request:', 'jfb-wc-quotes-advanced' ); ?></p> */ ?>
<?php /* End custom opening */ ?>

<?php
// *** THIS IS THE CRUCIAL PART TO DISPLAY YOUR MAIN BODY (INCLUDING THE TABLE) ***
if ( ! empty( $email_body_content ) ) {
    // email_body_content already has placeholders (like [Order Details Table]) replaced
    // wpautop adds paragraph tags based on newlines in your setting for better formatting.
    // Removed wpautop as it can break HTML from placeholders like the order table
    echo wp_kses_post( wptexturize( $email_body_content ) ); 
}
?>

<?php
/*
 * @hooked WC_Emails::order_details() Shows the order details table.
 * @hooked WC_Structured_Data::generate_order_data() Generates structured data.
 * @hooked WC_Structured_Data::output_structured_data() Outputs structured data.
 * @since 2.5.0
 */
// IMPORTANT: If your $email_body_content contains '[Order Details Table]',
// then the table is ALREADY included from the placeholder replacement.
// Calling do_action below would duplicate it.
// If you remove '[Order Details Table]' from your settings, then uncomment the line below.
// For now, assuming '[Order Details Table]' is in your settings, so this is commented out.
// do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
?>

<?php
/*
 * @hooked WC_Emails::order_meta() Shows order meta data.
 */
// You might want to hide default order meta if it's not relevant for a quote
// remove_action( 'woocommerce_email_order_meta', array( 'WC_Emails', 'order_meta' ), 10 ); // Example of removing default meta display
// Instead, display custom JE meta below or within the message body
// do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );
?>

<?php
/*
 * Display Custom Admin Message (Passed as $additional_content)
 */
if ( !empty( $additional_content ) ) {
     // $additional_content is already run through wpautop(wptexturize()) in the handler
     echo '<div style="margin-top:15px; padding-top:15px; border-top:1px solid #eee;">';
     echo '<h2>' . esc_html__( 'Message from Admin', 'jfb-wc-quotes-advanced' ) . '</h2>';
     echo wp_kses_post( $additional_content );
     echo '</div>';
}
?>

<?php
/*
 * Display Custom JE Fields (Optional - This section seems fine but might be redundant if placeholders are used)
 */
 $je_keys_string = ''; // Initialize
 $jfbwqa_options = function_exists('jfbwqa_get_options') ? jfbwqa_get_options() : null;
 if ($jfbwqa_options && isset($jfbwqa_options['jetengine_keys'])) {
    $je_keys_string = $jfbwqa_options['jetengine_keys'];
 }

 $je_keys = !empty($je_keys_string) ? preg_split( '/\r\n|\r|\n/', trim($je_keys_string) ) : [];

 if (!empty($je_keys)) {
    $show_je_details_section = false; // Flag to check if any JE meta has value
    $je_meta_table_content = ''; // Build content first

    foreach ($je_keys as $key) {
        $trimmed_key = trim($key);
        if (empty($trimmed_key)) continue;
        $value = $order->get_meta( $trimmed_key );
        if ( !empty( $value ) ) {
             $show_je_details_section = true;
             $label = ucwords( str_replace( '_', ' ', $trimmed_key ) );
             $je_meta_table_content .= '<tr><th scope="row" style="text-align:left; border: 1px solid #eee; padding: 12px; border-top: none;">' . esc_html( $label ) . ':</th>';
             $je_meta_table_content .= '<td style="text-align:left; border: 1px solid #eee; padding: 12px; border-top: none;">' . wp_kses_post( nl2br( esc_html( is_array($value) ? implode(', ', $value) : $value ) ) ) . '</td></tr>';
        }
    }

    if ($show_je_details_section) {
        echo '<div style="margin-top:15px; padding-top:15px; border-top:1px solid #eee;">';
        echo '<h2>' . esc_html__( 'Additional Details', 'jfb-wc-quotes-advanced' ) . '</h2>';
        echo '<table cellspacing="0" cellpadding="0" style="width: 100%; font-family: \'Helvetica Neue\', Helvetica, Roboto, Arial, sans-serif; margin-bottom: 20px; border-collapse: collapse;" border="0">'; // Removed outer border, styling per row
        echo '<tbody>';
        echo $je_meta_table_content;
        echo '</tbody>';
        echo '</table>';
        echo '</div>';
    }
 }
?>

<?php
/*
 * @hooked WC_Emails::customer_details() Shows customer details
 * @hooked WC_Emails::email_address() Shows email address
 */
// This will output the billing address by default.
// Ensure it's not duplicating what you might already have if $email_body_content contains address details.
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );
?>

<?php
/*
 * @hooked WC_Emails::email_footer() Output the email footer
 */
do_action( 'woocommerce_email_footer', $email );