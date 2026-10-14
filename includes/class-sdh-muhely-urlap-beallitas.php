<?php
/**
 * Űrlap-beállítások (0.62.0) – a fogaskerék minden űrlapon.
 *
 * A munkalap, a rendelés, a napi árukiadás, a Számlázz.hu számla és a
 * számla-előkészítő ablakának fejlécében egy fogaskerék áll: mögötte
 * kapcsolókkal állítható, mi látszik az űrlapon (blokkok, mezők, lapfülek,
 * gombok, választható állapotok), a számláknál pedig az, hogy a munkalap
 * tételei, tétel-megjegyzései és a megjegyzés magától rákerüljenek-e.
 *
 * Tárolás: egyetlen wp_options sor (sdh_muhely_urlap_beallitas), amelyet a
 * bővítmény frissítése nem érint – egyszer beállítva marad. Csak a KIKAPCSOLT
 * elemek listája tárolódik, így egy későbbi verzió új eleme alapból látszik.
 * A napi mentés (sdh_muhely_% opciók) ezt is menti.
 *
 * Elrejtett mező értéke megmarad és mentődik (csak nem látszik); a változásnapló
 * nem kapcsolható ki, ezért a Napló gombja sem szerepel a listában.
 * Ugyanez a beállítás a Beállítások › Űrlapok lapfülön is elérhető.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Urlap_Beallitas
{
    public const OPT = 'sdh_muhely_urlap_beallitas';

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $cache = null;

    public static function init(): void
    {
        add_action('wp_ajax_sdh_muhely_urlap_beall_ment', [self::class, 'ajax_mentes']);

        SDH_Muhely_Beallitasok::regisztral('urlapok', [
            'cim'    => 'Űrlapok',
            'csoport' => 'munkalap',
            'leiras' => 'Mi látszódjon a munkalap, a rendelés, a napi árukiadás és a két számla ablakában; a számlára magától kerüljenek-e a tételek. Ugyanez az űrlapok fogaskerekével is állítható.',
            'rajz'   => [self::class, 'beallitas_doboz'],
            'ment'   => [self::class, 'beallitas_mentes'],
        ]);
    }

    /* =================================================================
     * Az űrlapok és a kapcsolható elemeik
     * ============================================================== */

    /**
     * Minden űrlap: kulcs => cím, és csoportonként a kapcsolható elemek
     * (elem => [cím, súgó]). A három munkalapfajta (javitas, rendeles,
     * arukiadas) közös listából válogat: ami a fajtán nincs, az kimarad.
     *
     * @return array<string, array{cim: string, csoportok: array<string, array<string, array{0: string, 1: string}>>}>
     */
    public static function urlapok(): array
    {
        $lap = static function (string $fajta): array {
            $javitas  = $fajta === 'javitas';
            $rendeles = $fajta === 'rendeles';
            $eladas   = !$javitas;

            $bal = [
                'allapot'       => ['Állapotválasztó', 'A lap állapota (a választható állapotok lent szűkíthetők).'],
                'nev'           => ['Név (tárgy)', 'Rövid tárgy, pl. „kijelzőcsere".'],
                'felelos'       => ['Felelős', 'Melyik kolléga viszi a lapot.'],
                'datumok'       => ['Dátumok', 'Készült, ' . ($rendeles ? 'Várható érkezés' : 'Határidő') . ', Lezárva.'],
                'hatarido'      => [$rendeles ? 'Várható érkezés' : 'Határidő', 'Csak ez az egy dátum.'],
                'osszeg'        => ['Összeg (nettó, áfa, bruttó)', 'A bal oldali összegdoboz.'],
                'tetel_beall'   => ['Kedvezmény és áfakulcs', 'A minden tételsorra érvényes kedvezmény és áfa.'],
                'fizetes'       => ['Fizetés doboz', 'Fizetési mód, Fizetve, fizetés ideje, előleg, fizetendő.'],
                'fizetes_ideje' => ['Fizetés ideje', 'Csak a dátum.'],
                'eloleg'        => ['Fizetett (előleg)', 'Az előleg mezője.'],
                'bevizsgalas'   => ['Bevizsgálási díj', 'A díj pipája, a díjlista és a „Ha az ügyfél kéri a javítást" választó.'],
            ];

            if ($eladas) {
                unset($bal['bevizsgalas']);
            }

            if ($fajta === 'arukiadas') {
                unset($bal['hatarido']);
            }

            $fo = [
                'eladas_mezok' => [$rendeles ? 'Beszállító' : 'Helyszín', $rendeles ? 'A rendelés forrása.' : 'Melyik üzlet adta ki (egy telephelynél amúgy sem látszik).'],
                'rendeles_sor' => ['Hol tart / Rendelésszám', 'A rendelés útja és a beszállító rendelésszáma.'],
                'ugyfelkartya' => ['Ügyfélkártya', 'Az ügyfél adatai a lapfülek fölött.'],
                'eszkozpanel'  => ['Eszközök a lapon', 'Az eszközkártyák és az Eszköz csatolása gomb.'],
                'ful_eszkoz'   => ['Eszköz lapfül', 'Az ügyfél és a készülék összefoglalója.'],
                'ful_hibak'    => ['Hibák lapfül', 'A hibasorok.'],
                'ful_szolg'    => ['Szolgáltatások lapfül', 'A szolgáltatás-tételek.'],
                'ful_termek'   => ['Termékek lapfül', 'A termék-tételek.'],
                'ful_rma'      => ['RMA / Címke lapfül', 'Ügyfélüzenetek, RMA-oldal, címke.'],
                'megjegyzes'   => ['Megjegyzés', 'Az ügyfél felé is megjelenő megjegyzés lapfüle.'],
                'belso'        => ['Belső megjegyzés', 'Csak a CRM-ben látszó megjegyzés lapfüle.'],
                'gyakori_megj' => ['Gyakori megjegyzések gomb', 'Előre megírt megjegyzések popupból.'],
            ];

            if ($javitas) {
                unset($fo['eladas_mezok'], $fo['rendeles_sor'], $fo['ugyfelkartya']);
            } else {
                unset($fo['eszkozpanel'], $fo['ful_eszkoz'], $fo['ful_hibak']);
            }

            if ($fajta === 'arukiadas') {
                unset($fo['rendeles_sor'], $fo['ful_rma']);
            }

            $gombok = [
                'gomb_fizetes'    => ['Fizetés', 'Fizetés rögzítése és pénztárba írás.'],
                'gomb_nyomtatas'  => ['Nyomtatás', 'Nyomtatványok, PDF, e-mail.'],
                'gomb_matrica'    => ['Címke / matrica', 'Brother címkenyomtatás.'],
                'gomb_szamlazz'   => ['Számlázz.hu számla', 'A számla ablaka.'],
                'gomb_elokeszito' => ['Számla-előkészítő', 'A helyben készülő PDF.'],
            ];

            if ($fajta === 'arukiadas') {
                unset($gombok['gomb_matrica']);
            }

            return [
                'Bal oldal' => $bal,
                'Lap'       => $fo,
                'Gombok'    => $gombok,
            ];
        };

        $szamla = static function (bool $helyi): array {
            $hova = $helyi ? 'nyomtatványra' : 'számlára';

            return [
                'Automatikus kitöltés' => [
                    'auto_tetelek'   => ['A munkalap tételei magától a ' . $hova, 'Kikapcsolva a tételek pipa nélkül nyílnak: te pipálod be, amit kell, vagy Kézi tétellel írod. Az ablakban egy kapcsolóval mindet egyszerre be- vagy kipipálhatod.'],
                    'auto_tetel_megj' => ['Tétel-megjegyzés magától (eszköz neve, IMEI)', 'Több eszközös lapon a tétel alá írt készülék és azonosító.'],
                    'auto_megjegyzes' => ['A Megjegyzés mező magától', 'pl. „Munkalap: 26 · Samsung …" – kikapcsolva üresen nyílik.'],
                ],
                'Látszik' => [
                    'gyorskapcsolo' => ['„Tételek automatikusan" kapcsoló az ablakban', 'Egy kattintással mind be- vagy kipipálva – csak abban az ablakban, a beállítás marad.'],
                    'eszkozvalaszto' => ['„Mi kerül a ' . $hova . '" eszközválasztó', 'Több eszközös lapon: közös vagy eszközönkénti bizonylat.'],
                    'uj_tetel'       => ['Termék… / Szolgáltatás… / Kézi tétel gombok', 'Új tétel felvétele az ablakban.'],
                ],
            ];
        };

        $elokeszito = class_exists('SDH_Muhely_Szamla') ? SDH_Muhely_Szamla::nyomtatvany_cim() : 'Számla-előkészítő';

        return [
            'javitas'    => ['cim' => 'Munkalap', 'csoportok' => $lap('javitas')],
            'rendeles'   => ['cim' => 'Rendelés', 'csoportok' => $lap('rendeles')],
            'arukiadas'  => ['cim' => 'Napi árukiadás', 'csoportok' => $lap('arukiadas')],
            'szamla'     => ['cim' => 'Számlázz.hu számla', 'csoportok' => $szamla(false)],
            'elokeszito' => ['cim' => $elokeszito, 'csoportok' => $szamla(true)],
        ];
    }

    /** Munkalapfajta-e (van állapotlistája). */
    private static function lapfajta(string $urlap): bool
    {
        return in_array($urlap, ['javitas', 'rendeles', 'arukiadas'], true);
    }

    /** Az űrlap összes elemkulcsa. @return string[] */
    private static function elemek(string $urlap): array
    {
        $u = self::urlapok()[$urlap] ?? null;

        if ($u === null) {
            return [];
        }

        $ki = [];

        foreach ($u['csoportok'] as $elemek) {
            $ki = array_merge($ki, array_keys($elemek));
        }

        return $ki;
    }

    /* =================================================================
     * Olvasás
     * ============================================================== */

    /** @return array<string, array{ki: string[], allapot_ki: string[]}> */
    public static function beallitas(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $m  = get_option(self::OPT, []);
        $m  = is_array($m) ? $m : [];
        $ki = [];

        foreach (array_keys(self::urlapok()) as $urlap) {
            $sor = isset($m[$urlap]) && is_array($m[$urlap]) ? $m[$urlap] : [];

            $ki[$urlap] = [
                'ki'         => array_values(array_map('strval', isset($sor['ki']) && is_array($sor['ki']) ? $sor['ki'] : [])),
                'allapot_ki' => array_values(array_map('strval', isset($sor['allapot_ki']) && is_array($sor['allapot_ki']) ? $sor['allapot_ki'] : [])),
            ];
        }

        return self::$cache = $ki;
    }

    /** Látszik / be van-e kapcsolva az elem (alap: igen). */
    public static function be(string $urlap, string $elem): bool
    {
        $b = self::beallitas()[$urlap] ?? null;

        return $b === null || !in_array($elem, $b['ki'], true);
    }

    /**
     * A jelölő attribútum az elemre: data-sdh-ub="elem", és ha ki van
     * kapcsolva, data-sdh-ub-ki (admin.css: display none). A fogaskerék
     * popupja ezt kapcsolgatja élőben (urlap-beallitas.js).
     */
    public static function jel(string $urlap, string $elem): string
    {
        return ' data-sdh-ub="' . esc_attr($elem) . '"' . (self::be($urlap, $elem) ? '' : ' data-sdh-ub-ki=""');
    }

    /** A munkalapfajta választható állapota-e (a jelenlegi és az alap mindig az). */
    public static function allapot_be(string $fajta, string $kulcs, string $jelenlegi = ''): bool
    {
        if ($kulcs === $jelenlegi || !self::lapfajta($fajta)) {
            return true;
        }

        $alap = SDH_Muhely_Munkalap::alap_kulcs(SDH_Muhely_Munkalap::allapotok());

        return $kulcs === $alap || !in_array($kulcs, self::beallitas()[$fajta]['allapot_ki'], true);
    }

    /**
     * A munkalapfajta állapotai a fogaskerék listájához.
     *
     * @return array<string, string> kulcs => név
     */
    private static function allapot_lista(string $fajta): array
    {
        $osszes = SDH_Muhely_Munkalap::allapotok();
        $lista  = $fajta === 'javitas' ? $osszes : SDH_Muhely_Munkalap::eladas_allapotok($osszes);
        $ki     = [];

        foreach ($lista as $kulcs => $a) {
            if (!empty($a['reszben'])) {
                continue; // magától áll be több eszköznél – nem választható
            }

            $ki[(string) $kulcs] = (string) ($a['nev'] ?? $kulcs);
        }

        return $ki;
    }

    /* =================================================================
     * Rajzolás: a fogaskerék és a kapcsolók
     * ============================================================== */

    /** Admin állíthatja (a kollégák nem tudják véletlenül átállítani). */
    public static function allithat(): bool
    {
        return SDH_Muhely_Admin_UI::admin_e();
    }

    /** A fogaskerék (az ablak jobb felső sarkában) és a lenyíló kapcsolói; nem adminnak üres. A címsor ELÉ kerül, az űrlapon kívül. */
    public static function gomb(string $urlap): string
    {
        if (!self::allithat() || !isset(self::urlapok()[$urlap])) {
            return '';
        }

        $cim = self::urlapok()[$urlap]['cim'];

        return '<div class="sdh-ub" data-sdh-ub-urlap="' . esc_attr($urlap) . '">'
            . '<button type="button" class="sdh-ub-gomb" data-sdh-ub-nyit="' . esc_attr($urlap) . '"'
            . ' title="' . esc_attr($cim . ' – mi látszódjon az űrlapon (a beállítás megmarad)') . '"'
            . ' aria-label="' . esc_attr($cim . ' beállításai') . '" aria-expanded="false">'
            . '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 15.2a3.2 3.2 0 1 0 0-6.4 3.2 3.2 0 0 0 0 6.4Z"/>'
            . '<path d="M19.4 13.5a7.6 7.6 0 0 0 0-3l2-1.6-2-3.4-2.4 1a7.5 7.5 0 0 0-2.6-1.5L14 2.5h-4l-.4 2.5A7.5 7.5 0 0 0 7 6.5l-2.4-1-2 3.4 2 1.6a7.6 7.6 0 0 0 0 3l-2 1.6 2 3.4 2.4-1a7.5 7.5 0 0 0 2.6 1.5l.4 2.5h4l.4-2.5a7.5 7.5 0 0 0 2.6-1.5l2.4 1 2-3.4-2-1.6Z"/></svg>'
            . '</button>'
            . self::panel($urlap, true)
            . '</div>';
    }

    /**
     * A kapcsolók egy űrlaphoz. $lebego: a fogaskerék lenyíló panelje (saját
     * mentéssel, AJAX); különben a Beállítások lapfülének része (közös Mentés).
     */
    public static function panel(string $urlap, bool $lebego): string
    {
        $u = self::urlapok()[$urlap] ?? null;

        if ($u === null) {
            return '';
        }

        $b      = self::beallitas()[$urlap];
        $elotag = $lebego ? '' : 'ub[' . $urlap . ']';
        $nev    = static fn (string $mit): string => $elotag === '' ? $mit . '[]' : $elotag . '[' . $mit . '][]';

        ob_start();
        ?>
        <div class="sdh-ub-panel<?php echo $lebego ? ' sdh-ub-panel--lebego' : ''; ?>" data-sdh-ub-panel="<?php echo esc_attr($urlap); ?>"<?php echo $lebego ? ' hidden' : ''; ?>>
            <?php if ($lebego) : ?>
                <div class="sdh-ub-panel__fej">
                    <strong><?php echo esc_html($u['cim']); ?> – mi látszódjon</strong>
                    <span>Kapcsold ki, ami nem kell. Élőben látszik, és a Mentés után minden megnyitáskor így marad – frissítés után is.</span>
                </div>
            <?php endif; ?>
            <?php // A „be” pipák látszanak; mentéskor a ki NEM pipáltak listája tárolódik. ?>
            <input type="hidden" name="<?php echo esc_attr($elotag === '' ? 'urlap' : $elotag . '[jelen]'); ?>" value="<?php echo esc_attr($urlap); ?>">
            <div class="sdh-ub-panel__racs">
                <?php foreach ($u['csoportok'] as $csoport => $elemek) : ?>
                    <fieldset class="sdh-ub-panel__csoport">
                        <legend><?php echo esc_html($csoport); ?></legend>
                        <?php foreach ($elemek as $elem => $adat) : ?>
                            <label class="sdh-kapcsolo sdh-ub-kapcs" title="<?php echo esc_attr($adat[1]); ?>">
                                <input type="checkbox" name="<?php echo esc_attr($nev('be')); ?>" value="<?php echo esc_attr($elem); ?>"
                                       data-sdh-ub-elem="<?php echo esc_attr($elem); ?>" <?php checked(!in_array($elem, $b['ki'], true)); ?>>
                                <span class="sdh-kapcsolo__csuszka" aria-hidden="true"></span>
                                <span class="sdh-kapcsolo__felirat"><?php echo esc_html($adat[0]); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </fieldset>
                <?php endforeach; ?>

                <?php if (self::lapfajta($urlap)) : ?>
                    <?php $alap = SDH_Muhely_Munkalap::alap_kulcs(SDH_Muhely_Munkalap::allapotok()); ?>
                    <fieldset class="sdh-ub-panel__csoport">
                        <legend>Választható állapotok</legend>
                        <?php foreach (self::allapot_lista($urlap) as $kulcs => $allnev) : ?>
                            <label class="sdh-kapcsolo sdh-ub-kapcs" title="<?php echo esc_attr($kulcs === $alap ? 'Az új lap alapállapota – mindig választható.' : 'Kikapcsolva nem választható ezen az űrlapon (ami a lapon épp áll, az mindig látszik).'); ?>">
                                <input type="checkbox" name="<?php echo esc_attr($nev('allapot_be')); ?>" value="<?php echo esc_attr($kulcs); ?>"
                                       data-sdh-ub-allapot="<?php echo esc_attr($kulcs); ?>"
                                    <?php checked($kulcs === $alap || !in_array($kulcs, $b['allapot_ki'], true)); ?>
                                    <?php disabled($kulcs === $alap); ?>>
                                <span class="sdh-kapcsolo__csuszka" aria-hidden="true"></span>
                                <span class="sdh-kapcsolo__felirat"><?php echo esc_html($allnev); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </fieldset>
                <?php endif; ?>
            </div>
            <?php if ($lebego) : ?>
                <div class="sdh-ub-panel__lab">
                    <button type="button" class="sdh-gomb sdh-gomb--elsodleges" data-sdh-ub-ment>Mentés</button>
                    <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-ub-alap title="Minden elem újra látszik, minden állapot választható">Mind be</button>
                    <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-ub-bezar>Bezárás</button>
                    <span class="sdh-ub-panel__allapot" data-sdh-ub-allapotsor role="status" aria-live="polite"></span>
                </div>
            <?php endif; ?>
        </div>
        <?php

        return (string) ob_get_clean();
    }

    /* =================================================================
     * Mentés
     * ============================================================== */

    /**
     * Egy űrlap beküldött kapcsolóiból a tárolt alak: a NEM pipált elemek.
     *
     * @param mixed $be           a pipált elemek
     * @param mixed $allapot_be   a pipált állapotok
     * @return array{ki: string[], allapot_ki: string[]}
     */
    private static function feldolgoz(string $urlap, $be, $allapot_be): array
    {
        $be         = is_array($be) ? array_map(static fn ($x): string => sanitize_key((string) $x), $be) : [];
        $allapot_be = is_array($allapot_be) ? array_map(static fn ($x): string => sanitize_text_field((string) $x), $allapot_be) : [];

        $ki = array_values(array_diff(self::elemek($urlap), $be));

        $allapot_ki = [];

        if (self::lapfajta($urlap)) {
            $alap = SDH_Muhely_Munkalap::alap_kulcs(SDH_Muhely_Munkalap::allapotok());

            foreach (array_keys(self::allapot_lista($urlap)) as $kulcs) {
                if ($kulcs !== $alap && !in_array($kulcs, $allapot_be, true)) {
                    $allapot_ki[] = $kulcs;
                }
            }
        }

        return ['ki' => $ki, 'allapot_ki' => $allapot_ki];
    }

    /** Egy űrlap tárolása (a többihez nem nyúl). */
    public static function ment(string $urlap, array $ertek): void
    {
        $m = get_option(self::OPT, []);
        $m = is_array($m) ? $m : [];

        $m[$urlap] = $ertek;

        update_option(self::OPT, $m, true);
        self::$cache = null;
    }

    /** A fogaskerék Mentés gombja (AJAX, admin). */
    public static function ajax_mentes(): void
    {
        check_ajax_referer('sdh_muhely_modal');

        if (!self::allithat()) {
            wp_send_json_error(['uzenet' => 'Az űrlapok beállításához adminisztrátori jog kell.'], 403);
        }

        // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- feldolgoz() tisztít.
        $urlap = isset($_POST['urlap']) ? sanitize_key(wp_unslash($_POST['urlap'])) : '';

        if (!isset(self::urlapok()[$urlap])) {
            wp_send_json_error(['uzenet' => 'Ismeretlen űrlap.']);
        }

        $ertek = self::feldolgoz(
            $urlap,
            isset($_POST['be']) ? wp_unslash($_POST['be']) : [],
            isset($_POST['allapot_be']) ? wp_unslash($_POST['allapot_be']) : []
        );
        // phpcs:enable

        self::ment($urlap, $ertek);

        wp_send_json_success($ertek + ['uzenet' => 'Elmentve – így marad minden megnyitáskor.']);
    }

    /* =================================================================
     * Beállítások › Űrlapok
     * ============================================================== */

    public static function beallitas_doboz(): void
    {
        ?>
        <div class="sdh-doboz sdh-ub-beall">
        <p class="sdh-mezo__sugo">
            Minden űrlapnál kapcsold ki, ami nem kell. Ugyanez az űrlapok címsorában álló fogaskerékkel is állítható
            (ott élőben látszik a változás). A beállítás a bővítmény frissítése után is megmarad.
            Az elrejtett mező értéke nem vész el, csak nem látszik.
        </p>
        <div class="sdh-ub-lapok">
            <?php foreach (self::urlapok() as $urlap => $u) : ?>
                <details class="sdh-ub-lap" open>
                    <summary><?php echo esc_html($u['cim']); ?></summary>
                    <?php echo self::panel($urlap, false); // phpcs:ignore WordPress.Security.EscapeOutput -- a panel() escapel. ?>
                </details>
            <?php endforeach; ?>
        </div>
        </div>
        <?php
    }

    public static function beallitas_mentes(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- a Beállítások ellenőrizte; feldolgoz() tisztít.
        $ub = isset($_POST['ub']) && is_array($_POST['ub']) ? wp_unslash($_POST['ub']) : [];

        foreach (array_keys(self::urlapok()) as $urlap) {
            if (!isset($ub[$urlap]['jelen'])) {
                continue;
            }

            self::ment($urlap, self::feldolgoz($urlap, $ub[$urlap]['be'] ?? [], $ub[$urlap]['allapot_be'] ?? []));
        }
    }

    /** Teszteknek: a gyorsítótár ürítése. */
    public static function urit(): void
    {
        self::$cache = null;
    }
}
