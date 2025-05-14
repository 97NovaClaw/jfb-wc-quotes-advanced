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
        if ( isset($show_image) && $show_image && $product->get_image_id() ) { 
            $image_html_raw = $product->get_image( $image_size ); 
            if (strpos($image_html_raw, 'style=') !== false) {
                $image_html = str_replace('style="', 'style="display:block; margin:0 auto; vertical-align:middle; ', $image_html_raw);
            } else {
                $image_html = str_replace('<img ', '<img style="display:block; margin:0 auto; vertical-align:middle;" ', $image_html_raw);
            }
        }
	}

	?>
	<tr class="<?php echo esc_attr( apply_filters( 'woocommerce_order_item_class', 'order_item', $item, $order ) ); ?>">
        <?php // Column 1: Photo ?>
        <td class="td" style="text-align:center; vertical-align:middle; padding:8px; border: 1px solid #eee; width:<?php echo esc_attr($image_size[0] + 10); ?>px;">
            <?php 
            if ($image_html) {
                echo wp_kses_post( apply_filters( 'woocommerce_order_item_thumbnail', $image_html, $item ) );
            }
            ?>
        </td>
        <?php // Column 2: Product Name, SKU, Meta ?>
		<td class="td" style="text-align:<?php echo esc_attr( $text_align ); ?>; vertical-align:middle; padding:8px; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif; word-wrap:break-word; border: 1px solid #eee;">
            <?php 
            echo wp_kses_post( apply_filters( 'woocommerce_order_item_name', $item->get_name(), $item, false ) ); 
            if ( $show_sku && $sku ) {
                echo wp_kses_post( ' (#' . $sku . ')' );
            }
            do_action( 'woocommerce_order_item_meta_start', $item_id, $item, $order, $plain_text );
            wc_display_item_meta( $item, array( 
                'label_before' => '<strong class="wc-item-meta-label" style="font-size:small; display:block; margin-top:0.25em;">', 
                'autop' => true, 
                'separator' => '<br />' 
            ) );
            do_action( 'woocommerce_order_item_meta_end', $item_id, $item, $order, $plain_text );
            ?>
		</td>
        <?php // Column 3: Quantity ?>
		<td class="td" style="text-align:<?php echo esc_attr( $text_align ); ?>; vertical-align:middle; padding:8px; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif; border: 1px solid #eee; <?php echo $show_prices ? 'width:15%' : 'width:auto';?>">
			<?php
			$qty_display = esc_html( $item->get_quantity() );
			echo wp_kses_post( apply_filters( 'woocommerce_email_order_item_quantity', $qty_display, $item ) );
            if (!$show_prices) { echo ' <span style="font-style:italic;">(' . esc_html__('Quantity', 'jfb-wc-quotes-advanced') . ')</span>'; }
			?>
		</td>
        <?php // Column 4: Price (Conditional) ?>
        <?php if ( $show_prices ) : ?>
            <td class="td" style="text-align:<?php echo esc_attr( $text_align ); ?>; vertical-align:middle; padding:8px; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif; border: 1px solid #eee; width:25%;">
                <?php echo wp_kses_post( $order->get_formatted_line_subtotal( $item ) ); ?>
            </td>
        <?php endif; ?>
	</tr>
	<?php
	if ( $show_purchase_note && $purchase_note ) {
		?>
		<tr>
			<td colspan="<?php echo $show_prices ? '4' : '3'; ?>" style="text-align:<?php echo esc_attr( $text_align ); ?>; vertical-align:middle; padding:8px; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif; border: 1px solid #eee;">
				<?php echo wp_kses_post( wpautop( do_shortcode( $purchase_note ) ) ); ?>
			</td>
		</tr>
		<?php
	}
	?>
<?php endforeach; ?>

<?php 
// JFBWQA: Order Totals are now handled by the calling function (jfbwqa_replace_email_placeholders)
// which will build the full table structure including thead, tbody, and tfoot.
?> 