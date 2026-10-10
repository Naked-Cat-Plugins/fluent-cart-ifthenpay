/**
 * The admin notices javascript
 *
 * Records the dismissal of a "new payment method" notice, so it is shown again only once, months later.
 * WordPress itself removes the notice when its dismiss button is clicked. No dependencies.
 */

( function () {

	document.addEventListener( 'click', function ( e ) {
		var button = e.target.closest( '.ifthenpay-new-method-notice .notice-dismiss' );
		if ( ! button ) {
			return;
		}
		var body = new URLSearchParams();
		body.append( 'action', 'ifthenpay_fluentcart_dismiss_new_method' );
		body.append( 'method', button.closest( '.ifthenpay-new-method-notice' ).getAttribute( 'data-method' ) );
		body.append( 'nonce', ifthenpayFluentCartNotices.nonce );
		fetch( ifthenpayFluentCartNotices.ajax_url, { method: 'POST', body: body, credentials: 'same-origin' } );
	} );

}() );
