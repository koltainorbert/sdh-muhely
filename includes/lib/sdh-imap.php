<?php
/**
 * Vékony IMAP4rev1 kliens tiszta PHP-ben (a php-imap bővítmény nélkül).
 *
 * Azért saját: a php-imap bővítmény a PHP 8.4-ből kikerült, és a helyi
 * WordPress-telepítéseken (Local) sem mindig van meg. Ez a kliens csak azt
 * tudja, ami a levelezőhöz kell: belépés, mappák, lista, levél, jelzők,
 * áthelyezés, törlés, keresés, hozzáfűzés (piszkozat, elküldött).
 *
 * A válaszokat valódi elemzővel olvassa (atomok, idézett szövegek, literálok,
 * egymásba ágyazott zárójelek) – nem reguláris kifejezésekkel találgat.
 *
 * Gmail: a mappák neve a fiók nyelvén jön („[Gmail]/Elküldött levelek"),
 * ezért a szerepet (Elküldött, Kuka…) a SPECIAL-USE jelzőkből olvassuk, a
 * névből csak akkor, ha a szerver nem ad jelzőt.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

class SDH_Muhely_Imap_Hiba extends RuntimeException
{
}

final class SDH_Muhely_Imap
{
    /** @var resource|null */
    private $kapcsolat = null;

    private int $sorszam = 0;

    /** @var array<int, string> A legutóbbi válaszsorban állt literálok. */
    private array $literalok = [];

    /** @var array<int, string> A szerver képességei, nagybetűvel. */
    public array $kepessegek = [];

    private string $host;
    private int $port;
    private string $titkositas;
    private int $idokorlat;

    /**
     * @param string $titkositas 'ssl' (993), 'tls' (STARTTLS, 143) vagy 'nincs' (csak teszthez).
     */
    public function __construct(string $host, int $port = 993, string $titkositas = 'ssl', int $idokorlat = 20)
    {
        $this->host       = $host;
        $this->port       = $port;
        $this->titkositas = in_array($titkositas, ['ssl', 'tls', 'nincs'], true) ? $titkositas : 'ssl';
        $this->idokorlat  = max(5, $idokorlat);
    }

    public function __destruct()
    {
        $this->bont();
    }

    /* =================================================================
     * Kapcsolat
     * ============================================================== */

    public function kapcsolodik(): void
    {
        $cel      = ($this->titkositas === 'ssl' ? 'ssl://' : 'tcp://') . $this->host . ':' . $this->port;
        $kornyezet = stream_context_create([
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => $this->host],
        ]);

        $hibakod = 0;
        $hiba    = '';
        $fp      = @stream_socket_client($cel, $hibakod, $hiba, $this->idokorlat, STREAM_CLIENT_CONNECT, $kornyezet);

        if (!is_resource($fp)) {
            throw new SDH_Muhely_Imap_Hiba('A levelezőszerver nem érhető el (' . $this->host . ':' . $this->port . ')' . ($hiba !== '' ? ': ' . $hiba : '') . '.');
        }

        stream_set_timeout($fp, $this->idokorlat);
        $this->kapcsolat = $fp;

        $udvozles = $this->sor_olvas();

        if (strncmp($udvozles, '* OK', 4) !== 0 && strncmp($udvozles, '* PREAUTH', 9) !== 0) {
            $this->bont();

            throw new SDH_Muhely_Imap_Hiba('A levelezőszerver elutasította a kapcsolatot.');
        }

        if ($this->titkositas === 'tls') {
            $this->parancs('STARTTLS');

            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                $this->bont();

                throw new SDH_Muhely_Imap_Hiba('A titkosított kapcsolat (STARTTLS) nem jött létre.');
            }
        }

        // A képességeket belépés után kérdezzük le (akkor teljes a lista) – egy körrel kevesebb.
    }

    public function belep(string $felhasznalo, string $jelszo): void
    {
        try {
            $this->parancs('LOGIN ' . self::idez($felhasznalo) . ' ' . self::idez($jelszo));
        } catch (SDH_Muhely_Imap_Hiba $hiba) {
            throw new SDH_Muhely_Imap_Hiba('A belépés nem sikerült: a szerver elutasította a felhasználónevet vagy a jelszót. (' . $hiba->getMessage() . ')');
        }

        // Belépés után a szerver több képességet hirdet (pl. MOVE, X-GM-EXT-1).
        $this->kepessegek_olvas();
    }

    public function kilep(): void
    {
        if ($this->kapcsolat !== null) {
            try {
                $this->parancs('LOGOUT');
            } catch (SDH_Muhely_Imap_Hiba $hiba) {
                // A kapcsolat úgyis zárul.
            }
        }

        $this->bont();
    }

    private function bont(): void
    {
        if (is_resource($this->kapcsolat)) {
            @fclose($this->kapcsolat);
        }

        $this->kapcsolat = null;
    }

    public function tud(string $kepesseg): bool
    {
        return in_array(strtoupper($kepesseg), $this->kepessegek, true);
    }

    private function kepessegek_olvas(): void
    {
        foreach ($this->parancs('CAPABILITY') as $v) {
            if (isset($v[1]) && is_string($v[1]) && strtoupper($v[1]) === 'CAPABILITY') {
                $this->kepessegek = array_map('strtoupper', array_filter(array_slice($v, 2), 'is_string'));
            }
        }
    }

    /* =================================================================
     * Mappák
     * ============================================================== */

    /**
     * Minden mappa (Gmailnél: címke).
     *
     * @return array<int, array{nyers: string, nev: string, elvalaszto: string, jelzok: array<int, string>, szerep: string, valaszthato: bool}>
     */
    public function mappak(): array
    {
        $ki = [];

        foreach ($this->parancs('LIST "" "*"') as $v) {
            if (!isset($v[1], $v[4]) || !is_string($v[1]) || strtoupper($v[1]) !== 'LIST' || !is_string($v[4])) {
                continue;
            }

            $jelzok = array_map('strtolower', array_filter(is_array($v[2]) ? $v[2] : [], 'is_string'));
            $nyers  = $v[4];
            $nev    = self::mutf7_dekodol($nyers);

            $ki[$nyers] = [
                'nyers'       => $nyers,
                'nev'         => $nev,
                'elvalaszto'  => is_string($v[3]) ? $v[3] : '/',
                'jelzok'      => array_values($jelzok),
                'szerep'      => self::szerep($nev, $jelzok),
                'valaszthato' => !in_array('\\noselect', $jelzok, true) && !in_array('\\nonexistent', $jelzok, true),
            ];
        }

        // Ha két mappa is ugyanazt a szerepet kapta volna (név alapján), az első marad.
        $latott = [];

        foreach ($ki as $kulcs => $m) {
            if ($m['szerep'] === '') {
                continue;
            }

            if (isset($latott[$m['szerep']])) {
                $ki[$kulcs]['szerep'] = '';
            } else {
                $latott[$m['szerep']] = true;
            }
        }

        return array_values($ki);
    }

    /**
     * A mappa szerepe: inbox, sent, drafts, trash, junk, all, flagged, important – vagy üres.
     *
     * @param array<int, string> $jelzok kisbetűs jelzők
     */
    private static function szerep(string $nev, array $jelzok): string
    {
        if (strtoupper($nev) === 'INBOX') {
            return 'inbox';
        }

        $jelzobol = [
            '\\sent' => 'sent', '\\drafts' => 'drafts', '\\trash' => 'trash', '\\junk' => 'junk', '\\spam' => 'junk',
            '\\all' => 'all', '\\allmail' => 'all', '\\archive' => 'all', '\\flagged' => 'flagged', '\\starred' => 'flagged', '\\important' => 'important',
        ];

        foreach ($jelzok as $j) {
            if (isset($jelzobol[$j])) {
                return $jelzobol[$j];
            }
        }

        // Tartalék: a szerver nem ad SPECIAL-USE jelzőt (nem Gmail).
        $level = mb_strtolower((string) preg_replace('~^.*[/.]~u', '', $nev), 'UTF-8');
        $nevbol = [
            'sent' => ['sent', 'sent items', 'sent mail', 'elküldött', 'elküldött elemek', 'elküldött levelek'],
            'drafts' => ['drafts', 'draft', 'piszkozatok', 'piszkozat'],
            'trash' => ['trash', 'deleted items', 'deleted messages', 'kuka', 'törölt elemek', 'törölt'],
            'junk' => ['junk', 'spam', 'junk e-mail', 'levélszemét'],
        ];

        foreach ($nevbol as $szerep => $nevek) {
            if (in_array($level, $nevek, true)) {
                return $szerep;
            }
        }

        return '';
    }

    /**
     * Mappa megnyitása.
     *
     * @return array{exists: int, uidvalidity: int, uidnext: int}
     */
    public function kivalaszt(string $nyers, bool $csak_olvas = false): array
    {
        $ki = ['exists' => 0, 'uidvalidity' => 0, 'uidnext' => 0];

        foreach ($this->parancs(($csak_olvas ? 'EXAMINE ' : 'SELECT ') . self::idez($nyers)) as $v) {
            if (isset($v[2]) && is_string($v[2]) && strtoupper($v[2]) === 'EXISTS') {
                $ki['exists'] = (int) $v[1];
            }

            // * OK [UIDVALIDITY 123] … – a szögletes zárójeles rész egy atom.
            if (isset($v[2]) && is_string($v[2]) && preg_match('/^\[(UIDVALIDITY|UIDNEXT) (\d+)\]$/i', $v[2], $m) === 1) {
                $ki[strtolower($m[1])] = (int) $m[2];
            }
        }

        return $ki;
    }

    /**
     * Egy (nem megnyitott) mappa számlálói.
     *
     * @return array{messages: int, unseen: int, uidnext: int, uidvalidity: int}
     */
    public function allapot(string $nyers): array
    {
        $ki = ['messages' => 0, 'unseen' => 0, 'uidnext' => 0, 'uidvalidity' => 0];

        foreach ($this->parancs('STATUS ' . self::idez($nyers) . ' (MESSAGES UNSEEN UIDNEXT UIDVALIDITY)') as $v) {
            if (!isset($v[1], $v[3]) || !is_string($v[1]) || strtoupper($v[1]) !== 'STATUS' || !is_array($v[3])) {
                continue;
            }

            for ($i = 0; $i + 1 < count($v[3]); $i += 2) {
                $kulcs = strtolower((string) $v[3][$i]);

                if (isset($ki[$kulcs])) {
                    $ki[$kulcs] = (int) $v[3][$i + 1];
                }
            }
        }

        return $ki;
    }

    public function mappa_letrehoz(string $nev_utf8): void
    {
        $this->parancs('CREATE ' . self::idez(self::mutf7_kodol($nev_utf8)));
    }

    public function mappa_atnevez(string $nyers, string $uj_nev_utf8): void
    {
        $this->parancs('RENAME ' . self::idez($nyers) . ' ' . self::idez(self::mutf7_kodol($uj_nev_utf8)));
    }

    public function mappa_torol(string $nyers): void
    {
        $this->parancs('DELETE ' . self::idez($nyers));
    }

    /* =================================================================
     * Levelek
     * ============================================================== */

    /**
     * Keresés a megnyitott mappában; UID-ket ad vissza, növekvő sorrendben.
     *
     * @return array<int, int>
     */
    public function uid_keres(string $feltetel): array
    {
        return $this->keres('UID SEARCH ' . $feltetel);
    }

    /**
     * Keresés; sorszámokat (nem UID-ket) ad vissza.
     *
     * @return array<int, int>
     */
    public function sorszam_keres(string $feltetel): array
    {
        return $this->keres('SEARCH ' . $feltetel);
    }

    /**
     * Szabad szavas keresés. Gmailnél a Gmail saját keresője (X-GM-RAW), máshol
     * a szabványos TEXT keresés.
     *
     * @return array<int, int> UID-k
     */
    public function szoveg_keres(string $szoveg): array
    {
        $szoveg = trim($szoveg);

        if ($szoveg === '') {
            return [];
        }

        if ($this->tud('X-GM-EXT-1')) {
            return $this->parancs_keres('UID SEARCH CHARSET UTF-8 X-GM-RAW', $szoveg);
        }

        return $this->parancs_keres('UID SEARCH CHARSET UTF-8 TEXT', $szoveg);
    }

    /** @return array<int, int> */
    private function parancs_keres(string $parancs, string $szoveg): array
    {
        return $this->szamok($this->parancs($parancs . ' ', $szoveg));
    }

    /** @return array<int, int> */
    private function keres(string $parancs): array
    {
        return $this->szamok($this->parancs($parancs));
    }

    /**
     * @param array<int, array<int, mixed>> $valaszok
     * @return array<int, int>
     */
    private function szamok(array $valaszok): array
    {
        $ki = [];

        foreach ($valaszok as $v) {
            if (isset($v[1]) && is_string($v[1]) && strtoupper($v[1]) === 'SEARCH') {
                foreach (array_slice($v, 2) as $szam) {
                    if (is_string($szam) && ctype_digit($szam)) {
                        $ki[] = (int) $szam;
                    }
                }
            }
        }

        sort($ki);

        return array_values(array_unique($ki));
    }

    /**
     * Levelek adatai.
     *
     * @param string $halmaz  UID-halmaz („12,15:20", „101:*") vagy – ha $uid hamis – sorszámhalmaz
     * @param string $elemek  pl. „UID FLAGS INTERNALDATE RFC822.SIZE BODY.PEEK[HEADER]"
     * @return array<int, array{uid: int, sorszam: int, jelzok: array<int, string>, datum: string, meret: int, torzs: array<string, string>}>
     */
    public function lekeres(string $halmaz, string $elemek, bool $uid = true): array
    {
        $ki = [];

        foreach ($this->parancs(($uid ? 'UID FETCH ' : 'FETCH ') . $halmaz . ' (' . $elemek . ')') as $v) {
            if (!isset($v[2], $v[3]) || !is_string($v[2]) || strtoupper($v[2]) !== 'FETCH' || !is_array($v[3])) {
                continue;
            }

            $elem = ['uid' => 0, 'sorszam' => (int) $v[1], 'jelzok' => [], 'datum' => '', 'meret' => 0, 'torzs' => []];
            $van_jelzo = false;

            for ($i = 0; $i + 1 < count($v[3]); $i += 2) {
                $kulcs = is_string($v[3][$i]) ? strtoupper($v[3][$i]) : '';
                $ertek = $v[3][$i + 1];

                if ($kulcs === 'UID') {
                    $elem['uid'] = (int) $ertek;
                } elseif ($kulcs === 'FLAGS') {
                    $elem['jelzok'] = array_map('strtolower', array_filter(is_array($ertek) ? $ertek : [], 'is_string'));
                    $van_jelzo      = true;
                } elseif ($kulcs === 'INTERNALDATE') {
                    $elem['datum'] = (string) $ertek;
                } elseif ($kulcs === 'RFC822.SIZE') {
                    $elem['meret'] = (int) $ertek;
                } elseif (preg_match('/^BODY\[(.*?)\](?:<\d+>)?$/', $kulcs, $m) === 1) {
                    // A „HEADER.FIELDS (FROM TO …)" válasza is egyszerűen HEADER néven érhető el.
                    $elem['torzs'][strncmp($m[1], 'HEADER', 6) === 0 ? 'HEADER' : $m[1]] = is_string($ertek) ? $ertek : '';
                }
            }

            if ($elem['uid'] <= 0) {
                continue;
            }

            // Egy levélről több FETCH sor is jöhet (pl. kéretlen jelzőfrissítés): összefésüljük.
            if (isset($ki[$elem['uid']])) {
                $regi = $ki[$elem['uid']];
                $elem['torzs'] = $regi['torzs'] + $elem['torzs'];
                $elem['datum'] = $elem['datum'] !== '' ? $elem['datum'] : $regi['datum'];
                $elem['meret'] = $elem['meret'] > 0 ? $elem['meret'] : $regi['meret'];
                $elem['jelzok'] = $van_jelzo ? $elem['jelzok'] : $regi['jelzok'];
            }

            $ki[$elem['uid']] = $elem;
        }

        ksort($ki);

        return array_values($ki);
    }

    /** A teljes levél nyersen (a „nem olvasott" állapotot nem változtatja meg). */
    public function nyers_level(int $uid): string
    {
        $sorok = $this->lekeres((string) $uid, 'UID BODY.PEEK[]');

        return $sorok !== [] ? (string) ($sorok[0]['torzs'][''] ?? '') : '';
    }

    /**
     * Több levél nyersen, egyetlen kéréssel (előtöltés a gyorsítótárba).
     *
     * @param array<int, int> $uidk
     * @return array<int, string> uid => nyers levél
     */
    public function nyers_levelek(array $uidk): array
    {
        $ki = [];

        if ($uidk === []) {
            return $ki;
        }

        foreach ($this->lekeres(self::halmaz($uidk), 'UID BODY.PEEK[]') as $e) {
            if ((string) ($e['torzs'][''] ?? '') !== '') {
                $ki[(int) $e['uid']] = (string) $e['torzs'][''];
            }
        }

        return $ki;
    }

    /**
     * Jelzők állítása.
     *
     * @param array<int, string> $jelzok pl. ['\\Seen']
     */
    public function jelzo(string $uid_halmaz, bool $be, array $jelzok): void
    {
        if ($uid_halmaz === '' || $jelzok === []) {
            return;
        }

        $this->parancs('UID STORE ' . $uid_halmaz . ' ' . ($be ? '+' : '-') . 'FLAGS.SILENT (' . implode(' ', $jelzok) . ')');
    }

    public function masol(string $uid_halmaz, string $cel_nyers): void
    {
        $this->parancs('UID COPY ' . $uid_halmaz . ' ' . self::idez($cel_nyers));
    }

    /** Áthelyezés: MOVE, ha a szerver tudja (a Gmail tudja); különben másolás + törlés. */
    public function athelyez(string $uid_halmaz, string $cel_nyers): void
    {
        if ($this->tud('MOVE')) {
            $this->parancs('UID MOVE ' . $uid_halmaz . ' ' . self::idez($cel_nyers));

            return;
        }

        $this->masol($uid_halmaz, $cel_nyers);
        $this->vegleg_torol($uid_halmaz);
    }

    /** Végleges törlés a megnyitott mappából (Gmailnél a Kukában és a Spamben valóban töröl). */
    public function vegleg_torol(string $uid_halmaz): void
    {
        $this->jelzo($uid_halmaz, true, ['\\Deleted']);

        // UIDPLUS-szal csak a megadott levelek törlődnek, nem minden \Deleted jelzésű.
        $this->parancs($this->tud('UIDPLUS') ? 'UID EXPUNGE ' . $uid_halmaz : 'EXPUNGE');
    }

    /**
     * Levél hozzáfűzése egy mappához (piszkozat, elküldött).
     *
     * @param array<int, string> $jelzok
     */
    public function hozzafuz(string $nyers_mappa, string $uzenet, array $jelzok = []): void
    {
        $this->parancs('APPEND ' . self::idez($nyers_mappa) . ' (' . implode(' ', $jelzok) . ') ', $uzenet);
    }

    public function ures(): void
    {
        $this->parancs('NOOP');
    }

    /* =================================================================
     * Halmazok, nevek
     * ============================================================== */

    /**
     * UID-lista tömör halmazként: [1,2,3,7] → „1:3,7".
     *
     * @param array<int, int> $uidk
     */
    public static function halmaz(array $uidk): string
    {
        $uidk = array_values(array_unique(array_filter(array_map('intval', $uidk), static fn (int $u): bool => $u > 0)));
        sort($uidk);

        $reszek = [];
        $db     = count($uidk);

        for ($i = 0; $i < $db; $i++) {
            $kezdet = $uidk[$i];

            while ($i + 1 < $db && $uidk[$i + 1] === $uidk[$i] + 1) {
                $i++;
            }

            $reszek[] = $kezdet === $uidk[$i] ? (string) $kezdet : $kezdet . ':' . $uidk[$i];
        }

        return implode(',', $reszek);
    }

    /** Mappanév a szerver kódolásából (módosított UTF-7) UTF-8-ra. */
    public static function mutf7_dekodol(string $nyers): string
    {
        if (strpos($nyers, '&') === false) {
            return $nyers;
        }

        $ki = @mb_convert_encoding($nyers, 'UTF-8', 'UTF7-IMAP');

        return is_string($ki) && $ki !== '' ? $ki : $nyers;
    }

    public static function mutf7_kodol(string $utf8): string
    {
        if (preg_match('/[^\x20-\x25\x27-\x7e]/', $utf8) !== 1) {
            return $utf8;
        }

        $ki = @mb_convert_encoding($utf8, 'UTF7-IMAP', 'UTF-8');

        return is_string($ki) && $ki !== '' ? $ki : $utf8;
    }

    /** Idézett szöveg a parancsban. */
    private static function idez(string $szoveg): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $szoveg) . '"';
    }

    /* =================================================================
     * Protokoll
     * ============================================================== */

    /**
     * Parancs küldése, a címke nélküli válaszok visszaadása elemzett alakban.
     * A `$literal` a parancs végére kerülő adat (APPEND törzse, UTF-8 keresőszöveg).
     *
     * @return array<int, array<int, mixed>>
     * @throws SDH_Muhely_Imap_Hiba
     */
    private function parancs(string $parancs, ?string $literal = null): array
    {
        if (!is_resource($this->kapcsolat)) {
            throw new SDH_Muhely_Imap_Hiba('Nincs kapcsolat a levelezőszerverrel.');
        }

        $cimke = 'S' . (++$this->sorszam);

        if ($literal === null) {
            $this->ir($cimke . ' ' . $parancs . "\r\n");
        } else {
            // Szinkron literál: a szerver „+" válasza után megy az adat.
            $this->ir($cimke . ' ' . $parancs . '{' . strlen($literal) . "}\r\n");

            $v = $this->valasz_olvas();

            if (!isset($v[0]) || $v[0] !== '+') {
                if (isset($v[0]) && $v[0] === $cimke) {
                    throw new SDH_Muhely_Imap_Hiba(self::valasz_szoveg($v));
                }

                throw new SDH_Muhely_Imap_Hiba('A levelezőszerver nem fogadta az adatot.');
            }

            $this->ir($literal . "\r\n");
        }

        $valaszok = [];

        while (true) {
            $v = $this->valasz_olvas();

            if (isset($v[0]) && $v[0] === $cimke) {
                $allapot = isset($v[1]) && is_string($v[1]) ? strtoupper($v[1]) : '';

                if ($allapot !== 'OK') {
                    throw new SDH_Muhely_Imap_Hiba(self::valasz_szoveg($v));
                }

                return $valaszok;
            }

            if (isset($v[0]) && $v[0] === '*' && isset($v[1]) && is_string($v[1]) && strtoupper($v[1]) === 'BYE' && strncmp($parancs, 'LOGOUT', 6) !== 0) {
                $this->bont();

                throw new SDH_Muhely_Imap_Hiba('A levelezőszerver bontotta a kapcsolatot. ' . self::valasz_szoveg($v));
            }

            $valaszok[] = $v;
        }
    }

    /** @param array<int, mixed> $v */
    private static function valasz_szoveg(array $v): string
    {
        $reszek = [];

        foreach (array_slice($v, 2) as $r) {
            if (is_string($r)) {
                $reszek[] = $r;
            }
        }

        return trim(implode(' ', $reszek)) !== '' ? trim(implode(' ', $reszek)) : 'A levelezőszerver hibát jelzett.';
    }

    private function ir(string $adat): void
    {
        $hossz = strlen($adat);
        $irt   = 0;

        while ($irt < $hossz) {
            $n = @fwrite($this->kapcsolat, substr($adat, $irt));

            if ($n === false || $n === 0) {
                $this->bont();

                throw new SDH_Muhely_Imap_Hiba('A levelezőszerverrel megszakadt a kapcsolat (írás).');
            }

            $irt += $n;
        }
    }

    private function sor_olvas(): string
    {
        $sor = @fgets($this->kapcsolat);

        if ($sor === false) {
            $meta = is_resource($this->kapcsolat) ? stream_get_meta_data($this->kapcsolat) : [];
            $this->bont();

            throw new SDH_Muhely_Imap_Hiba(!empty($meta['timed_out'])
                ? 'A levelezőszerver nem válaszolt időben.'
                : 'A levelezőszerverrel megszakadt a kapcsolat.');
        }

        return $sor;
    }

    private function bajt_olvas(int $hossz): string
    {
        $adat = '';

        while (strlen($adat) < $hossz) {
            $resz = @fread($this->kapcsolat, min(65536, $hossz - strlen($adat)));

            if ($resz === false || $resz === '') {
                $meta = is_resource($this->kapcsolat) ? stream_get_meta_data($this->kapcsolat) : [];

                if (empty($meta['timed_out']) && is_resource($this->kapcsolat) && !feof($this->kapcsolat)) {
                    continue;
                }

                $this->bont();

                throw new SDH_Muhely_Imap_Hiba('A levelezőszerverrel megszakadt a kapcsolat (olvasás).');
            }

            $adat .= $resz;
        }

        return $adat;
    }

    /**
     * Egy teljes válasz beolvasása (a sorvégi literálokkal együtt) és elemzése.
     *
     * @return array<int, mixed>
     */
    private function valasz_olvas(): array
    {
        $this->literalok = [];
        $teljes          = '';

        while (true) {
            $sor = $this->sor_olvas();

            // A sor végén {n}: n bájt literál következik, utána folytatódik a válasz.
            if (preg_match('/\{(\d+)\}\r?\n$/', $sor, $m) === 1) {
                $this->literalok[] = $this->bajt_olvas((int) $m[1]);
                $teljes           .= substr($sor, 0, -strlen($m[0])) . "\x00" . (count($this->literalok) - 1) . "\x00";

                continue;
            }

            $teljes .= rtrim($sor, "\r\n");

            break;
        }

        $poz = 0;

        return $this->elemez($teljes, $poz, false);
    }

    /**
     * IMAP-válasz elemzése: atomok, "idézett szöveg", literál-helyőrzők,
     * (zárójeles listák) – ez utóbbiak beágyazott tömbként. A NIL null lesz.
     *
     * @return array<int, mixed>
     */
    private function elemez(string $s, int &$poz, bool $listaban): array
    {
        $ki    = [];
        $hossz = strlen($s);

        while ($poz < $hossz) {
            $c = $s[$poz];

            if ($c === ' ') {
                $poz++;

                continue;
            }

            if ($c === '(') {
                $poz++;
                $ki[] = $this->elemez($s, $poz, true);

                continue;
            }

            if ($c === ')') {
                $poz++;

                if ($listaban) {
                    return $ki;
                }

                continue;
            }

            if ($c === '"') {
                $poz++;
                $szoveg = '';

                while ($poz < $hossz && $s[$poz] !== '"') {
                    if ($s[$poz] === '\\' && $poz + 1 < $hossz) {
                        $poz++;
                    }

                    $szoveg .= $s[$poz];
                    $poz++;
                }

                $poz++;
                $ki[] = $szoveg;

                continue;
            }

            if ($c === "\x00") {
                $veg  = strpos($s, "\x00", $poz + 1);
                $ki[] = $this->literalok[(int) substr($s, $poz + 1, $veg - $poz - 1)] ?? '';
                $poz  = $veg + 1;

                continue;
            }

            // Atom. A szögletes zárójelen belüli rész (BODY[HEADER.FIELDS (FROM TO)],
            // [UIDVALIDITY 12]) szóközökkel és zárójelekkel együtt az atomhoz tartozik.
            $atom  = '';
            $melyseg = 0;

            while ($poz < $hossz) {
                $c = $s[$poz];

                if ($c === '[') {
                    $melyseg++;
                } elseif ($c === ']' && $melyseg > 0) {
                    $melyseg--;
                } elseif ($melyseg === 0 && ($c === ' ' || $c === '(' || $c === ')' || $c === "\x00")) {
                    break;
                }

                $atom .= $c;
                $poz++;
            }

            $ki[] = $listaban && strtoupper($atom) === 'NIL' ? null : $atom;
        }

        return $ki;
    }
}
