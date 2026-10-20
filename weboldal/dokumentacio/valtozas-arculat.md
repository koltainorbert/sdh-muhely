# Verziónapló-jegyzet – Arculat / téma (a README-be kerül az összefésüléskor)

## Kirakat 1.1.0 + Portál (arculat) – szerkeszthető feliratok, kattintható jelölés, skinek (2026-10-20)

A kinézet nem változott: az alapbeállítás („Neon” skin) pixelre a korábbi neon + üveg arculat (nincs arculat-CSS a kimenetben).

- **Feliratok (Stúdió-szerződés):** a téma minden kódba írt, látogatónak látható szövege (203 db – fejléc, lábléc, kereső,
  blog, árak, 404, tudásbázis, a JS-nek adott feliratok is) felülírható a `sdh_sales_feliratok` opcióból.
  - Jegyzék: `tema/inc/feliratok.php` (kulcs → alap, csoport, leírás „Hol: …”), lekérdezés: `sdh_tema_feliratok()`.
  - `sdh_tema_f($kulcs, $alap)`: felülírás (HTML nélkül, max. 500 karakter, tokencserével), üres → alap.
  - `sdh_tema__($s)` a jegyzékbeli szövegnél automatikusan `sdh_tema_f`-en megy át (a hívási helyek változatlanok).
    Ugyanaz a szöveg több helyen = egy kulcs. Új felirat: a sablonba `sdh_tema__('…')`, és egy sor a jegyzékbe (t245 őrzi).
- **Kattintható szerkesztés:** `sdh_tema_szerk($ut)` (a `SDH_Sales_Studio::attr()` vagy a `sdh_tema_szerk_attr` szűrő
  attribútuma, különben üres). Utak: `o:{post}/{szekció}` (a szekció egésze), `o:{post}/{szekció}/{mező}`,
  `o:…/{lista}/{elem}/{almező}` (az eredeti sorszámmal), `o:…/cta/{n}` | `gomb` | `gomb2` (gombok), `f:{felirat}`,
  `b:termeknev` | `b:szlogen` | `b:ceg` | `b:arculat.logo`, `m:{fo|lablec|jogi}/{i}` és almenüpont `m:fo/{i}/{j}`,
  `t:{tipus}/{kulcs}[/{mező}]` (csomag, modul, funkció, iparág, GYIK, konkurens, esemény, telepítés).
  A szekció-diszpécser `_post` mezőt ad a szekcióknak (a mount-opciókba nem kerül).
- **Skinek:** `SDH_Sales_Arculat::skinek()` – Neon (alap), Ibolya, Kobalt, Smaragd, Borostyán, Magenta; mindegyik világos +
  sötét. Csak a kiemelő-családot cserélik (kitöltés, halvány tónusok, átmenetek, aurora, ragyogás, fókusz); háttér, felület,
  szöveg, állapotszínek maradnak. Beállítás: `arculat.skin` (`ment(['skin' => …])`, előnézet: `terkep(['skin' => …])`),
  `allapot()` → `skin`, `skinek` (név, leírás, mintaszínek). A tokenenkénti felülírás a skin után jön (az nyer).
  WCAG AA: mindhárom szín szövegként ≥ 4,5:1 a háttéren/felületen, a kitöltésen a felirat ≥ 4,5:1 (t246 méri).
- **Javítás:** a tokenenkénti világos felülírás rendszer-világos módban eddig alulmaradt a téma szelektorával szemben
  (kisebb specifikusság) – most ugyanazzal a szelektorral íródik ki.
- Tesztek: t245 (440 ellenőrzés), t246 (176 ellenőrzés).
