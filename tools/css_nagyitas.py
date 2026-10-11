#!/usr/bin/env python3
"""
A stíluslapok képpontméretei a betűnagyítás változójával (--sdh-n) szorozva.

    python3 tools/css_nagyitas.py            # átírja az assets/*.css fájlokat
    python3 tools/css_nagyitas.py --ellenoriz  # csak megnézi: 0-val lép ki, ha nincs teendő

Miért: az Áttekintés csúszkája (és a Beállítások „Alap betűméret" mezője) a
--sdh-n változót állítja (1 = 100%). A rem / em értékek a gyökér betűméretén
át maguktól követik; a képpontban (px) megadott méretek (betűméret, magasság,
térköz, lekerekítés) ettől a szorzótól nőnek együtt a betűvel:

    font-size: 13px;   →   font-size: calc(13px * var(--sdh-n));

A szkript többször is futtatható: ami már át van írva, ahhoz nem nyúl. Új
CSS írása után elég lefuttatni – a t34 teszt bukik, ha kimaradt.

NEM írja át:
  - a keretek és körvonalak vastagságát, az árnyékokat (hajszálvonal maradjon),
  - a @media feltételeit és a @media print blokkok tartalmát (a nyomtatott
    címke és bizonylat mérete nem függhet a képernyő nagyításától),
  - a megjegyzéseket, a szövegeket és az url(...) tartalmát,
  - az árnyék / neon változókat (--sdh-arnyek…, --sdh-popneon…, …izzas…),
  - az ügyféloldal stíluslapját (rma-ugyfel.css): az nem a műhely-felület.
"""

import re
import sys
from pathlib import Path

GYOKER = Path(__file__).resolve().parent.parent / 'assets'
KIHAGY_FAJL = {'rma-ugyfel.css'}
VALTOZO = 'var(--sdh-n)'

# Ezeknél a tulajdonságoknál a képpont marad: vonalvastagság és árnyék.
KIHAGY_TULAJDONSAG = re.compile(
    r'^(border(-(top|right|bottom|left|block|inline)(-(start|end))?)?(-width)?'
    r'|outline(-width|-offset)?|column-rule(-width)?'
    r'|box-shadow|text-shadow|filter|backdrop-filter|-webkit-backdrop-filter'
    r'|stroke-width|-webkit-text-stroke(-width)?)$'
)
KIHAGY_VALTOZO = re.compile(r'arnyek|izzas|neon')

VEDETT = re.compile(r'/\*.*?\*/|"(?:\\.|[^"\\])*"|\'(?:\\.|[^\'\\])*\'|url\((?:[^)"\']|"[^"]*"|\'[^\']*\')*\)', re.S)
PX = re.compile(r'(?<![\w.#\x00])(-?(?:\d+\.?\d*|\.\d+))px\b(?!\s*\*\s*var\(--sdh-n\))')


def ertek_atir(ertek: str) -> str:
    def csere(m: re.Match) -> str:
        return m.group(0) if float(m.group(1)) == 0 else f'calc({m.group(1)}px * {VALTOZO})'

    return PX.sub(csere, ertek)


def deklaracio_atir(szoveg: str) -> str:
    """Egy `tulajdonság: érték` darab (pontosvessző nélkül)."""
    if ':' not in szoveg:
        return szoveg

    nev, ertek = szoveg.split(':', 1)
    tiszta = re.sub(r'\x00\d+\x00', '', nev).strip().lower()

    if tiszta.startswith('--'):
        if KIHAGY_VALTOZO.search(tiszta) or tiszta == '--sdh-n':
            return szoveg
    elif KIHAGY_TULAJDONSAG.match(tiszta) or not re.match(r'^[a-z-]+$', tiszta):
        return szoveg

    return nev + ':' + ertek_atir(ertek)


def atir(css: str) -> str:
    vedett: list[str] = []

    def eltesz(m: re.Match) -> str:
        vedett.append(m.group(0))

        return f'\x00{len(vedett) - 1}\x00'

    css = VEDETT.sub(eltesz, css)

    ki: list[str] = []
    verem: list[bool] = []      # nyitott blokkonként: nyomtatási (@media print) blokkban vagyunk-e
    darab = ''

    for jel in css:
        if jel == '{':
            fej = re.sub(r'\x00\d+\x00', '', darab).strip().lower()
            nyomtat = (verem[-1] if verem else False) or (fej.startswith('@media') and 'print' in fej)
            verem.append(nyomtat)
            ki.append(darab + jel)
            darab = ''
        elif jel in ';}':
            kell = bool(verem) and not verem[-1]
            ki.append((deklaracio_atir(darab) if kell else darab) + jel)
            darab = ''

            if jel == '}' and verem:
                verem.pop()
        else:
            darab += jel

    ki.append(darab)

    return re.sub(r'\x00(\d+)\x00', lambda m: vedett[int(m.group(1))], ''.join(ki))


def main() -> int:
    ellenoriz = '--ellenoriz' in sys.argv
    teendo = 0

    for fajl in sorted(GYOKER.glob('*.css')):
        if fajl.name in KIHAGY_FAJL:
            continue

        regi = fajl.read_text(encoding='utf-8')
        uj = atir(regi)

        if uj != regi:
            teendo += 1

            if ellenoriz:
                print(f'átírandó: {fajl.name}')
            else:
                fajl.write_text(uj, encoding='utf-8')
                print(f'átírva: {fajl.name}')

    if ellenoriz:
        print('Rendben: minden képpontméret a nagyítással szorzódik.' if teendo == 0 else f'{teendo} fájlban van átírandó méret.')

    return 1 if (ellenoriz and teendo) else 0


if __name__ == '__main__':
    sys.exit(main())
