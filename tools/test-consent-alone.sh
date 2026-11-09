#!/usr/bin/env bash
# Fluffless Consent seul sur un site vierge : thème par défaut de WordPress, ni thème Fluffless ni Suite,
# WP_DEBUG et WP_DEBUG_LOG actifs. Parcourt l'activation, l'écran de réglages (enregistrement par
# options.php), la bannière servie (refus, réouverture, acceptation dans jsdom), puis la désinstallation.
# Échoue si debug.log n'est pas vide, si une ressource externe est dans le HTML avant le consentement,
# ou si la désinstallation laisse une option, une métadonnée ou un réglage de l'extension.
# Le banc est un projet compose jetable, démonté à la fin (down -v), même en cas d'échec.
# Usage : npm run test:consent-alone (CONSENT_ALONE_PORT, 8896 par défaut, entre 8894 et 8899).
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
port="${CONSENT_ALONE_PORT:-8896}"
if [ "$port" -lt 8894 ] || [ "$port" -gt 8899 ]; then
	echo "CONSENT_ALONE_PORT doit être entre 8894 et 8899 (reçu : $port)." >&2
	exit 2
fi
host="127.0.0.1:$port"
site="http://$host"
export COMPOSE_PROJECT_NAME="fluffless-consent-jetable-$port"

work="$(mktemp -d "${TMPDIR:-/tmp}/consent-alone.XXXXXX")"
# Une copie de l'extension, seule dans wp-content/plugins : « wp plugin uninstall » efface son dossier.
mkdir -p "$work/plugins"
cp -R "$root/packages/fluffless-consent" "$work/plugins/fluffless-consent"
umask 077
openssl rand -hex 16 >"$work/db-password"
cat >"$work/compose.yaml" <<YAML
x-db-env: &db-env
  WORDPRESS_DB_HOST: db
  WORDPRESS_DB_USER: wordpress
  WORDPRESS_DB_PASSWORD_FILE: /run/secrets/db
  WORDPRESS_DB_NAME: wordpress
x-code-volumes: &code-volumes
  - wp:/var/www/html
  - ./plugins:/var/www/html/wp-content/plugins
services:
  db:
    image: mariadb:11.4
    environment:
      MARIADB_DATABASE: wordpress
      MARIADB_USER: wordpress
      MARIADB_PASSWORD_FILE: /run/secrets/db
      MARIADB_RANDOM_ROOT_PASSWORD: "1"
    secrets: [db]
    healthcheck:
      test: ["CMD", "healthcheck.sh", "--connect", "--innodb_initialized"]
      interval: 3s
      timeout: 5s
      retries: 40
  wordpress:
    image: wordpress:7.1-php8.3-apache
    depends_on:
      db:
        condition: service_healthy
    ports:
      - "127.0.0.1:$port:80"
    environment:
      <<: *db-env
      WORDPRESS_DEBUG: "1"
      WORDPRESS_CONFIG_EXTRA: "define( 'WP_DEBUG_LOG', true ); define( 'WP_DEBUG_DISPLAY', false );"
    secrets: [db]
    volumes: *code-volumes
  cli:
    image: wordpress:cli-2-php8.3
    profiles: ["cli"]
    user: "33:33"
    depends_on: [wordpress]
    environment:
      <<: *db-env
    secrets: [db]
    volumes: *code-volumes
secrets:
  db:
    file: ./db-password
volumes:
  wp:
YAML
chmod 644 "$work/db-password"

compose=(docker compose -f "$work/compose.yaml")
cleanup() {
	"${compose[@]}" --profile cli down -v --remove-orphans >/dev/null 2>&1 || echo "Attention : banc $COMPOSE_PROJECT_NAME non démonté." >&2
	rm -rf "$work"
}
trap cleanup EXIT

wp() {
	"${compose[@]}" run --rm -T cli wp "$@" 2>/dev/null
}
debug_log() {
	"${compose[@]}" exec -T wordpress sh -c 'cat wp-content/debug.log 2>/dev/null || true'
}
failures=0
fail() {
	echo "Échec : $*" >&2
	failures=$((failures + 1))
}
log_is_empty() {
	local log
	log="$(debug_log)"
	if [ -n "$log" ]; then
		fail "debug.log non vide ($1) :"
		printf '%s\n' "$log" | head -20 >&2
	fi
}

# Reste d'un banc précédent mal démonté : repartir d'un site vraiment vierge.
"${compose[@]}" --profile cli down -v --remove-orphans >/dev/null 2>&1 || true
if ! "${compose[@]}" up -d --wait wordpress >"$work/up.log" 2>&1; then
	echo "Banc non démarré (port $port occupé ?) :" >&2
	tail -5 "$work/up.log" >&2
	exit 1
fi
for _ in $(seq 1 30); do
	curl -fsS -o /dev/null "$site/wp-admin/install.php" 2>/dev/null && break
	sleep 1
done
wp core install --url="$site" --title="Banc Consent" --admin_user=banc --admin_email=banc@example.com \
	--admin_password="$(openssl rand -hex 16)" --skip-email >/dev/null

theme="$(wp theme list --status=active --field=name)"
plugins="$(wp plugin list --status=active --field=name)"
if [[ "$theme" != twenty* ]]; then
	fail "thème actif inattendu : $theme (attendu : thème par défaut de WordPress)"
fi
if [ -n "$plugins" ]; then
	fail "extensions déjà actives sur le site vierge : $plugins"
fi
echo "Site vierge : thème $theme, aucune extension active."

# Référence : options et métadonnées du site vierge, avant l'extension.
curl -fsS -o /dev/null "$site/"
wp option list --field=option_name --orderby=option_name >"$work/options-before"

wp plugin activate fluffless-consent >/dev/null
curl -fsS "$site/" >"$work/home-empty.html"
if grep -q 'id="fluffless-consent"\|consent/consent.js' "$work/home-empty.html"; then
	fail "bannière ou script servis alors qu'aucun service n'est déclaré"
fi
node "$root/tools/consent-alone-dom.mjs" resources "$work/home-empty.html" "$host" || fail "ressources externes sur l'accueil sans service"
log_is_empty "activation et accueil sans service"

# Écrans d'administration, avec des témoins de session générés par WP-CLI (aucun mot de passe).
wp eval 'echo AUTH_COOKIE . "=" . rawurlencode( wp_generate_auth_cookie( 1, time() + HOUR_IN_SECONDS, "auth" ) ) . "; " . LOGGED_IN_COOKIE . "=" . rawurlencode( wp_generate_auth_cookie( 1, time() + HOUR_IN_SECONDS, "logged_in" ) );' >"$work/cookies"
admin() {
	curl -sS -b "$(cat "$work/cookies")" "$@"
}
for screen in "index.php" "plugins.php" "options-general.php?page=fluffless-consent" "options-privacy.php?tab=policyguide"; do
	code="$(admin -o "$work/screen.html" -w '%{http_code}' "$site/wp-admin/$screen")"
	if [ "$code" != 200 ]; then
		fail "wp-admin/$screen répond $code"
	elif grep -qiE '<b>(warning|notice|deprecated|fatal error)</b>|There has been a critical error' "$work/screen.html"; then
		fail "avis PHP affiché sur wp-admin/$screen"
	fi
done
admin -o "$work/settings.html" "$site/wp-admin/options-general.php?page=fluffless-consent"
grep -q 'name="fluffless_settings\[consent\]\[ga_id\]"' "$work/settings.html" || fail "champ GA4 absent de l'écran de réglages"
nonce="$(sed -n 's/.*name="_wpnonce" value="\([^"]*\)".*/\1/p' "$work/settings.html" | head -1)"
code="$(admin -o /dev/null -w '%{http_code}' "$site/wp-admin/options.php" \
	--data-urlencode "option_page=fluffless_consent" --data-urlencode "action=update" \
	--data-urlencode "_wpnonce=$nonce" --data-urlencode "_wp_http_referer=/wp-admin/options-general.php?page=fluffless-consent" \
	--data-urlencode "fluffless_settings[consent][profile]=quebec" \
	--data-urlencode "fluffless_settings[consent][ga_id]=G-TEST12345" \
	--data-urlencode "fluffless_settings[consent][retention_months]=99")"
[ "$code" = 302 ] || fail "enregistrement des réglages : options.php répond $code (attendu : 302)"
saved="$(wp option get fluffless_settings --format=json)"
node -e '
	const c = JSON.parse(process.argv[1]).consent || {};
	if (c.ga_id !== "G-TEST12345" || c.retention_months !== 13 || c.profile !== "quebec") {
		console.error("Échec : réglages enregistrés inattendus : " + JSON.stringify(c));
		process.exit(1);
	}' "$saved" || fail "réglages non enregistrés ou non assainis par options.php"
# Avec GA4 déclaré, le guide de politique de confidentialité porte la section de l'extension.
admin -o "$work/guide.html" "$site/wp-admin/options-privacy.php?tab=policyguide"
grep -q 'Fluffless Consent: cookies and consent' "$work/guide.html" || fail "section de l'extension absente du guide de politique de confidentialité"
log_is_empty "écrans d'administration et enregistrement"

# Un contenu intégré YouTube (bloc HTML) dans un article publié.
post_id="$(wp post create --post_type=post --post_status=publish --post_title="Vidéo" --user=1 --porcelain \
	--post_content='<!-- wp:html --><iframe src="https://www.youtube-nocookie.com/embed/aqz-KE-bpKQ" width="560" height="315" title="Vidéo"></iframe><!-- /wp:html -->')"
for path in "/" "/?p=$post_id"; do
	curl -fsS "$site$path" >"$work/page.html"
	grep -q 'id="fluffless-consent"' "$work/page.html" || fail "bannière absente de $path avec GA4 déclaré"
	node "$root/tools/consent-alone-dom.mjs" resources "$work/page.html" "$host" || fail "ressource externe avant consentement sur $path"
done
node "$root/tools/consent-alone-dom.mjs" choices "$work/page.html" "$root/packages/fluffless-consent/assets/consent/consent.js" || fail "refus ou acceptation sur la bannière servie"
log_is_empty "pages servies avec la bannière"

# Désinstallation : rien de l'extension ne reste.
"${compose[@]}" run --rm -T cli wp plugin uninstall fluffless-consent --deactivate >/dev/null 2>"$work/uninstall.err" ||
	fail "wp plugin uninstall a échoué : $(tail -3 "$work/uninstall.err")"
wp option list --field=option_name --orderby=option_name >"$work/options-after"
new="$(comm -13 "$work/options-before" "$work/options-after")"
if printf '%s\n' "$new" | grep -qiE 'fluffless|consent'; then
	fail "options laissées par l'extension (transients compris) : $(printf '%s' "$new" | grep -iE 'fluffless|consent' | tr '\n' ' ')"
fi
left="$(printf '%s\n' "$new" | grep -viE '^_(site_)?transient|fluffless|consent' || true)"
if wp cron event list --field=hook | grep -qiE 'fluffless|consent'; then
	fail "événement planifié laissé par l'extension"
fi
[ -z "$left" ] || echo "Options nouvelles du cœur après le parcours (hors extension) : $(printf '%s' "$left" | tr '\n' ' ')"
meta="$(wp db query "SELECT COUNT(*) FROM $(wp db prefix)usermeta WHERE meta_key LIKE '%fluffless%' OR meta_key LIKE '%consent%'; SELECT COUNT(*) FROM $(wp db prefix)postmeta WHERE meta_key LIKE '%fluffless%' OR meta_key LIKE '%consent%';" --skip-column-names | xargs)"
[ "$meta" = "0 0" ] || fail "métadonnées laissées (utilisateurs, articles) : $meta"
if wp plugin is-installed fluffless-consent; then
	fail "dossier de l'extension encore présent après désinstallation"
fi
log_is_empty "désinstallation"

if [ "$failures" -gt 0 ]; then
	echo "Consent seul : $failures échec(s)." >&2
	exit 1
fi
echo "Consent seul : site vierge, réglages, bannière, refus, acceptation et désinstallation OK, debug.log vide."
