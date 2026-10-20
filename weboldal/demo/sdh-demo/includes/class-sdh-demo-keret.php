<?php
/**
 * Látogatói keret: címenként (IP-kivonat) 24 óra alatt legfeljebb N új rekord (alap: 10) a műhely fő tábláiba, és
 * legfeljebb M írás bármelyik nem-napló táblába (árasztás ellen). A `query` szűrő a {prefix}sdh_… táblákba menő
 * INSERT / REPLACE utasításokat nézi, amíg bemutató-felhasználó van belépve (vagy kijelentkezett webes kérés fut –
 * pl. az ügyféloldal, az online foglalás). Túllépésnél a kérés a műhely AJAX-hibaútján, érthető magyar üzenettel áll le.
 *
 * Minden számolt új rekordot megjegyez (tábla + azonosító): ezeket a takarítás 24 óra után törli – a pillanatkép
 * azonosítóinál nagyobbakat, így a generált alapadathoz soha nem nyúl.
 *
 * @package SDH_Demo
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Demo_Keret
{
    /** Az „új rekord” táblák (műhely-táblanév az `sdh_` előtag után). */
    public const FO_TABLAK = [
        'ugyfel', 'eszkoz', 'munkalap', 'termek', 'szolgaltatas', 'penztar', 'naptar_esemeny', 'naptar_szemely',
        'beszallito', 'helyszin', 'szamla', 'csapat_uzenet', 'csapat_cetli', 'csapat_csoport',
    ];

    /** Napló- és mellékes táblák: nem számítanak (a műhely magától ír beléjük). */
    public const KIZART = [
        'valtozasnaplo', 'munkalap_naplo', 'penztar_naplo', 'penztar_nap', 'csapat_tag', 'csapat_nyugta', 'mezo_emlek',
        'sms_esemeny', 'sms_link_esemeny', 'sms_dontes', 'sms_onteszt', 'naptar_jelzes', 'ugyfel_szo', 'sms_sablon_verzio',
        'ai_uzenet', 'ai_muvelet', 'tac',
    ];

    /** A törléskor a rekorddal együtt törlendő gyereksorok: tábla => [[gyerek, oszlop], …]. */
    private const GYEREKEK = [
        'munkalap' => [['munkalap_tetel', 'munkalap_id'], ['munkalap_hiba', 'munkalap_id'], ['munkalap_eszkoz', 'munkalap_id'],
            ['munkalap_naplo', 'munkalap_id'], ['uzenet', 'munkalap_id'], ['sms_uzenet', 'munkalap_id'], ['termek_mozgas', 'munkalap_id']],
        'ugyfel'   => [['ugyfel_szo', 'ugyfel_id']],
        'termek'   => [['termek_mozgas', 'termek_id']],
    ];

    private static bool $benn = false;
    private static ?bool $figyelt = null;
    private static int $figyelt_uid = -1;

    /** @var array{0: int, 1: string}|null a számolt INSERT napló-sora és táblája (az azonosító a következő lekérdezésnél jön) */
    private static ?array $fuggo = null;

    /** @var array<string, int> kérésen belüli számlálók */
    private static array $db = [];

    /** Teszthez: kijelentkezett CLI-kérés is számítson. */
    public static bool $cli_anonim = false;

    public static function init(): void
    {
        add_action('shutdown', [self::class, 'felold'], 0);
    }

    /** Teszthez: a kérésen belüli állapot eldobása. */
    public static function felejt(): void
    {
        self::$figyelt     = null;
        self::$figyelt_uid = -1;
        self::$fuggo       = null;
        self::$db          = [];
    }

    /**
     * @param mixed $q
     * @return mixed
     */
    public static function szuro($q)
    {
        if (self::$benn || !is_string($q)) {
            return $q;
        }

        if (self::$fuggo !== null) {
            self::felold();
        }

        $eleje = strtoupper(substr(ltrim($q), 0, 7));

        if ($eleje !== 'INSERT ' && $eleje !== 'REPLACE') {
            return $q;
        }

        global $wpdb;

        if (!preg_match('/^\s*(?:INSERT|REPLACE)\s+(?:LOW_PRIORITY\s+|DELAYED\s+|HIGH_PRIORITY\s+)?(?:IGNORE\s+)?(?:INTO\s+)?[`"]?([A-Za-z0-9_]+)[`"]?/i', $q, $t)) {
            return $q;
        }

        $elotag = $wpdb->prefix . 'sdh_';

        if (strpos($t[1], $elotag) !== 0) {
            return $q;
        }

        $tabla = substr($t[1], strlen($elotag));

        if ($tabla === '' || strpos($tabla, 'sales_') === 0 || strpos($tabla, 'kozpont_') === 0 || in_array($tabla, self::KIZART, true)) {
            return $q;
        }

        self::$benn = true;

        try {
            if (!self::figyelt()) {
                return $q;
            }

            $ip   = SDH_Demo::ip_hash();
            $fo   = in_array($tabla, self::FO_TABLAK, true);
            $rek  = self::szam($ip, 'rekord');
            $osz  = $rek + self::szam($ip, 'iras');

            if ($fo && $rek >= (int) SDH_Demo::b('keret')) {
                self::$benn = false;
                SDH_Demo::megallit(429, self::uzenet());
            }

            if ($osz >= (int) SDH_Demo::b('iras_max')) {
                self::$benn = false;
                SDH_Demo::megallit(429, 'A bemutatóban egy címről egy nap alatt ennyi módosítás fér el. Holnap újra kipróbálhatod – vagy kérj saját próbapéldányt.');
            }

            $tipus = $fo ? 'rekord' : 'iras';
            $id    = SDH_Demo::naplo($tipus, $ip, $tabla, get_current_user_id());
            self::$db[$ip . '|' . $tipus] = self::szam($ip, $tipus) + 1;

            if ($fo) {
                self::$fuggo = [$id, $tabla];
            }
        } finally {
            self::$benn = false;
        }

        return $q;
    }

    /** A rekordkeret üzenete (a felületen ez látszik). */
    public static function uzenet(): string
    {
        return 'Elérted a bemutató napi keretét: egy címről 24 óra alatt legfeljebb ' . (int) SDH_Demo::b('keret')
            . ' új rekord vehető fel. A meglévő adatokat továbbra is megnyithatod és szerkesztheted; a keret legkésőbb 24 óra múlva újraindul.';
    }

    /** Kell-e figyelni ezt a kérést: bemutató-felhasználó, vagy kijelentkezett webes látogató (nem ütemező, nem parancssor). */
    public static function figyelt(): bool
    {
        if (!did_action('init') || !function_exists('get_current_user_id')) {
            return false;
        }

        $uid = get_current_user_id();

        if (self::$figyelt !== null && self::$figyelt_uid === $uid) {
            return self::$figyelt;
        }

        if ($uid > 0) {
            $f = SDH_Demo::demo_felhasznalo($uid);
        } else {
            $f = !wp_doing_cron() && (PHP_SAPI !== 'cli' || self::$cli_anonim);
        }

        self::$figyelt_uid = $uid;

        return self::$figyelt = $f;
    }

    private static function szam(string $ip, string $tipus): int
    {
        $k = $ip . '|' . $tipus;

        if (!isset(self::$db[$k])) {
            self::$db[$k] = SDH_Demo::naplo_db($ip, [$tipus]);
        }

        return self::$db[$k];
    }

    /** Az előző (számolt) INSERT azonosítójának feljegyzése – a következő lekérdezésnél, vagy a kérés végén. */
    public static function felold(): void
    {
        global $wpdb;

        if (self::$fuggo === null) {
            return;
        }

        [$naplo_id, $tabla] = self::$fuggo;
        self::$fuggo = null;

        $id = (int) $wpdb->insert_id;

        // Sikertelen INSERT után a régi azonosító maradhat: ilyenkor nincs mit megjegyezni.
        if ($id === $naplo_id || (string) $wpdb->last_error !== '') {
            $id = 0;
        }

        self::$benn = true;

        try {
            $wpdb->update(SDH_Demo::tabla('naplo'), ['rekord_id' => $id], ['id' => $naplo_id]);
        } finally {
            self::$benn = false;
        }
    }

    /** Hátralévő új rekord a mostani címnek. */
    public static function maradek(?string $ip_hash = null): int
    {
        $ip = $ip_hash ?? SDH_Demo::ip_hash();

        return max(0, (int) SDH_Demo::b('keret') - SDH_Demo::naplo_db($ip, ['rekord']));
    }

    /**
     * 24 óránál régebbi látogatói rekordok törlése (gyereksoraikkal), és a 48 óránál régebbi naplósorok.
     * Csak a pillanatkép legnagyobb azonosítójánál nagyobb azonosítójú sorok (a generált alapadat érintetlen).
     *
     * @return int törölt rekordok
     */
    public static function takarit(?int $most = null): int
    {
        global $wpdb;

        $most  = $most ?? time();
        $n     = SDH_Demo::tabla('naplo');
        $maxok = (array) (SDH_Demo::allapot()['maxok'] ?? []);
        $sorok = $wpdb->get_results($wpdb->prepare(
            "SELECT id, tabla, rekord_id FROM {$n} WHERE tipus = 'rekord' AND torolve = 0 AND rekord_id > 0 AND ido <= %d LIMIT 5000",
            $most - DAY_IN_SECONDS
        ));
        $db    = 0;

        self::$benn = true;

        try {
            foreach ((array) $sorok as $s) {
                $tabla = (string) $s->tabla;
                $id    = (int) $s->rekord_id;

                if (in_array($tabla, self::FO_TABLAK, true) && $id > (int) ($maxok[$tabla] ?? 0)) {
                    foreach (self::GYEREKEK[$tabla] ?? [] as [$gyerek, $oszlop]) {
                        $wpdb->query($wpdb->prepare('DELETE FROM ' . SDH_Muhely_Schema::tabla($gyerek) . " WHERE {$oszlop} = %d", $id)); // phpcs:ignore
                    }

                    $db += (int) $wpdb->query($wpdb->prepare('DELETE FROM ' . SDH_Muhely_Schema::tabla($tabla) . ' WHERE id = %d', $id)); // phpcs:ignore
                }

                $wpdb->update($n, ['torolve' => 1], ['id' => (int) $s->id]);
            }

            $wpdb->query($wpdb->prepare("DELETE FROM {$n} WHERE ido <= %d", $most - 2 * DAY_IN_SECONDS));
        } finally {
            self::$benn = false;
        }

        if ($db > 0 && class_exists('SDH_Muhely_Gyorsitotar')) {
            SDH_Muhely_Gyorsitotar::ervenytelenit();
        }

        return $db;
    }

    /** A keret-számlálás és a figyelés kikapcsolása egy művelet idejére (generálás, visszaállítás). */
    public static function csendben(callable $f)
    {
        $elozo      = self::$benn;
        self::$benn = true;

        try {
            return $f();
        } finally {
            self::$benn = $elozo;
        }
    }
}
