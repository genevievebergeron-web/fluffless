#!/usr/bin/env python3
"""Blocs invalides dans l'éditeur réel : ouvre chaque composition dans Gutenberg et les compte.

Pour chaque composition (pattern) dont le nom commence par le préfixe demandé, crée une page de
test (brouillon, titre « [test-editeur] <nom> »), l'ouvre dans l'éditeur de blocs avec Playwright
et relève les blocs invalides (`isValid` faux) et les blocs inconnus (`core/missing`). Les pages
de test sont supprimées à la fin, même en cas d'échec.

Usage :
    python3 tools/check-editor-blocks.py [--prefix fluffless/] [--only nom1,nom2] [--details] [--keep]
Variables :
    COMPOSE_FILE   fichier compose du banc (défaut : compose.yaml du dépôt)
    WP_BASE_URL    adresse du banc (défaut : http://127.0.0.1:8882, pas localhost : les cookies
                   et l'API REST ne suivent pas)
Prérequis : banc WordPress démarré (npm run wp:up), `pip install playwright` et
`playwright install chromium`. Ne tourne pas en CI sans navigateur : contrôle manuel.
Sortie : un compte par composition, puis un total ; code 1 s'il reste un bloc invalide.
"""
import argparse
import json
import os
import subprocess
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
COMPOSE = os.environ.get("COMPOSE_FILE", os.path.join(ROOT, "compose.yaml"))
BASE = os.environ.get("WP_BASE_URL", "http://127.0.0.1:8882").rstrip("/")
TITRE = "[test-editeur] "
BORNE_EDITEUR_MS = 240000
BORNE_NAVIGATION_MS = 300000


def wp_eval(code):
    """Exécute du PHP par WP-CLI dans le conteneur cli et rend la sortie standard."""
    cmd = ["docker", "compose", "-f", COMPOSE, "run", "--rm", "-T", "cli", "wp", "eval", code]
    r = subprocess.run(cmd, capture_output=True, text=True, cwd=ROOT, timeout=900)
    if r.returncode != 0:
        sys.exit("WP-CLI a échoué : " + r.stderr.strip()[-400:])
    return r.stdout


PHP_CREER = r"""
$prefixe = %s; $seules = json_decode( %s, true ); $ids = []; $ecarts = [];
$adm = get_users( [ 'role' => 'administrator', 'number' => 1 ] )[0]; wp_set_current_user( $adm->ID );
foreach ( WP_Block_Patterns_Registry::get_instance()->get_all_registered() as $p ) {
	if ( 0 !== strpos( $p['name'], $prefixe ) ) { continue; }
	if ( $seules && ! in_array( $p['name'], $seules, true ) ) { continue; }
	$id = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'draft',
		'post_title' => %s . $p['name'], 'post_content' => wp_slash( $p['content'] ) ] );
	if ( $id && ! is_wp_error( $id ) ) { $ids[ $id ] = $p['name'];
		if ( get_post_field( 'post_content', $id ) !== $p['content'] ) { $ecarts[] = $p['name']; } }
}
$u = get_users( [ 'role' => 'administrator', 'number' => 1 ] )[0];
$exp = time() + 3600;
echo wp_json_encode( [ 'pages' => $ids, 'alteres' => $ecarts, 'cookies' => [
	[ 'name' => 'wordpress_' . COOKIEHASH, 'value' => wp_generate_auth_cookie( $u->ID, $exp, 'auth' ), 'path' => '/wp-admin' ],
	[ 'name' => 'wordpress_logged_in_' . COOKIEHASH, 'value' => wp_generate_auth_cookie( $u->ID, $exp, 'logged_in' ), 'path' => '/' ],
] ] );
"""

PHP_NETTOYER = r"""
$n = 0;
foreach ( get_posts( [ 'post_type' => 'page', 'post_status' => 'any', 'numberposts' => -1,
	's' => %s, 'fields' => 'ids' ] ) as $id ) {
	if ( 0 === strpos( get_the_title( $id ), %s ) ) { wp_delete_post( $id, true ); ++$n; }
}
echo $n;
"""

JS_COMPTE = """() => {
	const tous = [];
	const parcourir = (bs) => bs.forEach((b) => { tous.push(b); parcourir(b.innerBlocks); });
	parcourir(wp.data.select('core/block-editor').getBlocks());
	const libres = tous.filter((b) => b.name === 'core/html' || b.name === 'core/freeform').length;
	const invalides = tous.filter((b) => !b.isValid).map((b) => b.name);
	const manquants = tous.filter((b) => b.name === 'core/missing').length;
	return { n: tous.length, invalides: invalides.length, noms: [...new Set(invalides)], manquants, libres };
}"""


def php_chaine(valeur):
    return json.dumps(valeur)


def main():
    ap = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    ap.add_argument("--prefix", default="fluffless/", help="préfixe des noms de compositions")
    ap.add_argument("--only", default="", help="noms exacts séparés par des virgules")
    ap.add_argument("--details", action="store_true", help="affiche les messages « Block validation » de la console")
    ap.add_argument("--expect", type=int, default=0, help="nombre exact de compositions attendu (échoue sinon)")
    ap.add_argument("--keep", action="store_true", help="ne pas supprimer les pages de test")
    args = ap.parse_args()

    try:
        from playwright.sync_api import sync_playwright
    except ImportError:
        sys.exit("Playwright manque : pip install playwright && playwright install chromium")

    seules = [s for s in args.only.split(",") if s]
    php = PHP_CREER % (php_chaine(args.prefix), php_chaine(json.dumps(seules)), php_chaine(TITRE))
    donnees = json.loads(wp_eval(php).strip().splitlines()[-1])
    pages = donnees["pages"]
    if not pages:
        sys.exit("Aucune composition trouvée pour ce préfixe : le thème et la Suite sont-ils actifs ?")
    if args.expect and len(pages) != args.expect:
        sys.exit(f"{len(pages)} compositions trouvées, {args.expect} attendues.")
    print(f"{len(donnees.get('alteres', []))} composition(s) dont le contenu enregistré diffère de la composition : {donnees.get('alteres')}" if donnees.get("alteres") else "contenu enregistré identique aux compositions")
    print(f"{len(pages)} compositions à ouvrir dans l'éditeur ({BASE})")

    total_inv = total_man = 0
    ennuis = []
    try:
        with sync_playwright() as p:
            nav = p.chromium.launch()
            ctx = nav.new_context(viewport={"width": 1400, "height": 900})
            ctx.add_cookies([{"name": c["name"], "value": c["value"], "url": BASE + c["path"].rstrip("/") + "/"}
                             for c in donnees["cookies"]])
            pg = ctx.new_page()
            pg.set_default_navigation_timeout(BORNE_NAVIGATION_MS)
            if args.details:
                pg.on("console", lambda m: print("      " + m.text[:700].replace("\n", " | "))
                      if "Block validation" in m.text or "Expected" in m.text or "Content generated" in m.text else None)
            for pid, nom in pages.items():
                pg.goto(f"{BASE}/wp-admin/post.php?post={pid}&action=edit", wait_until="domcontentloaded")
                try:
                    pg.wait_for_function(
                        "window.wp&&wp.data&&wp.data.select('core/block-editor')&&wp.data.select('core/block-editor').getBlocks().length>0",
                        timeout=BORNE_EDITEUR_MS)
                except Exception as e:  # noqa: BLE001 : on note et on continue
                    ennuis.append(nom)
                    print(f"  ?? {nom} : éditeur non chargé ({str(e)[:70]})")
                    continue
                pg.wait_for_timeout(1500)
                r = pg.evaluate(JS_COMPTE)
                total_inv += r["invalides"]
                total_man += r["manquants"]
                etat = "OK" if not r["invalides"] and not r["manquants"] and not r["libres"] else "!!"
                detail = f" invalides={r['noms']} manquants={r['manquants']} html-libre={r['libres']}" if etat == "!!" else ""
                print(f"  {etat} {nom} : {r['n']} blocs{detail}")
                if etat == "!!":
                    ennuis.append(nom)
            nav.close()
    finally:
        if not args.keep:
            n = wp_eval(PHP_NETTOYER % (php_chaine(TITRE.strip()), php_chaine(TITRE))).strip()
            print(f"{n} pages de test supprimées")

    print(f"Total : {total_inv} bloc(s) invalide(s), {total_man} bloc(s) inconnu(s), {len(ennuis)} composition(s) à revoir")
    sys.exit(1 if ennuis else 0)


if __name__ == "__main__":
    main()
