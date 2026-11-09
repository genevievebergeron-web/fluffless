/* Forme et densité : aperçu en direct. Sans ce script, l'écran marche : l'aperçu montre les valeurs enregistrées. */
( () => {
	const form = document.getElementById( 'fl-shape-form' );
	const box = document.getElementById( 'fl-preview' );
	if ( ! form || ! box ) {
		return;
	}
	const range = form.elements.radius;
	const out = document.getElementById( 'fl-radius-out' );
	const pick = ( name ) => form.querySelector( '[name="' + name + '"]:checked' );
	const paint = () => {
		const p = pick( 'preset' );
		const d = pick( 'density' );
		const r = range.value + 'px';
		out.value = range.value + ' px';
		box.style.setProperty( '--p-r', r );
		box.style.setProperty( '--p-br', ( p && p.dataset.btn ) || r );
		box.style.setProperty( '--p-cut', ( p && p.dataset.cut ) || '0px' );
		box.style.setProperty( '--p-slant', ( p && p.dataset.slant ) || '0px' );
		box.style.setProperty( '--p-d', d ? d.dataset.factor : '1' );
	};
	form.addEventListener( 'input', ( e ) => {
		const t = e.target;
		if ( 'preset' === t.name && t.dataset.radius ) {
			range.value = t.dataset.radius;
		}
		if ( t === range ) {
			const p = pick( 'preset' );
			if ( p && p.dataset.radius !== range.value ) {
				form.querySelector( '[name="preset"][value="custom"]' ).checked = true;
			}
		}
		paint();
	} );
} )();
