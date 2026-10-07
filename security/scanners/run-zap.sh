#!/usr/bin/env bash
# Angemeldeter, tiefer ZAP-Lauf gegen die lokale Instanz.
# Baut bei Bedarf das Regel-Image, legt die Session auf ein Host-Verzeichnis
# (nicht in die enge Container-Schicht) und schreibt den Report nach
# security/out/zap-deep.{xml,html}.
#
# Voraussetzung: security/zap/pw.env mit GB_PW (aus pw.env.example).
set -eu
DIR="$(cd "$(dirname "$0")/.." && pwd)"
ZAP="$DIR/zap"
OUT="$DIR/out"
mkdir -p "$OUT" "$OUT/zap-session"

[ -f "$ZAP/pw.env" ] || { echo "security/zap/pw.env fehlt – aus pw.env.example anlegen." >&2; exit 1; }

if ! docker image inspect gastrobook-zap:deep >/dev/null 2>&1; then
  echo "Baue gastrobook-zap:deep (Beta/Alpha-Regeln) ..."
  docker build -t gastrobook-zap:deep "$ZAP"
fi

# /zap/wrk = Plan + Reports; die Session liegt separat auf dem Host-Mount,
# damit ZAPs eingebettete DB nicht die Container-Schicht sprengt.
docker run --rm --shm-size=2g --env-file "$ZAP/pw.env" \
  -v "$ZAP:/zap/wrk:rw" -v "$OUT:/zap/out:rw" \
  gastrobook-zap:deep \
  zap.sh -cmd -newsession /zap/out/zap-session/s \
         -config oast.activeScanService=Interactsh \
         -autorun /zap/wrk/plan-deep.yaml

# Reports schreibt der Plan nach /zap/wrk (= security/zap); dorthin verschoben
mv -f "$ZAP"/zap-deep.xml "$ZAP"/zap-deep.html "$OUT"/ 2>/dev/null || true
echo "Report: $OUT/zap-deep.html"
