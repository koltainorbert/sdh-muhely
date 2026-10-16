<?php
/**
 * Beállítások › Mentés lapfül (0.73.0, H3): felület és AJAX-végpontok.
 *
 * Dobozok: napi mentés · titkosító jelszó · külső mentési helyek (Google Drive, FTP, NAS, saját gép) ·
 * mentések (helyi lista, letöltés, visszaállítás) · export / import · beállítások mentése.
 *
 * A visszaállítás két lépésben megy: előbb ELŐNÉZET (a rendszer kibontja és megszámolja, mi van a
 * fájlban – semmihez nem nyúl), majd a külön megerősített VISSZAÁLLÍTÁS. Az előnézet ideiglenes
 * fájlt hoz létre (a mentési mappában, kívülről nem olvasható); az egy órán belül magától törlődik.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Mentes_Felulet
{
    private const ESZKOZ = 'sdh_muhely_gdrive_eszkoz';

    public static function init(): void
    {
        foreach (['cel_proba', 'cel_lista', 'gdrive_indit', 'gdrive_var', 'gdrive_lekapcsol', 'elonezet', 'vissza', 'csomag_le'] as $m) {
            add_action('wp_ajax_sdh_muhely_mentes_' . $m, [self::class, 'ajax_' . $m]);
        }
    }

    /* =================================================================
     * Közös segédek
     * ============================================================== */

    private static function admin_vagy_leall(): void
    {
        check_ajax_referer('sdh_muhely_modal');

        if (!class_exists('SDH_Muhely_Admin_UI') || !SDH_Muhely_Admin_UI::admin_e()) {
            wp_send_json_error(['uzenet' => 'Ehhez adminisztrátori jog kell.'], 403);
        }
    }

    private static function cel_id(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- az admin_vagy_leall ellenőrizte.
        $id = isset($_POST['cel']) ? sanitize_key(wp_unslash($_POST['cel'])) : '';

        if (!isset(SDH_Muhely_Mentes_Cel::tipusok()[$id])) {
            wp_send_json_error(['uzenet' => 'Ismeretlen mentési hely.']);
        }

        return $id;
    }

    /** @return array<string, mixed> */
    private static function mezo_post(): array
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- a mezőket a Cel::mentendo tisztítja.
        return isset($_POST['mezo']) && is_array($_POST['mezo']) ? (array) wp_unslash($_POST['mezo']) : [];
    }

    private static function fajlnev(string $nyers): string
    {
        $f = basename(str_replace('\\', '/', trim($nyers)));

        return preg_match('/^sdh-mentes-[\w\-]+\.(jsonl\.gz|sdhmentes)$/', $f) === 1 ? $f : '';
    }

    /* =================================================================
     * Felület
     * ============================================================== */

    private static function mezo_html(string $cel, string $kulcs, array $m, array $adat): void
    {
        $nev   = 'mentes_cel[' . $cel . '][' . $kulcs . ']';
        $id    = 'mentes_cel_' . $cel . '_' . $kulcs;
        $ertek = (string) ($adat[$kulcs] ?? '');
        $rejt  = '';

        if (!empty($m['ha'])) {
            $felt = (string) array_key_first($m['ha']);
            $rejt = ' data-sdh-ha-mezo="' . esc_attr($felt) . '" data-sdh-ha-ertek="' . esc_attr((string) $m['ha'][$felt]) . '"'
                . (($adat[$felt] ?? '') === (string) $m['ha'][$felt] ? '' : ' hidden');
        }

        if ($m['tipus'] === 'jelolo') {
            echo '<div class="sdh-mezo sdh-mezo--jelolo"' . $rejt . '>'; // phpcs:ignore WordPress.Security.EscapeOutput
            echo '<input type="checkbox" id="' . esc_attr($id) . '" name="' . esc_attr($nev) . '" value="1"' . checked($ertek, '1', false) . ' data-sdh-cel-mezo="' . esc_attr($kulcs) . '">';
            echo '<label for="' . esc_attr($id) . '">' . esc_html((string) $m['cim']) . '</label>';
        } else {
            echo '<div class="sdh-mezo"' . $rejt . '>'; // phpcs:ignore WordPress.Security.EscapeOutput
            echo '<label for="' . esc_attr($id) . '">' . esc_html((string) $m['cim']) . '</label>';

            if ($m['tipus'] === 'valaszto') {
                echo '<select id="' . esc_attr($id) . '" name="' . esc_attr($nev) . '" data-sdh-cel-mezo="' . esc_attr($kulcs) . '">';

                foreach ((array) $m['opciok'] as $k => $cimke) {
                    echo '<option value="' . esc_attr((string) $k) . '"' . selected($ertek, (string) $k, false) . '>' . esc_html((string) $cimke) . '</option>';
                }

                echo '</select>';
            } elseif ($m['tipus'] === 'jelszo') {
                echo '<input type="password" id="' . esc_attr($id) . '" name="' . esc_attr($nev) . '" value="" autocomplete="new-password" data-sdh-cel-mezo="' . esc_attr($kulcs) . '"'
                    . ' placeholder="' . esc_attr($ertek !== '' ? '•••••••• (megadva – üresen hagyva marad)' : '') . '">';
            } else {
                echo '<input type="' . ($m['tipus'] === 'szam' ? 'number' : 'text') . '" id="' . esc_attr($id) . '" name="' . esc_attr($nev) . '" value="' . esc_attr($ertek) . '" data-sdh-cel-mezo="' . esc_attr($kulcs) . '" autocomplete="off">';
            }
        }

        if (!empty($m['sugo'])) {
            echo '<span class="sdh-mezo__sugo">' . esc_html((string) $m['sugo']) . '</span>';
        }

        echo '</div>';
    }

    /** Az állapotsor egy cél alatt: legutóbbi siker / hiba / várakozó másolatok. */
    private static function allapot_html(string $id): void
    {
        $a = SDH_Muhely_Mentes_Cel::allapot()[$id] ?? [];

        if (!is_array($a) || $a === []) {
            echo '<p class="sdh-sugo sdh-mentes-allapot">Még nem készült ide másolat.</p>';

            return;
        }

        if (!empty($a['ido'])) {
            echo '<p class="sdh-sugo sdh-mentes-allapot">Utolsó sikeres másolat: <strong>' . esc_html((string) $a['ido']) . '</strong>'
                . (!empty($a['fajl']) ? ' · <code>' . esc_html((string) $a['fajl']) . '</code>' : '') . '</p>';
        }

        if (!empty($a['hiba'])) {
            $var = array_filter((array) ($a['varakozik'] ?? []), 'is_string');
            echo '<div class="sdh-uzenet sdh-uzenet--hiba">Utolsó hiba (' . esc_html((string) ($a['hiba_ido'] ?? '')) . '): ' . esc_html((string) $a['hiba']);

            if ($var !== []) {
                echo ' – ' . count($var) . ' mentés vár a feltöltésre, a rendszer újra próbálkozik.';
            }

            echo '</div>';
        }
    }

    private static function cel_kartya(string $id, array $tipus, string $nonce): void
    {
        $adat = SDH_Muhely_Mentes_Cel::adatok($id);

        echo '<div class="sdh-mentes-cel" data-sdh-cel="' . esc_attr($id) . '">';
        echo '<div class="sdh-mentes-cel__fej">';
        echo '<input type="checkbox" id="mentes_cel_' . esc_attr($id) . '_aktiv" name="mentes_cel[' . esc_attr($id) . '][aktiv]" value="1"' . checked(!empty($adat['aktiv']), true, false) . '>';
        echo '<label for="mentes_cel_' . esc_attr($id) . '_aktiv"><strong>' . esc_html($tipus['nev']) . '</strong></label>';
        echo '</div>';
        echo '<p class="sdh-sugo">' . esc_html($tipus['leiras']) . '</p>';

        echo '<div class="sdh-mezok">';

        foreach (SDH_Muhely_Mentes_Cel::mezok($id) as $kulcs => $m) {
            self::mezo_html($id, $kulcs, $m, $adat);
        }

        echo '</div>';

        if ($id === 'gdrive') {
            $kapcsolva = !empty($adat['refresh_token']);
            echo '<div class="sdh-mentes-gdrive" data-sdh-gdrive>';
            echo '<p class="sdh-sugo" data-sdh-gdrive-allapot>' . ($kapcsolva
                ? 'Összekapcsolva' . (!empty($adat['fiok']) ? ': <strong>' . esc_html((string) $adat['fiok']) . '</strong>' : '') . '.'
                : 'Még nincs összekapcsolva.') . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
            echo '<p><button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-gdrive-indit>' . ($kapcsolva ? 'Újra összekapcsolás' : 'Összekapcsolás a Google-fiókkal') . '</button> ';

            if ($kapcsolva) {
                echo '<button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-gdrive-bont>Kapcsolat bontása</button>';
            }

            echo '</p>';
            echo '<div class="sdh-uzenet" data-sdh-gdrive-kod hidden></div>';
            echo '<p class="sdh-sugo">A Google-felület beállításához (egyszeri, 5 perc) lásd a Súgót: <em>Mentés, jogosultság, biztonság › Google Drive</em>. '
                . 'Fontos: az OAuth-hozzájárulási képernyő állapota legyen „Éles”, különben a Google 7 naponta lejáratja a kapcsolatot.</p>';
            echo '</div>';
        }

        self::allapot_html($id);

        echo '<p class="sdh-mentes-cel__gombok">';
        echo '<button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-cel-proba>Kapcsolat próbája</button> ';
        echo '<button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-cel-lista>Mentések listázása</button> ';
        echo '<span class="sdh-sugo" data-sdh-cel-uzenet aria-live="polite"></span></p>';
        echo '<ul class="sdh-ai-mentesek sdh-mentes-lista" data-sdh-cel-lista-hely hidden></ul>';
        echo '</div>';
    }

    public static function doboz(): void
    {
        $b       = SDH_Muhely_Mentes::beallitas();
        $lista   = class_exists('SDH_Muhely_Asszisztens_Tudas') ? SDH_Muhely_Asszisztens_Tudas::mentesek() : [];
        $nonce   = wp_create_nonce('sdh_muhely_modal');
        $utolso  = get_option('sdh_muhely_mentes_utolso', []);
        $jelszo  = SDH_Muhely_Mentes_Cel::jelszo_van();
        $ajax    = admin_url('admin-ajax.php');
        $titkos_ok = SDH_Muhely_Mentes_Titkos::elerheto();
        $uv      = SDH_Muhely_Mentes_Vissza::utolso();

        echo '<div data-sdh-mentes-gyoker data-nonce="' . esc_attr($nonce) . '" data-ajax="' . esc_url($ajax) . '">';

        /* ---- 1. Napi mentés -------------------------------------------------- */
        ?>
        <div class="sdh-doboz">
            <h2 class="sdh-doboz__cim">Napi automatikus mentés</h2>

            <p class="sdh-sugo">
                Minden nap készül egy teljes mentés a rendszer összes saját táblájáról és beállításáról
                (a TAC-adatbázis, a levél-gyorsítótár és az ügyfél-szóindex kivételével – azok újra előállíthatók).
                Ha a gép éjjel ki van kapcsolva, a mentés a nap első bejelentkezése után készül el, a háttérben.
                A kulcsok és jelszavak a mentésben is titkosítva állnak.
            </p>

            <?php if (is_array($utolso) && !empty($utolso['hiba'])) : ?>
                <div class="sdh-uzenet sdh-uzenet--hiba">A legutóbbi mentés nem sikerült (<?php echo esc_html((string) ($utolso['ido'] ?? '')); ?>): <?php echo esc_html((string) $utolso['hiba']); ?></div>
            <?php endif; ?>

            <div class="sdh-mezo sdh-mezo--jelolo sdh-mezo--also-ter">
                <input type="hidden" name="mentes_jelen" value="1">
                <input type="checkbox" name="mentes_aktiv" id="mentes_aktiv" value="1" <?php checked($b['aktiv']); ?>>
                <label for="mentes_aktiv">Napi mentés bekapcsolva</label>
            </div>

            <div class="sdh-mezok">
                <div class="sdh-mezo">
                    <label for="mentes_megtart">Megőrzött mentések száma</label>
                    <input type="number" name="mentes_megtart" id="mentes_megtart" min="3" max="365" step="1" value="<?php echo (int) $b['megtart']; ?>">
                    <span class="sdh-mezo__sugo">A legrégebbiek törlődnek. Napi egy mentésnél a 20 nagyjából három hét. Az <strong>előző napi mentés</strong> mindig megmarad, a számon felül is – akkor is, ha ma több kézi mentés készült.</span>
                </div>
            </div>
        </div>

        <?php
        /* ---- 2. Titkosítás --------------------------------------------------- */
        ?>
        <div class="sdh-doboz">
            <h2 class="sdh-doboz__cim">Titkosítás</h2>

            <p class="sdh-sugo">
                A szerveren kívülre (Google Drive, FTP, NAS, másik gép) csak <strong>jelszóval titkosított</strong> mentés kerül –
                ügyféladat nem utazik nyíltan. A jelszó nincs a mentésfájlban: ha elveszik, a titkosított mentések
                <strong>senki által nem nyithatók meg</strong>. Írd fel, és tedd el biztonságos helyre (nem a szerverre).
            </p>

            <?php if (!$titkos_ok) : ?>
                <div class="sdh-uzenet sdh-uzenet--hiba">A szerveren nincs libsodium – a titkosított külső mentés nem használható.</div>
            <?php endif; ?>

            <div class="sdh-mezok">
                <div class="sdh-mezo">
                    <label for="mentes_jelszo">Titkosító jelszó</label>
                    <input type="password" id="mentes_jelszo" name="mentes_jelszo" value="" autocomplete="new-password" placeholder="<?php echo $jelszo ? '•••••••• (megadva – üresen hagyva marad)' : 'Legalább ' . (int) SDH_Muhely_Mentes_Titkos::MIN_JELSZO . ' karakter'; ?>">
                </div>
                <div class="sdh-mezo">
                    <label for="mentes_jelszo2">Jelszó újra</label>
                    <input type="password" id="mentes_jelszo2" name="mentes_jelszo2" value="" autocomplete="new-password">
                    <span class="sdh-mezo__sugo">Ha később megváltoztatod, a már feltöltött régi mentések a régi jelszóval nyílnak meg.</span>
                </div>
            </div>
        </div>

        <?php
        /* ---- 3. Külső helyek ------------------------------------------------- */
        ?>
        <div class="sdh-doboz">
            <h2 class="sdh-doboz__cim">Külső mentési helyek</h2>

            <p class="sdh-sugo">
                Minden mentés után a rendszer titkosított másolatot tölt fel a bekapcsolt helyekre, és ott is megőrzi a fenti számú mentést
                (plusz az előző napit). Ha egy hely nem érhető el, a másolat várakozik, és 15 percenként újra próbálkozik.
                Ajánlott: legalább egy hely <strong>másik gépen vagy a felhőben</strong> legyen – a mentés a gép lemezével együtt elveszhet.
            </p>

            <?php foreach (SDH_Muhely_Mentes_Cel::tipusok() as $id => $tipus) : ?>
                <?php self::cel_kartya($id, $tipus, $nonce); ?>
            <?php endforeach; ?>
        </div>

        <?php
        /* ---- 4. Mentések ----------------------------------------------------- */
        $kell   = SDH_Muhely_Mentes::megtartando($lista, $b['megtart']);
        $ma     = current_time('Y-m-d');
        $elozo  = '';

        foreach ($lista as $m) {
            if (wp_date('Y-m-d', $m['ido']) < $ma) {
                $elozo = $m['fajl'];
                break;
            }
        }
        ?>
        <div class="sdh-doboz">
            <h2 class="sdh-doboz__cim">Mentések ezen a gépen</h2>

            <p class="sdh-sugo">
                A helyi mentések a szerveren maradnak – ha a gép lemeze meghibásodik, velük együtt vesznek el, ezért kell a külső hely.
                A <strong>Letöltés (titkosítva)</strong> hordozható csomagot ad: másik gépen is visszaállítható, a beállításokkal és a kulcsokkal együtt.
            </p>

            <p>
                <button type="button" class="sdh-gomb sdh-gomb--elsodleges" data-sdh-mentes-most
                        data-nonce="<?php echo esc_attr($nonce); ?>">Mentés most</button>
                <span class="sdh-sugo" data-sdh-mentes-uzenet aria-live="polite"></span>
            </p>

            <?php if ($lista === []) : ?>
                <p class="sdh-sugo sdh-sugo--utolso">Még nincs mentés.</p>
            <?php else : ?>
                <ul class="sdh-ai-mentesek sdh-mentes-lista">
                    <?php foreach (array_slice($lista, 0, 12) as $m) : ?>
                        <li data-sdh-mentes-sor data-fajl="<?php echo esc_attr($m['fajl']); ?>">
                            <span><?php echo esc_html(wp_date('Y. m. d. H:i', $m['ido'])); ?></span>
                            <?php if ($m['fajl'] === $elozo) : ?><span class="sdh-mentes-jelveny" title="Az előző napi mentés – mindig megmarad">előző napi</span><?php endif; ?>
                            <?php if (strpos($m['fajl'], '-visszaallitas-elott-') !== false) : ?><span class="sdh-mentes-jelveny" title="Visszaállítás előtt készült biztonsági mentés">visszaállítás előtti</span><?php endif; ?>
                            <code><?php echo esc_html($m['fajl']); ?></code>
                            <span><?php echo esc_html(size_format($m['meret'])); ?></span>
                            <span class="sdh-mentes-sor__gombok">
                                <a class="sdh-gomb sdh-gomb--vilagos" href="<?php echo esc_url(add_query_arg(['action' => 'sdh_muhely_ai_mentes_le', 'fajl' => $m['fajl'], '_wpnonce' => $nonce], $ajax)); ?>">Letöltés</a>
                                <?php if ($jelszo) : ?>
                                    <a class="sdh-gomb sdh-gomb--vilagos" href="<?php echo esc_url(add_query_arg(['action' => 'sdh_muhely_mentes_csomag_le', 'fajl' => $m['fajl'], '_wpnonce' => $nonce], $ajax)); ?>">Letöltés (titkosítva)</a>
                                <?php endif; ?>
                                <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-vissza-helyi="<?php echo esc_attr($m['fajl']); ?>">Visszaállítás…</button>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php if (count($lista) > 12) : ?>
                    <p class="sdh-sugo sdh-sugo--utolso">+<?php echo count($lista) - 12; ?> régebbi mentés a szerveren.</p>
                <?php endif; ?>
            <?php endif; ?>

            <?php if (!empty($uv['ido'])) : ?>
                <p class="sdh-sugo">Legutóbbi visszaállítás: <?php echo esc_html((string) $uv['ido']); ?> (<?php echo ($uv['mod'] ?? '') === 'beallitasok' ? 'csak beállítások' : 'adatok és beállítások'; ?>); a visszaállítás előtti állapot: <code><?php echo esc_html((string) ($uv['biztonsagi'] ?? '')); ?></code></p>
            <?php endif; ?>
        </div>

        <?php
        /* ---- 5. Export / import ---------------------------------------------- */
        ?>
        <div class="sdh-doboz">
            <h2 class="sdh-doboz__cim">Export és import (költözés, teljes beállításmentés)</h2>

            <p class="sdh-sugo">
                <strong>Beállítások exportja</strong>: a rendszer MINDEN beállítása (arculat, számlázás, SMS, levelezés, nyomtatványok, kulcsok, mentési helyek…)
                egy jelszavas fájlban – adatok nélkül. Új telepítésen (pl. éles szerveren) egy lépésben visszaállítható, nem kell mindent újra beállítani.
                A <strong>teljes mentés</strong> (adatok + beállítások) a fenti listából tölthető le titkosítva.
            </p>

            <p>
                <?php if ($jelszo) : ?>
                    <a class="sdh-gomb sdh-gomb--elsodleges" href="<?php echo esc_url(add_query_arg(['action' => 'sdh_muhely_mentes_csomag_le', 'fajl' => 'beallitasok', '_wpnonce' => $nonce], $ajax)); ?>">Beállítások exportja</a>
                <?php else : ?>
                    <span class="sdh-sugo">Az exporthoz előbb adj meg titkosító jelszót, és mentsd a lapot.</span>
                <?php endif; ?>
            </p>

            <h3 class="sdh-doboz__alcim">Import fájlból</h3>
            <div class="sdh-mezok">
                <div class="sdh-mezo">
                    <label for="mentes_import_fajl">Mentés- vagy beállításfájl (.sdhmentes, .jsonl.gz)</label>
                    <input type="file" id="mentes_import_fajl" data-sdh-import-fajl accept=".sdhmentes,.gz">
                    <span class="sdh-mezo__sugo">Nagy fájlnál (a feltöltési korlát: <?php echo esc_html(size_format(wp_max_upload_size())); ?>) tedd a fájlt a „Saját gép” vagy a NAS mappájába, és onnan állítsd vissza a listából.</span>
                </div>
                <div class="sdh-mezo">
                    <label for="mentes_import_jelszo">A fájl jelszava</label>
                    <input type="password" id="mentes_import_jelszo" data-sdh-import-jelszo autocomplete="off" placeholder="<?php echo $jelszo ? 'Üresen: a beállított jelszó' : ''; ?>">
                </div>
            </div>
            <p><button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-import-gomb>Fájl megnyitása (előnézet)</button>
                <span class="sdh-sugo" data-sdh-import-uzenet aria-live="polite"></span></p>
        </div>

        <?php
        /* ---- 6. Visszaállítás-panel (az előnézet tölti) ---------------------- */
        ?>
        <div class="sdh-doboz sdh-mentes-vissza" data-sdh-vissza-panel hidden>
            <h2 class="sdh-doboz__cim">Visszaállítás</h2>
            <div data-sdh-vissza-tartalom></div>
        </div>
        <?php

        echo '</div>';
    }

    /* =================================================================
     * Mentés (a lapfül „Mentés” gombja)
     * ============================================================== */

    public static function mentes(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- a Beállítások mentése ellenőrizte.
        if (empty($_POST['mentes_jelen'])) {
            return;
        }

        $hiba = static function (string $uzenet): void {
            if (wp_doing_ajax()) {
                wp_send_json_error(['uzenet' => $uzenet]);
            }
        };

        $j1 = isset($_POST['mentes_jelszo']) ? (string) wp_unslash($_POST['mentes_jelszo']) : '';
        $j2 = isset($_POST['mentes_jelszo2']) ? (string) wp_unslash($_POST['mentes_jelszo2']) : '';

        if ($j1 !== '') {
            if (($h = SDH_Muhely_Mentes_Titkos::jelszo_hiba($j1)) !== null) {
                $hiba($h);

                return;
            }

            if ($j1 !== $j2) {
                $hiba('A két jelszó nem egyezik – a mentés jelszava nem változott.');

                return;
            }
        }

        $cel_post = isset($_POST['mentes_cel']) && is_array($_POST['mentes_cel']) ? $_POST['mentes_cel'] : [];
        $kell_jelszo = false;

        foreach ($cel_post as $c) {
            if (is_array($c) && !empty($c['aktiv'])) {
                $kell_jelszo = true;
            }
        }

        if ($kell_jelszo && $j1 === '' && !SDH_Muhely_Mentes_Cel::jelszo_van()) {
            $hiba('Külső mentési hely bekapcsolásához előbb adj meg titkosító jelszót.');

            return;
        }

        SDH_Muhely_Mentes::beallitas_ment([
            'aktiv'   => !empty($_POST['mentes_aktiv']),
            'megtart' => min(365, max(3, isset($_POST['mentes_megtart']) ? (int) $_POST['mentes_megtart'] : 20)),
        ]);

        if ($j1 !== '') {
            SDH_Muhely_Mentes_Cel::jelszo_ment($j1);
        }

        SDH_Muhely_Mentes_Cel::ment_postbol($_POST);
        // phpcs:enable

        delete_option('sdh_muhely_mentes_nap');
    }

    /* =================================================================
     * AJAX: célok
     * ============================================================== */

    public static function ajax_cel_proba(): void
    {
        self::admin_vagy_leall();
        $id = self::cel_id();

        if (!SDH_Muhely_Mentes_Cel::jelszo_van() && SDH_Muhely_Mentes_Titkos::elerheto()) {
            wp_send_json_error(['uzenet' => 'Előbb adj meg titkosító jelszót, és mentsd a lapot – jelszó nélkül nem készül külső másolat.']);
        }

        $cel = SDH_Muhely_Mentes_Cel::peldany($id, SDH_Muhely_Mentes_Cel::adatok_postbol($id, self::mezo_post()));

        if ($cel === null) {
            wp_send_json_error(['uzenet' => 'Ez a hely ezen a szerveren nem érhető el.']);
        }

        @set_time_limit(120); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        $h = $cel->tesztel();

        if ($h !== null) {
            wp_send_json_error(['uzenet' => $h]);
        }

        wp_send_json_success(['uzenet' => 'A kapcsolat rendben: a hely elérhető, és lehet rá írni.']);
    }

    public static function ajax_cel_lista(): void
    {
        self::admin_vagy_leall();
        $id  = self::cel_id();
        $cel = SDH_Muhely_Mentes_Cel::peldany($id, SDH_Muhely_Mentes_Cel::adatok_postbol($id, self::mezo_post()));

        if ($cel === null) {
            wp_send_json_error(['uzenet' => 'Ez a hely ezen a szerveren nem érhető el.']);
        }

        @set_time_limit(120); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        $l = $cel->listaz();

        if (is_string($l)) {
            wp_send_json_error(['uzenet' => $l]);
        }

        foreach ($l as $k => $m) {
            $l[$k]['ido'] = SDH_Muhely_Mentes::nev_ido($m['fajl']) ?: (int) $m['ido'];
        }

        usort($l, static fn (array $a, array $b): int => [$b['ido'], $b['fajl']] <=> [$a['ido'], $a['fajl']]);

        $ki = [];

        foreach ($l as $m) {
            $ki[] = ['fajl' => $m['fajl'], 'ido' => $m['ido'] > 0 ? wp_date('Y. m. d. H:i', $m['ido']) : '', 'meret' => size_format($m['meret'])];
        }

        wp_send_json_success(['lista' => $ki, 'uzenet' => $ki === [] ? 'Még nincs mentés ezen a helyen.' : count($ki) . ' mentés.']);
    }

    /* ----- Google Drive összekapcsolás --------------------------------- */

    public static function ajax_gdrive_indit(): void
    {
        self::admin_vagy_leall();

        // A most beírt azonosítók tartósan eltárolódnak (az összekapcsolás után ezekkel frissül a kulcs).
        $p = self::mezo_post();
        SDH_Muhely_Mentes_Cel::ment_egy('gdrive', $p);
        $be = SDH_Muhely_Mentes_Cel::adatok('gdrive');

        if (($be['kliens_id'] ?? '') === '' || ($be['kliens_titok'] ?? '') === '') {
            wp_send_json_error(['uzenet' => 'Add meg a kliensazonosítót és a kliens titkát.']);
        }

        $r = SDH_Muhely_Mentes_Gdrive::kod_kerese((string) $be['kliens_id']);

        if (is_string($r)) {
            wp_send_json_error(['uzenet' => $r]);
        }

        set_transient(self::ESZKOZ, ['kod' => $r['eszkoz_kod']], $r['lejar']);

        wp_send_json_success(['kod' => $r['felhasznalo_kod'], 'cim' => $r['cim'], 'varakozas' => $r['varakozas'], 'lejar' => $r['lejar']]);
    }

    public static function ajax_gdrive_var(): void
    {
        self::admin_vagy_leall();
        $t = get_transient(self::ESZKOZ);

        if (!is_array($t) || empty($t['kod'])) {
            wp_send_json_error(['uzenet' => 'A kód lejárt – kérj újat.']);
        }

        $be = SDH_Muhely_Mentes_Cel::adatok('gdrive');
        $r  = SDH_Muhely_Mentes_Gdrive::kod_ellenorzese((string) ($be['kliens_id'] ?? ''), (string) ($be['kliens_titok'] ?? ''), (string) $t['kod']);

        if ($r['allapot'] === 'kesz' || $r['allapot'] === 'hiba') {
            delete_transient(self::ESZKOZ);
        }

        wp_send_json_success($r);
    }

    public static function ajax_gdrive_lekapcsol(): void
    {
        self::admin_vagy_leall();
        SDH_Muhely_Mentes_Gdrive::lekapcsol();
        wp_send_json_success(['uzenet' => 'A Google Drive kapcsolat megszűnt. A már feltöltött mentések a Drive-on maradnak.']);
    }

    /* =================================================================
     * AJAX: letöltés, előnézet, visszaállítás
     * ============================================================== */

    /** Titkosított, hordozható csomag letöltése: egy helyi mentésből, vagy a beállításokból. */
    public static function ajax_csomag_le(): void
    {
        check_ajax_referer('sdh_muhely_modal');

        if (!SDH_Muhely_Admin_UI::admin_e()) {
            wp_die('Ehhez adminisztrátori jog kell.', '', ['response' => 403]);
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- fent ellenőrizve.
        $kert = isset($_GET['fajl']) ? sanitize_text_field(wp_unslash($_GET['fajl'])) : '';
        $jelszo = SDH_Muhely_Mentes_Cel::jelszo();

        if ($jelszo === '') {
            wp_die('Előbb adj meg titkosító jelszót a Beállítások › Mentés lapon.', '', ['response' => 400]);
        }

        if ($kert === 'beallitasok') {
            $forras = '';
            $nev    = 'sdh-beallitasok-' . current_time('Ymd-His') . SDH_Muhely_Mentes_Titkos::KITERJESZTES;
        } else {
            $fajl   = self::fajlnev($kert);
            $forras = $fajl === '' ? '' : SDH_Muhely_Asszisztens_Tudas::mentes_mappa() . $fajl;

            if ($fajl === '' || substr($fajl, -9) !== '.jsonl.gz' || !is_file($forras)) {
                wp_die('Nincs ilyen mentés.', '', ['response' => 404]);
            }

            $nev = (string) preg_replace('/\.jsonl\.gz$/', SDH_Muhely_Mentes_Titkos::KITERJESZTES, $fajl);
        }

        @set_time_limit(600); // phpcs:ignore WordPress.PHP.NoSilencedErrors

        $ut = SDH_Muhely_Asszisztens_Tudas::mentes_mappa() . 'sdh-mentes-tmp-' . wp_generate_password(10, false) . '.csomag.tmp';
        $h  = SDH_Muhely_Mentes_Vissza::csomag_keszit($forras, $ut, $jelszo);

        if ($h !== null) {
            wp_die(esc_html($h), '', ['response' => 500]);
        }

        nocache_headers();
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $nev . '"');
        header('Content-Length: ' . filesize($ut));
        readfile($ut); // phpcs:ignore WordPress.WP.AlternativeFunctions
        @unlink($ut); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        exit;
    }

    private static function stage_ut(string $token): string
    {
        return SDH_Muhely_Asszisztens_Tudas::mentes_mappa() . 'sdh-mentes-tmp-' . $token . '.stage.tmp';
    }

    /**
     * Előnézet: a forrás kibontása és áttekintése, VÁLTOZTATÁS NÉLKÜL. Forrás: helyi mentés, külső hely, vagy feltöltött fájl.
     */
    public static function ajax_elonezet(): void
    {
        self::admin_vagy_leall();

        // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput
        $forras = isset($_POST['forras']) ? sanitize_key(wp_unslash($_POST['forras'])) : '';
        $jelszo = isset($_POST['jelszo']) ? (string) wp_unslash($_POST['jelszo']) : '';
        $fajl   = isset($_POST['fajl']) ? self::fajlnev((string) wp_unslash($_POST['fajl'])) : '';
        // phpcs:enable

        if ($jelszo === '') {
            $jelszo = SDH_Muhely_Mentes_Cel::jelszo();
        }

        @set_time_limit(900); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        ignore_user_abort(true);

        $tmp    = SDH_Muhely_Asszisztens_Tudas::mentes_mappa() . 'sdh-mentes-tmp-' . wp_generate_password(10, false) . '.forras.tmp';
        $torles = true;

        if ($forras === 'helyi') {
            $ut = $fajl === '' ? '' : SDH_Muhely_Asszisztens_Tudas::mentes_mappa() . $fajl;

            if ($ut === '' || !is_file($ut)) {
                wp_send_json_error(['uzenet' => 'Nincs ilyen mentés.']);
            }

            $tmp    = $ut;
            $torles = false;
        } elseif ($forras === 'cel') {
            $id  = self::cel_id();
            $cel = SDH_Muhely_Mentes_Cel::peldany($id);

            if ($cel === null || $fajl === '') {
                wp_send_json_error(['uzenet' => 'Ez a hely vagy fájl nem érhető el.']);
            }

            $h = $cel->letolt($fajl, $tmp);

            if ($h !== null) {
                @unlink($tmp); // phpcs:ignore WordPress.PHP.NoSilencedErrors
                wp_send_json_error(['uzenet' => $h]);
            }
        } elseif ($forras === 'feltoltes') {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $f = $_FILES['fajl'] ?? null;

            if (!is_array($f) || (int) ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name'])) {
                wp_send_json_error(['uzenet' => 'A fájl feltöltése nem sikerült (túl nagy, vagy megszakadt). Nagy fájlt a „Saját gép” mappán át tölts be.']);
            }

            if (!@move_uploaded_file((string) $f['tmp_name'], $tmp)) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
                wp_send_json_error(['uzenet' => 'A feltöltött fájl nem menthető el.']);
            }
        } else {
            wp_send_json_error(['uzenet' => 'Ismeretlen forrás.']);
        }

        $info = SDH_Muhely_Mentes_Vissza::megnyit($tmp, $jelszo);

        if ($torles && ($info === null || is_string($info) || $info['gz'] !== $tmp)) {
            @unlink($tmp); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        }

        if (is_string($info)) {
            wp_send_json_error(['uzenet' => $info]);
        }

        $token = strtolower(wp_generate_password(14, false));
        $stage = self::stage_ut($token);

        // A kibontott (vagy eleve nyers) fájl a lépcsőhelyre kerül; a visszaállítás ezt olvassa.
        $ok = $info['kibontott'] ? @rename($info['gz'], $stage) : @copy($info['gz'], $stage); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions

        if ($torles && !$info['kibontott']) {
            @unlink($tmp); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        }

        if (!$ok) {
            SDH_Muhely_Mentes_Vissza::eldob($info['gz'], $info['kibontott']);
            wp_send_json_error(['uzenet' => 'Az előnézet ideiglenes fájlja nem írható.']);
        }

        $fej  = $info['fej'];
        $ossz = array_sum($info['tablak']);

        wp_send_json_success([
            'token'     => $token,
            'tipus'     => $info['tipus'],
            'ido'       => (string) ($fej['ido'] ?? ''),
            'verzio'    => (string) ($fej['mentes'] ?? ''),
            'ok'        => (string) ($fej['ok'] ?? ''),
            'tablak'    => count($info['tablak']),
            'sorok'     => $ossz,
            'reszletek' => $info['tablak'],
            'opciok'    => $info['opciok'],
            'jelszavas' => $info['jelszavas'],
        ]);
    }

    public static function ajax_vissza(): void
    {
        self::admin_vagy_leall();

        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $token = isset($_POST['token']) ? preg_replace('/[^a-z0-9]/', '', strtolower((string) wp_unslash($_POST['token']))) : '';
        $mod   = isset($_POST['mod']) ? sanitize_key(wp_unslash($_POST['mod'])) : '';
        // phpcs:enable

        $stage = $token === '' ? '' : self::stage_ut($token);

        if ($stage === '' || !is_file($stage)) {
            wp_send_json_error(['uzenet' => 'Az előnézet lejárt – nyisd meg újra a mentést.']);
        }

        $r = SDH_Muhely_Mentes_Vissza::visszaallit($stage, $mod);
        @unlink($stage); // phpcs:ignore WordPress.PHP.NoSilencedErrors

        if (is_string($r)) {
            wp_send_json_error(['uzenet' => $r]);
        }

        wp_send_json_success([
            'uzenet' => 'Visszaállítva: ' . $r['tablak'] . ' tábla, ' . $r['sorok'] . ' sor, ' . $r['opciok'] . ' beállítás. '
                . 'A visszaállítás előtti állapot elmentve: ' . $r['biztonsagi'] . '.',
        ]);
    }
}
