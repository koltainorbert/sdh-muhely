#!/bin/sh
# Az értékesítő webhely csomagjai – semleges mappa- és fájlnevekkel (REJTES.md: a kimenetben nem lehet „sdh”).
#   sh weboldal/eszkozok/csomagol.sh [kimeneti mappa]     (alap: weboldal/dist)
# Kimenet:
#   portal-<v>.zip     – az értékesítő bővítmény (mappa: portal/, fő fájl: portal.php)
#   kirakat-<v>.zip    – a téma (mappa: kirakat/)
#   bemutato-<v>.zip   – a demó-korlátozó (mappa: bemutato/, fő fájl: bemutato.php)
# A csomagba nem kerül: tesztek, fejlesztői dokumentáció, forrás-segédek (bin/), .map, rejtett fájlok.
# Ellenőrzés: a csomagolt fájlok nevében nincs „sdh”, a kiszolgált (nem PHP) fájlokban nincs tiltott minta.
set -e
GYOKER="$(cd "$(dirname "$0")/.." && pwd)"
KI="${1:-$GYOKER/dist}"
MUNKA="$(mktemp -d)"
mkdir -p "$KI"

verzio() { grep -m1 -E "^\s*\*?\s*Version:" "$1" | sed -E 's/.*Version:\s*//' | tr -d '\r '; }

# --- értékesítő bővítmény ---------------------------------------------------
V_SALES="$(verzio "$GYOKER/sales/sdh-sales/sdh-sales.php")"
mkdir -p "$MUNKA/portal"
cp -a "$GYOKER/sales/sdh-sales/." "$MUNKA/portal/"
mv "$MUNKA/portal/sdh-sales.php" "$MUNKA/portal/portal.php"
sed -i 's/^ \* Plugin Name:.*/ * Plugin Name:       Portál/' "$MUNKA/portal/portal.php"
# Belső fájlnevek semlegesen (a betöltő mindkét előtagot ismeri), a közvetlen webes elérés tiltva.
for f in "$MUNKA"/portal/includes/class-sdh-sales*.php; do
  n="$(basename "$f" | sed 's/^class-sdh-sales/class-p/')"; mv "$f" "$MUNKA/portal/includes/$n"
done
for d in includes data; do
  printf 'Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n' > "$MUNKA/portal/$d/.htaccess"
  [ -f "$MUNKA/portal/$d/index.php" ] || printf '<?php\n// Csend.\n' > "$MUNKA/portal/$d/index.php"
done

# --- téma ---------------------------------------------------------------------
V_TEMA="$(verzio "$GYOKER/tema/sdh-sales-tema/style.css")"
mkdir -p "$MUNKA/kirakat"
cp -a "$GYOKER/tema/sdh-sales-tema/." "$MUNKA/kirakat/"
rm -rf "$MUNKA/kirakat/bin"

# --- demó-korlátozó ---------------------------------------------------------
V_DEMO=""
DEMO_DIR="$(ls -d "$GYOKER"/demo/*/ 2>/dev/null | head -1)"
FO=""
[ -n "$DEMO_DIR" ] && FO="$(grep -l "Plugin Name:" "$DEMO_DIR"*.php 2>/dev/null | head -1)"
if [ -n "$FO" ]; then
  DEMO_DIR="${DEMO_DIR%/}"
  V_DEMO="$(verzio "$FO")"
  mkdir -p "$MUNKA/bemutato"
  cp -a "$DEMO_DIR/." "$MUNKA/bemutato/"
  mv "$MUNKA/bemutato/$(basename "$FO")" "$MUNKA/bemutato/bemutato.php"
  sed -i 's/^ \* Plugin Name:.*/ * Plugin Name:       Bemutató/' "$MUNKA/bemutato/bemutato.php"
  # A demó készülékképei a bővítmény kepek/ mappájába (a generátor ott is keresi).
  [ -d "$GYOKER/demo-adat/kepek" ] && cp -a "$GYOKER/demo-adat/kepek" "$MUNKA/bemutato/kepek"
fi

# --- takarítás ---------------------------------------------------------------
find "$MUNKA" \( -name '.*' -not -name '.htaccess' -o -name '*.map' -o -name 'node_modules' -o -name 'tesztek' \) -prune -exec rm -rf {} + 2>/dev/null || true

# --- ellenőrzés ----------------------------------------------------------------
HIBA=0
if find "$MUNKA" | grep -i 'sdh' >/dev/null; then
  echo "HIBA: „sdh” a fájlnevekben:"; find "$MUNKA" | grep -i 'sdh' | head -20; HIBA=1
fi
# A böngészőnek kiszolgált fájlok (css, js, json, svg, txt, html) nem tartalmazhatnak tiltott mintát.
TILT='wordpress|/wp-(content|includes|admin|json)/|wp-login\.php|admin-ajax\.php|\bsdh\b|sdh[-_]|[-_]sdh|api\.w\.org|rank ?math|wp ?rocket|site ?kit'
SZIV="$(find "$MUNKA" -type f \( -name '*.css' -o -name '*.js' -o -name '*.json' -o -name '*.svg' -o -name '*.txt' -o -name '*.html' \) -not -path '*/data/tudas/*' -print0 | xargs -0 grep -liE "$TILT" 2>/dev/null || true)"
if [ -n "$SZIV" ]; then
  echo "HIBA: tiltott minta a kiszolgált fájlokban:"; echo "$SZIV" | sed "s#$MUNKA/##" | head -30; HIBA=1
fi

# --- zip ------------------------------------------------------------------------
( cd "$MUNKA" && rm -f "$KI/portal-$V_SALES.zip" && zip -qr "$KI/portal-$V_SALES.zip" portal )
( cd "$MUNKA" && rm -f "$KI/kirakat-$V_TEMA.zip" && zip -qr "$KI/kirakat-$V_TEMA.zip" kirakat )
[ -n "$V_DEMO" ] && ( cd "$MUNKA" && rm -f "$KI/bemutato-$V_DEMO.zip" && zip -qr "$KI/bemutato-$V_DEMO.zip" bemutato )
rm -rf "$MUNKA"
ls -la "$KI"/*.zip
[ "$HIBA" = 0 ] && echo "Csomagolás: OK" || { echo "Csomagolás: HIBA (a zip elkészült, de javítani kell)"; exit 1; }
