# Bemutató (demó) – telepítés és üzemeltetés

Belső, fejlesztői dokumentáció (nem kerül csomagba). A bemutató egy **külön webhely** a demó aldomainen (pl.
`demo.<domain>`), **saját adatbázissal** – soha nem Norbi éles rendszere. Rajta a **valódi műhely** (vevői build) és a
**„Bemutató” bővítmény** (`weboldal/demo/sdh-demo/`, Bemutató 1.0.0, `SDH_DEMO_VERSION`) fut.

A látogató egy kattintással ideiglenes fiókot kap, egy kitalált szerviz 18 hónapnyi adatával dolgozik, semmi nem megy ki
(SMS, e-mail, számla), és éjjel 3:00-kor minden visszaáll.

## 1. Telepítés

1. **Szerver:** Linux, PHP 8.1+, MySQL 8 / MariaDB 10.6+ (SQLite-on is fut – így tesztelünk), HTTPS, valódi cron.
   Új, üres adatbázis és új példány; a rejtés üzemeltetési lépései ugyanazok, mint az értékesítő webhelyen
   (`dokumentacio/rejtes-uzemeltetes.md`: semleges tartalom- és bővítménymappa-nevek `WP_CONTENT_DIR/URL`-lel, saját
   sütinevek a `wp-config.php`-ban, `wp-cron.php` letiltva).
2. **A műhely vevői buildje:** a tároló gyökeréből
   `sh tools/vevo-csomag.sh /ut/munkora.zip` (a véglegesített állapotból; próbához `--munkafa`). Ebben nincs
   `includes/sdh/`, a feliratok a termék nevét (`SDH_Muhely_Arculat::termeknev()`, alap: „Munkora”) mutatják. Telepítsd,
   kapcsold be. **Ne** a belső (saját) példány fájljait másold: abban a belső nevek látszanának.
3. **A Bemutató bővítmény:** a csomagoló zipje (a mappát semleges névre nevezi), telepítés + bekapcsolás. Bekapcsoláskor
   létrejön a `bemutato` szerep (jogai: `read`, a műhely használata és beállítás-megtekintése – semmi más) és két saját
   tábla: `{prefix}bemutato_naplo` (indítás / keret, csak IP-kivonat), `{prefix}bemutato_kimeno` (elfogott üzenetek).
4. **`wp-config.php`:**
   ```php
   define('SDH_MUHELY_TESZTUZEM', true);   // a bővítmény is beállítja, de legyen itt is
   define('DISABLE_WP_CRON', true);        // valódi cron, lásd 6.
   // opcionális:
   define('BEMUTATO_PILLANATKEP_MAPPA', '/srv/bemutato-pillanatkep');  // a webgyökéren KÍVÜL (ajánlott)
   define('BEMUTATO_KEPEK', '/srv/bemutato-kepek');                     // ha a képek nem a bővítmény kepek/ mappájában vannak
   ```
5. **Adatok + pillanatkép** (lásd 3–4.), majd **Eszközök › Bemutató**: értékesítő webhely címe, Turnstile-kulcsok.
6. **Értékesítő webhely:** az ottani beállítás `demo.url` = `https://demo.<domain>/?bemutato=indit` (a
   `demo_inditas` szekció és a „Bemutató” gombok ide visznek). A bemutató sávjának „Megrendelem” gombja a Bemutató
   beállításában megadott értékesítő címre mutat.

## 2. Tesztüzem és kimenő üzenetek

- A bővítmény a legkorábban beállítja a műhely tesztüzemét (`SDH_MUHELY_TESZTUZEM` + `sdh_muhely_tesztuzem` szűrő
  `PHP_INT_MAX` prioritással): SMS csak szimuláció, a saját postafiókokból sem megy levél, Számlázz.hu / Google tiltva,
  nincs külső és napi mentés, a felületen „Tesztüzem” sáv.
- **Hálózati kapu** (`pre_http_request`, 0. prioritás): kifelé csak a saját webhely, a Turnstile-ellenőrzés és – csak
  beállított **teszt**kulccsal – a Számlázz.hu teszt-fiókja megy. Minden más (központ / telemetria / frissítés, IMEI-
  szolgáltatás, AI, Google, SMS-átjáró, LAN-címek) érthető üzenettel hibát ad.
- **E-mail:** a `pre_wp_mail` (0. prioritás) elfogja, „elküldöttnek” jelzi, és a `bemutato_kimeno` táblába írja.
- **SMS:** a műhely SMS-sora szimulál; az `sdh_muhely_sms_azonnal` szűrő jelzi az új SMS-t, a kérés végén (és óránként,
  valamint a nézet megnyitásakor) átkerül a kimenő üzenetek közé.
- **„Kimenő üzenetek”** menüpont a műhely felületén (saját modul, `?muhely=kimeno`): a látogató a sajátját látja, az
  admin mindet; a HTML-levelek szövegként (escapelve).
- **Számlázz.hu:** a valódi Agent-kulcs helyett üres (vagy a bemutató tesztkulcsa), minden előtag `MINTA`, a vevő nem kap
  levelet. Tesztkulcsot csak Számlázz.hu **teszt-fiókból** adj meg (Eszközök › Bemutató). Kulcs nélkül a számla
  előnézete és a helyi nyomtatvány (MINTA-…) működik, valódi számla nem készül.

## 3. Adatgenerátor (`weboldal/demo-adat/`)

```
php weboldal/demo-adat/general.php --wp=/ut/wp-load.php --db=10000 --pillanatkep
```
vagy **Eszközök › Bemutató › Adatok generálása** (1 000 / 5 000 / 10 000 / 20 000).

- Alapméret (10 000 → 9 800 rekord): ügyfél 2 000, eszköz 2 500, munkalap 2 300 (1–3 tétel, hibasor, végösszeg = tételek
  összege), termék 1 200 (nyitókészlet), szolgáltatás 300, pénztártétel 1 200 (a fizetett lapokból + kiadások),
  naptár 300 (±45 nap); 18 hónapra szórva, a friss időszak sűrűbb. Négy kitalált munkatárs a felelős (naptár-személlyel).
- A műhely tesztadat-generátorának kódútjai (oszlopok, `SDH_Muhely_Ugyfel::kereso_adat / kereso_szavak`, a beállított
  állapotok, kötegelt INSERT, SQLite-on közvetlen PDO – a régi és az új SQLite-meghajtóval is).
- Determinisztikus (soronkénti véletlenmag), kitalált: gyakori magyar nevek, valódi irányítószám + település, kitalált
  utcaszám, telefon `+36 20/30/70 0xx xxxx` (0-val kezdődő előfizetői szám – nem kiosztott tartomány), e-mail `@pelda.hu`,
  adószám `0000…`, IMEI Luhn-helyes, de kitalált. Nincs benne SDH-ra vagy eredetre utaló adat (t290 ellenőrzi).
- Jelölés: `forras = 'bemutato'`, `kulso_azonosito = 'bm-…'` (szolgáltatás: megjegyzés `[bemutató]`, naptár: forrás).
  Újrafuttatás előbb a jelölt sorokat törli (csatolmányfájlokkal) – más adathoz nem nyúl.
- **Képek:** `demo-adat/kepek/manifest.json` (+ webp) → `uploads/bemutato/`, a készülékek saját fotója (`eszkoz.kep_url`,
  telefon / tablet / laptop / konzol / okosóra), és minden 10. munkalaphoz átvételi fotó (a műhely csatolmány-tárolásával).
  A képmappa keresési sorrendje: `--kepek` / `BEMUTATO_KEPEK` / a bővítmény `kepek/` mappája / `demo-adat/kepek`.
  Kép nélkül is működik (a készülékek mockupot kapnak).
- Mért (SQLite tesztkörnyezet): 10 000 → 1,6–4,3 s (újrafuttatással, törléssel együtt), 1 000 → 0,6–1,4 s.

## 4. Pillanatkép és visszaállítás

- `SDH_Demo_Pillanatkep::keszit()`: a műhely minden táblája (`{prefix}sdh_…`, az értékesítő / központ táblái nélkül), a
  műhely opciói (`sdh_…`, a verziójelzők nélkül) és a generált munkatársak (sor + meta) → `pillanatkep-<idő>-<véletlen>.jsonl.gz`
  (gzip, soronként JSON, 2 000-es olvasási kötegek), `0600` jog, védett mappában (`.htaccess`, `web.config`, `index.php`;
  alap: `wp-content/bemutato-pillanatkep`, ajánlott a webgyökéren kívül). Egy pillanatkép marad.
- `visszaallit()`: egy tranzakcióban táblánként ürít és kötegelt INSERT-tel tölt (SQLite: PDO, 500 sor; MySQL: `$wpdb`,
  250 sor), az azóta jött táblák ürülnek, az azóta jött oszlopok alapértéket kapnak; utána: a látogatói fiókok, a kimenő
  üzenetek, a keret-napló, a látogatói csatolmányfájlok törlése, gyorsítótár.
- **Ütemezés:** éjjel 03:00 Europe/Budapest (egyszeri esemény, minden futás után a következő 03:00-ra – óraátállításkor
  is pontos), és **Eszközök › Bemutató › Visszaállítás most** (kétlépcsős gomb).
- Mért (SQLite, 10 000-es alap, 48 tábla, ~40 900 sor, ~940 KB): pillanatkép 0,36–1,26 s, visszaállítás 1,4–3,4 s.
- **Munkarend:** generálás → (kézi finomhangolás a felületen, ha kell) → pillanatkép. Műhely-frissítés után új
  pillanatkép (a visszaállítás ugyan bírja az új oszlopokat, de a friss alap a tiszta).

## 5. Látogató: indítás, keret, tiltások, sáv

- **Indítás:** `https://demo.<domain>/?bemutato=indit` – POST-űrlap; **Cloudflare Turnstile** a szerveren ellenőrizve
  (`siteverify`, titok + token + IP), ha mindkét kulcs meg van adva; különben rejtett csapda-mező + aláírt időbélyeg
  (2 mp – 1 óra). Ideiglenes fiók: véletlen név és 40 karakteres jelszó (senki nem látja), `@pelda.hu`, „Vendég NNNN”,
  24 óra; címenként legfeljebb 5 indítás / 24 óra (állítható). Jelszó-visszaállítás, jelszó- / e-mail- / profilmódosítás
  tiltva, kétlépcsős belépés ki. Közös **demo / demo** belépés kapcsolható (alapból ki).
- **Keret:** címenként 10 új rekord / 24 óra (ügyfél, eszköz, munkalap, termék, szolgáltatás, pénztártétel, naptár-
  esemény, beszállító, helyszín, számla, csapat-üzenet / -cetli / -csoport), plusz 300 írás bármelyik nem-napló táblába.
  A `query` szűrő a `{prefix}sdh_…` táblákba menő INSERT / REPLACE-et nézi, bemutató-felhasználónál és kijelentkezett
  webes kérésnél (ügyféloldal, online foglalás); túllépésnél a műhely AJAX-hibaútján (`success:false`, `data.uzenet`,
  HTTP 429) érthető magyar üzenet jelenik meg a popupban. A felvett rekordok azonosítója naplózva: óránként a 24 óránál
  régebbieket (gyereksorokkal) törli – a pillanatkép azonosítóinál kisebbet soha.
- **Tiltva (szerveren, 403):** frissítés, modulok, licenc, távoli karbantartás / támogatás, minden mentés-művelet (külső
  célok, visszaállítás, export / import), csapattagok kezelése (Kollégák), kétlépcsős belépés, adatátvétel, anonimizálás,
  pénztár-import, exportok (CSV), fejlesztői részek (mérés, admin-átállás, demó-betöltő), feltöltés, AI-kulcs,
  SMS-párosítás, postafiók-teszt. A látogató AJAX-ban csak a műhely saját műveleteit, admin-post-ban csak a rekord-
  mentéseket hívhatja; a WordPress-admin helyett a műhely nyílik; a REST felhasználó-végpontjai tiltva.
  **Beállítások:** csak a nem érzékeny lapfülek látszanak és nyílnak meg (a Rendszer csoport, a Levelezés, az Asszisztens
  és a TAC nem); mentés alapból nincs (közös beállítás – minden látogató látná), az admin engedélyezheti.
- **BEMUTATÓ sáv** a műhely felületének alján (`sdh_muhely_felulet_vege`): visszaszámlálás a 03:00-s visszaállításig,
  hátralévő keret (minden mentés után frissül a semleges `?bemutato=allapot` címről), link a Kimenő üzenetekre és a
  „Megrendelem” (értékesítő webhely). Régió + élő terület, AA kontraszt, nyomtatásban rejtve; azonosítói `bm-*`,
  sütit nem állít.

## 6. Valódi cron

```
*/5 * * * *  cd /srv/demo && php wp-cron.php >/dev/null 2>&1
```
(vagy `wp cron event run --due-now`). Az éjjeli visszaállítás (`sdh_demo_ejjeli`) és az óránkénti takarítás
(`sdh_demo_orankenti`) így pontosan fut, látogató nélkül is. Ellenőrzés: Eszközök › Bemutató › Állapot.

## 7. Biztonság – ellenőrzőlista

- Külön példány, külön adatbázis és sók; a műhely licenckulcsa nincs megadva (vagy külön, bemutató-licenc).
- A pillanatkép a webgyökéren kívül (`BEMUTATO_PILLANATKEP_MAPPA`), vagy a mellékelt tiltásokkal.
- Turnstile-kulcs (a titkos kulcs titkosítva tárolva); a látogató IP-je csak HMAC-kivonatként kerül a naplóba.
- Proxy / CDN mögött a webszerver adja a valódi `REMOTE_ADDR`-t (vagy az `sdh_demo_ip` szűrő) – különben a keret és az
  indításkorlát a proxyra vonatkozna.
- Admin-fiók erős jelszóval, a titkos belépési címen; a látogató soha nem kap `manage_options`-t.
- A REJTES-ellenőrzés (t299 + a végső bejáró) a bemutató saját kimenetét nézi; a műhely felületének azonosítói a vevői
  build ügye.

## 8. Tesztek

`t290`–`t299` (`weboldal/tesztek/`, közös keret: `_t29-seged.php`, a bemutató-példányon):
```
SDH_TESZT_ADATBAZIS=1 SDH_WP_LOAD=/home/claude/env/demo/wp/wp-load.php \
SDH_TESZT_DB=/home/claude/env/demo/wp/wp-content/database/sales.sqlite SDH_TESZT_ALAP=/home/claude/env/demo/alap.sqlite \
php weboldal/tesztek/futtat.php t29
```
Böngészős végigjárás: `python3 weboldal/tesztek/e2e/bemutato-folyamat.py <képmappa>` (indítás → műhely → új ügyfél →
keret csökken → Frissítés 403 → tiltott lapfül → 11. rekord érthető hibával → képernyőkép).
