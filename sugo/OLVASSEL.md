# A súgó fejezetei – írási szabályok

Ez a mappa a rendszer **dokumentációja**. Ebből épül a Súgó menüpont (Bemutató, Dokumentáció, GYIK),
az oldalankénti túra, és ebből tanul az AI-asszisztens. Egy fejezet = egy `NN-kulcs.md` fájl.

**Kötelező szabály:** ha a rendszerben bármi új készül vagy bármi megváltozik, a hozzá tartozó fejezet
ugyanabban a körben frissül. A `tesztek/t36-sugo.php` elbukik, ha egy modulnak, egy Beállítások-lapfülnek
vagy az asszisztens egy eszközének nincs dokumentációja, vagy ha a `00-sugo.md` `verzio` sora nem egyezik
a bővítmény verziójával.

## Fejléc

A fájl elején, három kötőjel között, soronként `kulcs: érték`:

```
---
kulcs: munkalapok
cim: Munkalapok
csoport: Napi munka
modul: munkalapok
szakaszok: allapotok, hiba-allapotok
kulcsszavak: munkalap, javítás, felvétel, állapot
osszefoglalo: Egy mondat arról, mire való a fejezet tárgya.
---
```

- `kulcs` – a fájlnév a sorszám nélkül; csak kisbetű, szám, kötőjel.
- `csoport` – az egyik: `Kezdés`, `Napi munka`, `Pénz`, `Kapcsolat`, `Csapat és idő`, `Rendszer`.
- `modul` – a fejezet által leírt menüpont(ok) kulcsa vesszővel (pl. `penztar`); üres is lehet.
- `szakaszok` – a Beállítások azon lapfüleinek kulcsa, amelyeket EZ a fejezet ír le; üres is lehet.
- `kulcsszavak` – amire keresni fognak (rokon értelmű szavak, köznyelvi alakok is).

## Törzs

- A fejezet `##` szakaszokból áll. **Minden `##` szakasz önállóan is érthető legyen** (az asszisztens
  szakaszonként kapja meg): nevezd meg benne, melyik menüpontról van szó.
- Ajánlott szakaszok, ebben a sorrendben: `## Mire való`, `## Hol találod`, feladatonként egy
  `## Lépésről lépésre: …`, `## Mezők és gombok`, `## Szabályok, amiket a rendszer betart`,
  `## Tippek`, `## Ha valami nem megy`, végül `## GYIK` és `## Túra: <modulkulcs>`.
- A szakaszcímek egy fejezeten belül egyediek.

Használható jelölések (más nem):

| Jelölés | Eredmény |
|---|---|
| `**szöveg**` | félkövér – gombok, mezők pontos felirata |
| `*szöveg*` | dőlt |
| `` `szöveg` `` | kód, begépelendő érték |
| `[[Enter]]` | billentyű |
| `- ` és `1. ` | felsorolás, számozott lista (egy szint behúzás: két szóköz) |
| `### Alcím` | alcím a szakaszon belül |
| `> **Tipp:** …` | kiemelt doboz; a kezdőszó lehet `Tipp`, `Figyelem`, `Fontos`, `Példa` |
| `[felirat](sugo:fejezetkulcs)` | hivatkozás másik fejezetre |
| `[felirat](modul:modulkulcs)` | gomb, amely megnyitja a menüpontot |
| táblázat `|`-jellel | egyszerű táblázat (fejléc + elválasztó sor) |

## GYIK

A `## GYIK` szakaszban minden `### ` sor egy kérdés (kérdőjellel a végén), alatta a válasz
(bekezdés, lista megengedett). A kérdés úgy szóljon, ahogy egy kolléga megkérdezné.

## Túra

A `## Túra: <modulkulcs>` szakasz az adott oldal végigvezetése. Soronként egy lépés:

```
- `css-szelektor` | A lépés címe | Mit lát itt, és mire jó. Egy-két mondat.
```

- A szelektor az oldal betöltése után **látható** elemre mutasson (popupban lévőre ne).
- Az oldalmenüt, a fejlécet, a keresőt és a profilt a rendszer magától bemutatja – ezek ne legyenek benne.
- Ami épp nincs a képernyőn, azt a túra kihagyja.

## Tartalmi szabályok

- Magyarul, tegezve, rövid mondatokkal. A gombok és mezők felirata **betűre pontos**.
- Csak azt írd le, ami a rendszerben tényleg benne van. Ami beállítást igényel, arról írd meg, hol kell beállítani.
- **Cégadat nem kerülhet bele:** se cégnév, se valódi személy, se ár. A példák kitaláltak
  („Minta Márta", „+36 30 123 4567", „12 000 Ft").
- Belső kódnév (függvény, tábla) csak a `Rendszer` csoport fejezeteiben szerepelhet.
