/**
 * The MB WAY order receipt javascript
 *
 * While the customer approves the payment in the MB WAY app, checks whether the order was paid, at growing
 * intervals, and reloads the receipt as soon as it is, or once the time to approve it is over. Optionally,
 * shows the time left. No dependencies.
 */

( function () {

	function init( box ) {
		var expires   = parseInt( box.getAttribute( 'data-expires' ), 10 );
		var check     = box.getAttribute( 'data-check' ) === 'yes';
		var countdown = box.querySelector( '.ifthenpay-mbway-countdown-time' );
		var interval  = 10000;
		var reloading = false;

		function reload() {
			if ( ! reloading ) {
				reloading = true;
				window.location.reload();
			}
		}

		// Countdown
		if ( countdown ) {
			var tick = function () {
				var left = Math.max( 0, Math.round( ( expires - Date.now() ) / 1000 ) );
				countdown.textContent = Math.floor( left / 60 ) + ':' + String( left % 60 ).padStart( 2, '0' );
				if ( left === 0 ) {
					clearInterval( timer );
					// A moment for a last minute payment to arrive
					setTimeout( reload, 3000 );
				}
			};
			var timer = setInterval( tick, 1000 );
			tick();
		}

		// Payment status check
		if ( check ) {
			var ask = function () {
				var body = new URLSearchParams();
				body.append( 'action', 'ifthenpay_fluentcart_order_status' );
				body.append( 'order_hash', box.getAttribute( 'data-order-hash' ) );
				fetch( box.getAttribute( 'data-ajax-url' ), { method: 'POST', body: body, credentials: 'same-origin' } )
					.then( function ( response ) {
						return response.json();
					} )
					.then( function ( response ) {
						if ( response && response.success && ! response.data.pending ) {
							reload();
							return;
						}
						next();
					} )
					.catch( next );
			};
			var next = function () {
				// Keep checking until a minute after the payment expires
				if ( Date.now() < expires + 60000 ) {
					setTimeout( ask, interval );
					interval = Math.round( interval * 1.2 );
				} else {
					reload();
				}
			};
			next();
		}
	}

	document.querySelectorAll( '.ifthenpay-mbway-status' ).forEach( init );

}() );
