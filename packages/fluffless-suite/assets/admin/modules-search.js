/**
 * Écran Modules : ouvre le panneau visé par une ancre, puis recherche. La recherche masque les modules qui ne correspondent pas, et les groupes devenus vides.
 * Sans ce script, le champ reste masqué et les liens d'ancre suffisent.
 */
( function () {
	// Une ancre vers un module (#fl-title-updates) ouvre aussi son panneau de réglages.
	const openFromHash = function () {
		const target = window.location.hash.length > 1 ? document.getElementById( decodeURIComponent( window.location.hash.slice( 1 ) ) ) : null;
		const body = target ? target.closest( 'tbody.fl-module' ) : null;
		const toggle = body ? body.querySelector( 'details.fl-module__toggle' ) : null;
		if ( toggle ) {
			toggle.open = true;
			target.scrollIntoView();
		}
	};
	openFromHash();
	window.addEventListener( 'hashchange', openFromHash );

	const input = document.querySelector( '[data-fl-modules-search]' );
	const count = document.querySelector( '[data-fl-modules-count]' );
	if ( ! input ) {
		return;
	}
	input.closest( '.fl-finder__search' ).hidden = false;
	const fold = function ( text ) {
		return text.normalize( 'NFD' ).replace( /[̀-ͯ]/g, '' ).toLowerCase();
	};
	const tables = Array.from( document.querySelectorAll( 'table.fl-modules' ) );
	const rows = [];
	tables.forEach( function ( table ) {
		table.querySelectorAll( 'tbody.fl-module' ).forEach( function ( body ) {
			const name = body.querySelector( '.fl-module__name' );
			const desc = body.querySelector( '.fl-module__desc' );
			rows.push( { body: body, table: table, text: fold( ( name ? name.textContent : '' ) + ' ' + ( desc ? desc.textContent : '' ) ) } );
		} );
	} );
	const run = function () {
		const words = fold( input.value ).split( /\s+/ ).filter( Boolean );
		let shown = 0;
		const live = new Set();
		rows.forEach( function ( row ) {
			const match = words.every( function ( word ) {
				return row.text.indexOf( word ) !== -1;
			} );
			row.body.hidden = ! match;
			if ( match ) {
				shown++;
				live.add( row.table );
			}
		} );
		tables.forEach( function ( table ) {
			table.hidden = ! live.has( table );
		} );
		if ( count ) {
			count.textContent = 0 === words.length ? '' : ( 0 === shown ? count.dataset.none : ( 1 === shown ? count.dataset.one : count.dataset.many.replace( '%s', shown ) ) );
		}
	};
	input.addEventListener( 'input', run );
	input.addEventListener( 'keydown', function ( event ) {
		// Entrée ne doit pas enregistrer les réglages.
		if ( 'Enter' === event.key ) {
			event.preventDefault();
		}
	} );
} )();
