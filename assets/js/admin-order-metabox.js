jQuery(document).ready(function($) {
    console.log('JFBWQA: admin-order-metabox.js loaded successfully.');

    var $sendQuoteButton = $('#jfbwqa_send_quote_button');
    console.log('JFBWQA: Send Quote button element found in DOM:', $sendQuoteButton.length, $sendQuoteButton);

    if ($sendQuoteButton.length > 0) {
        $sendQuoteButton.on('click', function() {
            console.log('JFBWQA: Send Quote button clicked.');
            var $button = $(this);
            var originalButtonText = $button.text();
            var orderId = $('#post_ID').val();
            console.log('JFBWQA: Order ID:', orderId);

            var customMessage = $('#jfbwqa_custom_quote_message').val();
            var includePricing = $('#jfbwqa_include_pricing').is(':checked');
            console.log('JFBWQA: Custom Message:', customMessage, 'Include Pricing:', includePricing);

            var $statusMessage = $('#jfbwqa_send_status_message');
            if ($statusMessage.length === 0) {
                // If the status message div wasn't in the initially rendered HTML (e.g., if PHP part failed)
                // $button.after('<div id="jfbwqa_send_status_message" style="margin-top: 10px; padding: 10px; display:none;"></div>');
                // $statusMessage = $('#jfbwqa_send_status_message');
                // For now, assume it was rendered by PHP; if not, AJAX success/error might not show status.
                console.warn('JFBWQA: Status message element #jfbwqa_send_status_message not found initially.');
            }
            var $spinner = $('#jfbwqa_spinner');

            $button.text(jfbwqa_metabox_params.sending_text).prop('disabled', true);
            $spinner.addClass('is-active');
            $statusMessage.text('').removeClass('notice-success notice-error notice-warning').hide();

            console.log('JFBWQA: Initiating AJAX call to send quote.', jfbwqa_metabox_params);

            $.ajax({
                url: jfbwqa_metabox_params.ajax_url,
                type: 'POST',
                data: {
                    action: 'jfbwqa_send_quote_via_metabox',
                    security: jfbwqa_metabox_params.send_quote_nonce,
                    order_id: orderId,
                    custom_message: customMessage,
                    include_pricing: includePricing // Will be true or false
                },
                success: function(response) {
                    console.log('JFBWQA: AJAX success:', response);
                    if (response.success) {
                        $statusMessage.html(response.data.message).removeClass('notice-error notice-warning').addClass('notice-success notice is-dismissible').show();
                    } else {
                        var errorMessage = response.data && response.data.message ? response.data.message : jfbwqa_metabox_params.error_text;
                        $statusMessage.html(errorMessage).removeClass('notice-success notice-warning').addClass('notice-error notice is-dismissible').show();
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    console.error("JFBWQA: AJAX Error - Status:", textStatus, "Error Thrown:", errorThrown, "Response:", jqXHR.responseText);
                    $statusMessage.html(jfbwqa_metabox_params.error_text + ' (' + textStatus + ')').removeClass('notice-success notice-warning').addClass('notice-error notice is-dismissible').show();
                },
                complete: function() {
                    console.log('JFBWQA: AJAX call complete.');
                    $button.text(originalButtonText).prop('disabled', false);
                    $spinner.removeClass('is-active');
                }
            });
        });
        console.log('JFBWQA: Click handler attached to Send Quote button.');
    } else {
        console.warn('JFBWQA: Send Quote button (#jfbwqa_send_quote_button) not found. Click handler not attached.');
    }
}); 