(function () {
	var source = document.querySelector( '.markdown-source' );
	if ( source ) {
		var prefersReducedMotion = window.matchMedia &&
			window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
		var lines                = source.querySelectorAll( '.md-line' );

		if ( ! prefersReducedMotion && lines.length ) {
			source.classList.add( 'is-enhanced' );
			lines.forEach(
				function ( line, index ) {
					window.setTimeout(
						function () {
							line.classList.add( 'is-visible' );
						},
						Math.min( index * 80, 1200 )
					);
				}
			);
		}
	}

	var copyButtons = document.querySelectorAll( '[data-copy-target]' );
	copyButtons.forEach(
		function ( button ) {
			button.addEventListener(
				'click',
				function () {
					var target = document.getElementById( button.getAttribute( 'data-copy-target' ) );
					if ( ! target ) {
						return;
					}

					copyText( target.textContent ).then(
						function () {
							markCopied( button );
						}
					);
				}
			);
		}
	);

	function markCopied( button ) {
		var original       = button.textContent;
		button.textContent = 'Copied';
		button.classList.add( 'is-copied' );
		window.setTimeout(
			function () {
				button.textContent = original;
				button.classList.remove( 'is-copied' );
			},
			1400
		);
	}

	function copyText( text ) {
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			return navigator.clipboard.writeText( text );
		}

		return new Promise(
			function ( resolve ) {
				var input   = document.createElement( 'textarea' );
				input.value = text;
				input.setAttribute( 'readonly', 'readonly' );
				input.style.position = 'fixed';
				input.style.opacity  = '0';
				document.body.appendChild( input );
				input.select();
				document.execCommand( 'copy' );
				document.body.removeChild( input );
				resolve();
			}
		);
	}
}());
