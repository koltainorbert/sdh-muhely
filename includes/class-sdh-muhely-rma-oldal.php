<?php
/**
 * Az ügyféloldal megjelenítése (RMA).
 *
 * A CRM formanyelvét használja (assets/admin.css színváltozói, jelvényei,
 * gombjai), egy ablakban a választható háttér (szín, kép, videó) fölött –
 * ahogy a munkalap-ablak a CRM-ben: bal oldalt az állapot, a dátumok és az
 * összeg, jobbra lapfülek (Eszköz, Hibák, Tételek, Történet, Üzenetek,
 * Elérhetőség). Mobilon az ablak kitölti a képernyőt, az oldalsáv egy
 * „Összegzés" lapfül lesz. Nem görgetős hosszú oldal: a tartalom az ablak
 * lapfülein belül marad.
 *
 * Világos / sötét mód (az ügyfél átválthatja, a böngésző megjegyzi) és
 * nyelvválasztás (magyar, angol, német – a Beállításokban szűkíthető).
 *
 * Az adatot, a feloldást és a POST-okat az SDH_Muhely_Rma intézi.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Rma_Oldal
{
    /** A nyelv sütije. */
    private const NYELV_SUTI = 'sdh_rma_nyelv';

    /** Az aktuális nyelv (hu | en | de). */
    private static string $nyelv = 'hu';

    /** A felület szövegei: kulcs => [hu, en, de]. */
    private const SZOVEG = [
        'alcim'           => ['Javítás állapota', 'Repair status', 'Reparaturstatus'],
        'belep_cim'       => ['Munkalap megtekintése', 'View your repair', 'Reparatur ansehen'],
        'belep_szoveg'    => [
            'A javítás állapotához adja meg a telefonszámát és a munkalap sorszámát. A sorszámot a munkalapon vagy a címkén találja.',
            'Enter your phone number and the job number to see the status of your repair. You will find the job number on your receipt or label.',
            'Geben Sie Ihre Telefonnummer und die Auftragsnummer ein, um den Status Ihrer Reparatur zu sehen. Die Auftragsnummer finden Sie auf Ihrem Beleg oder Etikett.',
        ],
        'telefon'         => ['Telefonszám', 'Phone number', 'Telefonnummer'],
        'sorszam'         => ['Munkalap sorszáma', 'Job number', 'Auftragsnummer'],
        'megnyitas'       => ['Megnyitás', 'Open', 'Öffnen'],
        'munkalap'        => ['Munkalap', 'Job', 'Auftrag'],
        'allapot'         => ['Állapot', 'Status', 'Status'],
        'utoljara'        => ['Utoljára módosult', 'Last updated', 'Zuletzt geändert'],
        'datumok'         => ['Dátumok', 'Dates', 'Termine'],
        'atvetel'         => ['Átvétel', 'Received', 'Angenommen'],
        'hatarido'        => ['Várható elkészülés', 'Expected ready', 'Voraussichtlich fertig'],
        'lezarva'         => ['Lezárva', 'Closed', 'Abgeschlossen'],
        'osszeg'          => ['Összeg', 'Amount', 'Betrag'],
        'brutto'          => ['Bruttó végösszeg', 'Total incl. VAT', 'Gesamt inkl. MwSt.'],
        'eloleg'          => ['Befizetett előleg', 'Deposit paid', 'Anzahlung'],
        'fizetendo'       => ['Fizetendő', 'Amount due', 'Zu zahlen'],
        'kifizetve'       => ['Kifizetve', 'Paid', 'Bezahlt'],
        'nincs_fizetendo' => ['Nincs fizetendő', 'Nothing to pay', 'Nichts zu zahlen'],
        'nincs_osszeg'    => ['Az összeg még nincs rögzítve.', 'The amount has not been set yet.', 'Der Betrag steht noch nicht fest.'],
        'f_osszegzes'     => ['Összegzés', 'Summary', 'Übersicht'],
        'f_eszkoz'        => ['Eszköz', 'Device', 'Gerät'],
        'f_hibak'         => ['Hibák', 'Faults', 'Fehler'],
        'f_tetelek'       => ['Tételek', 'Items', 'Positionen'],
        'f_tortenet'      => ['Történet', 'History', 'Verlauf'],
        'f_uzenetek'      => ['Üzenetek', 'Messages', 'Nachrichten'],
        'f_elerhetoseg'   => ['Elérhetőség', 'Contact', 'Kontakt'],
        'ugyfel'          => ['Ügyfél', 'Customer', 'Kunde'],
        'eszkoz'          => ['Eszköz', 'Device', 'Gerät'],
        'sorozatszam'     => ['Sorozatszám', 'Serial number', 'Seriennummer'],
        'szin'            => ['Szín', 'Colour', 'Farbe'],
        'tartozekok'      => ['Tartozékok', 'Accessories', 'Zubehör'],
        'atveteli'        => ['Átvételkori állapot', 'Condition on receipt', 'Zustand bei Annahme'],
        'megj_szerviz'    => ['Megjegyzés a szerviztől', 'Note from the service', 'Hinweis vom Service'],
        'nincs_hiba'      => ['Nincs rögzített hiba.', 'No faults recorded.', 'Keine Fehler erfasst.'],
        'megnevezes'      => ['Megnevezés', 'Description', 'Bezeichnung'],
        'menny'           => ['Menny.', 'Qty', 'Menge'],
        'osszesen'        => ['Összesen', 'Total', 'Summe'],
        'szolgaltatas'    => ['Munka', 'Labour', 'Arbeit'],
        'termek'          => ['Alkatrész', 'Part', 'Teil'],
        'nincs_tetel'     => ['Még nincs rögzített tétel.', 'No items yet.', 'Noch keine Positionen.'],
        'nincs_tortenet'  => ['Még nincs bejegyzés.', 'No entries yet.', 'Noch keine Einträge.'],
        'uz_ures'         => ['Még nincs üzenet. Kérdését alább írhatja meg.', 'No messages yet. You can write your question below.', 'Noch keine Nachrichten. Ihre Frage können Sie unten schreiben.'],
        'uz_mezo'         => ['Üzenet a szerviznek…', 'Message to the service…', 'Nachricht an den Service…'],
        'kuldes'          => ['Küldés', 'Send', 'Senden'],
        'on'              => ['Ön', 'You', 'Sie'],
        'kilepes'         => ['Kilépés', 'Sign out', 'Abmelden'],
        'tema'            => ['Világos / sötét mód', 'Light / dark mode', 'Hell- / Dunkelmodus'],
        'nyelv'           => ['Nyelv', 'Language', 'Sprache'],
        'hivas'           => ['Hívás', 'Call', 'Anrufen'],
        'iras'            => ['E-mail', 'E-mail', 'E-Mail'],
        'utvonal'         => ['Útvonal', 'Directions', 'Route'],
        'cim'             => ['Cím', 'Address', 'Adresse'],
        'email'           => ['E-mail', 'E-mail', 'E-Mail'],
        'nyitvatartas'    => ['Nyitvatartás', 'Opening hours', 'Öffnungszeiten'],
        'weboldal'        => ['Weboldal', 'Website', 'Webseite'],
        'egyeb'           => ['Tudnivaló', 'Good to know', 'Gut zu wissen'],
        'nincs_elerhetoseg' => ['Az elérhetőség még nincs megadva.', 'Contact details have not been added yet.', 'Kontaktdaten wurden noch nicht hinterlegt.'],
        'h_rossz'         => ['A telefonszám vagy a munkalap sorszáma nem egyezik. Ellenőrizze, és próbálja újra.', 'The phone number or job number does not match. Please check and try again.', 'Telefonnummer oder Auftragsnummer stimmen nicht. Bitte prüfen und erneut versuchen.'],
        'h_sok'           => ['Túl sok sikertelen próbálkozás. Kérjük, próbálja újra 15 perc múlva.', 'Too many failed attempts. Please try again in 15 minutes.', 'Zu viele Fehlversuche. Bitte versuchen Sie es in 15 Minuten erneut.'],
        'h_lejart'        => ['Az űrlap lejárt. Kérjük, írja meg újra az üzenetet.', 'The form has expired. Please write your message again.', 'Das Formular ist abgelaufen. Bitte schreiben Sie Ihre Nachricht erneut.'],
        'h_ures'          => ['Az üzenet üres volt.', 'The message was empty.', 'Die Nachricht war leer.'],
        'h_sokuzenet'     => ['Rövid időn belül sok üzenet érkezett. Kérjük, próbálja később, vagy hívjon minket.', 'Too many messages in a short time. Please try later or give us a call.', 'Zu viele Nachrichten in kurzer Zeit. Bitte später erneut versuchen oder anrufen.'],
        'ok_kuldve'       => ['Üzenetét megkaptuk, hamarosan válaszolunk.', 'We have received your message and will reply soon.', 'Wir haben Ihre Nachricht erhalten und antworten bald.'],
        'ismeretlen_cim'  => ['Ismeretlen munkalap', 'Unknown job', 'Unbekannter Auftrag'],
        'ismeretlen'      => [
            'Ez a hivatkozás nem érvényes, vagy a munkalap még nincs rögzítve. Kérjük, olvassa be újra a munkalapon lévő QR-kódot, vagy keresse a szervizt.',
            'This link is not valid, or the job has not been registered yet. Please scan the QR code on your receipt again or contact the service.',
            'Dieser Link ist ungültig oder der Auftrag ist noch nicht erfasst. Bitte scannen Sie den QR-Code auf Ihrem Beleg erneut oder wenden Sie sich an den Service.',
        ],
        'elonezet'        => [
            'Munkatársi előnézet – pontosan ezt látja az ügyfél, miután a telefonszámával és a munkalap sorszámával belépett. Az innen küldött üzenet az ügyfél nevében kerül a munkalap üzenetei közé.',
            'Staff preview – this is exactly what the customer sees after signing in with their phone number and job number. A message sent from here is added to the job as if the customer had written it.',
            'Mitarbeiter-Vorschau – genau das sieht der Kunde nach der Anmeldung mit Telefon- und Auftragsnummer. Eine von hier gesendete Nachricht wird dem Auftrag im Namen des Kunden hinzugefügt.',
        ],
        'pelda'           => ['pl.', 'e.g.', 'z. B.'],
        'ctrl_enter'      => ['Ctrl + Enter: küldés', 'Ctrl + Enter: send', 'Strg + Enter: senden'],
    ];

    /** Az alapállapotok fordítása (csak ha a név a gyári magyar). */
    private const ALLAPOT = [
        'bejelentett' => ['Bejelentett', 'Registered', 'Angemeldet'],
        'arajanlat'   => ['Árajánlat', 'Quotation', 'Kostenvoranschlag'],
        'sablon'      => ['Sablon', 'Template', 'Vorlage'],
        'fuggo'       => ['Függő', 'On hold', 'Ausstehend'],
        'nyitott'     => ['Nyitott', 'In progress', 'In Bearbeitung'],
        'elkeszult'   => ['Elkészült', 'Ready', 'Fertig'],
        'lezart'      => ['Lezárt', 'Completed', 'Abgeschlossen'],
        'ervenytelen' => ['Érvénytelen', 'Cancelled', 'Storniert'],
    ];

    /** A hibasor-állapotok fordítása. */
    private const HIBA_ALLAPOT = [
        'uj'            => ['Új', 'New', 'Neu'],
        'folyamatban'   => ['Folyamatban', 'In progress', 'In Bearbeitung'],
        'kesz'          => ['Kész', 'Done', 'Erledigt'],
        'nem_javithato' => ['Nem javítható', 'Not repairable', 'Nicht reparierbar'],
    ];

    /** A gyakori eszközkategóriák fordítása. */
    private const KATEGORIA = [
        'telefon'     => ['Phone', 'Telefon'],
        'tablet'      => ['Tablet', 'Tablet'],
        'ora'         => ['Smartwatch', 'Smartwatch'],
        'fejhallgato' => ['Headphones', 'Kopfhörer'],
        'ebook'       => ['E-book reader', 'E-Book-Reader'],
        'powerbank'   => ['Power bank / charger', 'Powerbank / Ladegerät'],
        'laptop'      => ['Laptop', 'Laptop'],
        'asztali'     => ['Desktop PC', 'Desktop-PC'],
        'aio'         => ['All-in-one PC', 'All-in-One-PC'],
        'monitor'     => ['Monitor', 'Monitor'],
        'nyomtato'    => ['Printer / scanner', 'Drucker / Scanner'],
        'halozat'     => ['Router / network device', 'Router / Netzwerkgerät'],
        'tarolo'      => ['External storage', 'Externer Speicher'],
        'tv'          => ['TV', 'Fernseher'],
        'konzol'      => ['Game console', 'Spielkonsole'],
        'kezi_konzol' => ['Handheld console', 'Handheld-Konsole'],
        'projektor'   => ['Projector', 'Projektor'],
        'hangszoro'   => ['Bluetooth speaker', 'Bluetooth-Lautsprecher'],
        'fenykepezo'  => ['Camera', 'Kamera'],
        'kamera'      => ['Video / action camera', 'Video- / Actionkamera'],
        'drone'       => ['Drone', 'Drohne'],
    ];

    private const NYELV_INDEX = ['hu' => 0, 'en' => 1, 'de' => 2];

    /* =================================================================
     * Nyelv
     * ============================================================== */

    /** A nyelv kiválasztása: ?nyelv=, süti, beállítás vagy a böngésző nyelve. */
    public static function nyelv_beallit(): void
    {
        $b        = SDH_Muhely_Rma::beallitas();
        $engedett = $b['nyelvek'];

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $kert = isset($_GET['nyelv']) ? sanitize_key(wp_unslash($_GET['nyelv'])) : '';

        if ($kert !== '' && in_array($kert, $engedett, true)) {
            SDH_Muhely_Rma::suti_beallit(self::NYELV_SUTI, $kert, time() + YEAR_IN_SECONDS);
            self::$nyelv = $kert;

            return;
        }

        $suti = isset($_COOKIE[self::NYELV_SUTI]) ? sanitize_key(wp_unslash($_COOKIE[self::NYELV_SUTI])) : '';

        if (in_array($suti, $engedett, true)) {
            self::$nyelv = $suti;

            return;
        }

        if ($b['nyelv'] !== 'auto') {
            self::$nyelv = in_array($b['nyelv'], $engedett, true) ? (string) $b['nyelv'] : $engedett[0];

            return;
        }

        self::$nyelv = self::bongeszo_nyelv(
            isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? (string) $_SERVER['HTTP_ACCEPT_LANGUAGE'] : '',
            $engedett
        );
    }

    /**
     * Az Accept-Language fejlécből az első engedett nyelv (különben az első engedett).
     *
     * @param array<int, string> $engedett
     */
    public static function bongeszo_nyelv(string $fejlec, array $engedett): string
    {
        $jeloltek = [];

        foreach (explode(',', strtolower($fejlec)) as $resz) {
            $darabok = explode(';q=', trim($resz));
            $kod     = substr(trim($darabok[0]), 0, 2);
            $suly    = isset($darabok[1]) ? (float) $darabok[1] : 1.0;

            if ($kod !== '' && !isset($jeloltek[$kod])) {
                $jeloltek[$kod] = $suly;
            }
        }

        arsort($jeloltek);

        foreach (array_keys($jeloltek) as $kod) {
            if (in_array($kod, $engedett, true)) {
                return $kod;
            }
        }

        return $engedett[0] ?? 'hu';
    }

    public static function nyelv(): string
    {
        return self::$nyelv;
    }

    /** Teszteléshez és az előnézethez. */
    public static function nyelv_kenyszerit(string $nyelv): void
    {
        self::$nyelv = isset(self::NYELV_INDEX[$nyelv]) ? $nyelv : 'hu';
    }

    /** Egy felületi szöveg az aktuális nyelven. */
    public static function t(string $kulcs): string
    {
        $sor = self::SZOVEG[$kulcs] ?? null;

        if ($sor === null) {
            return $kulcs;
        }

        return $sor[self::NYELV_INDEX[self::$nyelv] ?? 0] ?? $sor[0];
    }

    /** Munkalap-állapot neve: a gyári nevek fordítva, a sajátok ahogy beírták. */
    public static function allapot_nev(string $kulcs, array $allapot): string
    {
        $nev = (string) ($allapot['nev'] ?? $kulcs);

        if (isset(self::ALLAPOT[$kulcs]) && self::ALLAPOT[$kulcs][0] === $nev) {
            return self::ALLAPOT[$kulcs][self::NYELV_INDEX[self::$nyelv]];
        }

        return $nev;
    }

    private static function hiba_allapot_nev(string $kulcs, array $allapot): string
    {
        $nev = (string) ($allapot['nev'] ?? $kulcs);

        if (isset(self::HIBA_ALLAPOT[$kulcs]) && self::HIBA_ALLAPOT[$kulcs][0] === $nev) {
            return self::HIBA_ALLAPOT[$kulcs][self::NYELV_INDEX[self::$nyelv]];
        }

        return $nev;
    }

    private static function kategoria(string $kulcs): string
    {
        if (self::$nyelv !== 'hu' && isset(self::KATEGORIA[$kulcs])) {
            return self::KATEGORIA[$kulcs][self::$nyelv === 'en' ? 0 : 1];
        }

        return SDH_Muhely_Eszkoz::kategoria_cimke($kulcs);
    }

    private static function me(string $me): string
    {
        if ($me === 'db') {
            return ['hu' => 'db', 'en' => 'pcs', 'de' => 'Stk.'][self::$nyelv];
        }

        return $me;
    }

    /** Dátum (és idő) a nyelv szokása szerint, vagy ''. */
    public static function ido(?string $ertek, bool $ora = true): string
    {
        $ertek = trim((string) $ertek);

        if ($ertek === '' || str_starts_with($ertek, '0000')) {
            return '';
        }

        $ts = strtotime($ertek);

        if ($ts === false) {
            return '';
        }

        $datum = ['hu' => 'Y. m. d.', 'en' => 'd/m/Y', 'de' => 'd.m.Y'][self::$nyelv];
        $van_ora = $ora && strlen($ertek) > 10;

        return gmdate($datum . ($van_ora ? ' H:i' : ''), $ts);
    }

    /** Összeg forintban, a nyelv tagolásával. */
    public static function penz(float $osszeg): string
    {
        $nbsp = "\u{00A0}";

        switch (self::$nyelv) {
            case 'en':
                return number_format($osszeg, 0, '.', ',') . $nbsp . 'Ft';
            case 'de':
                return number_format($osszeg, 0, ',', '.') . $nbsp . 'Ft';
            default:
                return number_format($osszeg, 0, ',', $nbsp) . $nbsp . 'Ft';
        }
    }

    /* =================================================================
     * Keret
     * ============================================================== */

    /** Ikonok (vonalas, a CRM ikonjaihoz illő). */
    private static function ikon(string $nev): string
    {
        $rajzok = [
            'osszegzes'   => '<rect x="3.5" y="4" width="13" height="12" rx="1.6"/><path d="M3.5 8h13M8 8v8"/>',
            'eszkoz'      => '<rect x="6" y="2.5" width="8" height="15" rx="1.8"/><path d="M8.6 15.2h2.8"/>',
            'hibak'       => '<circle cx="10" cy="10" r="7"/><path d="M10 6.2v4.6M10 13.6v.2"/>',
            'tetelek'     => '<path d="M5 3h10v14l-2.5-1.5L10 17l-2.5-1.5L5 17z"/><path d="M7.5 7h5M7.5 10h5"/>',
            'tortenet'    => '<circle cx="10" cy="10" r="7"/><path d="M10 6v4l2.6 1.6"/>',
            'uzenetek'    => '<path d="M3.5 5.5a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H9l-3.5 3v-3h0a2 2 0 0 1-2-2z"/>',
            'elerhetoseg' => '<path d="M10 17s5.5-4.6 5.5-9A5.5 5.5 0 0 0 4.5 8c0 4.4 5.5 9 5.5 9z"/><circle cx="10" cy="8" r="2"/>',
            'telefon'     => '<path d="M5 3.5h2.6l1.2 3.2-1.7 1.1a8.6 8.6 0 0 0 5.1 5.1l1.1-1.7 3.2 1.2V15a1.5 1.5 0 0 1-1.6 1.5A12.6 12.6 0 0 1 3.5 5.1 1.5 1.5 0 0 1 5 3.5z"/>',
            'level'       => '<rect x="3" y="4.5" width="14" height="11" rx="1.6"/><path d="m3.5 5.5 6.5 5 6.5-5"/>',
            'web'         => '<circle cx="10" cy="10" r="7"/><path d="M3 10h14M10 3c2 2.2 2.9 4.5 2.9 7S12 14.8 10 17c-2-2.2-2.9-4.5-2.9-7S8 5.2 10 3z"/>',
            'ora'         => '<circle cx="10" cy="10" r="7"/><path d="M10 6v4l2.6 1.6"/>',
            'info'        => '<circle cx="10" cy="10" r="7"/><path d="M10 9v4.6M10 6.4v.2"/>',
            'nap'         => '<circle cx="10" cy="10" r="3.2"/><path d="M10 2.5v2M10 15.5v2M17.5 10h-2M4.5 10h-2M15.3 4.7l-1.4 1.4M6.1 13.9l-1.4 1.4M15.3 15.3l-1.4-1.4M6.1 6.1 4.7 4.7"/>',
            'hold'        => '<path d="M15.5 12.4A6.5 6.5 0 0 1 7.6 4.5a6.5 6.5 0 1 0 7.9 7.9z"/>',
            'kilep'       => '<path d="M8 4H5.5A1.5 1.5 0 0 0 4 5.5v9A1.5 1.5 0 0 0 5.5 16H8"/><path d="M12 6.5 15.5 10 12 13.5M15.5 10H8"/>',
            'lakat'       => '<rect x="4.5" y="9" width="11" height="8" rx="1.6"/><path d="M7 9V6.5a3 3 0 0 1 6 0V9"/>',
            'kuld'        => '<path d="M3.5 10 16.5 4l-4 12-2.6-5.4z"/><path d="m9.9 10.6 6.6-6.6"/>',
        ];

        return '<svg class="rma-ikon" viewBox="0 0 20 20" aria-hidden="true">' . ($rajzok[$nev] ?? $rajzok['info']) . '</svg>';
    }

    /** Relatív hivatkozás a mostani címre egy másik nyelvvel. */
    private static function nyelv_link(string $nyelv): string
    {
        $keres = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '/';

        return add_query_arg('nyelv', $nyelv, remove_query_arg(['h', 'ok', 'nyelv'], $keres));
    }

    /** A háttér, a szín és a mód beállításai CSS-ként. */
    private static function stilus(array $b): string
    {
        $css = ':root{--rma-sotetites:' . ((int) $b['sotetites'] / 100) . ';--rma-hatter-szin:' . esc_attr((string) $b['hatter_szin']) . ';}';

        $k = (string) $b['kiemelo'];

        if ($k !== '') {
            // A felirat színe a háttér fényességéhez igazodik (világos kiemelőn sötét betű).
            $hex = ltrim($k, '#');
            $hex = strlen($hex) === 3 ? $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2] : $hex;
            $r   = hexdec(substr($hex, 0, 2));
            $g   = hexdec(substr($hex, 2, 2));
            $bl  = hexdec(substr($hex, 4, 2));
            $fenyes = (0.299 * $r + 0.587 * $g + 0.114 * $bl) / 255;

            $css .= ':root,:root[data-accent]{--sdh-accent:' . $k . ';--sdh-accent-sotet:color-mix(in srgb,' . $k . ' 82%,#000);'
                . '--sdh-accent-halvany:color-mix(in srgb,' . $k . ' 12%,transparent);--sdh-accent-szoveg:' . $k . ';'
                . '--sdh-accent-felirat:' . ($fenyes > 0.62 ? '#14171a' : '#ffffff') . ';}'
                . ':root[data-theme="dark"],:root[data-theme="dark"][data-accent]{--sdh-accent-szoveg:color-mix(in srgb,' . $k . ' 70%,#fff);}';
        }

        return $css;
    }

    /**
     * Az oldal váza: háttér + ablak.
     *
     * @param callable $tartalom Az ablak tartalma.
     */
    private static function keret(string $cim, string $osztaly, callable $tartalom): void
    {
        $b       = SDH_Muhely_Rma::beallitas();
        $arculat = class_exists('SDH_Muhely_Arculat') ? SDH_Muhely_Arculat::beallitas() : ['szin' => ''];
        $accent  = (string) $b['kiemelo'] === '' ? (string) ($arculat['szin'] ?? '') : '';
        $tipus   = (string) $b['hatter_tipus'];
        $kep     = (string) $b['hatter_kep'];
        $video   = (string) $b['hatter_video'];

        if ($tipus === 'kep' && $kep === '') {
            $tipus = 'alap';
        }

        if ($tipus === 'video' && $video === '') {
            $tipus = $kep !== '' ? 'kep' : 'alap';
        }

        ?>
<!doctype html>
<html lang="<?php echo esc_attr(self::$nyelv); ?>"<?php echo $accent !== '' ? ' data-accent="' . esc_attr($accent) . '"' : ''; ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="no-referrer">
<title><?php echo esc_html($cim . ' – ' . $b['szerviz_nev']); ?></title>
<script>
    (function () {
        var alap = <?php echo wp_json_encode((string) $b['tema']); ?>, t = null;
        try { t = localStorage.getItem('sdh-rma-tema'); } catch (e) {}
        var sotet = t ? t === 'sotet' : (alap === 'sotet' || (alap === 'rendszer' && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches));
        document.documentElement.setAttribute('data-theme', sotet ? 'dark' : 'light');
        document.documentElement.classList.add('js');
    }());
</script>
<link rel="stylesheet" href="<?php echo esc_url(SDH_MUHELY_URL . 'assets/admin.css?v=' . SDH_Muhely_Admin_UI::eszkoz_verzio('assets/admin.css')); ?>">
<link rel="stylesheet" href="<?php echo esc_url(SDH_MUHELY_URL . 'assets/rma-ugyfel.css?v=' . SDH_Muhely_Admin_UI::eszkoz_verzio('assets/rma-ugyfel.css')); ?>">
<style><?php echo self::stilus($b); // phpcs:ignore WordPress.Security.EscapeOutput ?></style>
</head>
<body class="rma <?php echo esc_attr($osztaly); ?><?php echo $b['uveg'] ? ' rma--uveg' : ''; ?>">
<div class="rma-hatter rma-hatter--<?php echo esc_attr($tipus); ?>"<?php echo in_array($tipus, ['kep', 'video'], true) && $kep !== '' ? ' style="background-image:url(\'' . esc_url($kep) . '\')"' : ''; ?> aria-hidden="true">
    <?php if ($tipus === 'video') : ?>
        <video class="rma-hatter__video" src="<?php echo esc_url($video); ?>" autoplay muted loop playsinline preload="auto"<?php echo $kep !== '' ? ' poster="' . esc_url($kep) . '"' : ''; ?>></video>
    <?php endif; ?>
</div>
<?php call_user_func($tartalom); ?>
<script src="<?php echo esc_url(SDH_MUHELY_URL . 'assets/rma-ugyfel.js?v=' . SDH_Muhely_Admin_UI::eszkoz_verzio('assets/rma-ugyfel.js')); ?>" defer></script>
</body>
</html>
        <?php
    }

    /** Az ablak fejléce: márka, (munkalap), nyelv, mód, kilépés. */
    private static function fejlec(?object $munkalap, bool $kilepes, bool $elonezet): void
    {
        $b = SDH_Muhely_Rma::beallitas();

        ?>
        <header class="rma-fej">
            <div class="rma-fej__marka">
                <?php if ((string) $b['logo'] !== '') : ?>
                    <img class="rma-fej__logo" src="<?php echo esc_url((string) $b['logo']); ?>" alt="<?php echo esc_attr((string) $b['szerviz_nev']); ?>">
                <?php else : ?>
                    <span class="rma-fej__jel"><?php echo esc_html(mb_strtoupper(mb_substr((string) $b['szerviz_nev'], 0, 3))); ?></span>
                <?php endif; ?>
                <span class="rma-fej__nev">
                    <b><?php echo esc_html((string) $b['szerviz_nev']); ?></b>
                    <span><?php echo esc_html(self::t('alcim')); ?></span>
                </span>
            </div>

            <?php if ($munkalap !== null) :
                $allapotok = SDH_Muhely_Munkalap::allapotok();
                $kulcs     = (string) $munkalap->allapot;
                $allapot   = $allapotok[$kulcs] ?? ['nev' => $kulcs, 'szin' => 'szurke'];
                ?>
                <div class="rma-fej__lap">
                    <span class="rma-fej__cimke"><?php echo esc_html(self::t('munkalap')); ?></span>
                    <b class="rma-fej__szam"><?php echo esc_html(SDH_Muhely_Munkalap::szam_formaz($munkalap->munkalap_szam)); ?></b>
                    <span class="sdh-allapot sdh-allapot--<?php echo esc_attr((string) $allapot['szin']); ?>"><?php echo esc_html(self::allapot_nev($kulcs, $allapot)); ?></span>
                </div>
            <?php endif; ?>

            <div class="rma-fej__eszkozok">
                <?php if (count($b['nyelvek']) > 1) : ?>
                    <nav class="rma-nyelv" aria-label="<?php echo esc_attr(self::t('nyelv')); ?>">
                        <?php foreach ($b['nyelvek'] as $ny) : ?>
                            <a href="<?php echo esc_url(self::nyelv_link($ny)); ?>" hreflang="<?php echo esc_attr($ny); ?>"
                               class="<?php echo $ny === self::$nyelv ? 'is-aktiv' : ''; ?>"
                               <?php echo $ny === self::$nyelv ? 'aria-current="true"' : ''; ?>><?php echo esc_html(strtoupper($ny)); ?></a>
                        <?php endforeach; ?>
                    </nav>
                <?php endif; ?>

                <button type="button" class="rma-ikongomb" data-rma-tema title="<?php echo esc_attr(self::t('tema')); ?>" aria-label="<?php echo esc_attr(self::t('tema')); ?>">
                    <span class="rma-csak-vilagos"><?php echo self::ikon('hold'); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
                    <span class="rma-csak-sotet"><?php echo self::ikon('nap'); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
                </button>

                <?php if ($kilepes && !$elonezet) : ?>
                    <form method="post" class="rma-fej__kilep">
                        <input type="hidden" name="sdh_rma_muvelet" value="kilep">
                        <button type="submit" class="rma-ikongomb" title="<?php echo esc_attr(self::t('kilepes')); ?>" aria-label="<?php echo esc_attr(self::t('kilepes')); ?>">
                            <?php echo self::ikon('kilep'); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </header>

        <?php if ($elonezet) : ?>
            <div class="rma-elonezet"><?php echo esc_html(self::t('elonezet')); ?></div>
        <?php endif; ?>
        <?php
    }

    /** Hiba- vagy sikerüzenet a ?h= / ?ok= paraméterből. */
    private static function visszajelzes(): string
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $h  = isset($_GET['h']) ? sanitize_key(wp_unslash($_GET['h'])) : '';
        $ok = isset($_GET['ok']) ? sanitize_key(wp_unslash($_GET['ok'])) : '';
        // phpcs:enable

        if (in_array($h, ['rossz', 'sok', 'lejart', 'ures', 'sokuzenet'], true)) {
            return '<p class="rma-jelzes rma-jelzes--hiba" role="alert" data-rma-jelzes>' . esc_html(self::t('h_' . $h)) . '</p>';
        }

        if ($ok === 'kuldve') {
            return '<p class="rma-jelzes rma-jelzes--ok" role="status" data-rma-jelzes>' . esc_html(self::t('ok_kuldve')) . '</p>';
        }

        return '';
    }

    /**
     * A szerviz elérhetőségei, ahogy megadták.
     *
     * @return array{cim: string, telefon: string, email: string, weboldal: string, nyitvatartas: string, egyeb: string, terkep: string, tel_link: string}
     */
    private static function elerhetoseg(): array
    {
        $b   = SDH_Muhely_Rma::beallitas();
        $cim = trim((string) $b['cim']);
        $tel = trim((string) $b['telefon']);

        $terkep = (string) $b['terkep'];

        if ($terkep === '' && $cim !== '') {
            $terkep = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($cim);
        }

        return [
            'cim'          => $cim,
            'telefon'      => $tel,
            'email'        => trim((string) $b['email']),
            'weboldal'     => trim((string) $b['weboldal']),
            'nyitvatartas' => trim((string) $b['nyitvatartas']),
            'egyeb'        => trim((string) $b['egyeb']),
            'terkep'       => $terkep,
            'tel_link'     => $tel !== '' ? 'tel:' . preg_replace('/[^\d+]/', '', $tel) : '',
        ];
    }

    /** Gyorsgombok: Hívás, E-mail, Útvonal (ami meg van adva). */
    private static function gyorsgombok(): string
    {
        $e     = self::elerhetoseg();
        $gombok = '';

        if ($e['tel_link'] !== '') {
            $gombok .= '<a class="sdh-gomb" href="' . esc_url($e['tel_link'], ['tel']) . '">' . self::ikon('telefon') . esc_html(self::t('hivas')) . '</a>';
        }

        if ($e['email'] !== '') {
            $gombok .= '<a class="sdh-gomb" href="' . esc_url('mailto:' . $e['email'], ['mailto']) . '">' . self::ikon('level') . esc_html(self::t('iras')) . '</a>';
        }

        if ($e['terkep'] !== '') {
            $gombok .= '<a class="sdh-gomb" href="' . esc_url($e['terkep']) . '" target="_blank" rel="noopener">' . self::ikon('elerhetoseg') . esc_html(self::t('utvonal')) . '</a>';
        }

        return $gombok === '' ? '' : '<div class="rma-gyors">' . $gombok . '</div>';
    }

    /* =================================================================
     * Oldalak
     * ============================================================== */

    /** Érvénytelen hivatkozás. */
    public static function ismeretlen(): void
    {
        self::keret(self::t('ismeretlen_cim'), 'rma--zar', static function (): void {
            ?>
            <div class="rma-ablak rma-ablak--kicsi">
                <?php self::fejlec(null, false, false); ?>
                <div class="rma-zar">
                    <div class="rma-zar__ikon"><?php echo self::ikon('info'); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
                    <h1><?php echo esc_html(self::t('ismeretlen_cim')); ?></h1>
                    <p><?php echo esc_html(self::t('ismeretlen')); ?></p>
                    <?php echo self::gyorsgombok(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                </div>
            </div>
            <?php
        });
    }

    /** A zárt oldal: telefonszám + sorszám. */
    public static function zar_oldal(object $munkalap, bool $elonezet = false): void
    {
        self::keret(self::t('belep_cim'), 'rma--zar', static function () use ($munkalap, $elonezet): void {
            $minta = SDH_Muhely_Munkalap::szam_formaz(1234);

            ?>
            <div class="rma-ablak rma-ablak--kicsi">
                <?php self::fejlec(null, false, $elonezet); ?>
                <div class="rma-zar">
                    <div class="rma-zar__ikon"><?php echo self::ikon('lakat'); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
                    <h1><?php echo esc_html(self::t('belep_cim')); ?></h1>
                    <p><?php echo esc_html(self::t('belep_szoveg')); ?></p>

                    <?php echo self::visszajelzes(); // phpcs:ignore WordPress.Security.EscapeOutput ?>

                    <form method="post" class="rma-urlap" autocomplete="on">
                        <input type="hidden" name="sdh_rma_muvelet" value="belep">
                        <label class="rma-mezo">
                            <span><?php echo esc_html(self::t('telefon')); ?></span>
                            <input type="tel" name="telefon" inputmode="tel" autocomplete="tel" placeholder="06301234567" required>
                        </label>
                        <label class="rma-mezo">
                            <span><?php echo esc_html(self::t('sorszam')); ?></span>
                            <input type="text" name="sorszam" inputmode="numeric" autocomplete="off"
                                   placeholder="<?php echo esc_attr(self::t('pelda') . ' ' . $minta); ?>" required>
                        </label>
                        <button type="submit" class="sdh-gomb sdh-gomb--elsodleges rma-gomb-nagy"><?php echo esc_html(self::t('megnyitas')); ?></button>
                    </form>

                    <?php echo self::gyorsgombok(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                </div>
            </div>
            <?php
        });
    }

    /** A feloldott munkalap-ablak. */
    public static function rma_oldal(object $munkalap, ?object $ugyfel, bool $elonezet = false): void
    {
        $cim = self::t('munkalap') . ' ' . SDH_Muhely_Munkalap::szam_formaz($munkalap->munkalap_szam);

        self::keret($cim, 'rma--lap', static function () use ($munkalap, $ugyfel, $elonezet): void {
            $id        = (int) $munkalap->id;
            $allapotok = SDH_Muhely_Munkalap::allapotok();
            $kulcs     = (string) $munkalap->allapot;
            $allapot   = $allapotok[$kulcs] ?? ['nev' => $kulcs, 'szin' => 'szurke'];
            $naplo     = SDH_Muhely_Rma::naplo($id);
            $utolso    = $naplo !== [] ? (string) $naplo[0]->letrehozva : (string) $munkalap->modositva;
            $eszkoz    = SDH_Muhely_Rma::eszkoz((int) $munkalap->eszkoz_id);
            $hibak     = SDH_Muhely_Munkalap::hibasorok($id);
            $tetelek   = SDH_Muhely_Tetel::lista($id);
            $uzenetek  = SDH_Muhely_Rma::uzenetek($id);

            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $alap_ful = isset($_GET['ok']) || in_array($_GET['h'] ?? '', ['ures', 'sokuzenet', 'lejart'], true) ? 'uzenetek' : '';

            ?>
            <div class="rma-ablak">
                <?php self::fejlec($munkalap, true, $elonezet); ?>
                <?php echo self::visszajelzes(); // phpcs:ignore WordPress.Security.EscapeOutput ?>

                <div class="rma-test" data-rma-test data-rma-alap="<?php echo esc_attr($alap_ful); ?>">
                    <nav class="rma-fulek" role="tablist">
                        <?php
                        $fulek = [
                            'osszegzes'   => [self::t('f_osszegzes'), 0],
                            'eszkoz'      => [self::t('f_eszkoz'), 0],
                            'hibak'       => [self::t('f_hibak'), count($hibak)],
                            'tetelek'     => [self::t('f_tetelek'), count($tetelek)],
                            'tortenet'    => [self::t('f_tortenet'), count($naplo)],
                            'uzenetek'    => [self::t('f_uzenetek'), count($uzenetek)],
                            'elerhetoseg' => [self::t('f_elerhetoseg'), 0],
                        ];

                        foreach ($fulek as $k => [$felirat, $db]) {
                            printf(
                                '<button type="button" role="tab" class="rma-ful%s" data-rma-ful="%s" id="rma-ful-%s" aria-controls="rma-panel-%s">%s<span>%s</span>%s</button>',
                                $k === 'osszegzes' ? ' rma-csak-mobil' : '',
                                esc_attr($k),
                                esc_attr($k),
                                esc_attr($k),
                                self::ikon($k), // phpcs:ignore WordPress.Security.EscapeOutput
                                esc_html($felirat),
                                $db > 0 ? '<span class="sdh-fulek__db">' . (int) $db . '</span>' : ''
                            );
                        }
                        ?>
                    </nav>

                    <aside class="rma-oldal rma-panel" data-rma-panel="osszegzes" id="rma-panel-osszegzes" role="tabpanel">
                        <?php self::oldalsav($munkalap, $allapot, $kulcs, $utolso); ?>
                    </aside>

                    <div class="rma-panelek">
                        <section class="rma-panel" data-rma-panel="eszkoz" id="rma-panel-eszkoz" role="tabpanel">
                            <?php self::panel_eszkoz($munkalap, $ugyfel, $eszkoz); ?>
                        </section>
                        <section class="rma-panel" data-rma-panel="hibak" id="rma-panel-hibak" role="tabpanel">
                            <?php self::panel_hibak($hibak); ?>
                        </section>
                        <section class="rma-panel" data-rma-panel="tetelek" id="rma-panel-tetelek" role="tabpanel">
                            <?php self::panel_tetelek($tetelek, $munkalap); ?>
                        </section>
                        <section class="rma-panel" data-rma-panel="tortenet" id="rma-panel-tortenet" role="tabpanel">
                            <?php self::panel_tortenet($naplo, $allapotok); ?>
                        </section>
                        <section class="rma-panel rma-panel--uzenetek" data-rma-panel="uzenetek" id="rma-panel-uzenetek" role="tabpanel">
                            <?php self::panel_uzenetek($munkalap, $uzenetek, $elonezet); ?>
                        </section>
                        <section class="rma-panel" data-rma-panel="elerhetoseg" id="rma-panel-elerhetoseg" role="tabpanel">
                            <?php self::panel_elerhetoseg(); ?>
                        </section>
                    </div>
                </div>
            </div>
            <?php
        });
    }

    /* =================================================================
     * Az ablak részei
     * ============================================================== */

    /** Bal oldalsáv: állapot, dátumok, összeg, gyorsgombok. */
    private static function oldalsav(object $munkalap, array $allapot, string $kulcs, string $utolso): void
    {
        $brutto    = (float) $munkalap->brutto_ertek;
        $fizetett  = (float) $munkalap->fizetett;
        $fizetve   = (int) $munkalap->fizetve === 1;
        $fizetendo = $fizetve ? 0.0 : $brutto - $fizetett;

        $sor = static function (string $cimke, string $ertek, string $osztaly = ''): void {
            if ($ertek === '') {
                return;
            }

            printf(
                '<div class="rma-sor%s"><span>%s</span><b>%s</b></div>',
                $osztaly !== '' ? ' ' . esc_attr($osztaly) : '',
                esc_html($cimke),
                esc_html($ertek)
            );
        };

        ?>
        <div class="rma-csoport">
            <h3 class="sdh-ml__cim"><?php echo esc_html(self::t('allapot')); ?></h3>
            <div class="rma-allapot sdh-allapot sdh-allapot--<?php echo esc_attr((string) $allapot['szin']); ?>"><?php echo esc_html(self::allapot_nev($kulcs, $allapot)); ?></div>
            <div class="rma-halvany"><?php echo esc_html(self::t('utoljara') . ': ' . self::ido($utolso)); ?></div>
        </div>

        <div class="rma-csoport">
            <h3 class="sdh-ml__cim"><?php echo esc_html(self::t('datumok')); ?></h3>
            <?php
            $sor(self::t('atvetel'), self::ido((string) $munkalap->keszult, false));
            $sor(self::t('hatarido'), self::ido((string) $munkalap->hatarido, false));
            $sor(self::t('lezarva'), self::ido((string) $munkalap->lezarva, false));
            ?>
        </div>

        <div class="rma-csoport">
            <h3 class="sdh-ml__cim"><?php echo esc_html(self::t('osszeg')); ?></h3>
            <?php if ($brutto <= 0 && $fizetett <= 0) : ?>
                <div class="rma-halvany"><?php echo esc_html(self::t('nincs_osszeg')); ?></div>
            <?php else : ?>
                <?php
                $sor(self::t('brutto'), self::penz($brutto), 'rma-sor--brutto');

                if ($fizetett > 0) {
                    $sor(self::t('eloleg'), self::penz($fizetett));
                }
                ?>
                <?php if ($fizetendo > 0) : ?>
                    <div class="rma-fizetendo">
                        <span><?php echo esc_html(self::t('fizetendo')); ?></span>
                        <b><?php echo esc_html(self::penz($fizetendo)); ?></b>
                    </div>
                <?php else : ?>
                    <div class="rma-kifizetve"><?php echo esc_html($fizetve ? self::t('kifizetve') . ($munkalap->fizetes_ideje ? ' · ' . self::ido((string) $munkalap->fizetes_ideje, false) : '') : self::t('nincs_fizetendo')); ?></div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <?php echo self::gyorsgombok(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
        <?php
    }

    private static function adat(string $cimke, string $ertek): void
    {
        if (trim($ertek) === '') {
            return;
        }

        printf('<div class="rma-adat__sor"><dt>%s</dt><dd>%s</dd></div>', esc_html($cimke), nl2br(esc_html($ertek)));
    }

    private static function panel_eszkoz(object $munkalap, ?object $ugyfel, ?object $eszkoz): void
    {
        echo '<dl class="rma-adat">';

        if ($ugyfel) {
            self::adat(self::t('ugyfel'), (string) $ugyfel->nev);
        }

        if ($eszkoz) {
            self::adat(self::t('eszkoz'), self::kategoria((string) $eszkoz->kategoria) . ' – ' . SDH_Muhely_Eszkoz::megnevezes($eszkoz));

            $azon = (string) ($eszkoz->imei !== '' ? $eszkoz->imei : $eszkoz->sorozatszam);

            if ($azon !== '') {
                // Csak a vége látszik – a címke elvesztése esetén se legyen teljes azonosító.
                self::adat(
                    $eszkoz->imei !== '' ? 'IMEI' : self::t('sorozatszam'),
                    strlen($azon) > 5 ? str_repeat('•', strlen($azon) - 5) . substr($azon, -5) : $azon
                );
            }

            self::adat(self::t('szin'), (string) ($eszkoz->szin ?? ''));
            self::adat(self::t('tartozekok'), (string) ($eszkoz->tartozekok ?? ''));
            self::adat(self::t('atveteli'), (string) ($eszkoz->atveteli_allapot ?? ''));
        }

        echo '</dl>';

        $megj = trim((string) ($munkalap->ugyfel_megjegyzes ?? ''));

        if ($megj !== '') {
            printf(
                '<div class="rma-megj"><h3>%s</h3><p>%s</p></div>',
                esc_html(self::t('megj_szerviz')),
                nl2br(esc_html($megj))
            );
        }
    }

    /**
     * @param array<int, object> $hibak
     */
    private static function panel_hibak(array $hibak): void
    {
        if ($hibak === []) {
            echo '<p class="rma-ures">' . esc_html(self::t('nincs_hiba')) . '</p>';

            return;
        }

        $allapotok = SDH_Muhely_Munkalap::hiba_allapotok();

        echo '<ul class="rma-lista">';

        foreach ($hibak as $hiba) {
            $k  = (string) $hiba->allapot;
            $ha = $allapotok[$k] ?? null;

            printf(
                '<li><span class="rma-lista__fo">%s</span>%s</li>',
                esc_html((string) $hiba->leiras),
                $ha ? '<span class="sdh-allapot sdh-allapot--' . esc_attr((string) $ha['szin']) . '">' . esc_html(self::hiba_allapot_nev($k, $ha)) . '</span>' : ''
            );
        }

        echo '</ul>';
    }

    /**
     * @param array<int, object> $tetelek
     */
    private static function panel_tetelek(array $tetelek, object $munkalap): void
    {
        if ($tetelek === []) {
            echo '<p class="rma-ures">' . esc_html(self::t('nincs_tetel')) . '</p>';

            return;
        }

        $ossz = 0.0;

        echo '<table class="rma-tabla"><thead><tr><th>' . esc_html(self::t('megnevezes')) . '</th><th class="j">'
            . esc_html(self::t('menny')) . '</th><th class="j">' . esc_html(self::t('brutto')) . '</th></tr></thead><tbody>';

        foreach ($tetelek as $t) {
            $ossz += (float) $t->brutto_ertek;
            $menny = rtrim(rtrim(number_format((float) $t->mennyiseg, 3, self::$nyelv === 'en' ? '.' : ',', ''), '0'), ',.');

            printf(
                '<tr><td>%s<small>%s</small></td><td class="j">%s %s</td><td class="j">%s</td></tr>',
                esc_html((string) $t->megnevezes),
                esc_html(self::t($t->tipus === 'termek' ? 'termek' : 'szolgaltatas')),
                esc_html($menny),
                esc_html(self::me((string) $t->me)),
                esc_html(self::penz((float) $t->brutto_ertek))
            );
        }

        printf(
            '</tbody><tfoot><tr><td colspan="2">%s</td><td class="j rma-brutto">%s</td></tr></tfoot></table>',
            esc_html(self::t('osszesen')),
            esc_html(self::penz($ossz))
        );
    }

    /**
     * @param array<int, object> $naplo
     */
    private static function panel_tortenet(array $naplo, array $allapotok): void
    {
        if ($naplo === []) {
            echo '<p class="rma-ures">' . esc_html(self::t('nincs_tortenet')) . '</p>';

            return;
        }

        echo '<ol class="rma-ido">';

        foreach ($naplo as $i => $sor) {
            $k = (string) $sor->allapot;
            $a = $allapotok[$k] ?? ['nev' => $k, 'szin' => 'szurke'];

            printf(
                '<li class="sdh-allapot--%s%s"><span class="rma-ido__pont"></span><b>%s</b><span class="rma-halvany">%s</span></li>',
                esc_attr((string) $a['szin']),
                $i === 0 ? ' is-friss' : '',
                esc_html(self::allapot_nev($k, $a)),
                esc_html(self::ido((string) $sor->letrehozva))
            );
        }

        echo '</ol>';
    }

    /**
     * @param array<int, object> $uzenetek
     */
    private static function panel_uzenetek(object $munkalap, array $uzenetek, bool $elonezet): void
    {
        $b = SDH_Muhely_Rma::beallitas();

        echo '<div class="rma-uz-szal" data-rma-szal>';

        if ($uzenetek === []) {
            echo '<p class="rma-ures">' . esc_html(self::t('uz_ures')) . '</p>';
        }

        foreach ($uzenetek as $u) {
            $sajat = $u->irany === 'be';

            printf(
                '<div class="rma-uz%s"><div class="rma-uz__fej"><b>%s</b> <span>%s</span></div><div class="rma-uz__szoveg">%s</div></div>',
                $sajat ? ' rma-uz--sajat' : '',
                esc_html($sajat ? self::t('on') : (string) $b['szerviz_nev']),
                esc_html(self::ido((string) $u->letrehozva)),
                nl2br(esc_html((string) $u->szoveg))
            );
        }

        echo '</div>';

        ?>
        <form method="post" class="rma-uz-urlap" data-rma-uz-urlap>
            <input type="hidden" name="sdh_rma_muvelet" value="valasz">
            <input type="hidden" name="jel" value="<?php echo esc_attr(SDH_Muhely_Rma::urlap_jel($munkalap)); ?>">
            <?php if ($elonezet) : ?>
                <input type="hidden" name="elonezet" value="1">
            <?php endif; ?>
            <textarea name="szoveg" rows="2" maxlength="<?php echo (int) SDH_Muhely_Rma::UZENET_HOSSZ; ?>" required
                      placeholder="<?php echo esc_attr(self::t('uz_mezo')); ?>"
                      aria-label="<?php echo esc_attr(self::t('uz_mezo')); ?>"></textarea>
            <button type="submit" class="sdh-gomb sdh-gomb--elsodleges" title="<?php echo esc_attr(self::t('ctrl_enter')); ?>">
                <?php echo self::ikon('kuld'); // phpcs:ignore WordPress.Security.EscapeOutput ?><span><?php echo esc_html(self::t('kuldes')); ?></span>
            </button>
        </form>
        <?php
    }

    private static function panel_elerhetoseg(): void
    {
        $e     = self::elerhetoseg();
        $b     = SDH_Muhely_Rma::beallitas();
        $van   = false;

        echo '<div class="rma-kartyak">';

        $kartya = static function (string $ikon, string $cim, string $ertek_html, string $gomb = '') use (&$van): void {
            $van = true;

            printf(
                '<div class="rma-kartya"><div class="rma-kartya__ikon">%s</div><div class="rma-kartya__test"><h3>%s</h3><div>%s</div>%s</div></div>',
                self::ikon($ikon), // phpcs:ignore WordPress.Security.EscapeOutput
                esc_html($cim),
                $ertek_html, // phpcs:ignore WordPress.Security.EscapeOutput
                $gomb // phpcs:ignore WordPress.Security.EscapeOutput
            );
        };

        if ($e['cim'] !== '') {
            $kartya('elerhetoseg', self::t('cim'), '<b>' . esc_html((string) $b['szerviz_nev']) . '</b><br>' . esc_html($e['cim']),
                $e['terkep'] !== '' ? '<a class="sdh-gomb" href="' . esc_url($e['terkep']) . '" target="_blank" rel="noopener">' . esc_html(self::t('utvonal')) . '</a>' : '');
        }

        if ($e['telefon'] !== '') {
            $kartya('telefon', self::t('telefon'), esc_html($e['telefon']),
                '<a class="sdh-gomb sdh-gomb--elsodleges" href="' . esc_url($e['tel_link'], ['tel']) . '">' . esc_html(self::t('hivas')) . '</a>');
        }

        if ($e['email'] !== '') {
            $kartya('level', self::t('email'), esc_html($e['email']),
                '<a class="sdh-gomb" href="' . esc_url('mailto:' . $e['email'], ['mailto']) . '">' . esc_html(self::t('iras')) . '</a>');
        }

        if ($e['nyitvatartas'] !== '') {
            $kartya('ora', self::t('nyitvatartas'), nl2br(esc_html($e['nyitvatartas'])));
        }

        if ($e['weboldal'] !== '') {
            $kartya('web', self::t('weboldal'), '<a href="' . esc_url($e['weboldal']) . '" target="_blank" rel="noopener">' . esc_html(preg_replace('#^https?://(www\.)?#', '', rtrim($e['weboldal'], '/'))) . '</a>');
        }

        if ($e['egyeb'] !== '') {
            $kartya('info', self::t('egyeb'), nl2br(esc_html($e['egyeb'])));
        }

        echo '</div>';

        if (!$van) {
            echo '<p class="rma-ures">' . esc_html(self::t('nincs_elerhetoseg')) . '</p>';
        }
    }
}
