#!/bin/sh
# SDH Műhely – VEVŐI telepítőcsomag (0.96.1).
#
# A fő ág tartalma, de NÉLKÜLE:
#   - includes/sdh/   (az SDH saját, belső moduljai – eladáskor nem mehetnek ki),
#   - tools/          (fejlesztői segédletek),
#   - README.md       (fejlesztői verziónapló),
#   - sugo/27-sdh-belso.md (a belső modulok súgója),
#   - tesztek/, kozpont/ (ezek a .gitattributes miatt eleve kimaradnak).
# Ezt a zipet kell feltölteni az SDH Központ „Kiadás” fülén, és ezt kapja a vevő.
#
#   sh tools/vevo-csomag.sh            # → sdh-muhely-<verzió>-vevo.zip a repo gyökerében
#   sh tools/vevo-csomag.sh /ut/x.zip  # → a megadott helyre
set -e
cd "$(dirname "$0")/.."
V=$(sed -n 's/^ \* Version: *//p' sdh-muhely.php | head -1 | tr -d ' ')
KI=${1:-"sdh-muhely-$V-vevo.zip"}
rm -f "$KI"
git archive --format=zip --prefix=sdh-muhely/ -o "$KI" HEAD
python3 - "$KI" <<'PY'
import sys, zipfile, shutil, os
ut = sys.argv[1]
tmp = ut + '.tmp'
with zipfile.ZipFile(ut) as be, zipfile.ZipFile(tmp, 'w', zipfile.ZIP_DEFLATED) as ki:
    for i in be.infolist():
        n = i.filename
        if n.startswith(('sdh-muhely/includes/sdh/', 'sdh-muhely/tools/')) or n in ('sdh-muhely/README.md', 'sdh-muhely/sugo/27-sdh-belso.md'):
            continue
        ki.writestr(i, be.read(n))
os.replace(tmp, ut)
PY
echo "Kész: $KI (verzió $V)"
