<?php
/**
 * N6 (0.92.0): a 0.78.0 előtt adminná tett kollégák átállítása „SDH adminisztrátor”-ra.
 *
 * 0.78.0 óta a WordPress-adminisztrátor = fejlesztő (látja, hogy WordPress, bemehet a wp-adminba). Akit
 * korábban a Csapatban adminná tettek, az WordPress-adminisztrátor lett – ők valójában a rendszer
 * adminisztrátorai. A Beállítások › Jogosultságok lapon a fejlesztő egy kétlépcsős gombbal átállítja őket
 * `sdh_admin` szerepre (minden rendszer-adminisztrátori jog megmarad, a WordPress elrejtve). Saját magát
 * nem állíthatja át, és legalább egy fejlesztő mindig marad.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Admin_Atallas
{
    public static function init(): void
    {
        add_action('wp_ajax_sdh_muhely_admin_atallit', [self::class, 'ajax']);
    }

    /**
     * A WordPress-adminisztrátorok a jelenlegi felhasználón kívül.
     *
     * @return array<int, WP_User>
     */
    public static function jeloltek(): array
    {
        $en = get_current_user_id();

        return array_values(array_filter(
            get_users(['role' => 'administrator', 'orderby' => 'display_name']),
            static fn (WP_User $u): bool => (int) $u->ID !== $en
        ));
    }

    /** Átállítás. Hibánál üzenet, különben ''. */
    public static function atallit(int $user_id): string
    {
        if (!SDH_Muhely_Admin_UI::fejleszto_e()) {
            return 'Ezt csak fejlesztő teheti.';
        }

        if ($user_id === get_current_user_id()) {
            return 'Saját magadat nem állíthatod át.';
        }

        $u = get_userdata($user_id);

        if (!$u || !in_array('administrator', (array) $u->roles, true)) {
            return 'Ez a felhasználó nem WordPress-adminisztrátor.';
        }

        if (count(get_users(['role' => 'administrator', 'fields' => 'ID'])) < 2) {
            return 'Legalább egy fejlesztőnek maradnia kell.';
        }

        $u->set_role(SDH_Muhely_Admin_UI::ADMIN_SZEREP);

        if (class_exists('SDH_Muhely_Valtozasnaplo')) {
            SDH_Muhely_Valtozasnaplo::esemeny('felhasznalo', $user_id, (string) $u->display_name . ': fejlesztőből SDH adminisztrátor (N6).');
        }

        return '';
    }

    public static function ajax(): void
    {
        check_ajax_referer('sdh_muhely_admin_atallit', '_wpnonce');

        $hiba = self::atallit(isset($_POST['user']) ? (int) $_POST['user'] : 0);

        if ($hiba !== '') {
            wp_send_json_error(['uzenet' => $hiba]);
        }

        wp_send_json_success(['uzenet' => 'Átállítva: mostantól SDH adminisztrátor.']);
    }

    /** Doboz a Jogosultságok lapon (csak fejlesztőnek, és csak ha van kit átállítani). */
    public static function doboz(): void
    {
        if (!SDH_Muhely_Admin_UI::fejleszto_e()) {
            return;
        }

        $lista = self::jeloltek();

        if ($lista === []) {
            return;
        }

        ?>
        <div class="sdh-doboz sdh-atallas" data-sdh-atallas data-nonce="<?php echo esc_attr(wp_create_nonce('sdh_muhely_admin_atallit')); ?>">
            <h2 class="sdh-doboz__cim">Fejlesztői fiókok [fejlesztő]</h2>
            <p class="sdh-sugo">
                Ezek a fiókok a WordPress-adminba is beléphetnek (fejlesztők). Akit korábban a Csapatban tettetek adminná,
                az valójában a rendszer adminisztrátora: állítsd át <strong>SDH adminisztrátor</strong>-ra – a rendszerben
                minden adminisztrátori joga megmarad, csak a WordPress marad előtte rejtve.
            </p>
            <table class="sdh-tabla">
                <thead><tr><th>Név</th><th>Belépési név</th><th>E-mail</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($lista as $u) : ?>
                    <tr>
                        <td><?php echo esc_html((string) $u->display_name); ?></td>
                        <td><?php echo esc_html((string) $u->user_login); ?></td>
                        <td><?php echo esc_html((string) $u->user_email); ?></td>
                        <td><button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-atallit="<?php echo (int) $u->ID; ?>">SDH adminisztrátorrá</button>
                            <span class="sdh-mezo__sugo" data-sdh-atallit-uzenet role="status"></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
