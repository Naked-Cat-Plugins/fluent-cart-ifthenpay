/**
 * The checkout javascript
 *
 * When a payment method is selected, including the one already selected when the checkout loads,
 * FluentCart fires fluent_cart_load_payments_{method} and keeps the "Place order" button disabled
 * until that payment method answers. Ours have nothing to load, so they enable it right away, as
 * FluentCart's own Cash on Delivery does.
 */

( function () {

	var methods = ( window.ifthenpayFluentCartCheckout && window.ifthenpayFluentCartCheckout.methods ) || [];

	methods.forEach( function ( method ) {
		window.addEventListener( 'fluent_cart_load_payments_' + method, function ( e ) {
			var submit_button = window.fluentcart_checkout_vars && window.fluentcart_checkout_vars.submit_button;
			if ( e.detail && e.detail.paymentLoader && submit_button ) {
				e.detail.paymentLoader.enableCheckoutButton( submit_button.text );
			}
		} );
	} );

}() );
