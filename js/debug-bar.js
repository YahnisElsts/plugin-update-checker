jQuery(function($) {

	function runAjaxAction(button, action) {
		button = $(button);
		var panel = button.closest('.puc-debug-bar-panel');
		var responseBox = button.closest('td').find('.puc-ajax-response');

		responseBox.text('Processing...').show();
		$.post(
			ajaxurl,
			{
				action  : action,
				slug    : panel.data('slug'),
				_wpnonce: panel.data('nonce')
			},
			function(data) {
				responseBox.html(data);
			},
			'html'
		);
	}

	$('.puc-debug-bar-panel input[name="puc-check-now-button"]').click(function() {
		runAjaxAction(this, 'puc_debug_check_now');
		return false;
	});

	$('.puc-debug-bar-panel input[name="puc-request-info-button"]').click(function() {
		runAjaxAction(this, 'puc_debug_request_info');
		return false;
	});
});