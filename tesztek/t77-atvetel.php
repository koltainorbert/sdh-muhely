<?php
/**
 * t77 – F3 (0.85.0): átvételi fotók és aláírás.
 *
 * - Az aláírás PNG ellenőrzése (fejléc, méret), mentése a lappal (név, idő), cseréje, törlése.
 * - Fotók a lapra (csatolmány `munkalap`), a lapfül darabszáma, a popup lapfüle.
 * - Hibás aláírásnál a lap nem mentődik; az átvételi nyomtatványon a kép(ek).
 */

define('SDH_MUHELY_TESZT', true);

require __DIR__ . '/_keret.php';

global $wpdb;

$A = 'SDH_Muhely_Atvetel';
$T = static fn (string $t): string => SDH_Muhely_Schema::tabla($t);

T::igaz(version_compare(SDH_Muhely_Schema::DB_VERSION, '0.48.0', '>='), 'sémaverzió 0.48.0');
T::igaz(t_oszlop($T('munkalap'), 'atvetel_alairva') && t_oszlop($T('munkalap'), 'atvetel_alairo'), 'új oszlopok: atvetel_alairva, atvetel_alairo');

// Egy aláírásnak látszó PNG és egy fotó (GD-vel).
$kep = imagecreatetruecolor(600, 150);
imagefill($kep, 0, 0, imagecolorallocate($kep, 255, 255, 255));
imageline($kep, 20, 120, 580, 30, imagecolorallocate($kep, 0, 0, 0));
ob_start();
imagepng($kep);
$png = (string) ob_get_clean();
$data_url = 'data:image/png;base64,' . base64_encode($png);

$foto = (string) tempnam(sys_get_temp_dir(), 't77');
$fk   = imagecreatetruecolor(800, 600);
imagefill($fk, 0, 0, imagecolorallocate($fk, 200, 80, 40));
imagejpeg($fk, $foto, 80);

/* --- Az aláírás ellenőrzése ---------------------------------------------------------------------- */

T::egyezik($png, $A::alairas_bajtok($data_url), 'érvényes PNG: elfogadva');
T::egyezik(null, $A::alairas_bajtok('data:image/jpeg;base64,' . base64_encode($png)), 'nem PNG típus: elutasítva');
T::egyezik(null, $A::alairas_bajtok('data:image/png;base64,' . base64_encode('nem kép')), 'hamis fejléc: elutasítva');
T::egyezik(null, $A::alairas_bajtok('data:image/png;base64,' . base64_encode("\x89PNG\r\n\x1a\n" . str_repeat('x', $A::ALAIRAS_MAX))), 'túl nagy: elutasítva');
T::egyezik(null, $A::alairas_bajtok('data:image/png;base64,%%%'), 'hibás base64: elutasítva');

/* --- Mentés a munkalappal ------------------------------------------------------------------------------ */

$u  = t_ugyfel('Átvétel T77');
$e  = t_eszkoz($u, 'Xiaomi', 'T77');
$ml = t_munkalap($u, [$e]);
$alap = SDH_Muhely_Munkalap::alap_kulcs(SDH_Muhely_Munkalap::allapotok());
$ment = static function (array $tovabbi) use ($ml, $u, $e, $alap): array {
    return t_ajax('sdh_muhely_munkalapok_ment', $tovabbi + [
        'id' => $ml, 'fajta' => 'javitas', 'allapot' => $alap, 'ugyfel_id' => $u, 'eszkoz_id' => $e,
        'keszult' => current_time('Y-m-d'), 'atvetel_jelen' => '1',
    ], 'sdh_nonce', 'sdh_muhely_munkalap_mentes');
};

// Hibás aláírás: a lap sem mentődik.
$r = $ment(['atvetel_alairas' => 'data:image/png;base64,' . base64_encode('rossz'), 'nev' => 'NEM MENTŐDHET T77']);
T::igaz(empty($r['success']), 'hibás aláírásnál a mentés hibát ad');
T::igaz((string) $wpdb->get_var($wpdb->prepare('SELECT nev FROM ' . $T('munkalap') . ' WHERE id = %d', $ml)) !== 'NEM MENTŐDHET T77', 'és a lap nem mentődött félig');

$_FILES = ['csatolmany' => ['name' => ['karc.jpg'], 'type' => ['image/jpeg'], 'tmp_name' => [$foto], 'error' => [UPLOAD_ERR_OK], 'size' => [filesize($foto)]]];
$r = $ment(['atvetel_alairas' => $data_url, 'atvetel_alairo' => 'Teszt Tamás']);
$_FILES = [];
T::igaz(!empty($r['success']), 'mentés aláírással és fotóval');

$l = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $T('munkalap') . ' WHERE id = %d', $ml));
T::egyezik('Teszt Tamás', (string) $l->atvetel_alairo, 'az aláíró neve');
T::igaz(!empty($l->atvetel_alairva), 'az aláírás ideje');
$al = $A::alairas($ml);
T::igaz($al !== null && (string) $al->mime === 'image/png', 'az aláírás PNG csatolmányként');
T::egyezik($png, (string) file_get_contents(SDH_Muhely_Csatolmany::fajl_ut($al)), 'a tárolt kép bájtra azonos');
T::egyezik(1, count($A::fotok($ml)), 'egy fotó a lapon');
T::egyezik(2, $A::darab($ml), 'a lapfül darabszáma: fotó + aláírás');
T::tartalmaz((string) $wpdb->get_var($wpdb->prepare('SELECT osszegzes FROM ' . SDH_Muhely_Valtozasnaplo::tabla() . " WHERE fo_tipus = 'munkalap' AND fo_id = %d AND osszegzes LIKE %s ORDER BY id DESC LIMIT 1", $ml, '%aláírás%')), 'Teszt Tamás', 'naplóban az aláírás');

// A lapfül.
$urlap = t_ajax('sdh_muhely_munkalapok_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => $ml])['nyers'];
T::tartalmaz($urlap, 'id="ful_m_atvetel"', 'a munkalap popupjában az Átvétel lapfül');
T::tartalmaz($urlap, 'data-sdh-alairas-vaszon', 'aláíró vászon');
T::tartalmaz($urlap, 'capture="environment"', 'a fotógomb a kamerát nyitja');
T::tartalmaz($urlap, 'data-sdh-alairas-meglevo', 'a meglévő aláírás látszik');
T::tartalmaz($urlap, 'Teszt Tamás', 'és a neve');
$elado = t_ajax('sdh_muhely_arukiadas_urlap', [], '_wpnonce', 'sdh_muhely_modal', ['id' => 0])['nyers'];
T::nem_tartalmaz($elado, 'ful_m_atvetel', 'eladásnál nincs Átvétel lapfül');

// Nyomtatvány: az átvételi lapon a kép(ek).
$pdf = SDH_Muhely_Nyomtatvany::keszit('atveteli', ['id' => $ml]);
T::igaz(is_array($pdf) && strncmp((string) $pdf['pdf'], '%PDF', 4) === 0, 'átvételi nyomtatvány készül');
$kepek_db = is_array($pdf) ? substr_count((string) $pdf['pdf'], '/Subtype /Image') : 0;
T::igaz($kepek_db >= 2, 'a nyomtatványon az aláírás és a fotó (' . $kepek_db . ' kép)');
$pdf2 = SDH_Muhely_Nyomtatvany::keszit('munkalap', ['id' => $ml]);
T::igaz(is_array($pdf2) && substr_count((string) $pdf2['pdf'], '/Subtype /Image') < $kepek_db, 'a kiadási munkalapra nem kerül az átvételi aláírás és fotó');

// Új aláírás: a régi cserélődik (egy marad).
$kep2 = imagecreatetruecolor(300, 100);
imagefill($kep2, 0, 0, imagecolorallocate($kep2, 255, 255, 255));
ob_start();
imagepng($kep2);
$png2 = (string) ob_get_clean();
$ment(['atvetel_alairas' => 'data:image/png;base64,' . base64_encode($png2), 'atvetel_alairo' => 'Másik Mária']);
T::egyezik(1, count(SDH_Muhely_Csatolmany::lista('munkalap_alairas', $ml)), 'új aláírásnál egy marad');
T::egyezik($png2, (string) file_get_contents(SDH_Muhely_Csatolmany::fajl_ut($A::alairas($ml))), 'az új kép');

// Mentés aláírás nélkül: a meglévő marad.
$ment(['atvetel_alairo' => 'Másik Mária']);
T::igaz($A::alairas($ml) !== null, 'üres aláírás-mezővel a meglévő megmarad');

// Törlés.
$ment(['atvetel_alairas_torol' => '1']);
T::egyezik(null, $A::alairas($ml), 'törölve');
T::igaz(empty($wpdb->get_var($wpdb->prepare('SELECT atvetel_alairva FROM ' . $T('munkalap') . ' WHERE id = %d', $ml))), 'az ideje is');

@unlink($foto); // phpcs:ignore WordPress.PHP.NoSilencedErrors

T::vege('t77 átvételi fotók és aláírás (F3)');
