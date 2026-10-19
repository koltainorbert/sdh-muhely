<?php
/**
 * SDH Értékesítés – Szikra eszközei (Anthropic tool use):
 *   csomag_ajanlo   – igények (felhasználók, telephelyek, modulok, ciklus) → ajánlott csomag + ár (SDH_Sales_Ar::szamol, ha van)
 *   lead_mentes     – név, e-mail, cég, igény → `lead` tábla (forras: szikra) – CSAK a felületen adott kifejezett hozzájárulással
 *   idopont_link    – időpontfoglalás linkje (demó, telepítés, oktatás, support)
 *   atadas_emberre  – emberi kolléga bevonása: lead (hozzájárulással) + hogyan és mikor keresnek
 *
 * A hozzájárulás nem a modell döntése: a látogató a csevegőben megjelenő gombbal adja meg (REST `hozzajarulas: true`).
 * Hozzájárulás nélkül a kért mentés függőben marad (munkamenetenként, 1 óráig), és a gomb megnyomásakor a szerver menti.
 *
 * @package SDH_Sales
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Sales_Szikra_Eszkozok
{
    /** @var array<string, mixed> az aktuális kérés környezete (munkamenet, hozzájárulás) */
    private static array $kornyezet = [];

    /** @var array<string, mixed> amit az eszközök a válaszba tesznek (hozzajarulas_kell, lead, atadva) */
    public static array $jelzes = [];

    /** @param array<string, mixed> $k */
    public static function kornyezet(array $k): void
    {
        self::$kornyezet = $k;
        self::$jelzes    = [];
    }

    /** @return array<int, array<string, mixed>> a modellnek átadott eszköz-leírások */
    public static function definiciok(): array
    {
        $modul_kulcsok = array_values(array_map(static fn ($m) => (string) $m['kulcs'], SDH_Sales_Tartalom::lista('modul')));
        $modul_tulaj   = ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'A kért modulok kulcsai (a tudásban „Modul: … (kulcs: …)” alakban szerepelnek).'];

        if ($modul_kulcsok !== []) {
            $modul_tulaj['items']['enum'] = $modul_kulcsok;
        }

        return [
            [
                'name'         => 'csomag_ajanlo',
                'description'  => 'Csomagot ajánl és árat számol a látogató igényei alapján, a webhely aktuális árlistájából. Árat CSAK ennek az eredményéből vagy a tudásból mondj.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'felhasznalo' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'description' => 'Hány kolléga használja egyszerre (felhasználói fiók).'],
                        'telephely'   => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'description' => 'Hány üzlet / telephely.'],
                        'modulok'     => $modul_tulaj,
                        'ciklus'      => ['type' => 'string', 'enum' => ['havi', 'eves'], 'description' => 'Fizetési ciklus.'],
                    ],
                    'required'   => ['felhasznalo'],
                ],
            ],
            [
                'name'         => 'lead_mentes',
                'description'  => 'Elmenti a látogató elérhetőségét, hogy egy kolléga felvegye vele a kapcsolatot. Csak akkor hívd, ha a látogató maga adta meg az adatait és kérte a kapcsolatfelvételt. A mentéshez a felületen külön hozzájárulás kell: ha az eredmény szerint még nincs, kérd meg, hogy a megjelenő gombbal járuljon hozzá.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'nev'   => ['type' => 'string', 'maxLength' => 120],
                        'email' => ['type' => 'string', 'maxLength' => 190],
                        'ceg'   => ['type' => 'string', 'maxLength' => 190],
                        'telefon' => ['type' => 'string', 'maxLength' => 40],
                        'igeny' => ['type' => 'string', 'maxLength' => 1500, 'description' => 'Röviden: mire van szüksége (felhasználók, telephelyek, modulok, kérdések).'],
                    ],
                    'required'   => ['email', 'igeny'],
                ],
            ],
            [
                'name'         => 'idopont_link',
                'description'  => 'Visszaadja az időpontfoglaló oldal linkjét (bemutató, telepítés, oktatás vagy support).',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => ['tipus' => ['type' => 'string', 'enum' => ['demo', 'telepites', 'oktatas', 'support']]],
                    'required'   => ['tipus'],
                ],
            ],
            [
                'name'         => 'atadas_emberre',
                'description'  => 'Emberi kollégához irányítja a látogatót: ha bizonytalan vagy, ha a kérdés egyedi ajánlatot, szerződést, panaszt vagy olyat érint, amit a tudás nem fed le, vagy ha a látogató kéri. Ha a látogató megadta az e-mail-címét, add át – a mentéshez itt is a felületen adott hozzájárulás kell.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'ok'            => ['type' => 'string', 'maxLength' => 300, 'description' => 'Miért kell ember.'],
                        'osszefoglalo'  => ['type' => 'string', 'maxLength' => 1500, 'description' => 'A beszélgetés lényege a kollégának.'],
                        'nev'           => ['type' => 'string', 'maxLength' => 120],
                        'email'         => ['type' => 'string', 'maxLength' => 190],
                        'telefon'       => ['type' => 'string', 'maxLength' => 40],
                    ],
                    'required'   => ['ok', 'osszefoglalo'],
                ],
            ],
        ];
    }

    /**
     * Egy eszköz futtatása. A visszaadott tömb kerül JSON-ként a tool_result-ba.
     *
     * @param mixed $bemenet
     * @return array<string, mixed>
     */
    public static function futtat(string $nev, $bemenet): array
    {
        $b = is_array($bemenet) ? $bemenet : [];

        switch ($nev) {
            case 'csomag_ajanlo':
                return self::csomag_ajanlo($b);
            case 'lead_mentes':
                return self::lead_mentes($b);
            case 'idopont_link':
                return self::idopont_link($b);
            case 'atadas_emberre':
                return self::atadas_emberre($b);
        }

        return ['hiba' => 'Ismeretlen eszköz.'];
    }

    /* =================================================================
     * csomag_ajanlo
     * ============================================================== */

    /**
     * @param array<string, mixed> $b
     * @return array<string, mixed>
     */
    public static function csomag_ajanlo(array $b): array
    {
        $fh      = max(1, min(500, (int) ($b['felhasznalo'] ?? 1)));
        $th      = max(1, min(100, (int) ($b['telephely'] ?? 1)));
        $ciklus  = ($b['ciklus'] ?? 'eves') === 'havi' ? 'havi' : 'eves';
        $kert    = array_values(array_unique(array_filter(array_map('sanitize_key', (array) ($b['modulok'] ?? [])))));
        $csomagok = SDH_Sales_Tartalom::lista('csomag');
        $modulok  = [];

        foreach (SDH_Sales_Tartalom::lista('modul') as $m) {
            $modulok[(string) $m['kulcs']] = $m;
        }

        if ($csomagok === []) {
            return ['hiba' => 'A csomagok árlistája most nem érhető el. Ajánld fel, hogy egy kolléga pontos ajánlatot küld (atadas_emberre).'];
        }

        $ismeretlen = array_values(array_diff($kert, array_keys($modulok)));
        $kert       = array_values(array_intersect($kert, array_keys($modulok)));
        $jeloltek   = [];

        foreach ($csomagok as $cs) {
            $a     = (array) $cs['adat'];
            $kulcs = (string) $cs['kulcs'];
            $benne = static function (string $mk) use ($a, $kulcs, $modulok): bool {
                $lista = $a['modulok'] ?? [];

                if ($lista === '*' || (is_array($lista) && in_array('*', $lista, true))) {
                    return true;
                }

                return (is_array($lista) && in_array($mk, $lista, true)) || in_array($kulcs, (array) ($modulok[$mk]['adat']['csomagban'] ?? []), true);
            };

            $hianyzo = array_values(array_filter($kert, static fn ($mk) => !$benne($mk)));
            $ar      = self::ar($kulcs, $a, $fh, $th, $ciklus, $hianyzo, $modulok);

            if ($ar === null) {
                continue;
            }

            $max_fh = (int) ($a['max_felhasznalo'] ?? 0);

            if ($max_fh > 0 && $fh > $max_fh) {
                continue;
            }

            $jeloltek[] = [
                'csomag'           => $kulcs,
                'nev'              => SDH_Sales::t((string) ($a['nev'] ?? $cs['cim'])),
                'kulon_modulok'    => $hianyzo,
                'netto'            => $ar['netto'],
                'brutto'           => $ar['brutto'],
                'sorok'            => $ar['sorok'],
                'forras'           => $ar['forras'],
            ];
        }

        if ($jeloltek === []) {
            return ['hiba' => 'Ehhez az igényhez nincs a webhelyen számolható csomag. Ajánld fel az egyedi ajánlatot (atadas_emberre).'];
        }

        usort($jeloltek, static fn ($x, $y) => [count($x['kulon_modulok']) > 0, $x['netto']] <=> [count($y['kulon_modulok']) > 0, $y['netto']]);
        $javasolt = $jeloltek[0];
        $per      = $ciklus === 'havi' ? ' / hó' : ' / év';

        return [
            'igeny'          => ['felhasznalo' => $fh, 'telephely' => $th, 'modulok' => $kert, 'ciklus' => $ciklus],
            'ismeretlen_modulok' => $ismeretlen,
            'javasolt'       => $javasolt + ['ar_szoveg' => SDH_Sales::ft($javasolt['netto']) . ' + áfa' . $per . ' (bruttó ' . SDH_Sales::ft($javasolt['brutto']) . $per . ')'],
            'alternativak'   => array_map(static fn ($j) => ['csomag' => $j['csomag'], 'nev' => $j['nev'], 'netto' => $j['netto'], 'brutto' => $j['brutto'], 'kulon_modulok' => $j['kulon_modulok']], array_slice($jeloltek, 1, 2)),
            'afa'            => SDH_Sales::afa(),
            'penznem'        => (string) SDH_Sales::b('penznem', 'HUF'),
            'arak_oldal'     => SDH_Sales_Szikra_Tudas::oldal_url((string) SDH_Sales_Szikra::b('szikra.arak_url')),
            'megjegyzes'     => 'Minden ár nettó, egész forint; a rendelésnél mindig a szerver által számolt ár érvényes. Kupon és egyedi kedvezmény nincs benne.',
        ];
    }

    /**
     * Egy csomag ára az igényre. Ha van SDH_Sales_Ar::szamol, azt használja (a rendelés is azt használja).
     *
     * @param array<string, mixed>              $a
     * @param array<int, string>                $kulon
     * @param array<string, array<string, mixed>> $modulok
     * @return array{netto: int, brutto: int, sorok: array<int, array<string, mixed>>, forras: string}|null
     */
    private static function ar(string $kulcs, array $a, int $fh, int $th, string $ciklus, array $kulon, array $modulok): ?array
    {
        if (class_exists('SDH_Sales_Ar') && method_exists('SDH_Sales_Ar', 'szamol')) {
            $tetelek = [['tipus' => 'csomag', 'kulcs' => $kulcs, 'db' => 1]];

            foreach ($kulon as $mk) {
                $tetelek[] = ['tipus' => 'modul', 'kulcs' => $mk, 'db' => 1];
            }

            try {
                $e = SDH_Sales_Ar::szamol($tetelek, ['ciklus' => $ciklus, 'felhasznalo' => $fh, 'telephely' => $th]);

                if (is_array($e) && empty($e['hibak']) && isset($e['netto'])) {
                    return ['netto' => (int) $e['netto'], 'brutto' => (int) ($e['brutto'] ?? SDH_Sales::brutto((int) $e['netto'])), 'sorok' => array_values((array) ($e['sorok'] ?? [])), 'forras' => 'arkalkulator'];
                }
            } catch (\Throwable $t) {
                SDH_Sales_Naplo::ir('szikra', 'Árkalkulátor-hiba a csomagajánlóban: ' . $t->getMessage(), 'hiba');
            }
        }

        // Tartalék: ugyanaz a szabály, mint az árlistában (data/arak.json séma).
        $havi = (int) ($a['havi'] ?? 0);
        $eves = (int) ($a['eves'] ?? 0);

        if ($havi <= 0 && $eves <= 0) {
            return null;
        }

        $szorzo = $ciklus === 'havi' ? 1 : 12;
        $kedv   = max(0, min(90, (int) SDH_Sales::b('eves_kedvezmeny', 0)));
        $alap   = $ciklus === 'havi' ? ($havi > 0 ? $havi : (int) round($eves / 12)) : ($eves > 0 ? $eves : (int) round($havi * 12 * (100 - $kedv) / 100));
        $sorok  = [['nev' => (string) ($a['nev'] ?? $kulcs), 'netto' => $alap]];
        $extra  = static function (int $havi_dij, int $db) use ($ciklus, $szorzo, $kedv): int {
            $o = $havi_dij * $db * $szorzo;

            return $ciklus === 'eves' ? (int) round($o * (100 - $kedv) / 100) : $o;
        };

        $fh_tobb = max(0, $fh - (int) ($a['felhasznalo_alap'] ?? 1));
        $th_tobb = max(0, $th - (int) ($a['telephely_alap'] ?? 1));

        if ($fh_tobb > 0) {
            if ((int) ($a['felhasznalo_extra_havi'] ?? 0) <= 0) {
                return null;
            }

            $sorok[] = ['nev' => '+' . $fh_tobb . ' felhasználó', 'netto' => $extra((int) $a['felhasznalo_extra_havi'], $fh_tobb)];
        }

        if ($th_tobb > 0) {
            if ((int) ($a['telephely_extra_havi'] ?? 0) <= 0) {
                return null;
            }

            $sorok[] = ['nev' => '+' . $th_tobb . ' telephely', 'netto' => $extra((int) $a['telephely_extra_havi'], $th_tobb)];
        }

        foreach ($kulon as $mk) {
            $m  = (array) ($modulok[$mk]['adat'] ?? []);
            $mh = (int) ($m['havi'] ?? 0);
            $me = (int) ($m['eves'] ?? 0);

            if ($mh <= 0 && $me <= 0) {
                return null;
            }

            $sorok[] = ['nev' => (string) ($m['nev'] ?? $mk) . ' modul', 'netto' => $ciklus === 'havi' ? ($mh > 0 ? $mh : (int) round($me / 12)) : ($me > 0 ? $me : $extra($mh, 1))];
        }

        $netto = array_sum(array_column($sorok, 'netto'));

        return ['netto' => $netto, 'brutto' => SDH_Sales::brutto($netto), 'sorok' => $sorok, 'forras' => 'arlista'];
    }

    /* =================================================================
     * Lead (hozzájárulással)
     * ============================================================== */

    private static function fuggo_kulcs(): string
    {
        return 'szikra_fuggo_' . md5((string) (self::$kornyezet['munkamenet'] ?? '') . '|' . SDH_Sales::ip_hash());
    }

    /** @return array<string, mixed>|null */
    public static function fuggo(): ?array
    {
        $f = get_transient(self::fuggo_kulcs());

        return is_array($f) ? $f : null;
    }

    /**
     * @param array<string, mixed> $b
     * @return array<string, string>
     */
    private static function tisztit(array $b): array
    {
        return [
            'nev'          => mb_substr(sanitize_text_field((string) ($b['nev'] ?? '')), 0, 120),
            'email'        => mb_substr(sanitize_email((string) ($b['email'] ?? '')), 0, 190),
            'ceg'          => mb_substr(sanitize_text_field((string) ($b['ceg'] ?? '')), 0, 190),
            'telefon'      => mb_substr(preg_replace('/[^0-9+()\/ -]/', '', (string) ($b['telefon'] ?? '')) ?? '', 0, 40),
            'igeny'        => mb_substr(sanitize_textarea_field((string) ($b['igeny'] ?? $b['osszefoglalo'] ?? '')), 0, 1500),
            'ok'           => mb_substr(sanitize_text_field((string) ($b['ok'] ?? '')), 0, 300),
        ];
    }

    /**
     * Lead mentése a táblába (a hozzájárulást a hívó ellenőrzi). Munkamenetenként egy lead: ismételt mentés frissít.
     *
     * @param array<string, string> $adat
     */
    public static function ment(array $adat, string $tipus): int
    {
        global $wpdb;

        $t  = SDH_Sales_Schema::t('lead');
        $mm = (string) (self::$kornyezet['munkamenet'] ?? '');
        $a  = array_filter($adat, static fn ($v) => $v !== '') + [
            'munkamenet'   => $mm,
            'tipus'        => $tipus,
            'hozzajarulas' => ['ido' => current_time('mysql', true), 'mod' => 'szikra_gomb', 'szoveg' => __('Hozzájárulok, hogy elmentsétek az adataimat, és felvegyétek velem a kapcsolatot.', 'sdh-sales')],
        ];

        $regi = $mm !== '' ? (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$t} WHERE forras = 'szikra' AND adat LIKE %s ORDER BY id DESC LIMIT 1", '%' . $wpdb->esc_like('"munkamenet":"' . $mm . '"') . '%')) : 0; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        if ($regi > 0) {
            $elozo = json_decode((string) $wpdb->get_var($wpdb->prepare("SELECT adat FROM {$t} WHERE id = %d", $regi)), true); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $a     = $a + (is_array($elozo) ? $elozo : []);
            $wpdb->update($t, ['email' => (string) ($a['email'] ?? ''), 'adat' => (string) wp_json_encode($a, JSON_UNESCAPED_UNICODE)], ['id' => $regi]);
            $id = $regi;
        } else {
            $wpdb->insert($t, [
                'forras'  => 'szikra',
                'email'   => (string) ($a['email'] ?? ''),
                'adat'    => (string) wp_json_encode($a, JSON_UNESCAPED_UNICODE),
                'allapot' => 'uj',
                'ido'     => current_time('mysql', true),
                'ip_hash' => SDH_Sales::ip_hash(),
            ]);
            $id = (int) $wpdb->insert_id;
            SDH_Sales_Naplo::ir('lead', 'Új érdeklődő Szikrától' . ($tipus === 'atadas' ? ' (emberi átadás)' : ''), 'info', 'lead', $id);
            self::ertesit($id, $a);
        }

        delete_transient(self::fuggo_kulcs());
        do_action('sdh_sales_lead', $id, 'szikra', $a);
        self::$jelzes['lead'] = true;

        return $id;
    }

    /** @param array<string, mixed> $a */
    private static function ertesit(int $id, array $a): void
    {
        $cim = sanitize_email((string) SDH_Sales::b('email.ertesites', ''));

        if ($cim === '') {
            return;
        }

        $sor = [];

        foreach (['nev' => 'Név', 'email' => 'E-mail', 'telefon' => 'Telefon', 'ceg' => 'Cég', 'igeny' => 'Igény', 'ok' => 'Átadás oka'] as $k => $c) {
            if (!empty($a[$k])) {
                $sor[] = $c . ': ' . (string) $a[$k];
            }
        }

        wp_mail($cim, '[' . SDH_Sales::termeknev() . '] Új érdeklődő Szikrától (#' . $id . ')', implode("\n", $sor));
    }

    /**
     * @param array<string, mixed> $b
     * @return array<string, mixed>
     */
    public static function lead_mentes(array $b, string $tipus = 'lead'): array
    {
        $adat = self::tisztit($b);

        if (!is_email($adat['email'])) {
            return ['mentve' => false, 'hiba' => 'Érvényes e-mail-cím kell. Kérd el a látogatótól (ne találd ki).'];
        }

        if (empty(self::$kornyezet['hozzajarulas'])) {
            set_transient(self::fuggo_kulcs(), ['adat' => $adat, 'tipus' => $tipus], HOUR_IN_SECONDS);
            self::$jelzes['hozzajarulas_kell'] = true;

            return ['mentve' => false, 'hozzajarulas_kell' => true, 'teendo' => 'NEM mentetted el. Kérd meg a látogatót, hogy a csevegőben most megjelenő „Hozzájárulok” gombbal járuljon hozzá az adatai mentéséhez. Addig ne állítsd, hogy mentve van.'];
        }

        $id = self::ment($adat, $tipus);

        return ['mentve' => true, 'azonosito' => $id, 'teendo' => 'Mondd el, hogy egy kolléga munkaidőben felveszi vele a kapcsolatot a megadott címen.'];
    }

    /**
     * @param array<string, mixed> $b
     * @return array<string, mixed>
     */
    public static function idopont_link(array $b): array
    {
        $tipus = in_array($b['tipus'] ?? '', ['demo', 'telepites', 'oktatas', 'support'], true) ? (string) $b['tipus'] : 'demo';

        return ['url' => add_query_arg('tipus', $tipus, SDH_Sales_Szikra::url('szikra.idopont_url')), 'tipus' => $tipus];
    }

    /**
     * @param array<string, mixed> $b
     * @return array<string, mixed>
     */
    public static function atadas_emberre(array $b): array
    {
        self::$jelzes['atadva'] = true;

        $alap = [
            'kapcsolat_oldal' => SDH_Sales_Szikra::url('szikra.kapcsolat_url'),
            'idopont'         => self::idopont_link(['tipus' => 'demo'])['url'],
            'email'           => (string) SDH_Sales::b('ceg.email', ''),
            'telefon'         => (string) SDH_Sales::b('ceg.telefon', ''),
        ];

        $email = sanitize_email((string) ($b['email'] ?? ''));

        if ($email === '' || !is_email($email)) {
            return $alap + ['mentve' => false, 'teendo' => 'Mondd el, hogyan érheti el a kollégákat (kapcsolati űrlap, időpontfoglalás, e-mail / telefon, ha meg van adva), vagy kérd el az e-mail-címét, ha szeretné, hogy keressék.'];
        }

        $l = self::lead_mentes(['nev' => $b['nev'] ?? '', 'email' => $email, 'telefon' => $b['telefon'] ?? '', 'igeny' => $b['osszefoglalo'] ?? '', 'ok' => $b['ok'] ?? ''], 'atadas');

        return $alap + $l + ($l['mentve'] ? ['teendo' => 'Mondd el, hogy egy kolléga munkaidőben, jellemzően egy munkanapon belül e-mailben jelentkezik, és addig a fenti elérhetőségeken is elérhet minket. Ne ígérj konkrét időpontot.'] : []);
    }
}
