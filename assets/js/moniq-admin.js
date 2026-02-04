jQuery(document).ready(function($) {
    $('#moniq-test-connection').on('click', function() {
        var $button = $(this);
        var $original_text = $button.text();
        var $spinner_html = '<span class="spinner is-active" style="float: none; vertical-align: middle; margin: 0 5px 0 0;"></span>';
        var $message_area = $('#moniq-test-connection-message');

        if ($message_area.length === 0) {
            $button.after('<div id="moniq-test-connection-message" style="margin-top: 10px;"></div>');
            $message_area = $('#moniq-test-connection-message');
        }

        $message_area.empty();
        $button.html($spinner_html + moniq_admin_params.testing_message).prop('disabled', true);

        $.ajax({
            url: moniq_admin_params.ajax_url,
            type: 'POST',
            data: {
                action: 'moniq_test_connection',
                nonce: moniq_admin_params.nonce
            },
            success: function(response) {
                if (response.success) {
                    $message_area.html('<p style="color: green;">' + moniq_admin_params.success_message + '</p>');
                } else {
                    var errorMsg = response.data && response.data.message ? response.data.message : 'Unknown error.';
                    $message_area.html('<p style="color: red;">' + moniq_admin_params.failure_message + errorMsg + '</p>');
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                $message_area.html('<p style="color: red;">' + moniq_admin_params.failure_message + textStatus + ' - ' + errorThrown + '</p>');
            },
            complete: function() {
                $button.html($original_text).prop('disabled', false);
            }
        });
    });
});
