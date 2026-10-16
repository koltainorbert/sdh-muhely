<?php
/**
 * Független napi mentés (0.52).
 *
 * Eddig teljes mentés csak akkor készült, amikor az asszisztens aznap
 * először módosított valamit. Mostantól minden nap készül egy – akkor is,
 * ha az asszisztenst senki nem használja:
 *
 *   1. a WordPress időzítője naponta elindítja (ha a szerver éjjel is megy);
 *   2. ha ez kimaradt (a gép éjjel ki volt kapcsolva, vagy a WP-cron tiltva
 *      van), a nap első bejelentkezett oldalbetöltése UTÁN készül el – a
 *      válasz kiküldése után, tehát a felületet nem lassítja.
 *
 * A mentés maga változatlan (SDH_Muhely_Asszisztens_Tudas::teljes_mentes):
 * minden saját tábla és beállítás, gzip-pelt JSON-sorokban, a feltöltési
 * mappa kívülről nem olvasható almappájában.
 *
 * Külső másolat (0.73.0, H3): a helyi mentés után, ha be van kapcsolva, a rendszer jelszóval
 * titkosított másolatot tölt fel a beállított helyekre (saját gép, NAS, FTP, Google Drive).
 * Ami nem sikerül, „várakozik”, és újrapróbálódik (15 percenként, legfeljebb hatszor; a napi
 * mentés ezt is újraindítja). Megőrzés: a beállított számú legfrissebb mentés, ÉS mindig az
 * előző napi mentés – így egy napi több kézi mentés sem szorítja ki a tegnapit.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Mentes
{
    private const OPTION = 'sdh_muhely_mentes';
    private const CRON   = 'sdh_muhely_napi_mentes';
    private const ZAR    = 'sdh_muhely_mentes_fut';
    /** A következő külső újrapróbálás ideje (unix); autoload, hogy a kérésenkénti ellenőrzés ne kerüljön lekérdezésbe. */
    private const UJRA   = 'sdh_muhely_mentes_ujra';
    private const KULSO_UJRA = 'sdh_muhely_mentes_kulso_ujra';
    private const MAX_PROBA  = 6;

    public static function init(): void
    {
        add_action('init', [self::class, 'cron_beallit']);
        add_action(self::CRON, [self::class, 'idozitett']);
        add_action(self::CRON . '_most', [self::class, 'idozitett']);
        add_action(self::KULSO_UJRA, [self::class, 'kulso_ujra_ha_kell']);
        add_action('shutdown', [self::class, 'potlas'], 99);
        add_action('wp_ajax_sdh_muhely_mentes_most', [self::class, 'ajax_most']);
    }

    /** @return array{aktiv: bool, megtart: int} */
    public static function beallitas(): array
    {
        $b = get_option(self::OPTION, []);
        $b = is_array($b) ? $b : [];

        return [
            'aktiv'   => !isset($b['aktiv']) || !empty($b['aktiv']),
            'megtart' => min(365, max(3, (int) ($b['megtart'] ?? 20))),
        ];
    }

    /** @param array{aktiv: bool, megtart: int} $b */
    public static function beallitas_ment(array $b): void
    {
        update_option(self::OPTION, [
            'aktiv'   => !empty($b['aktiv']),
            'megtart' => min(365, max(3, (int) $b['megtart'])),
        ], false);
    }

    /** Hány mentés marad meg (a régebbiek törlődnek). */
    public static function megtartas(): int
    {
        return self::beallitas()['megtart'];
    }

    private static function motor(): bool
    {
        return class_exists('SDH_Muhely_Asszisztens_Tudas');
    }

    /** A legutóbbi mentés időpontja (0 = még nincs). */
    public static function utolso(): int
    {
        if (!self::motor()) {
            return 0;
        }

        $lista = SDH_Muhely_Asszisztens_Tudas::mentesek();

        return $lista !== [] ? (int) $lista[0]['ido'] : 0;
    }

    public static function ma_megvan(): bool
    {
        $utolso = self::utolso();

        return $utolso > 0 && wp_date('Y-m-d', $utolso) === current_time('Y-m-d');
    }

    public static function cron_beallit(): void
    {
        $aktiv = self::beallitas()['aktiv'];
        $kov   = wp_next_scheduled(self::CRON);

        if ($aktiv && !$kov) {
            wp_schedule_event(time() + 300, 'daily', self::CRON);
        } elseif (!$aktiv && $kov) {
            wp_unschedule_event($kov, self::CRON);
        }
    }

    public static function idozitett(): void
    {
        self::keszit();
        // A napi futás a korábban elakadt külső másolásokat is újraindítja.
        self::kulso_ujra_ha_kell(true);
    }

    /**
     * A mai mentés elkészítése, ha még nincs. Egyszerre csak egy fut (zár).
     *
     * @return array{fajl: string, meret: int, sorok: int}|string|null null = nem kellett
     */
    public static function keszit(bool $akkor_is = false)
    {
        if (!self::motor() || (!$akkor_is && (!self::beallitas()['aktiv'] || self::ma_megvan()))) {
            return null;
        }

        if (!self::zar_fog()) {
            return null;
        }

        try {
            $r = SDH_Muhely_Asszisztens_Tudas::teljes_mentes($akkor_is ? 'kezi' : 'napi');
        } catch (Throwable $hiba) {
            $r = 'A mentés megszakadt: ' . $hiba->getMessage();
        }

        // A helyi mentés kész; a külső másolat hibája nem teszi hibássá (külön látszik a Mentés lapon).
        if (is_array($r)) {
            try {
                $r['kulso'] = self::kulso_masolat($r['fajl']);
            } catch (Throwable $hiba) {
                $r['kulso'] = ['*' => 'A külső másolás megszakadt: ' . $hiba->getMessage()];
            }
        }

        delete_option(self::ZAR);
        update_option('sdh_muhely_mentes_utolso', ['ido' => current_time('mysql'), 'hiba' => is_string($r) ? $r : ''], false);

        return $r;
    }

    /**
     * Egyszerre csak egy mentés fut. Az add_option az adatbázis egyedi kulcsán múlik, ezért két
     * egyidejű kérés közül csak az egyik kapja meg a zárat. A 15 percnél régebbi zár beragadt
     * (megszakadt kérés) – azt elengedjük.
     */
    private static function zar_fog(): bool
    {
        if (add_option(self::ZAR, (string) time(), '', false)) {
            return true;
        }

        $mikor = (int) get_option(self::ZAR, 0);

        if ($mikor > 0 && $mikor < time() - 15 * MINUTE_IN_SECONDS) {
            delete_option(self::ZAR);

            return add_option(self::ZAR, (string) time(), '', false);
        }

        return false;
    }

    /** A nap első bejelentkezett kérése után: ha ma még nincs mentés, most készül. */
    public static function potlas(): void
    {
        if (!is_user_logged_in() || !self::motor() || (defined('DOING_CRON') && DOING_CRON)) {
            return;
        }

        if (!class_exists('SDH_Muhely_Admin_UI') || !current_user_can(SDH_Muhely_Admin_UI::jog())) {
            return;
        }

        $ujra = (int) get_option(self::UJRA, 0);
        $ujra_esedekes = $ujra > 0 && $ujra <= time();

        // Olcsó előszűrő: naponta egyszer nézzük meg a fájlokat, nem minden kérésnél.
        if (!$ujra_esedekes && (get_option('sdh_muhely_mentes_nap') === current_time('Y-m-d') || !self::beallitas()['aktiv'])) {
            return;
        }

        if (get_option('sdh_muhely_db_version') === false) {
            return;
        }

        // Ha az előbb nem sikerült (megtelt lemez, nem írható mappa), egy óráig nem próbáljuk újra minden kérésnél.
        if (!$ujra_esedekes && get_transient(self::ZAR . '_hiba')) {
            return;
        }

        ignore_user_abort(true);

        // Ahol a válasz a mentés előtt lezárható (PHP-FPM, LiteSpeed), ott a böngésző nem vár rá.
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        } elseif (!(defined('DISABLE_WP_CRON') && DISABLE_WP_CRON)) {
            // Másutt (pl. Apache mod_php) a kérés megvárná a mentést: inkább külön, háttérkérésben fut le.
            if (!wp_next_scheduled(self::CRON . '_most')) {
                wp_schedule_single_event(time() - 1, self::CRON . '_most');
            }

            spawn_cron();
            // Ma többet nem próbálkozunk innen; ha a háttérkérés elmaradna, a napi időzítő pótolja.
            set_transient(self::ZAR . '_hiba', 1, HOUR_IN_SECONDS);

            return;
        }

        $r = self::keszit();

        if ($ujra_esedekes) {
            self::kulso_ujra_ha_kell();
        }

        if (is_string($r)) {
            set_transient(self::ZAR . '_hiba', 1, HOUR_IN_SECONDS);

            return;
        }

        if (self::ma_megvan()) {
            update_option('sdh_muhely_mentes_nap', current_time('Y-m-d'), false);
        }
    }

    public static function ajax_most(): void
    {
        check_ajax_referer('sdh_muhely_modal');

        if (!class_exists('SDH_Muhely_Admin_UI') || !SDH_Muhely_Admin_UI::admin_e()) {
            wp_send_json_error(['uzenet' => 'Ehhez adminisztrátori jog kell.'], 403);
        }

        $r = self::keszit(true);

        if ($r === null) {
            wp_send_json_error(['uzenet' => 'Éppen fut egy mentés – próbáld újra egy perc múlva.']);
        }

        if (is_string($r)) {
            wp_send_json_error(['uzenet' => $r]);
        }

        wp_send_json_success(['uzenet' => 'Mentés kész: ' . $r['fajl'] . ' (' . size_format($r['meret']) . ', ' . $r['sorok'] . ' sor).' . self::kulso_osszegzes($r['kulso'] ?? [])]);
    }

    /* =================================================================
     * Megőrzés: a beállított számú legfrissebb + az előző napi mentés
     * ============================================================== */

    /** A mentés ideje a fájlnévből (a webhely időzónájában); 0, ha a név nem a mi nevezéktanunk szerinti. */
    public static function nev_ido(string $nev): int
    {
        if (preg_match('/^sdh-mentes-(\d{8})-(\d{6})-/', $nev, $m) !== 1) {
            return 0;
        }

        $d = DateTimeImmutable::createFromFormat('YmdHis', $m[1] . $m[2], wp_timezone());

        return $d instanceof DateTimeImmutable ? $d->getTimestamp() : 0;
    }

    /**
     * Mely mentések maradnak meg. Mindig megmarad:
     *   – a $db legfrissebb;
     *   – az ELŐZŐ NAPI mentés: a legfrissebb, amely nem mai (így sem egy napi több kézi mentés, sem a
     *     mai próbálkozások nem szorítják ki a tegnapit);
     *   – a legutóbbi visszaállítás előtti biztonsági mentés (a visszaállítás visszacsinálásához).
     *
     * @param array<int, array{fajl: string, ido: int}> $lista
     * @param string $legujabb a most elkészült mentés (mindig megmarad, és beleszámít a darabszámba)
     * @return string[] a megtartandó fájlnevek
     */
    public static function megtartando(array $lista, int $db, ?string $ma = null, string $legujabb = ''): array
    {
        $ma = $ma ?? current_time('Y-m-d');

        foreach ($lista as $k => $m) {
            $lista[$k]['ido'] = self::nev_ido($m['fajl']) ?: (int) $m['ido'];
        }

        // Azonos másodpercben készült mentéseknél a név végi véletlen jelek döntenének: a most elkészült
        // ($legujabb) ezért kifejezetten a sor elejére kerül, és beleszámít a megtartott darabszámba.
        usort($lista, static fn (array $a, array $b): int => [$b['fajl'] === $legujabb, $b['ido'], $b['fajl']] <=> [$a['fajl'] === $legujabb, $a['ido'], $a['fajl']]);

        $meg = array_column(array_slice($lista, 0, max(1, $db)), 'fajl');

        foreach ($lista as $m) {
            if (wp_date('Y-m-d', $m['ido']) < $ma) {
                $meg[] = $m['fajl'];
                break;
            }
        }

        foreach ($lista as $m) {
            if (strpos($m['fajl'], '-visszaallitas-elott-') !== false) {
                $meg[] = $m['fajl'];
                break;
            }
        }

        return array_values(array_unique($meg));
    }

    /* =================================================================
     * Külső másolat (Google Drive, FTP, NAS, saját gép)
     * ============================================================== */

    /**
     * A helyi mentés titkosított másolata a bekapcsolt helyekre. Ami nem sikerül, „várakozik”.
     *
     * @param string[]|null $csak csak ezekre a célokra (újrapróbálásnál)
     * @return array<string, string> cél-azonosító => '' (sikerült) vagy a hiba szövege; '*' = általános hiba
     */
    public static function kulso_masolat(string $fajl, ?array $csak = null): array
    {
        $idk = SDH_Muhely_Mentes_Cel::aktiv_idk();

        if ($csak !== null) {
            $idk = array_values(array_intersect($idk, $csak));
        }

        if ($idk === [] || !self::motor()) {
            return [];
        }

        @set_time_limit(900); // phpcs:ignore WordPress.PHP.NoSilencedErrors

        $helyi  = SDH_Muhely_Asszisztens_Tudas::mentes_mappa() . $fajl;
        $nev    = (string) preg_replace('/\.jsonl\.gz$/', SDH_Muhely_Mentes_Titkos::KITERJESZTES, $fajl);
        $kulso  = [];
        $enc    = '';
        $altalanos = null;

        if (!is_file($helyi)) {
            $altalanos = 'A helyi mentés már nincs meg: ' . $fajl;
        } elseif (!SDH_Muhely_Mentes_Cel::jelszo_van()) {
            $altalanos = 'Nincs beállítva titkosító jelszó – a külső másolat nem készül (Beállítások › Mentés).';
        } elseif (!SDH_Muhely_Mentes_Titkos::elerheto()) {
            $altalanos = 'A szerveren nincs libsodium – a titkosított másolat nem készülhet el.';
        } else {
            $enc = SDH_Muhely_Asszisztens_Tudas::mentes_mappa() . 'sdh-mentes-tmp-' . wp_generate_password(10, false) . '.csomag.tmp';
            $altalanos = SDH_Muhely_Mentes_Vissza::csomag_keszit($helyi, $enc, SDH_Muhely_Mentes_Cel::jelszo());
        }

        foreach ($idk as $id) {
            if ($altalanos !== null) {
                $hiba = $altalanos;
            } else {
                $cel  = SDH_Muhely_Mentes_Cel::peldany($id);
                $hiba = $cel === null ? 'Ez a cél ezen a szerveren nem érhető el.' : $cel->feltolt($enc, $nev);

                if ($hiba === null && $cel !== null) {
                    self::kulso_takarit($id, $cel, $nev);
                }
            }

            self::kulso_rogzit($id, $fajl, $nev, $hiba, $altalanos !== null && strpos($altalanos, 'jelszó') !== false);
            $kulso[$id] = $hiba ?? '';
        }

        if ($enc !== '') {
            @unlink($enc); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        }

        return $kulso;
    }

    /** Egy cél állapotának rögzítése, a várakozó lista és az újrapróbálás ütemezése. */
    private static function kulso_rogzit(string $id, string $fajl, string $nev, ?string $hiba, bool $jelszo_hiany): void
    {
        $allapot = SDH_Muhely_Mentes_Cel::allapot();
        $varo    = array_values(array_filter((array) ($allapot[$id]['varakozik'] ?? []), 'is_string'));
        $proba   = (int) ($allapot[$id]['proba'] ?? 0);

        if ($hiba === null) {
            $varo  = array_values(array_diff($varo, [$fajl]));
            $proba = 0;
            SDH_Muhely_Mentes_Cel::allapot_ment($id, ['ido' => current_time('mysql'), 'fajl' => $nev, 'hiba' => '', 'varakozik' => $varo, 'proba' => 0]);

            return;
        }

        if (!in_array($fajl, $varo, true)) {
            $varo[] = $fajl;
        }

        rsort($varo);
        $varo = array_slice($varo, 0, 3);
        $proba++;

        SDH_Muhely_Mentes_Cel::allapot_ment($id, ['hiba_ido' => current_time('mysql'), 'hiba' => $hiba, 'varakozik' => $varo, 'proba' => $proba]);

        if (!$jelszo_hiany && $proba < self::MAX_PROBA) {
            update_option(self::UJRA, time() + 15 * MINUTE_IN_SECONDS, 'yes');

            if (!wp_next_scheduled(self::KULSO_UJRA)) {
                wp_schedule_single_event(time() + 15 * MINUTE_IN_SECONDS, self::KULSO_UJRA);
            }
        }
    }

    /** A célon a megőrzési szabály szerint felesleges mentések törlése (idegen fájlhoz nem nyúl). */
    private static function kulso_takarit(string $id, SDH_Muhely_Mentes_Cel_Alap $cel, string $friss = ''): void
    {
        $lista = $cel->listaz();

        if (!is_array($lista)) {
            return;
        }

        $kell = self::megtartando($lista, self::megtartas(), null, $friss);

        foreach ($lista as $m) {
            if (!in_array($m['fajl'], $kell, true) && SDH_Muhely_Mentes_Cel_Alap::mentes_nev($m['fajl'])) {
                $h = $cel->torol($m['fajl']);

                if ($h !== null) {
                    SDH_Muhely_Mentes_Cel::allapot_ment($id, ['takaritas_hiba' => $h]);
                    break;
                }
            }
        }
    }

    /**
     * Az elakadt külső másolások újrapróbálása. Esedékességre vár (15 perc), kivéve ha kényszerített
     * (a napi futás): akkor a próbaszámláló is nullázódik.
     */
    public static function kulso_ujra_ha_kell(bool $kenyszer = false): void
    {
        $ujra = (int) get_option(self::UJRA, 0);

        if (!self::motor() || (!$kenyszer && ($ujra <= 0 || $ujra > time()))) {
            return;
        }

        $allapot = SDH_Muhely_Mentes_Cel::allapot();
        $varo    = [];

        foreach (SDH_Muhely_Mentes_Cel::aktiv_idk() as $id) {
            foreach ((array) ($allapot[$id]['varakozik'] ?? []) as $f) {
                if (is_string($f)) {
                    $varo[$f][] = $id;
                }
            }

            if ($kenyszer && !empty($allapot[$id]['varakozik'])) {
                SDH_Muhely_Mentes_Cel::allapot_ment($id, ['proba' => 0]);
            }
        }

        if ($varo === []) {
            delete_option(self::UJRA);

            return;
        }

        if (!self::zar_fog()) {
            return;
        }

        try {
            ksort($varo);

            foreach ($varo as $fajl => $idk) {
                if (!is_file(SDH_Muhely_Asszisztens_Tudas::mentes_mappa() . $fajl)) {
                    // A helyi mentés közben a megőrzés törölte: nincs mit feltölteni.
                    foreach ($idk as $id) {
                        $m = array_values(array_diff((array) (SDH_Muhely_Mentes_Cel::allapot()[$id]['varakozik'] ?? []), [$fajl]));
                        SDH_Muhely_Mentes_Cel::allapot_ment($id, ['varakozik' => $m]);
                    }

                    continue;
                }

                self::kulso_masolat($fajl, $idk);
            }
        } finally {
            delete_option(self::ZAR);
        }

        // Van még várakozó, és belefér a próbák száma? Akkor újabb próba 15 perc múlva.
        $allapot = SDH_Muhely_Mentes_Cel::allapot();
        $tovabb  = false;

        foreach (SDH_Muhely_Mentes_Cel::aktiv_idk() as $id) {
            if (!empty($allapot[$id]['varakozik']) && (int) ($allapot[$id]['proba'] ?? 0) < self::MAX_PROBA) {
                $tovabb = true;
            }
        }

        if ($tovabb) {
            update_option(self::UJRA, time() + 15 * MINUTE_IN_SECONDS, 'yes');
        } else {
            delete_option(self::UJRA);
        }
    }

    /**
     * Emberi nyelvű összegzés a külső másolatokról.
     *
     * @param array<string, string> $kulso
     */
    public static function kulso_osszegzes(array $kulso): string
    {
        if ($kulso === []) {
            return '';
        }

        $nevek = SDH_Muhely_Mentes_Cel::tipusok();
        $reszek = [];

        foreach ($kulso as $id => $hiba) {
            $nev = $id === '*' ? 'Külső másolat' : ($nevek[$id]['nev'] ?? $id);
            $reszek[] = $nev . ' – ' . ($hiba === '' ? 'kész' : 'NEM sikerült: ' . $hiba);
        }

        return ' Külső másolat: ' . implode('; ', $reszek) . '.';
    }

    /* =================================================================
     * Beállítások › Mentés (a felület a Felulet osztályban)
     * ============================================================== */

    public static function beallitas_doboz(): void
    {
        SDH_Muhely_Mentes_Felulet::doboz();
    }

    public static function beallitas_mentes(): void
    {
        SDH_Muhely_Mentes_Felulet::mentes();
    }
}
