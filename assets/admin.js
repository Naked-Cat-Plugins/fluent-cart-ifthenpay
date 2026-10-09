/**
 * The backend javascript
 */

(function ( $ ) {

	// Webhook activation
	$( 'body' ).on( 'click', '#ifthenpay-activate-webhook', function ( e ) {
		e.preventDefault();
		
		var $button = $( this );

		// Show loading state
		$button.addClass( 'is-loading' );

		// Gather data
		var gateway     = $button.data( 'gateway' );
		var ent         = $button.data( 'ent' );
		var subent      = $button.data( 'subent' );
		var bo_key      = $.trim( prompt( ifthenpayFluentCart.text_enter_bo_key + ' ' + ent + ' - ' + subent + ':' ) );

		if ( bo_key ) {

			$.ajax( {
				url: ajaxurl,
				type: 'POST',
				data: {
					action: 'ifthenpay_fluentcart_activate_webhook',
					gateway: gateway,
					ent: ent,
					subent: subent,
					bo_key: bo_key,
					nonce: ifthenpayFluentCart.nonce
				},
				success: function ( response ) {
					if ( response.success ) {
						alert( response.data );
						window.location.reload();
					} else {
						alert( response.data );
					}
				},
				error: function () {
					alert( 'Connection error occurred' );
				}
			} );
		}

		/*
		// Make AJAX request to activate webhook
		$.post( ajaxurl, {
			action: 'ifthenpay_activate_webhook',
			gateway: gateway
		}).done( function ( response ) {
			// Handle success
			if ( response.success ) {
				alert( 'Webhook activated successfully!' );
			} else {
				alert( 'Failed to activate webhook: ' + response.data );
			}
		}).fail( function () {
			alert( 'Error occurred while activating webhook.' );
		}).always( function () {
			// Reset button state
			$button.removeClass( 'is-loading' );
		});
		*/

		// Remove loading state
		$button.removeClass( 'is-loading' );
	});

	// Simulate callback payment (testing tool on the order screen, only shown while debugging)
	$( 'body' ).on( 'click', '.ifthenpay-simulate-callback', function ( e ) {
		e.preventDefault();
		if ( ! confirm( ifthenpayFluentCart.text_simulate ) ) {
			return;
		}
		$.get( $( this ).data( 'url' ) ).done( function ( response ) {
			alert( response && response.message ? response.message : '' );
			window.location.reload();
		} ).fail( function ( xhr ) {
			alert( ifthenpayFluentCart.text_simulate_err + ( xhr.responseJSON && xhr.responseJSON.message ? ': ' + xhr.responseJSON.message : '' ) );
		} );
	} );

})( jQuery );