/**
 * The frontend javascript
 */

document.addEventListener(
	'DOMContentLoaded',
	function() {

		// alert( ifthenpayFluentCart.id + ' frontend script loaded!' );

		new NiceSelect( // NOT DEFINED
			document.getElementById( ifthenpayFluentCart.id + '-country-code' ),
			{
				placeholder: 'Country',
				clearable: false
			}
		);

	}
);