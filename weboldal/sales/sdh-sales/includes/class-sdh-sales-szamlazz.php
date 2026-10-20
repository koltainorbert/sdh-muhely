<?php
/**
 * SDH Értékesítés – Számlázz.hu Számla Agent: díjbekérő a rendeléskor, végszámla a fizetés után, védett PDF-tár,
 * fizetettség-lekérdezés, IPN (fizetési értesítés), minta mód (helyi „MINTA” PDF, kulcs nélkül).
 *
 * API (a többi modul ezt hívja):
 *   dijbekero(int $rendeles_id): array{ok, szamlaszam, pdf, uzenet}   – idempotens (ha van, azt adja vissza)
 *   vegszamla(int $rendeles_id, bool $kezi = false): array{ok, szamlaszam, pdf, uzenet}
 *                                                                     – idempotens; csak kifizetett rendelésre
 *   dijbekero_pdf(int $id): ?string / vegszamla_pdf(int $id): ?string – a helyi PDF abszolút útja (levélcsatolmány)
 *   pdf_link(int $rendeles_id, string $tipus = 'vegszamla'): string  – tokenes letöltő link a vevőnek
 *   fizetettseg(string $szamlaszam): ?array                            – a Számlázz.hu szerinti kifizetések
 *   automatikus(): bool  – a kiszolgálási lánc `szamla` lépése magától hívja-e a vegszamla()-t (Norbi: igen)
 *   mod(): 'eles' | 'teszt' | 'minta' | 'ki'
 * Hookok: `sdh_sales_rendeles_letrehozva` (5) → díjbekérő átutalásnál; kimenő: `sdh_sales_szamla_kesz` ($id, $tipus, $szamlaszam).
 * Titkok: `szamlazz_agent_kulcs` (éles), `szamlazz_teszt_kulcs` (teszt). Leírás: weboldal/dokumentacio/szamlazz.md.
 *
 * @package SDH_Sales
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Sales_Szamlazz
{
    public const AGENT_URL = 'https://www.szamlazz.hu/szamla/';
    public const MAPPA     = 'portal-szamla';

    /** A Számlázz.hu IPN küldő címei (2025-08-01-től, docs.szamlazz.hu). */
    public const IPN_IP = ['3.73.214.98', '3.76.149.232', '18.153.156.51'];

    /** Ugyanazt a kérést legfeljebb ennyiszer küldjük az Agentnek (a Számlázz.hu ajánlása), utána kézi újraindítás kell. */
    public const MAX_PROBA = 5;

    /** Kapcsolati / fiókhiba-kódok (nem a számla adataival van baj). */
    private const KAPCSOLAT_HIBAK = ['1', '3', '53', '57', '135', '136', '164'];

    public static function init(): void
    {
        add_action('sdh_sales_rendeles_letrehozva', [self::class, 'rendeles_letrehozva'], 5, 1);
        add_filter('rest_pre_serve_request', [self::class, 'nyers_kiszolgal'], 10, 4);

        SDH_Sales_Rest::ut('/admin/szamla/(?P<rendeles_id>\d+)/pdf', 'GET', [self::class, 'rest_admin_pdf'], 'admin');
        SDH_Sales_Rest::ut('/admin/szamla/(?P<rendeles_id>\d+)/ujra', 'POST', [self::class, 'rest_ujra'], 'admin', ['szamla_ujra', 30, 60]);
        SDH_Sales_Rest::ut('/admin/szamlazz/proba', 'POST', [self::class, 'rest_proba'], 'admin', ['szamlazz_proba', 10, 60]);
        SDH_Sales_Rest::ut('/szamla/(?P<azon>[A-Za-z0-9-]{3,40})/pdf', 'GET', [self::class, 'rest_vevo_pdf'], 'nyilvanos', ['szamla_pdf', 30, 300]);
        SDH_Sales_Rest::ut('/szamlazz/ipn', 'POST', [self::class, 'rest_ipn'], 'nyilvanos', ['szamlazz_ipn', 60, 60]);
    }

    /* =================================================================
     * Mód, beállítás
     * ============================================================== */

    private static function igen($v): bool
    {
        return in_array($v, [true, 1, '1', 'true', 'igen', 'on', 'yes'], true);
    }

    /** 'eles' (éles kulcs), 'teszt' (tesztkulcs, „MINTA” megjegyzés), 'minta' (tesztüzem kulcs nélkül: helyi PDF), 'ki'. */
    public static function mod(): string
    {
        if (self::igen(SDH_Sales::b('szamlazz.teszt', true))) {
            return SDH_Sales::titkos('szamlazz_teszt_kulcs') !== '' ? 'teszt' : 'minta';
        }

        return SDH_Sales::titkos('szamlazz_agent_kulcs') !== '' ? 'eles' : 'ki';
    }

    public static function bekotve(): bool
    {
        return self::mod() !== 'ki';
    }

    /** Sikeres fizetés után a végszámla magától megy-e (Norbi döntése: igen). */
    public static function automatikus(): bool
    {
        return self::igen(SDH_Sales::b('szamlazz.automatikus', true));
    }

    private static function kulcs(): string
    {
        $m = self::mod();

        if ($m === 'teszt') {
            return SDH_Sales::titkos('szamlazz_teszt_kulcs');
        }

        return $m === 'eles' ? SDH_Sales::titkos('szamlazz_agent_kulcs') : '';
    }

    private static function agent_url(): string
    {
        $u = defined('SDH_SALES_SZAMLAZZ_AGENT_URL') ? (string) SDH_SALES_SZAMLAZZ_AGENT_URL : self::AGENT_URL;

        return (string) apply_filters('sdh_sales_szamlazz_agent_url', $u);
    }

    /** Magyar naptári nap (a számla dátumai mindig budapesti idő szerint). */
    private static function nap(?int $ts = null): string
    {
        return wp_date('Y-m-d', $ts ?? time(), new DateTimeZone('Europe/Budapest'));
    }

    /* =================================================================
     * Rendelés olvasása
     * ============================================================== */

    /** @return array<string, mixed>|null a rendelés sora, a vevo és tetelek JSON-ja tömbként */
    public static function rendeles(int $id): ?array
    {
        global $wpdb;

        $r = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SDH_Sales_Schema::t('rendeles') . ' WHERE id = %d', $id), ARRAY_A);

        if (!is_array($r)) {
            return null;
        }

        foreach (['vevo', 'tetelek'] as $k) {
            $d     = json_decode((string) ($r[$k] ?? ''), true);
            $r[$k] = is_array($d) ? $d : [];
        }

        return $r;
    }

    private static function fizetve_e(array $r): bool
    {
        return in_array((string) $r['allapot'], ['fizetve', 'kiszolgalva'], true)
            || ((int) $r['brutto'] > 0 && (int) ($r['fizetett'] ?? 0) >= (int) $r['brutto']);
    }

    /**
     * A vevő számlázási adatai a rendelés `vevo` JSON-jából (rugalmasan: a cím lehet szöveg vagy tömb).
     *
     * @return array{adat: array<string, mixed>, hianyzik: array<int, string>}
     */
    public static function vevo(array $r): array
    {
        $v  = is_array($r['vevo'] ?? null) ? $r['vevo'] : [];
        $s  = static fn ($x): string => is_scalar($x) ? trim((string) $x) : '';
        $el = static function (array $kulcsok) use ($v, $s): string {
            foreach ($kulcsok as $k) {
                if (isset($v[$k]) && $s($v[$k]) !== '') {
                    return $s($v[$k]);
                }
            }

            return '';
        };

        $nev     = $el(['ceg', 'cegnev', 'nev', 'kapcsolattarto']);
        $orszag  = $el(['orszag']) ?: 'Magyarország';
        $irsz    = $el(['irsz', 'iranyitoszam']);
        $telep   = $el(['telepules', 'varos']);
        $utca    = $el(['utca', 'cim_utca']);
        $cim     = $v['cim'] ?? '';

        if (is_array($cim)) {
            $irsz   = $irsz ?: $s($cim['irsz'] ?? $cim['iranyitoszam'] ?? '');
            $telep  = $telep ?: $s($cim['telepules'] ?? $cim['varos'] ?? '');
            $utca   = $utca ?: $s($cim['cim'] ?? $cim['utca'] ?? '');
            $orszag = $s($cim['orszag'] ?? '') ?: $orszag;
        } elseif ($s($cim) !== '') {
            if (preg_match('/^\s*(?:H-)?(\d{4})\s+([^,]+),\s*(.+)$/u', $s($cim), $m) === 1) {
                $irsz  = $irsz ?: $m[1];
                $telep = $telep ?: trim($m[2]);
                $utca  = $utca ?: trim($m[3]);
            } elseif ($utca === '') {
                $utca = $s($cim);
            }
        }

        $adoszam = $el(['adoszam']);
        $hu      = preg_match('/^\d{8}-?\d-?\d{2}$/', $adoszam) === 1;

        if ($hu && strpos($adoszam, '-') === false) {
            $adoszam = substr($adoszam, 0, 8) . '-' . substr($adoszam, 8, 1) . '-' . substr($adoszam, 9, 2);
        }

        $ceg   = $el(['ceg', 'cegnev']) !== '';
        $email = $el(['email']) ?: $s($r['email'] ?? '');

        $adat = [
            'nev'       => $nev,
            'orszag'    => $orszag,
            'irsz'      => $irsz,
            'telepules' => $telep,
            'cim'       => $utca,
            'email'     => is_email($email) ? $email : '',
            // 1 = belföldi adószámos, 0 = nem tudjuk (pl. külföldi cég), -1 = magánszemély.
            'adoalany'  => $hu ? 1 : ($ceg ? 0 : -1),
            'adoszam'   => $hu ? $adoszam : '',
            'telefon'   => $el(['telefon']),
        ];

        $hianyzik = [];

        foreach (['nev' => 'név', 'irsz' => 'irányítószám', 'telepules' => 'település', 'cim' => 'utca, házszám'] as $k => $c) {
            if ($adat[$k] === '') {
                $hianyzik[] = $c;
            }
        }

        return ['adat' => $adat, 'hianyzik' => $hianyzik];
    }

    /**
     * A számla sorai a rendelés tételeiből. Nettó egységár × mennyiség = nettó, nettó × kulcs / 100 = áfa (2 tizedes,
     * mint a Műhelyben – így megy át az Agent ellenőrzésén). Tételek nélkül egyetlen sor a rendelés nettójával.
     * Forint-egyezés: ha minden sor egész forint és azonos kulcsú, és a sorok nettója a rendelés nettója, az áfa soronként
     * egész forintra kerül úgy (lefelé kerekítve + a legnagyobb maradékok kapják a +1 Ft-ot), hogy Σ áfa = a rendelés áfája –
     * így a díjbekérő / végszámla végösszege fillérre a fizetendő (QR, levél, kártya) összeg.
     *
     * @return array<int, array{megnevezes: string, mennyiseg: float, me: string, netto_egysegar: float, afakulcs: string, netto: float, afa: float, brutto: float, megjegyzes: string}>
     */
    public static function sorok(array $r): array
    {
        $alap_kulcs = trim((string) SDH_Sales::b('szamlazz.afakulcs', ''));
        $alap_kulcs = $alap_kulcs !== '' ? $alap_kulcs : (string) SDH_Sales::afa();
        $tized      = (int) apply_filters('sdh_sales_szamlazz_tizedes', 2);
        $sorok      = [];

        $szazalek = static fn (string $k): float => is_numeric($k) ? (float) $k : 0.0;
        $s        = static fn ($x): string => is_scalar($x) ? trim((string) $x) : '';

        foreach ((array) ($r['tetelek'] ?? []) as $t) {
            if (!is_array($t)) {
                continue;
            }

            $nev   = $s($t['megnevezes'] ?? $t['nev'] ?? $t['cim'] ?? '');
            $menny = (float) ($t['mennyiseg'] ?? $t['db'] ?? 1);
            $menny = $menny > 0 ? $menny : 1.0;
            $me    = $s($t['me'] ?? $t['mennyisegi_egyseg'] ?? '') ?: 'db';
            $kulcs = $s($t['afakulcs'] ?? '') ?: $alap_kulcs;

            if (isset($t['netto']) && is_numeric($t['netto'])) {
                $netto = (float) $t['netto'];

                if (abs(fmod($netto, $menny)) > 0.000001) {
                    // Nem osztható egész egységárra: egy sor, a mennyiség a megnevezésben.
                    $nev  .= ' (' . rtrim(rtrim(number_format($menny, 2, ',', ''), '0'), ',') . ' ' . $me . ')';
                    $menny = 1.0;
                    $me    = 'tétel';
                }

                $egysegar = round($netto / $menny, 2);
            } else {
                $egysegar = round((float) ($t['netto_egysegar'] ?? $t['egysegar'] ?? $t['ar'] ?? 0), 2);
            }

            $netto = round($egysegar * $menny, 2);
            $afa   = round($netto * $szazalek($kulcs) / 100, $tized);

            $sorok[] = [
                'megnevezes'     => mb_substr($nev !== '' ? $nev : SDH_Sales::termeknev(), 0, 250),
                'mennyiseg'      => $menny,
                'me'             => $me,
                'netto_egysegar' => $egysegar,
                'afakulcs'       => $kulcs,
                'netto'          => $netto,
                'afa'            => $afa,
                'brutto'         => round($netto + $afa, 2),
                'megjegyzes'     => mb_substr($s($t['megjegyzes'] ?? $t['leiras_szamla'] ?? ''), 0, 200),
            ];
        }

        if ($sorok !== []) {
            $sorok = self::afa_elosztas($sorok, $r);
        }

        if ($sorok === []) {
            $netto   = (float) (int) $r['netto'];
            $afa     = round($netto * $szazalek($alap_kulcs) / 100, $tized);
            $afa     = isset($r['afa']) && is_numeric($alap_kulcs) && abs($afa - (float) (int) $r['afa']) < 1 ? (float) (int) $r['afa'] : $afa;
            $sorok[] = [
                'megnevezes'     => SDH_Sales::termeknev() . ' – ' . (string) $r['azon'],
                'mennyiseg'      => 1.0,
                'me'             => 'db',
                'netto_egysegar' => $netto,
                'afakulcs'       => $alap_kulcs,
                'netto'          => $netto,
                'afa'            => $afa,
                'brutto'         => round($netto + $afa, 2),
                'megjegyzes'     => '',
            ];
        }

        return $sorok;
    }

    /**
     * A sorok áfája egész forintra, legnagyobb maradékkal, hogy Σ = a rendelés áfája (ha a feltételek teljesülnek).
     *
     * @param array<int, array<string, mixed>> $sorok
     * @param array<string, mixed>             $r
     * @return array<int, array<string, mixed>>
     */
    public static function afa_elosztas(array $sorok, array $r): array
    {
        if (!isset($r['afa'], $r['netto']) || !is_numeric($r['afa'])) {
            return $sorok;
        }

        $kulcsok = array_unique(array_map(static fn (array $s): string => (string) $s['afakulcs'], $sorok));
        $kulcs   = (string) reset($kulcsok);
        $netto   = 0;

        foreach ($sorok as $s) {
            if (abs((float) $s['netto'] - round((float) $s['netto'])) > 0.000001) {
                return $sorok;
            }

            $netto += (int) round((float) $s['netto']);
        }

        if (count($kulcsok) !== 1 || !is_numeric($kulcs) || $netto !== (int) $r['netto']) {
            return $sorok;
        }

        $k     = (int) round((float) $kulcs * 100);
        $cel   = (int) $r['afa'];
        $resz  = [];
        $alap  = 0;

        foreach ($sorok as $i => $s) {
            $x        = (int) round((float) $s['netto']) * $k;  // áfa × 10000
            $resz[$i] = [intdiv($x, 10000), $x % 10000];
            $alap    += $resz[$i][0];
        }

        $marad = $cel - $alap;

        if ($marad < 0 || $marad > count($sorok)) {
            return $sorok;
        }

        $sorr = array_keys($sorok);
        usort($sorr, static fn (int $a, int $b): int => $resz[$b][1] <=> $resz[$a][1] ?: $a <=> $b);

        foreach ($sorr as $n => $i) {
            $afa                   = $resz[$i][0] + ($n < $marad ? 1 : 0);
            $sorok[$i]['afa']      = (float) $afa;
            $sorok[$i]['brutto']   = round((float) $sorok[$i]['netto'] + $afa, 2);
        }

        return $sorok;
    }

    private static function fizmod(string $fizmod): string
    {
        $m = [
            'atutalas'  => 'Átutalás',
            'barion'    => 'Barion',
            'simplepay' => 'Bankkártya',
            'kartya'    => 'Bankkártya',
            'kezi'      => 'Átutalás',
        ];

        return (string) apply_filters('sdh_sales_szamlazz_fizmod', $m[$fizmod] ?? 'Átutalás', $fizmod);
    }

    /* =================================================================
     * XML
     * ============================================================== */

    private static function x(string $s): string
    {
        $s = (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $s);

        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function n(float $v): string
    {
        $s = rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');

        return $s === '' || $s === '-0' ? '0' : $s;
    }

    /** Elemek sorban (a séma sorrendje kötött); a null kimarad, a bool „true”/„false”. @param array<string, mixed> $m */
    private static function elemek(array $m, string $be = '    '): string
    {
        $ki = '';

        foreach ($m as $nev => $v) {
            if ($v === null) {
                continue;
            }

            $v   = is_bool($v) ? ($v ? 'true' : 'false') : (string) $v;
            $ki .= $be . '<' . $nev . '>' . self::x($v) . '</' . $nev . ">\n";
        }

        return $ki;
    }

    /** Külső azonosító (a Számlázz.hu-n visszakereshető, a duplikáció kezeléséhez). */
    private static function kulso_azon(array $r, string $tipus): string
    {
        return 'p-' . (string) $r['azon'] . '-' . ($tipus === 'dijbekero' ? 'd' : 'v');
    }

    private static function megjegyzes(string $tipus, array $r): string
    {
        $sablon = (string) SDH_Sales::b('szamlazz.megjegyzes', 'Rendelés: {rendeles}');
        $resz   = [trim(SDH_Sales::t($sablon, ['{rendeles}' => (string) $r['azon'], '{kozlemeny}' => (string) $r['kozlemeny']]))];

        if ($tipus === 'dijbekero' && (string) $r['kozlemeny'] !== '') {
            $resz[] = 'Átutaláskor a közlemény: ' . (string) $r['kozlemeny'];
        }

        if ($tipus === 'vegszamla') {
            $resz[] = 'Fizetve: ' . self::fizetes_nap($r) . ', ' . self::fizmod((string) $r['fizmod']) . '.';
        }

        if (in_array(self::mod(), ['teszt', 'minta'], true)) {
            array_unshift($resz, 'MINTA – tesztüzem, nem valódi számla.');
        }

        return implode(' ', array_filter($resz));
    }

    private static function fizetes_nap(array $r): string
    {
        $f = (string) ($r['fizetve_ido'] ?? '');
        $t = $f !== '' && $f !== '0000-00-00 00:00:00' ? strtotime($f . ' UTC') : false;

        return self::nap($t !== false ? $t : null);
    }

    /**
     * A díjbekérő / végszámla XML-je (xmlszamla, a séma elemsorrendjében).
     *
     * @param string $tipus 'dijbekero' | 'vegszamla'
     */
    public static function xml(string $tipus, array $r): string
    {
        $v       = self::vevo($r)['adat'];
        $vegsz   = $tipus === 'vegszamla';
        $ma      = self::nap();
        $hatarido = (string) ($r['hatarido'] ?? '');

        if ($vegsz) {
            $telj     = self::fizetes_nap($r);
            $hatarido = $telj;
        } else {
            $telj = $ma;

            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $hatarido) !== 1 || $hatarido === '0000-00-00') {
                $hatarido = self::nap(time() + DAY_IN_SECONDS * max(0, (int) SDH_Sales::b('fizetes.dijbekero_nap', 8)));
            }
        }

        $dbk    = (string) $r['szamla_dijbekero'];
        $elotag = trim((string) SDH_Sales::b('szamlazz.elotag', ''));
        $kulcs  = self::kulcs();

        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<xmlszamla xmlns="http://www.szamlazz.hu/xmlszamla" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"'
            . ' xsi:schemaLocation="http://www.szamlazz.hu/xmlszamla https://www.szamlazz.hu/szamla/docs/xsds/agent/xmlszamla.xsd">' . "\n";

        $xml .= "  <beallitasok>\n" . self::elemek([
            'szamlaagentkulcs' => $kulcs !== '' ? $kulcs : 'MINTA',
            'eszamla'          => self::igen(SDH_Sales::b('szamlazz.eszamla', false)),
            'szamlaLetoltes'   => true,
            'valaszVerzio'     => 2,
            'szamlaKulsoAzon'  => self::kulso_azon($r, $tipus),
        ]) . "  </beallitasok>\n";

        $xml .= "  <fejlec>\n" . self::elemek([
            'keltDatum'             => $ma,
            'teljesitesDatum'       => $telj,
            'fizetesiHataridoDatum' => $hatarido,
            'fizmod'                => self::fizmod((string) $r['fizmod']),
            'penznem'               => (string) ($r['penznem'] ?: 'HUF'),
            'szamlaNyelve'          => 'hu',
            'megjegyzes'            => self::megjegyzes($tipus, $r),
            'rendelesSzam'          => (string) $r['azon'],
            // Végszámla a díjbekérőből: a Számlázz.hu a megadott díjbekérőhöz kapcsolja (a minta-díjbekérőt nem).
            'dijbekeroSzamlaszam'   => $vegsz && $dbk !== '' && strncmp($dbk, 'MINTA-', 6) !== 0 ? $dbk : null,
            'dijbekero'             => $vegsz ? null : true,
            'szamlaszamElotag'      => $elotag !== '' ? $elotag : null,
            'fizetve'               => $vegsz,
        ]) . "  </fejlec>\n";

        $xml .= "  <elado>\n" . self::elemek([
            'bank'            => trim((string) SDH_Sales::b('szamlazz.bank', '')) ?: null,
            'bankszamlaszam'  => trim((string) SDH_Sales::b('ceg.bankszamla', '')) ?: null,
            'emailReplyto'    => is_email((string) SDH_Sales::b('ceg.email', '')) ? (string) SDH_Sales::b('ceg.email', '') : null,
        ]) . "  </elado>\n";

        $xml .= "  <vevo>\n" . self::elemek([
            'nev'         => $v['nev'],
            'orszag'      => $v['orszag'],
            'irsz'        => $v['irsz'],
            'telepules'   => $v['telepules'],
            'cim'         => $v['cim'],
            'email'       => $v['email'] !== '' ? $v['email'] : null,
            // A számlát a saját levelünk viszi (csatolmány + tokenes link); a Számlázz.hu levele beállítható.
            'sendEmail'   => self::igen(SDH_Sales::b('szamlazz.kuldes', false)) && $v['email'] !== '',
            'adoalany'    => (int) $v['adoalany'],
            'adoszam'     => $v['adoszam'] !== '' ? $v['adoszam'] : null,
            'telefonszam' => $v['telefon'] !== '' ? $v['telefon'] : null,
        ]) . "  </vevo>\n";

        $xml .= "  <tetelek>\n";

        foreach (self::sorok($r) as $s) {
            $xml .= "    <tetel>\n" . self::elemek([
                'megnevezes'       => $s['megnevezes'],
                'mennyiseg'        => self::n($s['mennyiseg']),
                'mennyisegiEgyseg' => $s['me'],
                'nettoEgysegar'    => self::n($s['netto_egysegar']),
                'afakulcs'         => $s['afakulcs'],
                'nettoErtek'       => self::n($s['netto']),
                'afaErtek'         => self::n($s['afa']),
                'bruttoErtek'      => self::n($s['brutto']),
                'megjegyzes'       => $s['megjegyzes'] !== '' ? $s['megjegyzes'] : null,
            ], '      ') . "    </tetel>\n";
        }

        return $xml . "  </tetelek>\n</xmlszamla>\n";
    }

    /* =================================================================
     * Agent-kliens
     * ============================================================== */

    /** Egy elem szövege egy XML-ből (névtértől függetlenül, az első előfordulás). */
    private static function xml_ertek(DOMDocument $d, string $nev, ?DOMElement $alatt = null): string
    {
        $l = ($alatt ?? $d)->getElementsByTagNameNS('*', $nev);

        return $l->length > 0 ? trim((string) $l->item(0)->textContent) : '';
    }

    private static function dom(string $xml): ?DOMDocument
    {
        if ($xml === '' || strpos($xml, '<') === false) {
            return null;
        }

        $d   = new DOMDocument();
        $elo = libxml_use_internal_errors(true);
        $ok  = $d->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($elo);

        return $ok ? $d : null;
    }

    /**
     * Kérés az Agentnek (multipart, az XML fájlként). Értelmezi a fejléceket (szlahu_*), a valaszVerzio=2 XML-t
     * (xmlszamlavalasz), a v1 PDF-et, a „[ERR]” és az „xmlagentresponse=DONE;” szövegtörzset.
     *
     * @return array{ok: bool, kod: string, uzenet: string, szamlaszam: string, brutto: float, pdf: string, torzs: string, http: int}
     */
    public static function agent_kuld(string $mezo, string $xml): array
    {
        $ki = ['ok' => false, 'kod' => '', 'uzenet' => '', 'szamlaszam' => '', 'brutto' => 0.0, 'pdf' => '', 'torzs' => '', 'http' => 0];

        $hatar = '----portal' . wp_generate_password(24, false);
        $torzs = '--' . $hatar . "\r\n"
            . 'Content-Disposition: form-data; name="' . $mezo . '"; filename="request.xml"' . "\r\n"
            . "Content-Type: text/xml\r\n\r\n" . $xml . "\r\n--" . $hatar . "--\r\n";

        $v = wp_remote_post(self::agent_url(), [
            'timeout'     => 40,
            'redirection' => 0,
            'headers'     => ['Content-Type' => 'multipart/form-data; boundary=' . $hatar],
            'body'        => $torzs,
        ]);

        if (is_wp_error($v)) {
            $ki['uzenet'] = 'A Számlázz.hu nem érhető el (' . $v->get_error_message() . ').';

            return $ki;
        }

        $f = static fn (string $n): string => trim((string) wp_remote_retrieve_header($v, $n));

        $ki['http']       = (int) wp_remote_retrieve_response_code($v);
        $ki['torzs']      = (string) wp_remote_retrieve_body($v);
        $ki['kod']        = $f('szlahu_error_code');
        $ki['uzenet']     = urldecode($f('szlahu_error'));
        $ki['szamlaszam'] = urldecode($f('szlahu_szamlaszam'));
        $ki['brutto']     = (float) $f('szlahu_bruttovegosszeg');
        $t                = $ki['torzs'];

        if (strncmp($t, '%PDF', 4) === 0) {
            $ki['pdf'] = $t;
        } elseif (strpos($t, 'xmlszamlavalasz') !== false && ($d = self::dom($t)) !== null) {
            if (self::xml_ertek($d, 'sikeres') !== 'true') {
                $ki['kod']    = $ki['kod'] ?: self::xml_ertek($d, 'hibakod');
                $ki['uzenet'] = $ki['uzenet'] ?: self::xml_ertek($d, 'hibauzenet');
            }

            $ki['szamlaszam'] = $ki['szamlaszam'] ?: self::xml_ertek($d, 'szamlaszam');
            $ki['brutto']     = $ki['brutto'] ?: (float) self::xml_ertek($d, 'szamlabrutto');
            $pdf              = base64_decode(preg_replace('/\s+/', '', self::xml_ertek($d, 'pdf')) ?? '', true);
            $ki['pdf']        = is_string($pdf) && strncmp($pdf, '%PDF', 4) === 0 ? $pdf : '';
        } elseif (strncmp(ltrim($t), '[ERR]', 5) === 0) {
            $resz         = preg_split('/\R-{3,}/', substr(ltrim($t), 5)) ?: [''];
            $ki['uzenet'] = $ki['uzenet'] ?: trim((string) $resz[0]);
        } elseif (preg_match('/xmlagentresponse=DONE;([^\s;]+)/', $t, $m) === 1) {
            $ki['szamlaszam'] = $ki['szamlaszam'] ?: urldecode($m[1]);
        }

        if ($ki['kod'] !== '' || $ki['uzenet'] !== '') {
            $ki['uzenet'] = $ki['uzenet'] !== '' ? $ki['uzenet'] : 'A Számlázz.hu hibát jelzett.';

            return $ki;
        }

        if ($ki['http'] !== 200) {
            $ki['uzenet'] = 'A Számlázz.hu ' . $ki['http'] . ' kóddal válaszolt.';

            return $ki;
        }

        $ki['ok'] = true;

        return $ki;
    }

    /** Hibaüzenet az adminnak (a Számlázz.hu szövege + a teendő), a kóddal. */
    public static function hiba_szoveg(array $v): string
    {
        $sajat = [
            '3'   => 'A Számlázz.hu nem fogadta el a Számla Agent kulcsot. Ellenőrizd a Beállítások → Számlázás alatt (csak kisbetűs kulcs érvényes).',
            '54'  => 'A fiókban nincs engedélyezve az e-számla. Kapcsold ki az e-számlát a beállításban, vagy engedélyezd a Számlázz.hu-n.',
            '57'  => 'A Számlázz.hu érvénytelennek találta a kérést (XML). Jelezd a fejlesztőnek a naplóbejegyzéssel együtt.',
            '136' => 'A Számla Agent most nem használható ebben a fiókban (előfizetés, tartozás). Nézd meg a Számlázz.hu fiókodat.',
            '202' => 'A számlaszám-előtag nincs felvéve a Számlázz.hu fiókban. Vedd fel ott, vagy töröld a beállításból.',
            '71'  => 'Ehhez a rendelésszámhoz már készült ilyen bizonylat a Számlázz.hu-n.',
            '152' => 'Ehhez a rendelésszámhoz már készült ilyen bizonylat a Számlázz.hu-n.',
        ];

        $u = $sajat[(string) $v['kod']] ?? (string) $v['uzenet'];

        return $u . ((string) $v['kod'] !== '' ? ' (Számlázz.hu hibakód: ' . $v['kod'] . ')' : '');
    }

    /** Egy bizonylat XML-adatai (szamlaszam vagy rendelesSzam vagy külső azonosító alapján). */
    private static function lekerdez(array $azon): ?DOMDocument
    {
        $kulcs = self::kulcs();

        if ($kulcs === '') {
            return null;
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<xmlszamlaxml xmlns="http://www.szamlazz.hu/xmlszamlaxml" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">' . "\n"
            . self::elemek([
                'szamlaagentkulcs' => $kulcs,
                'szamlaszam'       => $azon['szamlaszam'] ?? null,
                'rendelesSzam'     => $azon['rendelesSzam'] ?? null,
                'pdf'              => false,
                'szamlaKulsoAzon'  => $azon['szamlaKulsoAzon'] ?? null,
            ], '  ') . "</xmlszamlaxml>\n";

        $v = self::agent_kuld('action-szamla_agent_xml', $xml);

        return $v['ok'] ? self::dom($v['torzs']) : null;
    }

    /* =================================================================
     * Kiállítás
     * ============================================================== */

    /**
     * Egy bizonylat kiállítása (mentés nélkül).
     *
     * @return array{ok: bool, szamlaszam: string, pdf: string, uzenet: string, kod: string}
     */
    private static function kiallit(string $tipus, array $r): array
    {
        $mod = self::mod();
        $vv  = self::vevo($r);

        if ($vv['hianyzik'] !== [] && $mod !== 'minta') {
            return ['ok' => false, 'szamlaszam' => '', 'pdf' => '', 'kod' => 'vevo', 'uzenet' => 'Hiányzó vevőadat a számlához: ' . implode(', ', $vv['hianyzik']) . '.'];
        }

        if ($mod === 'minta') {
            $szam = 'MINTA-' . ($tipus === 'dijbekero' ? 'D-' : '') . (string) $r['azon'];

            return ['ok' => true, 'szamlaszam' => $szam, 'pdf' => self::minta_pdf($tipus, $r, $szam), 'kod' => '', 'uzenet' => 'Minta mód: helyi minta-PDF készült, a Számlázz.hu nem kapott kérést.'];
        }

        $v = self::agent_kuld('action-xmlagentxmlfile', self::xml($tipus, $r));

        // Már létező rendelésszám (71/152): a korábbi bizonylatot keressük meg, nem állítunk ki újat.
        if (!$v['ok'] && in_array($v['kod'], ['71', '152'], true)) {
            $d = self::lekerdez(['szamlaKulsoAzon' => self::kulso_azon($r, $tipus)]);
            $sz = $d !== null ? self::xml_ertek($d, 'szamlaszam') : '';

            if ($sz !== '' && $sz !== (string) $r['szamla_dijbekero']) {
                return ['ok' => true, 'szamlaszam' => $sz, 'pdf' => '', 'kod' => $v['kod'], 'uzenet' => 'A bizonylat már létezett a Számlázz.hu-n; azt kapcsoltuk a rendeléshez.'];
            }
        }

        if (!$v['ok'] || $v['szamlaszam'] === '') {
            return ['ok' => false, 'szamlaszam' => '', 'pdf' => '', 'kod' => $v['kod'], 'uzenet' => $v['ok'] ? 'A Számlázz.hu nem adott számlaszámot.' : self::hiba_szoveg($v)];
        }

        return ['ok' => true, 'szamlaszam' => $v['szamlaszam'], 'pdf' => $v['pdf'], 'kod' => '', 'uzenet' => ''];
    }

    /** @return array{ok: bool, szamlaszam: string, pdf: string, uzenet: string} */
    private static function bizonylat(string $tipus, int $id, bool $kezi): array
    {
        global $wpdb;

        $mezo = $tipus === 'dijbekero' ? 'szamla_dijbekero' : 'szamla_vegszamla';
        $nev  = $tipus === 'dijbekero' ? 'Díjbekérő' : 'Végszámla';
        $ki   = ['ok' => false, 'szamlaszam' => '', 'pdf' => '', 'uzenet' => ''];
        $r    = self::rendeles($id);

        if ($r === null) {
            $ki['uzenet'] = 'Nincs ilyen rendelés.';

            return $ki;
        }

        if ((string) $r[$mezo] !== '') {
            return ['ok' => true, 'szamlaszam' => (string) $r[$mezo], 'pdf' => (string) self::pdf($id, $tipus), 'uzenet' => $nev . ' már készült.'];
        }

        if ($tipus === 'vegszamla' && !self::fizetve_e($r)) {
            $ki['uzenet'] = 'A rendelés még nincs kifizetve – végszámla csak a fizetés után készül.';

            return $ki;
        }

        if (!self::bekotve()) {
            $ki['uzenet'] = 'A Számlázz.hu nincs bekötve (Beállítások → Számlázás: Agent-kulcs vagy tesztüzem).';

            return $ki;
        }

        $proba_kulcs = 'sdh_sales_szproba_' . $tipus . '_' . $id;

        if ($kezi) {
            delete_option($proba_kulcs);
        } elseif ((int) get_option($proba_kulcs, 0) >= self::MAX_PROBA) {
            $ki['uzenet'] = $nev . ': ' . self::MAX_PROBA . ' sikertelen próba után a rendszer nem küldi újra magától. Javítsd a hibát, majd indítsd kézzel.';

            return $ki;
        }

        if (!self::zar('szamla:' . $id)) {
            $ki['uzenet'] = 'Ezt a rendelést most egy másik folyamat számlázza. Próbáld újra egy perc múlva.';

            return $ki;
        }

        try {
            $r = self::rendeles($id) ?? $r;

            if ((string) $r[$mezo] !== '') {
                return ['ok' => true, 'szamlaszam' => (string) $r[$mezo], 'pdf' => (string) self::pdf($id, $tipus), 'uzenet' => $nev . ' már készült.'];
            }

            $e = self::kiallit($tipus, $r);

            if (!$e['ok']) {
                update_option($proba_kulcs, (int) get_option($proba_kulcs, 0) + 1, false);
                SDH_Sales_Naplo::ir('szamla', $nev . ' nem készült (' . (string) $r['azon'] . '): ' . $e['uzenet'], 'hiba', 'rendeles', $id);
                $ki['uzenet'] = $e['uzenet'];

                return $ki;
            }

            delete_option($proba_kulcs);
            $wpdb->update(SDH_Sales_Schema::t('rendeles'), [$mezo => mb_substr($e['szamlaszam'], 0, 60), 'modositva' => current_time('mysql', true)], ['id' => $id]);

            $ut = '';

            if ($e['pdf'] !== '') {
                $ut = self::pdf_ment($id, $tipus, $e['szamlaszam'], $e['pdf']);
            }

            if ($ut === '') {
                $ut = (string) self::pdf($id, $tipus);
            }

            SDH_Sales_Naplo::ir('szamla', $nev . ' kész: ' . $e['szamlaszam'] . ' (' . (string) $r['azon'] . ', ' . self::mod() . ')', 'siker', 'rendeles', $id, null, ['tipus' => $tipus, 'szamlaszam' => $e['szamlaszam']]);
            do_action('sdh_sales_szamla_kesz', $id, $tipus, $e['szamlaszam']);

            return ['ok' => true, 'szamlaszam' => $e['szamlaszam'], 'pdf' => $ut, 'uzenet' => $e['uzenet'] !== '' ? $e['uzenet'] : $nev . ' kész.'];
        } finally {
            self::elenged('szamla:' . $id);
        }
    }

    /** @return array{ok: bool, szamlaszam: string, pdf: string, uzenet: string} */
    public static function dijbekero(int $rendeles_id): array
    {
        return self::bizonylat('dijbekero', $rendeles_id, false);
    }

    /**
     * Végszámla a díjbekérőből (fizetve, fizetési mód és nap, rendelésszám, vevő adószámmal). Idempotens.
     * `$kezi`: admin gomb – a sikertelen próbák számlálóját nullázza.
     *
     * @return array{ok: bool, szamlaszam: string, pdf: string, uzenet: string}
     */
    public static function vegszamla(int $rendeles_id, bool $kezi = false): array
    {
        return self::bizonylat('vegszamla', $rendeles_id, $kezi);
    }

    /** Hook: új rendelés → díjbekérő (átutalásnál; kártyánál nem kell). */
    public static function rendeles_letrehozva($rendeles_id): void
    {
        $id = (int) $rendeles_id;
        $r  = $id > 0 ? self::rendeles($id) : null;

        if ($r === null || !self::bekotve()) {
            return;
        }

        if (!(bool) apply_filters('sdh_sales_szamlazz_dijbekero_kell', (string) $r['fizmod'] === 'atutalas', $r)) {
            return;
        }

        self::dijbekero($id);
    }

    /* =================================================================
     * Zár (adatbázis-egyediség: a korlat tábla elsődleges kulcsa)
     * ============================================================== */

    private static function zar(string $nev, int $mp = 60): bool
    {
        global $wpdb;

        $t     = SDH_Sales_Schema::t('korlat');
        $k     = 'zar:' . $nev;
        $proba = max(1, (int) apply_filters('sdh_sales_zar_proba', 40, $nev));

        for ($i = 0; $i < $proba; $i++) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$t} WHERE kulcs = %s AND lejar < %s", $k, gmdate('Y-m-d H:i:s')));
            $elnyom = $wpdb->suppress_errors(true);
            $ok     = $wpdb->insert($t, ['kulcs' => $k, 'db' => 1, 'lejar' => gmdate('Y-m-d H:i:s', time() + $mp)]);
            $wpdb->suppress_errors($elnyom);

            if ($ok) {
                return true;
            }

            usleep(100000);
        }

        return false;
    }

    private static function elenged(string $nev): void
    {
        global $wpdb;

        $wpdb->delete(SDH_Sales_Schema::t('korlat'), ['kulcs' => 'zar:' . $nev]);
    }

    /* =================================================================
     * PDF-tár (uploads/portal-szamla, közvetlenül nem elérhető)
     * ============================================================== */

    public static function mappa(): string
    {
        $u    = wp_upload_dir();
        $m    = trailingslashit((string) $u['basedir']) . self::MAPPA;
        $regi = trailingslashit((string) $u['basedir']) . 'sdh-sales-szamla';

        // A régi, árulkodó nevű mappa tartalma átkerül (a PDF-ek fájlneve nem függ a mappától, így megmaradnak).
        if (is_dir($regi)) {
            if (!is_dir($m)) {
                @rename($regi, $m); // phpcs:ignore WordPress.PHP.NoSilencedErrors
            } else {
                foreach ((array) glob($regi . '/*.pdf') as $f) {
                    if (!file_exists($m . '/' . basename((string) $f))) {
                        @rename((string) $f, $m . '/' . basename((string) $f)); // phpcs:ignore WordPress.PHP.NoSilencedErrors
                    }
                }

                foreach (array_diff((array) scandir($regi), ['.', '..']) as $f) {
                    if (is_file($regi . '/' . $f) && (!str_ends_with((string) $f, '.pdf') || file_exists($m . '/' . $f))) {
                        wp_delete_file($regi . '/' . $f);
                    }
                }

                @rmdir($regi); // phpcs:ignore WordPress.PHP.NoSilencedErrors
            }
        }

        if (!is_dir($m)) {
            wp_mkdir_p($m);
        }

        $vedelem = [
            'index.php' => "<?php\n// Csend.\n",
            '.htaccess' => "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
            'web.config' => "<?xml version=\"1.0\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
        ];

        foreach ($vedelem as $n => $tartalom) {
            if (!file_exists($m . '/' . $n)) {
                file_put_contents($m . '/' . $n, $tartalom);
            }
        }

        return $m;
    }

    /** Kitalálhatatlan, de determinisztikus fájlnév (nem kell hozzá oszlop). */
    private static function pdf_ut(int $id, string $tipus, string $szamlaszam): string
    {
        return self::mappa() . '/' . substr(hash_hmac('sha256', $id . '|' . $tipus . '|' . $szamlaszam, wp_salt('auth') . 'sdh-szamla'), 0, 40) . '.pdf';
    }

    private static function pdf_ment(int $id, string $tipus, string $szamlaszam, string $pdf): string
    {
        if (strncmp($pdf, '%PDF', 4) !== 0) {
            return '';
        }

        $ut = self::pdf_ut($id, $tipus, $szamlaszam);

        return file_put_contents($ut, $pdf) !== false ? $ut : '';
    }

    /** A bizonylat PDF-jének helyi útja; ha nincs meg, újra lekéri (Agent) vagy újrarajzolja (minta). */
    private static function pdf(int $id, string $tipus): ?string
    {
        $r = self::rendeles($id);

        if ($r === null) {
            return null;
        }

        $szam = (string) ($tipus === 'dijbekero' ? $r['szamla_dijbekero'] : $r['szamla_vegszamla']);

        if ($szam === '') {
            return null;
        }

        $ut = self::pdf_ut($id, $tipus, $szam);

        if (is_readable($ut)) {
            return $ut;
        }

        if (strncmp($szam, 'MINTA-', 6) === 0) {
            return self::pdf_ment($id, $tipus, $szam, self::minta_pdf($tipus, $r, $szam)) ?: null;
        }

        $kulcs = self::kulcs();

        if ($kulcs === '') {
            return null;
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<xmlszamlapdf xmlns="http://www.szamlazz.hu/xmlszamlapdf" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">' . "\n"
            . self::elemek(['szamlaagentkulcs' => $kulcs, 'szamlaszam' => $szam, 'valaszVerzio' => 1], '  ')
            . "</xmlszamlapdf>\n";

        $v = self::agent_kuld('action-szamla_agent_pdf', $xml);

        return $v['pdf'] !== '' ? (self::pdf_ment($id, $tipus, $szam, $v['pdf']) ?: null) : null;
    }

    public static function dijbekero_pdf(int $id): ?string
    {
        return self::pdf($id, 'dijbekero');
    }

    public static function vegszamla_pdf(int $id): ?string
    {
        return self::pdf($id, 'vegszamla');
    }

    private static function pdf_token(int $id, string $tipus, string $szamlaszam): string
    {
        return substr(hash_hmac('sha256', 'pdf|' . $id . '|' . $tipus . '|' . $szamlaszam, wp_salt('auth') . 'sdh-szamla-link'), 0, 32);
    }

    /** Tokenes letöltő link a vevőnek (levélbe, fiókba). Üres, ha még nincs bizonylat. */
    public static function pdf_link(int $rendeles_id, string $tipus = 'vegszamla'): string
    {
        $r = self::rendeles($rendeles_id);

        if ($r === null) {
            return '';
        }

        $tipus = $tipus === 'dijbekero' ? 'dijbekero' : 'vegszamla';
        $szam  = (string) ($tipus === 'dijbekero' ? $r['szamla_dijbekero'] : $r['szamla_vegszamla']);

        if ($szam === '') {
            return '';
        }

        return add_query_arg(['tipus' => $tipus, 't' => self::pdf_token($rendeles_id, $tipus, $szam)], rest_url(SDH_Sales_Rest::NS . '/szamla/' . rawurlencode((string) $r['azon']) . '/pdf'));
    }

    /* =================================================================
     * Minta-PDF (könyvtár nélkül)
     * ============================================================== */

    private static function pdf_szoveg(string $s): string
    {
        $s = strtr($s, ['ő' => 'ö', 'Ő' => 'Ö', 'ű' => 'ü', 'Ű' => 'Ü', '–' => '-', '—' => '-', '„' => '"', '”' => '"', "\u{00A0}" => ' ']);
        $c = function_exists('iconv') ? @iconv('UTF-8', 'CP1252//TRANSLIT', $s) : false;
        $c = is_string($c) ? $c : (string) preg_replace('/[^\x20-\x7E]/', '?', $s);

        return strtr($c, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '', "\n" => ' ']);
    }

    /**
     * Egyoldalas PDF szövegsorokból (Helvetica, WinAnsi). `$sorok`: [szöveg, betűméret, félkövér?].
     *
     * @param array<int, array{0: string, 1?: int, 2?: bool}> $sorok
     */
    public static function pdf_ir(array $sorok, string $vizjel = ''): string
    {
        $c = '';

        if ($vizjel !== '') {
            $c .= "q 0.88 g BT /F2 110 Tf 0.7071 0.7071 -0.7071 0.7071 140 230 Tm (" . self::pdf_szoveg($vizjel) . ") Tj ET Q\n";
        }

        $y = 800;
        $c .= "0 g\n";

        foreach (array_slice($sorok, 0, 62) as $s) {
            $meret = (int) ($s[1] ?? 10);
            $y    -= (int) round($meret * 1.45);

            if ($y < 40) {
                break;
            }

            $c .= 'BT /' . (!empty($s[2]) ? 'F2' : 'F1') . ' ' . $meret . ' Tf 50 ' . $y . ' Td (' . self::pdf_szoveg((string) $s[0]) . ") Tj ET\n";
        }

        $obj = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents 6 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
            '<< /Length ' . strlen($c) . " >>\nstream\n" . $c . "endstream",
        ];

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $hol = [];

        foreach ($obj as $i => $o) {
            $hol[] = strlen($pdf);
            $pdf  .= ($i + 1) . " 0 obj\n" . $o . "\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($obj) + 1) . "\n0000000000 65535 f \n";

        foreach ($hol as $h) {
            $pdf .= str_pad((string) $h, 10, '0', STR_PAD_LEFT) . " 00000 n \n";
        }

        return $pdf . "trailer\n<< /Size " . (count($obj) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
    }

    private static function minta_pdf(string $tipus, array $r, string $szam): string
    {
        $v   = self::vevo($r)['adat'];
        $ft  = static fn (float $x): string => number_format($x, 2, ',', ' ') . ' ' . (string) ($r['penznem'] ?: 'HUF');
        $sor = [
            ['MINTA - nem valódi számla, csak bemutató / teszt', 9, true],
            [$tipus === 'dijbekero' ? 'DÍJBEKÉRŐ' : 'SZÁMLA', 22, true],
            ['Sorszám: ' . $szam, 11, true],
            ['Rendelés: ' . (string) $r['azon'] . '   Kelt: ' . self::nap() . ($tipus === 'vegszamla' ? '   Teljesítés: ' . self::fizetes_nap($r) : ''), 10],
            ['Fizetési mód: ' . self::fizmod((string) $r['fizmod']) . ($tipus === 'vegszamla' ? '   FIZETVE' : '   Közlemény: ' . (string) $r['kozlemeny']), 10],
            ['', 6],
            ['Eladó: ' . (string) SDH_Sales::b('ceg.nev', ''), 10, true],
            [trim((string) SDH_Sales::b('ceg.cim', '') . '  Adószám: ' . (string) SDH_Sales::b('ceg.adoszam', '')), 9],
            ['Bankszámla: ' . (string) SDH_Sales::b('ceg.bankszamla', ''), 9],
            ['', 6],
            ['Vevő: ' . ($v['nev'] !== '' ? $v['nev'] : '-'), 10, true],
            [trim($v['irsz'] . ' ' . $v['telepules'] . ', ' . $v['cim'], ' ,') . ($v['adoszam'] !== '' ? '  Adószám: ' . $v['adoszam'] : ''), 9],
            ['', 6],
            ['Tételek', 11, true],
        ];

        $n = $a = $b = 0.0;

        foreach (self::sorok($r) as $s) {
            $sor[] = [$s['megnevezes'], 10];
            $sor[] = ['    ' . self::n($s['mennyiseg']) . ' ' . $s['me'] . ' x ' . $ft($s['netto_egysegar']) . '   nettó ' . $ft($s['netto']) . '   ÁFA ' . $s['afakulcs'] . '%: ' . $ft($s['afa']) . '   bruttó ' . $ft($s['brutto']), 9];
            $n += $s['netto'];
            $a += $s['afa'];
            $b += $s['brutto'];
        }

        $sor[] = ['', 6];
        $sor[] = ['Nettó összesen: ' . $ft($n) . '    ÁFA: ' . $ft($a), 10];
        $sor[] = ['Fizetendő bruttó: ' . $ft($b), 13, true];
        $sor[] = ['', 6];
        $sor[] = [self::megjegyzes($tipus, $r), 8];

        return self::pdf_ir($sor, 'MINTA');
    }

    /* =================================================================
     * Fizetettség (Számlázz.hu: xmlszamlaxml → kifizetesek, totalossz)
     * ============================================================== */

    /**
     * A bizonylat kifizetései a Számlázz.hu szerint. Null, ha nem kérdezhető le (nincs kulcs, hiba, ismeretlen szám).
     *
     * @return array{szamlaszam: string, tipus: string, hatarido: string, brutto: float, fizetve: float, hatralek: float, rendezett: bool, kifizetesek: array<int, array{datum: string, jogcim: string, osszeg: float}>, forras: string}|null
     */
    public static function fizetettseg(string $szamlaszam): ?array
    {
        $szamlaszam = trim($szamlaszam);

        if ($szamlaszam === '') {
            return null;
        }

        if (strncmp($szamlaszam, 'MINTA-', 6) === 0) {
            global $wpdb;

            $t  = SDH_Sales_Schema::t('rendeles');
            $id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$t} WHERE szamla_vegszamla = %s OR szamla_dijbekero = %s LIMIT 1", $szamlaszam, $szamlaszam));
            $r  = $id > 0 ? self::rendeles($id) : null;

            if ($r === null) {
                return null;
            }

            $b = (float) (int) $r['brutto'];
            $f = min($b, (float) (int) ($r['fizetett'] ?? 0));

            return ['szamlaszam' => $szamlaszam, 'tipus' => 'minta', 'hatarido' => (string) $r['hatarido'], 'brutto' => $b, 'fizetve' => $f, 'hatralek' => max(0.0, $b - $f), 'rendezett' => $b > 0 && $f >= $b, 'kifizetesek' => [], 'forras' => 'helyi'];
        }

        $d = self::lekerdez(['szamlaszam' => $szamlaszam]);

        if ($d === null) {
            return null;
        }

        $tot    = $d->getElementsByTagNameNS('*', 'totalossz');
        $brutto = $tot->length > 0 ? (float) self::xml_ertek($d, 'brutto', $tot->item(0)) : 0.0;
        $kif    = [];
        $ossz   = 0.0;

        foreach ($d->getElementsByTagNameNS('*', 'kifizetes') as $k) {
            $o      = (float) self::xml_ertek($d, 'osszeg', $k);
            $ossz  += $o;
            $kif[]  = ['datum' => self::xml_ertek($d, 'datum', $k), 'jogcim' => self::xml_ertek($d, 'jogcim', $k), 'osszeg' => $o];
        }

        return [
            'szamlaszam'  => $szamlaszam,
            'tipus'       => self::xml_ertek($d, 'tipus'),
            'hatarido'    => self::xml_ertek($d, 'fizh'),
            'brutto'      => $brutto,
            'fizetve'     => round($ossz, 2),
            'hatralek'    => max(0.0, round($brutto - $ossz, 2)),
            'rendezett'   => $brutto > 0 && $ossz + 0.5 >= $brutto,
            'kifizetesek' => $kif,
            'forras'      => 'szamlazz',
        ];
    }

    /* =================================================================
     * REST
     * ============================================================== */

    /** Nyers (nem JSON) válasz kiszolgálása: PDF, ICS. A választ az `X-Nyers` fejléc jelöli. */
    public static function nyers_kiszolgal($kiszolgalva, $valasz, $keres, $szerver)
    {
        if ($kiszolgalva || !($valasz instanceof WP_HTTP_Response)) {
            return $kiszolgalva;
        }

        $h = $valasz->get_headers();

        if (empty($h['X-Nyers']) || !is_string($valasz->get_data())) {
            return $kiszolgalva;
        }

        echo $valasz->get_data(); // phpcs:ignore WordPress.Security.EscapeOutput -- PDF / ICS.

        return true;
    }

    private static function pdf_valasz(string $ut, string $nev): WP_REST_Response
    {
        $pdf = (string) file_get_contents($ut);
        $v   = new WP_REST_Response($pdf, 200);
        $v->header('X-Nyers', '1');
        $v->header('Content-Type', 'application/pdf');
        $v->header('Content-Disposition', 'inline; filename="' . sanitize_file_name($nev) . '.pdf"');
        $v->header('Content-Length', (string) strlen($pdf));
        $v->header('X-Content-Type-Options', 'nosniff');

        return $v;
    }

    public static function rest_admin_pdf(WP_REST_Request $q)
    {
        $id = (int) $q['rendeles_id'];
        $r  = self::rendeles($id);

        if ($r === null) {
            return SDH_Sales_Rest::hiba('Nincs ilyen rendelés.', 404, 'nincs');
        }

        $tipus = (string) $q->get_param('tipus');
        $tipus = in_array($tipus, ['dijbekero', 'vegszamla'], true) ? $tipus : ((string) $r['szamla_vegszamla'] !== '' ? 'vegszamla' : 'dijbekero');
        $ut    = self::pdf($id, $tipus);

        if ($ut === null) {
            return SDH_Sales_Rest::hiba('Ehhez a rendeléshez nincs letölthető ' . ($tipus === 'dijbekero' ? 'díjbekérő' : 'számla') . '.', 404, 'nincs');
        }

        return self::pdf_valasz($ut, (string) ($tipus === 'dijbekero' ? $r['szamla_dijbekero'] : $r['szamla_vegszamla']));
    }

    public static function rest_vevo_pdf(WP_REST_Request $q)
    {
        global $wpdb;

        $azon  = (string) $q['azon'];
        $tipus = (string) $q->get_param('tipus') === 'dijbekero' ? 'dijbekero' : 'vegszamla';
        $t     = (string) $q->get_param('t');
        $id    = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . SDH_Sales_Schema::t('rendeles') . ' WHERE azon = %s', $azon));
        $r     = $id > 0 ? self::rendeles($id) : null;
        $szam  = $r !== null ? (string) ($tipus === 'dijbekero' ? $r['szamla_dijbekero'] : $r['szamla_vegszamla']) : '';

        if ($r === null || $szam === '' || strlen($t) !== 32 || !hash_equals(self::pdf_token($id, $tipus, $szam), $t)) {
            return SDH_Sales_Rest::hiba('A letöltő link érvénytelen vagy lejárt. Kérj újat az ügyfélfiókodból.', 403, 'link');
        }

        $ut = self::pdf($id, $tipus);

        return $ut !== null ? self::pdf_valasz($ut, $szam) : SDH_Sales_Rest::hiba('A számla PDF-je most nem érhető el. Próbáld újra később.', 404, 'nincs');
    }

    public static function rest_ujra(WP_REST_Request $q)
    {
        $id    = (int) $q['rendeles_id'];
        $tipus = (string) $q->get_param('tipus') === 'dijbekero' ? 'dijbekero' : 'vegszamla';
        $e     = self::bizonylat($tipus, $id, true);

        if (!$e['ok']) {
            return SDH_Sales_Rest::hiba($e['uzenet'], 422, 'szamla');
        }

        if ($tipus === 'vegszamla') {
            // A kiszolgálási lánc ebből tudja, hogy a kézi gomb lefutott (automatikus = ki esetén erre vár).
            do_action('sdh_sales_vegszamla_kezi', $id, $e['szamlaszam']);
        }

        return new WP_REST_Response(['ok' => true, 'szamlaszam' => $e['szamlaszam'], 'uzenet' => $e['uzenet'], 'pdf' => $e['pdf'] !== '']);
    }

    public static function rest_proba(WP_REST_Request $q)
    {
        $mod = self::mod();

        if ($mod === 'ki') {
            return SDH_Sales_Rest::hiba('Előbb add meg és mentsd el az Agent-kulcsot, vagy kapcsold be a tesztüzemet.', 422, 'szamla');
        }

        if ($mod === 'minta') {
            return new WP_REST_Response(['ok' => true, 'mod' => $mod, 'uzenet' => 'Tesztüzem kulcs nélkül: a számlák helyi „MINTA” PDF-ek, a Számlázz.hu nem kap kérést.']);
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<xmlszamlaxml xmlns="http://www.szamlazz.hu/xmlszamlaxml" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">' . "\n"
            . self::elemek(['szamlaagentkulcs' => self::kulcs(), 'szamlaszam' => 'KAPCSOLAT-ELLENORZES'], '  ')
            . "</xmlszamlaxml>\n";

        $v = self::agent_kuld('action-szamla_agent_xml', $xml);

        if (($v['kod'] === '' && !$v['ok']) || in_array($v['kod'], self::KAPCSOLAT_HIBAK, true)) {
            return SDH_Sales_Rest::hiba(self::hiba_szoveg($v), 422, 'szamla');
        }

        return new WP_REST_Response(['ok' => true, 'mod' => $mod, 'ipn_url' => self::ipn_url(), 'uzenet' => 'A Számlázz.hu elfogadta a kulcsot – a kapcsolat él (' . ($mod === 'teszt' ? 'tesztkulcs' : 'éles kulcs') . '). Számla nem készült.']);
    }

    /** Az IPN-cím titkos tokennel – ezt kell beírni a Számlázz.hu fiókban (Fiók beállítások → Számlázás alapadatok). */
    public static function ipn_url(bool $uj = false): string
    {
        $t = SDH_Sales::titkos('szamlazz_ipn_token');

        if ($t === '' || $uj) {
            $t = SDH_Sales::token(24);
            SDH_Sales::titkos_ment('szamlazz_ipn_token', $t);
        }

        return add_query_arg(['k' => $t], rest_url(SDH_Sales_Rest::NS . '/szamlazz/ipn'));
    }

    /**
     * Számlázz.hu IPN (fizetési értesítés díjbekérőre / számlára). A Számlázz.hu nem ír alá, ezért: titkos token az
     * IPN-címben, küldő IP a hivatalos listán (`szamlazz.ipn_ip_szures`, alapból be), díjbekérő-szám és rendelésszám
     * egyezik, és ha lekérdezhető, a Számlázz.hu adata is igazolja az összeget.
     * Csak `fizetes.automatizalas` = "szamlazz" esetén dolgozik; a fizetést az egyetlen belépési pont rögzíti.
     */
    public static function rest_ipn(WP_REST_Request $q)
    {
        global $wpdb;

        if ((string) SDH_Sales::b('fizetes.automatizalas', 'import') !== 'szamlazz') {
            return new WP_REST_Response(['ok' => false], 200);
        }

        $titok = SDH_Sales::titkos('szamlazz_ipn_token');

        if ($titok === '' || !hash_equals($titok, (string) $q->get_param('k'))) {
            SDH_Sales_Naplo::ir('szamla', 'Számlázz.hu IPN elutasítva: hibás token.', 'figyel');

            return SDH_Sales_Rest::hiba('Tiltott.', 403, 'ipn');
        }

        $ipk = (array) apply_filters('sdh_sales_szamlazz_ipn_ip', self::IPN_IP);

        if (self::igen(SDH_Sales::b('szamlazz.ipn_ip_szures', true)) && !in_array(SDH_Sales::ip(), $ipk, true)) {
            SDH_Sales_Naplo::ir('szamla', 'Számlázz.hu IPN elutasítva: ismeretlen küldő.', 'figyel');

            return SDH_Sales_Rest::hiba('Tiltott.', 403, 'ipn');
        }

        $p     = static fn (string $k): string => sanitize_text_field((string) $q->get_param($k));
        $dbk   = $p('szlahu_dijbekero_szama') ?: $p('szlahu_szamlaszam');
        $rszam = $p('szlahu_rendelesszam');
        $fiz   = (int) round((float) $p('szlahu_kifizetettbrutto'));
        $t     = SDH_Sales_Schema::t('rendeles');
        $id    = $dbk !== '' ? (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$t} WHERE szamla_dijbekero = %s", $dbk)) : 0;
        $r     = $id > 0 ? self::rendeles($id) : null;

        if ($r === null || ($rszam !== '' && $rszam !== (string) $r['azon']) || $fiz <= 0) {
            SDH_Sales_Naplo::ir('szamla', 'Számlázz.hu IPN: nem párosítható (' . $dbk . ').', 'figyel');

            return new WP_REST_Response(['ok' => false], 200);
        }

        $ell = self::fizetettseg($dbk);

        if ($ell !== null && $ell['forras'] === 'szamlazz' && $ell['fizetve'] + 0.5 < $fiz) {
            SDH_Sales_Naplo::ir('szamla', 'Számlázz.hu IPN: az összeg nem egyezik a Számlázz.hu adatával (' . $dbk . ').', 'hiba', 'rendeles', $id);

            return new WP_REST_Response(['ok' => false], 200);
        }

        if (!class_exists('SDH_Sales_Rendeles') || !method_exists('SDH_Sales_Rendeles', 'fizetve')) {
            return SDH_Sales_Rest::hiba('Átmenetileg nem elérhető.', 503, 'nem_elerheto');
        }

        // Az IPN a bizonylaton eddig kifizetett TELJES összeget hozza; a rendelésen a különbözet az új befizetés.
        $uj = $fiz - (int) ($r['fizetett'] ?? 0);

        if ($uj <= 0) {
            return new WP_REST_Response(['ok' => true], 200);
        }

        // A titkos token (`k`) nem kerül a nyers adatba.
        $ok = SDH_Sales_Rendeles::fizetve($id, 'szamlazz', 'ipn:' . $dbk . ':' . $fiz, $uj, ['ipn' => array_diff_key($q->get_params(), ['k' => 1]), 'ellenorizve' => $ell !== null]);

        return new WP_REST_Response(['ok' => (bool) $ok], 200);
    }
}
