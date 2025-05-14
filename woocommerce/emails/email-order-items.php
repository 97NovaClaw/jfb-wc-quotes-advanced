<?php
/**
 * Email Order Items
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/emails/email-order-items.php.
 *
 * HOWEVER, on occasion WooCommerce will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @see     https://woocommerce.com/document/template-structure/
 * @package WooCommerce\Templates\Emails
 * @version 3.7.0
 */

defined( 'ABSPATH' ) || exit;

$text_align  = is_rtl() ? 'right' : 'left';
$margin_side = is_rtl() ? 'left' : 'right';

// JFBWQA: Determine if prices are being shown from the passed arguments
$show_prices = isset($show_prices) && $show_prices === true;

foreach ( $items as $item_id => $item ) :
	$product       = $item->get_product();
	$sku           = '';
	$purchase_note = '';
	$image_html    = ''; // Initialize image html

	if ( ! apply_filters( 'woocommerce_order_item_visible', true, $item ) ) {
		continue;
	}

	if ( is_object( $product ) ) {
		$sku           = $product->get_sku();
		$purchase_note = $product->get_purchase_note();
        if ( $show_image ) { // $show_image is passed from $table_args in the main plugin file
            $image_html_raw = $product->get_image( $image_size ); 
            if (strpos($image_html_raw, 'style=') !== false) {
                $image_html = str_replace('style="', 'style="margin-right:10px; vertical-align:middle; ', $image_html_raw);
            } else {
                $image_html = str_replace('<img ', '<img style="margin-right:10px; vertical-align:middle;" ', $image_html_raw);
            }
        }
	}

	?>
	<tr class="<?php echo esc_attr( apply_filters( 'woocommerce_order_item_class', 'order_item', $item, $order ) ); ?>">
		<td class="td" style="text-align:<?php echo esc_attr( $text_align ); ?>; vertical-align:middle; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif; word-wrap:break-word; <?php if (!$show_prices) echo 'width: 70%;'; /* Give more space if no price */ ?>">
		<?php
		echo wp_kses_post( apply_filters( 'woocommerce_order_item_thumbnail', $image_html, $item ) );

		// Product name.
		echo '<span style="vertical-align:middle;">' . wp_kses_post( apply_filters( 'woocommerce_order_item_name', $item->get_name(), $item, false ) ) . '</span>';

		// SKU.
		if ( $show_sku && $sku ) { // $show_sku is passed from $table_args
			echo wp_kses_post( ' (#' . $sku . ')' );
		}

		do_action( 'woocommerce_order_item_meta_start', $item_id, $item, $order, $plain_text );
		wc_display_item_meta( $item, array( 'label_before' => '<strong class="wc-item-meta-label" style="float: ' . esc_attr( $text_align ) . '; margin-' . esc_attr( $margin_side ) . ': .25em; clear: both">' ) );
		do_action( 'woocommerce_order_item_meta_end', $item_id, $item, $order, $plain_text );
		?>
		</td>
		<td class="td" style="text-align:<?php echo esc_attr( $text_align ); ?>; vertical-align:middle; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif; <?php if (!$show_prices) echo 'width: 30%;'; /* Adjust width if no price */ ?>">
			<?php
			$qty_display = esc_html( $item->get_quantity() );
			// Removed refunded qty logic for simplicity in quote context, can be added back if needed.
			echo wp_kses_post( apply_filters( 'woocommerce_email_order_item_quantity', $qty_display, $item ) );
            if (!$show_prices) { echo ' (' . esc_html__('Quantity', 'jfb-wc-quotes-advanced') . ')'; }
			?>
		</td>
        <?php if ( $show_prices ) : ?>
            <td class="td" style="text-align:<?php echo esc_attr( $text_align ); ?>; vertical-align:middle; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif;">
                <?php echo wp_kses_post( $order->get_formatted_line_subtotal( $item ) ); // This is the line item subtotal (qty x price) ?>
            </td>
        <?php endif; ?>
	</tr>
	<?php
	if ( $show_purchase_note && $purchase_note ) {
		?>
		<tr>
			<td colspan="<?php echo $show_prices ? '3' : '2'; ?>" style="text-align:<?php echo esc_attr( $text_align ); ?>; vertical-align:middle; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif;">
				<?php echo wp_kses_post( wpautop( do_shortcode( $purchase_note ) ) ); ?>
			</td>
		</tr>
		<?php
	}
	?>
<?php endforeach; ?>

<?php 
// JFBWQA: Add Order Totals if prices are shown
if ( $show_prices ) :
    $item_totals = $order->get_order_item_totals();
    if ( $item_totals ) :
        ?>
        <tfoot>
            <?php foreach ( $item_totals as $key => $total ) : ?>
                <tr>
                    <th class="td" scope="row" colspan="2" style="text-align:<?php echo esc_attr( $text_align ); ?>; <?php echo ( 'customer_note' === $key ) ? 'padding-bottom: 40px;' : ''; ?>border-top:1px solid #eee;"><?php echo esc_html( $total['label'] ); ?></th>
                    <td class="td" style="text-align:<?php echo esc_attr( $text_align ); ?>; <?php echo ( 'customer_note' === $key ) ? 'padding-bottom: 40px;' : ''; ?>border-top:1px solid #eee;"><?php echo wp_kses_post( $total['value'] ); ?></td>
                </tr>
            <?php endforeach; ?>
        </tfoot>
        <?php
    endif;
endif;
?> 