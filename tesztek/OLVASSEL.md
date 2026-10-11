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
| `t26-fust` | füstteszt: űrlap, rács, részletpanel, beállítások, asszisztens – PHP-figyelmeztetés nélkül |

Új teszt: `tNN-nev.php`, az elején `require __DIR__ . '/_keret.php';`, a végén `T::vege('tNN név');`.
A korábbi (0.42.0-ig írt) t14–t21 tesztek csak a felhős munkakörnyezetben éltek, és azzal együtt elvesztek –
ezért kerültek a tesztek a repóba.
