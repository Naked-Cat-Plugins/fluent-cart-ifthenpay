/**
 * The backend javascript
 */

(function ( $ ) {

	// Backoffice Key box, shared by all our payment methods
	function backofficeKeyMessage( $box, text, isError ) {
		$box.find( '.ifthenpay-backoffice-key-message' ).text( text || '' ).toggleClass( 'is-error', !! isError );
	}

	function backofficeKeyRequest( $box, data ) {
		return $.post( ajaxurl, $.extend( { action: 'ifthenpay_fluentcart_backoffice_key', nonce: ifthenpayFluentCart.nonce }, data ) ).done( function ( response ) {
			if ( response.success ) {
				$box.find( '.ifthenpay-backoffice-key-masked' ).text( response.data.masked );
				$box.find( '.ifthenpay-backoffice-key-input' ).val( '' );
				$box.attr( 'data-saved', response.data.masked ? 'yes' : 'no' ).removeClass( 'is-editing' );
				backofficeKeyMessage( $box, response.data.message, false );
			} else {
				backofficeKeyMessage( $box, response.data, true );
			}
		} ).fail( function () {
			backofficeKeyMessage( $box, 'Connection error occurred', true );
		} );
	}

	$( 'body' ).on( 'click', '.ifthenpay-backoffice-key-save', function ( e ) {
		e.preventDefault();
		var $box = $( this ).closest( '.ifthenpay-backoffice-key' );
		backofficeKeyRequest( $box, { operation: 'save', bo_key: $.trim( $box.find( '.ifthenpay-backoffice-key-input' ).val() ) } );
	} );

	$( 'body' ).on( 'keydown', '.ifthenpay-backoffice-key-input', function ( e ) {
		if ( e.key === 'Enter' ) {
			e.preventDefault();
			$( this ).closest( '.ifthenpay-backoffice-key' ).find( '.ifthenpay-backoffice-key-save' ).trigger( 'click' );
		}
	} );

	$( 'body' ).on( 'click', '.ifthenpay-backoffice-key-change', function ( e ) {
		e.preventDefault();
		var $box = $( this ).closest( '.ifthenpay-backoffice-key' );
		$box.addClass( 'is-editing' );
		backofficeKeyMessage( $box, '' );
		$box.find( '.ifthenpay-backoffice-key-input' ).trigger( 'focus' );
	} );

	$( 'body' ).on( 'click', '.ifthenpay-backoffice-key-cancel', function ( e ) {
		e.preventDefault();
		var $box = $( this ).closest( '.ifthenpay-backoffice-key' );
		$box.removeClass( 'is-editing' ).find( '.ifthenpay-backoffice-key-input' ).val( '' );
		backofficeKeyMessage( $box, '' );
	} );

	$( 'body' ).on( 'click', '.ifthenpay-backoffice-key-remove', function ( e ) {
		e.preventDefault();
		if ( confirm( ifthenpayFluentCart.text_bo_key_remove ) ) {
			backofficeKeyRequest( $( this ).closest( '.ifthenpay-backoffice-key' ), { operation: 'remove' } );
		}
	} );

	// Webhook activation, with the saved Backoffice Key
	$( 'body' ).on( 'click', '#ifthenpay-activate-webhook', function ( e ) {
		e.preventDefault();

		var $button = $( this );
		var $box    = $( '.ifthenpay-backoffice-key' );

		// No Backoffice Key yet: ask for it in the box
		if ( $box.attr( 'data-saved' ) !== 'yes' ) {
			backofficeKeyMessage( $box, ifthenpayFluentCart.text_bo_key_needed, true );
			$box.find( '.ifthenpay-backoffice-key-input' ).trigger( 'focus' );
			return;
		}

		$button.addClass( 'is-loading' );

		$.post( ajaxurl, {
			action: 'ifthenpay_fluentcart_activate_webhook',
			gateway: $button.data( 'gateway' ),
			ent: $button.data( 'ent' ),
			subent: $button.data( 'subent' ),
			nonce: ifthenpayFluentCart.nonce
		} ).done( function ( response ) {
			alert( response.data );
			if ( response.success ) {
				window.location.reload();
			}
		} ).fail( function () {
			alert( 'Connection error occurred' );
		} ).always( function () {
			$button.removeClass( 'is-loading' );
		} );
	} );

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