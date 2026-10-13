<?php
/**
 * SDH – belső modulok kerete.
 *
 * EZ A MAPPA (`includes/sdh/`) CSAK AZ SDH SZERVIZÉ. Ami ide kerül, az a
 * cég SAJÁT dolga: beszállítói levelek, saját partnerek, saját papírok,
 * saját szokások. ELADÁSKOR EZ A MAPPA NEM MEGY KI – a bővítmény nélküle
 * is hiánytalanul működik, mert minden általános képesség a rendes
 * modulokban van, itt csak a RÁHÚZOTT, SDH-specifikus beállítás áll.
 *
 * Így kell használni:
 *   - Új, csak az SDH-nak szóló dolog?  → ebbe a mappába, és ide
 *     regisztráld: SDH_Muhely_Sdh::regisztral(...).
 *   - Általános képesség (más szerviznek is jó)? → NEM ide, hanem a
 *     megfelelő rendes modulba, és ide legfeljebb egy előre kitöltött
 *     beállítás (preset) kerül hozzá.
 *
 * Eladás előtti teendő: `includes/sdh/` mappa törlése. A betöltő
 * (sdh-muhely.php) a hiányzó fájlt átlépi, a Beállításokból eltűnik az
 * „SDH – belső" sor, és semmi más nem változik.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Sdh
{
    /** A fő kapcsoló (Beállítások › SDH – belső › SDH-modulok). */
    public const OPT = 'sdh_muhely_sdh_belso';

    /**
     * A regisztrált belső modulok.
     *
     * @var array<string, array{cim: string, leiras: string, rajz: callable, ment?: callable}>
     */
    private static array $modulok = [];

    public static function init(): void
    {
        // A Beállítások lapfülei a szakaszok() végén kérik be a belső lapokat.
        add_action('init', [self::class, 'lapfulek'], 30);
    }

    /** Be van-e kapcsolva a belső rész (alapból igen – az SDH gépén). */
    public static function be(): bool
    {
        return get_option(self::OPT, '1') !== '0';
    }

    /**
     * Egy belső modul bejelentkezése. A `rajz` a Beállítások lapjának
     * tartalmát rajzolja, a `ment` (ha van) a közös Mentés gombra fut.
     *
     * @param array{cim: string, leiras: string, rajz: callable, ment?: callable} $modul
     */
    public static function regisztral(string $kulcs, array $modul): void
    {
        self::$modulok[sanitize_key($kulcs)] = $modul;
    }

    /** @return array<string, array<string, mixed>> */
    public static function modulok(): array
    {
        return self::$modulok;
    }

    /** A belső lapfülek beadása a Beállításoknak (a saját „SDH – belső" csoportba). */
    public static function lapfulek(): void
    {
        if (!class_exists('SDH_Muhely_Beallitasok')) {
            return;
        }

        SDH_Muhely_Beallitasok::regisztral('sdh', [
            'cim'    => 'SDH-modulok',
            'csoport' => 'sdh',
            'leiras' => 'Az SDH saját, belső moduljai: mi fut, és egyben ki- vagy bekapcsolható. Ez a rész eladáskor nem megy ki.',
            'rajz'   => [self::class, 'fo_doboz'],
            'ment'   => [self::class, 'fo_mentes'],
        ]);

        if (!self::be()) {
            return;
        }

        foreach (self::$modulok as $kulcs => $m) {
            SDH_Muhely_Beallitasok::regisztral('sdh_' . $kulcs, [
                'cim'     => (string) $m['cim'],
                'csoport' => 'sdh',
                'leiras'  => (string) $m['leiras'],
                'rajz'    => $m['rajz'],
            ] + (isset($m['ment']) ? ['ment' => $m['ment']] : []));
        }
    }

    /* =================================================================
     * Beállítások › SDH – belső › SDH-modulok
     * ============================================================== */

    public static function fo_doboz(): void
    {
        ?>
        <div class="sdh-doboz">
            <h2 class="sdh-doboz__cim">SDH saját, belső modulok</h2>

            <p class="sdh-sugo">
                Ami ezen a soron (<strong>SDH – belső</strong>) van, az kizárólag az SDH Szerviz saját dolga:
                a saját beszállítóink levelei, a saját papírjaink, a saját szokásaink. Más szerviznek eladva
                a rendszer ezt a részt nem tartalmazza – az <code>includes/sdh/</code> mappa törlésével
                nyomtalanul eltűnik, és semmi más nem változik.
            </p>

            <div class="sdh-mezo sdh-mezo--jelolo sdh-mezo--also-ter">
                <input type="hidden" name="sdh_belso_jelen" value="1">
                <input type="checkbox" name="sdh_belso_aktiv" id="sdh_belso_aktiv" value="1" <?php checked(self::be()); ?>>
                <label for="sdh_belso_aktiv">A belső modulok fussanak</label>
            </div>

            <?php if (self::$modulok === []) : ?>
                <p class="sdh-sugo">Jelenleg nincs betöltött belső modul.</p>
            <?php else : ?>
                <table class="sdh-tabla">
                    <thead>
                        <tr><th>Belső modul</th><th>Mit csinál</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach (self::$modulok as $m) : ?>
                            <tr>
                                <td class="sdh-tabla__nev"><?php echo esc_html((string) $m['cim']); ?></td>
                                <td><?php echo esc_html((string) $m['leiras']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <p class="sdh-sugo sdh-sugo--utolso">
                Kikapcsolva a belső modulok lapjai is eltűnnek innen, és a hozzájuk tartozó automatizmusok
                (például a beszállítói levelek feldolgozása) nem futnak. A már létrejött feladatok megmaradnak.
            </p>
        </div>
        <?php
    }

    public static function fo_mentes(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- a Beállítások mentése ellenőrizte.
        if (empty($_POST['sdh_belso_jelen'])) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        update_option(self::OPT, empty($_POST['sdh_belso_aktiv']) ? '0' : '1', false);
    }
}
