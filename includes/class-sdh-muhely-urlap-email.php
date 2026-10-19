<?php
/**
 * Űrlapok e-mailben és a munkalap Fájlok lapfüle (0.99.4).
 *
 * 1. Minden fő űrlap (munkalap, napi árukiadás, rendelés, ügyfél, eszköz, termék, szolgáltatás)
 *    alsó gombsorának utolsó eleme az „E-mail” gomb: az űrlap adatai (a lapoknál a nyomtatvány
 *    PDF-je is) azonnal továbbíthatók. Űrlaponként ki-be kapcsolható.
 * 2. A munkalap lapfül-sorának utolsó eleme a „Fájlok”: kép és bármilyen dokumentum feltöltése,
 *    ugyanúgy, mint az eszköz felvételénél. A feltöltött dokumentumok egy gombbal elküldhetők a
 *    Beállításokban megadott e-mail címre.
 *
 * Beállítások › Űrlapok e-mailben.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Urlap_Email
{
    public const OPT = 'sdh_muhely_urlap_email';

    /** A feltöltő mező neve a munkalap Fájlok lapfülén (az Átvétel fotóié a „csatolmany”). */
    public const MEZO = 'fajl_csatolmany';

    /** A Fájlok lapfül csatolmány-típusa. */
    public const TIPUS = 'munkalap_fajl';

    /** Egy levél csatolmányainak felső határa (bájt). */
    private const MAX_LEVEL = 20 * 1024 * 1024;

    /** @var array<string, mixed>|null */
    private static ?array $cache = null;

    public static function init(): void
    {
        add_action('wp_ajax_sdh_muhely_urlap_email_elo', [self::class, 'ajax_elo']);
        add_action('wp_ajax_sdh_muhely_urlap_email_kuld', [self::class, 'ajax_kuld']);
        add_action('wp_ajax_sdh_muhely_munkalap_fajlok_kuld', [self::class, 'ajax_fajlok_kuld']);
        add_action('sdh_muhely_munkalap_mentve', [self::class, 'mentes_utan'], 12, 2);

        add_action('plugins_loaded', static function (): void {
            if (class_exists('SDH_Muhely_Beallitasok')) {
                SDH_Muhely_Beallitasok::regisztral('urlap_email', [
                    'cim'     => 'Űrlapok e-mailben',
                    'csoport' => 'munkalap',
                    'leiras'  => 'Az E-mail gomb az űrlapokon (munkalap, árukiadás, rendelés, ügyfél, eszköz, termék, szolgáltatás), a munkalap Fájlok lapfüle és a dokumentumok e-mail címe.',
                    'rajz'    => [self::class, 'beallitas_doboz'],
                    'ment'    => [self::class, 'beallitas_mentes'],
                ]);
            }
        });
    }

    /* =================================================================
     * Beállítás
     * ============================================================== */

    /** @return array<string, string> űrlap => felirat */
    public static function urlapok(): array
    {
        return [
            'munkalap'     => 'Munkalap',
            'arukiadas'    => 'Napi árukiadás',
            'rendeles'     => 'Rendelés',
            'ugyfel'       => 'Ügyfél',
            'eszkoz'       => 'Eszköz',
            'termek'       => 'Termék',
            'szolgaltatas' => 'Szolgáltatás',
        ];
    }

    /** @return array<string, mixed> */
    public static function beallitas(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $nyers = get_option(self::OPT, []);
        $nyers = is_array($nyers) ? $nyers : [];
        $gomb  = [];

        foreach (array_keys(self::urlapok()) as $u) {
            $gomb[$u] = isset($nyers['gomb'][$u]) ? (int) (bool) $nyers['gomb'][$u] : 1;
        }

        return self::$cache = [
            'gomb'       => $gomb,
            'pdf'        => isset($nyers['pdf']) ? (int) (bool) $nyers['pdf'] : 1,
            'fajlok_ful' => isset($nyers['fajlok_ful']) ? (int) (bool) $nyers['fajlok_ful'] : 1,
            'dok_cim'    => is_email((string) ($nyers['dok_cim'] ?? '')) ? (string) $nyers['dok_cim'] : '',
            'kuldo'      => sanitize_key((string) ($nyers['kuldo'] ?? '')),
        ];
    }

    public static function urit(): void
    {
        self::$cache = null;
    }

    public static function gomb_be(string $urlap): bool
    {
        return !empty(self::beallitas()['gomb'][$urlap]);
    }

    public static function fajlok_be(): bool
    {
        return !empty(self::beallitas()['fajlok_ful']) && class_exists('SDH_Muhely_Csatolmany');
    }

    /** @return array<string, mixed> a böngészőnek */
    public static function js(): array
    {
        $b = self::beallitas();

        return ['gomb' => $b['gomb'], 'dokCim' => $b['dok_cim'] !== ''];
    }

    /** A cég levelezőfiókjai (Levelezés modul), amelyekből a levél mehet. @return array<string, string> */
    private static function fiokok(): array
    {
        return class_exists('SDH_Muhely_Levelezes') && SDH_Muhely_Modulok::be('levelezes') ? SDH_Muhely_Levelezes::kuldo_fiokok() : [];
    }

    private static function kuldo(): string
    {
        $k = (string) self::beallitas()['kuldo'];
        $f = self::fiokok();

        if ($k === 'wp') {
            return '';
        }

        return isset($f[$k]) ? $k : (string) (array_key_first($f) ?? '');
    }

    public static function beallitas_doboz(): void
    {
        $b      = self::beallitas();
        $fiokok = self::fiokok();
        ?>
        <div class="sdh-doboz">
            <h2 class="sdh-doboz__cim">Űrlapok e-mailben</h2>
            <p class="sdh-sugo">
                Az űrlapok alsó gombsorának utolsó gombja: <strong>E-mail</strong>. Az űrlap adatai (a munkalapnál, árukiadásnál,
                rendelésnél a nyomtatvány PDF-je is) azonnal továbbíthatók bárkinek. Ahol nem kell, kapcsold ki.
            </p>
            <input type="hidden" name="urlap_email_jelen" value="1">
            <div class="sdh-okos-kapcsolok">
                <?php foreach (self::urlapok() as $u => $cim) : ?>
                    <div class="sdh-okos-kapcsolo">
                        <label class="sdh-kapcsolo" title="<?php echo esc_attr($cim); ?>">
                            <input type="checkbox" name="urlap_email[gomb][<?php echo esc_attr($u); ?>]" value="1" <?php checked(!empty($b['gomb'][$u])); ?>>

                        </label>
                        <div><strong><?php echo esc_html($cim); ?></strong> <span class="sdh-mezo__sugo">– E-mail gomb az űrlapon</span></div>
                    </div>
                <?php endforeach; ?>
                <div class="sdh-okos-kapcsolo">
                    <label class="sdh-kapcsolo" title="PDF csatolása">
                        <input type="checkbox" name="urlap_email[pdf]" value="1" <?php checked(!empty($b['pdf'])); ?>>

                    </label>
                    <div><strong>A lapok nyomtatványa PDF-ben is</strong> <span class="sdh-mezo__sugo">– munkalap, árukiadás, rendelés</span></div>
                </div>
            </div>
        </div>

        <div class="sdh-doboz">
            <h2 class="sdh-doboz__cim">A munkalap Fájlok lapfüle</h2>
            <p class="sdh-sugo">
                A munkalap lapfüleinek utolsó eleme: kép és dokumentum feltöltése (fotó, PDF, számla, nyilatkozat), mint az eszköznél.
                Az ott feltöltött összes dokumentum egy gombnyomással elküldhető az alábbi címre.
            </p>
            <div class="sdh-okos-kapcsolok">
                <div class="sdh-okos-kapcsolo">
                    <label class="sdh-kapcsolo" title="Fájlok lapfül">
                        <input type="checkbox" name="urlap_email[fajlok_ful]" value="1" <?php checked(!empty($b['fajlok_ful'])); ?>>

                    </label>
                    <div><strong>Fájlok lapfül a munkalapon</strong></div>
                </div>
            </div>
            <div class="sdh-mezo">
                <label for="urlap_email_dok">A dokumentumok e-mail címe</label>
                <input type="email" id="urlap_email_dok" name="urlap_email[dok_cim]" maxlength="190" autocomplete="off"
                       value="<?php echo esc_attr((string) $b['dok_cim']); ?>" placeholder="pl. dokumentumok@ceg.hu">
                <p class="sdh-mezo__sugo">Ide megy a munkalap Fájlok lapfülén a „Dokumentumok elküldése” gomb. Üresen a gomb nem látszik.</p>
            </div>
            <?php if ($fiokok !== []) : ?>
                <div class="sdh-mezo">
                    <label for="urlap_email_kuldo">A levelek innen mennek</label>
                    <select id="urlap_email_kuldo" name="urlap_email[kuldo]">
                        <?php foreach ($fiokok as $k => $nev) : ?>
                            <option value="<?php echo esc_attr((string) $k); ?>" <?php selected(self::kuldo(), (string) $k); ?>><?php echo esc_html((string) $nev); ?></option>
                        <?php endforeach; ?>
                        <option value="wp" <?php selected((string) $b['kuldo'], 'wp'); ?>>A szerver levélküldője</option>
                    </select>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function beallitas_mentes(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- a Beállítások ellenőrizte.
        if (!isset($_POST['urlap_email_jelen'])) {
            return;
        }

        $be = isset($_POST['urlap_email']) && is_array($_POST['urlap_email']) ? wp_unslash($_POST['urlap_email']) : [];
        // phpcs:enable
        $gomb = [];

        foreach (array_keys(self::urlapok()) as $u) {
            $gomb[$u] = !empty($be['gomb'][$u]) ? 1 : 0;
        }

        $cim = sanitize_email((string) ($be['dok_cim'] ?? ''));

        update_option(self::OPT, [
            'gomb'       => $gomb,
            'pdf'        => !empty($be['pdf']) ? 1 : 0,
            'fajlok_ful' => !empty($be['fajlok_ful']) ? 1 : 0,
            'dok_cim'    => is_email($cim) ? $cim : '',
            'kuldo'      => sanitize_key((string) ($be['kuldo'] ?? '')),
        ], false);

        self::$cache = null;
    }

    /* =================================================================
     * Az E-mail gomb
     * ============================================================== */

    /** Az űrlap gombsorának utolsó eleme (mentett rekordnál, ha be van kapcsolva). */
    public static function gomb(string $urlap, int $id): string
    {
        if ($id < 1 || !isset(self::urlapok()[$urlap]) || !self::gomb_be($urlap)) {
            return '';
        }

        return sprintf(
            '<button type="button" class="sdh-gomb sdh-gomb--vilagos sdh-uemail-gomb" data-sdh-urlap-email="%s" data-id="%d" title="%s">'
            . '<svg viewBox="0 0 20 20" width="15" height="15" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="2.5" y="4.5" width="15" height="11" rx="2"/><path d="M3 5.5l7 5.5 7-5.5"/></svg>'
            . '<span>E-mail</span></button>',
            esc_attr($urlap),
            $id,
            esc_attr('Az űrlap továbbítása e-mailben')
        );
    }

    /* =================================================================
     * Az űrlap adatai levélnek
     * ============================================================== */

    /**
     * @return array{cim: string, targy: string, sorok: array<int, string>, cimzett: string, pdf_tipus: string}|null
     */
    public static function adatlap(string $urlap, int $id): ?array
    {
        global $wpdb;

        $t   = static fn (string $n): string => SDH_Muhely_Schema::tabla($n);
        $sor = static fn (string $cimke, $ertek): string => trim((string) $ertek) !== '' ? $cimke . ': ' . trim((string) $ertek) : '';

        switch ($urlap) {
            case 'munkalap':
            case 'arukiadas':
            case 'rendeles':
                $l = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $t('munkalap') . ' WHERE id = %d', $id)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

                if (!$l) {
                    return null;
                }

                $u = (int) $l->ugyfel_id > 0 ? $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $t('ugyfel') . ' WHERE id = %d', (int) $l->ugyfel_id)) : null; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $e = (int) $l->eszkoz_id > 0 ? $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $t('eszkoz') . ' WHERE id = %d', (int) $l->eszkoz_id)) : null; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $allapotok = SDH_Muhely_Munkalap::allapotok();
                $nev       = ['munkalap' => 'Munkalap', 'arukiadas' => 'Napi árukiadás', 'rendeles' => 'Rendelés'][$urlap];
                $sorok     = [
                    $sor('Sorszám', (int) $l->munkalap_szam > 0 ? (string) $l->munkalap_szam : ''),
                    $sor('Állapot', (string) ($allapotok[(string) $l->allapot]['nev'] ?? $l->allapot)),
                    $sor('Készült', (string) $l->keszult),
                    $sor('Határidő', (string) $l->hatarido),
                    $sor('Ügyfél', $u ? (string) $u->nev : (string) $l->nev),
                    $sor('Telefon', $u ? (string) $u->telefon : ''),
                    $sor('E-mail', $u ? (string) $u->email : ''),
                    $sor('Cím', $u ? SDH_Muhely_Ugyfel::cim_szoveg($u) : ''),
                    $sor('Eszköz', $e ? trim($e->gyarto . ' ' . $e->tipus . ' ' . $e->megnevezes) : ''),
                    $sor('IMEI', $e ? (string) $e->imei : ''),
                    $sor('Gyári szám', $e ? (string) $e->sorozatszam : ''),
                    $sor('Rendelésszám', (string) ($l->rend_szam ?? '')),
                    $sor('Megjegyzés', (string) $l->ugyfel_megjegyzes),
                ];

                $tetelek = (array) $wpdb->get_results($wpdb->prepare('SELECT tipus, megnevezes, mennyiseg, me, brutto_ertek FROM ' . $t('munkalap_tetel') . ' WHERE munkalap_id = %d ORDER BY sorrend, id', $id)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

                if ($tetelek !== []) {
                    $sorok[] = '';
                    $sorok[] = 'Tételek:';

                    foreach ($tetelek as $tt) {
                        $sorok[] = ' – ' . $tt->megnevezes . ' · ' . rtrim(rtrim(number_format((float) $tt->mennyiseg, 2, ',', ''), '0'), ',') . ' ' . $tt->me
                            . ((float) $tt->brutto_ertek != 0.0 ? ' · ' . number_format((float) $tt->brutto_ertek, 0, ',', ' ') . ' Ft' : '');
                    }
                }

                $sorok[] = '';
                $sorok[] = $sor('Bruttó összesen', number_format((float) $l->brutto_ertek, 0, ',', ' ') . ' Ft');
                $sorok[] = $sor('Fizetett', number_format((float) $l->fizetett, 0, ',', ' ') . ' Ft');

                $szam = (int) $l->munkalap_szam > 0 ? ' ' . $l->munkalap_szam : '';

                return [
                    'cim'       => $nev . $szam,
                    'targy'     => $nev . $szam . ($u ? ' – ' . $u->nev : ''),
                    'sorok'     => $sorok,
                    'cimzett'   => $u ? (string) $u->email : '',
                    'pdf_tipus' => ['munkalap' => 'munkalap', 'arukiadas' => 'eladas', 'rendeles' => 'rendeles'][$urlap],
                ];

            case 'ugyfel':
                $u = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $t('ugyfel') . ' WHERE id = %d', $id)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

                return $u ? [
                    'cim'       => 'Ügyfél: ' . $u->nev,
                    'targy'     => 'Ügyféladatok – ' . $u->nev,
                    'sorok'     => [
                        $sor('Név', (string) $u->nev),
                        $sor('Kapcsolattartó', (string) $u->kapcsolattarto),
                        $sor('Ügyfélszám', (string) $u->ugyfel_szam),
                        $sor('Adószám', (string) $u->adoszam),
                        $sor('Telefon', (string) $u->telefon),
                        $sor('Telefon 2', (string) $u->telefon2),
                        $sor('E-mail', (string) $u->email),
                        $sor('Cím', SDH_Muhely_Ugyfel::cim_szoveg($u)),
                        $sor('Megjegyzés', (string) $u->megjegyzes),
                    ],
                    'cimzett'   => (string) $u->email,
                    'pdf_tipus' => '',
                ] : null;

            case 'eszkoz':
                $e = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $t('eszkoz') . ' WHERE id = %d', $id)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

                if (!$e) {
                    return null;
                }

                $u = (int) $e->ugyfel_id > 0 ? $wpdb->get_row($wpdb->prepare('SELECT nev, email FROM ' . $t('ugyfel') . ' WHERE id = %d', (int) $e->ugyfel_id)) : null; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $nev = trim($e->gyarto . ' ' . $e->tipus) ?: (string) $e->megnevezes;

                // A feloldó kód és a minta szándékosan nem kerül a levélbe.
                return [
                    'cim'       => 'Eszköz: ' . $nev,
                    'targy'     => 'Eszközadatok – ' . $nev,
                    'sorok'     => [
                        $sor('Kategória', (string) $e->kategoria),
                        $sor('Gyártó', (string) $e->gyarto),
                        $sor('Típus', (string) $e->tipus),
                        $sor('Megnevezés', (string) $e->megnevezes),
                        $sor('IMEI', (string) $e->imei),
                        $sor('IMEI 2', (string) $e->imei2),
                        $sor('Gyári szám', (string) $e->sorozatszam),
                        $sor('Modellszám', (string) $e->modell_szam),
                        $sor('Szín', (string) $e->szin),
                        $sor('Tartozékok', (string) $e->tartozekok),
                        $sor('Garancia', (int) $e->garancias === 1 ? 'garanciális' : ''),
                        $sor('Ügyfél', $u ? (string) $u->nev : ''),
                    ],
                    'cimzett'   => $u ? (string) $u->email : '',
                    'pdf_tipus' => '',
                ];

            case 'termek':
                $p = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $t('termek') . ' WHERE id = %d', $id)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

                return $p ? [
                    'cim'       => 'Termék: ' . $p->megnevezes,
                    'targy'     => 'Termék – ' . $p->megnevezes,
                    'sorok'     => [
                        $sor('Megnevezés', (string) $p->megnevezes),
                        $sor('Kategória', (string) $p->kategoria),
                        $sor('Cikkszám', (string) $p->cikkszam),
                        $sor('Termékkód', (string) $p->termekkod),
                        $sor('Vonalkód', (string) $p->vonalkod),
                        $sor('Gyári szám', (string) $p->gyari_szam),
                        $sor('Készlet', (int) $p->keszletkezeles === 1 ? rtrim(rtrim(number_format((float) $p->keszlet, 2, ',', ''), '0'), ',') . ' ' . $p->me : ''),
                        $sor('Bruttó ár', (float) $p->brutto_ar > 0 ? number_format((float) $p->brutto_ar, 0, ',', ' ') . ' Ft' : ''),
                        $sor('Leírás', (string) $p->leiras),
                    ],
                    'cimzett'   => '',
                    'pdf_tipus' => '',
                ] : null;

            case 'szolgaltatas':
                $s = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $t('szolgaltatas') . ' WHERE id = %d', $id)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

                return $s ? [
                    'cim'       => 'Szolgáltatás: ' . $s->nev,
                    'targy'     => 'Szolgáltatás – ' . $s->nev,
                    'sorok'     => [
                        $sor('Megnevezés', (string) $s->nev),
                        $sor('Kategória', (string) $s->kategoria),
                        $sor('Kód', (string) $s->kod),
                        $sor('Bruttó ár', (float) $s->brutto_ar > 0 ? number_format((float) $s->brutto_ar, 0, ',', ' ') . ' Ft' : ''),
                        $sor('Megjegyzés', (string) $s->megjegyzes),
                    ],
                    'cimzett'   => '',
                    'pdf_tipus' => '',
                ] : null;
        }

        return null;
    }

    /**
     * Levél csatolmányokkal: a cég levelezőfiókjából, vagy a szerver levélküldőjével.
     *
     * @param array<int, string>                                          $cimek
     * @param array<int, array{nev: string, tartalom: string, tipus: string}> $csatolmanyok
     * @return true|string
     */
    public static function level(array $cimek, string $targy, string $szoveg, array $csatolmanyok = [])
    {
        $fiok = self::kuldo();

        if ($fiok !== '') {
            return SDH_Muhely_Levelezes::egyszeru_kuldes($fiok, $cimek, $targy, $szoveg, $csatolmanyok);
        }

        $hiba    = '';
        $figyelo = static function ($h) use (&$hiba): void {
            $hiba = is_wp_error($h) ? $h->get_error_message() : '';
        };
        $csatol  = static function ($levelkuldo) use ($csatolmanyok): void {
            foreach ($csatolmanyok as $c) {
                $levelkuldo->addStringAttachment((string) $c['tartalom'], (string) $c['nev'], 'base64', (string) $c['tipus']);
            }
        };

        add_action('wp_mail_failed', $figyelo);
        add_action('phpmailer_init', $csatol);

        try {
            $ok = wp_mail($cimek, $targy, $szoveg);
        } catch (Throwable $h) {
            $ok   = false;
            $hiba = $h->getMessage();
        }

        remove_action('phpmailer_init', $csatol);
        remove_action('wp_mail_failed', $figyelo);

        return $ok ? true : ($hiba !== '' ? $hiba : 'a szerver levélküldője nem fogadta el (nincs beállítva a levélküldés?).');
    }

    /** @return array<int, string> a beírt címek (vessző, pontosvessző, szóköz) – csak az érvényesek */
    public static function cimek(string $nyers): array
    {
        $ki = [];

        foreach (preg_split('/[\s,;]+/', $nyers, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $c) {
            $c = sanitize_email($c);

            if ($c !== '' && is_email($c) && !in_array($c, $ki, true)) {
                $ki[] = $c;
            }
        }

        return array_slice($ki, 0, 10);
    }

    /** A levél szövege: a kitöltött sorok (az üres adatok nélkül). @param array{cim: string, sorok: array<int, string>} $a */
    public static function szoveg(array $a): string
    {
        return $a['cim'] . "\n\n" . implode("\n", array_values(array_filter($a['sorok'], static fn (string $s): bool => $s !== '')));
    }

    private static function jog(): void
    {
        check_ajax_referer('sdh_muhely_modal');

        if (!current_user_can(SDH_Muhely_Admin_UI::jog())) {
            wp_send_json_error(['uzenet' => 'Nincs jogosultságod ehhez.'], 403);
        }
    }

    /** A naplóhoz: melyik rekordtípus. */
    private static function naplo_tipus(string $urlap): string
    {
        return in_array($urlap, ['munkalap', 'arukiadas', 'rendeles'], true) ? 'munkalap' : $urlap;
    }

    /** Az ablak kitöltése: címzett, tárgy, szöveg, van-e PDF. */
    public static function ajax_elo(): void
    {
        self::jog();

        $urlap = isset($_POST['urlap']) ? sanitize_key(wp_unslash($_POST['urlap'])) : '';
        $id    = isset($_POST['id']) ? (int) $_POST['id'] : 0;

        if (!self::gomb_be($urlap)) {
            wp_send_json_error(['uzenet' => 'Ennél az űrlapnál az e-mailes továbbítás ki van kapcsolva (Beállítások › Űrlapok e-mailben).']);
        }

        $a = self::adatlap($urlap, $id);

        if ($a === null) {
            wp_send_json_error(['uzenet' => 'Nincs ilyen rekord (előbb mentsd el az űrlapot).']);
        }

        wp_send_json_success([
            'cimzett' => $a['cimzett'],
            'targy'   => $a['targy'],
            'szoveg'  => self::szoveg($a),
            'pdf'     => $a['pdf_tipus'] !== '' && !empty(self::beallitas()['pdf']),
            'cim'     => $a['cim'],
        ]);
    }

    public static function ajax_kuld(): void
    {
        self::jog();

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- a jog() ellenőrizte.
        $urlap  = isset($_POST['urlap']) ? sanitize_key(wp_unslash($_POST['urlap'])) : '';
        $id     = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $cimek  = self::cimek(isset($_POST['cimzett']) ? (string) wp_unslash($_POST['cimzett']) : '');
        $targy  = isset($_POST['targy']) ? sanitize_text_field(wp_unslash($_POST['targy'])) : '';
        $szoveg = isset($_POST['szoveg']) ? sanitize_textarea_field(wp_unslash($_POST['szoveg'])) : '';
        $pdf_be = !empty($_POST['pdf']);
        // phpcs:enable

        if (!self::gomb_be($urlap)) {
            wp_send_json_error(['uzenet' => 'Ennél az űrlapnál az e-mailes továbbítás ki van kapcsolva.']);
        }

        if ($cimek === []) {
            wp_send_json_error(['uzenet' => 'Adj meg legalább egy érvényes e-mail címet.']);
        }

        $a = self::adatlap($urlap, $id);

        if ($a === null) {
            wp_send_json_error(['uzenet' => 'Nincs ilyen rekord.']);
        }

        $targy  = $targy !== '' ? $targy : $a['targy'];
        $szoveg = trim($szoveg) !== '' ? $szoveg : self::szoveg($a);
        $csat   = [];

        if ($pdf_be && $a['pdf_tipus'] !== '' && !empty(self::beallitas()['pdf']) && class_exists('SDH_Muhely_Nyomtatvany')) {
            $r = SDH_Muhely_Nyomtatvany::keszit($a['pdf_tipus'], ['id' => $id], 1);

            if (is_array($r)) {
                $csat[] = ['nev' => (string) $r['nev'], 'tartalom' => (string) $r['pdf'], 'tipus' => 'application/pdf'];
            }
        }

        $ok = self::level($cimek, $targy, $szoveg, $csat);

        if ($ok !== true) {
            wp_send_json_error(['uzenet' => 'A levél nem ment el: ' . $ok]);
        }

        if (class_exists('SDH_Muhely_Valtozasnaplo')) {
            SDH_Muhely_Valtozasnaplo::esemeny(self::naplo_tipus($urlap), $id, $a['cim'] . ': e-mailben továbbítva', ['Címzett' => implode(', ', $cimek), 'Tárgy' => $targy, 'PDF' => $csat !== [] ? 'igen' : 'nem']);
        }

        wp_send_json_success(['uzenet' => 'Elküldve: ' . implode(', ', $cimek) . ($csat !== [] ? ' (PDF-fel)' : '')]);
    }

    /* =================================================================
     * A munkalap Fájlok lapfüle
     * ============================================================== */

    /** A lapfül tartalma. */
    public static function ful(?object $l): void
    {
        $id = $l !== null ? (int) $l->id : 0;
        $b  = self::beallitas();
        ?>
        <input type="hidden" name="fajlok_jelen" value="1">
        <div class="sdh-fajlok">
            <p class="sdh-mezo__sugo">Kép és bármilyen dokumentum (PDF, számla, nyilatkozat, Word, Excel) a munkalaphoz. A fájlok a lap mentésekor kerülnek fel.</p>
            <?php SDH_Muhely_Csatolmany::panel(self::TIPUS, $id, self::MEZO); ?>
            <?php if ($id > 0 && $b['dok_cim'] !== '') : ?>
                <div class="sdh-fajlok__kuld">
                    <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-fajlok-kuld data-id="<?php echo (int) $id; ?>">
                        Dokumentumok elküldése: <?php echo esc_html((string) $b['dok_cim']); ?>
                    </button>
                    <span class="sdh-mezo__sugo" data-sdh-fajlok-allapot></span>
                </div>
            <?php elseif ($id > 0 && SDH_Muhely_Admin_UI::admin_e()) : ?>
                <p class="sdh-mezo__sugo">A dokumentumok e-mailes küldéséhez add meg a címet: Beállítások › Űrlapok e-mailben.</p>
            <?php endif; ?>
        </div>
        <?php
    }

    /** A lapfül feliratának száma. */
    public static function darab(int $id): int
    {
        return $id > 0 && class_exists('SDH_Muhely_Csatolmany') ? SDH_Muhely_Csatolmany::darab(self::TIPUS, $id) : 0;
    }

    /** Mentés ELŐTT: a Fájlok lapfül fájljai rendben vannak-e. */
    public static function ellenoriz(): ?string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if (!empty($_FILES[self::MEZO]['name']) && class_exists('SDH_Muhely_Csatolmany')) {
            return SDH_Muhely_Csatolmany::ellenoriz(self::MEZO);
        }

        return null;
    }

    /** Mentés UTÁN: a feltöltések és a törlésre jelöltek. */
    public static function mentes_utan(int $id, $regi = null): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if (!isset($_POST['fajlok_jelen']) || !class_exists('SDH_Muhely_Csatolmany')) {
            return;
        }

        $r = SDH_Muhely_Csatolmany::feldolgoz(self::TIPUS, $id, self::MEZO);

        if (($r['hozzaadva'] > 0 || $r['torolve'] > 0) && class_exists('SDH_Muhely_Valtozasnaplo')) {
            SDH_Muhely_Valtozasnaplo::esemeny('munkalap', $id, 'Fájlok: ' . $r['hozzaadva'] . ' feltöltve, ' . $r['torolve'] . ' törölve');
        }
    }

    /** A munkalap összes feltöltött dokumentuma (Fájlok + Átvétel fotói) a beállított címre. */
    public static function ajax_fajlok_kuld(): void
    {
        global $wpdb;

        self::jog();

        $id  = isset($_POST['id']) ? (int) $_POST['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $cim = (string) self::beallitas()['dok_cim'];

        if ($cim === '') {
            wp_send_json_error(['uzenet' => 'Nincs megadva a dokumentumok e-mail címe (Beállítások › Űrlapok e-mailben).']);
        }

        $l = $wpdb->get_row($wpdb->prepare('SELECT id, munkalap_szam, ugyfel_id, nev FROM ' . SDH_Muhely_Schema::tabla('munkalap') . ' WHERE id = %d', $id)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        if (!$l) {
            wp_send_json_error(['uzenet' => 'Nincs ilyen munkalap.']);
        }

        $fajlok = array_merge(SDH_Muhely_Csatolmany::lista(self::TIPUS, $id), SDH_Muhely_Csatolmany::lista('munkalap', $id));

        if ($fajlok === []) {
            wp_send_json_error(['uzenet' => 'Ezen a lapon még nincs feltöltött dokumentum (előbb mentsd a lapot a fájlokkal).']);
        }

        $csat   = [];
        $osszes = 0;
        $kimarad = [];

        foreach ($fajlok as $f) {
            $ut = SDH_Muhely_Csatolmany::fajl_ut($f);

            if ($ut === '' || !is_readable($ut)) {
                continue;
            }

            $meret = (int) filesize($ut);

            if ($osszes + $meret > self::MAX_LEVEL) {
                $kimarad[] = (string) $f->eredeti_nev;
                continue;
            }

            $osszes += $meret;
            $csat[]  = ['nev' => (string) $f->eredeti_nev, 'tartalom' => (string) file_get_contents($ut), 'tipus' => (string) $f->mime]; // phpcs:ignore WordPress.WP.AlternativeFunctions
        }

        if ($csat === []) {
            wp_send_json_error(['uzenet' => 'A fájlok nem olvashatók, vagy együtt túl nagyok egy levélhez.']);
        }

        $szam   = (int) $l->munkalap_szam > 0 ? (string) $l->munkalap_szam : '#' . $id;
        $nev    = (int) $l->ugyfel_id > 0 ? SDH_Muhely_Ugyfel::nev((int) $l->ugyfel_id) : (string) $l->nev;
        $targy  = 'Munkalap ' . $szam . ' – dokumentumok' . ($nev !== '' ? ' – ' . $nev : '');
        $szoveg = 'A(z) ' . $szam . ' munkalap feltöltött dokumentumai (' . count($csat) . ' fájl) csatolva.'
            . ($kimarad !== [] ? "\n\nMéretkorlát miatt kimaradt: " . implode(', ', $kimarad) : '');

        $ok = self::level([$cim], $targy, $szoveg, $csat);

        if ($ok !== true) {
            wp_send_json_error(['uzenet' => 'A levél nem ment el: ' . $ok]);
        }

        if (class_exists('SDH_Muhely_Valtozasnaplo')) {
            SDH_Muhely_Valtozasnaplo::esemeny('munkalap', $id, 'Dokumentumok e-mailben elküldve', ['Címzett' => $cim, 'Fájlok' => (string) count($csat)]);
        }

        wp_send_json_success(['uzenet' => count($csat) . ' fájl elküldve: ' . $cim . ($kimarad !== [] ? ' (' . count($kimarad) . ' kimaradt a méret miatt)' : '')]);
    }
}
