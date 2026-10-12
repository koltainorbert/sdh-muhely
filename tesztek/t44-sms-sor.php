<?php
/**
 * t44 – SMS küldési sor: sorba tétel, küldés, állapotkövetés, újrapróbálás (növekvő
 * várakozással), 1. → 2. szám, dupla küldés elleni védelem, bizonytalan küldés,
 * csendes órák, leiratkozás, szimuláció, értesítő e-mail, átjáró-állapot, zár.
 *
 * A hamis átjárót (tesztek/sms-mock.php) a teszt maga indítja. Valódi SMS nem megy ki.
 */

require __DIR__ . '/_keret.php';

global $wpdb;

$S    = 'SDH_Muhely_Sms';
$port = 8092;
$fajl = sys_get_temp_dir() . '/sdh-sms-mock-t44.json';
@unlink($fajl);

$folyamat = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/sms-mock.php'],
    [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
    $csovek,
    null,
    ['SDH_SMS_MOCK_FAJL' => $fajl] + getenv()
);

register_shutdown_function(static function () use ($folyamat): void {
    if (is_resource($folyamat)) {
        proc_terminate($folyamat);
    }
});

for ($i = 0; $i < 40; $i++) {
    $ink = @fsockopen('127.0.0.1', $port, $en, $es, 0.2);

    if ($ink) {
        fclose($ink);
        break;
    }

    usleep(100000);
}

$mock = static function (string $ut) use ($port): void {
    wp_remote_post('http://127.0.0.1:' . $port . $ut, ['timeout' => 12]);
};
$lista = static function () use ($port): array {
    $v = wp_remote_get('http://127.0.0.1:' . $port . '/_lista', ['timeout' => 12]);
    $j = json_decode((string) wp_remote_retrieve_body($v), true);

    return is_array($j) ? $j : [];
};
$mock('/_reset');

// Ne induljon küldés a kérés végén (shutdown) – a teszt maga hívja a feldolgozót.
add_filter('sdh_muhely_sms_azonnal', '__return_false');

// Az értesítő e-mailek nem mennek ki, csak összegyűlnek.
$levelek = [];
add_filter('pre_wp_mail', static function ($r, $atts) use (&$levelek) {
    $levelek[] = $atts;

    return true;
}, 10, 2);

$beallit = static function (array $felul = []) use ($S, $port): void {
    update_option($S::OPT, array_merge($S::alapok(), [
        'be'          => true,
        'mod'         => 'helyi',
        'cim'         => '127.0.0.1',
        'port'        => $port,
        'felhasznalo' => 'tesztfelh',
        'jelszo'      => SDH_Muhely_Titok::titkosit('tesztjelszo'),
        'idotullepes' => 5,
        'ujra_db'     => 2,
        'ujra_perc'   => 3,
        'csendes_be'  => false,
        'riasztas_email' => 'riaszt@example.com',
    ], $felul));
};

$sor   = static fn (int $id): object => $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $S::tabla('uzenet') . ' WHERE id = %d', $id));
$esem  = static fn (int $id): int => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $S::tabla('esemeny') . ' WHERE sms_id = %d', $id));
$esedek = static function (int $id) use ($wpdb, $S): void {
    $wpdb->update($S::tabla('uzenet'), ['kovetkezo' => '2000-01-01 00:00:00'], ['id' => $id]);
};
$ugyfel = static fn (string $t1, string $t2 = '', array $tovabbi = []): int => t_ugyfel('SMS Sor', ['telefon' => $t1, 'telefon2' => $t2] + $tovabbi);

// Tiszta lap: a korábbi futások sorai ne zavarjanak (a teszt-adatbázisban szabad törölni).
$wpdb->query('DELETE FROM ' . $S::tabla('uzenet'));
$wpdb->query('DELETE FROM ' . $S::tabla('esemeny'));
delete_option($S::OPT_ALLAPOT);
delete_option('sdh_muhely_sms_zar');

/* --- Főkapcsoló KI: nem kerül semmi a sorba ------------------------------- */

delete_option($S::OPT);
$u0 = $ugyfel('06 30 111 2222');
$v  = $S::sor_be(['ugyfel_id' => $u0, 'szoveg' => 'Teszt']);
T::egyezik(false, $v['ok'], 'főkapcsoló KI: nem megy sorba');
T::tartalmaz($v['uzenet'], 'ki van kapcsolva', 'főkapcsoló KI: érthető üzenet');
T::egyezik(0, (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $S::tabla('uzenet') . ' WHERE ugyfel_id = ' . $u0), 'főkapcsoló KI: nincs naplósor');
T::egyezik(0, $S::feldolgoz()['kuldve'], 'főkapcsoló KI: a feldolgozó nem csinál semmit');

/* --- Ütemező --------------------------------------------------------------- */

T::igaz(isset(wp_get_schedules()['sdh_perc']) && wp_get_schedules()['sdh_perc']['interval'] === 60, 'van percenkénti ütem (sdh_perc)');
wp_clear_scheduled_hook($S::HOOK);
$S::idozit();
T::egyezik(false, (bool) wp_next_scheduled($S::HOOK), 'KI állapotban nincs ütemezés');
$beallit();
$S::idozit();
T::igaz((bool) wp_next_scheduled($S::HOOK), 'BE állapotban ütemezve van a feldolgozó');
update_option($S::OPT, ['be' => false] + get_option($S::OPT));
$S::idozit();
T::egyezik(false, (bool) wp_next_scheduled($S::HOOK), 'kikapcsolás után az ütemezés megszűnik');
$beallit();

/* --- Sima küldés és állapotkövetés ---------------------------------------- */

$u1 = $ugyfel('06 30 111 2222');
$v  = $S::sor_be(['ugyfel_id' => $u1, 'szoveg' => 'Kész a készüléke.', 'kivalto' => 'kezi', 'kulcs' => 'teszt:egyedi:1']);
T::egyezik(true, $v['ok'], 'sorba tétel sikerült');
$id1 = $v['id'];
$s = $sor($id1);
T::egyezik('varakozik', $s->allapot, 'új sor: várakozik');
T::egyezik('+36301112222', $s->szam, 'a szám E.164 alakban');
T::egyezik('ugyfel1', $s->szam_forras, 'a szám az ügyfél 1. mezőjéből jön');
T::egyezik(0, count($lista()), 'sorba tételkor még semmi nem ment ki');

$v2 = $S::sor_be(['ugyfel_id' => $u1, 'szoveg' => 'Kész a készüléke.', 'kivalto' => 'kezi', 'kulcs' => 'teszt:egyedi:1']);
T::egyezik($id1, $v2['id'], 'azonos kulccsal ugyanaz a sor (nincs dupla)');
T::egyezik('duplikalt', $v2['allapot'], 'a második hívás duplikáltként jelzett');

$ki = $S::feldolgoz();
T::egyezik(1, $ki['kuldve'], 'a feldolgozó elküldte az SMS-t');
$s = $sor($id1);
T::egyezik('pending', $s->allapot, 'átadás után: pending');
T::egyezik('m1', $s->atjaro_id, 'az átjáró azonosítója mentve');
T::egyezik(1, (int) $s->proba, 'egy próba');
T::igaz($s->elkuldve !== null, 'elküldve időbélyeg');
$l = $lista();
T::egyezik(1, count($l), 'az átjáró egy üzenetet kapott');
T::egyezik('+36301112222', $l[0]['szam'], 'az átjáró a normalizált számot kapta');
T::egyezik('Kész a készüléke.', $l[0]['text'], 'az átjáró a pontos szöveget kapta');

$e_elotte = $esem($id1);
$S::feldolgoz();
T::egyezik($e_elotte, $esem($id1), 'változatlan állapotnál nem keletkezik új esemény');
T::egyezik(1, count($lista()), 'második feldolgozás nem küld újra');

$mock('/_allapot?id=m1&state=Sent');
$S::feldolgoz();
T::egyezik('sent', $sor($id1)->allapot, 'követés: sent');
$mock('/_allapot?id=m1&state=Delivered');
$S::feldolgoz();
$s = $sor($id1);
T::egyezik('delivered', $s->allapot, 'követés: delivered');
T::igaz($s->kezbesitve !== null, 'kézbesítés időbélyege');
$mock('/_allapot?id=m1&state=Sent');
$S::feldolgoz();
T::egyezik('delivered', $sor($id1)->allapot, 'elkésett „sent” nem írja felül a „delivered”-et');
T::igaz($esem($id1) >= 4, 'a napló minden átmenetet külön soron őriz (létrehozva, pending, sent, delivered)');

/* --- Párhuzamos futás: a zár / foglalás ----------------------------------- */

$v = $S::sor_be(['ugyfel_id' => $u1, 'szoveg' => 'Zár próba.']);
$id = $v['id'];
T::egyezik('atadva', $S::kuld_sor($id), 'első foglalás elküldi');
T::egyezik('foglalt', $S::kuld_sor($id), 'a második foglalás nem küld újra');
T::egyezik(2, count($lista()), 'összesen két üzenet ment ki eddig');

update_option('sdh_muhely_sms_zar', time());
T::egyezik(true, $S::feldolgoz()['zarva'], 'futó feldolgozó mellett a második kilép');
update_option('sdh_muhely_sms_zar', time() - 300);
T::egyezik(false, $S::feldolgoz()['zarva'], 'elavult zár nem akasztja meg a feldolgozót');
T::egyezik(false, get_option('sdh_muhely_sms_zar', false), 'a zár felszabadul');

/* --- Újrapróbálás növekvő várakozással ------------------------------------ */

$mock('/_hiba?n=2&kod=500');
$v  = $S::sor_be(['ugyfel_id' => $u1, 'szoveg' => 'Újrapróba.']);
$id = $v['id'];
$S::feldolgoz();
$s = $sor($id);
T::egyezik('varakozik', $s->allapot, '1. hiba után: újra várakozik');
T::egyezik(1, (int) $s->proba, '1. hiba után 1 próba');
$kul = strtotime((string) $s->kovetkezo) - time();
T::igaz($kul > 150 && $kul < 200, '1. hiba után ~3 perc múlva (mért: ' . $kul . ' mp)');
T::egyezik(0, $S::feldolgoz()['kuldve'], 'amíg nem esedékes, nem próbálkozik');
T::tartalmaz((string) $s->hiba, '500', 'a hiba oka látszik a soron');

$esedek($id);
$S::feldolgoz();
$s = $sor($id);
T::egyezik(2, (int) $s->proba, '2. próba');
$kul = strtotime((string) $s->kovetkezo) - time();
T::igaz($kul > 330 && $kul < 380, '2. hiba után ~6 perc (duplázódik; mért: ' . $kul . ' mp)');

$esedek($id);
$ki = $S::feldolgoz();
$s = $sor($id);
T::egyezik(1, $ki['kuldve'], 'a 3. próba sikeres');
T::egyezik('pending', $s->allapot, '3. próba után átadva');
T::egyezik(3, (int) $s->proba, 'összesen 3 próba (1 + 2 újra)');
T::egyezik(3, count($lista()), 'az átjáró pontosan egyszer kapta meg az üzenetet');

/* --- Kimerült próbák → Manuális teendő + értesítés ------------------------- */

$levelek = [];
$mock('/_hiba?n=9&kod=503');
$v  = $S::sor_be(['ugyfel_id' => $u1, 'szoveg' => 'Végleges hiba.']);
$id = $v['id'];

for ($i = 0; $i < 3; $i++) {
    $S::feldolgoz();
    $esedek($id);
}

$s = $sor($id);
T::egyezik('manualis', $s->allapot, 'a próbák elfogytak: manuális teendő');
T::egyezik(3, (int) $s->proba, '3 próba után áll meg');
T::egyezik(1, count($levelek), 'egy értesítő e-mail ment');
T::egyezik('riaszt@example.com', (string) ($levelek[0]['to'] ?? ''), 'az értesítés a beállított címre megy');
$S::feldolgoz();
T::egyezik(1, count($levelek), 'az értesítés nem ismétlődik');
T::egyezik(3, count($lista()), 'a sikertelen sor nem ment ki az átjáróra');
$mock('/_hiba?n=0');

/* --- 1. szám elutasítva → 2. szám ----------------------------------------- */

$levelek = [];
$u2 = $ugyfel('06 30 123 0000', '06 20 555 6666');
$v  = $S::sor_be(['ugyfel_id' => $u2, 'szoveg' => 'Tartalék szám.']);
$id = $v['id'];
$S::feldolgoz();
$s = $sor($id);
T::egyezik('failed', $s->allapot, 'az 1. szám elutasítva: az eredeti sor „failed”');
T::tartalmaz((string) $s->hiba, '', 'a hiba oka megmarad');
$uj = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $S::tabla('uzenet') . ' WHERE kulcs = %s', 'tovabb:' . $id));
T::igaz(is_object($uj), 'új sor készült a 2. számra');
T::egyezik('+36205556666', (string) $uj->szam, 'a 2. szám normalizálva');
T::egyezik('ugyfel2', (string) $uj->szam_forras, 'forrás: 2. telefonmező');
T::egyezik('varakozik', (string) $uj->allapot, 'az új sor várakozik');
T::egyezik(0, count($levelek), 'amíg van másik szám, nincs riasztás');
$S::feldolgoz();
$uj = $sor((int) $uj->id);
T::egyezik('pending', $uj->allapot, 'a 2. számra elment');
$l = $lista();
T::egyezik('+36205556666', (string) end($l)['szam'], 'az átjáró a 2. számot kapta');

// Mindkét szám rossz → értesítés
$u3 = $ugyfel('06 30 123 0000', '06 20 555 0000');
$v  = $S::sor_be(['ugyfel_id' => $u3, 'szoveg' => 'Mindkettő rossz.']);
$id = $v['id'];
$S::feldolgoz();
$S::feldolgoz();
T::egyezik('failed', $sor($id)->allapot, 'mindkét szám rossz: az 1. sor failed');
$masodik = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $S::tabla('uzenet') . ' WHERE kulcs = %s', 'tovabb:' . $id));
T::egyezik('manualis', (string) $masodik->allapot, 'mindkét szám rossz: manuális teendő a 2. soron');
T::egyezik(1, count($levelek), 'mindkét szám rossz: értesítést kapunk');

/* --- Érvénytelen / hiányzó számok ----------------------------------------- */

$db_elotte = count($lista());
$u4 = $ugyfel('0');
$v  = $S::sor_be(['ugyfel_id' => $u4, 'szoveg' => 'Nincs szám.']);
T::egyezik(false, $v['ok'], '„0” szám: nem küldhető');
T::egyezik('nincs_szam', $v['allapot'], '„0” szám: nincs_szam állapot');
T::tartalmaz($v['uzenet'], 'Nincs használható telefonszám', '„0” szám: érthető üzenet');
T::egyezik('nincs_szam', $sor($v['id'])->allapot, '„0” szám: naplózva');
$S::feldolgoz();
T::egyezik($db_elotte, count($lista()), '„0” szám: semmi nem ment ki');

$u5 = $ugyfel('', '06 20 777 8888');
$v  = $S::sor_be(['ugyfel_id' => $u5, 'szoveg' => 'Csak a 2. mező.']);
T::egyezik(true, $v['ok'], 'üres 1. mező, jó 2.: sorba kerül');
T::egyezik('ugyfel2', $sor($v['id'])->szam_forras, 'üres 1. mező: a 2. számra megy');

$v = $S::sor_be(['ugyfel_id' => $ugyfel('06 30 999 1111'), 'szoveg' => 'Kézi szám.', 'szam' => '06 70 222 3333']);
T::egyezik('+36702223333', $sor($v['id'])->szam, 'kézzel megadott szám felülírja az ügyfélét');
T::egyezik('kezi', $sor($v['id'])->szam_forras, 'kézi szám forrása: kezi');

T::egyezik(false, $S::sor_be(['ugyfel_id' => $u1, 'szoveg' => "  \n "])['ok'], 'üres szöveg nem küldhető');

/* --- Leiratkozás ---------------------------------------------------------- */

$u6 = $ugyfel('06 30 444 5555', '', ['sms_tilt' => 1]);
$v  = $S::sor_be(['ugyfel_id' => $u6, 'szoveg' => 'Automata.', 'kivalto' => 'auto']);
T::egyezik('letiltva', $v['allapot'], 'leiratkozott ügyfélnek automata SMS nem megy');
T::egyezik('letiltva', $sor($v['id'])->allapot, 'a letiltás naplózva');
$v = $S::sor_be(['ugyfel_id' => $u6, 'szoveg' => 'Kézi válasz.', 'kivalto' => 'kezi']);
T::egyezik(true, $v['ok'], 'leiratkozott ügyfélnek a kézi SMS a személyzet döntése');

/* --- Csendes órák --------------------------------------------------------- */

$ts = static fn (string $s): int => (new DateTimeImmutable($s, wp_timezone()))->getTimestamp();

$beallit(['csendes_be' => true, 'csendes_tol' => '21:00', 'csendes_ig' => '08:00']);
T::egyezik($ts('2026-03-11 08:00'), $S::csendes_vege($ts('2026-03-10 22:30')), 'este 22:30 → reggel 8:00 a vége');
T::egyezik($ts('2026-03-10 08:00'), $S::csendes_vege($ts('2026-03-10 07:00')), 'hajnal 7:00 → aznap 8:00');
T::egyezik(0, $S::csendes_vege($ts('2026-03-10 12:00')), 'délben nincs csendes idő');
T::egyezik(0, $S::csendes_vege($ts('2026-03-10 08:00')), 'a záró perc már nem csendes');
T::egyezik($ts('2026-03-11 08:00'), $S::csendes_vege($ts('2026-03-10 21:00')), 'a kezdő perc már csendes');
$beallit(['csendes_be' => true, 'csendes_tol' => '13:00', 'csendes_ig' => '15:00']);
T::egyezik($ts('2026-03-10 15:00'), $S::csendes_vege($ts('2026-03-10 14:00')), 'éjfélt nem átlépő ablak is működik');
$beallit(['csendes_be' => false]);
T::egyezik(0, $S::csendes_vege($ts('2026-03-10 23:00')), 'kikapcsolt csendes órák: mindig 0');

// Most az ablakban vagyunk: az automata SMS reggelre halasztódik, a kézi kimegy.
$ora = (int) wp_date('G');
$beallit(['csendes_be' => true, 'csendes_tol' => sprintf('%02d:00', ($ora + 23) % 24), 'csendes_ig' => sprintf('%02d:00', ($ora + 1) % 24)]);
$beallit(['csendes_be' => false]);
$S::feldolgoz(); // a korábbi tesztekből várakozó kézi sorok kimennek
$beallit(['csendes_be' => true, 'csendes_tol' => sprintf('%02d:00', ($ora + 23) % 24), 'csendes_ig' => sprintf('%02d:00', ($ora + 1) % 24)]);
$db_elotte = count($lista());
$v   = $S::sor_be(['ugyfel_id' => $u1, 'szoveg' => 'Automata éjjel.', 'kivalto' => 'auto']);
$ida = $v['id'];
$sa  = $sor($ida);
T::igaz(strtotime((string) $sa->kovetkezo) > time() + 30, 'csendes időben az automata SMS jövőbeli időpontra kerül');
$S::feldolgoz();
T::egyezik('varakozik', $sor($ida)->allapot, 'csendes időben az automata SMS nem megy ki');
T::egyezik($db_elotte, count($lista()), 'csendes időben semmi nem ment ki az átjárón');
$esedek($ida);
T::egyezik('elhalasztva', $S::kuld_sor($ida), 'esedékesnek látszó, de csendes időben lévő sor visszakerül');
T::egyezik(0, (int) $sor($ida)->proba, 'a halasztás nem fogyaszt próbát');

$v = $S::sor_be(['ugyfel_id' => $u1, 'szoveg' => 'Kézi éjjel.', 'kivalto' => 'kezi']);
$S::feldolgoz();
T::egyezik('pending', $sor($v['id'])->allapot, 'a kézi SMS csendes időben is kimegy');

$beallit(['csendes_be' => false]);

/* --- Szimuláció ----------------------------------------------------------- */

$beallit(['szimulacio' => true]);
$db_elotte = count($lista());
$v = $S::sor_be(['ugyfel_id' => $u1, 'szoveg' => 'Szimulált.']);
$S::feldolgoz();
T::egyezik('szimulalt', $sor($v['id'])->allapot, 'szimulációban a sor „szimulalt”');
T::egyezik($db_elotte, count($lista()), 'szimulációban semmi nem megy az átjáróra');
$beallit();

/* --- Bizonytalan küldés (időtúllépés): nincs automatikus újraküldés --------- */

$levelek = [];
$beallit(['idotullepes' => 2]);
$mock('/_lassu?n=1&mp=4');
$db_elotte = count($lista());
$v  = $S::sor_be(['ugyfel_id' => $u1, 'szoveg' => 'Lassú átjáró.']);
$id = $v['id'];
$S::feldolgoz();
$s = $sor($id);
T::egyezik('bizonytalan', $s->allapot, 'időtúllépés: „bizonytalan”, nem „failed”');
T::tartalmaz((string) $s->hiba, 'lehet, hogy az SMS kiment', 'időtúllépés: a hiba érthető');
T::egyezik(1, count($levelek), 'időtúllépés: értesítés megy');
$S::feldolgoz();
$esedek($id);
$S::feldolgoz();
T::egyezik($db_elotte + 1, count($lista()), 'időtúllépés után nem megy ki még egy példány (nincs dupla SMS)');
T::egyezik('bizonytalan', $sor($id)->allapot, 'a bizonytalan sor emberi döntésre vár');
$beallit();

/* --- Az átjáró „Failed” jelzése: újrapróbálás, majd 2. szám ---------------- */

$v   = $S::sor_be(['ugyfel_id' => $u1, 'szoveg' => 'Telefon hiba.']);
$id  = $v['id'];
$S::feldolgoz();
$azon = $sor($id)->atjaro_id;
$mock('/_allapot?id=' . $azon . '&state=Failed');
$S::feldolgoz();
$s = $sor($id);
T::egyezik('varakozik', $s->allapot, 'átjáró „Failed”: újrapróbálás következik');
T::egyezik(1, (int) $s->proba, 'átjáró „Failed”: az első próba beszámít');
$esedek($id);
$db_elotte = count($lista());
$S::feldolgoz();
T::egyezik($db_elotte + 1, count($lista()), 'átjáró „Failed” után új üzenet megy (az előző véglegesen elbukott, nincs dupla)');
T::igaz($sor($id)->atjaro_id !== $azon, 'új átjáró-azonosító');

$beallit(['ujra_db' => 0]);
$u7 = $ugyfel('06 30 888 1212', '06 20 888 3434');
$v  = $S::sor_be(['ugyfel_id' => $u7, 'szoveg' => 'Failed, nincs újra.']);
$id = $v['id'];
$S::feldolgoz();
$mock('/_allapot?id=' . $sor($id)->atjaro_id . '&state=Failed');
$S::feldolgoz();
T::egyezik('failed', $sor($id)->allapot, 'újrapróbálás nélkül: a sor „failed”');
$uj = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $S::tabla('uzenet') . ' WHERE kulcs = %s', 'tovabb:' . $id));
T::igaz(is_object($uj) && $uj->szam === '+36208883434', 'az átjáró „Failed” jelzése után a 2. számra megy tovább');
$beallit();

/* --- Megszakadt küldés ----------------------------------------------------- */

$S::feldolgoz(); // várakozó sorok kimennek, mielőtt mérünk
$levelek = [];
$v  = $S::sor_be(['ugyfel_id' => $u1, 'szoveg' => 'Elakadt.']);
$id = $v['id'];
$db_elotte = count($lista());
$wpdb->update($S::tabla('uzenet'), ['allapot' => 'kuldes', 'utolso_proba' => wp_date('Y-m-d H:i:s', time() - 600), 'proba' => 1], ['id' => $id]);
$S::feldolgoz();
T::egyezik('bizonytalan', $sor($id)->allapot, 'megszakadt küldés: bizonytalan (nem küldjük újra)');
T::egyezik($db_elotte, count($lista()), 'megszakadt küldés: nem ment ki újra');
T::egyezik(1, count($levelek), 'megszakadt küldés: értesítés');

/* --- Átjáró-állapot (health) ----------------------------------------------- */

delete_option($S::OPT_ALLAPOT);
$S::feldolgoz();
$a = $S::allapot();
T::igaz(!empty($a['atjaro_ok']), 'feldolgozás: az átjáró elérhető, rögzítve');
T::egyezik(0, (int) ($a['hiba_db'] ?? 0), 'nincs hibaszámláló');

$beallit(['port' => 1]);
update_option($S::OPT_ALLAPOT, array_merge($S::allapot(), ['health_ido' => 0]));
$S::feldolgoz();
$a = $S::allapot();
T::egyezik(1, (int) $a['hiba_db'], 'elérhetetlen átjáró: hibaszámláló 1');
T::igaz($a['hiba_kezdet'] !== '', 'a hiba kezdete rögzítve');
$kezdet = $a['hiba_kezdet'];
update_option($S::OPT_ALLAPOT, array_merge($S::allapot(), ['health_ido' => 0]));
$S::feldolgoz();
$a = $S::allapot();
T::egyezik(2, (int) $a['hiba_db'], 'második sikertelen ellenőrzés: 2');
T::egyezik($kezdet, $a['hiba_kezdet'], 'a hiba kezdete nem csúszik');
T::igaz($a['atjaro_hiba'] !== '', 'a hiba oka rögzítve');

$beallit();
update_option($S::OPT_ALLAPOT, array_merge($S::allapot(), ['health_ido' => 0]));
$S::feldolgoz();
$a = $S::allapot();
T::egyezik(0, (int) $a['hiba_db'], 'helyreállás: a hibaszámláló nullázódik');
T::egyezik('', $a['hiba_kezdet'], 'helyreállás: a hiba kezdete törlődik');

// A percenkénti ütem nem kérdez rá minden futáskor.
$elozo = (int) $S::allapot()['health_ido'];
$S::feldolgoz();
T::egyezik($elozo, (int) $S::allapot()['health_ido'], 'az átjáró-ellenőrzés a beállított gyakorisággal fut');

/* --- Napló: semmi nem törlődik ---------------------------------------------- */

$ossz = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $S::tabla('uzenet'));
$esz  = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $S::tabla('esemeny'));
T::igaz($ossz >= 14, 'a napló sorai megmaradtak (' . $ossz . ')');
T::igaz($esz > $ossz, 'átmenetenként külön eseménysor van (' . $esz . ' esemény)');
T::egyezik('Kész a készüléke.', $sor($id1)->szoveg, 'a küldött szöveg változatlan maradt');

T::vege('t44 SMS sor: küldés, újrapróbálás, 2. szám, bizonytalan küldés, csendes órák, health');
