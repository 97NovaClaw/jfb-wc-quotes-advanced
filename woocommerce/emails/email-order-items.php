<?php
/**
 * Email Order Items
 *
 * v1.27 update: this template now reads a single `$jfbwqa_config` arg
 * (an array of feature toggles) instead of the v1.25 `$show_prices`
 * boolean. The new toggles let the parent renderer
 * (jfbwqa_render_order_details_table) gate columns granularly without
 * passing a stack of positional bools through.
 *
 * Honored config keys:
 *   - show_image       (gates the image column - matches the column
 *                       layout produced by the parent renderer)
 *   - show_unit_price  (gates the Unit Price column)
 *   - show_line_total  (gates the Total column)
 *
 * Back-compat: if `$jfbwqa_config` is absent and the legacy
 * `$show_prices` flag is set, behavior matches v1.25.
 *
 * Fees and shipping rows are NOT iterated here in v1.27+ - the parent
 * renderer appends them separately so this template stays focused on
 * line items.
 *
 * @package WooCommerce\Templates\Emails
 * @version 3.7.0
 */

defined( 'ABSPATH' ) || exit;

$text_align  = is_rtl() ? 'right' : 'left';
$margin_side = is_rtl() ? 'left' : 'right';

// v1.27: prefer the new config dict; fall back to the legacy show_prices bool.
$jfbwqa_config = isset( $jfbwqa_config ) && is_array( $jfbwqa_config ) ? $jfbwqa_config : array();
$cfg_show_image      = isset( $jfbwqa_config['show_image'] ) ? (bool) $jfbwqa_config['show_image'] : ( isset( $show_image ) && $show_image );
$cfg_show_unit_price = isset( $jfbwqa_config['show_unit_price'] ) ? (bool) $jfbwqa_config['show_unit_price'] : ( isset( $show_prices ) && $show_prices );
$cfg_show_line_total = isset( $jfbwqa_config['show_line_total'] ) ? (bool) $jfbwqa_config['show_line_total'] : ( isset( $show_prices ) && $show_prices );

foreach ( $items as $item_id => $item ) :
	$product       = $item->get_product();
	$sku           = '';
	$purchase_note = '';
	$image_html    = '';

	if ( ! apply_filters( 'woocommerce_order_item_visible', true, $item ) ) {
		continue;
	}

	if ( is_object( $product ) ) {
		$sku           = $product->get_sku();
		$purchase_note = $product->get_purchase_note();
		if ( $cfg_show_image && $product->get_image_id() ) {
			$image_html_raw = $product->get_image( $image_size );
			if ( strpos( $image_html_raw, 'style=' ) !== false ) {
				$image_html = str_replace( 'style="', 'style="display:block; margin:0 auto; vertical-align:middle; ', $image_html_raw );
			} else {
				$image_html = str_replace( '<img ', '<img style="display:block; margin:0 auto; vertical-align:middle;" ', $image_html_raw );
			}
		}
	}

	?>
	<tr class="<?php echo esc_attr( apply_filters( 'woocommerce_order_item_class', 'order_item', $item, $order ) ); ?>">
		<?php // Column 1: Photo (gated by show_image) ?>
		<?php if ( $cfg_show_image ) : ?>
			<td class="td" style="text-align:center; vertical-align:middle; padding:8px; border: 1px solid #eee; width:<?php echo esc_attr( $image_size[0] + 10 ); ?>px;">
				<?php if ( $image_html ) {
					echo wp_kses_post( apply_filters( 'woocommerce_order_item_thumbnail', $image_html, $item ) );
				} ?>
			</td>
		<?php endif; ?>
		<?php // Column 2: Product Name, SKU, Meta ?>
		<td class="td" style="text-align:<?php echo esc_attr( $text_align ); ?>; vertical-align:middle; padding:8px; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif; word-wrap:break-word; border: 1px solid #eee;">
			<?php
			echo wp_kses_post( apply_filters( 'woocommerce_order_item_name', $item->get_name(), $item, false ) );
			if ( $show_sku && $sku ) {
				echo wp_kses_post( ' (#' . $sku . ')' );
			}
			do_action( 'woocommerce_order_item_meta_start', $item_id, $item, $order, $plain_text );
			wc_display_item_meta(
				$item,
				array(
					'label_before' => '<strong class="wc-item-meta-label" style="font-size:small; display:block; margin-top:0.25em;">',
					'autop'        => true,
					'separator'    => '<br />',
				)
			);
			do_action( 'woocommerce_order_item_meta_end', $item_id, $item, $order, $plain_text );
			?>
		</td>
		<?php // Column 3: Quantity ?>
		<td class="td" style="text-align:<?php echo esc_attr( $text_align ); ?>; vertical-align:middle; padding:8px; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif; border: 1px solid #eee; <?php echo ( $cfg_show_unit_price || $cfg_show_line_total ) ? 'width:15%' : 'width:auto'; ?>">
			<?php
			$qty_display = esc_html( $item->get_quantity() );
			echo wp_kses_post( apply_filters( 'woocommerce_email_order_item_quantity', $qty_display, $item ) );
			if ( ! $cfg_show_unit_price && ! $cfg_show_line_total ) {
				echo ' <span style="font-style:italic;">(' . esc_html__( 'Quantity', 'jfb-wc-quotes-advanced' ) . ')</span>';
			}
			?>
		</td>
		<?php // Column 4: Unit Price (config-gated) ?>
		<?php if ( $cfg_show_unit_price && $item->get_quantity() > 0 ) : ?>
			<td class="td" style="text-align:<?php echo esc_attr( $text_align ); ?>; vertical-align:middle; padding:8px; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif; border: 1px solid #eee; width:20%;">
				<?php
				$quantity = $item->get_quantity();
				if ( $quantity > 0 ) {
					$display_prices_including_tax = get_option( 'woocommerce_tax_display_cart' ) === 'incl';
					if ( $display_prices_including_tax ) {
						$line_total_with_tax = $item->get_total() + $item->get_total_tax();
						$unit_price          = $line_total_with_tax / $quantity;
					} else {
						$line_total = $item->get_total();
						$unit_price = $line_total / $quantity;
					}
					echo wp_kses_post( wc_price( $unit_price ) );
				} else {
					echo wp_kses_post( wc_price( 0 ) );
				}
				?>
			</td>
		<?php endif; ?>
		<?php // Column 5: Line Total (config-gated) ?>
		<?php if ( $cfg_show_line_total ) : ?>
			<td class="td" style="text-align:<?php echo esc_attr( $text_align ); ?>; vertical-align:middle; padding:8px; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif; border: 1px solid #eee; width:25%;">
				<?php echo wp_kses_post( $order->get_formatted_line_subtotal( $item ) ); ?>
			</td>
		<?php endif; ?>
	</tr>
	<?php
	if ( $show_purchase_note && $purchase_note ) {
		$colspan = ( $cfg_show_image ? 1 : 0 ) + 2 /* product + qty */
		         + ( $cfg_show_unit_price ? 1 : 0 )
		         + ( $cfg_show_line_total ? 1 : 0 );
		?>
		<tr>
			<td colspan="<?php echo (int) $colspan; ?>" style="text-align:<?php echo esc_attr( $text_align ); ?>; vertical-align:middle; padding:8px; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif; border: 1px solid #eee;">
				<?php echo wp_kses_post( wpautop( do_shortcode( $purchase_note ) ) ); ?>
			</td>
		</tr>
		<?php
	}
	?>
<?php endforeach; ?>

<?php
// v1.27 NOTE: Order totals (subtotal/discount/tax/grand-total) and the
// fee/shipping body rows are now rendered by the parent function
// (jfbwqa_render_order_details_table) and the helper functions
// jfbwqa_render_fee_rows_html / jfbwqa_render_shipping_rows_html.
// This template is responsible only for the line-item rows.
?>
