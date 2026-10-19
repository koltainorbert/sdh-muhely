<?php
/**
 * Plugin Name:       SDH Központ
 * Description:       Az SDH Műhely forgalmazói központja: vevők (CRM), licencek és jogok, élő rendszerfigyelés, távoli karbantartás és támogatás, kiadások.
 * Version:           2.0.1
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
 * - `app/*` – a Központ felülete (bejelentkezett adminisztrátor, REST-nonce)
 *
 * @package SDH_Kozpont
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SDH_KOZPONT_VERSION', '2.0.1');
define('SDH_KOZPONT_FILE', __FILE__);
define('SDH_KOZPONT_DIR', __DIR__);

require_once __DIR__ . '/includes/class-sdh-kozpont.php';
require_once __DIR__ . '/includes/class-sdh-kozpont-api.php';
require_once __DIR__ . '/includes/class-sdh-kozpont-app.php';

SDH_Kozpont::init();
SDH_Kozpont_Api::init();
SDH_Kozpont_App::init();
