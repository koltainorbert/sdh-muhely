# Számlázás (Számlázz.hu Számla Agent) és időpontfoglalás

Osztályok: `SDH_Sales_Szamlazz` (`includes/class-sdh-sales-szamlazz.php`), `SDH_Sales_Naptar` (`includes/class-sdh-sales-naptar.php`),
front: `assets/front/foglalas.js`. Tesztek: `tesztek/t260–t269`. A dokumentációt 2026-10-19-én vetettem össze a
https://docs.szamlazz.hu/hu/agent/ oldallal.

## 1. Módok

| Mód (`mod()`) | Mikor | Mi történik |
|---|---|---|
| `minta` | `szamlazz.teszt` = igaz, nincs tesztkulcs | Helyi „MINTA” PDF (saját, könyvtár nélküli PDF-író), a Számlázz.hu nem kap kérést. Demóhoz és fejlesztéshez. |
| `teszt` | `szamlazz.teszt` = igaz + `szamlazz_teszt_kulcs` titok | Valódi Agent-hívás **tesztfiókra**; a megjegyzés MINDIG „MINTA – tesztüzem, nem valódi számla.”-val kezdődik. |
| `eles` | `szamlazz.teszt` = hamis + `szamlazz_agent_kulcs` titok | Valódi számla. **Visszafordíthatatlan – élesre kapcsolni csak Norbi döntése.** |
| `ki` | éles mód kulcs nélkül | Nincs számlázás; a lánc `szamla` lépése hibát jelez. |

A Számlázz.hu-nak nincs külön sandbox-szervere: tesztelni egy külön (ingyenes / próba) fiókkal és annak Agent-kulcsával lehet.
Az Agent-kulcs csak kisbetűs lehet, nem jár le, és egy fiókhoz legfeljebb 17 kulcs tartozhat.

## 2. Folyamat

1. `sdh_sales_rendeles_letrehozva` hook → átutalásnál `dijbekero($id)` (`<dijbekero>true</dijbekero>`, `rendelesSzam` = rendelés
   azonosítója, a megjegyzésben a közlemény) → `rendeles.szamla_dijbekero`, PDF a védett mappában.
2. Fizetés → a kiszolgálási lánc `szamla` lépése: ha `szamlazz.automatikus` igaz (**alapértelmezés, Norbi döntése**), meghívja a
   `vegszamla($id)`-t. Ha hamis, a lánc vár, és az admin indítja a `POST /admin/szamla/{id}/ujra` végponttal
   (`sdh_sales_vegszamla_kezi` hook).
3. `vegszamla()`: normál számla a díjbekérőből (`<dijbekeroSzamlaszam>`, „Az Agent a megadott számlaszám alapján a díjbekérőhöz
   kapcsolva készíti el a számlát”), `fizetve=true`, a teljesítés napja a fizetés napja (budapesti idő), fizetési mód
   (átutalás / Barion / bankkártya), vevő adószámmal (`adoalany` 1 / 0 / −1; magánszemély adóazonosító jele nem kerül rá),
   tételek a `rendeles.tetelek`-ből, áfakulcs az `afa` beállításból (vagy `szamlazz.afakulcs`, pl. „AAM”).
   Ha a rendeléshez nem készült díjbekérő (kártyás fizetés), önálló számla megy.

Nem `vegszamla=true`-t használunk: a Számlázz.hu-ban a „végszámla” az **előlegszámlát** záró bizonylat, a díjbekérőt nem.

## 3. Idempotencia és duplikáció

- Ha a `szamla_dijbekero` vagy a `szamla_vegszamla` mező ki van töltve, a függvény a meglévő bizonylatot adja vissza, és nem küld kérést.
- A párhuzamos futást zár akadályozza meg (`korlat` tábla, `zar:szamla:<id>` elsődleges kulcs, 60 mp-es lejárattal).
- Minden bizonylat `szamlaKulsoAzon` = `sdh-sales-<rendelés>-d|v` azonosítót kap. Ha a Számlázz.hu fiókban be van kapcsolva a
  „Rendelésszám ismétlődés tiltása” beállítás, és 71-es vagy 152-es kód jön, a meglévő bizonylatot a külső azonosító alapján
  visszakeressük (`action-szamla_agent_xml`), és azt kapcsoljuk a rendeléshez. **Ajánlott bekapcsolni.** Bekapcsolt tiltásnál, ha
  2 napon belül ugyanaz a kérés érkezik újra, a Számlázz.hu a korábbi számlát adja vissza.
- A rendszer automatikusan legfeljebb 5-ször próbálkozik (`MAX_PROBA`, a Számlázz.hu ajánlása). Utána kézi újraindítás kell
  (`/admin/szamla/{id}/ujra`), amely lenullázza a számlálót.

## 4. Hibakezelés

A válaszból ezeket olvassuk ki: `szlahu_error_code`, `szlahu_error`, `szlahu_szamlaszam`, `szlahu_bruttovegosszeg` fejléc;
valaszVerzio=2 esetén `xmlszamlavalasz` (`sikeres`, `hibakod`, `hibauzenet`, `szamlaszam`, base64 `pdf`); v1-nél PDF-törzs,
„[ERR] …” vagy „xmlagentresponse=DONE;<szám>”. Saját magyarázatot írunk a 3, 54, 57, 71/152, 136 és 202 kódhoz, a többinél a
Számlázz.hu szövegét adjuk tovább a kóddal együtt. Hiányos vevőadatnál (név, irányítószám, település, cím) a rendszer nem küld
kérést. Minden hiba bekerül a naplóba (`ref_tipus=rendeles`).

## 5. PDF-ek

`wp-content/uploads/sdh-sales-szamla/`: a mappában `.htaccess` (Apache 2.2/2.4 deny), `index.php` és `web.config` (IIS) van, a
fájlnév HMAC-kal képzett (40 hex). **Nginx alatt** ezt a szabályt kézzel kell felvenni:
`location ^~ /wp-content/uploads/sdh-sales-szamla/ { deny all; }`.

A PDF-ek elérhetősége:
- admin: `GET /admin/szamla/{rendeles_id}/pdf?tipus=dijbekero|vegszamla` (`sdh_sales_admin` jog);
- vevő: `SDH_Sales_Szamlazz::pdf_link($id, $tipus)` → `GET /szamla/{azon}/pdf?tipus=…&t=<HMAC>` (nem jár le, rendelésenként és
  bizonylatonként külön token);
- levélcsatolmány: `dijbekero_pdf($id)` / `vegszamla_pdf($id)` → helyi útvonal. Ha a fájl elveszett, a rendszer újra lekéri
  (`action-szamla_agent_pdf`), minta módban újrarajzolja.

## 6. Fizetettség – mit kínál a Számlázz.hu?

- **Lekérdezés**: a „Bizonylat lekérése XML-ben” (`action-szamla_agent_xml`, `xmlszamlaxml`: `szamlaszam` | `rendelesSzam` |
  `szamlaKulsoAzon`) válaszában szerepel a `kifizetesek/kifizetes` (`datum`, `jogcim`, `osszeg`) és az
  `osszegek/totalossz/brutto`. Külön „fizetve” mező nincs, ezért a `fizetettseg()` a kifizetések összegét veti össze a bruttóval:
  `{brutto, fizetve, hatralek, rendezett, kifizetesek[], hatarido (fizh)}`. A dokumentáció szerint ez a felület csak a
  Számlázz.hu-ban kiállított kimenő számlákra működik; díjbekérőre a tesztfiókban kell kipróbálni.
- **Push (IPN)**: a Számlázz.hu `POST`-ot küld (`application/x-www-form-urlencoded`), amikor egy számla vagy díjbekérő fizetett
  összege változik. Mezők: `szlahu_szamlaszam`, `szlahu_dijbekero_szama`, `szlahu_rendelesszam`, `szlahu_bruttovegosszeg`,
  `szlahu_kifizetettbrutto`, `szlahu_fizetesmod`, `szlahu_kifizdat` (ez utóbbit az ügyfélszolgálat kapcsolja be). 3 percenként
  legfeljebb 10-szer próbálkozik, HTTP 200-at vár. **Aláírás nincs.** A küldő IP-címek 2025-08-01-től: 3.73.214.98,
  3.76.149.232, 18.153.156.51.
  Végpontunk a `POST /szamlazz/ipn?k=<titok>`. Csak `fizetes.automatizalas` = `"szamlazz"` esetén működik. Védelmei: titkos token,
  IP-lista (`szamlazz.ipn_ip_szures`, alapból be), a díjbekérő és a rendelésszám egyezése, és ha lekérdezhető, a Számlázz.hu adata
  az összegre. A kifizetett összeg és a rendelésen már rögzített összeg különbözetét az `SDH_Sales_Rendeles::fizetve(id, 'szamlazz',
  'ipn:<szám>:<összeg>', különbözet)` rögzíti. Az IPN-címet a `POST /admin/szamlazz/proba` válasza (`ipn_url`) adja; ezt kell
  beírni a Számlázz.hu-n: Fiók beállítások → Számlázás alapadatok.
- **Befizetés rögzítése** (`action-szamla_agent_kifiz`, `xmlszamlakifiz`): ezzel MI írnánk a Számlázz.hu-ba; ezt nem használjuk,
  mert a végszámla eleve `fizetve=true`.

## 7. Beállítások és titkok

`szamlazz.teszt`, `szamlazz.automatikus`, `szamlazz.elotag` (a Számlázz.hu fiókban felvett előtag!), `szamlazz.eszamla`,
`szamlazz.kuldes` (a Számlázz.hu is küldjön levelet; alapból nem, mert a mi levelünk viszi a számlát), `szamlazz.bank`,
`szamlazz.megjegyzes` (`{rendeles}`, `{kozlemeny}`), `szamlazz.afakulcs`, `szamlazz.ipn_ip_szures`, `fizetes.dijbekero_nap`.
Titkok (`SDH_Sales::titkos_ment`): `szamlazz_agent_kulcs`, `szamlazz_teszt_kulcs`, `szamlazz_ipn_token` (automatikus).
Agent URL tesztre: `SDH_SALES_SZAMLAZZ_AGENT_URL` állandó vagy `sdh_sales_szamlazz_agent_url` szűrő.

## 8. Időpontfoglalás (`SDH_Sales_Naptar`)

- Típusok: `demo` (30 perc + 15 perc puffer, nyilvános), `telepites` (90 + 30), `oktatas` (60 + 15), `support` (30 + 10,
  nyilvános). A nem nyilvános típusok **csak** a rendeléshez kötött `ajanlat_link($rendeles_id, $tipus)` linkkel foglalhatók
  (HMAC `rt`). Beállítás: `naptar.tipus.<kulcs>` = `{nev, leiras, hossz, puffer, aktiv, nyilvanos}`.
- Munkaidő: `naptar.napok` [1..5], `naptar.nyit` 09:00, `naptar.zar` 17:00, `naptar.lepes` 30, `naptar.min_ora` 12,
  `naptar.max_nap` 30, `naptar.kapacitas` 1, `naptar.lemondas_ora` 2, `naptar.zarolt` (napok vagy `YYYY-MM-DD..YYYY-MM-DD`),
  `naptar.unnepnapok` (magyar munkaszüneti napok: fix napok, nagypéntek, húsvét- és pünkösdhétfő). Az áthelyezett munkanapok
  (szombati munkanapok, „hídnapok”) nincsenek benne: ezeket a `naptar.zarolt` beállításban, vagy az `sdh_sales_unnepnapok`
  szűrővel kell megadni.
- Időkezelés: a sávokat napról napra budapesti falióra-időből számoljuk, és UTC-ben tároljuk. A nyári időszámítás váltásának hetében is
  9:00 a 9:00 (a t264 teszteli 2026-10-25 körül).
- Dupla foglalás ellen: a foglalás egy adatbázis-zár (`zar:foglalas`) alatt újraszámolja a szabad sávokat. Négy párhuzamos
  folyamatból pontosan egy nyer (t265). Rate limit: IP-nként 10/óra, e-mail-címenként 5/nap. Ha létezik, a rendszer meghívja a
  `SDH_Sales_Biztonsag::ellenoriz()`-t is.
- Kezelő link: `?foglalas=<id>-<HMAC>`. Az adatbázisban csak egy véletlen nonce áll, a link nem állítható elő belőle. Ezzel lehet
  lemondani (két lépésben) és áthelyezni (a régi foglalás `atfoglalva` állapotba kerül, a levél a régi időpont törlő ICS-ét is
  viszi).
- Emlékeztető: 24 és 1 órával előtte, a `SDH_Sales::CRON`-ból (`emlekezteto_kuldve`: 0 → 1 → 2, feltételes UPDATE, így
  mindegyik pontosan egyszer megy).
- Levelek: `SDH_Sales_Email::kuld($sablon, $cimzett, $tokenek, $csatolmanyok)`, ha létezik (sablonok: `foglalas_visszaigazolas`,
  `foglalas_emlekezteto`, `foglalas_lemondas`, `idopont_ajanlat`; tokenek: `{nev}`, `{tipus}`, `{idopont}`, `{idotartam}`,
  `{helyszin}`, `{lemondas_url}`, `{atfoglalas_url}`, `{ics_url}`, `{foglalas_url}`, `{rendeles}`, `{regi_idopont}`). Ha
  nincs ilyen modul, beépített HTML-szöveg megy `wp_mail`-lel.
- ICS: RFC 5545 (CRLF, 75 oktettes hajtogatás, stabil UID, UTC DTSTAMP/DTSTART/DTEND, VALARM, CANCEL).
- **Google Naptár – feliratkozás (ajánlott, nulla beállítás)**: az admin a `GET /admin/naptar/ical` végpontról kapja a titkos
  feed-URL-t. A Google Naptárban: Egyéb naptárak → „+” → URL alapján. A Google kb. 8–24 óránként frissít (ezt a Google-tól
  tudjuk, nem mérés). Új cím a `POST /admin/naptar/ical` végponttal kérhető, ez a régit érvényteleníti.
- **Google Calendar API-küldés (opcionális, alapból ki)**: azonnali bejegyzés. Beállítás: Google Cloud Console → projekt →
  Calendar API engedélyezése → OAuth-kliens (Web) → refresh token megszerzése (pl. OAuth Playground, scope:
  `https://www.googleapis.com/auth/calendar.events`). Beállítások: `naptar.google.be` = igaz, `naptar.google.client_id`,
  `naptar.google.naptar_id` (alapértelmezés: `primary`). Titkok: `google_client_secret`, `google_refresh_token`. Próba:
  `POST /admin/naptar/google`. A szinkron a cronból fut (az új foglalásokat felküldi, a lemondottakat törli).
- REST: `GET /foglalas/savok?tipus&tol&ig` (`Cache-Control: no-store`), `POST /foglalas`, `POST /foglalas/lemond`,
  `GET /foglalas/adat?t`, `GET /foglalas/ics?t`, `GET /foglalas/elotolt?tipus&rendeles&rt`, `GET /naptar/ical?t`;
  admin: `GET /admin/foglalasok`, `POST /admin/foglalasok/{id}/lemond`, `GET|POST /admin/naptar/ical`, `POST /admin/naptar/google`.
- Front: `<div data-sdh="foglalas" data-tipus="demo">` + `SDH_Sales_Front::igeny('foglalas')`, vagy a `[sdh_foglalas tipus="demo"]`
  shortcode. A foglaló oldal URL-je: `naptar.oldal`, ennek hiányában az `/idopontfoglalas/` slug.
