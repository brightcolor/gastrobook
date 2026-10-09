#!/usr/bin/env bash
# nuclei gegen die lokale Instanz (aus dem Container über host.docker.internal).
# Ausgabe als JSONL nach security/out/nuclei.jsonl.
#
#   security/scanners/run-nuclei.sh [ziel]
# ziel-Vorgabe: http://host.docker.internal:8088
set -eu
TARGET="${1:-http://host.docker.internal:8088}"
OUT="$(cd "$(dirname "$0")/.." && pwd)/out"
mkdir -p "$OUT"
docker run --rm -v "$OUT:/out" projectdiscovery/nuclei:latest \
  -u "$TARGET" -jsonl -o /out/nuclei.jsonl \
  -severity info,low,medium,high,critical \
  -etags intrusive,dos,fuzz,brute -rl 80 -c 20 -nc
echo "Treffer: $(wc -l < "$OUT/nuclei.jsonl" 2>/dev/null || echo 0) -> $OUT/nuclei.jsonl"
