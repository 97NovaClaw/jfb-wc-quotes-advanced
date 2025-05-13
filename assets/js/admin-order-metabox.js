jQuery(document).ready(function($) {
    console.log('JFBWQA: admin-order-metabox.js (modal version) loaded successfully.');

    var $modal = $('#jfbwqa-quote-response-modal');
    var $openModalButton = $('#jfbwqa_open_quote_modal_button');
    var $closeModalButton = $('#jfbwqa-modal-close');
    var $sendEmailInModalButton = $('#jfbwqa_send_quote_button_modal');

    console.log('JFBWQA: Modal element:', $modal.length, 'Open button:', $openModalButton.length);

    if ($openModalButton.length > 0 && $modal.length > 0) {
        $openModalButton.on('click', function() {
            console.log('JFBWQA: Open Estimate Response Modal button clicked.');
            // Optionally, load current values into modal fields if they could be stale
            // For now, assuming PHP populates them correctly on page load.
            $modal.fadeIn(200);
        });
        console.log('JFBWQA: Click handler attached to Open Modal button.');
    } else {
        if ($openModalButton.length === 0) console.warn('JFBWQA: Open Modal button (#jfbwqa_open_quote_modal_button) not found.');
        if ($modal.length === 0) console.warn('JFBWQA: Modal element (#jfbwqa-quote-response-modal) not found.');
    }

    if ($closeModalButton.length > 0 && $modal.length > 0) {
        $closeModalButton.on('click', function() {
            console.log('JFBWQA: Modal close button clicked.');
            $modal.fadeOut(200);
        });
        // Also close if clicking on the background overlay
        $modal.on('click', function(e) {
            if (e.target === this) { // if the click target is the modal background itself
                console.log('JFBWQA: Modal background clicked, closing modal.');
                $modal.fadeOut(200);
            }
        });
        console.log('JFBWQA: Click handlers attached to Modal close mechanisms.');
    }

    if ($sendEmailInModalButton.length > 0) {
        $sendEmailInModalButton.on('click', function() {
            console.log('JFBWQA: Send Email button inside modal clicked.');
            var $button = $(this);
            var originalButtonText = $button.text();
            var orderId = $('#post_ID').val();
            console.log('JFBWQA: Order ID:', orderId);

            var customMessage = $('#jfbwqa_custom_quote_message_modal').val();
            var includePricing = $('#jfbwqa_include_pricing_modal').is(':checked');
            console.log('JFBWQA: Modal - Custom Message:', customMessage, 'Include Pricing:', includePricing);

            var $statusMessage = $('#jfbwqa_send_status_message_modal');
            var $spinner = $('#jfbwqa_spinner_modal');

            $button.text(jfbwqa_metabox_params.sending_text).prop('disabled', true);
            $spinner.addClass('is-active');
            $statusMessage.text('').removeClass('notice-success notice-error notice-warning').hide();

            console.log('JFBWQA: Modal - Initiating AJAX call to send quote.');

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
                    console.log('JFBWQA: Modal - AJAX success:', response);
                    if (response.success) {
                        $statusMessage.html(response.data.message).removeClass('notice-error notice-warning').addClass('notice-success notice is-dismissible').show();
                        // setTimeout(function(){ $modal.fadeOut(200); }, 3000); // Optionally close modal on success after a delay
                    } else {
                        var errorMessage = response.data && response.data.message ? response.data.message : jfbwqa_metabox_params.error_text;
                        $statusMessage.html(errorMessage).removeClass('notice-success notice-warning').addClass('notice-error notice is-dismissible').show();
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    console.error("JFBWQA: Modal - AJAX Error - Status:", textStatus, "Error Thrown:", errorThrown, "Response:", jqXHR.responseText);
                    $statusMessage.html(jfbwqa_metabox_params.error_text + ' (' + textStatus + ')').removeClass('notice-success notice-warning').addClass('notice-error notice is-dismissible').show();
                },
                complete: function() {
                    console.log('JFBWQA: Modal - AJAX call complete.');
                    $button.text(originalButtonText).prop('disabled', false);
                    $spinner.removeClass('is-active');
                }
            });
        });
        console.log('JFBWQA: Click handler attached to Send Email button in modal.');
    } else {
        console.warn('JFBWQA: Send Email button in modal (#jfbwqa_send_quote_button_modal) not found.');
    }
}); 