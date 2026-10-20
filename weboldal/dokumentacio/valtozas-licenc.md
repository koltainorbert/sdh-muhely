# Verziónapló-jegyzet – Licenckezelés a Portálban (2026-10-20, a README-be fésülendő)

## Portál (a következő verzióban)
- **A licencközpont a Portál része – egy telepítés.** A `portal-<v>.zip` hozza (`licenc/kozpont.php`,
  `licenc/includes/class-k*.php`, semleges nevekkel, a kiszolgált fájlokban nincs rendszer- / márkanév). Ha a különálló
  Központ-bővítmény aktív, az fut (nincs dupla betöltés); ha nem, a beágyazott (`plugins_loaded` -100). Azonos táblák és
  beállítások: a kettő között adatvesztés nélkül lehet váltani. A „helyi” licencközpont-mód így alapból működik.
- **Új admin-csoport: Licencközpont** (a Vevők és a Tartalom között):
  - **Licencek** – 6 számkártya (összes, aktív, 30 napon belül lejár, lejárt, felfüggesztett, próba), szűrők, keresés
    (kulcs, cég, e-mail, adószám kötőjellel / nélkül, domain és címke a telepítés jelentéséből), rendezés, lapozás.
    Részletek (popup, lapfülekkel): áttekintés (vevő + licenc), modulok (kapcsolóval, azonnal), telepítések
    (leválasztás), letöltő linkek, a webes rendelések és számlák, napló. Műveletek: hosszabbítás (egy ciklus / +1, 3, 6,
    12 hó / dátumra / lejárat nélkül), csomagváltás (a csomag moduljaival és kereteivel, vagy csak névvel), kulcs küldése
    e-mailben (új letöltő linkkel), szerkesztés (keretek, díj, ciklus, csatorna, jogok, üzenet a vevőnek, megjegyzés),
    felfüggesztés / visszaállítás, új kulcs (a webes rendeléseken is cseréli), új letöltő link (a régiek érvénytelenek).
    Kétlépcsős gombok, minden művelet a licencközpont és a Portál naplójában. Új licenc kézzel (meglévő / új vevő).
  - **Telepítések** – a vevők rendszerei: jelentkezik / nem jelentkezik / gondban / régi verzió, keresés; részletek,
    címke és jegyzet, távoli műveletek (diagnosztika, mentés, frissítés, üzenet a vevőnek, támogatási hozzáférés),
    belépő linkek, leválasztás.
  - **Kiadások** – vevői zip feltöltése (stabil / teszt csatorna), kiadás mindenkinek / vissza tesztre, törlés.
  - A Vevők és a Rendelés popupban a licenckulcsra kattintva a licenc nyílik meg. Parancspaletta: „Új licenc”,
    „Lejáró licencek”.
  - **Haladó nézet**: a teljes licencközpont-felület (rendszerfigyelés, mérések, támogatás, beállítások) rejtett oldalként
    (`admin.php?page=licenckozpont`), „Licencközpont” címmel; beágyazva nincs külön felső menü, belépés utáni
    átirányítás és admin-sáv gomb.
  - Távoli licencközpont-módban a panelek ezt jelzik, és a távoli központra mutatnak (a REST 409).
- Új e-mail-sablon: `licenc_kulcs` (kézi kulcsküldés).
- REST (`portal/v1`, csak admin): `admin/licenc/allapot`, `admin/licencek`, `admin/licenc/{id}` (GET / POST),
  `admin/licenc` (POST), `admin/licenc/{id}/muvelet`, `admin/licenc/vevok`, `admin/licenc/peldanyok`,
  `admin/licenc/peldany/{id}` (+ `/muvelet`, `/levalaszt`), `admin/licenc/kiadasok`, `admin/licenc/kiadas` (+ `/{id}`),
  `admin/licenc/naplo`.
- Tesztek: t320 (beágyazott / különálló / nincs – al-folyamatokban, a tesztoldal beállításától függetlenül), t321
  (jogosultság, távoli mód), t322 (lista, szűrők, keresés, lapozás; 300 licenc: 11–25 ms), t323 (minden művelet), t324
  (létrehozás, módosítás, ellenőrzések), t325 (telepítések, kiadások), t326 (csomag: szerkezet, nevek, szivárgás, PHP-lint).
  Böngészős: `tesztek/e2e/licenc-vizualis.py` (az admin-vizualis.py mérőivel) – 225 állapot, 0 hiba.

## Licencközpont 2.2.0 (`kozpont/sdh-kozpont`)
- Beágyazható: egyszer töltődik (ha egy másik másolat már fut, a második kimarad), a csomagban semleges fájlnevekkel
  is (`class-k*.php`), `SDH_KOZPONT_BEAGYAZVA` / `SDH_KOZPONT_URL` állandók, `sdh_kozpont_felulet_url` és
  `sdh_kozpont_eszkoz_url` szűrő. Beágyazva a kiadások semleges mappába (`uploads/licenc-kiadas/kiadas-<v>-….zip`).
- A felület kiszolgált fájljaiból (kozpont.js / .css) kikerült a márka- és rendszernév (`window.LK_BOOT`, „Licencközpont”,
  „Rendszer-admin”); különálló módban a cím továbbra is „SDH Központ”.
- t94 a 2.2.0-ra igazítva; a Műhely teljes tesztcsomagja zöld (75), t92 / t94 a különálló Központtal.
- (A Műhely `README.md` verziónaplójába: „Központ 2.2.0 – beágyazható a Portálba”.)
