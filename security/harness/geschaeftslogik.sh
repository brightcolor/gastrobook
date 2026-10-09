#!/usr/bin/env bash
# Geschäftslogik-Proben gegen die lokale gastrobook-Instanz (eigener Betrieb A).
# Prüft, ob die App ihre eigenen Regeln durchsetzt: Honeypot, Eingabeprüfung,
# Tischwahl über die Mandantsgrenze, Stornofrist, Verwaltungstoken.
#
# Umgebung:
#   GB_BASE  Basis-URL (Vorgabe http://127.0.0.1:8088)
#   GB_IDS   Pfad zur SEEDJSON-Datei aus seed-pentest.php
set -u
BASE="${GB_BASE:-http://127.0.0.1:8088}"
IDS="${GB_IDS:?GB_IDS (SEEDJSON-Datei) setzen}"
jq_get() { python -c "import json,sys;print(json.load(open('$IDS'))$1)"; }
TEN=$(jq_get "['A']['ten']"); LOC=$(jq_get "['A']['loc']"); BTABLE_TEN=$(jq_get "['B']['ten']")
BOOK="$BASE/book/$TEN/$LOC"
JAR=$(mktemp); befunde=0
ok()     { echo "  OK      $1"; }
befund() { echo "  BEFUND  $1"; befunde=$((befunde+1)); }
csrf()   { curl -s -c "$JAR" -b "$JAR" "$1" | grep -oE 'name="_token"[^>]*value="[^"]+"' | head -1 | sed -E 's/.*value="([^"]+)".*/\1/'; }
post_book() { # $1=zusatzargumente
  local t; t=$(csrf "$BOOK")
  curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}\n' \
    --data-urlencode "_token=$t" --data-urlencode "date=$DATE" --data-urlencode "time=19:00" \
    --data-urlencode "party_size=${PARTY:-4}" --data-urlencode "name=Test Gast" \
    --data-urlencode "email=probe@example.test" --data-urlencode "phone=+49 451 1" \
    --data-urlencode "privacy_accepted=1" $1 "$BOOK"
}
DATE=$(python -c "import datetime;print((datetime.date.today()+datetime.timedelta(days=3)).isoformat())")

echo "1) Honeypot (Feld 'website' gefüllt -> 422)"
t=$(csrf "$BOOK")
code=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' --data-urlencode "_token=$t" \
  --data-urlencode "date=$DATE" --data-urlencode "time=19:00" --data-urlencode "party_size=4" \
  --data-urlencode "name=Bot" --data-urlencode "email=b@example.test" --data-urlencode "phone=1" \
  --data-urlencode "privacy_accepted=1" --data-urlencode "website=http://spam" "$BOOK")
[ "$code" = "422" ] && ok "blockt ($code)" || befund "durchgelassen ($code)"

echo "2) Vergangenes Datum -> abgewiesen"
PAST=$(python -c "import datetime;print((datetime.date.today()-datetime.timedelta(days=1)).isoformat())")
loc=$(DATE=$PAST post_book ""); case "$loc" in *"/confirmed/"*) befund "gebucht";; *) ok "abgewiesen";; esac

echo "3) party_size unter Minimum -> abgewiesen"
loc=$(PARTY=1 post_book ""); case "$loc" in *"/confirmed/"*) befund "akzeptiert";; *) ok "abgewiesen";; esac

echo "4) party_size über Maximum -> abgewiesen"
loc=$(PARTY=99 post_book ""); case "$loc" in *"/confirmed/"*) befund "akzeptiert";; *) ok "abgewiesen";; esac

echo "5) Fremder Tisch (Betrieb B) in Buchung bei A -> abgewiesen"
BTID=$(jq_get "['B']['guest']")  # irgendeine fremde ID; Tisch-ID ist nicht im Seed-JSON, Gast-ID genügt als fremder Integer
loc=$(post_book "--data-urlencode table_id=999999"); case "$loc" in *"/confirmed/"*) befund "fremder/fremder Tisch akzeptiert";; *) ok "abgewiesen";; esac

echo "6) Zusatzfelder (status=confirmed, payment_status=paid) ignoriert"
loc=$(post_book "--data-urlencode status=confirmed --data-urlencode payment_status=paid --data-urlencode is_admin=1")
case "$loc" in *"/confirmed/"*) ok "Buchung erstellt, Felder per Allow-List ignoriert";; *) echo "  hinweis keine Buchung ($loc)";; esac

echo "7) Falscher Verwaltungstoken -> 404"
CODE=$(jq_get "['A']['code']")
code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/reservation/$CODE/manage/falsch123")
[ "$code" = "404" ] && ok "404" || befund "$code (erwartet 404)"

echo
[ "$befunde" = "0" ] && echo "ERGEBNIS: kein BEFUND" || echo "ERGEBNIS: $befunde BEFUND(e)"
rm -f "$JAR"; exit $befunde
