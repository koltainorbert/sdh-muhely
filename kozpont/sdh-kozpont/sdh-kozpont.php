<?php
/**
 * Plugin Name:       SDH Központ
 * Description:       Az SDH Műhely forgalmazói központja: vevők (CRM), licencek és jogok, élő rendszerfigyelés, távoli karbantartás és támogatás, kiadások.
 * Version:           2.2.0
 * Requires PHP:      8.0
 * Text Domain:       sdh-kozpont
 *
 * A FORGALMAZÓ webhelyére kerül, nem a vevőkhöz.
 *
 * Adatvédelem: a példányoktól műszaki adat (verzió, szerver, sebesség, mentés, hibák) és darabszámok érkeznek.
 * A vevő ügyfeleinek személyes adata (név, telefon, e-mail, cím) és a forgalom összege soha.
 *
 * Végpontok (REST, `sdh-kozpont/v1`):
 * - POST `szivveres` (2.0) {kulcs, peldany, ujjlenyomat, verzio, allapot, meres, eredmenyek} → aláírt válasz + műveletek
 * - POST `jelentes`  (1.0, a 0.93–0.98-as példányoknak) {kulcs, peldany, ujjlenyomat, verzio}
 * - GET  `csomag`    ?kulcs&peldany → a licenc csatornájának aktuális kiadása
 * - POST `tamogatas` {kulcs, peldany, link, lejar, ki} → a támogatási link rögzítése
 * - POST `kiszolgalas` (2.1) {adat: base64(json), alairas: base64(Ed25519)} → az értékesítő webhely rendelése: vevő +
 *   licenc (muvelet: uj | megujit | bovit | proba), rendelésenként idempotens; aláírás az értékesítő webhely kulcsával
 *   (Beállítások › Értékesítő webhely), időbélyeg ±300 mp, egyszer használható nonce, opcionális IP-lista; hibára 401
 *   részletek nélkül; a válasz a Központ kulcsával aláírva. Ugyanazon a webhelyen: `SDH_Kozpont_Kiszolgalas::feldolgoz()`.
 * - GET  `letoltes`  (2.1) ?t=<token> → egyszer használható letöltő link az új vevőnek (14 nap, legfeljebb 3 letöltés,
 *   a licenc csatornájának aktuális kiadása, semleges fájlnévvel: `<termeknev>-<verzio>.zip`)
 * - 2.1: a `kiszolgalas` és a `letoltes` a semleges `licenc/v1` névtérben is él – a vevő ezt látja (a link így készül).
 *   A példány-utak (`szivveres`, `jelentes`, `csomag`, `tamogatas`) is ott vannak; semleges hibakódok, a frissítő csomag
 *   neve `<termék>-<verzió>.zip`. Vevő-párosítás: adószám ÉS e-mail / céges domain, vagy pontos e-mail (név szerint soha);
 *   az idempotencia a kérő vevő lenyomatához kötött (más vevő ugyanazzal a rendelésazonosítóval hibát kap).
 *   Licenckulcs-előtag: Beállítások › Értékesítő webhely (`kulcs_elotag`, alapból a terméknévből, végül „LIC”);
 *   a régi `SDH-` kulcsok változatlanul érvényesek.
 * - `app/*` – a Központ felülete (bejelentkezett adminisztrátor, REST-nonce)
 * - 2.2: beágyazható – az értékesítő bővítmény (Portál) a saját csomagjában hozza (`licenc/` mappa, semleges fájlnevek:
 *   `kozpont.php`, `includes/class-k*.php`), ha a különálló Központ nincs bekapcsolva. Beágyazva (`SDH_KOZPONT_BEAGYAZVA`)
 *   nincs saját felső menü, belépés utáni átirányítás és admin-sáv gomb; a licenceket a Portál adminja kezeli, a teljes
 *   központ-felület rejtett haladó oldalként érhető el. Ha a különálló bővítmény is aktív, az fut (nincs dupla betöltés).
 *
 * @package SDH_Kozpont
 */

if (!defined('ABSPATH')) {
    exit;
}

// Egy példány fut: ha egy másik másolat (különálló bővítmény / beágyazott) már betöltődött, ez kimarad.
if (defined('SDH_KOZPONT_VERSION') || class_exists('SDH_Kozpont', false)) {
    return;
}

define('SDH_KOZPONT_VERSION', '2.2.0');
define('SDH_KOZPONT_FILE', __FILE__);
define('SDH_KOZPONT_DIR', __DIR__);

// A fájlnév előtagja a tárolóban `class-sdh-kozpont`, a semleges (beágyazott) csomagban `class-k`.
$sdh_kozpont_elotag = is_file(__DIR__ . '/includes/class-k.php') ? 'class-k' : 'class-sdh-kozpont';

foreach (['', '-api', '-app', '-kiszolgalas'] as $sdh_kozpont_v) {
    require_once __DIR__ . '/includes/' . $sdh_kozpont_elotag . $sdh_kozpont_v . '.php';
}

unset($sdh_kozpont_elotag, $sdh_kozpont_v);

SDH_Kozpont::init();
SDH_Kozpont_Api::init();
SDH_Kozpont_App::init();
SDH_Kozpont_Kiszolgalas::init();
