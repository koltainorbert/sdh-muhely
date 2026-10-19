# REJTÉS – a legfontosabb szabály (Norbi, 2026-10-19 21:18) – minden más szabály előtt

**Senki nem tudhatja meg, hogy a rendszer alatt WordPress fut, és hogy WordPresszel dolgozunk. Senki nem tudhatja meg,
hogy az SDH (SDH Szerviz, SDH Műhely, SDH Központ) áll mögötte. SDH-anyag nem kerülhet bele és nem mehet ki.**

Érvényes: az értékesítő webhelyre, a demóra, a vevőnek átadott csomagra (Műhely vevői build), minden e-mailre, PDF-re,
számlára, licenckulcsra, letöltött fájlra, hibaüzenetre, HTTP-fejlécre, sütire, JS-változóra, CSS-osztályra, URL-re,
fájl- és mappanévre, JSON-LD-re, llms.txt-re, robots.txt-re, sitemapre, AI-chat válaszra, tudásbázisra.
Belső (soha ki nem szolgált) dolgok maradhatnak: PHP-osztálynevek, opció- és táblanevek, a tároló mappái, a fejlesztői
dokumentáció a `weboldal/dokumentacio/` alatt (az nem kerül csomagba).

## Tilos a kimenetben (HTML, JS, CSS, JSON, fejléc, e-mail, PDF, fájlnév, URL)
- a szavak: `WordPress`, `wordpress`, `WP ` (önállóan), `Gutenberg`, `WooCommerce`, `plugin`, `bővítmény`, `téma` mint WP-téma,
  `Rank Math`, `WP Rocket`, `Site Kit`, `Yoast`, `Elementor`, `Bricks`, `SDH`, `sdh`, `SDH Szerviz`, `Veszprém` mint eredettörténet
- az útvonalak: `/wp-content/`, `/wp-includes/`, `/wp-admin/`, `/wp-json/`, `wp-login.php`, `xmlrpc.php`, `wp-cron.php`,
  `admin-ajax.php`, `?rest_route=`, `?p=123`, `/author/`, `wp-sitemap`
- az azonosítók: `wp-` kezdetű CSS-osztály / id / handle (`wp-block-*`, `wp-image-*`, `wp-emoji`, `wp-embed`), `sdh` bármilyen
  formában (`sdh-`, `sdh_`, `SDH_`, `data-sdh`, `window.SDH`), a WP alap sütinevei (`wordpress_*`, `wp-settings-*`)
- `<meta name="generator">`, RSD, wlwmanifest, shortlink, oEmbed-linkek, `Link: …api.w.org` fejléc, `X-Pingback`,
  `X-Redirect-By`, `X-Powered-By`, harmadik fél HTML-megjegyzései („Rank Math”, „WP Rocket”, „Site Kit”…)

## Kötelező nevek (a kimenetbe kerülő azonosítók) – ezeket használd, a régieket cseréld
| Régi | Új |
|---|---|
| REST: `/wp-json/sdh-sales/v1/…` | `/api/portal/v1/…` (`SDH_Sales_Rest::NS` = `portal/v1`, előtag `api`; JS-ben soha ne írd be kézzel, mindig a kapott `rest` címet használd) |
| `data-sdh="kalkulator"` | `data-modul="kalkulator"` |
| `window.SDH`, `window.SDH_<MODUL>`, `SDH_KOZOS`, `SDH_SUTI` | `window.APP`, `window.APP_<MODUL>`, `APP_KOZOS`, `APP_SUTI` |
| script/style handle `sdh-sales-*` (a `<script id>`-ben látszik!) | `p-*` (pl. `p-kozos`, `p-kalkulator`) – `SDH_Sales_Front` már így adja |
| süti `sdh_suti_id`, bármilyen `sdh_*` süti | `suti_id`, előtag nélkül |
| query var / GET-paraméter `sdh_*` (`?sdh_tanusitvany=`, `?sdh_demo=`) | semleges (`?tanusitvany=`, `?bemutato=`) |
| feltöltési mappa `uploads/sdh-sales/`, `sdh-sales-szamla/` | `uploads/portal/`, `uploads/portal-szamla/` |
| CSS-osztály `sdh-*` | `s-*` (a téma előtagja) |
| HTML-megjegyzés, `console.log` „SDH” | tilos |
| licenckulcs `SDH-XXXX-…` | `<előtag>-XXXX-…`, az előtag a Központ beállítása (alap: a termék rövid neve nagybetűvel, pl. `MUNKORA`) |
| terméknév helyőrző „SDH CRM” | „Munkora” (helyőrző, Norbi még választ – egy mező: `termeknev`) |

## Szöveg
- A telepítés oldalon sem szerepel WordPress: „saját szerveren vagy felhőben fut; követelmény: Linux szerver, PHP 8.1+,
  MySQL 8 / MariaDB 10.6+, HTTPS, ütemező (cron); a telepítést mi végezzük (telepítési díj)”.
- Nincs eredettörténet, ami az SDH-ra / Veszprémre / egy konkrét szervizre utal. A cég adatai csak tokenekből (`{ceg}`…).
- Az AI-chat (Szikra) és a tudásbázis sem említ WordPresst, SDH-t; ha rákérdeznek, mire épül: „saját fejlesztésű,
  PHP / MySQL alapú webes rendszer” – ennél többet nem mond.

## Technika (a vezető írja: `includes/class-sdh-sales-rejtes.php` + `dokumentacio/rejtes-uzemeltetes.md`)
REST-előtag `api`, a WP saját REST-végpontjai látogatónak 404; nincs generator / RSD / wlwmanifest / shortlink / oEmbed /
emoji / block-library CSS / jQuery a fronton; `xmlrpc`, `wp-cron.php` (valódi cron), `/author/`, `?author=` 404; a belépés
titkos címen (`wp-login.php`, `/wp-admin/` látogatónak 404); `X-Redirect-By`, `X-Pingback`, `Link` fejléc ki; az e-mail
feladója a beállításból; saját `wp_die` kezelő; kimeneti szűrő (utolsó védvonal) a megmaradt nyomokra; a tartalommappa és a
bővítmény-/témamappák semleges nevűek a telepítésnél (wp-config: `WP_CONTENT_DIR/URL`, sütinevek) – lásd az üzemeltetési
leírást. Ellenőrzés: `tesztek/t310-rejtes.php` + a végső bejáró (`tesztek/e2e/rejtes-bejaras.py`), ami minden oldal HTML-jét,
fejléceit, sütijeit és a betöltött fájlok URL-jeit átnézi a tiltott mintákra – 0 találat a cél.
