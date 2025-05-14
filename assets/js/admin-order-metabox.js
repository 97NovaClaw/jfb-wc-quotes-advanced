jQuery(document).ready(function($) {
    console.log('JFBWQA: admin-order-metabox.js (modal version with delegated event) loaded. AJAX PART IS NOW HANDLED BY INLINE VANILLA JS.');

    var $modal = $('#jfbwqa-quote-response-modal');
    
    if ($modal.length === 0) {
        console.warn('JFBWQA: Modal element (#jfbwqa-quote-response-modal) not found. Modal cannot be opened by this script.');
        return; 
    }
    console.log('JFBWQA: Modal element found by jQuery script (though display is handled by vanilla JS).');

    // Delegated event handler for opening the modal - THIS IS HANDLED BY INLINE VANILLA JS NOW
    // $(document).on('click', '#jfbwqa_open_quote_modal_button', function(e) {
    //     e.preventDefault(); 
    //     console.log('JFBWQA jQuery: Open Estimate Response Modal button clicked (delegated).');
    //     $modal.fadeIn(200);
    // });
    // console.log('JFBWQA jQuery: Delegated click handler for open button commented out.');

    // Modal Close Button - THIS IS HANDLED BY INLINE VANILLA JS NOW
    // $('#jfbwqa-modal-close').on('click', function() {
    //     console.log('JFBWQA jQuery: Modal close button clicked.');
    //     $modal.fadeOut(200);
    // });

    // Click on overlay to close - THIS IS HANDLED BY INLINE VANILLA JS NOW
    // $modal.on('click', function(e) {
    //     if (e.target === this) {
    //         console.log('JFBWQA jQuery: Modal background clicked, closing modal.');
    //         $modal.fadeOut(200);
    //     }
    // });
    // console.log('JFBWQA jQuery: Click handlers for modal close mechanisms commented out.');

    // Send Email button inside the modal - THIS AJAX LOGIC IS NOW INLINE VANILLA JS
    /*
    var $sendEmailInModalButton = $('#jfbwqa_send_quote_button_modal');
    if ($sendEmailInModalButton.length > 0) {
        $sendEmailInModalButton.on('click', function() {
            console.log('JFBWQA jQuery: Send Email button inside modal clicked.');
            var $button = $(this);
            var originalButtonText = $button.text();
            var orderId = $('#post_ID').val();
            console.log('JFBWQA jQuery: Order ID:', orderId);

            var customMessage = $('#jfbwqa_custom_quote_message_modal').val();
            var includePricing = $('#jfbwqa_include_pricing_modal').is(':checked');
            console.log('JFBWQA jQuery: Modal - Custom Message:', customMessage, 'Include Pricing:', includePricing);

            var $statusMessage = $('#jfbwqa_send_status_message_modal');
            var $spinner = $('#jfbwqa_spinner_modal');

            $button.text(jfbwqa_metabox_params.sending_text).prop('disabled', true);
            $spinner.addClass('is-active');
            $statusMessage.text('').removeClass('notice-success notice-error notice-warning').hide();

            console.log('JFBWQA jQuery: Modal - Initiating AJAX call to send quote.');

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
                    console.log('JFBWQA jQuery: Modal - AJAX success:', response);
                    if (response.success) {
                        $statusMessage.html(response.data.message).removeClass('notice-error notice-warning').addClass('notice-success notice is-dismissible').show();
                    } else {
                        var errorMessage = response.data && response.data.message ? response.data.message : jfbwqa_metabox_params.error_text;
                        $statusMessage.html(errorMessage).removeClass('notice-success notice-warning').addClass('notice-error notice is-dismissible').show();
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    console.error("JFBWQA jQuery: Modal - AJAX Error - Status:", textStatus, "Error Thrown:", errorThrown, "Response:", jqXHR.responseText);
                    $statusMessage.html(jfbwqa_metabox_params.error_text + ' (' + textStatus + ')').removeClass('notice-success notice-warning').addClass('notice-error notice is-dismissible').show();
                },
                complete: function() {
                    console.log('JFBWQA jQuery: Modal - AJAX call complete.');
                    $button.text(originalButtonText).prop('disabled', false);
                    $spinner.removeClass('is-active');
                }
            });
        });
        console.log('JFBWQA jQuery: Click handler attached to Send Email button in modal.');
    } else {
        console.warn('JFBWQA jQuery: Send Email button in modal (#jfbwqa_send_quote_button_modal) not found.');
    }
    */
}); 