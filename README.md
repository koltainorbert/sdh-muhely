# SDH Műhely

Belső műhely- és ügyfélkezelő rendszer (CRM + munkalap) az **SDH Szerviz** számára.
A régi *MunkaLap* desktop program (Java/Derby) webes utódja, WordPress plugin formában.

---

## Mi ez

Önálló, belső WordPress-telepítésben futó plugin. **Nem** a nyilvános
`sdh.hu`-n él, hanem egy elzárt belső rendszerben. A cél a MunkaLap
funkcionalitásának átültetése, modulonként:

- **Ügyfél** – törzsadat, több címtípus, kategória, kedvezmény
- **Eszköz** – ügyfélhez kötve, gyártó/típus, IMEI, sorozatszám, garancia
- **Munkalap** – állapot, felelős, határidő, fizetés
- **Hiba** – munkalaphoz kötött hibajegyek
- **Tétel** – termék és szolgáltatás, beszerzési ár, haszonkulcs, készlet
- *(később)* raktár, számla, pénztár, határidő, statisztika

A meglévő **SDH Platform** plugin (beszállítói katalógus → rendelés) külön
fut; a kettő összekötése egy későbbi lépés.

---

## Fejlesztői környezet

| | |
|---|---|
| **Helyi stack** | Local by Flywheel |
| **PHP** | 8.1+ |
| **WordPress** | 6.4+ |
| **Verziókezelés** | Git + privát GitHub repo |
| **Munkagépek** | munkahelyi asztali + otthoni, git-en keresztül szinkronban |

A repo gyökere maga a plugin mappa:
`wp-content/plugins/sdh-muhely/`

### Napi munkamenet

```bash
# Munka kezdetén – behúzom a másik gépen készült változásokat
git pull

# ... fejlesztés ...

# Munka végén
git add -A
git commit -m "Rövid, beszédes üzenet"
git push
```

Otthon ugyanez, fordított sorrendben: előbb `pull`, végén `push`.
Mindig a végén tolj fel, hogy a másik gép a `pull`-lal mindent megkapjon.

---

## Verziónapló

### 0.16.0
- Munkalap-modul (Munkalapok a menüben): lista kereséssel (szám, név,
  ügyfél, telefon, IMEI, készülék) és állapotszűrővel, felvitel és
  szerkesztés popupban, JS nélküli teljes oldalas tartalékkal.
- A munkalap állapotai a MunkaLap 3-éi: Bejelentett, Árajánlat, Sablon,
  Függő, Nyitott, Elkészült, Lezárt, Érvénytelen – a MunkaLap 3 színeivel.
  Az állapotlista a Beállításokban szerkeszthető (kulcs, név, szín,
  `szamozott` és `zart` jelző), semmi sincs beégetve.
- Munkalapszám: csak a `szamozott` állapotú lap kap számot (a Sablon és az
  Árajánlat nem), az első számozott állapotba lépéskor, zár alatt, egyedi
  kulccsal – két gép nem kaphatja ugyanazt. A Beállításokban a következő
  szám, az előtag és a kitöltés állítható. A MunkaLap 3-ból való átvétel
  után a kezdőérték: 34983.
- Hibasorok: egy munkalapon több hiba, mindegyik saját állapottal
  (Új, Folyamatban, Kész, Nem javítható – szerkeszthető) és javítási
  megjegyzéssel. A listában „x / y kész” látszik.
- Számozott állapotnál az ügyfél és az eszköz kötelező, és az eszköznek az
  ügyfélé kell lennie. Az eszközválasztó az ügyfél kiválasztása után töltődik.
- Munkalap nem törölhető: az érvénytelen lap az Érvénytelen állapotba kerül.
- Állapotszínek: `--sdh-allapot-*` változók az `assets/admin.css` tetején,
  sötét módra külön értékekkel.
- Az áttekintőn a „nyitott munkalap” kártya élő számot mutat; az ügyfél
  adatlapján új „Munkalapjai” gomb.
- Sémaverzió: 0.10.0 (`munkalap`, `munkalap_hiba` tábla).

### 0.15.0
- Arculat képernyő (SDH Műhely → Arculat): élő stíluskalauz az összes
  színnel és komponenssel, plusz a cég alapértelmezett megjelenése.
  A dolgozó saját beállítása a profilmenüben ezt felülírja.
- Minden beégetett szín kivezetve a PHP-ból és a JavaScriptből:
  az átszínezés egyetlen fájlból (`assets/admin.css`) megy.
- Külön `--sdh-accent-szoveg` változó: sötét módban világosabb, mint a
  gombszín. Enélkül a piros linkek 3,3:1 kontraszton álltak.
- A paletta minden párosa méréssel ellenőrizve: a legrosszabb érték
  világos módban 4,62:1, sötétben 4,60:1 – mindkettő WCAG AA fölött.

### 0.14.0
- Új felület: valódi CRM-váz. Bal oldalt összecsukható ikonos menü,
  felül morzsamenü, globális kereső és profilmenü.
- Formanyelv: világos semleges vászon, fehér felületek, sűrű 13 px-es
  táblázatok. Rendszerbetű – webfont nélkül, hogy internet nélkül is
  jól nézzen ki.
- Világos / sötét / rendszer megjelenés és hat választható kiemelő szín,
  böngészőben megjegyezve. A beállítás a stílusok előtt áll be, így
  sötét módban nincs felvillanás.
- Globális kereső a fejlécben: ügyfél és eszköz egyszerre, név, telefon,
  IMEI, gyári szám és sorozatszám alapján. A `/` billentyű ráugrik.

### 0.13.0
- Ellenőrző gombok a Feldolgozás mellett: imeicheck.com és iunlocker.com
  egy kattintással, az IMEI-vel a vágólapon.
- A lista a Beállításokban szerkeszthető (`Név | URL` soronként); ha egy
  oldal URL-ben fogadja az IMEI-t, az `{imei}` jelölővel kitöltve nyílik.

### 0.12.0
- Gyári adatok beillesztéssel: az ingyenes IMEI-ellenőrzés eredményét
  bemásolod, és a rendszer kitölti a mezőket. Beillesztéskor magától fut,
  gombot sem kell keresni.
- Nincs előfizetés-kényszer: a fizetős szolgáltató opcionális maradt,
  ugyanazt a feldolgozót használja.
- A készülékkép a TAC-adatbázisba is bekerül, így a következő ugyanolyan
  típusnál magától megjelenik.

### 0.11.0
- Teljes gyári adatlap: modellszám, garancia állapota, gyártási dátum,
  ország, szolgáltató, készülékkép – az eszköz-űrlapon és a listában.
- Külső IMEI-szolgáltató beköthető (SDH Műhely → Beállítások): végpont
  URL `{imei}` és `{kulcs}` jelölőkkel, API-kulcs, be/ki kapcsoló.
  Nincs szolgáltató beégetve – más szerviz más előfizetést használhat.
- A válasz feldolgozása JSON-t és „Címke: érték" szövegblokkot is ismer,
  a dátumokat egységes alakra hozza.
- Ez az egyetlen pont, ami internetet igényel; nélküle minden megy tovább.

### 0.10.0
- Feloldó minta rajzolható mező: 3×3 pöttyrács, egérrel behúzható piros
  vonal, nyilakkal az irányról és gyűrűvel a kezdőponton.
- Átlépett pötty automatikusan bekerül (1→3 esetén a 2 is), ahogy az
  Android is csinálja – különben nem azt vennénk fel, amit az ügyfél rajzol.
- A minta a pöttyök sorrendjeként tárolódik (`minta` oszlop), a zárkód
  mező ezentúl a PIN/jelszó helye.

### 0.9.0
- **A gyári szám az elsődleges azonosító**, nem a kereskedelmi név:
  `SM-A505F/DS`, nem `Galaxy A50`. A listák, a fejlécek és az IMEI-ből
  való kitöltés mind ezt használják.
- A kereskedelmi név külön mezőbe került (keresni lehet rá, azonosítani
  nem). A keresés mindkettőre megy.
- A TAC-adatbázis újragenerálva modellkóddal: 248 364 TAC, ebből
  131 802-nél (53%) van gyári szám is.

### 0.8.0
- A TAC-adatbázis tanul: ismeretlen TAC-ú készülék kézi felvitelekor a
  gyártó és a típus bekerül a saját táblába, és a következő ugyanolyan
  készüléknél már magától kitöltődik.
- A csomagolt lista 2025 végéig tart; a saját bejegyzéseket az
  újratöltés nem írja felül (`forras = 'sajat'`).
- A TAC képernyő külön mutatja a saját felvitelből tanult darabszámot.

### 0.7.0
- TAC-adatbázis: az IMEI első nyolc számjegyéből a gyártó és a típus
  soha nem látott készüléknél is kitöltődik. 248 364 készüléktípus,
  a plugin mellé csomagolva (`data/tac.csv.gz`, 1,8 MB).
- Helyi másolat, nem online API: a rendszernek internet nélkül is
  mennie kell az irodai szerveren.
- Betöltő képernyő (SDH Műhely → TAC adatbázis): darabokban fut,
  megszakítható, ismételt futtatás nem duplikál.

### 0.6.0
- IMEI-kikeresés: a 15. számjegy beírása után a rendszer megnézi, járt-e
  már nálunk a készülék. Ha igen, kitölti az adatait és az ügyfelet, és a
  mentés a meglévő rekordot frissíti – nem keletkezik duplikátum.
- IMEI ellenőrzőszám (Luhn) figyelmeztetésként: elgépelést jelez, de nem
  tiltja a mentést.
- Az IMEI mező csak számjegyet fogad (vonalkódolvasó szóközeit kiszűri).

### 0.5.0
- Eszköz-modul: ügyfélhez kötött készülék (gyártó, típus, IMEI,
  sorozatszám, zárkód, tartozékok, átvételkori állapot, garancia).
  Lista kereséssel, popupos felvitel, ügyfélre szűrve is.
- Ügyfélválasztó mező: gépelésre keres, mert 40 ezer rekordnál egy
  legördülő használhatatlan. Enter az első találatot veszi.
- Az ügyfél adatlapjáról egy gombbal átlépsz a készülékeire.

### 0.4.0
- Popup (modál) rendszer: a felvitel és a szerkesztés párbeszédablakban
  nyílik, így a lista és a keresés nem vész el. Natív `<dialog>`, nincs
  külső könyvtár.
- Az ügyfél-űrlap egy helyen íródik, és onnan megy a popupba és a teljes
  oldalas változatba is.
- JavaScript nélkül minden link a teljes oldalas űrlapra visz – a
  rendszer JS nélkül is használható.
- Az „Új ügyfél" gomb az áttekintőn is megjelenik.

### 0.3.0
- Saját műhely-felület a `/muhely/` útvonalon: nem használja a sablont,
  bejelentkezés nélkül átirányít, saját fejléccel és menüvel.
- Modul-nyilvántartás (`SDH_Muhely_Modulok`): a modulok egyszer íródnak
  meg, és mindkét felületen megjelennek. Az URL-építés kontextusfüggő.
- Az ügyfél-modul mindkét felületen fut; a mentés oda tér vissza,
  ahonnan a beküldés jött.

### 0.2.0
- Verziózott adatbázis-séma (`SDH_Muhely_Schema`), aktiváláskor és
  sémaverzió-váltáskor magától lefut.
- Közös admin arculat: menüszerkezet, oldalfejléc, értesítések, saját CSS.
  A modulok az `sdh_muhely_menupontok` szűrőn át jelentkeznek be.
- Ügyfél-modul: lista kereséssel és lapozással, felvitel/szerkesztés,
  inaktiválás törlés helyett.

### 0.1.0
- A plugin váza: admin nyitóképernyő, verzió- és környezet-kijelzés.
- A Local + Git munkafolyamat ellenőrzésére.
