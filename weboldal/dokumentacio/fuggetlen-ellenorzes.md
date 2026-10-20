# Független ellenőrzés – értékesítő webhely: pénz, fizetés, licenc, számla, fiók, rejtés

Ellenőr: független ügynök („ellenor”), 2026-10-20. Kódot nem módosítottam. Hatókör: `sales/sdh-sales/includes/`
(rendelés, fizetés*, bank-import, qr, ar, kupon, kosar, kiszolgalas, kozpont-kapcsolat, szamlazz, email, fiok, biztonsag,
rest, rejtes, suti, szikra*, egyedi) + `kozpont/sdh-kozpont/includes/class-sdh-kozpont-kiszolgalas.php`, `-api.php`.
Mérce: ARCHITEKTURA.md §5, REJTES.md, DONTESNAPLO.md.

Bizonyítás saját, elszigetelt oldalon (`/home/claude/env/ellenor`, WP 6.8.3 + SQLite, `php -S 127.0.0.1:8799` + router).
Szkriptek (a `_keret.php` / `_t210-seged.php` mintájára, hamis hálózattal):
`/tmp/claude-0/-home-claude/3333a1c6-ca63-5c29-9b43-ee4b30f38be6/scratchpad/ell/p1.php … p4.php` – a lent idézett „P…” / „K…” / „R…” sorok ezek kimenetei.

**Tesztcsomag** (`futtat.php`, saját oldal): **56/57 teszt OK**; egyetlen hiba: `t266 lemondás + áthelyezés: 29/37 HIBA` –
a teszt beégetett dátumot használ (`2026-10-20T07:00:00Z`, t266:14), ami ma már a lemondási határidőn belül van
(`naptar.lemondas_ora` = 2) → 409. Tesztbug (naptár, nem a hatókör része), de a csomag piros.

Összesítés: **kritikus 1 · magas 5 · közepes 7 · alacsony 13**.

---

## KRITIKUS

### KR-1 Banki import: ugyanaz a jóváírás két csatornán (vagy kétszer) beolvasva kétszer számít → hamis „visszautalás szükséges”, részfizetésből „fizetve” + licenc + végszámla
- Hol: `class-sdh-sales-bank-import.php:577-588` (a tranzakció-kulcs: banki hivatkozás, ha van, különben a mezők lenyomata,
  benne a dátum), `:452` (értesítőnél dátum hiányában **a mai nap**), `:618-620`; `class-sdh-sales-rendeles.php:841-869`
  (más kulcs = új befizetés; kifizetett rendelésnél „Túlfizetés … Visszautalás szükséges” levél). Ugyanez a Számlázz.hu IPN
  (`ipn:<díjbekérő>:<összeg>`) és egy későbbi kivonat-import között.
- Bizonyítás (p1 P3, p4):
  - 20 000 Ft-os rendelés, 10 000 Ft érkezik. Értesítő bemásolva → `10000 / dijbekero`; utána a kivonat (ugyanaz a
    tétel, banki azonosítóval) → `fizetett 20000 / brutto 20000 / kiszolgalva` – licenc, végszámla „fizetve”, üdvözlő levél,
    pedig csak 10 000 Ft jött. Rögzítve: `["h:cbd0…","ref:TX-778899"]`.
  - Teljes fizetésnél (12 573 Ft): `fizetett/brutto: 25146/12573`, és a levél: „Túlfizetés … újabb 12 573 Ft érkezett …
    **Visszautalás szükséges.**” – a szokásos munkamenet (gyors értesítő-beillesztés, havi kivonat-import) minden
    rendelésnél valódi pénzt visszautaltathat.
  - Ugyanaz az értesítő másnap újra beillesztve (dátummező nélkül): „ÚJ befizetésként rögzítve”.
- Javaslat: csatornafüggetlen lenyomat (összeg + rendelés/közlemény + a bank könyvelési napja; „ma” soha ne legyen
  alapérték); azonos összeg + azonos rendelés N napon belül, eltérő forrásból → `ellenorizendo` állapot adminra, nem
  automatikus jóváírás; az értesítőből (hivatkozás nélkül) csak javaslat legyen, a kivonat/IPN igazolja; a túlfizetés-levél
  „ellenőrizd” legyen, ne „visszautalás szükséges”.

## MAGAS

### M-1 A vevő a core REST-tel átírja a saját e-mail-címét → idegen rendelések, licenckulcs, letöltő link, számla, megújítás
- Hol: a fiók és a licenc-tulajdon e-mail-egyezésre épül: `class-sdh-sales-fiok.php:188` (`user_id = %d OR email = %s`),
  `class-sdh-sales-rendeles.php:403-411` (`licenc_sajat`), `:804` (`utolso_vevo` – pénztár-előtöltés); a rejtés a
  bejelentkezett felhasználónak minden core REST-utat átenged (`class-sdh-sales-rejtes.php:190-192`), a `/wp/v2/users/me`
  saját e-mail-módosítást megerősítés nélkül elfogad; a belépő link válasza REST-nonce-ot is ad (`fiok.php:296`).
- Bizonyítás (p3): B bejelentkezve rendel, a rendelésben a könyvelője címe (ehhez nincs fiók). A támadó vendégként rendel,
  belép, `POST /wp/v2/users/me {"email": <B rendelési címe>}` → `200`, utána a fiókjában: B rendelése, B licenckulcsa
  (`MUNKORA-…`), B friss letöltő linkje, és `licenc_sajat(B kulcsa, A) = true` (megújíthatja / bővítheti).
- Feltétel: a rendelés e-mail-címéhez nincs másik WP-fiók (bejelentkezett vevő más számlázási címmel rendelt, vagy a fiók
  címe később változott). Éles MySQL-en a már foglalt címre a WP nem enged váltani.
- Javaslat: tulajdon csak `user_id` alapján (az e-mail csak akkor, ha a rendeléskor a fiókhoz igazoltan kötődött);
  `sdh_vevo` szerepnek a core `/wp/v2/*` REST zárva (rest_zar: nem-admin bejelentkezettnek is csak a saját névtér);
  `rest_pre_insert_user` / `send_email_change_email` – e-mail-csere csak megerősítéssel.

### M-2 Az IP a hamisítható `CF-Connecting-IP` fejlécből jön (alapból bekapcsolva) → minden korlát és az IPN IP-szűrő megkerülhető
- Hol: `class-sdh-sales.php:302-304` (`cloudflare` alapértéke `true`, `data/beallitas-alap.json:19`); nincs ellenőrizve, hogy a
  kérés tényleg Cloudflare-címről jön. Érinti: `SDH_Sales_Rest::korlat()` minden nyilvános végponton, a Számlázz.hu IPN
  IP-szűrőjét (`class-sdh-sales-szamlazz.php:1273`), a Szikra napi / perc korlátját.
- Bizonyítás (p1 P1): `/kupon` korlát 20/600 s – fejléc nélkül `20/30 átjutott`, változó hamis fejléccel `30/30 átjutott`.
- Következmény: kuponkód-próbálgatás, korlátlan rendelés / vevőfiók / díjbekérő-levél tetszőleges címre (spam a cég
  nevében), belépő-link-bombázás, a Szikra havi keretének percek alatti kimerítése (szolgáltatásmegtagadás), az IPN
  IP-szűrője csak a titkos tokenen múlik.
- Javaslat: a fejlécet csak akkor fogadd el, ha `REMOTE_ADDR` a Cloudflare (vagy beállított megbízható proxy) tartományában
  van; alapérték ki; korlátokhoz `REMOTE_ADDR`-t is számolni.

### M-3 Rejtés: minden REST-válasz WordPress-ujjlenyomatot hordoz; a névtér-index az admin útvonalakat is listázza
- Hol: a WP REST-szerver fejlécei és hibái érintetlenek (`class-sdh-sales-rest.php:72-80` csak Cache-Control-t ír,
  `class-sdh-sales-rejtes.php` REST-részében sincs eltávolítás).
- Bizonyítás (curl, saját oldal):
  - `GET /wp/api/portal/v1/admin/kozpont` → `401`, fejlécek: `Link: <…/wp/api/>; rel="https://api.w.org/"`,
    `Access-Control-Allow-Headers: Authorization, X-WP-Nonce, …`, `Access-Control-Expose-Headers: X-WP-Total,
    X-WP-TotalPages, Link`; törzs: `{"code":"rest_forbidden","message":"Sorry, you are not allowed to do that."…}`.
  - Rossz metódus: `rest_no_route` „No route was found matching the URL and request method.” (p2 R1).
  - `GET /wp/api/portal/v1` látogatónak: a teljes útvonal-lista (`/portal/v1/admin/beallitasok`, `/admin/titok…`),
    `OPTIONS /api/portal/v1/ar` a sémát adja.
  - REJTES.md kifejezetten tiltja (`api.w.org`, `X-WP-…`); minden kosár / pénztár / fiók hívás a böngészőben ezt mutatja.
- Javaslat: `rest_pre_serve_request`-ben `header_remove('Link')`; `rest_allowed_cors_headers` / `rest_exposed_cors_headers`
  szűrők; a saját névtérben a core hibakódok és angol üzenetek cseréje (`rest_post_dispatch` / `rest_request_after_callbacks`
  – pl. `nincs`, „Nincs ilyen cím.”); a névtér-index és az `OPTIONS` látogatónak 404; teszt a t310-ben (ami nincs meg).

### M-4 Helyi Központ (alapmód) + rejtés: a vevők Műhelye nem éri el a Központot (szívverés, frissítés)
- Hol: a Műhely kliens `?rest_route=/sdh-kozpont/v1/…` alakban hív (`includes/class-sdh-muhely-licenc.php:171-172`), a rejtés
  pedig látogatónak minden `?rest_route=`-ot 404-re fut (`class-sdh-sales-rejtes.php:225-227`); `kozpont.mod` alapértéke
  `helyi` (`data/beallitas-alap.json:23`), az üdvözlő levél a webhely címét adja Központként (`kiszolgalas.php:750`).
- Bizonyítás (curl): `POST /wp/?rest_route=/sdh-kozpont/v1/szivveres` → **404**; `POST /wp/api/sdh-kozpont/v1/szivveres` → 200;
  `?rest_route=/sdh-kozpont/v1/csomag` → 404.
- Következmény: minden eladott licenc szívverése és frissítése elbukik ezen a beállításon (a Műhely offline türelmi
  idejének lejárta után a vevő kizárva – a Műhely viselkedése szerint).
- Javaslat: a `korai()` engedje a Központ kliens-névtereit `?rest_route=`-tal is (`/sdh-kozpont/v1`, `/licenc/v1`), vagy a
  vevői build a semleges `/api/licenc/v1`-et hívja és a Központ ott is regisztrálja a `szivveres/jelentes/csomag/tamogatas`
  utakat; e2e teszt Műhely → rejtett értékesítő webhely.

### M-5 Banki párosítás „összeg + név” és „rendelésazonosító” alapján téves rendelést fizet ki (licenc nem fizetőnek)
- Hol: `class-sdh-sales-bank-import.php:502-515` (egyetlen ≥3 betűs közös szó elég, a kapcsolattartó neve is), `:559-571`
  (automatikus jóváírás), `:551` (rendelésazonosító-minta szóhatár és kötőjel nélkül).
- Bizonyítás (p1): P4 – „Kovács Szerviz Kft.” 31 337 Ft-ot utal közlemény nélkül → `osszeg_nev / R-2026-000003`, a
  „Mobil Szerviz Bt.” rendelése `kiszolgalva`. P5 – közlemény „Megrendelés nr 2026 000004 (saját belső szám)” →
  `R-2026-000004 (azon)`.
- A szakmában a „szerviz”, „mobil”, „gsm”, „telefon”, vezetéknevek gyakoriak, a csomagárak egyformák → reális ütközés.
- Javaslat: a 3. (azonosító) és 4. (összeg+név) mód csak **javaslat** legyen admin-jóváhagyással; a név-egyezésből zárd ki az
  általános szavakat és a kapcsolattartót, kérj erős hasonlóságot; az azonosító-minta `\bR-\d{4}-\d{6}\b`.

## KÖZEPES

### K-1 0 Ft-os rendelés fizetés nélkül „fizetve” és végigfut a lánc
- `class-sdh-sales-rendeles.php:731-736`; a valódi árlistában `support_alap` (havi 0) és `egyedi_fejlesztes` (0) van
  (`data/arak.json`), a `support_` kulcs időpont-ajánlatot is küld (`kiszolgalas.php:792-795`).
- p1 P2: vendég `POST /rendeles` (0 Ft-os szolgáltatás) → `201 / fizetve`, DB `kiszolgalva`, fizetés `kezi/ingyenes-…`,
  levél „Megérkezett a fizetés”, vevői WP-fiók létrejött. M-2-vel korlátlanul ismételhető (levél tetszőleges címre).
- Javaslat: 0 Ft-os sor csak csomag/licenc mellé rendelhető, vagy 0 Ft-os rendelés → lead / admin-jóváhagyás.

### K-2 Kupon: fizetetlen rendelések elfogyasztják; nincs zár; kiértékelés korlát nélkül az /ar-on
- `class-sdh-sales-ar.php:61-70` (a `dijbekero` is számít), `:344` (ellenőrzés és mentés között nincs zár → `max` túlléphető),
  `class-sdh-sales-rendeles.php:553` (megújításnál is újra használható, vevőnkénti korlát nincs); `/ar` 120/perc és
  `/kosar/ellenoriz` 120/perc ugyanazt a kuponhibát adja vissza, napló nélkül (a `/kupon` 20/600 s korlátja így hatástalan).
- p1 P7: `max: 2` kupon, 2 fizetetlen rendelés után a valódi vevő: „A(z) KETTO kupon elfogyott.” P6: `/ar` érvényes kódra
  `{"kod":"SZ10",…}`, ismeretlenre „Ismeretlen kuponkód”.
- Javaslat: használat csak fizetett rendelésnél (vagy foglalás lejárattal), atomi számláló; vevőnkénti / első vásárlás
  korlát mezőként; `/ar` és `/kosar/ellenoriz` ne értékelje a kupont (vagy közös, szigorú kupon-korlát).

### K-3 Áfa-kerekítés: a díjbekérő / végszámla végösszege eltér a fizetendőtől
- Rendelés: áfa a nettó összegre egész forintra (`class-sdh-sales-ar.php:420`); számla: soronként 2 tizedesre
  (`class-sdh-sales-szamlazz.php:250-263`).
- p1 P8: rendelés `11233 / 3033 / 14266`; számlasorok `["12573","1692.91"]` = **14 265,91**; QR / levél / Barion: **14 266**.
- Javaslat: a számlasorok áfáját egész forintra, legnagyobb maradékkal úgy elosztani, hogy Σ = rendelés `afa` (vagy a rendelés
  is a számla algoritmusával számoljon). Teszt: egyezés minden árkombinációra.

### K-4 Ütemezett feladatok „éhezése” (LIMIT a szűrés előtt)
- `class-sdh-sales-fizetes.php:276` + `:360` (Számlázz.hu fizetettség: `ORDER BY id ASC LIMIT 20`, a tranziens-kihagyás
  utána) → 20 nyitott díjbekérő fölött az újabbak soha nem kerülnek sorra; `:291-296` (emlékeztető: LIMIT 50, a már
  emlékeztetettek PHP-ban kihagyva); `class-sdh-sales-kiszolgalas.php:1043-1047` (nem kész láncok LIMIT 50 – kikapcsolt
  automatikus számlázásnál minden lánc `kezi` miatt 45 napig „nem kész”, `:651-656`) → az új rendelések újrapróbája elmarad.
- Bizonyítás: kódolvasás (nem futtattam 50+ rendeléssel).
- Javaslat: a kihagyási feltétel az SQL-be (`_emlekeztetve`, utolsó lekérdezés ideje, `kovetkezo <= most`), vagy forgó
  kurzor / `ORDER BY utolso_proba`.

### K-5 Központ: vevő-párosítás puszta cégnévre / nyilvános adószámra, és ugyanez a tulajdon-ellenőrzés
- `class-sdh-kozpont-kiszolgalas.php:611-643` (adószám → e-mail → **név**), `:665-681` (az üres mezőket a támadó adatával
  tölti), `:709-728` (`sajat()`: egyező adószám elég).
- p2 K1: két idegen vevő ugyanazzal a cégnévvel, illetve más névvel de ugyanazzal az adószámmal → `vevo_id 2 vs 2`;
  `sajat()` idegen e-maillel + az adószámmal → `true`.
- Javaslat: név szerint ne párosíts automatikusan (csak javaslat a Központ felületén); tulajdonhoz adószám ÉS e-mail (vagy az
  értékesítő oldali `user_id`-ből képzett vevő-azonosító).

### K-6 Központ: az idempotencia kulcsa csak a rendelésazonosító
- `class-sdh-kozpont-kiszolgalas.php:345-350`, `:397-413`: más vevővel, ugyanazzal az azonosítóval a tárolt választ adja.
- p2 K2: `MUNKORA-2GZ2-… / MUNKORA-2GZ2-… ismetles=true`, és a második vevő **friss letöltő linket** kap. Az `R-ÉÉÉÉ-NNNNNN`
  sorszám újraindul visszaállított / klónozott (staging) értékesítő adatbázisnál – a klón ugyanazzal a kulccsal ír alá.
- Javaslat: a foglaláshoz tárold a vevő (e-mail, adószám) és a tételek lenyomatát; eltérésnél hiba; a kérésbe kerüljön az
  értékesítő példány azonosítója.

### K-7 Rejtés a Központ útjain
- A rejtés látogatónak is nyitva hagyja a `/api/sdh-kozpont/v1/*`-ot (`class-sdh-sales-rejtes.php:202`); hibakód
  `sdh_kozpont_tiltva` / `sdh_kozpont_tamogatas` (`class-sdh-kozpont-api.php:51`, `:286`); a frissítő csomag neve
  `sdh-muhely-<verzió>.zip` (`class-sdh-kozpont-api.php:291`) – a vevő letöltött fájlja.
- curl: `GET /wp/api/sdh-kozpont/v1/csomag?kulcs=x` → `{"code":"sdh_kozpont_tiltva",…}`.
- Javaslat: semleges névtér + kódok, a csomagnév a `fajlnev()`-ből (mint a `letoltes` útnál).

## ALACSONY
1. Admin-levelek `/wp-admin/admin.php?page=sdh-sales` hivatkozással (`kiszolgalas.php:907`, `:936`) – p2 R2: a „Kiszolgálva”
   levélben `/wp-admin/`, `sdh-` találat. REJTES „minden e-mailre” vonatkozik; címzett csak Norbi.
2. A Számlázz.hu IPN titkos tokenje (`k`) a nyers fizetési adatban az adatbázisba kerül (`szamlazz.php:1312`
   `'ipn' => $q->get_params()`), és URL-paraméterként a szervernaplóba.
3. `POST /fizetes/ujra {fizmod:"atutalas"}` díjbekérő állapotú rendelésnél minden hívásra újra elküldi a díjbekérőt és
   újrasüti a `letrehozva` hookot (`fizetes.php:241-247`) – tokenbirtokos levélbombázás (10/600 s, M-2-vel korlátlan).
4. A korlát ellenőrzés-majd-növelés nem atomi (`rest.php:103-113`) – párhuzamos kérésekkel kis túllépés.
5. Központ-kulcs TOFU, `kozpont.url` http is lehet (`kozpont-kapcsolat.php:229-261`) – első kapcsolatkor MITM hamis
   licenckulcsot / letöltő URL-t adhat; javaslat: https kötelező, a kulcs kézi rögzítése kötelező távoli módban.
6. Barion: a pénznem nincs ellenőrizve (`fizetes-barion.php:148-155`).
7. SimplePay: egy korábbi, aláírt FAIL-visszatérés újrajátszása a folyamatban lévő új fizetést „sikertelen”-re állítja és levelet
   küld (`fizetes-simplepay.php:251-256`; a tranzakció nincs a rendelés aktuális azonosítójához kötve).
8. Átutalási QR ideiglenes fájl kitalálható névvel a közös temp mappában (`fizetes-atutalas.php:128`).
9. CSP alapból csak `report` (`biztonsag.php:181`).
10. Egyedi fejlesztés: `fileinfo` hiányában a MIME-ellenőrzés kimarad (`egyedi.php:235-237`); a feltöltések / leadek
    megőrzési ideje nincs szabályozva (GDPR-minimalizálás).
11. A licenc-térkép egyetlen opcióban, olvas-módosít-ír zár nélkül (`kiszolgalas.php:1099-1117`) – két párhuzamos lánc
    elveszítheti egymás bejegyzését (lejárat-emlékeztető kimarad).
12. Bővítésnél a korábban külön vett modul újra felszámítható, ha a kosárban szerepel (`rendeles.php:555`, `ar.php:292-301`
    csak a csomag moduljait szűri).
13. Szikra: a hozzájárulási körben a modell bármely, a beszélgetésben szereplő e-mail-címet elmentheti leadként
    (`szikra-eszkozok.php:403`, `szikra-api.php:232`) – harmadik személy adata hozzájárulás nélkül.

---

## Ami szilárd (ellenőrizve)
- **Egyetlen fizetési belépési pont**: `(forras, tranzakcio)` egyedi kulcs, a „fizetve” átmenet feltételes UPDATE-tel,
  a hook pontosan egyszer (`rendeles.php:841-899`); `fizetve()` önmagában nem hívható kívülről – minden út admin-jog,
  aláírt / API-val igazolt kapu vagy titkos tokenes IPN mögött.
- **Kártyás kapuk**: Barion – a callback nincs aláírva, ezért csak a saját indítású PaymentId-re, a Barion API-tól
  lekérdezett állapottal és összeggel rögzít; SimplePay – HMAC-SHA384 `hash_equals`-szal, a válasz aláírása is ellenőrizve,
  az összeg a query API-ból, orderRef-egyezés (`kapu_eredmeny`).
- **Ed25519 a Központ felé**: nyers bájtokon aláírás (nincs újrakódolás), ±300 s, egyszer használható nonce (600 s őrzés,
  visszajátszás-biztos), a válaszban a mi nonce-unk, opcionális IP-lista, kézzel rögzíthető központi kulcs.
- **Letöltő link**: 192 bites véletlen, csak SHA-256 lenyomat, 14 nap, 3 letöltés atomi `UPDATE … db < max_db`-vel,
  semleges fájlnév, aktív licenc kell.
- **Belépő link**: 256 bites token, SHA-256 tárolás, 30 perc, egyszeri (DELETE dönt, versenyhelyzet-biztos), egyforma
  válasz (nincs felhasználó-felderítés), admin nem léphet be linkkel.
- **Számla**: végszámla csak teljes fizetés után, zár + mezőellenőrzés + `szamlaKulsoAzon` (71/152 hibánál a meglévőt
  kapcsolja) → nincs duplikátum; tesztüzemben „MINTA” megjegyzés / helyi minta-PDF; az Agent-kulcs nem kerül naplóba;
  a PDF tokenes (HMAC) linkkel, védett mappából.
- **Ár**: mindig a szerver számol, egész forint, százalékos kupon legnagyobb maradékkal sorokra; az ügyfél ára csak
  „árváltozás” jelzésre szolgál; lejárat / kezdet / min / hatókör / ciklus / aktív ellenőrizve.
- **REST**: az admin végpontok `SDH_Sales::admin()` + WP cookie-nonce (CSRF ellen), titokszűrő a kimeneten; minden SQL
  prepared (a dinamikus részek konstansokból).
- **Levelek**: tokenértékek escapelve, egylépéses `strtr` (nincs token-befecskendezés), tárgy sortörés-szűrve, címek
  maszkolva a naplóban.
- **Feltöltés**: kiterjesztés-fehérlista + tartalom szerinti MIME + kettős kiterjesztés / PHP-tartalom tiltva, véletlen
  fájlnév, tiltott mappa, csak admin tölti le `attachment` + `nosniff` fejléccel.
- **TOTP**: ±1 lépés, egy kód egyszer (`META_LEPES`), tartalékkódok HMAC-lenyomattal.
