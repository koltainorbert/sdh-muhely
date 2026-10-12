# Tesztek

A tesztek egy **valódi WordPress-telepítésen** futnak, és adatot írnak az adatbázisba (ügyfél, eszköz,
munkalap, pénztártétel). **Csak külön tesztoldalon futtasd** – éles vagy munkára használt oldalon soha.

```
SDH_TESZT_ADATBAZIS=1 SDH_WP_LOAD=/út/a/wp-load.php php tesztek/futtat.php        # mind
SDH_TESZT_ADATBAZIS=1 SDH_WP_LOAD=/út/a/wp-load.php php tesztek/futtat.php t22    # csak egy
```

- `SDH_TESZT_ADATBAZIS=1` nélkül a tesztek nem indulnak el (védelem a véletlen futtatás ellen).
- `SDH_WP_LOAD` elhagyható, ha a plugin a `wp-content/plugins/sdh-muhely` mappában áll.
- Tesztenként egy összegző sor jön (`t22 eszköz-állapot: 69/69 OK`); hibánál alatta a bukott állítások.
- A Számlázz.hu-t a tesztek hamis válasszal helyettesítik: valódi számla nem készül, a hálózatra semmi nem megy ki.
- A telepítő zipbe ez a mappa nem kerül be (`.gitattributes`: `export-ignore`).

| Teszt | Mit ellenőriz |
|---|---|
| `t22-eszkoz-allapot` | eszközönkénti állapot, a lap közös állapota („Részben elkészült"), űrlapmentés |
| `t23-eszkoz-fizetve` | eszközönkénti „Fizetve": részfizetés a Fizetés ablakból, pénztártétel, a jelző levétele |
| `t24-rma-eszkoz` | RMA-üzenet eszközhöz kötve, ügyféloldal eszközönként, állapottörténet |
| `t25-szamla-eszkoz` | eszközönkénti számla (választható), a helyi nyomtatvány sorszáma |
| `t27-bevizsgalasi-dij` | a bevizsgálási díj nem nő a későbbi előlegtől / részfizetéstől (egy és több eszköz) |
| `t28-fizetett-osszeg` | a „Fizetett" a ténylegesen befolyt összeg; a „Fizetve" pipa levétele, a fizetés visszavonása |
| `t29-eladas` | napi árukiadás és rendelés: mentés ügyfél / eszköz nélkül, fizetés és pénztár, lezárás, helyszínek, beszállítók, értesítés |
| `t30-rma-csevego` | a munkalap RMA-csevegője (időrend, frissítő végpont), az ügyféloldal Szolgáltatások és Termékek füle |
| `t31-megjegyzes-ugyfel-arculat` | gyakori megjegyzések, az ügyfél adatlapjának Munkalapok / Rendelések / Vásárlások füle, az Arculat logója és egyéni színe |
| `t33-ugyfel-lista-ora-level` | ügyfélkártya és szerkesztés az eladásokon, árukiadás a rácsban, állapot a listából, pill-gombok, nyitvatartás és óra, új levél fiókonként, Asszisztens a Beállításokban |
| `t35-naptar` | naptár: nevek (közös fiók és profilonként egy), esemény mentése, ismétlődés, a nézetek adatai, áthelyezés, törlés, a ki nem nyomott kártyák, e-mail értesítés (a levelek nem mennek ki), napi összefoglaló, privát esemény, beállítások |
| `t34-szerkesztes-betu-szin` | eszköz szerkesztése a munkalapról, csak két számlagomb, csempe-szám entitás nélkül, két hasábos oldalsáv több eszköznél, betűnagyítás és betűtípus, megjelenés és üvegszín |
| `t36-sugo` | súgó: minden menüpontnak, Beállítások-lapfülnek és asszisztens-eszköznek van leírása; a súgó verziója egyezik; fejezetformátum, jelölésnyelv, keresés, kérdés–válasz, oldaltúrák, az asszisztens tudása és új eszközei, Papír megjelenés, kékfény-szűrő |
| `t37-nyomtatas` | nyomtatványok (PDF): minden típus, példányszám, egy eszköz a több eszközös lapról, sablon és közös megjelenés, próbanyomat; Nyomtatás ablak; e-mail csatolmánnyal; felajánlás új lapnál és lezáráskor; a Számla Agent és az IMEI-kulcs titkosított tárolása; független napi mentés; zárás utáni emlékeztető |
| `t38-gyors-szoveg-betu` | a Számla-előkészítő PDF egy nyomtatvány után is elkészül (betű-gyorsítótár, mindkét sorrendben); az RMA csevegő gyors szövegei: gyári lista, tisztítás, mentés a popupból és a Beállításokból, gomb a csevegőben |
| `t32-beallitasok` | a Beállítások lapfülei, lapfülenkénti mentés, jogosultság, nincs wp-adminba mutató link, az új paraméterek |
| `t26-fust` | füstteszt: űrlap, rács, részletpanel, beállítások, asszisztens – PHP-figyelmeztetés nélkül |

Új teszt: `tNN-nev.php`, az elején `require __DIR__ . '/_keret.php';`, a végén `T::vege('tNN név');`.
A korábbi (0.42.0-ig írt) t14–t21 tesztek csak a felhős munkakörnyezetben éltek, és azzal együtt elvesztek –
ezért kerültek a tesztek a repóba.
