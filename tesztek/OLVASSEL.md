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
| `t36-sugo` | súgó: minden menüpontnak, Beállítások-lapfülnek és asszisztens-eszköznek van leírása; a súgó verziója egyezik; fejezetformátum, jelölésnyelv, keresés, kérdés–válasz, oldaltúrák, az asszisztens tudása és új eszközei, Papír megjelenés, kékfény-szűrő; csak fejlesztőnek szóló fejezetek / részek, a nem fejlesztőnek látszó súgó és asszisztens nem árulja el, mi fut alatta (0.78) |
| `t37-nyomtatas` | nyomtatványok (PDF): minden típus, példányszám, egy eszköz a több eszközös lapról, sablon és közös megjelenés, próbanyomat; Nyomtatás ablak; e-mail csatolmánnyal; felajánlás új lapnál és lezáráskor; a Számla Agent és az IMEI-kulcs titkosított tárolása; független napi mentés; zárás utáni emlékeztető |
| `t38-gyors-szoveg-betu` | a Számla-előkészítő PDF egy nyomtatvány után is elkészül (betű-gyorsítótár, mindkét sorrendben); az RMA csevegő gyors szövegei: gyári lista, tisztítás, mentés a popupból és a Beállításokból, gomb a csevegőben |
| `t42-mockup` | eszköz-mockupok: gyári készlet (20 kép), kiválasztás (szabály > saját fénykép > kategória > tartalék), Beállítások › Mockupok mentése és visszaállítása; a munkalap Eszközök panelje (kártyák képpel), a 2. eszköz teljes adatlapja, a részletpanel képei |
| `t41-penztar-ajanlat-bent` | a pénztár-ajánlat két eszköz díjánál: tiszta esetben 10 000 + 10 000; ha 8 000 már bent van, 2 000 + 10 000, és az ajánlat viszi, mennyi járna és mennyi van már bent (a popup kiírja) |
| `t40-ai-ures-bemenet` | az AI-hívás az üres eszköz-bemenetet objektumként ({}) küldi vissza (hamis AI-szerverrel, két körben): nincs „Input should be an object” hiba |
| `t39-parbaj-jelzes` | a levél-jelzések a figura mellé kerülnek, a buborék fölébük emelkedik; párbaj a Hiba-bogárral: kapcsoló, mentés, kliensadat, leállítás, „kevesebb mozgás” |
| `t32-beallitasok` | a Beállítások lapfülei, lapfülenkénti mentés, jogosultság, nincs wp-adminba mutató link, az új paraméterek |
| `t47-modulok` | Modulok: a 15 fő modul ki- és bekapcsolása, mentés és függőség, a menü / csempék / Beállítások / súgó / AI-eszközök, az AJAX- és admin-post-őr (kivétel: Fizetés ablak), pénztár nélküli fizetés, Számlázz.hu nélkül a helyi előkészítő, RMA nélkül a „Címke” lapfül, Csapat nélkül a kollégák kezelése, telepítői zár; a végén minden visszakapcsol |
| `t48-zarolt-lap` | lezárt lap zárolása: a nyitott lap szerkeszthető, a lezárt csak olvasható (sáv, jelölés); mentés, eszköz állapota / „fizetve", fizetés, rendelés útja, AI-javaslat elutasítva és naplózva; a kifizetett lezárt lap utólag a pénztárba írható; újranyitás okkal, választott állapotba; kötelező ok; a zárolás kikapcsolható; Beállítások › Lezárt lapok |
| `t49-valtozasnaplo` | változásnapló: SQL-elemző (tábla, WHERE, idézőjelek), létrehozás / módosítás mezőnként / törlés, zaj (időbélyeg, cetli helye) nélkül, kérésenként csoportban, az insert_id érintetlen; üzenet, cetli, naptár (privát rejtve), készletmozgás; AI-forrás; események; beállítások titkok nélkül; védelem (a napló nem írható át / törölhető / üríthető); aláírás és épség (átírás, hézag); kijelzés magyarul; AI-eszközök (zárkód nélkül, kolléga a beállítások naplója nélkül), rendszerüzenet; Napló ablak, oldal, szűrés, jogosultság, lapfül |
| `t50-matrica` | RMA-matrica: alapbeállítás (a kért nyolc adat, sorrendben), tisztítás, adatok eszközönként (hiba, kód, minta, IMEI, RMA, sorszám), elrendezés (kicsinyítés, levágás jelzése), Code 128, PDF (oldalak, címkeméret), PNG, Brother QL raszter (fej, ESC i z, sorok, lapdobás, széles QL), közvetlen nyomtatás egy helyi TCP-„nyomtatóra", hibaüzenet, Matrica ablak, élő előnézet, próbanyomat, lapfül mentése, modul ki / be |
| `t56-urlap-beallitas` | fogaskerék minden űrlapon: elemek és állapotok rejtése (a mező értéke marad), a számla tételei magától vagy kézzel (két ablak külön), Beállítások › Űrlapok, csak admin menthet |
| `t57-emlek` | mezőmemória (rögzítés, titokszűrő, kártyaszám, törlés, ki/be, AJAX), Leggyakoribb Számla tételek (alap, mentés, tételnév-felajánlás), a munkalap tételeinek elrejtése a számlán, eszközcsatolás-doboz, Beállítások › Űrlapok |
| `t26-fust` | füstteszt: űrlap, rács, részletpanel, beállítások, asszisztens – PHP-figyelmeztetés nélkül |

| `t66-kulso-mentes` | külső jelszavas mentés (H3): titkosítás körút / rossz jelszó / csonka és módosított fájl; megőrzés (N + előző napi + visszaállítás előtti); mappa, WebDAV (valódi HTTP-kiszolgálóval), FTP (valódi FTP-kiszolgálóval), Google Drive (hamis Google-válaszokkal); teljes folyamat, várakozás és újrapróba |
| `t67-visszaallitas` | visszaállítás (H3): bájtra pontos sorok, NULL, titkos kulcsok újratitkosítása, biztonsági mentés és visszavonás, csak beállítások, Export/Import, ROLLBACK sérült fájlnál, verziókapu, zár, AJAX-folyamat |

| `t68-frissites` | frissítés a GitHubról (H4): verzió-kiolvasás és gyorsítótár, hibák (nincs net, 404, korlát), WordPress-bekötés, csomagmappa átnevezése, privát tároló kulcsa, védelmek (nem admin, git-példány), a Névjegy doboz |
| `t71-zart-mod` | zárt mód (0.77): idegen oldal → műhely, robots.txt, saját ikon, REST/XML-RPC zárva, saját bejelentkező és kilépés (nonce, jó/rossz jelszó, idegen visszatérési cím), wp-login.php átirányítás, árulkodó fájlok törlése; a bejelentkező oldal testreszabása (H6b, 0.80): szűkítés, mentés, kinézet, videó / mobil, élő előnézet |
| `t70-szintek` | fejlesztő / SDH adminisztrátor / kolléga szintek (0.76): szerep és jogok, WordPress-admin kizárás, Modulok és Frissítés csak fejlesztőnek, közös fiók, bejelentkező képernyő; Csapat: az „Adminisztrátor” SDH adminisztrátor, a fejlesztő fiókja védett (0.78) |
| `t72-zarkod` | H6 (0.79): zárkód / minta titkosítva – séma, átállás napló nélkül, mentés (érintetlen / azonos / új / törlés / jog nélküli felülírás), új jog és a régi szerepek átállítása, űrlap és Megmutat (naplózva, jog nélkül elutasítva), napló, matrica, IMEI-találat, hordozható mentés |
| `t69-szerepkor` | szerepkörök és jogok (H5): alap szerepek, mentés / új / törlés, személy → szerep, szerveroldali őr (modul + jog, AJAX / popup / admin-post), láthatóság, body-osztályok, közös fiók + dolgozó, admin_e() |

Új teszt: `tNN-nev.php`, az elején `require __DIR__ . '/_keret.php';`, a végén `T::vege('tNN név');`.
A korábbi (0.42.0-ig írt) t14–t21 tesztek csak a felhős munkakörnyezetben éltek, és azzal együtt elvesztek –
ezért kerültek a tesztek a repóba.
| `t58-ugyfelkereso` | gyors ügyfélkereső: keresőszöveg és szó-index, séma és indexek, mentéskor / bármely azonosító szerinti íráskor frissül, kötegelt háttérfeltöltés, gyors út ⊇ régi út, „tartalmazza" csak találat nélkül, ügyféllista, böngészőoldal (100 ms, megszakítás, azonnali lista) |
| `t59-racs-sebesseg` | gyorsítótár (érvénytelenítés, takarítás), kezdőképernyő-számok, rács: halasztott számlálás, index menti rendezés = teljes ORDER BY, kereső-allekérdezés = régi találatszám; listák (találatszám, sorrend), ablakos lapozó, pénztári összeg, fejléc-kereső, szerverállapot, felület-csomag |
