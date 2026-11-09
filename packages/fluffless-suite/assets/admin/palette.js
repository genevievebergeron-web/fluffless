/* Palette : générateur, cadenas et aperçu en direct. Sans ce script, l'écran marche : les 9 champs
   couleur sont validés par le serveur (contraste compris). */
( () => {
	document.querySelectorAll( '[data-print]' ).forEach( ( button ) => {
		button.hidden = false;
		button.addEventListener( 'click', () => window.print() );
	} );
	const form = document.getElementById( 'fl-palette-form' );
	const core = window.flPalette;
	const gen = document.getElementById( 'fl-palette-generator' );
	const report = document.getElementById( 'fl-palette-report' );
	const preview = document.getElementById( 'fl-palette-preview' );
	if ( ! form || ! core || ! gen || ! report || ! preview ) {
		return;
	}
	let i18n = {};
	try {
		i18n = JSON.parse( form.dataset.i18n || '{}' );
	} catch {
		i18n = {};
	}
	const roles = i18n.roles || {};
	const fill = ( text, ...args ) => {
		let n = 0;
		return String( text || '' ).replace( /%(\d\$)?[sd]/g, ( m, pos ) => String( args[ pos ? parseInt( pos, 10 ) - 1 : n++ ] ) );
	};
	const field = ( role ) => form.elements[ 'colors[' + role + ']' ];
	// Un rôle facultatif n'a de champ que s'il est déclaré (voir Palette::render()).
	const read = () => Object.fromEntries( core.ROLES.concat( core.OPTIONAL ).filter( field ).map( ( r ) => [ r, field( r ).value.toLowerCase() ] ) );

	const paint = () => {
		const pal = read();
		Object.keys( pal ).forEach( ( r ) => preview.style.setProperty( '--c-' + r, pal[ r ] ) );
		const bad = core.check( pal, i18n.button ).map( ( p ) => {
			const [ t, b ] = p.split( '/' );
			return fill( i18n.pair, roles[ t ] || t, roles[ b ] || b );
		} );
		report.textContent = fill( i18n.score, core.score( pal ) ) + ' · ' + ( bad.length ? fill( i18n.bad, bad.join( ', ' ) ) : i18n.ok );
		report.classList.toggle( 'is-bad', bad.length > 0 );
	};

	gen.hidden = false;
	form.querySelectorAll( '.fl-palette-lock' ).forEach( ( el ) => {
		el.hidden = false;
	} );
	gen.querySelector( '[data-gen="run"]' ).addEventListener( 'click', () => {
		const locked = {};
		form.querySelectorAll( 'input[data-lock]:checked' ).forEach( ( el ) => {
			locked[ el.dataset.lock ] = field( el.dataset.lock ).value;
		} );
		const pick = ( name ) => gen.querySelector( '[data-gen="' + name + '"]' ).value;
		const pal = core.generate( { mood: pick( 'mood' ), strict: pick( 'strict' ), harmony: pick( 'harmony' ), locked } );
		core.ROLES.forEach( ( r ) => {
			// Un code Pantone décrit une couleur précise : il part avec elle.
			const code = form.elements[ 'pantone[' + r + ']' ];
			if ( code && field( r ).value.toLowerCase() !== pal[ r ] ) {
				code.value = '';
			}
			field( r ).value = pal[ r ];
		} );
		paint();
	} );
	form.addEventListener( 'input', paint );
	paint();
} )();
