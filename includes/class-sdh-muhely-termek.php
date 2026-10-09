<?php
/**
 * Terméktörzs és készlet.
 *
 * A MunkaLap 3 „Tétel" ablakának webes megfelelője: alkatrészek és eladható
 * termékek kódokkal (gyári szám, cikkszám, termékkód, vonalkód, osztály),
 * kategóriával, beszállítóval, beszerzési és eladási árral, készlettel.
 * Saját modulja van az oldalsávban („Termékek"): lista széltől szélig,
 * felvitel és szerkesztés popupban. A munkalap „Termékek" lapfülén a
 * terméket választó popupból lehet kiválasztani (app.js `termVal*`).
 *
 * ÁRAK: az eladási ár BRUTTÓ egységár (mint a munkalap tételeinél), a nettó
 * az áfakulcsból adódik. A beszerzési ár NETTÓ, saját áfakulccsal. A
 * haszonkulcs nem tárolt adat: (eladási nettó − beszerzési nettó) / beszerzési nettó.
 *
 * KÉSZLET: minden változás egy sor a mozgástáblában (előjeles mennyiség);
 * a termék „keszlet" mezője ezek összege. Kézi mozgás az űrlapról jön
 * (nyitókészlet, bevételezés, korrekció), a munkalapra tett terméktétel
 * pedig magától levonódik – de csak számozott, nem érvénytelen munkalapnál
 * (az árajánlat és a sablon nem fogyaszt készletet). Tételenként legfeljebb
 * egy mozgássor van, ezért a munkalap akárhányszor újramenthető.
 *
 * A terméket, ami már szerepelt munkalapon, nem töröljük: inaktív lesz.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Termek
{
    /** A modul kulcsa az URL-ekben, a menüben és az AJAX-műveletekben. */
    public const KULCS = 'termekek';

    /** Ennyi sort kap legfeljebb a munkalap választó popupja. */
    private const LISTA_HATAR = 5000;

    /** Hány sor egy oldalon a modul listájában. */
    private const OLDAL_MERET = 50;

    /** Hány mozgás látszik a termék űrlapján (görgetősáv nincs). */
    private const URLAP_MOZGAS = 6;

    public static function init(): void
    {
        SDH_Muhely_Modulok::regisztral([
            'kulcs'   => self::KULCS,
            'cim'     => 'Termékek',
            'render'  => [self::class, 'oldal'],
            'sorrend' => 36,
        ]);

        // Teljes oldalas űrlap beküldése (tartalék, JS nélkül is működik).
        add_action('admin_post_sdh_muhely_termek_mentes', [self::class, 'mentes']);
        add_action('admin_post_sdh_muhely_termek_demo', [self::class, 'demo_gomb']);

        // Popup: az űrlap lekérése és beküldése.
        add_action('wp_ajax_sdh_muhely_termekek_urlap', [self::class, 'ajax_urlap']);
        add_action('wp_ajax_sdh_muhely_termekek_ment', [self::class, 'ajax_mentes']);

        // A munkalap választó popupja ebből a listából dolgozik.
        add_action('wp_ajax_sdh_muhely_termekek', [self::class, 'ajax_lista']);
        add_action('wp_ajax_sdh_muhely_termek_torol', [self::class, 'ajax_torol']);

        // Állapotváltáskor a készletmozgás követi a munkalapot (pl. Érvénytelen
        // → a termék visszakerül a készletre; Árajánlat → Nyitott: levonódik).
        add_action('sdh_muhely_munkalap_allapot', [self::class, 'allapot_valtozott'], 20, 1);

        // Sémafrissítés után: fejlesztői/demó telepítésen a mintatermékek.
        add_action('sdh_muhely_sema_frissult', [self::class, 'demo_potlas'], 30);
    }

    public static function tabla(): string
    {
        return SDH_Muhely_Schema::tabla('termek');
    }

    public static function mozgas_tabla(): string
    {
        return SDH_Muhely_Schema::tabla('termek_mozgas');
    }

    /* =================================================================
     * Szótárak
     * ============================================================== */

    /**
     * A készletmozgás fajtái.
     *
     * @return array<string, string> kulcs => felirat
     */
    public static function mozgas_tipusok(): array
    {
        return [
            'nyito'     => 'Nyitókészlet',
            'bevet'     => 'Bevételezés',
            'korrekcio' => 'Korrekció',
            'munkalap'  => 'Munkalap',
        ];
    }

    /**
     * A termékkategóriák: ami a törzsben már szerepel, kiegészítve néhány
     * javasolttal. Szűrővel bővíthető (a rendszer más szerviznek is eladható).
     *
     * @return array<int, string>
     */
    public static function kategoriak(): array
    {
        global $wpdb;

        $sajat = $wpdb->get_col('SELECT DISTINCT kategoria FROM ' . self::tabla() . " WHERE kategoria <> '' ORDER BY kategoria ASC");

        return is_array($sajat) ? array_values(array_map('strval', $sajat)) : [];
    }

    /**
     * @return array<int, string>
     */
    private static function javasolt_kategoriak(): array
    {
        $lista = [
            'Kijelző', 'Akkumulátor', 'Töltőcsatlakozó', 'Hátlap', 'Kamera', 'Hangszóró',
            'Tartozék', 'Műhelyanyag', 'Telefon', 'Tablet', 'PC-IT termék',
        ];

        $szurt = apply_filters('sdh_muhely_termek_kategoriak', $lista);

        return is_array($szurt) ? array_values(array_map('strval', $szurt)) : $lista;
    }

    /**
     * A beszállítók, akik a törzsben már szerepelnek.
     *
     * @return array<int, string>
     */
    public static function beszallitok(): array
    {
        global $wpdb;

        $sajat = $wpdb->get_col('SELECT DISTINCT beszallito FROM ' . self::tabla() . " WHERE beszallito <> '' ORDER BY beszallito ASC");

        return is_array($sajat) ? array_values(array_map('strval', $sajat)) : [];
    }

    /* =================================================================
     * Olvasás
     * ============================================================== */

    public static function egy(int $id): ?object
    {
        global $wpdb;

        if ($id <= 0) {
            return null;
        }

        $sor = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::tabla() . ' WHERE id = %d', $id));

        return is_object($sor) ? $sor : null;
    }

    public static function darab(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::tabla());
    }

    /** Eladási nettó egységár a bruttóból. */
    public static function netto_ar(object $t): float
    {
        return SDH_Muhely_Tetel::bruttobol_netto(
            (float) $t->brutto_ar,
            SDH_Muhely_Tetel::afa_szazalek(SDH_Muhely_Tetel::afakulcs_ervenyes((string) $t->afa_kulcs))
        );
    }

    /** Beszerzési bruttó egységár a nettóból. */
    public static function besz_brutto(object $t): float
    {
        return SDH_Muhely_Tetel::nettobol_brutto(
            (float) $t->besz_netto,
            SDH_Muhely_Tetel::afa_szazalek(SDH_Muhely_Tetel::afakulcs_ervenyes((string) $t->besz_afa_kulcs))
        );
    }

    /** Haszonkulcs százalékban, vagy null, ha nincs beszerzési ár. */
    public static function haszonkulcs(object $t): ?float
    {
        $besz = (float) $t->besz_netto;

        if ($besz <= 0) {
            return null;
        }

        return round((self::netto_ar($t) - $besz) / $besz * 100, 1);
    }

    /**
     * Hány munkalap-tétel hivatkozik a termékre.
     */
    public static function hasznalat(int $id): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare('SELECT COUNT(*) FROM ' . SDH_Muhely_Tetel::tabla() . ' WHERE termek_id = %d', $id)
        );
    }

    /* =================================================================
     * Készlet
     * ============================================================== */

    /**
     * A termék készlete a mozgások összegéből. Minden mozgásváltozás után
     * ezt kell hívni, hogy a tárolt készlet és a mozgások ne térjenek el.
     */
    public static function keszlet_ujraszamol(int $termek_id): float
    {
        global $wpdb;

        if ($termek_id <= 0) {
            return 0.0;
        }

        $keszlet = round((float) $wpdb->get_var(
            $wpdb->prepare('SELECT COALESCE(SUM(mennyiseg), 0) FROM ' . self::mozgas_tabla() . ' WHERE termek_id = %d', $termek_id)
        ), 3);

        $wpdb->update(self::tabla(), ['keszlet' => $keszlet], ['id' => $termek_id]);

        return $keszlet;
    }

    /**
     * Kézi készletmozgás (nyitókészlet, bevételezés, korrekció).
     *
     * @return bool Létrejött-e a mozgás.
     */
    public static function mozgas(int $termek_id, float $mennyiseg, string $tipus, string $megjegyzes = ''): bool
    {
        global $wpdb;

        $mennyiseg = round($mennyiseg, 3);

        if ($termek_id <= 0 || abs($mennyiseg) < 0.0005) {
            return false;
        }

        $siker = $wpdb->insert(self::mozgas_tabla(), [
            'termek_id'   => $termek_id,
            'tipus'       => isset(self::mozgas_tipusok()[$tipus]) && $tipus !== 'munkalap' ? $tipus : 'korrekcio',
            'mennyiseg'   => $mennyiseg,
            'megjegyzes'  => mb_substr($megjegyzes, 0, 255),
            'felhasznalo' => get_current_user_id(),
            'letrehozva'  => current_time('mysql'),
        ]);

        self::keszlet_ujraszamol($termek_id);

        return $siker !== false;
    }

    /** Fogyaszt-e készletet a munkalap ebben az állapotban. */
    private static function munkalap_fogyaszt(string $allapot): bool
    {
        $allapotok = SDH_Muhely_Munkalap::allapotok();
        $fogyaszt  = !empty($allapotok[$allapot]['szamozott']) && $allapot !== 'ervenytelen';

        return (bool) apply_filters('sdh_muhely_keszlet_allapot', $fogyaszt, $allapot);
    }

    /** A munkalap állapota megváltozott: a készletmozgások követik. */
    public static function allapot_valtozott($munkalap_id): void
    {
        self::munkalap_mozgasok((int) $munkalap_id);
    }

    /**
     * Egy munkalap terméktételeinek készletmozgása: tételenként egy sor,
     * a tétel mennyiségével csökkentve a készletet. Idempotens – a mostani
     * tételekhez igazítja a mozgásokat (ami új, létrejön; ami változott,
     * frissül; ami megszűnt, törlődik), majd újraszámolja az érintett
     * termékek készletét.
     */
    public static function munkalap_mozgasok(int $munkalap_id): void
    {
        global $wpdb;

        if ($munkalap_id <= 0) {
            return;
        }

        $mozgas_tabla = self::mozgas_tabla();
        $munkalap     = $wpdb->get_row(
            $wpdb->prepare('SELECT id, allapot, munkalap_szam FROM ' . SDH_Muhely_Schema::tabla('munkalap') . ' WHERE id = %d', $munkalap_id)
        );

        // A kívánt állapot: tetel_id => [termek_id, mennyiség].
        $kell = [];

        if (is_object($munkalap) && self::munkalap_fogyaszt((string) $munkalap->allapot)) {
            $tetelek = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT t.id, t.termek_id, t.mennyiseg FROM ' . SDH_Muhely_Tetel::tabla() . ' t
                     INNER JOIN ' . self::tabla() . " p ON p.id = t.termek_id
                     WHERE t.munkalap_id = %d AND t.tipus = 'termek' AND t.mozgas = 'kimeno'
                       AND t.termek_id > 0 AND p.keszletkezeles = 1",
                    $munkalap_id
                )
            );

            foreach ((array) $tetelek as $t) {
                $kell[(int) $t->id] = [(int) $t->termek_id, round((float) $t->mennyiseg, 3)];
            }
        }

        $megjegyzes = is_object($munkalap) && (int) $munkalap->munkalap_szam > 0
            ? SDH_Muhely_Munkalap::szam_formaz($munkalap->munkalap_szam)
            : '';

        $erintett = [];
        $meglevok = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, termek_id, tetel_id, mennyiseg FROM {$mozgas_tabla} WHERE munkalap_id = %d AND tipus = 'munkalap'",
                $munkalap_id
            )
        );

        foreach ((array) $meglevok as $m) {
            $tetel_id = (int) $m->tetel_id;
            $erintett[(int) $m->termek_id] = true;

            if (!isset($kell[$tetel_id])) {
                $wpdb->delete($mozgas_tabla, ['id' => (int) $m->id]);
                continue;
            }

            [$termek_id, $menny] = $kell[$tetel_id];
            unset($kell[$tetel_id]);

            if ((int) $m->termek_id !== $termek_id || abs((float) $m->mennyiseg + $menny) >= 0.0005) {
                $wpdb->update($mozgas_tabla, [
                    'termek_id'  => $termek_id,
                    'mennyiseg'  => -$menny,
                    'megjegyzes' => $megjegyzes,
                ], ['id' => (int) $m->id]);

                $erintett[$termek_id] = true;
            }
        }

        foreach ($kell as $tetel_id => [$termek_id, $menny]) {
            $wpdb->insert($mozgas_tabla, [
                'termek_id'   => $termek_id,
                'tipus'       => 'munkalap',
                'mennyiseg'   => -$menny,
                'munkalap_id' => $munkalap_id,
                'tetel_id'    => $tetel_id,
                'megjegyzes'  => $megjegyzes,
                'felhasznalo' => get_current_user_id(),
                'letrehozva'  => current_time('mysql'),
            ]);

            $erintett[$termek_id] = true;
        }

        foreach (array_keys($erintett) as $termek_id) {
            self::keszlet_ujraszamol((int) $termek_id);
        }
    }

    /* =================================================================
     * Írás
     * ============================================================== */

    /**
     * A beküldött űrlapmezők megtisztítva.
     *
     * @return array<string, mixed>
     */
    private static function adatok_osszeallit(): array
    {
        $szoveg = static fn (string $mezo, int $hossz = 255): string => isset($_POST[$mezo]) && is_scalar($_POST[$mezo])
            ? mb_substr(sanitize_text_field(wp_unslash((string) $_POST[$mezo])), 0, $hossz)
            : '';
        $hosszu = static fn (string $mezo): string => isset($_POST[$mezo]) && is_scalar($_POST[$mezo])
            ? sanitize_textarea_field(wp_unslash((string) $_POST[$mezo]))
            : '';
        $szam   = static fn (string $mezo): float => SDH_Muhely_Tetel::szam($szoveg($mezo, 40));

        $besz_afa = SDH_Muhely_Tetel::afakulcs_ervenyes($szoveg('besz_afa_kulcs', 12));
        $besz     = max(0.0, round($szam('besz_netto'), 2));

        // Ha csak a beszerzési bruttót írták be (JS nélkül), abból lesz a nettó.
        if ($besz <= 0 && $szam('besz_brutto') > 0) {
            $besz = SDH_Muhely_Tetel::bruttobol_netto($szam('besz_brutto'), SDH_Muhely_Tetel::afa_szazalek($besz_afa));
        }

        $afa    = SDH_Muhely_Tetel::afakulcs_ervenyes($szoveg('afa_kulcs', 12));
        $brutto = max(0.0, round($szam('brutto_ar'), 2));

        if ($brutto <= 0 && $szam('netto_ar') > 0) {
            $brutto = SDH_Muhely_Tetel::nettobol_brutto($szam('netto_ar'), SDH_Muhely_Tetel::afa_szazalek($afa));
        }

        $konyvelve = $szoveg('konyvelve', 10);

        return [
            'megnevezes'     => trim((string) preg_replace('/[\s\x{00a0}\x{202f}]+/u', ' ', $szoveg('megnevezes'))),
            'leiras'         => $hosszu('leiras'),
            'kategoria'      => $szoveg('kategoria', 120),
            'beszallito'     => $szoveg('beszallito', 190),
            'gyari_szam'     => $szoveg('gyari_szam', 60),
            'cikkszam'       => $szoveg('cikkszam', 60),
            'termekkod'      => $szoveg('termekkod', 60),
            'vonalkod'       => $szoveg('vonalkod', 60),
            'osztaly'        => $szoveg('osztaly', 60),
            'keszletkezeles' => !empty($_POST['keszletkezeles']) ? 1 : 0,
            'min_keszlet'    => max(0.0, round($szam('min_keszlet'), 3)),
            'me'             => $szoveg('me', 20) !== '' ? $szoveg('me', 20) : 'db',
            'besz_netto'     => $besz,
            'besz_afa_kulcs' => $besz_afa,
            'brutto_ar'      => $brutto,
            'afa_kulcs'      => $afa,
            'kedvezmeny'     => min(100.0, max(0.0, round($szam('kedvezmeny'), 2))),
            'konyvelve'      => preg_match('/^\d{4}-\d{2}-\d{2}$/', $konyvelve) === 1 ? $konyvelve : null,
            'megjegyzes'     => $hosszu('megjegyzes'),
            'aktiv'          => isset($_POST['aktiv_jelen']) ? (!empty($_POST['aktiv']) ? 1 : 0) : 1,
        ];
    }

    /**
     * Termék mentése a törzsbe.
     *
     * @param array<string, mixed> $adat Az adatok_osszeallit() eredménye (vagy azzal egyező kulcsok).
     * @return int Az azonosító, vagy 0 adatbázishibánál.
     */
    public static function torzsbe(int $id, array $adat): int
    {
        global $wpdb;

        $most = current_time('mysql');

        $adat['modositva'] = $most;

        if ($id > 0) {
            return $wpdb->update(self::tabla(), $adat, ['id' => $id]) === false ? 0 : $id;
        }

        $adat['keszlet']    = 0;
        $adat['letrehozva'] = $most;
        $adat['letrehozo']  = get_current_user_id();
        $adat['forras']     = $adat['forras'] ?? 'kezi';

        return $wpdb->insert(self::tabla(), $adat) === false ? 0 : (int) $wpdb->insert_id;
    }

    /**
     * Az űrlapon átírt készlet mozgássá alakítása. A különbséget ahhoz az
     * értékhez mérjük, amit az űrlap megnyitáskor mutatott – így egy közben
     * mentett munkalap fogyása nem íródik vissza.
     */
    private static function keszlet_urlaprol(int $id, bool $uj): void
    {
        if (empty($_POST['keszletkezeles']) || !isset($_POST['keszlet'])) {
            return;
        }

        $cel     = round(SDH_Muhely_Tetel::szam(sanitize_text_field(wp_unslash((string) $_POST['keszlet']))), 3);
        $eredeti = $uj || !isset($_POST['keszlet_eredeti'])
            ? 0.0
            : round((float) wp_unslash((string) $_POST['keszlet_eredeti']), 3);
        $kulonbseg = round($cel - $eredeti, 3);

        if (abs($kulonbseg) < 0.0005) {
            return;
        }

        $megjegyzes = isset($_POST['keszlet_megjegyzes'])
            ? sanitize_text_field(wp_unslash((string) $_POST['keszlet_megjegyzes']))
            : '';

        self::mozgas($id, $kulonbseg, $uj ? 'nyito' : ($kulonbseg > 0 ? 'bevet' : 'korrekcio'), $megjegyzes);
    }

    /**
     * Mentés az űrlapról (a teljes oldalas és a popupos beküldés közös része).
     *
     * @return array{0: int, 1: string}|string [azonosító, üzenetkulcs], vagy hibaüzenet.
     */
    private static function urlap_mentes()
    {
        $id     = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $adatok = self::adatok_osszeallit();

        if ($adatok['megnevezes'] === '') {
            return 'A megnevezés kitöltése kötelező.';
        }

        if ($id > 0 && self::egy($id) === null) {
            return 'Nincs ilyen termék. Lehet, hogy időközben törölték.';
        }

        $uj      = $id <= 0;
        $mentett = self::torzsbe($id, $adatok);

        if ($mentett <= 0) {
            return 'Az adatbázis visszautasította a mentést.';
        }

        self::keszlet_urlaprol($mentett, $uj);

        return [$mentett, $uj ? 'termek_letrehozva' : 'termek_mentve'];
    }

    /** Teljes oldalas beküldés (JS nélküli tartalék). */
    public static function mentes(): void
    {
        SDH_Muhely_Admin_UI::jog_ellenoriz();
        check_admin_referer('sdh_muhely_termek_mentes', 'sdh_nonce');

        $eredmeny = self::urlap_mentes();

        wp_safe_redirect(self::vissza(['uzenet' => is_array($eredmeny) ? $eredmeny[1] : 'termek_hiba']));
        exit;
    }

    /** Popupos beküldés. */
    public static function ajax_mentes(): void
    {
        check_ajax_referer('sdh_muhely_termek_mentes', 'sdh_nonce');

        if (!current_user_can(SDH_Muhely_Admin_UI::jog())) {
            wp_send_json_error(['uzenet' => 'Nincs jogosultságod ehhez.'], 403);
        }

        $eredmeny = self::urlap_mentes();

        if (!is_array($eredmeny)) {
            wp_send_json_error(['uzenet' => $eredmeny]);
        }

        $t = self::egy($eredmeny[0]);

        // A mezőket a munkalap választó popupja használja: a frissen felvitt
        // terméket oldalfrissítés nélkül teszi a tételsorba.
        wp_send_json_success(array_merge(self::valaszto_sor($t, true), [
            'vissza' => self::vissza(['uzenet' => $eredmeny[1]]),
        ]));
    }

    /**
     * Törlés: ha a termék már szerepelt munkalapon, csak inaktív lesz.
     *
     * @return string 'torolve', 'inaktiv' vagy '' (nincs ilyen).
     */
    public static function torol(int $id): string
    {
        global $wpdb;

        if (self::egy($id) === null) {
            return '';
        }

        if (self::hasznalat($id) > 0) {
            $wpdb->update(self::tabla(), ['aktiv' => 0, 'modositva' => current_time('mysql')], ['id' => $id]);

            return 'inaktiv';
        }

        $wpdb->delete(self::mozgas_tabla(), ['termek_id' => $id]);
        $wpdb->delete(self::tabla(), ['id' => $id]);

        return 'torolve';
    }

    /* =================================================================
     * URL-ek, kontextus
     * ============================================================== */

    private static function bekuldes_kontextusa(): string
    {
        $kontextus = isset($_REQUEST['kontextus']) ? sanitize_key(wp_unslash($_REQUEST['kontextus'])) : 'admin';

        return $kontextus === 'frontend' ? 'frontend' : 'admin';
    }

    private static function kontextus_ertek(): string
    {
        return wp_doing_ajax() ? self::bekuldes_kontextusa() : SDH_Muhely_Modulok::kontextus();
    }

    /**
     * @param array<string, mixed> $parameterek
     */
    private static function vissza(array $parameterek): string
    {
        return SDH_Muhely_Modulok::visszateres(self::KULCS, $parameterek, self::bekuldes_kontextusa());
    }

    /**
     * @param array<string, mixed> $parameterek
     */
    private static function url(array $parameterek = []): string
    {
        return SDH_Muhely_Modulok::url(self::KULCS, $parameterek);
    }

    /* =================================================================
     * Kiírás
     * ============================================================== */

    private static function penz(float $osszeg): string
    {
        return number_format($osszeg, 0, ',', "\u{00a0}");
    }

    /** Mennyiség kiírva: fölösleges tizedesek nélkül, tizedesvesszővel. */
    public static function menny(float $ertek): string
    {
        $szoveg = rtrim(rtrim(number_format($ertek, 3, ',', "\u{00a0}"), '0'), ',');

        return $szoveg === '' || $szoveg === '-' ? '0' : $szoveg;
    }

    /** Szám az űrlapmezőbe: fölösleges tizedesek nélkül, tizedesvesszővel. */
    private static function mezobe(float $ertek, int $tizedes = 2): string
    {
        $szoveg = rtrim(rtrim(number_format($ertek, $tizedes, ',', ''), '0'), ',');

        return $szoveg === '' ? '0' : $szoveg;
    }

    private static function afa_felirat(string $kulcs): string
    {
        $kulcs = SDH_Muhely_Tetel::afakulcs_ervenyes($kulcs);

        return is_numeric($kulcs) ? $kulcs . '%' : $kulcs;
    }

    /** A készlet jelzése: 'nincs' (0 vagy kevesebb), 'keves' (minimum alatt), 'ok', vagy '' (nem kezelt). */
    public static function keszlet_jelzes(object $t): string
    {
        if ((int) $t->keszletkezeles !== 1) {
            return '';
        }

        $keszlet = (float) $t->keszlet;

        if ($keszlet <= 0) {
            return 'nincs';
        }

        return (float) $t->min_keszlet > 0 && $keszlet < (float) $t->min_keszlet ? 'keves' : 'ok';
    }

    private static function keszlet_html(object $t): string
    {
        $jelzes = self::keszlet_jelzes($t);

        if ($jelzes === '') {
            return '<span class="sdh-tabla__halvany" title="Nincs készletkezelés">—</span>';
        }

        $cimek = [
            'nincs' => 'Nincs készleten',
            'keves' => 'A minimális készlet (' . self::menny((float) $t->min_keszlet) . ') alatt',
            'ok'    => 'Készleten',
        ];

        return sprintf(
            '<span class="sdh-keszlet sdh-keszlet--%s" title="%s">%s</span>',
            esc_attr($jelzes),
            esc_attr($cimek[$jelzes]),
            esc_html(self::menny((float) $t->keszlet))
        );
    }

    /* =================================================================
     * A modul oldala
     * ============================================================== */

    public static function oldal(): void
    {
        SDH_Muhely_Admin_UI::jog_ellenoriz();

        $nezet = isset($_GET['nezet']) ? sanitize_key(wp_unslash($_GET['nezet'])) : 'lista';

        if ($nezet === 'uj' || $nezet === 'szerkeszt') {
            self::urlap_oldal($nezet);

            return;
        }

        if ($nezet === 'mozgasok') {
            self::mozgasok_oldal();

            return;
        }

        self::lista_oldal();
    }

    /**
     * A lista rendezhető oszlopai: kulcs => SQL-kifejezés.
     *
     * @return array<string, string>
     */
    private static function rendezesek(): array
    {
        return [
            'id'         => 'id',
            'kategoria'  => 'kategoria',
            'megnevezes' => 'megnevezes',
            'termekkod'  => 'termekkod',
            'cikkszam'   => 'cikkszam',
            'gyari_szam' => 'gyari_szam',
            'vonalkod'   => 'vonalkod',
            'keszlet'    => 'keszlet',
            'besz_netto' => 'besz_netto',
            'brutto_ar'  => 'brutto_ar',
            'ertek'      => '(keszlet * brutto_ar)',
        ];
    }

    /** A lista felső fülei (a MunkaLap 3 „Készlet / Termék készlet / Összes tételmozgás" mintájára). */
    private static function nezetfulek(string $aktiv): void
    {
        global $wpdb;

        $tabla = self::tabla();
        $db    = $wpdb->get_row(
            "SELECT COUNT(*) AS mind,
                    SUM(CASE WHEN aktiv = 1 AND keszletkezeles = 1 AND keszlet > 0 THEN 1 ELSE 0 END) AS keszleten,
                    SUM(CASE WHEN aktiv = 1 AND keszletkezeles = 1 AND (keszlet <= 0 OR (min_keszlet > 0 AND keszlet < min_keszlet)) THEN 1 ELSE 0 END) AS hiany,
                    SUM(CASE WHEN aktiv = 0 THEN 1 ELSE 0 END) AS inaktiv
             FROM {$tabla}"
        );

        $fulek = [
            ''          => ['Összes termék', (int) ($db->mind ?? 0) - (int) ($db->inaktiv ?? 0), self::url()],
            'keszleten' => ['Készleten', (int) ($db->keszleten ?? 0), self::url(['szuro' => 'keszleten'])],
            'hiany'     => ['Rendelni kell', (int) ($db->hiany ?? 0), self::url(['szuro' => 'hiany'])],
            'inaktiv'   => ['Inaktív', (int) ($db->inaktiv ?? 0), self::url(['szuro' => 'inaktiv'])],
            'mozgasok'  => ['Összes tételmozgás', null, self::url(['nezet' => 'mozgasok'])],
        ];

        echo '<nav class="sdh-nezetfulek" aria-label="Nézetek">';

        foreach ($fulek as $kulcs => [$cim, $szam, $cel]) {
            if ($kulcs === 'inaktiv' && $szam === 0 && $aktiv !== 'inaktiv') {
                continue;
            }

            printf(
                '<a href="%s" class="sdh-nezetfulek__ful%s"%s>%s%s</a>',
                esc_url($cel),
                $kulcs === $aktiv ? ' is-aktiv' : '',
                $kulcs === $aktiv ? ' aria-current="page"' : '',
                esc_html($cim),
                $szam !== null
                    ? ' <span class="sdh-nezetfulek__db' . ($kulcs === 'hiany' && $szam > 0 ? ' is-figyelem' : '') . '">' . (int) $szam . '</span>'
                    : ''
            );
        }

        echo '</nav>';
    }

    private static function lista_oldal(): void
    {
        global $wpdb;

        $kereses  = isset($_GET['k']) ? sanitize_text_field(wp_unslash($_GET['k'])) : '';
        $kat      = isset($_GET['kat']) ? sanitize_text_field(wp_unslash($_GET['kat'])) : '';
        $szuro    = isset($_GET['szuro']) ? sanitize_key(wp_unslash($_GET['szuro'])) : '';
        $szuro    = in_array($szuro, ['keszleten', 'hiany', 'inaktiv'], true) ? $szuro : '';
        $rendezes = self::rendezesek();
        $rend     = isset($_GET['rend']) ? sanitize_key(wp_unslash($_GET['rend'])) : 'id';
        $rend     = isset($rendezes[$rend]) ? $rend : 'id';
        $irany    = isset($_GET['irany']) ? strtolower(sanitize_key(wp_unslash($_GET['irany']))) : ($rend === 'id' ? 'le' : 'fel');
        $irany    = $irany === 'le' ? 'le' : 'fel';
        $oldal    = isset($_GET['oldalszam']) ? max(1, (int) $_GET['oldalszam']) : 1;
        $eltolas  = ($oldal - 1) * self::OLDAL_MERET;
        $tabla    = self::tabla();

        $feltetelek = [$szuro === 'inaktiv' ? 'aktiv = 0' : 'aktiv = 1'];
        $ertekek    = [];

        if ($szuro === 'keszleten') {
            $feltetelek[] = 'keszletkezeles = 1 AND keszlet > 0';
        } elseif ($szuro === 'hiany') {
            $feltetelek[] = 'keszletkezeles = 1 AND (keszlet <= 0 OR (min_keszlet > 0 AND keszlet < min_keszlet))';
        }

        if ($kat !== '') {
            $feltetelek[] = 'kategoria = %s';
            $ertekek[]    = $kat;
        }

        if ($kereses !== '') {
            $minta        = '%' . $wpdb->esc_like($kereses) . '%';
            $feltetelek[] = '(megnevezes LIKE %s OR cikkszam LIKE %s OR termekkod LIKE %s OR gyari_szam LIKE %s'
                . ' OR vonalkod LIKE %s OR kategoria LIKE %s OR beszallito LIKE %s OR osztaly LIKE %s)';
            $ertekek      = array_merge($ertekek, array_fill(0, 8, $minta));
        }

        $hol     = 'WHERE ' . implode(' AND ', $feltetelek);
        $sorrend = $rendezes[$rend] . ($irany === 'le' ? ' DESC' : ' ASC') . ($rend === 'id' ? '' : ', id DESC');

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- a $hol csak helyőrzőket, a $sorrend csak fehérlistás oszlopot tartalmaz.
        $ossz_sql = "SELECT COUNT(*) AS db,
                            COALESCE(SUM(CASE WHEN keszletkezeles = 1 THEN keszlet ELSE 0 END), 0) AS keszlet,
                            COALESCE(SUM(CASE WHEN keszletkezeles = 1 THEN keszlet * besz_netto ELSE 0 END), 0) AS besz_ertek,
                            COALESCE(SUM(CASE WHEN keszletkezeles = 1 THEN keszlet * brutto_ar ELSE 0 END), 0) AS brutto_ertek
                     FROM {$tabla} {$hol}";
        $ossz     = $ertekek === [] ? $wpdb->get_row($ossz_sql) : $wpdb->get_row($wpdb->prepare($ossz_sql, $ertekek));
        $osszesen = (int) ($ossz->db ?? 0);

        $sorok = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$tabla} {$hol} ORDER BY {$sorrend} LIMIT %d OFFSET %d",
                array_merge($ertekek, [self::OLDAL_MERET, $eltolas])
            )
        );
        // phpcs:enable

        $sorok   = is_array($sorok) ? $sorok : [];
        $oldalak = max(1, (int) ceil($osszesen / self::OLDAL_MERET));
        $alap    = array_filter([
            'k'     => $kereses !== '' ? $kereses : null,
            'kat'   => $kat !== '' ? $kat : null,
            'szuro' => $szuro !== '' ? $szuro : null,
        ]);
        $rendezve = array_filter([
            'rend'  => $rend !== 'id' || $irany !== 'le' ? $rend : null,
            'irany' => $rend !== 'id' || $irany !== 'le' ? $irany : null,
        ]);
        $ures_torzs = self::darab() === 0;

        // Rendezhető oszlopfej: kattintásra rendez, újabb kattintásra megfordul.
        $fej = static function (string $kulcs, string $cim, string $osztaly = '', string $sugo = '') use ($rend, $irany, $alap): void {
            $aktiv  = $rend === $kulcs;
            $uj     = $aktiv && $irany === 'fel' ? 'le' : 'fel';
            $cel    = self::url(array_merge($alap, ['rend' => $kulcs, 'irany' => $uj]));

            printf(
                '<th class="%s"%s%s><a class="sdh-tabla__rendez%s" href="%s">%s%s</a></th>',
                esc_attr($osztaly),
                $sugo !== '' ? ' title="' . esc_attr($sugo) . '"' : '',
                $aktiv ? ' aria-sort="' . ($irany === 'fel' ? 'ascending' : 'descending') . '"' : '',
                $aktiv ? ' is-aktiv' : '',
                esc_url($cel),
                esc_html($cim),
                $aktiv ? '<span aria-hidden="true">' . ($irany === 'fel' ? ' ▲' : ' ▼') . '</span>' : ''
            );
        };

        ?>
        <div class="sdh-wrap">
            <?php
            SDH_Muhely_Admin_UI::uzenet();
            SDH_Muhely_Admin_UI::fejlec(
                'Termékek',
                'Alkatrészek és eladható termékek készlettel. A munkalapra tett termék magától lejön a készletről.',
                [
                    [
                        'cimke'      => '+ Új termék',
                        'url'        => self::url(['nezet' => 'uj']),
                        'elsodleges' => true,
                        'adatok'     => ['sdh-urlap' => self::KULCS, 'sdh-id' => '0'],
                    ],
                ]
            );

            self::nezetfulek($szuro);
            ?>

            <form method="get" class="sdh-kereso"
                  action="<?php echo esc_url(SDH_Muhely_Modulok::urlap_cel(self::KULCS)); ?>">
                <?php SDH_Muhely_Modulok::urlap_rejtett(self::KULCS); ?>

                <?php foreach (array_merge(['szuro' => $szuro !== '' ? $szuro : null], $rendezve) as $nev => $ertek) : ?>
                    <?php if ($ertek !== null) : ?>
                        <input type="hidden" name="<?php echo esc_attr($nev); ?>" value="<?php echo esc_attr((string) $ertek); ?>">
                    <?php endif; ?>
                <?php endforeach; ?>

                <input type="search" name="k" value="<?php echo esc_attr($kereses); ?>"
                       placeholder="Megnevezés, cikkszám, termékkód, gyári szám, vonalkód…">

                <select name="kat" aria-label="Kategória" data-sdh-auto-kuld>
                    <option value="">Minden kategória</option>
                    <?php foreach (self::kategoriak() as $k) : ?>
                        <option value="<?php echo esc_attr($k); ?>" <?php selected($kat, $k); ?>><?php echo esc_html($k); ?></option>
                    <?php endforeach; ?>
                </select>

                <button type="submit" class="sdh-gomb sdh-gomb--vilagos">Keresés</button>

                <?php if ($kereses !== '' || $kat !== '') : ?>
                    <a class="sdh-gomb sdh-gomb--vilagos"
                       href="<?php echo esc_url(self::url(array_filter(['szuro' => $szuro !== '' ? $szuro : null]))); ?>">Szűrő törlése</a>
                <?php endif; ?>

                <span class="sdh-kereso__talalat">
                    <?php echo esc_html(number_format_i18n($osszesen)); ?> termék
                </span>
            </form>

            <table class="sdh-tabla sdh-tabla--termek">
                <thead>
                    <tr>
                        <?php
                        $fej('id', 'Sorsz.', 'sdh-tabla__szam sdh-tabla__mobil-nem');
                        $fej('kategoria', 'Kategória', 'sdh-tabla__mobil-nem');
                        $fej('megnevezes', 'Megnevezés');
                        $fej('termekkod', 'Termékkód', 'sdh-tabla__rejtheto sdh-tabla__ritka2');
                        $fej('cikkszam', 'Cikkszám', 'sdh-tabla__mobil-nem');
                        $fej('gyari_szam', 'Gyári szám', 'sdh-tabla__rejtheto sdh-tabla__ritka');
                        $fej('vonalkod', 'Vonalkód', 'sdh-tabla__rejtheto sdh-tabla__ritka');
                        $fej('keszlet', 'Készlet', 'sdh-tabla__szam');
                        ?>
                        <th class="sdh-tabla__mobil-nem">M.e.</th>
                        <?php $fej('besz_netto', 'Besz. n. ár', 'sdh-tabla__szam sdh-tabla__rejtheto', 'Beszerzési nettó egységár'); ?>
                        <th class="sdh-tabla__szam sdh-tabla__rejtheto sdh-tabla__ritka" title="Beszerzési bruttó egységár">Besz. b. ár</th>
                        <th class="sdh-tabla__mobil-nem">Áfak.</th>
                        <th class="sdh-tabla__szam sdh-tabla__rejtheto sdh-tabla__ritka" title="Eladási nettó egységár">Nettó ár</th>
                        <?php
                        $fej('brutto_ar', 'Bruttó ár', 'sdh-tabla__szam', 'Eladási bruttó egységár');
                        $fej('ertek', 'Bruttó é.', 'sdh-tabla__szam sdh-tabla__rejtheto', 'A készlet értéke eladási bruttó áron');
                        ?>
                    </tr>
                </thead>
                <tbody>
                <?php if ($sorok === []) : ?>
                    <tr>
                        <td colspan="15" class="sdh-tabla__ures">
                            <?php if (!$ures_torzs) : ?>
                                Erre a szűrésre nincs találat.
                            <?php else : ?>
                                Még nincs termék.
                                <a href="<?php echo esc_url(self::url(['nezet' => 'uj'])); ?>"
                                   data-sdh-urlap="<?php echo esc_attr(self::KULCS); ?>"
                                   data-sdh-id="0">Vidd fel az elsőt</a>,
                                vagy töltsd be a 20 mintaterméket:
                                <form method="post" class="sdh-tabla__urlap" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                    <input type="hidden" name="action" value="sdh_muhely_termek_demo">
                                    <input type="hidden" name="kontextus" value="<?php echo esc_attr(self::kontextus_ertek()); ?>">
                                    <?php wp_nonce_field('sdh_muhely_termek_demo', 'sdh_nonce'); ?>
                                    <button type="submit" class="sdh-gomb sdh-gomb--vilagos">20 mintatermék betöltése</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php else : ?>
                    <?php foreach ($sorok as $t) : ?>
                        <?php
                        $szerkeszt_url = self::url(['nezet' => 'szerkeszt', 'id' => (int) $t->id]);
                        $brutto        = (float) $t->brutto_ar;
                        $besz          = (float) $t->besz_netto;
                        $kezelt        = (int) $t->keszletkezeles === 1;
                        ?>
                        <tr class="<?php echo (int) $t->aktiv === 1 ? '' : 'sdh-sor--inaktiv'; ?>">
                            <td class="sdh-tabla__szam sdh-tabla__halvany sdh-tabla__mobil-nem"><?php echo (int) $t->id; ?></td>
                            <td class="sdh-tabla__mobil-nem"><?php echo esc_html((string) $t->kategoria); ?></td>
                            <td class="sdh-tabla__nev">
                                <a href="<?php echo esc_url($szerkeszt_url); ?>"
                                   data-sdh-urlap="<?php echo esc_attr(self::KULCS); ?>"
                                   data-sdh-id="<?php echo (int) $t->id; ?>">
                                    <?php echo esc_html((string) $t->megnevezes); ?>
                                </a>
                            </td>
                            <td class="sdh-tabla__rejtheto sdh-tabla__ritka2"><?php echo esc_html((string) $t->termekkod); ?></td>
                            <td class="sdh-tabla__mobil-nem"><?php echo esc_html((string) $t->cikkszam); ?></td>
                            <td class="sdh-tabla__rejtheto sdh-tabla__ritka"><?php echo esc_html((string) $t->gyari_szam); ?></td>
                            <td class="sdh-tabla__rejtheto sdh-tabla__ritka"><?php echo esc_html((string) $t->vonalkod); ?></td>
                            <td class="sdh-tabla__szam"><?php echo self::keszlet_html($t); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
                            <td class="sdh-tabla__mobil-nem"><?php echo esc_html((string) $t->me); ?></td>
                            <td class="sdh-tabla__szam sdh-tabla__rejtheto"><?php echo $besz > 0 ? esc_html(self::penz($besz)) : '—'; ?></td>
                            <td class="sdh-tabla__szam sdh-tabla__rejtheto sdh-tabla__ritka sdh-tabla__halvany"><?php echo $besz > 0 ? esc_html(self::penz(self::besz_brutto($t))) : '—'; ?></td>
                            <td class="sdh-tabla__mobil-nem"><?php echo esc_html(self::afa_felirat((string) $t->afa_kulcs)); ?></td>
                            <td class="sdh-tabla__szam sdh-tabla__rejtheto sdh-tabla__ritka sdh-tabla__halvany"><?php echo $brutto > 0 ? esc_html(self::penz(self::netto_ar($t))) : '—'; ?></td>
                            <td class="sdh-tabla__szam"><strong><?php echo $brutto > 0 ? esc_html(self::penz($brutto)) : '—'; ?></strong></td>
                            <td class="sdh-tabla__szam sdh-tabla__rejtheto sdh-tabla__halvany">
                                <?php echo $kezelt && (float) $t->keszlet > 0 ? esc_html(self::penz((float) $t->keszlet * $brutto)) : '—'; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
                <?php if ($sorok !== []) : ?>
                    <tfoot>
                        <tr>
                            <td class="sdh-tabla__mobil-nem"></td>
                            <td class="sdh-tabla__mobil-nem"></td>
                            <td class="sdh-tabla__szumma">Σ a teljes találatra (<?php echo esc_html(number_format_i18n($osszesen)); ?> termék)</td>
                            <td class="sdh-tabla__rejtheto sdh-tabla__ritka2"></td>
                            <td class="sdh-tabla__mobil-nem"></td>
                            <td class="sdh-tabla__rejtheto sdh-tabla__ritka"></td>
                            <td class="sdh-tabla__rejtheto sdh-tabla__ritka"></td>
                            <td class="sdh-tabla__szam" title="Készlet összesen"><?php echo esc_html(self::menny((float) $ossz->keszlet)); ?></td>
                            <td class="sdh-tabla__mobil-nem"></td>
                            <td class="sdh-tabla__szam sdh-tabla__rejtheto" title="A készlet értéke beszerzési nettó áron">
                                <?php echo esc_html(self::penz((float) $ossz->besz_ertek)); ?>
                            </td>
                            <td class="sdh-tabla__rejtheto sdh-tabla__ritka"></td>
                            <td class="sdh-tabla__mobil-nem"></td>
                            <td class="sdh-tabla__rejtheto sdh-tabla__ritka"></td>
                            <td></td>
                            <td class="sdh-tabla__szam sdh-tabla__rejtheto" title="A készlet értéke eladási bruttó áron">
                                <?php echo esc_html(self::penz((float) $ossz->brutto_ertek)); ?>
                            </td>
                        </tr>
                    </tfoot>
                <?php endif; ?>
            </table>

            <?php self::lapozo($oldal, $oldalak, array_merge($alap, $rendezve)); ?>
        </div>
        <?php
    }

    /**
     * @param array<string, mixed> $alap
     */
    private static function lapozo(int $oldal, int $oldalak, array $alap): void
    {
        if ($oldalak <= 1) {
            return;
        }

        echo '<div class="sdh-lapozo">';

        for ($i = 1; $i <= $oldalak; $i++) {
            if ($i === $oldal) {
                echo '<span class="sdh-lapozo__aktiv">' . (int) $i . '</span>';
            } else {
                echo '<a href="' . esc_url(self::url(array_merge($alap, ['oldalszam' => $i]))) . '">' . (int) $i . '</a>';
            }
        }

        echo '</div>';
    }

    /** „Összes tételmozgás": a készletmozgások naplója, a legújabb elöl. */
    private static function mozgasok_oldal(): void
    {
        global $wpdb;

        $oldal   = isset($_GET['oldalszam']) ? max(1, (int) $_GET['oldalszam']) : 1;
        $eltolas = ($oldal - 1) * self::OLDAL_MERET;
        $mozgas  = self::mozgas_tabla();
        $tabla   = self::tabla();

        $osszesen = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$mozgas}");
        $sorok    = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT m.*, p.megnevezes, p.me, p.cikkszam
                 FROM {$mozgas} m LEFT JOIN {$tabla} p ON p.id = m.termek_id
                 ORDER BY m.id DESC LIMIT %d OFFSET %d",
                self::OLDAL_MERET,
                $eltolas
            )
        );
        $sorok    = is_array($sorok) ? $sorok : [];
        $oldalak  = max(1, (int) ceil($osszesen / self::OLDAL_MERET));

        ?>
        <div class="sdh-wrap">
            <?php
            SDH_Muhely_Admin_UI::uzenet();
            SDH_Muhely_Admin_UI::fejlec(
                'Termékek',
                'Minden készletváltozás: nyitókészlet, bevételezés, korrekció és a munkalapokra tett termékek.',
                [
                    [
                        'cimke'      => '+ Új termék',
                        'url'        => self::url(['nezet' => 'uj']),
                        'elsodleges' => true,
                        'adatok'     => ['sdh-urlap' => self::KULCS, 'sdh-id' => '0'],
                    ],
                ]
            );

            self::nezetfulek('mozgasok');
            ?>

            <table class="sdh-tabla sdh-tabla--mozgas">
                <thead>
                    <tr>
                        <th>Időpont</th>
                        <th>Termék</th>
                        <th class="sdh-tabla__rejtheto">Cikkszám</th>
                        <th>Mozgás</th>
                        <th class="sdh-tabla__szam">Mennyiség</th>
                        <th>Megjegyzés</th>
                        <th class="sdh-tabla__rejtheto">Rögzítette</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($sorok === []) : ?>
                    <tr><td colspan="7" class="sdh-tabla__ures">Még nincs készletmozgás.</td></tr>
                <?php else : ?>
                    <?php foreach ($sorok as $m) : ?>
                        <tr>
                            <td class="sdh-tabla__halvany"><?php echo esc_html(mysql2date('Y. m. d. H:i', (string) $m->letrehozva)); ?></td>
                            <td class="sdh-tabla__nev">
                                <?php if ($m->megnevezes !== null) : ?>
                                    <a href="<?php echo esc_url(self::url(['nezet' => 'szerkeszt', 'id' => (int) $m->termek_id])); ?>"
                                       data-sdh-urlap="<?php echo esc_attr(self::KULCS); ?>"
                                       data-sdh-id="<?php echo (int) $m->termek_id; ?>"><?php echo esc_html((string) $m->megnevezes); ?></a>
                                <?php else : ?>
                                    <span class="sdh-tabla__halvany">(törölt termék)</span>
                                <?php endif; ?>
                            </td>
                            <td class="sdh-tabla__rejtheto"><?php echo esc_html((string) ($m->cikkszam ?? '')); ?></td>
                            <td><?php echo self::mozgas_cimke($m); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
                            <td class="sdh-tabla__szam"><?php echo self::mozgas_menny($m); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
                            <td><?php echo esc_html((string) $m->megjegyzes); ?></td>
                            <td class="sdh-tabla__rejtheto sdh-tabla__halvany"><?php echo esc_html(self::felhasznalo_nev((int) $m->felhasznalo)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <?php self::lapozo($oldal, $oldalak, ['nezet' => 'mozgasok']); ?>
        </div>
        <?php
    }

    private static function felhasznalo_nev(int $id): string
    {
        $felhasznalo = $id > 0 ? get_userdata($id) : false;

        return $felhasznalo ? (string) $felhasznalo->display_name : '';
    }

    /** A mozgás fajtája; munkalapnál a munkalap megnyitható. */
    private static function mozgas_cimke(object $m): string
    {
        $tipusok = self::mozgas_tipusok();
        $nev     = $tipusok[(string) $m->tipus] ?? (string) $m->tipus;

        if ((string) $m->tipus === 'munkalap' && (int) $m->munkalap_id > 0) {
            return sprintf(
                '<a href="%s" data-sdh-urlap="munkalapok" data-sdh-id="%d">%s</a>',
                esc_url(SDH_Muhely_Modulok::url('munkalapok', ['nezet' => 'szerkeszt', 'id' => (int) $m->munkalap_id])),
                (int) $m->munkalap_id,
                esc_html($nev)
            );
        }

        return esc_html($nev);
    }

    private static function mozgas_menny(object $m): string
    {
        $menny = (float) $m->mennyiseg;

        return sprintf(
            '<span class="sdh-mozgas sdh-mozgas--%s">%s%s %s</span>',
            $menny < 0 ? 'ki' : 'be',
            $menny > 0 ? '+' : '',
            esc_html(self::menny($menny)),
            esc_html((string) ($m->me ?? ''))
        );
    }

    /* =================================================================
     * Űrlap
     * ============================================================== */

    private static function alcim(?object $t): string
    {
        if ($t === null) {
            return 'Csak a megnevezés kötelező. Az eladási ár bruttó, a beszerzési ár nettó egységár.';
        }

        $db = self::hasznalat((int) $t->id);

        return '#' . (int) $t->id . ' · ' . ($db > 0 ? $db . ' munkalap-tételben szerepel.' : 'Még nem szerepelt munkalapon.')
            . ((int) $t->aktiv === 1 ? '' : ' INAKTÍV – a választóban nem jelenik meg.');
    }

    /** Teljes oldalas változat (tartalék). */
    private static function urlap_oldal(string $nezet): void
    {
        $t = null;

        if ($nezet === 'szerkeszt') {
            $t = self::egy(isset($_GET['id']) ? (int) $_GET['id'] : 0);

            if ($t === null) {
                wp_safe_redirect(self::url(['uzenet' => 'nincs_ilyen']));
                exit;
            }
        }

        ?>
        <div class="sdh-wrap">
            <?php
            SDH_Muhely_Admin_UI::uzenet();
            SDH_Muhely_Admin_UI::fejlec(
                $t === null ? 'Új termék' : (string) $t->megnevezes,
                self::alcim($t),
                [['cimke' => '← Vissza a listához', 'url' => self::url()]]
            );
            ?>

            <form class="sdh-urlap sdh-urlap--szeles" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="sdh_muhely_termek_mentes">
                <?php self::urlap_belso($t, false); ?>
            </form>
        </div>
        <?php
    }

    /** Popup változat. */
    public static function ajax_urlap(): void
    {
        check_ajax_referer('sdh_muhely_modal');

        if (!current_user_can(SDH_Muhely_Admin_UI::jog())) {
            status_header(403);
            echo '<div class="sdh-uzenet sdh-uzenet--hiba">Nincs jogosultságod ehhez.</div>';
            wp_die();
        }

        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        $t  = $id > 0 ? self::egy($id) : null;

        if ($id > 0 && $t === null) {
            echo '<div class="sdh-uzenet sdh-uzenet--hiba">Nincs ilyen termék.</div>';
            wp_die();
        }

        // Új felvitelnél a hívó előre megadhatja a nevet (a választó popup keresője).
        $nev = $t === null && isset($_GET['nev']) ? sanitize_text_field(wp_unslash($_GET['nev'])) : '';

        ?>
        <h2 class="sdh-modal__cim"><?php echo esc_html($t === null ? 'Új termék' : (string) $t->megnevezes); ?></h2>
        <p class="sdh-modal__alcim"><?php echo esc_html(self::alcim($t)); ?></p>

        <form class="sdh-urlap" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
              data-sdh-ajax-action="sdh_muhely_termekek_ment">
            <input type="hidden" name="action" value="sdh_muhely_termek_mentes">
            <?php self::urlap_belso($t, true, $nev); ?>
        </form>
        <?php

        wp_die();
    }

    /**
     * Popupból választható, de saját értékkel is bővíthető mező (app.js data-sdh-pop).
     *
     * @param array<int, array{cim: string, elemek: array<int, string>}> $csoportok
     */
    private static function pop_mezo(string $nev, string $tipus, string $ertek, string $helyorzo, array $csoportok): void
    {
        $csoportok = array_values(array_filter($csoportok, static fn (array $cs): bool => $cs['elemek'] !== []));

        ?>
        <input type="text" name="<?php echo esc_attr($nev); ?>" id="termek_<?php echo esc_attr($nev); ?>"
               class="sdh-pop" readonly autocomplete="off"
               data-sdh-pop="<?php echo esc_attr($tipus); ?>"
               data-sdh-pop-adat="<?php echo esc_attr((string) wp_json_encode($csoportok)); ?>"
               placeholder="<?php echo esc_attr($helyorzo); ?>"
               value="<?php echo esc_attr($ertek); ?>">
        <?php
    }

    /**
     * @param array<string, array{nev: string, szazalek: float}> $afakulcsok
     */
    private static function afa_valaszto(string $nev, string $adat, array $afakulcsok, string $kivalasztott, string $cimke): void
    {
        ?>
        <select name="<?php echo esc_attr($nev); ?>" data-t="<?php echo esc_attr($adat); ?>" aria-label="<?php echo esc_attr($cimke); ?>">
            <?php foreach ($afakulcsok as $kulcs => $a) : ?>
                <option value="<?php echo esc_attr((string) $kulcs); ?>"
                        data-szazalek="<?php echo esc_attr((string) $a['szazalek']); ?>"
                        <?php selected($kivalasztott, (string) $kulcs); ?>>
                    <?php echo esc_html($a['nev']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    /**
     * Az űrlap belseje – a teljes oldalas és a popupos változat közös része.
     * Elrendezés a MunkaLap 3 „Tétel szerkesztése" ablaka szerint: balra a
     * kódok, jobbra a megnevezés, a készlet és az árak, alul lapfülek.
     */
    private static function urlap_belso(?object $t, bool $modal, string $nev = ''): void
    {
        global $wpdb;

        $uj         = $t === null;
        $afakulcsok = SDH_Muhely_Tetel::afakulcsok();
        $alap_afa   = SDH_Muhely_Tetel::alap_afakulcs();
        $afa        = $uj ? $alap_afa : SDH_Muhely_Tetel::afakulcs_ervenyes((string) $t->afa_kulcs);
        $besz_afa   = $uj ? $alap_afa : SDH_Muhely_Tetel::afakulcs_ervenyes((string) $t->besz_afa_kulcs);
        $ert        = static fn (string $mezo): string => $uj ? '' : (string) ($t->{$mezo} ?? '');
        $ar         = static fn (float $ertek): string => $ertek > 0 ? self::mezobe($ertek) : '';
        $kezelt     = $uj ? true : (int) $t->keszletkezeles === 1;
        $keszlet    = $uj ? 0.0 : (float) $t->keszlet;
        $haszon     = $uj ? null : self::haszonkulcs($t);
        $kedv       = $uj ? 0.0 : (float) $t->kedvezmeny;
        $min        = $uj ? 0.0 : (float) $t->min_keszlet;

        $sajat_kat = self::kategoriak();
        $kategoria_adat = [
            ['cim' => 'Használt kategóriák', 'elemek' => $sajat_kat],
            ['cim' => 'Javasolt', 'elemek' => array_values(array_diff(self::javasolt_kategoriak(), $sajat_kat))],
        ];

        $sajat_besz = self::beszallitok();
        $cegek      = $wpdb->get_col(
            'SELECT nev FROM ' . SDH_Muhely_Schema::tabla('ugyfel') . " WHERE aktiv = 1 AND tipus <> 'maganszemely' AND nev <> '' ORDER BY nev ASC LIMIT 80"
        );
        $beszallito_adat = [
            ['cim' => 'Eddigi beszállítók', 'elemek' => $sajat_besz],
            ['cim' => 'Cégek az ügyfelek közül', 'elemek' => array_values(array_diff(array_map('strval', (array) $cegek), $sajat_besz))],
        ];

        $mozgasok = $uj ? [] : (array) $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . self::mozgas_tabla() . ' WHERE termek_id = %d ORDER BY id DESC LIMIT %d',
                (int) $t->id,
                self::URLAP_MOZGAS
            )
        );

        ?>
        <input type="hidden" name="id" value="<?php echo (int) ($t->id ?? 0); ?>">
        <input type="hidden" name="kontextus" value="<?php echo esc_attr(self::kontextus_ertek()); ?>">
        <input type="hidden" name="keszlet_eredeti" value="<?php echo esc_attr(number_format($keszlet, 3, '.', '')); ?>">
        <?php wp_nonce_field('sdh_muhely_termek_mentes', 'sdh_nonce'); ?>

        <div class="sdh-ugyfelurlap sdh-termekurlap" data-sdh-termekurlap>
            <div class="sdh-termekurlap__felso">
                <?php // --- Jobb: megnevezés, készlet, árak ----------------------------- ?>
                <div class="sdh-termekurlap__fo">
                    <div class="sdh-ig">
                        <label for="termek_megnevezes">Megnevezés *</label>
                        <input type="text" name="megnevezes" id="termek_megnevezes" required maxlength="255" autocomplete="off"
                               placeholder="Pl. Samsung Galaxy A54 kijelző (kerettel)"
                               value="<?php echo esc_attr($uj ? $nev : (string) $t->megnevezes); ?>">
                    </div>

                    <div class="sdh-sor sdh-sor--ketto">
                        <div class="sdh-ig">
                            <label for="termek_kategoria">Kategória</label>
                            <?php self::pop_mezo('kategoria', 'termekkat', $ert('kategoria'), 'Válassz vagy írj be újat', $kategoria_adat); ?>
                        </div>

                        <div class="sdh-ig">
                            <label for="termek_beszallito">Beszállító</label>
                            <?php self::pop_mezo('beszallito', 'beszallito', $ert('beszallito'), 'Válassz vagy írj be újat', $beszallito_adat); ?>
                        </div>
                    </div>

                    <div class="sdh-termekurlap__keszlet">
                        <label class="sdh-jelolo">
                            <input type="checkbox" name="keszletkezeles" value="1" data-t="kezelt" <?php checked($kezelt); ?>>
                            Készletkezelés
                        </label>

                        <div class="sdh-termekurlap__kmezo" data-t-keszletsor>
                            <label for="termek_keszlet"><?php echo $uj ? 'Nyitókészlet' : 'Készlet'; ?></label>
                            <input type="text" inputmode="decimal" name="keszlet" id="termek_keszlet" class="is-jobb" data-t="keszlet"
                                   value="<?php echo esc_attr(self::mezobe($keszlet, 3)); ?>">
                        </div>

                        <div class="sdh-termekurlap__kmezo">
                            <label for="termek_me">M.e.</label>
                            <input type="text" name="me" id="termek_me" maxlength="20" placeholder="db"
                                   value="<?php echo esc_attr($uj ? 'db' : (string) $t->me); ?>">
                        </div>

                        <div class="sdh-termekurlap__kmezo" data-t-keszletsor>
                            <label for="termek_min_keszlet" title="Ez alatt a termék a „Rendelni kell” nézetbe kerül">Min. készlet</label>
                            <input type="text" inputmode="decimal" name="min_keszlet" id="termek_min_keszlet" class="is-jobb"
                                   placeholder="0" value="<?php echo esc_attr($min > 0 ? self::mezobe($min, 3) : ''); ?>">
                        </div>

                        <div class="sdh-termekurlap__kmezo sdh-termekurlap__kmezo--szeles">
                            <span class="sdh-termekurlap__sugo" data-t-keszletsugo>
                                <?php echo $uj
                                    ? 'A nyitókészlet készletmozgásként rögzül. A munkalapra tett termék magától levonódik.'
                                    : 'Az átírt készlet mozgásként rögzül (bevételezés vagy korrekció); a munkalapok fogyása magától levonódik.'; ?>
                            </span>

                            <?php if (!$uj) : ?>
                                <span class="sdh-termekurlap__ok" data-t-keszletok hidden>
                                    <label for="termek_keszlet_megjegyzes">A változás oka</label>
                                    <input type="text" name="keszlet_megjegyzes" id="termek_keszlet_megjegyzes" maxlength="255"
                                           placeholder="Pl. beérkezett rendelés, leltár, selejt">
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="sdh-termekurlap__arak">
                        <span></span>
                        <span class="sdh-termekurlap__arfej">Nettó</span>
                        <span class="sdh-termekurlap__arfej">Bruttó</span>
                        <span class="sdh-termekurlap__arfej">Áfakulcs</span>
                        <span class="sdh-termekurlap__arfej">Haszonkulcs</span>

                        <label for="termek_besz_netto">Beszerzési ár</label>
                        <input type="text" inputmode="decimal" name="besz_netto" id="termek_besz_netto" class="is-jobb"
                               data-t="besz_netto" placeholder="0" aria-label="Beszerzési nettó ár"
                               value="<?php echo esc_attr($uj ? '' : $ar((float) $t->besz_netto)); ?>">
                        <input type="text" inputmode="decimal" name="besz_brutto" class="is-jobb"
                               data-t="besz_brutto" placeholder="0" aria-label="Beszerzési bruttó ár"
                               value="<?php echo esc_attr($uj ? '' : $ar(self::besz_brutto($t))); ?>">
                        <?php self::afa_valaszto('besz_afa_kulcs', 'besz_afa', $afakulcsok, $besz_afa, 'A beszerzés áfakulcsa'); ?>
                        <span></span>

                        <label for="termek_brutto_ar">Eladási ár</label>
                        <input type="text" inputmode="decimal" name="netto_ar" class="is-jobb"
                               data-t="netto" placeholder="0" aria-label="Eladási nettó ár"
                               value="<?php echo esc_attr($uj ? '' : $ar(self::netto_ar($t))); ?>">
                        <input type="text" inputmode="decimal" name="brutto_ar" id="termek_brutto_ar" class="is-jobb sdh-termekurlap__fomezo"
                               data-t="brutto" placeholder="0" aria-label="Eladási bruttó ár"
                               value="<?php echo esc_attr($uj ? '' : $ar((float) $t->brutto_ar)); ?>">
                        <?php self::afa_valaszto('afa_kulcs', 'afa', $afakulcsok, $afa, 'Az eladás áfakulcsa'); ?>
                        <span class="sdh-termekurlap__szazalek">
                            <input type="text" inputmode="decimal" class="is-jobb" data-t="haszon" placeholder="—"
                                   aria-label="Haszonkulcs százalékban"
                                   title="Átírva az eladási árat számolja a beszerzési árból"
                                   value="<?php echo esc_attr($haszon !== null ? self::mezobe($haszon, 1) : ''); ?>">
                            <span aria-hidden="true">%</span>
                        </span>

                        <label for="termek_kedvezmeny">Kedvezmény</label>
                        <span class="sdh-termekurlap__szazalek">
                            <input type="text" inputmode="decimal" name="kedvezmeny" id="termek_kedvezmeny" class="is-jobb"
                                   placeholder="0" title="A munkalapra ezzel a kedvezménnyel kerül"
                                   value="<?php echo esc_attr($kedv > 0 ? self::mezobe($kedv) : ''); ?>">
                            <span aria-hidden="true">%</span>
                        </span>
                        <output class="sdh-termekurlap__haszon" data-t="haszon_ft"></output>
                    </div>
                </div>

                <?php // --- Bal: kódok --------------------------------------------------- ?>
                <fieldset class="sdh-termekurlap__kodok">
                    <legend>Kódok</legend>

                    <?php foreach ([
                        'gyari_szam' => 'Gyári szám',
                        'cikkszam'   => 'Cikkszám',
                        'termekkod'  => 'Termékkód',
                        'vonalkod'   => 'Vonalkód',
                        'osztaly'    => 'Osztály',
                    ] as $mezo => $cimke) : ?>
                        <div class="sdh-ig">
                            <label for="termek_<?php echo esc_attr($mezo); ?>"><?php echo esc_html($cimke); ?></label>
                            <input type="text" name="<?php echo esc_attr($mezo); ?>" id="termek_<?php echo esc_attr($mezo); ?>"
                                   maxlength="60" autocomplete="off" value="<?php echo esc_attr($ert($mezo)); ?>">
                        </div>
                    <?php endforeach; ?>

                    <div class="sdh-ig">
                        <label for="konyvelve">Könyvelve</label>
                        <input type="text" name="konyvelve" id="konyvelve"
                               class="sdh-pop sdh-pop--datum" readonly autocomplete="off"
                               data-sdh-pop="datum" data-sdh-pop-adat="[]" placeholder="éééé-hh-nn"
                               value="<?php echo esc_attr($ert('konyvelve')); ?>">
                    </div>
                </fieldset>
            </div>

            <?php // --- Alul: lapfülek -------------------------------------------------- ?>
            <div class="sdh-fulek sdh-fulek--also sdh-termekurlap__also">
                <input type="radio" class="sdh-fulek__ful sdh-fulek__ful--1" name="_ful_termek" id="ful_t_leiras" checked>
                <input type="radio" class="sdh-fulek__ful sdh-fulek__ful--2" name="_ful_termek" id="ful_t_megj">
                <?php if (!$uj) : ?>
                    <input type="radio" class="sdh-fulek__ful sdh-fulek__ful--3" name="_ful_termek" id="ful_t_mozgas">
                <?php endif; ?>

                <div class="sdh-fulek__sav">
                    <label for="ful_t_leiras">Leírás</label>
                    <label for="ful_t_megj">Megjegyzés</label>
                    <?php if (!$uj) : ?>
                        <label for="ful_t_mozgas">Készletmozgás</label>
                    <?php endif; ?>
                </div>

                <div class="sdh-fulek__panel sdh-fulek__panel--1">
                    <textarea name="leiras" rows="4" aria-label="Leírás"
                              placeholder="A termék leírása (kompatibilitás, minőség, tartozékok…)"><?php echo esc_textarea($ert('leiras')); ?></textarea>
                </div>

                <div class="sdh-fulek__panel sdh-fulek__panel--2">
                    <textarea name="megjegyzes" rows="4" aria-label="Megjegyzés"
                              placeholder="Belső megjegyzés (polc, rendelési tudnivalók…)"><?php echo esc_textarea($ert('megjegyzes')); ?></textarea>
                </div>

                <?php if (!$uj) : ?>
                    <div class="sdh-fulek__panel sdh-fulek__panel--3">
                        <?php if ($mozgasok === []) : ?>
                            <p class="sdh-termekurlap__ures">Ennek a terméknek még nincs készletmozgása.</p>
                        <?php else : ?>
                            <table class="sdh-tabla sdh-termekurlap__mozgasok">
                                <tbody>
                                <?php foreach ($mozgasok as $m) : ?>
                                    <?php $m->me = (string) $t->me; ?>
                                    <tr>
                                        <td class="sdh-tabla__halvany"><?php echo esc_html(mysql2date('Y. m. d. H:i', (string) $m->letrehozva)); ?></td>
                                        <td><?php echo esc_html(self::mozgas_tipusok()[(string) $m->tipus] ?? (string) $m->tipus); ?></td>
                                        <td class="sdh-tabla__szam"><?php echo self::mozgas_menny($m); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
                                        <td><?php echo esc_html((string) $m->megjegyzes); ?></td>
                                        <td class="sdh-tabla__halvany"><?php echo esc_html(self::felhasznalo_nev((int) $m->felhasznalo)); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                            <p class="sdh-termekurlap__ures">
                                Az utolsó <?php echo (int) count($mozgasok); ?> mozgás.
                                <a href="<?php echo esc_url(SDH_Muhely_Modulok::visszateres(self::KULCS, ['nezet' => 'mozgasok'], self::kontextus_ertek())); ?>">Összes tételmozgás</a>
                            </p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="sdh-urlap__lablec">
                <button type="submit" class="sdh-gomb sdh-gomb--elsodleges">
                    <?php echo $uj ? 'Termék létrehozása' : 'Mentés'; ?>
                </button>

                <?php if ($modal) : ?>
                    <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-megsem>Mégsem</button>
                <?php else : ?>
                    <a class="sdh-gomb sdh-gomb--vilagos" href="<?php echo esc_url(self::url()); ?>">Mégsem</a>
                <?php endif; ?>

                <?php if (!$uj) : ?>
                    <input type="hidden" name="aktiv_jelen" value="1">
                    <label class="sdh-jelolo" title="Az inaktív termék a munkalap választójában nem jelenik meg">
                        <input type="checkbox" name="aktiv" value="1" <?php checked((int) $t->aktiv, 1); ?>>
                        Aktív
                    </label>
                <?php endif; ?>

                <?php if (!$uj && $modal) : ?>
                    <button type="button" class="sdh-gomb sdh-gomb--vilagos sdh-gomb--jobbra"
                            data-sdh-termek-torol="<?php echo (int) $t->id; ?>"
                            data-vissza="<?php echo esc_url(self::vissza(['uzenet' => 'termek_torolve'])); ?>"
                            data-vissza-inaktiv="<?php echo esc_url(self::vissza(['uzenet' => 'termek_inaktiv'])); ?>"
                            title="Ha már szerepelt munkalapon, nem törlődik, csak inaktív lesz.">
                        Törlés
                    </button>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /* =================================================================
     * AJAX
     * ============================================================== */

    private static function jog_ellenorzes(): void
    {
        check_ajax_referer('sdh_muhely_modal');

        if (!current_user_can(SDH_Muhely_Admin_UI::jog())) {
            wp_send_json_error(['uzenet' => 'Nincs jogosultságod ehhez.'], 403);
        }
    }

    /**
     * Egy termék a választó popupnak.
     *
     * @return array<string, mixed>|array<int, mixed>
     */
    private static function valaszto_sor(object $t, bool $nevvel = false): array
    {
        $sor = [
            'id'         => (int) $t->id,
            'nev'        => (string) $t->megnevezes,
            'me'         => (string) $t->me,
            'brutto_ar'  => (float) $t->brutto_ar,
            'afa_kulcs'  => SDH_Muhely_Tetel::afakulcs_ervenyes((string) $t->afa_kulcs),
            'keszlet'    => (int) $t->keszletkezeles === 1 ? (float) $t->keszlet : null,
            'cikkszam'   => (string) $t->cikkszam,
            'termekkod'  => (string) $t->termekkod,
            'gyari_szam' => (string) $t->gyari_szam,
            'vonalkod'   => (string) $t->vonalkod,
            'kategoria'  => (string) $t->kategoria,
            'kedvezmeny' => (float) $t->kedvezmeny,
            'jelzes'     => self::keszlet_jelzes($t),
        ];

        return $nevvel ? $sor : array_values($sor);
    }

    /**
     * Az aktív termékek a böngésznek. Egy sor: [id, név, m.e., bruttó ár,
     * áfakulcs, készlet|null, cikkszám, termékkód, gyári szám, vonalkód,
     * kategória, kedvezmény, készletjelzés].
     */
    public static function ajax_lista(): void
    {
        global $wpdb;

        self::jog_ellenorzes();

        $termekek = $wpdb->get_results(
            $wpdb->prepare('SELECT * FROM ' . self::tabla() . ' WHERE aktiv = 1 ORDER BY megnevezes ASC, id ASC LIMIT %d', self::LISTA_HATAR)
        );

        $sorok = [];

        foreach ((array) $termekek as $t) {
            $sorok[] = self::valaszto_sor($t);
        }

        nocache_headers();
        wp_send_json_success(['sorok' => $sorok]);
    }

    public static function ajax_torol(): void
    {
        self::jog_ellenorzes();

        $id       = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $eredmeny = self::torol($id);

        if ($eredmeny === '') {
            wp_send_json_error(['uzenet' => 'Nincs ilyen termék.'], 404);
        }

        wp_send_json_success(['id' => $id, 'inaktiv' => $eredmeny === 'inaktiv']);
    }

    /* =================================================================
     * Mintatermékek (demó)
     * ============================================================== */

    /** A lista üres állapotának gombja. */
    public static function demo_gomb(): void
    {
        SDH_Muhely_Admin_UI::jog_ellenoriz();
        check_admin_referer('sdh_muhely_termek_demo', 'sdh_nonce');

        self::demo_betolt();

        wp_safe_redirect(self::vissza(['uzenet' => 'termek_demo']));
        exit;
    }

    /**
     * Sémafrissítés után fut. Csak üres terméktörzsbe tölt, és csak ott, ahol
     * ez fejlesztői telepítés (helyi környezet) vagy már vannak demó ügyfelek –
     * éles rendszerbe magától nem kerül mintatermék.
     */
    public static function demo_potlas(): void
    {
        global $wpdb;

        if (self::darab() > 0 || get_option('sdh_muhely_termek_demo')) {
            return;
        }

        $helyi = in_array(wp_get_environment_type(), ['local', 'development'], true);
        $demo  = (int) $wpdb->get_var(
            'SELECT COUNT(*) FROM ' . SDH_Muhely_Schema::tabla('ugyfel') . " WHERE kulso_azonosito LIKE 'demo-u-%'"
        );

        if ($helyi || $demo > 0) {
            self::demo_betolt();
        }
    }

    /** EAN-13 vonalkód ellenőrző számjeggyel (a 12 jegyű törzsből). */
    private static function ean13(string $torzs): string
    {
        $osszeg = 0;

        for ($i = 0; $i < 12; $i++) {
            $osszeg += (int) $torzs[$i] * ($i % 2 === 0 ? 1 : 3);
        }

        return $torzs . ((10 - $osszeg % 10) % 10);
    }

    /**
     * 20 fiktív termék egy mobilszerviz kínálatából, nyitókészlettel. Ami már
     * megvan (`demo-t-…`), azt nem hozza létre újra.
     *
     * @return int Hány új termék jött létre.
     */
    public static function demo_betolt(): int
    {
        global $wpdb;

        update_option('sdh_muhely_termek_demo', current_time('mysql'), false);

        // [megnevezés, kategória, beszállító, osztály, készlet, min., m.e., besz. nettó, eladási bruttó, leírás]
        $termekek = [
            ['Samsung Galaxy A54 5G kijelző (OLED, kerettel)', 'Kijelző', 'Mobilparts Demo Kft.', 'A', 2, 1, 'db', 21500, 39900, 'Gyári minőségű OLED panel, előlapi kerettel. Fekete.'],
            ['Samsung Galaxy S23 kijelző (Dynamic AMOLED, kerettel)', 'Kijelző', 'Mobilparts Demo Kft.', 'A', 1, 1, 'db', 46000, 79900, 'Service pack, kerettel és akkumulátor nélkül.'],
            ['iPhone 13 kijelző (OLED, utángyártott)', 'Kijelző', 'GSM Alkatrész Demo Bt.', 'B', 3, 2, 'db', 17800, 34900, 'Soft OLED, True Tone áttölthető.'],
            ['iPhone 11 kijelző (LCD, utángyártott)', 'Kijelző', 'GSM Alkatrész Demo Bt.', 'B', 4, 2, 'db', 8900, 19900, 'In-cell LCD, fémlemezzel.'],
            ['Xiaomi Redmi Note 12 kijelző (kerettel)', 'Kijelző', 'TechnoImport Demo Zrt.', 'B', 0, 1, 'db', 12400, 26900, 'AMOLED, kerettel. Rendelésre 2–3 munkanap.'],
            ['iPhone 12 akkumulátor (2815 mAh)', 'Akkumulátor', 'GSM Alkatrész Demo Bt.', 'A', 6, 3, 'db', 4200, 12900, 'Nulla ciklusos, ragasztócsíkkal.'],
            ['Samsung Galaxy S21 akkumulátor (4000 mAh)', 'Akkumulátor', 'Mobilparts Demo Kft.', 'A', 2, 2, 'db', 5100, 14900, 'Service pack akkumulátor.'],
            ['Huawei P30 Lite akkumulátor (3340 mAh)', 'Akkumulátor', 'TechnoImport Demo Zrt.', 'B', 1, 2, 'db', 3600, 10900, 'Utángyártott, 12 hónap garancia.'],
            ['Samsung Galaxy A52 töltőcsatlakozó panel', 'Töltőcsatlakozó', 'Mobilparts Demo Kft.', 'A', 5, 2, 'db', 2300, 8900, 'USB-C aljzat, mikrofonnal, alaplapi csatlakozóval.'],
            ['iPhone 11 töltőcsatlakozó flex kábel', 'Töltőcsatlakozó', 'GSM Alkatrész Demo Bt.', 'B', 3, 2, 'db', 2900, 11900, 'Lightning aljzat, mikrofonokkal. Fekete.'],
            ['USB-C töltőaljzat (univerzális, 16 pin)', 'Töltőcsatlakozó', 'TechnoImport Demo Zrt.', 'C', 25, 10, 'db', 180, 1500, 'Forrasztható aljzat panelcseréhez.'],
            ['Samsung Galaxy A54 5G hátlap (fekete)', 'Hátlap', 'Mobilparts Demo Kft.', 'B', 2, 1, 'db', 3900, 9900, 'Üveg hátlap, kameralencsével és ragasztóval.'],
            ['iPhone 13 hátlapi kamera modul', 'Kamera', 'GSM Alkatrész Demo Bt.', 'A', 1, 1, 'db', 14500, 29900, 'Dupla kamera modul, bontott gyári.'],
            ['iPhone 12 hangszóró (alsó, csengő)', 'Hangszóró', 'GSM Alkatrész Demo Bt.', 'B', 4, 2, 'db', 1600, 6900, 'Alsó hangszóró modul.'],
            ['Kijelzővédő üvegfólia (univerzális 6,5")', 'Tartozék', 'TechnoImport Demo Zrt.', 'C', 40, 15, 'db', 350, 2990, '9H keménység, felhelyezéssel együtt.'],
            ['USB-C – USB-C adatkábel 1 m (60 W)', 'Tartozék', 'TechnoImport Demo Zrt.', 'C', 18, 8, 'db', 690, 2990, 'Szövet borítású, gyorstöltésre alkalmas.'],
            ['Hálózati gyorstöltő 25 W (USB-C)', 'Tartozék', 'TechnoImport Demo Zrt.', 'C', 9, 5, 'db', 2100, 6990, 'Power Delivery, kábel nélkül.'],
            ['Szilikon tok – Samsung Galaxy A54 (átlátszó)', 'Tartozék', 'TechnoImport Demo Zrt.', 'C', 12, 5, 'db', 420, 2490, 'Ütésálló sarkokkal.'],
            ['B-7000 ragasztó (15 ml)', 'Műhelyanyag', 'Mobilparts Demo Kft.', 'C', 7, 3, 'db', 520, 1990, 'Kijelző- és hátlapragasztáshoz.'],
            ['Kártyafüggetlen nyomógombos mobiltelefon (demó modell)', 'Telefon', 'TechnoImport Demo Zrt.', 'A', 2, 1, 'db', 8400, 14990, 'Nagy gombos, SOS gombbal, asztali töltővel.'],
        ];

        $tabla = self::tabla();
        $most  = current_time('mysql');
        $ma    = current_time('Y-m-d');
        $rovid = [
            'Kijelző' => 'KIJ', 'Akkumulátor' => 'AKK', 'Töltőcsatlakozó' => 'TLT', 'Hátlap' => 'HAT', 'Kamera' => 'KAM',
            'Hangszóró' => 'HNG', 'Tartozék' => 'TRT', 'Műhelyanyag' => 'MUH', 'Telefon' => 'TEL',
        ];
        $uj    = 0;

        foreach ($termekek as $i => $p) {
            $sorszam = $i + 1;
            $kulcs   = sprintf('demo-t-%02d', $sorszam);

            if ((int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$tabla} WHERE kulso_azonosito = %s", $kulcs)) > 0) {
                continue;
            }

            $siker = $wpdb->insert($tabla, [
                'megnevezes'      => $p[0],
                'leiras'          => $p[9],
                'kategoria'       => $p[1],
                'beszallito'      => $p[2],
                'gyari_szam'      => $sorszam === 20 ? '350000000000' . sprintf('%03d', 417) : '',
                'cikkszam'        => sprintf('SDH-%s-%04d', $rovid[$p[1]] ?? 'TRM', $sorszam),
                'termekkod'       => sprintf('G%06d', 100000 + $sorszam * 137),
                'vonalkod'        => self::ean13(sprintf('599900%06d', 4200 + $sorszam * 31)),
                'osztaly'         => $p[3],
                'keszletkezeles'  => 1,
                'keszlet'         => 0,
                'min_keszlet'     => $p[5],
                'me'              => $p[6],
                'besz_netto'      => $p[7],
                'besz_afa_kulcs'  => '27',
                'brutto_ar'       => $p[8],
                'afa_kulcs'       => '27',
                'kedvezmeny'      => 0,
                'konyvelve'       => $ma,
                'megjegyzes'      => 'Mintatermék – fiktív adat.',
                'aktiv'           => 1,
                'forras'          => 'demo',
                'kulso_azonosito' => $kulcs,
                'letrehozva'      => $most,
                'modositva'       => $most,
                'letrehozo'       => get_current_user_id(),
            ]);

            if ($siker === false) {
                continue;
            }

            $uj++;
            self::mozgas((int) $wpdb->insert_id, (float) $p[4], 'nyito', 'Mintatermék nyitókészlete');
        }

        return $uj;
    }
}
