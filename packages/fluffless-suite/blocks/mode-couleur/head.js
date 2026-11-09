/*
 * Bascule clair ou sombre, imprimé en ligne dans <head> par Blocks\ModeCouleur (ce commentaire est
 * retiré à l'impression). Relit le choix avant l'affichage, montre le bouton seulement si
 * light-dark() est compris, garde aria-pressed juste, et revient au réglage de l'appareil quand le
 * choix l'égale. Aucune requête, aucune dépendance. Testé par tests/mode-couleur-test.mjs.
 */
(function (d, w) {
	var r = d.documentElement, k = 'fluffless-scheme', a = 'data-fluffless-scheme', b = '.wp-block-fluffless-mode-couleur button', q, s;
	try { q = w.matchMedia('(prefers-color-scheme: dark)'); } catch (e) {}
	try { s = w.localStorage.getItem(k); } catch (e) {}
	if (s === 'light' || s === 'dark') r.setAttribute(a, s);
	if (w.CSS && w.CSS.supports && w.CSS.supports('color', 'light-dark(red, red)')) r.setAttribute('data-fluffless-toggle', '');
	function sys() { return q && q.matches ? 'dark' : 'light'; }
	function now() { return r.getAttribute(a) || sys(); }
	function sync() {
		var l = d.querySelectorAll(b), i;
		for (i = 0; i < l.length; i++) l[i].setAttribute('aria-pressed', now() === 'dark' ? 'true' : 'false');
	}
	d.addEventListener('click', function (e) {
		var t = e.target && e.target.closest && e.target.closest(b), n;
		if (!t) return;
		n = now() === 'dark' ? 'light' : 'dark';
		try {
			if (n === sys()) w.localStorage.removeItem(k); else w.localStorage.setItem(k, n);
		} catch (x) {}
		if (n === sys()) r.removeAttribute(a); else r.setAttribute(a, n);
		sync();
	});
	if (q && q.addEventListener) q.addEventListener('change', sync);
	if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', sync); else sync();
})(document, window);
