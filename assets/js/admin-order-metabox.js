jQuery(document).ready(function($) {
    $('#jfbwqa_send_quote_button').on('click', function() {
        var $button = $(this);
        var originalButtonText = $button.text();
        var orderId = $('#post_ID').val(); // Get Order ID from the standard WordPress post ID field

        var customMessage = $('#jfbwqa_custom_quote_message').val();
        var includePricing = $('#jfbwqa_include_pricing').is(':checked');

        // Add a status message area if it doesn't exist
        if ($('#jfbwqa_send_status_message').length === 0) {
            $button.after('<div id="jfbwqa_send_status_message" style="margin-top: 5px; padding: 5px;"></div>');
        }
        var $statusMessage = $('#jfbwqa_send_status_message');

        $button.text(jfbwqa_metabox_params.sending_text).prop('disabled', true);
        $statusMessage.text('').removeClass('notice-success notice-error').hide();

        $.ajax({
            url: jfbwqa_metabox_params.ajax_url,
            type: 'POST',
            data: {
                action: 'jfbwqa_send_quote_via_metabox',
                security: jfbwqa_metabox_params.send_quote_nonce,
                order_id: orderId,
                custom_message: customMessage,
                include_pricing: includePricing
            },
            success: function(response) {
                if (response.success) {
                    $statusMessage.text(response.data.message).addClass('notice-success').show();
                    // Optionally, clear the custom message field or update UI
                    // $('#jfbwqa_custom_quote_message').val(''); 
                } else {
                    var errorMessage = response.data && response.data.message ? response.data.message : jfbwqa_metabox_params.error_text;
                    $statusMessage.text(errorMessage).addClass('notice-error').show();
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.error("JFBWQA AJAX Error:", textStatus, errorThrown, jqXHR.responseText);
                $statusMessage.text(jfbwqa_metabox_params.error_text + ' (' + textStatus + ')').addClass('notice-error').show();
            },
            complete: function() {
                $button.text(originalButtonText).prop('disabled', false);
            }
        });
    });
}); 