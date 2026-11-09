#!/usr/bin/env bash
# Publication : contrôles, paquets ZIP du thème et de l'extension, sommes SHA-256 et manifeste
# de mises à jour (dist/update.json, format décrit dans docs/PUBLICATION.md).
# Usage : npm run release
#   SKIP_CHECKS=1 npm run release           reconstruit sans relancer npm run ci (version vérifiée quand même)
#   RELEASE_BASE_URL=https://… npm run release   adresse des paquets dans update.json
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root"

version="$(node -p 'require("./package.json").version')"
base_url="${RELEASE_BASE_URL:-https://fluffless.dev/downloads}"
dist="$root/dist"

if [ "${SKIP_CHECKS:-0}" = 1 ]; then
	node tools/version.mjs check
else
	npm run --silent ci
fi

# Une constante vide livrerait un produit qui ne propose jamais de mise à jour (C9).
if ! grep -qE "SIGNING_PUBLIC_KEY = '[A-Za-z0-9+/]{43}=';" packages/fluffless-suite/src/Modules/Updates.php; then
	echo "Updates::SIGNING_PUBLIC_KEY est vide ou mal formée (32 octets en base64 attendus)." >&2
	exit 1
fi

# Date unique de tous les fichiers des zip : SOURCE_DATE_EPOCH, sinon celle du dernier commit.
# Avec des droits et un ordre fixés, deux constructions du même commit donnent les mêmes octets,
# et les sommes publiées sur fluffless.dev restent vérifiables après une reconstruction.
epoch="${SOURCE_DATE_EPOCH:-$(git -C "$root" log -1 --format=%ct 2>/dev/null || true)}"
if ! [[ "$epoch" =~ ^[0-9]+$ ]]; then
	echo "Date de construction introuvable : SOURCE_DATE_EPOCH absente et aucun commit git." >&2
	exit 1
fi
stamp="$(node -e 'process.stdout.write(new Date(Number(process.argv[1]) * 1000).toISOString().replace(/\.\d+Z$/, "Z"))' "$epoch")"

stage="$(mktemp -d)"
trap 'rm -rf "$stage"' EXIT
rm -rf "$dist"
mkdir -p "$dist"

# Sources de développement exclues des paquets (dossiers vides élagués par rsync -m) ; .mo, .l10n.php et .json de traduction restent.
excludes=(
	--exclude=tests/ --exclude=node_modules/ --exclude=vendor/
	--exclude='*.po' --exclude='*.pot' --exclude='*.map'
	--exclude=README.md --exclude=package.json --exclude=package-lock.json
	--exclude='composer.*' --exclude='phpcs.xml*' --exclude='phpstan.neon*'
	--exclude='.*'
)

# Construit dist/<slug>-<version>.zip avec un dossier racine <slug>/ et vérifie son contenu.
package() {
	local slug="$1" main="$2"
	rsync -am "${excludes[@]}" "packages/$slug/" "$stage/$slug/"
	find "$stage/$slug" -type d -exec chmod 755 {} +
	find "$stage/$slug" -type f -exec chmod 644 {} +
	find "$stage/$slug" -exec touch -d "$stamp" {} +
	# Liste triée et heure DOS en UTC : ni l'ordre du disque ni le fuseau n'entrent dans le zip.
	(cd "$stage" && find "$slug" | LC_ALL=C sort | TZ=UTC zip -qX "$dist/$slug-$version.zip" -@)
	local listing
	listing="$(unzip -Z1 "$dist/$slug-$version.zip")"
	if ! grep -qx "$slug/$main" <<< "$listing"; then
		echo "$slug : $main absent du paquet." >&2
		exit 1
	fi
	if grep -E '(^|/)(tests|node_modules)/|\.pot?$' <<< "$listing"; then
		echo "$slug : fichiers de développement dans le paquet (liste ci-dessus)." >&2
		exit 1
	fi
}

# Valeur d'un en-tête WordPress (« Requires PHP: 8.1 ») ; vide s'il est absent.
header() {
	sed -nE "s/^[ *]*$2:[[:space:]]*(.*[^[:space:]])[[:space:]]*$/\1/p" "$1" | head -n 1
}

sha256() {
	shasum -a 256 "$1" | cut -d ' ' -f 1
}

# Écrit <zip>.sig pour les TESTS LOCAUX seulement : en production, le serveur signe à la mise en ligne
# avec sa propre clé. Même format que le serveur : ed25519 (base64) des octets exacts du ZIP.
# La clé (jetable ; graine de 32 octets ou clé sodium de 64 octets, en base64) vient de
# FLUFFLESS_SIGNING_KEY, passée par l'environnement, jamais en argument.
sign() {
	ZIP="$1" node -e '
		const crypto = require("node:crypto");
		const raw = Buffer.from(process.env.FLUFFLESS_SIGNING_KEY || "", "base64");
		if (raw.length !== 32 && raw.length !== 64) {
			console.error("FLUFFLESS_SIGNING_KEY : 32 ou 64 octets en base64 attendus, reçu " + raw.length + ".");
			process.exit(1);
		}
		const pkcs8 = Buffer.concat([Buffer.from("302e020100300506032b657004220420", "hex"), raw.subarray(0, 32)]);
		const key = crypto.createPrivateKey({ key: pkcs8, format: "der", type: "pkcs8" });
		process.stdout.write(crypto.sign(null, require("node:fs").readFileSync(process.env.ZIP), key).toString("base64"));
	' > "$1.sig"
}

# Clé publique (base64) de FLUFFLESS_SIGNING_KEY, à comparer à FLUFFLESS_SIGNING_PUBLIC_KEY des sites.
public_key() {
	node -e '
		const crypto = require("node:crypto");
		const raw = Buffer.from(process.env.FLUFFLESS_SIGNING_KEY, "base64").subarray(0, 32);
		const key = crypto.createPrivateKey({ key: Buffer.concat([Buffer.from("302e020100300506032b657004220420", "hex"), raw]), format: "der", type: "pkcs8" });
		process.stdout.write(crypto.createPublicKey(key).export({ format: "der", type: "spki" }).subarray(-32).toString("base64"));
	'
}

if [ -z "${FLUFFLESS_SIGNING_KEY:-}" ] && [ "${RELEASE_UNSIGNED:-0}" != 1 ]; then
	echo "FLUFFLESS_SIGNING_KEY absente : les sites refusent un paquet non signé. RELEASE_UNSIGNED=1 pour un paquet local de test." >&2
	exit 1
fi

package fluffless style.css
package fluffless-suite fluffless-suite.php
package fluffless-seo fluffless-seo.php
# Fluffless Consent : gratuit, sans Update URI ; la Suite le met à jour par le serveur, WordPress.org sinon.
package fluffless-consent fluffless-consent.php

if [ -n "${FLUFFLESS_SIGNING_KEY:-}" ]; then
	for zip in "$dist"/*.zip; do
		sign "$zip"
	done
fi

theme_zip="fluffless-$version.zip"
plugin_zip="fluffless-suite-$version.zip"
plugin_main="packages/fluffless-suite/fluffless-suite.php"
seo_zip="fluffless-seo-$version.zip"
seo_main="packages/fluffless-seo/fluffless-seo.php"
theme_main="packages/fluffless/style.css"
consent_zip="fluffless-consent-$version.zip"
consent_main="packages/fluffless-consent/fluffless-consent.php"

jq -n \
	--arg generated "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
	--arg version "$version" \
	--arg p_id "$(header "$plugin_main" 'Update URI')" \
	--arg p_url "$(header "$plugin_main" 'Plugin URI')" \
	--arg p_package "$base_url/$plugin_zip" \
	--arg p_sha "$(sha256 "$dist/$plugin_zip")" \
	--arg p_requires "$(header "$plugin_main" 'Requires at least')" \
	--arg p_php "$(header "$plugin_main" 'Requires PHP')" \
	--arg p_tested "$(header "$plugin_main" 'Tested up to')" \
	--arg s_id "$(header "$seo_main" 'Update URI')" \
	--arg s_url "$(header "$seo_main" 'Plugin URI')" \
	--arg s_package "$base_url/$seo_zip" \
	--arg s_sha "$(sha256 "$dist/$seo_zip")" \
	--arg s_requires "$(header "$seo_main" 'Requires at least')" \
	--arg s_php "$(header "$seo_main" 'Requires PHP')" \
	--arg s_tested "$(header "$seo_main" 'Tested up to')" \
	--arg c_url "$(header "$consent_main" 'Plugin URI')" \
	--arg c_package "$base_url/$consent_zip" \
	--arg c_sha "$(sha256 "$dist/$consent_zip")" \
	--arg c_requires "$(header "$consent_main" 'Requires at least')" \
	--arg c_php "$(header "$consent_main" 'Requires PHP')" \
	--arg c_tested "$(header "$consent_main" 'Tested up to')" \
	--arg t_url "$(header "$theme_main" 'Theme URI')" \
	--arg t_package "$base_url/$theme_zip" \
	--arg t_sha "$(sha256 "$dist/$theme_zip")" \
	--arg t_requires "$(header "$theme_main" 'Requires at least')" \
	--arg t_php "$(header "$theme_main" 'Requires PHP')" \
	--arg t_tested "$(header "$theme_main" 'Tested up to')" \
	'{
		schema: 1,
		generated: $generated,
		plugins: {
			"fluffless-suite/fluffless-suite.php": {
				id: $p_id, slug: "fluffless-suite", plugin: "fluffless-suite/fluffless-suite.php",
				version: $version, url: $p_url, package: $p_package, sha256: $p_sha,
				requires: $p_requires, requires_php: $p_php, tested: $p_tested
			},
			"fluffless-seo/fluffless-seo.php": {
				id: $s_id, slug: "fluffless-seo", plugin: "fluffless-seo/fluffless-seo.php",
				version: $version, url: $s_url, package: $s_package, sha256: $s_sha,
				requires: $s_requires, requires_php: $s_php, tested: $s_tested
			},
			"fluffless-consent/fluffless-consent.php": {
				id: "https://api.fluffless.dev/fluffless-consent", slug: "fluffless-consent", plugin: "fluffless-consent/fluffless-consent.php",
				version: $version, url: $c_url, package: $c_package, sha256: $c_sha,
				requires: $c_requires, requires_php: $c_php, tested: $c_tested
			}
		},
		themes: {
			fluffless: {
				theme: "fluffless", version: $version, url: $t_url, package: $t_package, sha256: $t_sha,
				requires: $t_requires, requires_php: $t_php, tested: $t_tested
			}
		}
	}' > "$dist/update.json"

(cd "$dist" && shasum -a 256 ./*.zip > SHA256SUMS)

# dist/public/ : seul dossier copié vers fluffless.dev/downloads/. Les deux paquets gratuits et leurs
# sommes, au nom nu (lu par le code court [fluffless_telecharger] du site) ; rien de la Suite ni de SEO.
mkdir -p "$dist/public"
cp "$dist/$theme_zip" "$dist/fluffless-consent-$version.zip" "$dist/public/"
(cd "$dist/public" && shasum -a 256 "$theme_zip" "fluffless-consent-$version.zip" > SHA256SUMS)

printf '\nPublication %s :\n' "$version"
for zip in "$dist"/*.zip; do
	printf '  %s  %s o  %s\n' "${zip#"$root"/}" "$(wc -c < "$zip" | tr -d ' ')" "$(sha256 "$zip")"
done
printf '  dist/update.json, dist/SHA256SUMS\n  dist/public/ : paquets gratuits et SHA256SUMS, à copier vers fluffless.dev/downloads/\n'
if [ -n "${FLUFFLESS_SIGNING_KEY:-}" ]; then
	printf '  dist/*.zip.sig, clé publique : %s\n' "$(public_key)"
else
	printf '  AUCUNE signature (RELEASE_UNSIGNED=1) : paquets refusés par les sites.\n'
fi
