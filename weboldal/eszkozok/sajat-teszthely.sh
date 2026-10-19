#!/bin/sh
# Saját, elszigetelt tesztoldal egy ügynöknek / futásnak (a közös fejlesztői oldal másolata, külön SQLite-adatbázissal).
#   sh weboldal/eszkozok/sajat-teszthely.sh <nev>   →  /home/claude/env/<nev>/wp
# Futtatás utána:
#   SDH_TESZT_ADATBAZIS=1 SDH_WP_LOAD=/home/claude/env/<nev>/wp/wp-load.php \
#   SDH_TESZT_DB=/home/claude/env/<nev>/wp/wp-content/database/sales.sqlite SDH_TESZT_ALAP=/home/claude/env/<nev>/alap.sqlite \
#   php weboldal/tesztek/futtat.php [szűrő]
set -e
NEV="${1:?nev kell}"
CEL="/home/claude/env/$NEV"
if [ -d "$CEL/wp" ]; then echo "megvan: $CEL/wp"; exit 0; fi
mkdir -p "$CEL"
cp -a /home/claude/env/site/wp "$CEL/wp"
rm -f "$CEL/wp/wp-content/database/sales.sqlite"*
cp /home/claude/env/alap-ures.sqlite "$CEL/wp/wp-content/database/sales.sqlite"
cp /home/claude/env/alap-ures.sqlite "$CEL/alap.sqlite"
echo "kész: $CEL/wp"
