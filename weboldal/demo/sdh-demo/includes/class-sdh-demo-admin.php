<?php
/**
 * Az üzemeltető felülete (WordPress-admin › Eszközök › Bemutató – csak a fejlesztő / adminisztrátor látja):
 * beállítások (értékesítő webhely, Turnstile, demo / demo, korlátok, Számlázz.hu tesztkulcs), adatgenerálás,
 * pillanatkép és visszaállítás gombbal, állapot (mért idők).
 *
 * @package SDH_Demo
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Demo_Admin
{
    public const OLDAL = 'bemutato';

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'menu']);

        foreach (['beallitas', 'general', 'pillanatkep', 'visszaallit'] as $m) {
            add_action('admin_post_bemutato_' . $m, [self::class, $m]);
        }
    }

    public static function menu(): void
    {
        add_management_page('Bemutató', 'Bemutató', 'manage_options', self::OLDAL, [self::class, 'oldal']);
    }

    private static function url(array $p = []): string
    {
        return add_query_arg(array_merge(['page' => self::OLDAL], $p), admin_url('tools.php'));
    }

    private static function ellenoriz(string $muvelet): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Nincs jogosultság.', '', ['response' => 403]);
        }

        check_admin_referer('bemutato_' . $muvelet);
    }

    private static function vissza(string $uzenet, bool $hiba = false): void
    {
        set_transient('bemutato_admin_uzenet_' . get_current_user_id(), ['szoveg' => $uzenet, 'hiba' => $hiba], 120);
        wp_safe_redirect(self::url());
        exit;
    }

    /* =================================================================
     * Műveletek
     * ============================================================== */

    public static function beallitas(): void
    {
        self::ellenoriz('beallitas');

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- ellenoriz() nézte.
        $p = wp_unslash($_POST);
        SDH_Demo::ment([
            'ertekesito_url'   => (string) ($p['ertekesito_url'] ?? ''),
            'turnstile_kulcs'  => (string) ($p['turnstile_kulcs'] ?? ''),
            'demo_demo'        => !empty($p['demo_demo']),
            'beallitas_mentes' => !empty($p['beallitas_mentes']),
            'max_inditas_ip'   => (int) ($p['max_inditas_ip'] ?? 5),
            'keret'            => (int) ($p['keret'] ?? 10),
            'iras_max'         => (int) ($p['iras_max'] ?? 300),
            'elet_ora'         => (int) ($p['elet_ora'] ?? 24),
        ]);
        SDH_Demo::titok_ment('turnstile_titok', (string) ($p['turnstile_titok'] ?? ''), !empty($p['turnstile_titok_torol']));
        SDH_Demo::titok_ment('szamlazz_teszt_kulcs', (string) ($p['szamlazz_teszt_kulcs'] ?? ''), !empty($p['szamlazz_torol']));
        // phpcs:enable

        SDH_Demo_Inditas::demo_demo_szinkron();
        self::vissza('Beállítások elmentve.');
    }

    public static function general(): void
    {
        self::ellenoriz('general');

        $db = isset($_POST['db']) ? (int) $_POST['db'] : 10000; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $db = in_array($db, [1000, 5000, 10000, 20000], true) ? $db : 10000;

        try {
            $r = SDH_Demo_Generator::general($db);
            self::vissza(sprintf('Kész: %s rekord (%s tétel, %d készülékkép, %d átvételi fotó) %s mp alatt. Ha ez az alapállapot, készíts pillanatképet!',
                number_format_i18n($r['osszes']), number_format_i18n($r['tetelek']), $r['kepek'], $r['fotok'], number_format_i18n($r['mp'], 2)));
        } catch (Throwable $e) {
            self::vissza('A generálás nem sikerült: ' . $e->getMessage(), true);
        }
    }

    public static function pillanatkep(): void
    {
        self::ellenoriz('pillanatkep');

        try {
            $r = SDH_Demo_Pillanatkep::keszit();
            self::vissza(sprintf('Pillanatkép kész: %d tábla, %s sor, %s KB, %s mp.', $r['tablak'], number_format_i18n($r['sorok']), number_format_i18n($r['meret'] / 1024, 1), number_format_i18n($r['mp'], 2)));
        } catch (Throwable $e) {
            self::vissza('A pillanatkép nem készült el: ' . $e->getMessage(), true);
        }
    }

    public static function visszaallit(): void
    {
        self::ellenoriz('visszaallit');

        try {
            $r = SDH_Demo_Pillanatkep::visszaallit();
            self::vissza(sprintf('Visszaállítva: %d tábla, %s sor, %d látogatói fiók és %d látogatói fájl törölve, %s mp.', $r['tablak'], number_format_i18n($r['sorok']), $r['fiokok'], $r['fajlok'], number_format_i18n($r['mp'], 2)));
        } catch (Throwable $e) {
            self::vissza('A visszaállítás nem sikerült: ' . $e->getMessage(), true);
        }
    }

    /* =================================================================
     * Oldal
     * ============================================================== */

    public static function oldal(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        global $wpdb;

        $b   = SDH_Demo::beallitas();
        $a   = SDH_Demo::allapot();
        $uz  = get_transient('bemutato_admin_uzenet_' . get_current_user_id());
        $pk  = (array) ($a['pillanatkep'] ?? []);
        $va  = (array) ($a['visszaallitas'] ?? []);
        $gen = (array) ($a['generalas'] ?? []);
        $lat = count(get_users(['role' => SDH_Demo::SZEREP, 'fields' => 'ID', 'number' => 5000]));
        $ind = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . SDH_Demo::tabla('naplo') . " WHERE tipus = 'inditas' AND ido > %d", time() - DAY_IN_SECONDS));
        $kim = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . SDH_Demo::tabla('kimeno'));
        $ido = static fn ($t): string => $t ? wp_date('Y. m. d. H:i', (int) $t) : '–';

        delete_transient('bemutato_admin_uzenet_' . get_current_user_id());
        ?>
        <div class="wrap">
            <h1>Bemutató</h1>
            <?php if (is_array($uz)) : ?>
                <div class="notice notice-<?php echo !empty($uz['hiba']) ? 'error' : 'success'; ?>"><p><?php echo esc_html((string) $uz['szoveg']); ?></p></div>
            <?php endif; ?>

            <p>Ez a webhely a nyilvános bemutató: a látogató a <code><?php echo esc_html(add_query_arg('bemutato', 'indit', home_url('/'))); ?></code> címen
                ideiglenes fiókot kap. Minden tesztüzemben fut (SMS, e-mail, számla nem megy ki), éjjel 3:00-kor a pillanatképből visszaáll.</p>

            <h2>Állapot</h2>
            <table class="widefat striped" style="max-width:820px">
                <tbody>
                <tr><th>Pillanatkép</th><td><?php echo $pk ? esc_html(sprintf('%s · %d tábla · %s sor · %s KB · %s mp', $ido($pk['ido'] ?? 0), (int) $pk['tablak'], number_format_i18n((int) $pk['sorok']), number_format_i18n(((int) $pk['meret']) / 1024, 1), number_format_i18n((float) $pk['mp'], 2))) : 'még nincs – generálás után készíts egyet!'; ?></td></tr>
                <tr><th>Utolsó visszaállítás</th><td><?php echo $va ? esc_html(sprintf('%s · %s sor · %s mp', $ido($va['ido'] ?? 0), number_format_i18n((int) $va['sorok']), number_format_i18n((float) $va['mp'], 2))) : '–'; ?></td></tr>
                <tr><th>Következő visszaállítás</th><td><?php echo esc_html($ido(SDH_Demo::kovetkezo_visszaallitas())); ?> (Europe/Budapest)</td></tr>
                <tr><th>Utolsó generálás</th><td><?php echo $gen ? esc_html(sprintf('%s · %s rekord · %s mp', $ido($gen['ido'] ?? 0), number_format_i18n((int) $gen['osszes']), number_format_i18n((float) $gen['mp'], 2))) : '–'; ?></td></tr>
                <tr><th>Látogatói fiókok most</th><td><?php echo (int) $lat; ?> (indítás az elmúlt 24 órában: <?php echo (int) $ind; ?>)</td></tr>
                <tr><th>Elfogott kimenő üzenet</th><td><?php echo (int) $kim; ?></td></tr>
                <tr><th>Képek forrása</th><td><code><?php echo esc_html(SDH_Demo_Generator::kep_forras() ?: 'nincs (a készülékek kép nélkül)'); ?></code></td></tr>
                </tbody>
            </table>

            <h2>Adatok</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:12px">
                <input type="hidden" name="action" value="bemutato_general">
                <?php wp_nonce_field('bemutato_general'); ?>
                <label>Méret <select name="db"><?php foreach ([1000, 5000, 10000, 20000] as $n) : ?><option value="<?php echo (int) $n; ?>"<?php selected($n, 10000); ?>><?php echo esc_html(number_format_i18n($n)); ?></option><?php endforeach; ?></select></label>
                <button type="submit" class="button button-primary" data-bm-ketlepcsos="Biztos? A korábbi bemutató-adat törlődik – katt újra">Adatok generálása</button>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:12px">
                <input type="hidden" name="action" value="bemutato_pillanatkep">
                <?php wp_nonce_field('bemutato_pillanatkep'); ?>
                <button type="submit" class="button">Pillanatkép most</button>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block">
                <input type="hidden" name="action" value="bemutato_visszaallit">
                <?php wp_nonce_field('bemutato_visszaallit'); ?>
                <button type="submit" class="button" data-bm-ketlepcsos="Biztos? Minden látogatói adat törlődik – katt újra"<?php disabled(!SDH_Demo_Pillanatkep::van()); ?>>Visszaállítás most</button>
            </form>

            <h2>Beállítások</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="bemutato_beallitas">
                <?php wp_nonce_field('bemutato_beallitas'); ?>
                <table class="form-table" role="presentation">
                    <tr><th><label for="bm-elado">Értékesítő webhely címe</label></th><td><input id="bm-elado" type="url" class="regular-text" name="ertekesito_url" value="<?php echo esc_attr((string) $b['ertekesito_url']); ?>"><p class="description">A sáv „Megrendelem” gombja ide visz.</p></td></tr>
                    <tr><th><label for="bm-ts">Turnstile webhelykulcs</label></th><td><input id="bm-ts" type="text" class="regular-text" name="turnstile_kulcs" value="<?php echo esc_attr((string) $b['turnstile_kulcs']); ?>"></td></tr>
                    <tr><th><label for="bm-tst">Turnstile titkos kulcs</label></th><td><input id="bm-tst" type="password" class="regular-text" name="turnstile_titok" value="" autocomplete="new-password" placeholder="<?php echo $b['turnstile_titok'] !== '' ? 'elmentve – üresen hagyva marad' : ''; ?>"> <label><input type="checkbox" name="turnstile_titok_torol" value="1"> törlés</label><p class="description">Kulcs nélkül a rejtett mező és az időbélyeg véd a gépi indítás ellen.</p></td></tr>
                    <tr><th>Közös demo / demo belépés</th><td><label><input type="checkbox" name="demo_demo" value="1"<?php checked(!empty($b['demo_demo'])); ?>> bekapcsolva (alapból ki)</label></td></tr>
                    <tr><th>Beállítások mentése</th><td><label><input type="checkbox" name="beallitas_mentes" value="1"<?php checked(!empty($b['beallitas_mentes'])); ?>> a látogató mentheti a nem érzékeny beállításokat (mindenki látja, éjjel visszaáll)</label></td></tr>
                    <tr><th><label for="bm-ind">Indítás / cím / 24 óra</label></th><td><input id="bm-ind" type="number" min="1" max="100" name="max_inditas_ip" value="<?php echo (int) $b['max_inditas_ip']; ?>"></td></tr>
                    <tr><th><label for="bm-ker">Új rekord / cím / 24 óra</label></th><td><input id="bm-ker" type="number" min="1" max="1000" name="keret" value="<?php echo (int) $b['keret']; ?>"></td></tr>
                    <tr><th><label for="bm-ir">Bármilyen írás / cím / 24 óra</label></th><td><input id="bm-ir" type="number" min="20" max="10000" name="iras_max" value="<?php echo (int) $b['iras_max']; ?>"></td></tr>
                    <tr><th><label for="bm-elet">A látogatói fiók élettartama (óra)</label></th><td><input id="bm-elet" type="number" min="1" max="168" name="elet_ora" value="<?php echo (int) $b['elet_ora']; ?>"></td></tr>
                    <tr><th><label for="bm-sz">Számlázz.hu TESZTFIÓK Agent-kulcsa</label></th><td><input id="bm-sz" type="password" class="regular-text" name="szamlazz_teszt_kulcs" value="" autocomplete="new-password" placeholder="<?php echo $b['szamlazz_teszt_kulcs'] !== '' ? 'elmentve – üresen hagyva marad' : 'üres: semmi nem megy a Számlázz.hu-ra'; ?>"> <label><input type="checkbox" name="szamlazz_torol" value="1"> törlés</label><p class="description">Csak teszt-fiók kulcsa! Minden előtag „MINTA”, a vevő nem kap levelet.</p></td></tr>
                </table>
                <?php submit_button('Mentés'); ?>
            </form>
        </div>
        <script>
        document.querySelectorAll('[data-bm-ketlepcsos]').forEach(function (g) {
            g.addEventListener('click', function (e) {
                if (g.getAttribute('data-elesitve') === '1') { return; }
                e.preventDefault();
                var eredeti = g.textContent;
                g.setAttribute('data-elesitve', '1');
                g.textContent = g.getAttribute('data-bm-ketlepcsos');
                setTimeout(function () { g.setAttribute('data-elesitve', '0'); g.textContent = eredeti; }, 4000);
            });
        });
        </script>
        <?php
    }
}
