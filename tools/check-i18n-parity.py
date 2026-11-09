#!/usr/bin/env python3
"""Parité des langues du thème et des extensions : le français (fr_CA) couvre tout l'anglais du code.

Le code est écrit en anglais, le français vit dans les fichiers .po. Ce script DÉTECTE les écarts,
il ne traduit rien. Pour chaque paquet de PAQUETS :
  1. régénère les chaînes du code (xgettext, mêmes mots-clés que WordPress) dans un dossier
     temporaire, jamais par-dessus le .pot du dépôt, et échoue sur toute chaîne absente du .po ;
  2. échoue sur toute chaîne du .pot livré (en-têtes des compositions comprises) absente du .po,
     vide ou marquée fuzzy ;
  3. échoue si le .mo livré ne compile pas la même chose que le .po.
Les chaînes périmées (dans le .po, absentes du code) sont comptées, sans faire échouer.

Limites : ne lit pas les chaînes des scripts de blocs (fichiers .json de `wp i18n make-json`) ni
le .l10n.php ; ne juge pas la qualité de la traduction (c'est le rôle de la linguiste).

Usage : python3 tools/check-i18n-parity.py [--paquet fluffless,fluffless-consent] [--locale fr_CA]
Sortie : 0 = parité, 1 = écart, 2 = erreur. Prérequis : gettext (xgettext), sans banc ni navigateur,
donc compatible avec la CI.
"""
import argparse
import gettext
import json
import pathlib
import subprocess
import sys
import tempfile

RACINE = pathlib.Path(__file__).resolve().parent.parent
# nom du paquet : (dossier, modèle du fichier .po avec {locale}, modèle du .mo)
PAQUETS = {
    "fluffless": ("packages/fluffless", "languages/{locale}.po", "languages/{locale}.mo"),
    "fluffless-suite": ("packages/fluffless-suite", "languages/fluffless-suite-{locale}.po",
                        "languages/fluffless-suite-{locale}.mo"),
    "fluffless-seo": ("packages/fluffless-seo", "languages/fluffless-seo-{locale}.po",
                      "languages/fluffless-seo-{locale}.mo"),
    "fluffless-consent": ("packages/fluffless-consent", "languages/fluffless-consent-{locale}.po",
                          "languages/fluffless-consent-{locale}.mo"),
}
EXCLUS = ("vendor", "node_modules", "tests", "languages", "build")
MOTS_CLES = ["-k", "-k__", "-k_e", "-kesc_html__", "-kesc_html_e", "-kesc_attr__", "-kesc_attr_e",
             "-k_x:1,2c", "-kesc_html_x:1,2c", "-kesc_attr_x:1,2c", "-k_ex:1,2c", "-k_n:1,2", "-k_nx:1,2,4c"]
MIN_CHAINES = 3  # sous ce seuil, l'extraction est suspecte (chemin faux), pas un succès


def lire_po(chemin):
    """Entrées d'un .po ou .pot : {(contexte, msgid): {'str': [...], 'fuzzy': bool}}. Ignore l'en-tête."""
    entrees, e, dernier = {}, {}, None

    def fermer():
        if e.get("id"):
            entrees[(e.get("ctx"), e["id"])] = {"str": e.get("str", []), "fuzzy": e.get("fuzzy", False)}

    for ligne in pathlib.Path(chemin).read_text(encoding="utf-8").splitlines():
        if ligne.startswith("#~"):
            continue
        if ligne.startswith("#,"):
            e["fuzzy"] = "fuzzy" in ligne
        elif ligne.startswith("msgctxt "):
            e["ctx"], dernier = json.loads(ligne[8:]), "ctx"
        elif ligne.startswith("msgid_plural "):
            e["pl"], dernier = json.loads(ligne[13:]), "pl"
        elif ligne.startswith("msgid "):
            e["id"], dernier = json.loads(ligne[6:]), "id"
        elif ligne.startswith("msgstr"):
            e.setdefault("str", []).append(json.loads(ligne.split(" ", 1)[1]))
            dernier = "str"
        elif ligne.startswith('"') and dernier:
            valeur = json.loads(ligne)
            if dernier == "str":
                e["str"][-1] += valeur
            elif dernier in e:
                e[dernier] += valeur
        elif not ligne.strip():
            fermer()
            e, dernier = {}, None
    fermer()
    return entrees


def chaines_du_code(dossier, destination):
    fichiers = sorted(str(p.relative_to(dossier)) for p in dossier.rglob("*.php")
                      if not any(part in EXCLUS for part in p.relative_to(dossier).parts))
    if not fichiers:
        raise SystemExit(f"Aucun fichier PHP dans {dossier} : chemin suspect.")
    cmd = ["xgettext", "--language=PHP", "--from-code=UTF-8", "--add-comments=translators", "--sort-by-file",
           "-o", str(destination), *MOTS_CLES, *fichiers]
    r = subprocess.run(cmd, cwd=dossier, capture_output=True, text=True)
    if r.returncode != 0:
        raise SystemExit(f"xgettext a échoué ({r.returncode}) : {r.stderr[-400:]}")
    return lire_po(destination)


def extrait(cle):
    return cle[1][:90].replace("\n", " ")


def verifier_paquet(nom, locale, ecarts, notes):
    sous, modele_po, modele_mo = PAQUETS[nom]
    dossier = RACINE / sous
    po_chemin = dossier / modele_po.format(locale=locale)
    mo_chemin = dossier / modele_mo.format(locale=locale)
    pot_livre = sorted((dossier / "languages").glob("*.pot"))
    for f in (po_chemin, mo_chemin):
        if not f.exists():
            ecarts.append(f"{nom} : fichier absent {f.relative_to(RACINE)}")
            return
    po = lire_po(po_chemin)
    with tempfile.TemporaryDirectory() as tmp:
        code = chaines_du_code(dossier, pathlib.Path(tmp) / "code.pot")
    if len(code) < MIN_CHAINES:
        raise SystemExit(f"{nom} : {len(code)} chaîne(s) extraites du code, extraction suspecte.")
    sources = {"code": code}
    if pot_livre:
        sources[".pot livré"] = lire_po(pot_livre[0])
    notes.append(f"{nom} : {len(code)} chaînes du code"
                 + (f", {len(sources['.pot livré'])} dans le .pot livré" if pot_livre else "")
                 + f", {len(po)} dans le .po {locale}.")
    vus = set()
    for origine, chaines in sources.items():
        for cle in chaines:
            if cle in vus:
                continue
            entree = po.get(cle)
            if entree is None:
                ecarts.append(f"{nom} : chaîne ({origine}) absente du .po : « {extrait(cle)} »")
            elif entree["fuzzy"]:
                ecarts.append(f"{nom} : chaîne fuzzy dans le .po : « {extrait(cle)} »")
            elif not entree["str"] or any(not s for s in entree["str"]):
                ecarts.append(f"{nom} : chaîne non traduite dans le .po : « {extrait(cle)} »")
            else:
                continue
            vus.add(cle)
    perimees = [c for c in po if c not in code and not (pot_livre and c in sources[".pot livré"])]
    if perimees:
        notes.append(f"{nom} : {len(perimees)} chaîne(s) du .po absentes du code (périmées, sans effet).")
    with open(mo_chemin, "rb") as f:
        catalogue = gettext.GNUTranslations(f)._catalog
    for (ctx, mid), entree in po.items():
        if entree["fuzzy"] or not entree["str"] or not entree["str"][0]:
            continue
        cle_mo = f"{ctx}\x04{mid}" if ctx else mid
        valeur = catalogue.get(cle_mo, catalogue.get((cle_mo, 0)))
        if valeur != entree["str"][0]:
            ecarts.append(f"{nom} : le .mo diffère du .po (recompiler avec msgfmt) : « {mid[:90]} »")
            break


def main():
    p = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    p.add_argument("--paquet", default=",".join(PAQUETS), help="paquets séparés par des virgules")
    p.add_argument("--locale", default="fr_CA")
    args = p.parse_args()
    ecarts, notes = [], []
    try:
        for nom in [n for n in args.paquet.split(",") if n]:
            if nom not in PAQUETS:
                print(f"Paquet inconnu : {nom} (choix : {', '.join(PAQUETS)})", file=sys.stderr)
                return 2
            verifier_paquet(nom, args.locale, ecarts, notes)
    except (OSError, ValueError, subprocess.SubprocessError) as e:
        print(f"Erreur : {e}", file=sys.stderr)
        return 2
    for n in notes:
        print("· " + n)
    if ecarts:
        print(f"✗ Parité {args.locale} : {len(ecarts)} écart(s).")
        for e in ecarts:
            print("  " + e)
        return 1
    print(f"✓ Parité {args.locale} : aucun écart.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
