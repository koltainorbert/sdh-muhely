#!/bin/sh
# Műhely – VEVŐI telepítőcsomag (0.96.1; 0.99.5: LICENSE, terméknév, munkafa-mód).
#
# A fő ág tartalma, de NÉLKÜLE:
#   - includes/sdh/   (a saját példány belső moduljai és alapnevei – eladáskor nem mehetnek ki),
#   - tools/          (fejlesztői segédletek),
#   - README.md       (fejlesztői verziónapló),
#   - sugo/27-sdh-belso.md (a belső modulok súgója),
#   - tesztek/, kozpont/, weboldal/, tesztadat/ (ezek a .gitattributes miatt eleve kimaradnak).
# BENNE van: LICENSE (GNU GPL v2 – a kód GPL v2 vagy későbbi alatt terjed; hiánya esetén a szkript hibával áll le).
# A zipben a fő fájl fejlécében a bővítmény neve a termék neve (alap: Munkora; VEVO_TERMEKNEV-vel más adható).
# Ezt a zipet kell feltölteni a licencközpont „Kiadás” fülén, és ezt kapja a vevő.
#
#   sh tools/vevo-csomag.sh                       # → sdh-muhely-<verzió>-vevo.zip a repo gyökerében (a HEAD-ből)
#   sh tools/vevo-csomag.sh /ut/x.zip             # → a megadott helyre
#   sh tools/vevo-csomag.sh /ut/x.zip --munkafa   # a munkafából (még nem véglegesített változásokkal; próbához)
set -e
cd "$(dirname "$0")/.."
V=$(sed -n 's/^ \* Version: *//p' sdh-muhely.php | head -1 | tr -d ' ')
KI=${1:-"sdh-muhely-$V-vevo.zip"}
MOD=${2:-head}
NEV=${VEVO_TERMEKNEV:-Munkora}
rm -f "$KI"

if [ "$MOD" = "--munkafa" ]; then
    git ls-files -co --exclude-standard -z > "$KI.lista"
else
    git archive --format=zip --prefix=sdh-muhely/ -o "$KI" HEAD
fi

python3 - "$KI" "$MOD" "$NEV" <<'PY'
import os, re, subprocess, sys, zipfile
ut, mod, nev = sys.argv[1], sys.argv[2], sys.argv[3]
tmp = ut + '.tmp'
KIHAGY = ('sdh-muhely/includes/sdh/', 'sdh-muhely/tools/')
KIHAGY_FAJL = ('sdh-muhely/README.md', 'sdh-muhely/sugo/27-sdh-belso.md')

def tartalom():
    if mod != '--munkafa':
        with zipfile.ZipFile(ut) as be:
            for i in be.infolist():
                yield i.filename, i, (None if i.is_dir() else be.read(i.filename))
        return
    # Munkafa: a git által követett és a nem kizárt új fájlok, a .gitattributes export-ignore szabályaival
    # (a mappára illeszkedő szabály a teljes mappát kizárja, ahogy a git archive is).
    utak = [p for p in open(ut + '.lista', 'rb').read().decode('utf-8').split('\0') if p and os.path.isfile(p)]
    os.remove(ut + '.lista')
    elemek = set(utak)
    for p in utak:
        d = os.path.dirname(p)
        while d:
            elemek.add(d)
            d = os.path.dirname(d)
    attr = subprocess.run(['git', 'check-attr', '-z', '--stdin', 'export-ignore'], input='\0'.join(sorted(elemek)).encode('utf-8'),
                          capture_output=True, check=True).stdout.decode('utf-8').split('\0')
    tiltott = {attr[i] for i in range(0, len(attr) - 2, 3) if attr[i + 2] == 'set'}
    for p in sorted(utak):
        reszek = p.split('/')
        if any('/'.join(reszek[:k]) in tiltott for k in range(1, len(reszek) + 1)):
            continue
        with open(p, 'rb') as f:
            yield 'sdh-muhely/' + p, None, f.read()

van_licenc = False
with zipfile.ZipFile(tmp, 'w', zipfile.ZIP_DEFLATED) as ki:
    for n, info, adat in tartalom():
        if n.startswith(KIHAGY) or n in KIHAGY_FAJL:
            continue
        if adat is None:
            ki.writestr(info, b'')
            continue
        if n == 'sdh-muhely/LICENSE':
            van_licenc = b'GNU GENERAL PUBLIC LICENSE' in adat and b'Version 2, June 1991' in adat
        if n == 'sdh-muhely/sdh-muhely.php':
            szoveg = adat.decode('utf-8')
            szoveg = re.sub(r'^( \* Plugin Name:\s*).*$', lambda m: m.group(1) + nev, szoveg, count=1, flags=re.M)
            adat = szoveg.encode('utf-8')
        if info is not None:
            ki.writestr(info, adat)
        else:
            ki.writestr(zipfile.ZipInfo(n, date_time=(2026, 1, 1, 0, 0, 0)), adat, zipfile.ZIP_DEFLATED)
if not van_licenc:
    os.remove(tmp)
    sys.exit('HIBA: a LICENSE (GNU GPL v2) hiányzik a csomagból')
os.replace(tmp, ut)
PY
echo "Kész: $KI (verzió $V, $MOD)"
