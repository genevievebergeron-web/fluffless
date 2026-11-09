#!/usr/bin/env bash
# Caractérisation de vrais Umami et Plausible auto-hébergés pour le module Rapport carbone (le module
# lui-même n'est pas exécuté ; certains « ok » valident un refus de l'API, pas un bon comportement).
# Rejoue, tels quels, les en-têtes et les paramètres que Carbon::fetch_umami() et fetch_plausible()
# construisent (voir tests/carbone-test.php, tests 7 (Plausible) et 9 (Umami), qui les figent). Aucune dépendance à WordPress.
# Projets compose isolés (noms fl-stats-*), ports 127.0.0.1:3010 et 8100 ; retire tout avec « down -v ».
#
#   tests/integration/stats-providers.sh up      démarre, crée site et clés, envoie 3 visites
#   tests/integration/stats-providers.sh check   exécute les vérifications
#   tests/integration/stats-providers.sh down    arrête et supprime
set -euo pipefail
cd "$(dirname "$0")"
U=http://127.0.0.1:3010
P=http://127.0.0.1:8100
STATE="${TMPDIR:-/tmp}/fl-stats-int"
UA='Mozilla/5.0 (Macintosh) Chrome/120 Safari/537'
mkdir -p "$STATE"

up() {
	docker compose -f umami.compose.yml up -d
	docker compose -f plausible.compose.yml up -d
	until curl -sf "$U/api/heartbeat" >/dev/null; do sleep 2; done
	until curl -sf "$P/api/health" >/dev/null; do sleep 2; done
	sleep 15 # migrations Plausible
	local tok wid
	tok=$(curl -s -X POST "$U/api/auth/login" -H 'Content-Type: application/json' -d '{"username":"admin","password":"umami"}' | python3 -c 'import sys,json;print(json.load(sys.stdin)["token"])')
	wid=$(curl -s -X POST "$U/api/websites" -H "Authorization: Bearer $tok" -H 'Content-Type: application/json' -d '{"name":"t","domain":"example.test"}' | python3 -c 'import sys,json;print(json.load(sys.stdin)["id"])')
	echo "$tok" >"$STATE/tok"; echo "$wid" >"$STATE/wid"
	local C=fl-stats-plausible-plausible-1 key
	key=$(printf 'k%.0s' $(seq 1 64)); echo "$key" >"$STATE/pkey"
	docker exec "$C" bin/plausible rpc '
{:ok, u} = Plausible.Auth.User.new(%{name: "T", email: "t@example.test", password: "correct-horse-battery-1", password_confirmation: "correct-horse-battery-1"}) |> Ecto.Changeset.apply_action(:insert)
u = Plausible.Repo.insert!(u)
{:ok, _} = Plausible.Sites.create(u, %{"domain" => "example.test", "timezone" => "UTC"})
cs = Plausible.Auth.ApiKey.changeset(%Plausible.Auth.ApiKey{team_id: 1, user_id: u.id}, %{"name" => "int", "key" => "'"$key"'", "scopes" => ["stats:read:*"]})
{:ok, _} = Plausible.Repo.insert(cs)' >/dev/null
	send_umami() {
		for p in /a /a /b; do
			curl -s -o /dev/null -X POST "$U/api/send" -H 'Content-Type: application/json' -H "User-Agent: $UA" \
				-d "{\"type\":\"event\",\"payload\":{\"website\":\"$wid\",\"hostname\":\"example.test\",\"url\":\"$p\",\"language\":\"fr\",\"screen\":\"1x1\"}}"
		done
	}
	send_plausible() {
		for p in /a /a /b; do
			curl -s -o /dev/null -X POST "$P/api/event" -H 'Content-Type: application/json' -H "User-Agent: $UA" -H 'X-Forwarded-For: 203.0.113.7' \
				-d "{\"name\":\"pageview\",\"url\":\"http://example.test$p\",\"domain\":\"example.test\"}"
		done
	}
	send_umami
	# Plausible ignore les visites d'un site créé à l'instant : renvoyer jusqu'à ce qu'elles soient comptées (sans doublon).
	local today
	today=$(date -u +%F)
	for _ in 1 2 3 4 5 6; do
		send_plausible
		sleep 12
		if curl -s -X POST "$P/api/v2/query" -H "Authorization: Bearer $key" -H 'Content-Type: application/json' \
			-d "{\"site_id\":\"example.test\",\"metrics\":[\"pageviews\"],\"date_range\":[\"$today\",\"$today\"]}" | grep -q '"metrics":\[[1-9]'; then
			break
		fi
	done
}

fails=0
code() { curl -s -o /dev/null -w '%{http_code}' "$@"; }
expect() { # description, attendu, obtenu
	if [ "$2" = "$3" ]; then echo "  ok   $1 ($3)"; else echo "  FAIL $1 : attendu $2, obtenu $3"; fails=$((fails + 1)); fi
}

check() {
	local tok wid key S E Q
	tok=$(cat "$STATE/tok"); wid=$(cat "$STATE/wid"); key=$(cat "$STATE/pkey")
	S=$(($(date +%s) - 3600))000; E=$(($(date +%s) + 3600))000; Q="startAt=$S&endAt=$E"
	echo "Umami auto-hébergé ($(docker exec fl-stats-int-umami-1 sh -c 'grep -m1 "\"version\"" package.json' | tr -d ' ,'))"
	# Carbon envoie x-umami-api-key ET Authorization: Bearer avec la même valeur (fetch_umami).
	expect "« clé d'API » inventée dans les deux en-têtes (cas annoncé par le site)" 401 \
		"$(code "$U/api/websites/$wid/stats?$Q" -H 'x-umami-api-key: abc' -H 'Authorization: Bearer abc')"
	expect "jeton de POST /api/auth/login placé dans FLUFFLESS_STATS_API_KEY : /stats" 200 \
		"$(code "$U/api/websites/$wid/stats?$Q" -H "x-umami-api-key: $tok" -H "Authorization: Bearer $tok")"
	expect "metrics/expanded type=path (ce que Carbon demande d'abord)" 200 \
		"$(code "$U/api/websites/$wid/metrics/expanded?$Q&type=path&limit=10" -H "x-umami-api-key: $tok" -H "Authorization: Bearer $tok")"
	expect "corps metrics/expanded : /a = 1 visiteur et 2 pages vues, /b = 1 et 1" "/a 1 2|/b 1 1" "$(curl -s "$U/api/websites/$wid/metrics/expanded?$Q&type=path&limit=10" -H "Authorization: Bearer $tok" \
		| python3 -c 'import sys,json;print("|".join(sorted("%s %s %s"%(r["name"],r["visitors"],r["pageviews"]) for r in json.load(sys.stdin))))')"
	expect "metrics type=url (repli d'Umami 2, refusé par Umami 3)" 400 \
		"$(code "$U/api/websites/$wid/metrics?$Q&type=url&limit=10" -H "Authorization: Bearer $tok")"
	expect "metrics type=path (nom actuel)" 200 \
		"$(code "$U/api/websites/$wid/metrics?$Q&type=path&limit=10" -H "Authorization: Bearer $tok")"
	echo "  info /metrics?type=path : $(curl -s "$U/api/websites/$wid/metrics?$Q&type=path&limit=10" -H "Authorization: Bearer $tok") (3 pages vues, 1 visiteur : y = visiteurs, pas pages vues)"
	echo "Plausible CE"
	expect "clé invalide" 401 "$(code -X POST "$P/api/v2/query" -H 'Authorization: Bearer nope' -H 'Content-Type: application/json' -d '{"site_id":"example.test","metrics":["visitors"],"date_range":"day"}')"
	local today; today=$(date -u +%F)
	expect "clé valide, requête de Carbon" 200 "$(code -X POST "$P/api/v2/query" -H "Authorization: Bearer $key" -H 'Content-Type: application/json' \
		-d "{\"site_id\":\"example.test\",\"metrics\":[\"visitors\",\"pageviews\"],\"date_range\":[\"$today\",\"$today\"],\"dimensions\":[\"event:page\"],\"pagination\":{\"limit\":1000}}")"
	expect "corps Plausible : /a = 1 visiteur et 2 pages vues, /b = 1 et 1" "/a 1 2|/b 1 1" "$(curl -s -X POST "$P/api/v2/query" -H "Authorization: Bearer $key" -H 'Content-Type: application/json' \
		-d "{\"site_id\":\"example.test\",\"metrics\":[\"visitors\",\"pageviews\"],\"date_range\":[\"$today\",\"$today\"],\"dimensions\":[\"event:page\"]}" \
		| python3 -c 'import sys,json;print("|".join(sorted("%s %s %s"%(r["dimensions"][0],*r["metrics"]) for r in json.load(sys.stdin)["results"])))')"
	expect "corps Umami type=path : y = visiteurs (/a=1 malgré 2 vues)" "/a 1|/b 1" "$(curl -s "$U/api/websites/$wid/metrics?$Q&type=path&limit=10" -H "Authorization: Bearer $tok" \
		| python3 -c 'import sys,json;print("|".join(sorted("%s %s"%(r["x"],r["y"]) for r in json.load(sys.stdin))))')"
	[ "$fails" -eq 0 ] && echo "Tout est conforme." || { echo "$fails échec(s)."; return 1; }
}

down() {
	docker compose -f umami.compose.yml down -v --remove-orphans
	docker compose -f plausible.compose.yml down -v --remove-orphans
	rm -rf "$STATE"
}

"${1:?usage: $0 up|check|down}"
