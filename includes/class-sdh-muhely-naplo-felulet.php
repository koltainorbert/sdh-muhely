<?php
/**
 * Napló – a változásnapló felülete (0.59).
 *
 * - **Napló menüpont** (adminisztrátornak): minden módosítás idővonalon,
 *   szűrőkkel (mi, ki, honnan, milyen művelet, időszak, munkalapszám,
 *   szöveg), lapozással, CSV-letöltéssel és az épség ellenőrzésével.
 * - **Napló ablak** egy rekordhoz (munkalap, árukiadás, rendelés, termék,
 *   ügyfél, eszköz, naptár, üzenőfal…): a rekord gombjáról nyílik, popupban,
 *   a kollégáknak is – amit látnak, annak a történetét is láthatják.
 * - **Beállítások › Változásnapló** és **Beállítások › Lezárt lapok**.
 *
 * A naplót írni nem lehet innen sem: se szerkesztő, se törlő gomb nincs.
 * Az adatot a SDH_Muhely_Valtozasnaplo adja.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Naplo_Felulet
{
    public const KULCS = 'naplo';

    /** A Napló ablakban egyszerre ennyi bejegyzés töltődik (a „Régebbiek" gomb hozza a többit). */
    private const ABLAK_LAP = 60;

    public static function init(): void
    {
        SDH_Muhely_Modulok::regisztral([
            'kulcs'      => self::KULCS,
            'cim'        => 'Napló',
            'render'     => [self::class, 'oldal'],
            'sorrend'    => 94,
            'csak_admin' => true,
        ]);

        add_action('wp_ajax_sdh_muhely_naplo_urlap', [self::class, 'ajax_ablak']);
        add_action('wp_ajax_sdh_muhely_naplo_tobb', [self::class, 'ajax_tobb']);
        add_action('wp_ajax_sdh_muhely_naplo_ellenoriz', [self::class, 'ajax_ellenoriz']);
        add_action('wp_ajax_sdh_muhely_naplo_csv', [self::class, 'ajax_csv']);

        add_action('admin_enqueue_scripts', [self::class, 'admin_eszkozok'], 22);
    }

    public static function admin_eszkozok(): void
    {
        $oldal = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if (!str_starts_with($oldal, SDH_Muhely_Admin_UI::FOMENU)) {
            return;
        }

        wp_enqueue_style('sdh-muhely-naplo', SDH_MUHELY_URL . 'assets/naplo.css', ['sdh-muhely-admin'], SDH_Muhely_Admin_UI::eszkoz_verzio('assets/naplo.css'));
        wp_enqueue_script('sdh-muhely-naplo', SDH_MUHELY_URL . 'assets/naplo.js', ['sdh-muhely-app'], SDH_Muhely_Admin_UI::eszkoz_verzio('assets/naplo.js'), true);
        wp_enqueue_script('sdh-muhely-matrica', SDH_MUHELY_URL . 'assets/matrica.js', ['sdh-muhely-app'], SDH_Muhely_Admin_UI::eszkoz_verzio('assets/matrica.js'), true);
    }

    /* =================================================================
     * A rekordok „Napló" gombja
     * ============================================================== */

    /**
     * A Napló ablakot nyitó gomb egy rekordhoz (id 0 = a típus összes rekordja,
     * pl. a teljes üzenőfal vagy naptár).
     */
    public static function gomb(string $tipus, int $id, string $felirat = 'Napló', string $osztaly = 'sdh-gomb sdh-gomb--vilagos'): string
    {
        return '<button type="button" class="' . esc_attr($osztaly) . ' sdh-naplo-gomb" data-sdh-naplo-nyit'
            . ' data-tipus="' . esc_attr(sanitize_key($tipus)) . '" data-id="' . (int) $id . '"'
            . ' title="Ki, mikor, mit módosított – a teljes előzmény">'
            . SDH_Muhely_Admin_UI::gomb_ikon('naplo') . '<span>' . esc_html($felirat) . '</span></button>';
    }

    /* =================================================================
     * A Napló ablak (popup)
     * ============================================================== */

    /** Láthatja-e a felhasználó ennek a rekordnak a naplóját. */
    private static function lathatja(string $tipus): bool
    {
        if (!current_user_can(SDH_Muhely_Admin_UI::jog())) {
            return false;
        }

        // A beállítások és a napló védelmi bejegyzései: csak adminisztrátornak.
        return !in_array($tipus, ['beallitas', 'naplo', 'valtozasnaplo'], true) || SDH_Muhely_Admin_UI::admin_e();
    }

    public static function ajax_ablak(): void
    {
        check_ajax_referer('sdh_muhely_modal');

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- fent ellenőrizve.
        $tipus = isset($_GET['tipus']) ? sanitize_key(wp_unslash((string) $_GET['tipus'])) : 'munkalap';
        $id    = isset($_GET['id']) ? max(0, (int) $_GET['id']) : 0;
        // phpcs:enable

        if (!self::lathatja($tipus)) {
            status_header(403);
            echo '<div class="sdh-uzenet sdh-uzenet--hiba">Nincs jogosultságod ehhez a naplóhoz.</div>';
            wp_die();
        }

        $V     = 'SDH_Muhely_Valtozasnaplo';
        $cimke = $id > 0 ? $V::rekord_cimke($tipus, $id) : $V::tabla_nev($tipus) . ' – minden bejegyzés';
        $sz    = ['rekord' => $tipus . ':' . $id];
        $l     = $V::lista($sz, 1, self::ABLAK_LAP);

        ?>
        <h2 class="sdh-modal__cim">Napló <span class="sdh-modal__cim-megj"><?php echo esc_html($cimke); ?></span></h2>

        <div class="sdh-naplo sdh-naplo--ablak" data-sdh-naplo-ablak data-sdh-naplo-rekord="<?php echo esc_attr($tipus . ':' . $id); ?>"
             data-tipus="<?php echo esc_attr($tipus); ?>" data-id="<?php echo (int) $id; ?>" data-osszes="<?php echo (int) $l['osszes']; ?>"
             data-cimke="<?php echo esc_attr($cimke); ?>">
            <div class="sdh-naplo__sav">
                <span class="sdh-naplo__db"><?php echo esc_html(self::db_szoveg($l['osszes'])); ?></span>
                <span class="sdh-naplo__sav-ter"></span>
                <?php if (class_exists('SDH_Muhely_Asszisztens') && SDH_Muhely_Modulok::be('asszisztens')) : ?>
                    <button type="button" class="sdh-gomb sdh-gomb--vilagos sdh-gomb--pill" data-sdh-naplo-ai
                            data-kerdes="<?php echo esc_attr('Mondd el röviden, időrendben, mi történt ezzel: ' . $cimke . '. Ki mit módosított, és van-e benne valami szokatlan?'); ?>">
                        <?php echo SDH_Muhely_Admin_UI::gomb_ikon('asszisztens'); // phpcs:ignore WordPress.Security.EscapeOutput ?><span>Mi történt? – kérdezd az asszisztenst</span>
                    </button>
                <?php endif; ?>
            </div>

            <div class="sdh-naplo__lista" data-sdh-naplo-lista>
                <?php self::kartyak($l['sorok'], $id > 0); ?>
            </div>

            <?php if ($l['osszes'] > self::ABLAK_LAP) : ?>
                <p class="sdh-naplo__tobb"><button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-naplo-tobb data-lap="2">Régebbiek</button></p>
            <?php endif; ?>

            <p class="sdh-sugo sdh-sugo--utolso">A naplót senki nem írhatja át és nem törölheti: minden bejegyzést aláírás véd.</p>
        </div>
        <?php

        wp_die();
    }

    /** A Napló ablak következő oldala (HTML-darab). */
    public static function ajax_tobb(): void
    {
        check_ajax_referer('sdh_muhely_modal');

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- fent ellenőrizve.
        $tipus = isset($_POST['tipus']) ? sanitize_key(wp_unslash((string) $_POST['tipus'])) : '';
        $id    = isset($_POST['id']) ? max(0, (int) $_POST['id']) : 0;
        $lap   = isset($_POST['lap']) ? max(2, (int) $_POST['lap']) : 2;
        // phpcs:enable

        if ($tipus === '' || !self::lathatja($tipus)) {
            wp_send_json_error(['uzenet' => 'Nincs jogosultságod ehhez a naplóhoz.'], 403);
        }

        $l = SDH_Muhely_Valtozasnaplo::lista(['rekord' => $tipus . ':' . $id], $lap, self::ABLAK_LAP);

        ob_start();
        self::kartyak($l['sorok'], $id > 0);

        wp_send_json_success([
            'html' => (string) ob_get_clean(),
            'van_meg' => $lap * self::ABLAK_LAP < $l['osszes'],
            'lap' => $lap + 1,
        ]);
    }

    private static function db_szoveg(int $db): string
    {
        return $db === 0 ? 'Még nincs bejegyzés.' : number_format($db, 0, ',', ' ') . ' bejegyzés';
    }

    /* =================================================================
     * Az idővonal kártyái
     * ============================================================== */

    /**
     * Idővonal: egy kártya = egy kérés változásai egy rekordon (ki, mikor,
     * honnan, mit). A napi elválasztó a dátumot mutatja.
     *
     * @param array<int, object> $sorok
     */
    public static function kartyak(array $sorok, bool $egy_rekord = false): void
    {
        $V = 'SDH_Muhely_Valtozasnaplo';

        if ($sorok === []) {
            echo '<p class="sdh-naplo__ures">Nincs a szűrőnek megfelelő bejegyzés.</p>';

            return;
        }

        $nap = '';

        foreach ($V::csoportosit($sorok) as $cs) {
            $f  = $cs['fej'];
            $n  = substr((string) $f->ido, 0, 10);

            if ($n !== $nap) {
                $nap = $n;
                echo '<div class="sdh-naplo__nap">' . esc_html(self::nap_felirat($n)) . '</div>';
            }

            $muveletek = array_unique(array_map(static fn ($s) => (string) $s->muvelet, $cs['sorok']));
            $fo_muv    = in_array('torles', $muveletek, true) ? 'torles' : (in_array('elutasitva', $muveletek, true) ? 'elutasitva' : (in_array('vedelem', $muveletek, true) ? 'vedelem' : (in_array('letrehozas', $muveletek, true) ? 'letrehozas' : (string) $f->muvelet)));
            ?>
            <article class="sdh-naplo__kartya sdh-naplo__kartya--<?php echo esc_attr($fo_muv); ?>" data-keres="<?php echo esc_attr((string) $f->keres); ?>">
                <header class="sdh-naplo__fej">
                    <time class="sdh-naplo__ido" datetime="<?php echo esc_attr((string) $f->ido); ?>"><?php echo esc_html(substr((string) $f->ido, 11, 5)); ?></time>
                    <span class="sdh-naplo__ki"><?php echo esc_html((string) $f->felhasznalo_nev); ?></span>
                    <span class="sdh-naplo__forras sdh-naplo__forras--<?php echo esc_attr((string) $f->forras); ?>"><?php echo esc_html($V::forras_nev((string) $f->forras)); ?></span>
                    <?php if (!$egy_rekord || (string) $f->fo_tipus !== 'munkalap') : ?>
                        <span class="sdh-naplo__rekord"><?php echo esc_html($V::rekord_cimke((string) $f->fo_tipus, (int) $f->fo_id)); ?></span>
                    <?php endif; ?>
                </header>

                <?php if ((string) $f->megjegyzes !== '') : ?>
                    <p class="sdh-naplo__megj"><?php echo esc_html((string) $f->megjegyzes); ?></p>
                <?php endif; ?>

                <ul class="sdh-naplo__valtozasok">
                    <?php foreach ($cs['sorok'] as $s) : ?>
                        <?php self::valtozas_sor($s); ?>
                    <?php endforeach; ?>
                </ul>
            </article>
            <?php
        }
    }

    private static function valtozas_sor(object $s): void
    {
        $V   = 'SDH_Muhely_Valtozasnaplo';
        $muv = (string) $s->muvelet;
        $r   = $V::reszletek($s);
        $mi  = $V::tabla_nev((string) $s->tabla);

        ?>
        <li class="sdh-naplo__v sdh-naplo__v--<?php echo esc_attr($muv); ?>">
            <span class="sdh-naplo__muv"><?php echo esc_html($V::muvelet_nev($muv)); ?></span>
            <span class="sdh-naplo__mi"><?php echo esc_html(in_array($muv, ['esemeny', 'nyomtatas', 'elutasitva', 'vedelem', 'tomeges', 'nyers', 'letrehozas', 'torles'], true) ? (string) $s->osszegzes : $mi); ?></span>

            <?php if ($muv === 'modositas' || $muv === 'beallitas') : ?>
                <dl class="sdh-naplo__mezok">
                    <?php foreach ($r as $x) : ?>
                        <div class="sdh-naplo__mezo">
                            <dt><?php echo esc_html($x['mezo']); ?></dt>
                            <dd><del><?php echo esc_html($x['regi']); ?></del><span class="sdh-naplo__nyil" aria-hidden="true">→</span><ins><?php echo esc_html($x['uj']); ?></ins></dd>
                        </div>
                    <?php endforeach; ?>
                </dl>
            <?php elseif (in_array($muv, ['letrehozas', 'torles'], true) && $r !== []) : ?>
                <details class="sdh-naplo__reszlet">
                    <summary>Minden adata</summary>
                    <dl class="sdh-naplo__mezok">
                        <?php foreach ($r as $x) : ?>
                            <div class="sdh-naplo__mezo">
                                <dt><?php echo esc_html($x['mezo']); ?></dt>
                                <dd><?php echo esc_html($muv === 'torles' ? $x['regi'] : $x['uj']); ?></dd>
                            </div>
                        <?php endforeach; ?>
                    </dl>
                </details>
            <?php elseif ($r !== []) : ?>
                <dl class="sdh-naplo__mezok">
                    <?php foreach ($r as $x) : ?>
                        <div class="sdh-naplo__mezo"><dt><?php echo esc_html($x['mezo']); ?></dt><dd><?php echo esc_html($x['uj']); ?></dd></div>
                    <?php endforeach; ?>
                </dl>
            <?php endif; ?>
        </li>
        <?php
    }

    private static function nap_felirat(string $nap): string
    {
        if ($nap === current_time('Y-m-d')) {
            return 'Ma – ' . str_replace('-', '.', $nap) . '.';
        }

        if ($nap === wp_date('Y-m-d', current_time('timestamp') - DAY_IN_SECONDS)) { // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
            return 'Tegnap – ' . str_replace('-', '.', $nap) . '.';
        }

        $ts = strtotime($nap . ' 12:00:00');

        return str_replace('-', '.', $nap) . '. ' . ($ts ? wp_date('l', $ts) : '');
    }

    /* =================================================================
     * A Napló menüpont
     * ============================================================== */

    /** @return array<string, mixed> A szűrő az URL-ből. */
    private static function szuro_url(): array
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- csak szűrés (olvasás).
        $g = static fn (string $k): string => isset($_GET[$k]) ? sanitize_text_field(wp_unslash((string) $_GET[$k])) : '';

        return array_filter([
            'tipus'       => sanitize_key($g('n_tipus')),
            'felhasznalo' => (int) $g('n_ki'),
            'forras'      => sanitize_key($g('n_forras')),
            'muvelet'     => sanitize_key($g('n_muvelet')),
            'tol'         => $g('n_tol'),
            'ig'          => $g('n_ig'),
            'munkalap'    => $g('n_ml'),
            'kereses'     => mb_substr($g('n_q'), 0, 100),
            'keres'       => preg_replace('/[^a-f0-9]/', '', $g('n_keres')),
        ]);
        // phpcs:enable
    }

    public static function oldal(): void
    {
        SDH_Muhely_Admin_UI::admin_jog_ellenoriz();

        $V   = 'SDH_Muhely_Valtozasnaplo';
        $b   = $V::beallitas();
        $sz  = self::szuro_url();
        $lap = isset($_GET['lap']) ? max(1, (int) $_GET['lap']) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $l   = $V::lista($sz, $lap, $b['lap']);
        $db  = $V::szamok();
        $ell = get_option($V::OPT . '_ellenorzes', []);
        $ell = is_array($ell) ? $ell : [];

        $kik = get_users(['orderby' => 'display_name', 'fields' => ['ID', 'display_name']]);
        $sz_leiras = self::szuro_leiras($sz);

        ?>
        <div class="sdh-wrap sdh-wrap--teljes sdh-naplo sdh-naplo--oldal" data-sdh-naplo-oldal data-sdh-naplo-rekord="naplo:0"
             data-szuro="<?php echo esc_attr($sz_leiras); ?>">
            <?php
            SDH_Muhely_Admin_UI::uzenet();
            SDH_Muhely_Admin_UI::fejlec(
                'Napló',
                'Minden módosítás egy helyen: ki, mikor, mit és honnan változtatott. A bejegyzéseket senki nem írhatja át és nem törölheti.'
            );
            ?>

            <div class="sdh-naplo__statok">
                <div class="sdh-naplo__stat"><strong><?php echo esc_html(number_format($db['osszes'], 0, ',', ' ')); ?></strong><span>bejegyzés összesen</span></div>
                <div class="sdh-naplo__stat"><strong><?php echo esc_html(number_format($db['ma'], 0, ',', ' ')); ?></strong><span>ma</span></div>
                <div class="sdh-naplo__stat sdh-naplo__stat--epseg<?php echo isset($ell['ok']) ? ($ell['ok'] ? ' is-ok' : ' is-hiba') : ''; ?>" data-sdh-naplo-epseg>
                    <strong><?php echo esc_html(isset($ell['ok']) ? ($ell['ok'] ? 'Ép' : 'Eltérés!') : 'Nincs ellenőrizve'); ?></strong>
                    <span><?php echo esc_html(isset($ell['ido']) ? 'Ellenőrizve: ' . substr((string) $ell['ido'], 0, 16) : 'A napló épsége'); ?></span>
                </div>
                <div class="sdh-naplo__stat-gombok">
                    <button type="button" class="sdh-gomb sdh-gomb--vilagos sdh-gomb--pill" data-sdh-naplo-ellenoriz>Épség ellenőrzése</button>
                    <a class="sdh-gomb sdh-gomb--vilagos sdh-gomb--pill" href="<?php echo esc_url(add_query_arg(array_merge(['action' => 'sdh_muhely_naplo_csv', '_wpnonce' => wp_create_nonce('sdh_muhely_modal')], self::szuro_parameterek($sz)), admin_url('admin-ajax.php'))); ?>">CSV letöltése</a>
                    <?php if (class_exists('SDH_Muhely_Asszisztens') && SDH_Muhely_Modulok::be('asszisztens')) : ?>
                        <button type="button" class="sdh-gomb sdh-gomb--elsodleges sdh-gomb--pill" data-sdh-naplo-ai
                                data-kerdes="<?php echo esc_attr('Nézd át a változásnaplót' . ($sz_leiras !== '' ? ' (' . $sz_leiras . ')' : ' (az utolsó napok)') . ', és foglald össze idővonalszerűen: mi történt, ki mit módosított, mi a szokatlan.'); ?>">
                            <?php echo SDH_Muhely_Admin_UI::gomb_ikon('asszisztens'); // phpcs:ignore WordPress.Security.EscapeOutput ?><span>Kérdezd az asszisztenst</span>
                        </button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="sdh-naplo__epseg-reszlet" data-sdh-naplo-epseg-reszlet hidden></div>

            <form class="sdh-naplo__szuro" method="get" action="<?php echo esc_url(SDH_Muhely_Modulok::urlap_cel(self::KULCS)); ?>">
                <?php SDH_Muhely_Modulok::urlap_rejtett(self::KULCS); ?>
                <div class="sdh-mezo">
                    <label for="n_tipus">Mi</label>
                    <select name="n_tipus" id="n_tipus">
                        <option value="">Minden</option>
                        <?php foreach ($V::kategoriak() as $k => $kat) : ?>
                            <option value="<?php echo esc_attr($k); ?>" <?php selected((string) ($sz['tipus'] ?? ''), $k); ?>><?php echo esc_html($kat['cim']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="sdh-mezo">
                    <label for="n_ki">Ki</label>
                    <select name="n_ki" id="n_ki">
                        <option value="">Bárki</option>
                        <?php foreach ($kik as $u) : ?>
                            <option value="<?php echo (int) $u->ID; ?>" <?php selected((int) ($sz['felhasznalo'] ?? 0), (int) $u->ID); ?>><?php echo esc_html((string) $u->display_name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="sdh-mezo">
                    <label for="n_forras">Honnan</label>
                    <select name="n_forras" id="n_forras">
                        <option value="">Bárhonnan</option>
                        <?php foreach (['felulet', 'ai', 'ai-visszaallitas', 'idozitett', 'ugyfeloldal', 'webhook', 'cli'] as $f) : ?>
                            <option value="<?php echo esc_attr($f); ?>" <?php selected((string) ($sz['forras'] ?? ''), $f); ?>><?php echo esc_html($V::forras_nev($f)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="sdh-mezo">
                    <label for="n_muvelet">Művelet</label>
                    <select name="n_muvelet" id="n_muvelet">
                        <option value="">Bármi</option>
                        <?php foreach (['letrehozas', 'modositas', 'torles', 'beallitas', 'esemeny', 'nyomtatas', 'elutasitva', 'tomeges', 'vedelem'] as $m) : ?>
                            <option value="<?php echo esc_attr($m); ?>" <?php selected((string) ($sz['muvelet'] ?? ''), $m); ?>><?php echo esc_html($V::muvelet_nev($m)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="sdh-mezo">
                    <label for="n_tol">Ettől</label>
                    <input type="text" name="n_tol" id="n_tol" class="sdh-pop sdh-pop--datum" readonly autocomplete="off" data-sdh-pop="datum" data-sdh-pop-adat="[]" placeholder="éééé-hh-nn" value="<?php echo esc_attr((string) ($sz['tol'] ?? '')); ?>">
                </div>
                <div class="sdh-mezo">
                    <label for="n_ig">Eddig</label>
                    <input type="text" name="n_ig" id="n_ig" class="sdh-pop sdh-pop--datum" readonly autocomplete="off" data-sdh-pop="datum" data-sdh-pop-adat="[]" placeholder="éééé-hh-nn" value="<?php echo esc_attr((string) ($sz['ig'] ?? '')); ?>">
                </div>
                <div class="sdh-mezo">
                    <label for="n_ml">Munkalapszám</label>
                    <input type="text" name="n_ml" id="n_ml" inputmode="numeric" value="<?php echo esc_attr((string) ($sz['munkalap'] ?? '')); ?>">
                </div>
                <div class="sdh-mezo sdh-naplo__kereso">
                    <label for="n_q">Keresés</label>
                    <input type="search" name="n_q" id="n_q" placeholder="név, érték, megjegyzés…" value="<?php echo esc_attr((string) ($sz['kereses'] ?? '')); ?>">
                </div>
                <div class="sdh-naplo__szuro-gombok">
                    <button type="submit" class="sdh-gomb sdh-gomb--elsodleges">Szűrés</button>
                    <a class="sdh-gomb sdh-gomb--vilagos" href="<?php echo esc_url(SDH_Muhely_Modulok::url(self::KULCS)); ?>">Alaphelyzet</a>
                </div>
            </form>

            <p class="sdh-naplo__db"><?php echo esc_html(self::db_szoveg($l['osszes']) . ($sz_leiras !== '' ? ' – ' . $sz_leiras : '')); ?></p>

            <div class="sdh-naplo__lista">
                <?php self::kartyak($l['sorok']); ?>
            </div>

            <?php self::lapozo($lap, $l['osszes'], $b['lap'], $sz); ?>
        </div>
        <?php
    }

    /** @param array<string, mixed> $sz @return array<string, string> */
    private static function szuro_parameterek(array $sz): array
    {
        $map = ['tipus' => 'n_tipus', 'felhasznalo' => 'n_ki', 'forras' => 'n_forras', 'muvelet' => 'n_muvelet', 'tol' => 'n_tol', 'ig' => 'n_ig', 'munkalap' => 'n_ml', 'kereses' => 'n_q', 'keres' => 'n_keres'];
        $ki  = [];

        foreach ($map as $k => $p) {
            if (!empty($sz[$k])) {
                $ki[$p] = (string) $sz[$k];
            }
        }

        return $ki;
    }

    /** @param array<string, mixed> $sz */
    private static function szuro_leiras(array $sz): string
    {
        $V = 'SDH_Muhely_Valtozasnaplo';
        $r = [];

        if (!empty($sz['tipus'])) {
            $r[] = (string) ($V::kategoriak()[$sz['tipus']]['cim'] ?? $sz['tipus']);
        }

        if (!empty($sz['felhasznalo'])) {
            $u = get_userdata((int) $sz['felhasznalo']);
            $r[] = 'ki: ' . ($u ? $u->display_name : '#' . (int) $sz['felhasznalo']);
        }

        if (!empty($sz['forras'])) {
            $r[] = 'honnan: ' . $V::forras_nev((string) $sz['forras']);
        }

        if (!empty($sz['muvelet'])) {
            $r[] = 'művelet: ' . $V::muvelet_nev((string) $sz['muvelet']);
        }

        if (!empty($sz['tol']) || !empty($sz['ig'])) {
            $r[] = 'időszak: ' . ($sz['tol'] ?? '…') . ' – ' . ($sz['ig'] ?? '…');
        }

        if (!empty($sz['munkalap'])) {
            $r[] = 'munkalap: ' . $sz['munkalap'];
        }

        if (!empty($sz['kereses'])) {
            $r[] = 'keresés: „' . $sz['kereses'] . '”';
        }

        return implode(', ', $r);
    }

    /** @param array<string, mixed> $sz */
    private static function lapozo(int $lap, int $osszes, int $egy, array $sz): void
    {
        $oldalak = (int) ceil($osszes / max(1, $egy));

        if ($oldalak <= 1) {
            return;
        }

        $nyil = static fn (string $d): string => '<svg viewBox="0 0 16 16" aria-hidden="true"><path d="' . $d . '"/></svg>';
        $url  = static fn (int $l): string => SDH_Muhely_Modulok::url(self::KULCS, self::szuro_parameterek($sz) + ['lap' => $l]);

        echo '<nav class="sdh-cs-lapozo sdh-cs-lapozo--lap" aria-label="Lapozás">';

        if ($lap > 1) {
            printf('<a class="sdh-gomb sdh-lapnyil" href="%s" aria-label="Újabbak">%s</a>', esc_url($url($lap - 1)), $nyil('M10 3 5 8l5 5')); // phpcs:ignore WordPress.Security.EscapeOutput
        }

        printf('<span>%d / %d</span>', (int) $lap, (int) $oldalak);

        if ($lap < $oldalak) {
            printf('<a class="sdh-gomb sdh-lapnyil" href="%s" aria-label="Régebbiek">%s</a>', esc_url($url($lap + 1)), $nyil('M6 3l5 5-5 5')); // phpcs:ignore WordPress.Security.EscapeOutput
        }

        echo '</nav>';
    }

    public static function ajax_ellenoriz(): void
    {
        check_ajax_referer('sdh_muhely_modal');

        if (!SDH_Muhely_Admin_UI::admin_e()) {
            wp_send_json_error(['uzenet' => 'Ehhez adminisztrátori jog kell.'], 403);
        }

        $e = SDH_Muhely_Valtozasnaplo::ellenoriz();

        $szoveg = $e['ok']
            ? sprintf('Rendben: mind a(z) %s bejegyzés aláírása stimmel, és egy sem hiányzik.', number_format($e['db'], 0, ',', ' '))
            : trim(
                ($e['hibas_db'] > 0 ? sprintf('%d bejegyzést utólag átírtak (azonosító: %s). ', $e['hibas_db'], implode(', ', array_slice($e['hibas'], 0, 10))) : '')
                . ($e['hezagok'] !== [] ? sprintf('Hiányzó bejegyzések: %s. ', implode(', ', array_map(static fn ($h) => $h[0] === $h[1] ? '#' . $h[0] : '#' . $h[0] . '–#' . $h[1], array_slice($e['hezagok'], 0, 10)))) : '')
                . 'A napló adatbázis-szinten megsérült – nézd meg, ki fér hozzá a szerverhez.'
            );

        wp_send_json_success($e + ['szoveg' => $szoveg]);
    }

    /** CSV a szűrt bejegyzésekről (Excelben nyitható: pontosvessző, UTF-8 BOM). */
    public static function ajax_csv(): void
    {
        check_ajax_referer('sdh_muhely_modal');

        if (!SDH_Muhely_Admin_UI::admin_e()) {
            wp_die('Ehhez adminisztrátori jog kell.', '', ['response' => 403]);
        }

        $V  = 'SDH_Muhely_Valtozasnaplo';
        $sz = self::szuro_url();

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="naplo-' . current_time('Ymd-His') . '.csv"');

        $ki = fopen('php://output', 'w');

        if ($ki === false) {
            exit;
        }

        fwrite($ki, "\xEF\xBB\xBF");
        fputcsv($ki, ['Azonosító', 'Idő', 'Ki', 'Honnan', 'Művelet', 'Rekord', 'Mi', 'Mező', 'Régi', 'Új', 'Megjegyzés', 'IP'], ';');

        for ($lap = 1; $lap <= 200; $lap++) {
            $l = $V::lista($sz, $lap, 500);

            foreach ($l['sorok'] as $s) {
                $r = $V::reszletek($s);
                $r = $r === [] ? [['mezo' => '', 'regi' => '', 'uj' => (string) $s->osszegzes]] : $r;

                foreach ($r as $x) {
                    fputcsv($ki, [
                        (int) $s->id, (string) $s->ido, (string) $s->felhasznalo_nev, $V::forras_nev((string) $s->forras),
                        $V::muvelet_nev((string) $s->muvelet), $V::rekord_cimke((string) $s->fo_tipus, (int) $s->fo_id),
                        $V::tabla_nev((string) $s->tabla), $x['mezo'], $x['regi'], $x['uj'], (string) $s->megjegyzes, (string) $s->ip,
                    ], ';');
                }
            }

            if (count($l['sorok']) < 500) {
                break;
            }
        }

        fclose($ki);
        exit;
    }

    /* =================================================================
     * Beállítások › Változásnapló és › Lezárt lapok
     * ============================================================== */

    public static function beallitas_doboz(): void
    {
        $V   = 'SDH_Muhely_Valtozasnaplo';
        $b   = $V::beallitas();
        $db  = $V::szamok();
        $ell = get_option($V::OPT . '_ellenorzes', []);
        $ell = is_array($ell) ? $ell : [];

        ?>
        <div class="sdh-doboz">
            <h2 class="sdh-doboz__cim">Mit naplóz a rendszer</h2>
            <p class="sdh-sugo">
                Mindent, ami a rendszer adatain változik: munkalap, napi árukiadás, rendelés (tételek, hibák, eszközök, fizetés, állapot),
                ügyfélüzenetek és SMS, csapatüzenetek és az üzenőfal cetlijei, naptár, termékek és készletmozgás, ügyfelek, eszközök,
                szolgáltatások, pénztár, számlák. Mezőnként: mi volt, mi lett, ki, mikor és honnan (felület, AI-asszisztens,
                időzített feladat, ügyféloldal). Ez nem kapcsolható ki, és a bejegyzések nem írhatók át, nem törölhetők.
            </p>
            <p class="sdh-sugo">Jelenleg <strong><?php echo esc_html(number_format($db['osszes'], 0, ',', ' ')); ?></strong> bejegyzés van, ebből ma <strong><?php echo esc_html(number_format($db['ma'], 0, ',', ' ')); ?></strong>.
                <?php if (isset($ell['ok'])) : ?>
                    Utolsó épségellenőrzés: <?php echo esc_html(substr((string) $ell['ido'], 0, 16)); ?> – <strong><?php echo esc_html($ell['ok'] ? 'ép' : 'ELTÉRÉS'); ?></strong>.
                <?php endif; ?>
            </p>

            <input type="hidden" name="naplo_jelen" value="1">

            <div class="sdh-mezo sdh-mezo--jelolo">
                <input type="checkbox" name="naplo_beallitasok" id="naplo_beallitasok" value="1" <?php checked($b['beallitasok']); ?>>
                <label for="naplo_beallitasok">A Beállítások változásai is kerüljenek a naplóba</label>
            </div>
            <div class="sdh-mezo sdh-mezo--jelolo">
                <input type="checkbox" name="naplo_ip" id="naplo_ip" value="1" <?php checked($b['ip']); ?>>
                <label for="naplo_ip">A gép hálózati címe (IP) is kerüljön a bejegyzésbe</label>
            </div>
            <div class="sdh-mezok">
                <div class="sdh-mezo">
                    <label for="naplo_lap">Bejegyzés oldalanként (Napló menüpont)</label>
                    <input type="number" name="naplo_lap" id="naplo_lap" min="20" max="500" step="10" value="<?php echo (int) $b['lap']; ?>">
                </div>
            </div>
        </div>
        <?php
    }

    public static function beallitas_mentes(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- a Beállítások mentése ellenőrizte.
        if (empty($_POST['naplo_jelen'])) {
            return;
        }

        update_option(SDH_Muhely_Valtozasnaplo::OPT, [
            'beallitasok' => !empty($_POST['naplo_beallitasok']),
            'ip'          => !empty($_POST['naplo_ip']),
            'lap'         => min(500, max(20, (int) ($_POST['naplo_lap'] ?? 100))),
        ], false);
        // phpcs:enable
    }

    public static function zarolas_doboz(): void
    {
        $b = SDH_Muhely_Munkalap::zar_beallitas();

        ?>
        <div class="sdh-doboz">
            <h2 class="sdh-doboz__cim">Lezárt lapok zárolása</h2>
            <p class="sdh-sugo">
                A lezárt állapotú lap (a Beállítások › Állapotok listájában „zart” jelzős, pl. Lezárt, Érvénytelen) csak olvasható:
                szerkeszteni a lap tetején lévő <strong>Újranyitás</strong> gomb után lehet. A szerver is elutasít minden módosítást
                (mentés, eszköz állapota és „fizetve” jelzője, fizetés, rendelés útja, AI-javaslat). A nyomtatás, a matrica, a számla és az
                ügyfélüzenetek zárolva is mennek. Az újranyitás, az oka és minden elutasított próbálkozás a Naplóba kerül.
            </p>

            <input type="hidden" name="zarolas_jelen" value="1">

            <div class="sdh-mezo sdh-mezo--jelolo">
                <input type="checkbox" name="zarolas_be" id="zarolas_be" value="1" <?php checked($b['be']); ?>>
                <label for="zarolas_be">A lezárt lap csak újranyitás után szerkeszthető</label>
            </div>
            <div class="sdh-mezo sdh-mezo--jelolo">
                <input type="checkbox" name="zarolas_indok" id="zarolas_indok" value="1" <?php checked($b['indok']); ?>>
                <label for="zarolas_indok">Az újranyitás oka kötelező (ilyenkor a listából sem nyitható újra, csak a lapon)</label>
            </div>
        </div>
        <?php
    }

    public static function zarolas_mentes(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- a Beállítások mentése ellenőrizte.
        if (empty($_POST['zarolas_jelen'])) {
            return;
        }

        update_option(SDH_Muhely_Munkalap::OPT_ZAROLAS, [
            'be'    => !empty($_POST['zarolas_be']),
            'indok' => !empty($_POST['zarolas_indok']),
        ]);
        // phpcs:enable
    }
}
