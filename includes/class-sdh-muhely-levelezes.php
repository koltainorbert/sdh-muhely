<?php
/**
 * Levelezés – a szerviz e-mail-fiókjai a CRM-ben.
 *
 * A fiókok (Gmail) IMAP-on és SMTP-n keresztül, alkalmazásjelszóval
 * kapcsolódnak; a levelek a szerveren maradnak, a CRM csak a fejadatok
 * gyorsítótárát tartja (táblák: sdh_level, sdh_level_mappa). Amit itt
 * csinálsz (olvasás, törlés, áthelyezés, küldés), az a valódi postafiókban
 * történik, és a telefonon, a Gmail felületén is úgy látszik.
 *
 *  - Minden mappa (Gmail-címke) látszik; a szerepüket (Elküldött, Kuka…) a
 *    szerver SPECIAL-USE jelzőiből tudjuk, nem a (fiók nyelvétől függő) névből.
 *  - Egy fiók külön CRM-jelszóval zárható: amíg valaki be nem írja, annak a
 *    fióknak semmilyen adata nem megy ki a böngészőbe (a szerver ellenőrzi).
 *  - Az alkalmazásjelszó titkosítva áll az adatbázisban, és soha nem kerül
 *    vissza a HTML-be.
 *  - A beérkező leveleket a fontos-levél ügynök besorolja
 *    (SDH_Muhely_Level_Ugynok) – az csak jelez, a postafiókhoz nem nyúl.
 *
 * @package SDH_Muhely
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDH_Muhely_Levelezes
{
    public const KULCS = 'levelezes';

    private const OPTION = 'sdh_muhely_levelezes';

    /** Ennyi fiók köthető be. */
    private const FIOK_MAX = 3;

    /** Egy oldalon ennyi levél. */
    private const OLDAL = 25;

    /** Ekkora ablakban (a legújabb levelek) követi a máshol történt változásokat (olvasás, csillag, törlés). */
    private const ABLAK = 100;

    /** Az első szinkron ennyi (legújabb) levelet tölt le – két oldalnyit; a többi lapozáskor jön. */
    private const ELSO = 50;

    /** Régebbi levelek lapozásakor egyszerre ennyit kér le. */
    private const ADAG = 50;

    /** A levéllistához levelenként letöltött adatok: fejléc + a törzs eleje (kivonat, ügynök). */
    // Nem a teljes fejléc (a Gmailé levelenként több kilobájt), csak ami a listához és az ügynöknek kell.
    private const ELEMEK = 'UID FLAGS INTERNALDATE RFC822.SIZE'
        . ' BODY.PEEK[HEADER.FIELDS (FROM TO CC SUBJECT DATE MESSAGE-ID REPLY-TO IN-REPLY-TO CONTENT-TYPE CONTENT-TRANSFER-ENCODING LIST-UNSUBSCRIBE LIST-ID PRECEDENCE AUTO-SUBMITTED)]'
        . ' BODY.PEEK[TEXT]<0.4096>';

    /** A levelek törzsének titkosított gyorsítótára (uploads alatt). */
    private const TAR_MAPPA = 'sdh-muhely-levelek';

    /** Ennél nagyobb levél nem kerül a gyorsítótárba (mindig a szerverről jön). */
    private const TAR_MAX = 8 * 1024 * 1024;

    /** Előtöltés: ennél kisebb leveleket tölt le előre a megnyitott oldalról, kérésenként legfeljebb ennyi bájtot. */
    private const ELORE_LEVEL = 300 * 1024;
    private const ELORE_OSSZ  = 3 * 1024 * 1024;

    /** A gyorsítótárban ennyi napig marad egy levél. */
    private const TAR_NAP = 30;

    /** Ennél nagyobb összes csatolmányt nem küldünk (a Gmail korlátja 25 MB). */
    private const CSATOLMANY_MAX = 24 * 1024 * 1024;

    /** A szerepek magyar neve és sorrendje a mappalistában. */
    private const SZEREPEK = [
        'inbox'     => 'Beérkezett',
        'flagged'   => 'Csillagozott',
        'important' => 'Fontos',
        'sent'      => 'Elküldött',
        'drafts'    => 'Piszkozatok',
        'all'       => 'Összes levél',
        'junk'      => 'Spam',
        'trash'     => 'Kuka',
    ];

    public static function init(): void
    {
        $muveletek = [
            'allapot'    => 'ajax_allapot',
            'mappak'     => 'ajax_mappak',
            'lista'      => 'ajax_lista',
            'olvas'      => 'ajax_olvas',
            'csatolmany' => 'ajax_csatolmany',
            'muvelet'    => 'ajax_muvelet',
            'urlap'      => 'ajax_urlap',
            'kuld'       => 'ajax_kuld',
            'olvasva'    => 'ajax_olvasva',
            'nyit'       => 'ajax_nyit',
            'zar'        => 'ajax_zar',
            'mappa'      => 'ajax_mappa',
            'elintezve'  => 'ajax_elintezve',
            'teszt'      => 'ajax_teszt',
            'rendezo_terv'       => 'ajax_rendezo_terv',
            'rendezo_vegrehajt'  => 'ajax_rendezo_vegrehajt',
        ];

        // A rendező ügynök ablaka (az app.js popupja „sdh_muhely_<modul>_urlap" néven kéri).
        add_action('wp_ajax_sdh_muhely_levelrendezo_urlap', [self::class, 'ajax_rendezo_urlap']);

        foreach ($muveletek as $nev => $fuggveny) {
            add_action('wp_ajax_sdh_muhely_level_' . $nev, [self::class, $fuggveny]);
        }

        add_action('admin_enqueue_scripts', [self::class, 'admin_eszkozok'], 20);

        SDH_Muhely_Modulok::regisztral([
            'kulcs'   => self::KULCS,
            'cim'     => 'Levelezés',
            'render'  => [self::class, 'oldal'],
            'sorrend' => 33,
            'jelveny' => [self::class, 'olvasatlan_db'],
        ]);
    }

    private static function tabla(): string
    {
        return SDH_Muhely_Schema::tabla('level');
    }

    private static function mappa_tabla(): string
    {
        return SDH_Muhely_Schema::tabla('level_mappa');
    }

    private static function konyvtarak(): void
    {
        require_once SDH_MUHELY_DIR . 'includes/lib/sdh-imap.php';
        require_once SDH_MUHELY_DIR . 'includes/lib/sdh-mime.php';
        require_once SDH_MUHELY_DIR . 'includes/class-sdh-muhely-level-ugynok.php';
        require_once SDH_MUHELY_DIR . 'includes/class-sdh-muhely-level-rendezo.php';
    }

    /* =================================================================
     * Beállítások
     * ============================================================== */

    /** @return array<string, mixed> */
    private static function alap_fiok(): array
    {
        return [
            'nev' => '', 'email' => '', 'felhasznalo' => '', 'jelszo' => '',
            'imap_host' => 'imap.gmail.com', 'imap_port' => 993, 'imap_titk' => 'ssl',
            'smtp_host' => 'smtp.gmail.com', 'smtp_port' => 465, 'smtp_titk' => 'ssl',
            'alairas' => '', 'zar' => '', 'aktiv' => true,
        ];
    }

    /**
     * @return array{fiokok: array<string, array<string, mixed>>, ugynok: array<string, mixed>, frissites_mp: int, hang: bool, zar_perc: int, rendezo: array<string, mixed>}
     */
    public static function beallitas(): array
    {
        $alap = [
            'fiokok'       => [],
            'ugynok'       => ['be' => true, 'vip' => '', 'zaj' => '', 'partner' => "apple.com\nsamsung.com", 'szavak' => '', 'ai' => false, 'ai_kulcs' => '', 'ai_modell' => 'claude-haiku-4-5-20251001'],
            'frissites_mp' => 60,
            'hang'         => true,
            'zar_perc'     => 480,
            'rendezo'      => [],
        ];

        require_once SDH_MUHELY_DIR . 'includes/class-sdh-muhely-level-rendezo.php';
        $alap['rendezo'] = SDH_Muhely_Level_Rendezo::alap_jogok();

        for ($i = 1; $i <= self::FIOK_MAX; $i++) {
            $alap['fiokok']['f' . $i] = self::alap_fiok();
        }

        // A fiókok kezdőértékei adatfájlból jönnek (nem a kódból), és a Beállításokban átírhatók.
        $fajl  = SDH_MUHELY_DIR . 'data/levelezes.json';
        $kezdo = is_readable($fajl) ? json_decode((string) file_get_contents($fajl), true) : [];

        foreach (is_array($kezdo['fiokok'] ?? null) ? $kezdo['fiokok'] : [] as $k => $f) {
            if (isset($alap['fiokok'][$k]) && is_array($f)) {
                $alap['fiokok'][$k] = array_merge($alap['fiokok'][$k], array_intersect_key($f, ['nev' => 1, 'email' => 1]));
            }
        }

        $mentett = get_option(self::OPTION, []);
        $mentett = is_array($mentett) ? $mentett : [];

        foreach ($alap['fiokok'] as $k => $f) {
            if (is_array($mentett['fiokok'][$k] ?? null)) {
                $alap['fiokok'][$k] = array_merge($f, array_intersect_key($mentett['fiokok'][$k], $f));
            }
        }

        if (is_array($mentett['ugynok'] ?? null)) {
            $alap['ugynok'] = array_merge($alap['ugynok'], array_intersect_key($mentett['ugynok'], $alap['ugynok']));
        }

        foreach (['frissites_mp', 'hang', 'zar_perc'] as $k) {
            if (isset($mentett[$k])) {
                $alap[$k] = $mentett[$k];
            }
        }

        if (is_array($mentett['rendezo'] ?? null)) {
            $alap['rendezo'] = array_merge($alap['rendezo'], array_intersect_key($mentett['rendezo'], $alap['rendezo']));
        }

        $alap['rendezo']['fiokok'] = is_array($alap['rendezo']['fiokok']) ? $alap['rendezo']['fiokok'] : [];

        return $alap;
    }

    /**
     * A használható fiókok (van címük és jelszavuk, be vannak kapcsolva).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function fiokok(): array
    {
        return array_filter(
            self::beallitas()['fiokok'],
            static fn (array $f): bool => !empty($f['aktiv']) && trim((string) $f['email']) !== '' && (string) $f['jelszo'] !== ''
        );
    }

    private static function fiok_nev(array $fiok): string
    {
        return trim((string) $fiok['nev']) !== '' ? (string) $fiok['nev'] : (string) $fiok['email'];
    }

    /* ---- Titkosítás: az alkalmazásjelszó és az AI-kulcs nem áll nyíltan az adatbázisban ---- */

    private static function titok_kulcs(): string
    {
        return sodium_crypto_generichash('sdh-muhely-levelezes|' . wp_salt('auth'), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    private static function titkosit(string $nyilt): string
    {
        if ($nyilt === '') {
            return '';
        }

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return 'v1:' . base64_encode($nonce . sodium_crypto_secretbox($nyilt, $nonce, self::titok_kulcs()));
    }

    private static function visszafejt(string $titkos): string
    {
        if (strncmp($titkos, 'v1:', 3) !== 0) {
            return '';
        }

        $nyers = base64_decode(substr($titkos, 3), true);

        if ($nyers === false || strlen($nyers) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return '';
        }

        $nyilt = sodium_crypto_secretbox_open(
            substr($nyers, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($nyers, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            self::titok_kulcs()
        );

        return $nyilt === false ? '' : $nyilt;
    }

    /** Helyi (teszt) szerver-e: csak ott engedünk titkosítatlan kapcsolatot. */
    private static function helyi_host(string $host): bool
    {
        return in_array(strtolower(trim($host)), ['127.0.0.1', 'localhost', '::1'], true);
    }

    private static function gmail(array $fiok): bool
    {
        return preg_match('/(^|\.)(gmail|googlemail)\.com$/i', (string) $fiok['imap_host']) === 1;
    }

    /** A Beállítások oldal doboza. Jelszó és kulcs soha nem kerül vissza a HTML-be. */
    public static function beallitas_doboz(): void
    {
        $b = self::beallitas();
        $u = $b['ugynok'];

        ?>
        <div class="sdh-doboz" id="levelezes">
            <h2 class="sdh-doboz__cim">Levelezés – e-mail-fiókok</h2>

            <p class="sdh-sugo">
                A CRM a fiókokhoz IMAP-on és SMTP-n kapcsolódik, <strong>alkalmazásjelszóval</strong> – a Google-fiók
                rendes jelszavát ide soha ne írd be. Alkalmazásjelszó: Google-fiók → <em>Biztonság</em> →
                <em>Kétlépcsős azonosítás</em> (be kell kapcsolni) → <em>Alkalmazásjelszavak</em>
                (<code>myaccount.google.com/apppasswords</code>) → új jelszó „SDH Műhely" néven → a kapott 16 betűt másold ide.
                A levelek a Gmailben maradnak: amit a CRM-ben törölsz vagy áthelyezel, az a telefonon is úgy lesz.
            </p>

            <?php foreach ($b['fiokok'] as $k => $f) : ?>
                <?php $van = (string) $f['jelszo'] !== ''; ?>
                <h3 class="sdh-doboz__alcim"><?php echo esc_html(substr((string) $k, 1) . '. fiók' . (trim((string) $f['email']) !== '' ? ' – ' . $f['email'] : '')); ?></h3>

                <div class="sdh-mezok">
                    <div class="sdh-mezo">
                        <label for="level_<?php echo esc_attr($k); ?>_email">E-mail-cím</label>
                        <input type="email" name="level[<?php echo esc_attr($k); ?>][email]" id="level_<?php echo esc_attr($k); ?>_email" maxlength="190"
                               value="<?php echo esc_attr((string) $f['email']); ?>" autocomplete="off">
                    </div>

                    <div class="sdh-mezo">
                        <label for="level_<?php echo esc_attr($k); ?>_nev">Megjelenő név (feladóként is)</label>
                        <input type="text" name="level[<?php echo esc_attr($k); ?>][nev]" id="level_<?php echo esc_attr($k); ?>_nev" maxlength="80"
                               value="<?php echo esc_attr((string) $f['nev']); ?>">
                    </div>

                    <div class="sdh-mezo">
                        <label for="level_<?php echo esc_attr($k); ?>_jelszo">Alkalmazásjelszó</label>
                        <input type="password" name="level[<?php echo esc_attr($k); ?>][jelszo]" id="level_<?php echo esc_attr($k); ?>_jelszo" autocomplete="new-password"
                               placeholder="<?php echo esc_attr($van ? '•••••••• (mentve – csak akkor írd be, ha cserélnéd)' : '16 betű a Google-től'); ?>">
                        <span class="sdh-mezo__sugo sdh-szamla-kapcsolat">
                            <?php if ($van) : ?>
                                <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-level-teszt="<?php echo esc_attr($k); ?>">Kapcsolat ellenőrzése</button>
                                <label class="sdh-jelolo"><input type="checkbox" name="level[<?php echo esc_attr($k); ?>][jelszo_torol]" value="1"> Jelszó törlése</label>
                                <span data-sdh-level-teszt-ki aria-live="polite"></span>
                            <?php else : ?>
                                Mentés után itt ellenőrizhető a kapcsolat.
                            <?php endif; ?>
                        </span>
                    </div>

                    <div class="sdh-mezo">
                        <label for="level_<?php echo esc_attr($k); ?>_zar">Külön jelszó a CRM-ben (nem kötelező)</label>
                        <input type="password" name="level[<?php echo esc_attr($k); ?>][zar]" id="level_<?php echo esc_attr($k); ?>_zar" autocomplete="new-password"
                               placeholder="<?php echo esc_attr((string) $f['zar'] !== '' ? '•••••••• (be van állítva)' : 'Üresen: mindenki látja, aki a CRM-et használja'); ?>">
                        <span class="sdh-mezo__sugo">
                            Ha megadod, ezt a postafiókot a CRM-ben csak az nyithatja meg, aki beírja ezt a jelszót.
                            <?php if ((string) $f['zar'] !== '') : ?>
                                <label class="sdh-jelolo"><input type="checkbox" name="level[<?php echo esc_attr($k); ?>][zar_torol]" value="1"> Jelszavas védelem kikapcsolása</label>
                            <?php endif; ?>
                        </span>
                    </div>

                    <div class="sdh-mezo sdh-mezo--szeles">
                        <label for="level_<?php echo esc_attr($k); ?>_alairas">Aláírás az új levelek végén</label>
                        <textarea name="level[<?php echo esc_attr($k); ?>][alairas]" id="level_<?php echo esc_attr($k); ?>_alairas" rows="3" maxlength="1000"><?php echo esc_textarea((string) $f['alairas']); ?></textarea>
                    </div>

                    <div class="sdh-mezo sdh-mezo--jelolo sdh-mezo--szeles">
                        <input type="checkbox" name="level[<?php echo esc_attr($k); ?>][aktiv]" id="level_<?php echo esc_attr($k); ?>_aktiv" value="1" <?php checked(!empty($f['aktiv'])); ?>>
                        <label for="level_<?php echo esc_attr($k); ?>_aktiv">A fiók be van kapcsolva.</label>
                    </div>

                    <details class="sdh-mezo sdh-mezo--szeles">
                        <summary>Szerver (Gmailnél nem kell átírni)</summary>
                        <div class="sdh-mezok">
                            <div class="sdh-mezo">
                                <label for="level_<?php echo esc_attr($k); ?>_felhasznalo">Felhasználónév (üresen az e-mail-cím)</label>
                                <input type="text" name="level[<?php echo esc_attr($k); ?>][felhasznalo]" id="level_<?php echo esc_attr($k); ?>_felhasznalo" maxlength="190"
                                       value="<?php echo esc_attr((string) $f['felhasznalo']); ?>" autocomplete="off">
                            </div>
                            <?php foreach (['imap' => 'Bejövő (IMAP)', 'smtp' => 'Kimenő (SMTP)'] as $p => $cimke) : ?>
                                <div class="sdh-mezo">
                                    <label for="level_<?php echo esc_attr($k . '_' . $p); ?>_host"><?php echo esc_html($cimke); ?> szerver, port, titkosítás</label>
                                    <span class="sdh-level-szerver">
                                        <input type="text" name="level[<?php echo esc_attr($k); ?>][<?php echo esc_attr($p); ?>_host]" id="level_<?php echo esc_attr($k . '_' . $p); ?>_host" maxlength="120"
                                               value="<?php echo esc_attr((string) $f[$p . '_host']); ?>">
                                        <input type="number" name="level[<?php echo esc_attr($k); ?>][<?php echo esc_attr($p); ?>_port]" min="1" max="65535" aria-label="<?php echo esc_attr($cimke . ' port'); ?>"
                                               value="<?php echo (int) $f[$p . '_port']; ?>">
                                        <select name="level[<?php echo esc_attr($k); ?>][<?php echo esc_attr($p); ?>_titk]" aria-label="<?php echo esc_attr($cimke . ' titkosítás'); ?>">
                                            <?php foreach (['ssl' => 'SSL/TLS', 'tls' => 'STARTTLS', 'nincs' => 'nincs (csak helyi teszt)'] as $ertek => $felirat) : ?>
                                                <option value="<?php echo esc_attr($ertek); ?>" <?php selected((string) $f[$p . '_titk'], $ertek); ?>><?php echo esc_html($felirat); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </details>
                </div>
            <?php endforeach; ?>

            <h3 class="sdh-doboz__alcim">Fontos-levél ügynök</h3>

            <p class="sdh-sugo">
                Az ügynök minden beérkező levelet besorol: <strong>Azonnal</strong> (1 órán belül reagálni kell),
                <strong>Ma</strong>, <strong>Ráér</strong> vagy <strong>Zaj</strong> – és szól, ha azonnali teendő érkezett.
                <strong>Mást nem csinál:</strong> nem ír, nem válaszol, nem töröl, nem mozgat levelet, és nem is tud – a postafiókhoz nincs hozzáférése.
                Azonnali: hatósági vagy jogi ügy, fiókbiztonság, gyártói partner teendővel, fizetés vagy szolgáltatás leállása,
                ügyfélpanasz, a feladó által sürgősnek jelzett ügy, és amit lent kiemeltnek jelölsz.
            </p>

            <div class="sdh-mezok">
                <div class="sdh-mezo sdh-mezo--jelolo sdh-mezo--szeles">
                    <input type="checkbox" name="level_ugynok[be]" id="level_ugynok_be" value="1" <?php checked(!empty($u['be'])); ?>>
                    <label for="level_ugynok_be">Az ügynök be van kapcsolva.</label>
                </div>

                <?php
                $listak = [
                    'vip'     => ['Kiemelt feladók – mindig azonnali', 'Soronként egy cím vagy domain, pl. fonok@ceg.hu vagy nagyugyfel.hu'],
                    'partner' => ['Partnerek – teendőnél azonnali, egyébként aznapi', 'Soronként egy domain'],
                    'szavak'  => ['Figyelt kifejezések – azonnali, ha a levélben szerepelnek', 'Soronként egy kifejezés, pl. garanciális csere'],
                    'zaj'     => ['Zajlista – ezekről soha nem szól', 'Soronként egy cím vagy domain'],
                ];
                ?>
                <?php foreach ($listak as $nev => $szoveg) : ?>
                    <div class="sdh-mezo">
                        <label for="level_ugynok_<?php echo esc_attr($nev); ?>"><?php echo esc_html($szoveg[0]); ?></label>
                        <textarea name="level_ugynok[<?php echo esc_attr($nev); ?>]" id="level_ugynok_<?php echo esc_attr($nev); ?>" rows="3" maxlength="4000"
                                  placeholder="<?php echo esc_attr($szoveg[1]); ?>"><?php echo esc_textarea((string) $u[$nev]); ?></textarea>
                    </div>
                <?php endforeach; ?>

                <div class="sdh-mezo sdh-mezo--jelolo sdh-mezo--szeles">
                    <input type="checkbox" name="level_ugynok[ai]" id="level_ugynok_ai" value="1" <?php checked(!empty($u['ai'])); ?>>
                    <label for="level_ugynok_ai">Mesterséges intelligencia (Claude) is segítsen a besorolásban.</label>
                    <span class="sdh-mezo__sugo">
                        Bekapcsolva a beérkező levelek feladója, tárgya és szövegének eleje elemzésre az Anthropic szerverére megy
                        (ez adattovábbítás – az adatkezelési tájékoztatóban szerepelnie kell). Kikapcsolva csak a fenti szabályok
                        döntenek, a levelek nem hagyják el a szervert. Az AI is csak besorol; amit a szabályok azonnalinak ítélnek, azt nem minősítheti le.
                    </span>
                </div>

                <div class="sdh-mezo">
                    <label for="level_ugynok_ai_kulcs">Claude API-kulcs</label>
                    <input type="password" name="level_ugynok[ai_kulcs]" id="level_ugynok_ai_kulcs" autocomplete="new-password"
                           placeholder="<?php echo esc_attr((string) $u['ai_kulcs'] !== '' ? '•••••••• (mentve)' : 'sk-ant-…'); ?>">
                    <?php if ((string) $u['ai_kulcs'] !== '') : ?>
                        <span class="sdh-mezo__sugo"><label class="sdh-jelolo"><input type="checkbox" name="level_ugynok[ai_kulcs_torol]" value="1"> Kulcs törlése</label></span>
                    <?php endif; ?>
                </div>

                <div class="sdh-mezo">
                    <label for="level_ugynok_ai_modell">Modell</label>
                    <input type="text" name="level_ugynok[ai_modell]" id="level_ugynok_ai_modell" maxlength="80" value="<?php echo esc_attr((string) $u['ai_modell']); ?>">
                </div>

            </div>

            <h3 class="sdh-doboz__alcim">Rendező ügynök</h3>

            <p class="sdh-sugo">
                A Levelezés „Rendező ügynök" gombjával kérhetsz tőle rendrakást (pl. „A beszállítói számlákat tedd a Számlák mappába").
                <strong>Két dolgot tehet: leveleket áthelyez mappába, és – engedélykérés után – új mappát hoz létre. Semmi mást.</strong>
                Nem töröl, nem küld, nem válaszol. Hogy a kettőből mit szabad, azt az alábbi kapcsolók döntik el – csak itt, kézzel állíthatók;
                az ügynök a saját jogain nem változtathat. Szabad szavas kéréshez Claude API-kulcs kell (fent); anélkül szűrővel dolgozik
                (feladó / tárgy tartalmazza → mappa).
            </p>

            <?php $r = $b['rendezo']; ?>
            <div class="sdh-mezok">
                <?php
                $kapcsolok = [
                    'be'         => 'A rendező ügynök be van kapcsolva.',
                    'mozgathat'  => 'Leveleket áthelyezhet mappába.',
                    'mappat'     => 'Új mappát létrehozhat (minden mappa előtt engedélyt kér).',
                    'kukaba'     => 'A Kukába és a Spambe is tehet levelet. (Kikapcsolva oda soha nem mozgat.)',
                    'jovahagyas' => 'Mozgatás előtt mindig megmutatja a tervet, és jóváhagyást kér. (Kikapcsolva a mozgatást rögtön végrehajtja.)',
                ];
                ?>
                <?php foreach ($kapcsolok as $nev => $szoveg) : ?>
                    <div class="sdh-mezo sdh-mezo--jelolo sdh-mezo--szeles">
                        <input type="checkbox" name="level_rendezo[<?php echo esc_attr($nev); ?>]" id="level_rendezo_<?php echo esc_attr($nev); ?>" value="1" <?php checked(!empty($r[$nev])); ?>>
                        <label for="level_rendezo_<?php echo esc_attr($nev); ?>"><?php echo esc_html($szoveg); ?></label>
                    </div>
                <?php endforeach; ?>

                <div class="sdh-mezo">
                    <label for="level_rendezo_max">Egy kérésben legfeljebb ennyi levelet mozgathat</label>
                    <input type="number" name="level_rendezo[max]" id="level_rendezo_max" min="1" max="300" value="<?php echo (int) $r['max']; ?>">
                </div>

                <div class="sdh-mezo">
                    <label for="level_rendezo_forras">Honnan mozgathat</label>
                    <select name="level_rendezo[forras]" id="level_rendezo_forras">
                        <option value="barmely" <?php selected((string) $r['forras'], 'barmely'); ?>>Bármelyik megnyitott mappából</option>
                        <option value="inbox" <?php selected((string) $r['forras'], 'inbox'); ?>>Csak a Beérkezett mappából</option>
                    </select>
                </div>

                <div class="sdh-mezo sdh-mezo--szeles">
                    <span class="sdh-mezo__cimke">Mely fiókokban dolgozhat</span>
                    <?php foreach ($b['fiokok'] as $k => $f) : ?>
                        <?php if (trim((string) $f['email']) === '') { continue; } ?>
                        <label class="sdh-jelolo">
                            <input type="checkbox" name="level_rendezo[fiokok][<?php echo esc_attr((string) $k); ?>]" value="1" <?php checked(!empty($r['fiokok'][$k])); ?>>
                            <?php echo esc_html((string) $f['email']); ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <h3 class="sdh-doboz__alcim">Értesítések</h3>

            <div class="sdh-mezok">
                <div class="sdh-mezo">
                    <label for="level_frissites_mp">Új levelek keresése (másodpercenként)</label>
                    <input type="number" name="level_frissites_mp" id="level_frissites_mp" min="30" max="900" value="<?php echo (int) $b['frissites_mp']; ?>">
                </div>

                <div class="sdh-mezo">
                    <label for="level_zar_perc">A jelszóval megnyitott fiók ennyi perc után újra zár</label>
                    <input type="number" name="level_zar_perc" id="level_zar_perc" min="5" max="1440" value="<?php echo (int) $b['zar_perc']; ?>">
                </div>

                <div class="sdh-mezo sdh-mezo--jelolo sdh-mezo--szeles">
                    <input type="checkbox" name="level_hang" id="level_hang" value="1" <?php checked(!empty($b['hang'])); ?>>
                    <label for="level_hang">Hangjelzés új levélnél.</label>
                </div>
            </div>
        </div>
        <?php
    }

    public static function beallitas_mentes(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- a Beállítások oldal ellenőrizte.
        if (!isset($_POST['level']) || !is_array($_POST['level'])) {
            return;
        }

        $b    = self::beallitas();
        $kert = wp_unslash($_POST['level']);

        foreach ($b['fiokok'] as $k => $f) {
            $uj = is_array($kert[$k] ?? null) ? $kert[$k] : null;

            if ($uj === null) {
                continue;
            }

            $szoveg = static fn (string $n, int $h): string => isset($uj[$n]) && is_scalar($uj[$n]) ? mb_substr(sanitize_text_field((string) $uj[$n]), 0, $h) : '';

            $regi_email = (string) $f['email'];

            $f['email']       = is_email($szoveg('email', 190)) ? strtolower($szoveg('email', 190)) : '';
            $f['nev']         = $szoveg('nev', 80);
            $f['felhasznalo'] = $szoveg('felhasznalo', 190);
            $f['alairas']     = isset($uj['alairas']) && is_scalar($uj['alairas']) ? mb_substr(sanitize_textarea_field((string) $uj['alairas']), 0, 1000) : '';
            $f['aktiv']       = !empty($uj['aktiv']);

            foreach (['imap', 'smtp'] as $p) {
                $host = strtolower((string) preg_replace('/[^A-Za-z0-9.\-:]/', '', $szoveg($p . '_host', 120)));
                $titk = in_array($szoveg($p . '_titk', 5), ['ssl', 'tls', 'nincs'], true) ? $szoveg($p . '_titk', 5) : 'ssl';

                $f[$p . '_host'] = $host !== '' ? $host : self::alap_fiok()[$p . '_host'];
                $f[$p . '_port'] = min(65535, max(1, (int) ($uj[$p . '_port'] ?? self::alap_fiok()[$p . '_port'])));
                // Titkosítatlan kapcsolat csak a saját gépen futó (teszt) szerverhez engedett.
                $f[$p . '_titk'] = $titk === 'nincs' && !self::helyi_host($f[$p . '_host']) ? 'ssl' : $titk;
            }

            $jelszo = isset($uj['jelszo']) && is_scalar($uj['jelszo']) ? trim((string) $uj['jelszo']) : '';

            if (!empty($uj['jelszo_torol'])) {
                $f['jelszo'] = '';
            } elseif ($jelszo !== '') {
                // A Google szóközökkel tagolva mutatja az alkalmazásjelszót; azok nem részei.
                $f['jelszo'] = self::titkosit(self::gmail($f) ? (string) preg_replace('/\s+/', '', $jelszo) : $jelszo);
            }

            $zar = isset($uj['zar']) && is_scalar($uj['zar']) ? (string) $uj['zar'] : '';

            if (!empty($uj['zar_torol'])) {
                $f['zar'] = '';
            } elseif ($zar !== '') {
                $f['zar'] = wp_hash_password($zar);
            }

            // Másik postafiók került a helyére: a régi gyorsítótára nem maradhat.
            if ($regi_email !== '' && $regi_email !== $f['email']) {
                self::gyorsitotar_urit((string) $k);
            }

            $b['fiokok'][$k] = $f;
        }

        $u = isset($_POST['level_ugynok']) && is_array($_POST['level_ugynok']) ? wp_unslash($_POST['level_ugynok']) : [];

        $b['ugynok']['be'] = !empty($u['be']);
        $b['ugynok']['ai'] = !empty($u['ai']);

        foreach (['vip', 'zaj', 'partner', 'szavak'] as $nev) {
            $b['ugynok'][$nev] = isset($u[$nev]) && is_scalar($u[$nev]) ? mb_substr(sanitize_textarea_field((string) $u[$nev]), 0, 4000) : '';
        }

        $kulcs = isset($u['ai_kulcs']) && is_scalar($u['ai_kulcs']) ? trim(sanitize_text_field((string) $u['ai_kulcs'])) : '';

        if (!empty($u['ai_kulcs_torol'])) {
            $b['ugynok']['ai_kulcs'] = '';
        } elseif ($kulcs !== '') {
            $b['ugynok']['ai_kulcs'] = self::titkosit($kulcs);
        }

        $modell = isset($u['ai_modell']) && is_scalar($u['ai_modell']) ? (string) preg_replace('/[^A-Za-z0-9.\-_:]/', '', (string) $u['ai_modell']) : '';

        $b['ugynok']['ai_modell'] = $modell !== '' ? mb_substr($modell, 0, 80) : 'claude-haiku-4-5-20251001';
        $b['frissites_mp']        = min(900, max(30, (int) ($_POST['level_frissites_mp'] ?? 60)));
        $b['zar_perc']            = min(1440, max(5, (int) ($_POST['level_zar_perc'] ?? 480)));
        $b['hang']                = !empty($_POST['level_hang']);

        // A rendező ügynök jogai: csak innen, a Beállítások űrlapjáról állíthatók.
        $rk = isset($_POST['level_rendezo']) && is_array($_POST['level_rendezo']) ? wp_unslash($_POST['level_rendezo']) : [];

        foreach (['be', 'mozgathat', 'mappat', 'kukaba', 'jovahagyas'] as $nev) {
            $b['rendezo'][$nev] = !empty($rk[$nev]);
        }

        $b['rendezo']['max']    = min(SDH_Muhely_Level_Rendezo::LEVEL_MAX, max(1, (int) ($rk['max'] ?? 100)));
        $b['rendezo']['forras'] = ($rk['forras'] ?? '') === 'inbox' ? 'inbox' : 'barmely';
        $b['rendezo']['fiokok'] = [];

        foreach (array_keys($b['fiokok']) as $k) {
            $b['rendezo']['fiokok'][$k] = !empty($rk['fiokok'][$k]);
        }
        // phpcs:enable

        // Nem töltődik be minden oldalon (autoload = no): jelszavak vannak benne.
        update_option(self::OPTION, $b, false);
    }

    private static function gyorsitotar_urit(string $fk): void
    {
        global $wpdb;

        $wpdb->delete(self::tabla(), ['fiok' => $fk]);
        $wpdb->delete(self::mappa_tabla(), ['fiok' => $fk]);
    }

    /* =================================================================
     * Jogosultság és zárolt fiók
     * ============================================================== */

    private static function jog_ellenorzes(bool $json = true): void
    {
        check_ajax_referer('sdh_muhely_modal');

        // Minden kérés használhatja az IMAP- és a MIME-könyvtárat (más oldalon nem töltődnek be).
        self::konyvtarak();

        if (!current_user_can(SDH_Muhely_Admin_UI::jog())) {
            if ($json) {
                wp_send_json_error(['uzenet' => 'Nincs jogosultságod ehhez.'], 403);
            }

            status_header(403);
            echo '<div class="sdh-uzenet sdh-uzenet--hiba">Nincs jogosultságod ehhez.</div>';
            wp_die();
        }
    }

    /** A jelszóval védett fiók zárva van-e a mostani felhasználó mostani munkamenetében. */
    public static function zarva(string $fk): bool
    {
        $fiok = self::beallitas()['fiokok'][$fk] ?? null;

        if (!is_array($fiok) || (string) $fiok['zar'] === '') {
            return false;
        }

        $nyitva = get_user_meta(get_current_user_id(), 'sdh_level_nyitva', true);
        $adat   = is_array($nyitva) && is_array($nyitva[$fk] ?? null) ? $nyitva[$fk] : null;

        // A feloldás ahhoz a jelszóhoz és ahhoz a bejelentkezéshez kötött, amelyikben történt.
        return $adat === null
            || (int) ($adat['lejar'] ?? 0) < time()
            || !hash_equals((string) ($adat['munkamenet'] ?? ''), self::munkamenet())
            || !hash_equals((string) ($adat['zar'] ?? ''), md5((string) $fiok['zar']));
    }

    private static function munkamenet(): string
    {
        return hash('sha256', 'sdh-level|' . wp_get_session_token());
    }

    /**
     * Egy fiók a kéréshez: létezik, használható, és nincs zárva – különben hibaválasz.
     *
     * @return array<string, mixed>
     */
    private static function fiok_kell(string $fk): array
    {
        $fiok = self::fiokok()[$fk] ?? null;

        if (!is_array($fiok)) {
            wp_send_json_error(['uzenet' => 'Nincs ilyen fiók, vagy nincs beállítva.']);
        }

        if (self::zarva($fk)) {
            wp_send_json_error(['uzenet' => 'Ez a postafiók jelszóval védett. Előbb nyisd meg a jelszavával.', 'zarva' => true], 423);
        }

        return $fiok;
    }

    public static function ajax_nyit(): void
    {
        self::jog_ellenorzes();

        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $fk     = isset($_POST['fiok']) ? sanitize_key(wp_unslash($_POST['fiok'])) : '';
        $jelszo = isset($_POST['jelszo']) && is_scalar($_POST['jelszo']) ? (string) wp_unslash($_POST['jelszo']) : '';
        // phpcs:enable
        $b      = self::beallitas();
        $fiok   = $b['fiokok'][$fk] ?? null;

        if (!is_array($fiok) || (string) $fiok['zar'] === '') {
            wp_send_json_error(['uzenet' => 'Ez a fiók nincs jelszóval védve.']);
        }

        // Próbálgatás ellen: 5 hibás kísérlet után 15 perc szünet (felhasználónként).
        $szamlalo = 'sdh_level_proba_' . get_current_user_id();
        $proba    = (int) get_transient($szamlalo);

        if ($proba >= 5) {
            wp_send_json_error(['uzenet' => 'Túl sok hibás próbálkozás. 15 perc múlva próbáld újra.'], 429);
        }

        if ($jelszo === '' || !wp_check_password($jelszo, (string) $fiok['zar'])) {
            set_transient($szamlalo, $proba + 1, 15 * MINUTE_IN_SECONDS);
            wp_send_json_error(['uzenet' => 'Hibás jelszó.']);
        }

        delete_transient($szamlalo);

        $nyitva      = get_user_meta(get_current_user_id(), 'sdh_level_nyitva', true);
        $nyitva      = is_array($nyitva) ? $nyitva : [];
        $nyitva[$fk] = [
            'lejar'      => time() + (int) $b['zar_perc'] * MINUTE_IN_SECONDS,
            'munkamenet' => self::munkamenet(),
            'zar'        => md5((string) $fiok['zar']),
        ];

        update_user_meta(get_current_user_id(), 'sdh_level_nyitva', $nyitva);

        wp_send_json_success(['fiokok' => self::fiokok_kifele()]);
    }

    public static function ajax_zar(): void
    {
        self::jog_ellenorzes();

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $fk     = isset($_POST['fiok']) ? sanitize_key(wp_unslash($_POST['fiok'])) : '';
        $nyitva = get_user_meta(get_current_user_id(), 'sdh_level_nyitva', true);

        if (is_array($nyitva)) {
            unset($nyitva[$fk]);
            update_user_meta(get_current_user_id(), 'sdh_level_nyitva', $nyitva);
        }

        wp_send_json_success(['fiokok' => self::fiokok_kifele()]);
    }

    /* =================================================================
     * Kapcsolat a levelezőszerverrel
     * ============================================================== */

    private static function imap(array $fiok): SDH_Muhely_Imap
    {
        self::konyvtarak();

        $imap = new SDH_Muhely_Imap((string) $fiok['imap_host'], (int) $fiok['imap_port'], (string) $fiok['imap_titk'], 20);

        $imap->kapcsolodik();
        $imap->belep(trim((string) $fiok['felhasznalo']) !== '' ? (string) $fiok['felhasznalo'] : (string) $fiok['email'], self::visszafejt((string) $fiok['jelszo']));

        return $imap;
    }

    /** Beállítások: a kapcsolat ellenőrzése (bejövő és kimenő), levél küldése nélkül. */
    public static function ajax_teszt(): void
    {
        self::jog_ellenorzes();

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['uzenet' => 'Ehhez rendszergazdai jog kell.'], 403);
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $fk   = isset($_POST['fiok']) ? sanitize_key(wp_unslash($_POST['fiok'])) : '';
        $fiok = self::beallitas()['fiokok'][$fk] ?? null;

        if (!is_array($fiok) || (string) $fiok['jelszo'] === '' || (string) $fiok['email'] === '') {
            wp_send_json_error(['uzenet' => 'Előbb mentsd el az e-mail-címet és az alkalmazásjelszót.']);
        }

        try {
            $imap   = self::imap($fiok);
            $mappak = count($imap->mappak());
            $imap->kilep();
        } catch (\Throwable $hiba) {
            wp_send_json_error(['uzenet' => 'Bejövő (IMAP): ' . $hiba->getMessage()]);
        }

        try {
            $smtp = self::levelkuldo($fiok);
            $ok   = $smtp->smtpConnect();
            $smtp->smtpClose();

            if (!$ok) {
                throw new \RuntimeException('a szerver elutasította a belépést');
            }
        } catch (\Throwable $hiba) {
            wp_send_json_error(['uzenet' => 'A bejövő kapcsolat él (' . $mappak . ' mappa), de a kimenő (SMTP) nem: ' . $hiba->getMessage()]);
        }

        delete_transient('sdh_level_szunet_' . $fk);

        wp_send_json_success(['uzenet' => 'A kapcsolat él: ' . $mappak . ' mappa látszik, és a küldés is engedélyezett. (Levél nem ment ki.)']);
    }

    /* =================================================================
     * Mappák
     * ============================================================== */

    /**
     * A fiók mappái a gyorsítótárból, megjelenítési sorrendben.
     *
     * @return array<int, object>
     */
    private static function mappak(string $fk): array
    {
        global $wpdb;

        $sorok = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::mappa_tabla() . ' WHERE fiok = %s', $fk));
        $sorok = is_array($sorok) ? $sorok : [];
        $rang  = array_flip(array_keys(self::SZEREPEK));

        usort($sorok, static function (object $a, object $b) use ($rang): int {
            return [$rang[(string) $a->szerep] ?? 99, mb_strtolower(self::mappa_nev($a), 'UTF-8')] <=> [$rang[(string) $b->szerep] ?? 99, mb_strtolower(self::mappa_nev($b), 'UTF-8')];
        });

        return $sorok;
    }

    /** A mappa megjelenő neve: a szerep magyar neve, különben a név a „[Gmail]/" előtag nélkül. */
    private static function mappa_nev(object $mappa): string
    {
        if (isset(self::SZEREPEK[(string) $mappa->szerep])) {
            return self::SZEREPEK[(string) $mappa->szerep];
        }

        return (string) preg_replace('~^\[(Gmail|Google Mail)\]/~', '', (string) $mappa->nev);
    }

    private static function mappa(int $id): ?object
    {
        global $wpdb;

        $sor = $id > 0 ? $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::mappa_tabla() . ' WHERE id = %d', $id)) : null;

        return is_object($sor) ? $sor : null;
    }

    private static function szerep_mappa(string $fk, string $szerep): ?object
    {
        global $wpdb;

        $sor = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::mappa_tabla() . ' WHERE fiok = %s AND szerep = %s ORDER BY id LIMIT 1', $fk, $szerep));

        return is_object($sor) ? $sor : null;
    }

    /**
     * A mappalista frissítése a szerverről. `$szamlalok`: a mappák levélszáma is (mappánként egy kérés).
     */
    private static function mappak_frissit(string $fk, SDH_Muhely_Imap $imap, bool $szamlalok): void
    {
        global $wpdb;

        $regiek = [];

        foreach (self::mappak($fk) as $m) {
            $regiek[(string) $m->nyers] = $m;
        }

        foreach ($imap->mappak() as $m) {
            $adat = [
                'fiok'        => $fk,
                'nyers'       => mb_substr($m['nyers'], 0, 255),
                'nev'         => mb_substr($m['nev'], 0, 255),
                'szerep'      => $m['szerep'],
                'elvalaszto'  => mb_substr($m['elvalaszto'], 0, 4),
                'valaszthato' => $m['valaszthato'] ? 1 : 0,
            ];

            if ($szamlalok && $m['valaszthato']) {
                try {
                    $a = $imap->allapot($m['nyers']);

                    $adat['osszes']     = $a['messages'];
                    $adat['olvasatlan'] = $a['unseen'];
                } catch (SDH_Muhely_Imap_Hiba $hiba) {
                    // Egy mappa számlálója nélkül is megjelenik a lista.
                    unset($hiba);
                }
            }

            if (isset($regiek[$m['nyers']])) {
                $wpdb->update(self::mappa_tabla(), $adat, ['id' => (int) $regiek[$m['nyers']]->id]);
                unset($regiek[$m['nyers']]);
            } else {
                $wpdb->insert(self::mappa_tabla(), $adat);
            }
        }

        // Ami a szerveren megszűnt, az a gyorsítótárból is kikerül.
        foreach ($regiek as $m) {
            $wpdb->delete(self::tabla(), ['mappa_id' => (int) $m->id]);
            $wpdb->delete(self::mappa_tabla(), ['id' => (int) $m->id]);
        }

        set_transient('sdh_level_mappak_' . $fk, time(), 10 * MINUTE_IN_SECONDS);
    }

    /**
     * A mappák a böngészőnek.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function mappak_kifele(string $fk): array
    {
        $ki = [];

        foreach (self::mappak($fk) as $m) {
            $nev = self::mappa_nev($m);
            $elv = (string) $m->elvalaszto !== '' ? (string) $m->elvalaszto : '/';

            $ki[] = [
                'id'          => (int) $m->id,
                'nev'         => (string) $m->szerep !== '' ? $nev : (string) preg_replace('~^.*' . preg_quote($elv, '~') . '~u', '', $nev),
                'teljes'      => $nev,
                'szint'       => (string) $m->szerep !== '' ? 0 : substr_count($nev, $elv),
                'szerep'      => (string) $m->szerep,
                'valaszthato' => (int) $m->valaszthato === 1,
                'osszes'      => (int) $m->osszes,
                'olvasatlan'  => (int) $m->olvasatlan,
            ];
        }

        return $ki;
    }

    /**
     * A fiókok a böngészőnek. Zárolt fióknál csak a név és a zár ténye megy ki.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function fiokok_kifele(): array
    {
        $ki = [];

        foreach (self::fiokok() as $fk => $fiok) {
            $zarva = self::zarva((string) $fk);
            $be    = $zarva ? null : self::szerep_mappa((string) $fk, 'inbox');
            $hiba  = get_transient('sdh_level_hiba_' . $fk);

            $ki[] = [
                'kulcs'      => (string) $fk,
                'nev'        => self::fiok_nev($fiok),
                'email'      => (string) $fiok['email'],
                'vedett'     => (string) $fiok['zar'] !== '',
                'zarva'      => $zarva,
                'olvasatlan' => $be ? (int) $be->olvasatlan : 0,
                'mappak'     => $zarva ? [] : self::mappak_kifele((string) $fk),
                'hiba'       => !$zarva && is_string($hiba) ? $hiba : '',
            ];
        }

        return $ki;
    }

    public static function ajax_mappak(): void
    {
        self::jog_ellenorzes();

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $fk   = isset($_POST['fiok']) ? sanitize_key(wp_unslash($_POST['fiok'])) : '';
        $fiok = self::fiok_kell($fk);

        try {
            $imap = self::imap($fiok);
            self::mappak_frissit($fk, $imap, true);
            $imap->kilep();
            delete_transient('sdh_level_hiba_' . $fk);
        } catch (\Throwable $hiba) {
            wp_send_json_error(['uzenet' => $hiba->getMessage()]);
        }

        wp_send_json_success(['fiokok' => self::fiokok_kifele()]);
    }

    /** Mappa létrehozása, átnevezése, törlése (a rendszermappák nem módosíthatók). */
    public static function ajax_mappa(): void
    {
        self::jog_ellenorzes();

        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $fk      = isset($_POST['fiok']) ? sanitize_key(wp_unslash($_POST['fiok'])) : '';
        $muvelet = isset($_POST['muvelet']) ? sanitize_key(wp_unslash($_POST['muvelet'])) : '';
        $nev     = isset($_POST['nev']) && is_scalar($_POST['nev']) ? trim(mb_substr(sanitize_text_field(wp_unslash((string) $_POST['nev'])), 0, 80)) : '';
        $mappa   = self::mappa(isset($_POST['mappa']) ? (int) $_POST['mappa'] : 0);
        // phpcs:enable
        $fiok    = self::fiok_kell($fk);

        if (in_array($muvelet, ['uj', 'atnevez'], true) && ($nev === '' || preg_match('/[\x00-\x1f"\\\\*%]/', $nev) === 1)) {
            wp_send_json_error(['uzenet' => 'Adj meg egy mappanevet (idézőjel, \\, * és % nélkül).']);
        }

        if (in_array($muvelet, ['atnevez', 'torol'], true) && ($mappa === null || (string) $mappa->fiok !== $fk || (string) $mappa->szerep !== '' || preg_match('~^\[(Gmail|Google Mail)\]~', (string) $mappa->nev) === 1)) {
            wp_send_json_error(['uzenet' => 'A rendszermappák nem nevezhetők át és nem törölhetők.']);
        }

        try {
            $imap = self::imap($fiok);

            if ($muvelet === 'uj') {
                $imap->mappa_letrehoz($nev);
            } elseif ($muvelet === 'atnevez') {
                // Az almappa a szülője alatt marad: csak az utolsó névrész változik.
                $elv   = (string) $mappa->elvalaszto !== '' ? (string) $mappa->elvalaszto : '/';
                $szulo = strrpos((string) $mappa->nev, $elv) !== false ? substr((string) $mappa->nev, 0, (int) strrpos((string) $mappa->nev, $elv) + strlen($elv)) : '';

                $imap->mappa_atnevez((string) $mappa->nyers, $szulo . $nev);
            } elseif ($muvelet === 'torol') {
                $imap->mappa_torol((string) $mappa->nyers);
            } else {
                wp_send_json_error(['uzenet' => 'Ismeretlen művelet.']);
            }

            self::mappak_frissit($fk, $imap, false);
            $imap->kilep();
        } catch (\Throwable $hiba) {
            wp_send_json_error(['uzenet' => $hiba->getMessage()]);
        }

        wp_send_json_success(['fiokok' => self::fiokok_kifele()]);
    }

    /* =================================================================
     * Szinkron: a levelek fejadatai a gyorsítótárba
     * ============================================================== */

    /** A szerver dátumából (vagy a Date fejlécből) a telephely ideje szerinti időpont. */
    private static function idopont(string $fejlec_datum, string $szerver_datum): string
    {
        $t = $fejlec_datum !== '' ? strtotime($fejlec_datum) : false;

        // Hibás vagy jövőbeli Date fejlécnél a szerver érkezési ideje a mérvadó.
        if ($t === false || $t <= 0 || $t > time() + DAY_IN_SECONDS) {
            $t = $szerver_datum !== '' ? strtotime($szerver_datum) : false;
        }

        return wp_date('Y-m-d H:i:s', $t !== false && $t > 0 ? $t : time());
    }

    /**
     * Letöltött levelek mentése a gyorsítótárba; az újakat (Beérkezett, olvasatlan,
     * friss) az ügynök besorolja.
     *
     * @param array<int, array<string, mixed>> $elemek SDH_Muhely_Imap::lekeres() eredménye
     * @return int A mentett új sorok száma.
     */
    private static function sorok_ment(string $fk, array $fiok, object $mappa, array $elemek): int
    {
        global $wpdb;

        if ($elemek === []) {
            return 0;
        }

        self::konyvtarak();

        $uidk    = array_map(static fn (array $e): int => (int) $e['uid'], $elemek);
        $megvan  = array_map('intval', (array) $wpdb->get_col(
            'SELECT uid FROM ' . self::tabla() . ' WHERE mappa_id = ' . (int) $mappa->id . ' AND uid IN (' . implode(',', $uidk) . ')'
        ));
        $b       = self::beallitas();
        $ugynok  = $b['ugynok'];
        $ugynok['ai_kulcs'] = self::visszafejt((string) $ugynok['ai_kulcs']);
        $sajat   = array_values(array_filter(array_map(static fn (array $f): string => strtolower((string) $f['email']), $b['fiokok'])));
        $ai_keret = 10;
        $db      = 0;

        foreach ($elemek as $e) {
            if (in_array((int) $e['uid'], $megvan, true)) {
                continue;
            }

            $nyers  = (string) ($e['torzs']['HEADER'] ?? '') . (string) ($e['torzs']['TEXT'] ?? '');
            $level  = SDH_Muhely_Mime::feldolgoz($nyers);
            $fej    = $level['fejlec'];
            $felado = SDH_Muhely_Mime::cimek((string) ($fej['from'] ?? ''))[0] ?? ['nev' => '', 'email' => ''];
            $szoveg = SDH_Muhely_Mime::olvashato($level);
            $jelzok = (array) $e['jelzok'];
            $csat   = false;

            foreach ($level['csatolmanyok'] as $c) {
                $csat = $csat || !$c['beagyazott'];
            }

            $sor = [
                'fiok'         => $fk,
                'mappa_id'     => (int) $mappa->id,
                'uid'          => (int) $e['uid'],
                'message_id'   => mb_substr(trim((string) ($fej['message-id'] ?? '')), 0, 255),
                'felado_nev'   => mb_substr($felado['nev'], 0, 190),
                'felado_email' => mb_substr($felado['email'], 0, 190),
                'cimzettek'    => mb_substr((string) ($fej['to'] ?? ''), 0, 1000),
                'targy'        => mb_substr((string) ($fej['subject'] ?? ''), 0, 255),
                'kivonat'      => SDH_Muhely_Mime::kivonat($szoveg, 240),
                'datum'        => self::idopont((string) ($fej['date'] ?? ''), (string) $e['datum']),
                'meret'        => (int) $e['meret'],
                'olvasott'     => in_array('\\seen', $jelzok, true) ? 1 : 0,
                'csillag'      => in_array('\\flagged', $jelzok, true) ? 1 : 0,
                'valaszolt'    => in_array('\\answered', $jelzok, true) ? 1 : 0,
                'piszkozat'    => in_array('\\draft', $jelzok, true) ? 1 : 0,
                // A lista csak a levél elejét tölti le: a többrészes „mixed" levél szinte mindig csatolmányos.
                'csatolmany'   => $csat || stripos((string) ($fej['content-type'] ?? ''), 'multipart/mixed') === 0 ? 1 : 0,
                'letrehozva'   => current_time('mysql'),
            ];

            // Az ügynök csak a Beérkezett friss, még olvasatlan leveleit nézi.
            if (!empty($ugynok['be']) && (string) $mappa->szerep === 'inbox' && $sor['olvasott'] === 0 && strtotime($sor['datum']) > strtotime(current_time('mysql')) - 7 * DAY_IN_SECONDS) {
                $u = $ugynok;

                if ($ai_keret <= 0) {
                    $u['ai'] = false;
                }

                $ertek = SDH_Muhely_Level_Ugynok::ertekel([
                    'felado_nev'    => $felado['nev'],
                    'felado_email'  => $felado['email'],
                    'targy'         => $sor['targy'],
                    'szoveg'        => $szoveg,
                    'fejlec'        => $fej,
                    'ismert_ugyfel' => self::ugyfel($felado['email']) !== null,
                    'sajat_cimek'   => $sajat,
                ], $u);

                $ai_keret -= $ertek['forras'] === 'ai' ? 1 : 0;

                $sor['fontossag'] = $ertek['szint'];
                $sor['fontos_ok'] = mb_substr($ertek['ok'], 0, 255);
                $sor['ugynok']    = $ertek['forras'];
            }

            $wpdb->suppress_errors(true);
            $db += $wpdb->insert(self::tabla(), $sor) ? 1 : 0;
            $wpdb->suppress_errors(false);
        }

        return $db;
    }

    /** A feladó e-mail-címe alapján az ügyfél (ha van ilyen a CRM-ben). */
    private static function ugyfel(string $email): ?object
    {
        global $wpdb;

        if ($email === '' || !is_email($email)) {
            return null;
        }

        $sor = $wpdb->get_row($wpdb->prepare('SELECT id, nev FROM ' . SDH_Muhely_Schema::tabla('ugyfel') . ' WHERE email = %s ORDER BY aktiv DESC, id DESC LIMIT 1', $email));

        return is_object($sor) ? $sor : null;
    }

    /**
     * Egy mappa szinkronja: új levelek, a máshol (telefonon) történt olvasás,
     * csillagozás és törlés követése a legújabb levelek ablakában, számlálók.
     *
     * @return object A frissített mappa-sor.
     */
    private static function szinkron(string $fk, array $fiok, object $mappa, SDH_Muhely_Imap $imap): object
    {
        global $wpdb;

        $allapot = $imap->kivalaszt((string) $mappa->nyers);
        $min     = (int) $mappa->min_uid;
        $max     = (int) $mappa->max_uid;

        // Új UIDVALIDITY: a szerver újraszámozta a mappát, a régi gyorsítótár érvénytelen.
        if ((int) $mappa->uidvalidity !== $allapot['uidvalidity'] || $allapot['exists'] === 0) {
            $wpdb->delete(self::tabla(), ['mappa_id' => (int) $mappa->id]);
            $min = 0;
            $max = 0;
        }

        if ($allapot['exists'] > 0) {
            if ($max === 0) {
                // Első alkalom: a legújabb levelek, sorszám szerint.
                $tol    = max(1, $allapot['exists'] - self::ELSO + 1);
                $elemek = $imap->lekeres($tol . ':' . $allapot['exists'], self::ELEMEK, false);

                if ($elemek !== []) {
                    $min = $tol === 1 ? 1 : (int) $elemek[0]['uid'];
                }
            } else {
                // A „max+1:*" akkor is visszaadja az utolsó levelet, ha nincs újabb: azt kiszűrjük.
                $elemek = array_values(array_filter(
                    $imap->lekeres(($max + 1) . ':*', self::ELEMEK),
                    static fn (array $e): bool => (int) $e['uid'] > $max
                ));
            }

            self::sorok_ment($fk, $fiok, $mappa, $elemek);

            foreach ($elemek as $e) {
                $max = max($max, (int) $e['uid']);
            }

            // Változások követése a legújabb levelek ablakában.
            $also = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT uid FROM ' . self::tabla() . ' WHERE mappa_id = %d AND uid >= %d ORDER BY uid DESC LIMIT 1 OFFSET %d',
                (int) $mappa->id,
                $min,
                self::ABLAK - 1
            ));
            $also = $also > 0 ? $also : max(1, $min);

            if ($max > 0) {
                self::jelzok_frissit((int) $mappa->id, $imap, $also . ':' . $max, $also, $max);
            }
        }

        $adat = [
            'uidvalidity' => $allapot['uidvalidity'],
            'min_uid'     => $min,
            'max_uid'     => $max,
            'osszes'      => $allapot['exists'],
            'olvasatlan'  => $allapot['exists'] > 0 ? count($imap->uid_keres('UNSEEN')) : 0,
            'szinkron'    => current_time('mysql'),
        ];

        $wpdb->update(self::mappa_tabla(), $adat, ['id' => (int) $mappa->id]);

        foreach ($adat as $k => $v) {
            $mappa->$k = $v;
        }

        return $mappa;
    }

    /**
     * A gyorsítótár jelzőinek (olvasott, csillag, megválaszolt) igazítása a
     * szerverhez egy UID-tartományban; ami ott már nincs meg, az innen is törlődik.
     */
    private static function jelzok_frissit(int $mappa_id, SDH_Muhely_Imap $imap, string $halmaz, int $tol, int $ig): void
    {
        global $wpdb;

        $szerver = [];

        foreach ($imap->lekeres($halmaz, 'UID FLAGS') as $e) {
            $szerver[(int) $e['uid']] = (array) $e['jelzok'];
        }

        $sorok = $wpdb->get_results($wpdb->prepare(
            'SELECT id, uid, olvasott, csillag, valaszolt FROM ' . self::tabla() . ' WHERE mappa_id = %d AND uid >= %d AND uid <= %d',
            $mappa_id,
            $tol,
            $ig
        ));

        foreach (is_array($sorok) ? $sorok : [] as $s) {
            $uid = (int) $s->uid;

            if (!isset($szerver[$uid])) {
                $wpdb->delete(self::tabla(), ['id' => (int) $s->id]);

                continue;
            }

            $uj = [
                'olvasott'  => in_array('\\seen', $szerver[$uid], true) ? 1 : 0,
                'csillag'   => in_array('\\flagged', $szerver[$uid], true) ? 1 : 0,
                'valaszolt' => in_array('\\answered', $szerver[$uid], true) ? 1 : 0,
            ];

            if ($uj['olvasott'] !== (int) $s->olvasott || $uj['csillag'] !== (int) $s->csillag || $uj['valaszolt'] !== (int) $s->valaszolt) {
                // Amit máshol (telefonon) megválaszoltak, az itt sem teendő többé.
                if ($uj['valaszolt'] === 1 && (int) $s->valaszolt !== 1) {
                    $uj['elintezve'] = 1;
                }

                $wpdb->update(self::tabla(), $uj, ['id' => (int) $s->id]);
            }
        }
    }

    /**
     * Régebbi levelek letöltése, amíg a hiánytalanul letöltött rész el nem éri a
     * kért darabszámot (vagy a mappa elejét).
     */
    private static function regebbiek(string $fk, array $fiok, object $mappa, SDH_Muhely_Imap $imap, int $kell): object
    {
        global $wpdb;

        for ($kor = 0; $kor < 40; $kor++) {
            $min = (int) $mappa->min_uid;
            $van = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::tabla() . ' WHERE mappa_id = %d AND uid >= %d', (int) $mappa->id, $min));

            if ($van >= $kell || $min <= 1) {
                break;
            }

            // A legrégebbi letöltött levél sorszáma: az előtte állók következnek.
            $sorszamok = $imap->sorszam_keres('UID ' . $min . ':*');
            $elso      = $sorszamok !== [] ? min($sorszamok) : (int) $mappa->osszes + 1;

            if ($elso <= 1) {
                $mappa->min_uid = 1;
                $wpdb->update(self::mappa_tabla(), ['min_uid' => 1], ['id' => (int) $mappa->id]);
                break;
            }

            $tol    = max(1, $elso - self::ADAG);
            $elemek = $imap->lekeres($tol . ':' . ($elso - 1), self::ELEMEK, false);

            self::sorok_ment($fk, $fiok, $mappa, $elemek);

            $mappa->min_uid = $tol === 1 || $elemek === [] ? 1 : (int) $elemek[0]['uid'];
            $wpdb->update(self::mappa_tabla(), ['min_uid' => (int) $mappa->min_uid], ['id' => (int) $mappa->id]);
        }

        return $mappa;
    }

    /* =================================================================
     * Állapot (percenkénti lekérdezés minden CRM-oldalról): új levelek, jelzések
     * ============================================================== */

    /** A fiók Beérkezett mappájának szinkronja, ha esedékes. A hiba nem akasztja meg a többit. */
    private static function hatter_szinkron(string $fk, array $fiok, int $ritkan): void
    {
        $be = self::szerep_mappa($fk, 'inbox');

        if ($be !== null && $be->szinkron !== null && strtotime((string) $be->szinkron) > strtotime(current_time('mysql')) - $ritkan) {
            return;
        }

        // Egyszerre egy szinkron fut fiókonként; hibás belépés után 5 perc szünet (a Google ne zárja ki a fiókot).
        if (get_transient('sdh_level_fut_' . $fk) || get_transient('sdh_level_szunet_' . $fk)) {
            return;
        }

        set_transient('sdh_level_fut_' . $fk, 1, 60);

        try {
            $imap = self::imap($fiok);

            if ($be === null || !get_transient('sdh_level_mappak_' . $fk)) {
                self::mappak_frissit($fk, $imap, $be === null);
                $be = self::szerep_mappa($fk, 'inbox');
            }

            if ($be !== null) {
                $regi_max = (int) $be->max_uid;
                $be       = self::szinkron($fk, $fiok, $be, $imap);

                // A most érkezett levelek törzse rögtön a gyorsítótárba: az értesítésre kattintva azonnal megnyílnak.
                if ($regi_max > 0 && (int) $be->max_uid > $regi_max) {
                    global $wpdb;

                    $ujak = $wpdb->get_results($wpdb->prepare('SELECT uid, meret FROM ' . self::tabla() . ' WHERE mappa_id = %d AND uid > %d ORDER BY uid DESC LIMIT 15', (int) $be->id, $regi_max));

                    self::elore_tolt($be, $imap, is_array($ujak) ? $ujak : []);
                }
            }

            $imap->kilep();
            delete_transient('sdh_level_hiba_' . $fk);
            self::tar_takarit();
        } catch (\Throwable $hiba) {
            set_transient('sdh_level_hiba_' . $fk, $hiba->getMessage(), 10 * MINUTE_IN_SECONDS);

            if (strpos($hiba->getMessage(), 'belépés') !== false) {
                set_transient('sdh_level_szunet_' . $fk, 1, 5 * MINUTE_IN_SECONDS);
            }
        }

        delete_transient('sdh_level_fut_' . $fk);
    }

    /** Az oldalmenü jelvénye: az olvasatlan levelek a fiókok Beérkezett mappáiban. */
    public static function olvasatlan_db(): int
    {
        global $wpdb;

        $kulcsok = array_keys(self::fiokok());

        if ($kulcsok === []) {
            return 0;
        }

        return (int) $wpdb->get_var(
            'SELECT COALESCE(SUM(olvasatlan), 0) FROM ' . self::mappa_tabla() . " WHERE szerep = 'inbox' AND fiok IN ('" . implode("','", array_map('esc_sql', $kulcsok)) . "')"
        );
    }

    /** Az ügynök le nem zárt azonnali jelzései a nyitott fiókokban (Áttekintés csempe, „Teendők"). */
    public static function azonnal_db(): int
    {
        global $wpdb;

        $nyitott = self::nyitott_sql();

        return $nyitott === '' ? 0 : (int) $wpdb->get_var(
            'SELECT COUNT(*) FROM ' . self::tabla() . ' l INNER JOIN ' . self::mappa_tabla() . " mp ON mp.id = l.mappa_id
             WHERE mp.szerep = 'inbox' AND l.fontossag = 'azonnal' AND l.elintezve = 0 AND l.fiok IN ({$nyitott})"
        );
    }

    /** A nyitott (nem zárolt) fiókok kulcsai SQL-listaként, vagy üres szöveg. */
    private static function nyitott_sql(): string
    {
        $kulcsok = array_filter(array_keys(self::fiokok()), static fn ($k): bool => !self::zarva((string) $k));

        return $kulcsok === [] ? '' : "'" . implode("','", array_map('esc_sql', $kulcsok)) . "'";
    }

    public static function ajax_allapot(): void
    {
        global $wpdb;

        self::jog_ellenorzes();

        $b = self::beallitas();

        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $utolso = isset($_POST['utolso']) ? max(0, (int) $_POST['utolso']) : 0;
        $elso   = !isset($_POST['utolso']) || $_POST['utolso'] === '';
        // phpcs:enable

        @set_time_limit(90); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

        foreach (self::fiokok() as $fk => $fiok) {
            self::hatter_szinkron((string) $fk, $fiok, max(20, (int) $b['frissites_mp'] - 15));
        }

        $t        = self::tabla();
        $m        = self::mappa_tabla();
        $nyitott  = self::nyitott_sql();
        $max      = (int) $wpdb->get_var("SELECT COALESCE(MAX(l.id), 0) FROM {$t} l INNER JOIN {$m} mp ON mp.id = l.mappa_id WHERE mp.szerep = 'inbox'");
        $ujak     = [];
        $zart_uj  = [];

        // Az első lekérdezés csak a kiindulópontot adja meg: a régi levelekről nem szólunk.
        if (!$elso && $max > $utolso) {
            $sorok = $wpdb->get_results($wpdb->prepare(
                "SELECT l.* FROM {$t} l INNER JOIN {$m} mp ON mp.id = l.mappa_id
                 WHERE mp.szerep = 'inbox' AND l.id > %d AND l.olvasott = 0 AND l.fontossag <> 'zaj' AND l.datum > %s
                 ORDER BY l.id DESC LIMIT 12",
                $utolso,
                // Csak a friss levél „új": a Spamből vagy az archívumból visszatett régi levélről nem szólunk.
                gmdate('Y-m-d H:i:s', (int) strtotime(current_time('mysql')) - DAY_IN_SECONDS)
            ));

            foreach (is_array($sorok) ? $sorok : [] as $s) {
                if (self::zarva((string) $s->fiok)) {
                    // Zárolt fiókból a levél adatai nem mennek ki – csak annyi, hogy érkezett.
                    $zart_uj[(string) $s->fiok] = ($zart_uj[(string) $s->fiok] ?? 0) + 1;

                    continue;
                }

                $ujak[] = self::sor_kifele($s);
            }
        }

        $azonnal = self::azonnal_db();

        $fiokok = self::fiokok_kifele();

        foreach ($fiokok as $i => $f) {
            $fiokok[$i]['uj_zarva'] = (int) ($zart_uj[$f['kulcs']] ?? 0);
            unset($fiokok[$i]['mappak']);
        }

        wp_send_json_success([
            'utolso'     => $max,
            'olvasatlan' => self::olvasatlan_db(),
            'azonnal'    => $azonnal,
            'ujak'       => array_reverse($ujak),
            'fiokok'     => $fiokok,
            'hang'       => !empty($b['hang']),
            'frissites'  => (int) $b['frissites_mp'],
        ]);
    }

    /* =================================================================
     * Levéllista
     * ============================================================== */

    /** Rövid dátum a listába: ma óra:perc, idén hónap-nap, régebben teljes dátum. */
    private static function datum_rovid(string $mysql): string
    {
        $t = strtotime($mysql);

        if ($t === false) {
            return '';
        }

        $ma = current_time('Y-m-d');

        if (gmdate('Y-m-d', $t) === $ma) {
            return gmdate('H:i', $t);
        }

        $honapok = ['', 'jan.', 'febr.', 'márc.', 'ápr.', 'máj.', 'jún.', 'júl.', 'aug.', 'szept.', 'okt.', 'nov.', 'dec.'];

        return gmdate('Y', $t) === substr($ma, 0, 4)
            ? $honapok[(int) gmdate('n', $t)] . ' ' . (int) gmdate('j', $t) . '.'
            : gmdate('Y. m. d.', $t);
    }

    /**
     * Egy levél sora a böngészőnek.
     *
     * @return array<string, mixed>
     */
    private static function sor_kifele(object $s): array
    {
        return [
            'id'         => (int) $s->id,
            'fiok'       => (string) $s->fiok,
            'mappa'      => (int) $s->mappa_id,
            'felado'     => (string) $s->felado_nev !== '' ? (string) $s->felado_nev : (string) $s->felado_email,
            'email'      => (string) $s->felado_email,
            'cimzett'    => (string) $s->cimzettek,
            'targy'      => (string) $s->targy !== '' ? (string) $s->targy : '(nincs tárgy)',
            'kivonat'    => (string) $s->kivonat,
            'datum'      => self::datum_rovid((string) $s->datum),
            'idopont'    => (string) $s->datum,
            'olvasott'   => (int) $s->olvasott === 1,
            'csillag'    => (int) $s->csillag === 1,
            'valaszolt'  => (int) $s->valaszolt === 1,
            'csatolmany' => (int) $s->csatolmany === 1,
            'fontossag'  => (string) $s->fontossag,
            'ok'         => (string) $s->fontos_ok,
            'elintezve'  => (int) $s->elintezve === 1,
        ];
    }

    public static function ajax_lista(): void
    {
        global $wpdb;

        self::jog_ellenorzes();

        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $fk       = isset($_POST['fiok']) ? sanitize_key(wp_unslash($_POST['fiok'])) : '';
        $mappa_k  = isset($_POST['mappa']) ? sanitize_key(wp_unslash($_POST['mappa'])) : '';
        $oldal    = isset($_POST['oldal']) ? max(1, (int) $_POST['oldal']) : 1;
        $q        = isset($_POST['q']) && is_scalar($_POST['q']) ? trim(mb_substr(sanitize_text_field(wp_unslash((string) $_POST['q'])), 0, 120)) : '';
        // phpcs:enable
        // Gyors mód: csak a gyorsítótárból, a levelezőszerver megkérdezése nélkül. A böngésző ezzel
        // rajzol azonnal, majd egy második (háttér)kéréssel frissít a szerverről.
        $gyors    = !empty($_POST['gyors']); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $t        = self::tabla();
        $m        = self::mappa_tabla();
        $eltolas  = ($oldal - 1) * self::OLDAL;

        @set_time_limit(90); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

        // --- „Teendők": az ügynök jelzései minden nyitott fiókból (a szerverhez nem kell kapcsolódni) ---
        if ($mappa_k === 'fontos') {
            $nyitott = self::nyitott_sql();
            $szuro   = "mp.szerep = 'inbox' AND l.fontossag IN ('azonnal', 'ma') AND l.elintezve = 0 AND l.fiok IN (" . ($nyitott !== '' ? $nyitott : "''") . ')';
            $ossz    = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t} l INNER JOIN {$m} mp ON mp.id = l.mappa_id WHERE {$szuro}");
            $sorok   = $wpdb->get_results($wpdb->prepare(
                "SELECT l.* FROM {$t} l INNER JOIN {$m} mp ON mp.id = l.mappa_id WHERE {$szuro}
                 ORDER BY CASE WHEN l.fontossag = 'azonnal' THEN 0 ELSE 1 END, l.datum DESC, l.id DESC LIMIT %d OFFSET %d",
                self::OLDAL,
                $eltolas
            ));

            wp_send_json_success([
                'sorok'   => array_map([self::class, 'sor_kifele'], is_array($sorok) ? $sorok : []),
                'ossz'    => $ossz,
                'oldal'   => $oldal,
                'oldalak' => max(1, (int) ceil($ossz / self::OLDAL)),
                'fiokok'  => self::fiokok_kifele(),
            ]);
        }

        $fiok  = self::fiok_kell($fk);
        $mappa = self::mappa((int) $mappa_k);

        // Első megnyitás: még nincs mappalista.
        if ($mappa === null && $mappa_k === '') {
            try {
                $imap = self::imap($fiok);
                self::mappak_frissit($fk, $imap, true);
                $imap->kilep();
            } catch (\Throwable $hiba) {
                wp_send_json_error(['uzenet' => $hiba->getMessage()]);
            }

            $mappa = self::szerep_mappa($fk, 'inbox');
        }

        if ($mappa === null || (string) $mappa->fiok !== $fk || (int) $mappa->valaszthato !== 1) {
            wp_send_json_error(['uzenet' => 'Nincs ilyen mappa.']);
        }

        $hiba_szoveg = '';
        $kereses_uid = null;

        if ($gyors && $q === '' && $mappa->szinkron !== null) {
            $sorok = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$t} WHERE mappa_id = %d AND uid >= %d ORDER BY uid DESC LIMIT %d OFFSET %d",
                (int) $mappa->id,
                (int) $mappa->min_uid,
                self::OLDAL,
                $eltolas
            ));
            $sorok = is_array($sorok) ? $sorok : [];
            $ossz  = (int) $mappa->osszes;

            wp_send_json_success([
                'sorok'   => array_map([self::class, 'sor_kifele'], $sorok),
                'ossz'    => $ossz,
                'oldal'   => $oldal,
                'oldalak' => max(1, (int) ceil($ossz / self::OLDAL)),
                'mappa'   => (int) $mappa->id,
                'fiokok'  => self::fiokok_kifele(),
                'hiba'    => '',
                'gyors'   => true,
                // Ennyi levélnek kellene lennie az oldalon; ha kevesebb van a gyorsítótárban, a háttérkérés pótolja.
                'hianyos' => count($sorok) < min(self::OLDAL, max(0, $ossz - $eltolas)),
            ]);
        }

        try {
            $imap  = self::imap($fiok);
            $mappa = self::szinkron($fk, $fiok, $mappa, $imap);

            if ($q !== '') {
                // Keresés a szerveren (Gmailnél a Gmail keresője); a találatok fejadatait letöltjük, ami hiányzik.
                $talalat     = array_reverse($imap->szoveg_keres($q));
                $kereses_uid = array_slice($talalat, $eltolas, self::OLDAL);
                $megvan      = $kereses_uid === [] ? [] : array_map('intval', (array) $wpdb->get_col(
                    "SELECT uid FROM {$t} WHERE mappa_id = " . (int) $mappa->id . ' AND uid IN (' . implode(',', array_map('intval', $kereses_uid)) . ')'
                ));
                $hianyzik    = array_values(array_diff($kereses_uid, $megvan));

                if ($hianyzik !== []) {
                    self::sorok_ment($fk, $fiok, $mappa, $imap->lekeres(SDH_Muhely_Imap::halmaz($hianyzik), self::ELEMEK));
                }

                $ossz = count($talalat);
            } else {
                $mappa = self::regebbiek($fk, $fiok, $mappa, $imap, $eltolas + self::OLDAL);
                $ossz  = (int) $mappa->osszes;

                // A megjelenő oldal jelzői mindig frissek (régi oldalon is, az ablakon kívül).
                $lap_uid = array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
                    "SELECT uid FROM {$t} WHERE mappa_id = %d AND uid >= %d ORDER BY uid DESC LIMIT %d OFFSET %d",
                    (int) $mappa->id,
                    (int) $mappa->min_uid,
                    self::OLDAL,
                    $eltolas
                )));

                if ($lap_uid !== [] && $oldal > 1) {
                    self::jelzok_frissit((int) $mappa->id, $imap, SDH_Muhely_Imap::halmaz($lap_uid), min($lap_uid), max($lap_uid));
                }

                // Előtöltés: az oldal leveleinek törzse a gyorsítótárba, hogy a megnyitás azonnali legyen.
                if ($lap_uid !== []) {
                    $lap_sorok = $wpdb->get_results("SELECT uid, meret FROM {$t} WHERE mappa_id = " . (int) $mappa->id . ' AND uid IN (' . implode(',', $lap_uid) . ') ORDER BY uid DESC');

                    self::elore_tolt($mappa, $imap, is_array($lap_sorok) ? $lap_sorok : []);
                }
            }

            $imap->kilep();
            delete_transient('sdh_level_hiba_' . $fk);
        } catch (\Throwable $hiba) {
            // Kapcsolat nélkül a gyorsítótár tartalma látszik.
            $hiba_szoveg = $hiba->getMessage();
            $ossz        = (int) $mappa->osszes;

            if ($q !== '') {
                wp_send_json_error(['uzenet' => 'A keresés a levelezőszerveren fut, és az most nem érhető el: ' . $hiba_szoveg]);
            }
        }

        if ($kereses_uid !== null) {
            $sorok = $kereses_uid === [] ? [] : $wpdb->get_results(
                "SELECT * FROM {$t} WHERE mappa_id = " . (int) $mappa->id . ' AND uid IN (' . implode(',', array_map('intval', $kereses_uid)) . ') ORDER BY uid DESC'
            );
        } else {
            $sorok = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$t} WHERE mappa_id = %d AND uid >= %d ORDER BY uid DESC LIMIT %d OFFSET %d",
                (int) $mappa->id,
                (int) $mappa->min_uid,
                self::OLDAL,
                $eltolas
            ));
        }

        wp_send_json_success([
            'sorok'   => array_map([self::class, 'sor_kifele'], is_array($sorok) ? $sorok : []),
            'ossz'    => $ossz,
            'oldal'   => $oldal,
            'oldalak' => max(1, (int) ceil($ossz / self::OLDAL)),
            'mappa'   => (int) $mappa->id,
            'fiokok'  => self::fiokok_kifele(),
            'hiba'    => $hiba_szoveg !== '' ? 'A levelezőszerver most nem érhető el – a legutóbb letöltött állapot látszik. (' . $hiba_szoveg . ')' : '',
        ]);
    }

    /* =================================================================
     * Egy levél
     * ============================================================== */

    /**
     * A levél sora a gyorsítótárból, ha a kérés hozzáférhet (létező, nyitott fiók).
     *
     * @return array{0: object, 1: object, 2: array<string, mixed>} sor, mappa, fiók
     */
    private static function level_kell(int $id): array
    {
        global $wpdb;

        $sor   = $id > 0 ? $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::tabla() . ' WHERE id = %d', $id)) : null;
        $mappa = is_object($sor) ? self::mappa((int) $sor->mappa_id) : null;

        if (!is_object($sor) || $mappa === null) {
            wp_send_json_error(['uzenet' => 'Ez a levél már nincs meg ebben a mappában. Frissítsd a listát.', 'eltunt' => true]);
        }

        return [$sor, $mappa, self::fiok_kell((string) $sor->fiok)];
    }

    /* ---- A levelek törzsének gyorsítótára: a megnyitás ne várjon a levelezőszerverre ---- */

    private static function tar_mappa(): string
    {
        $feltoltes = wp_upload_dir();
        $mappa     = trailingslashit($feltoltes['basedir']) . self::TAR_MAPPA;

        if (!is_dir($mappa)) {
            wp_mkdir_p($mappa);
        }

        $vedelem = [
            'index.php' => "<?php\n// Csend az aranyat ér.\n",
            '.htaccess' => "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
        ];

        foreach ($vedelem as $nev => $tartalom) {
            if (!file_exists($mappa . '/' . $nev)) {
                file_put_contents($mappa . '/' . $nev, $tartalom);
            }
        }

        return $mappa;
    }

    /** A levél fájlja: a név titkos kulccsal képzett lenyomat (kitalálhatatlan), a tartalom titkosított. */
    private static function tar_fajl(object $mappa, int $uid): string
    {
        $nev = hash_hmac('sha256', $mappa->fiok . '|' . (int) $mappa->id . '|' . (int) $mappa->uidvalidity . '|' . $uid, self::titok_kulcs());

        return self::tar_mappa() . '/' . substr($nev, 0, 40) . '.bin';
    }

    private static function tar_olvas(object $mappa, int $uid): string
    {
        $fajl = self::tar_fajl($mappa, $uid);

        if (!is_readable($fajl)) {
            return '';
        }

        $nyers = (string) file_get_contents($fajl);

        if (strlen($nyers) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return '';
        }

        $nyilt = sodium_crypto_secretbox_open(substr($nyers, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($nyers, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), self::titok_kulcs());
        $level = $nyilt === false ? false : @gzinflate($nyilt);

        return is_string($level) ? $level : '';
    }

    private static function tar_ir(object $mappa, int $uid, string $nyers): void
    {
        if ($nyers === '' || strlen($nyers) > self::TAR_MAX) {
            return;
        }

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        @file_put_contents(self::tar_fajl($mappa, $uid), $nonce . sodium_crypto_secretbox((string) gzdeflate($nyers, 4), $nonce, self::titok_kulcs()), LOCK_EX); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
    }

    private static function tar_van(object $mappa, int $uid): bool
    {
        return is_file(self::tar_fajl($mappa, $uid));
    }

    /** Naponta egyszer: a régi gyorsítótár-fájlok törlése. */
    private static function tar_takarit(): void
    {
        if (get_transient('sdh_level_takaritva')) {
            return;
        }

        set_transient('sdh_level_takaritva', 1, DAY_IN_SECONDS);

        $hatar = time() - self::TAR_NAP * DAY_IN_SECONDS;

        foreach (glob(self::tar_mappa() . '/*.bin') ?: [] as $fajl) {
            if ((int) @filemtime($fajl) < $hatar) {
                @unlink($fajl); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            }
        }
    }

    /**
     * Előtöltés: a megadott levelek törzse egyetlen kéréssel a gyorsítótárba, hogy a
     * megnyitásuk azonnali legyen. Csak a kisebb leveleket, és korlátos összmérettel.
     *
     * @param array<int, object> $sorok A gyorsítótár sorai (uid, meret).
     */
    private static function elore_tolt(object $mappa, SDH_Muhely_Imap $imap, array $sorok): int
    {
        $kell  = [];
        $ossz  = 0;

        foreach ($sorok as $s) {
            $meret = (int) $s->meret;

            if ($meret <= 0 || $meret > self::ELORE_LEVEL || $ossz + $meret > self::ELORE_OSSZ || self::tar_van($mappa, (int) $s->uid)) {
                continue;
            }

            $kell[] = (int) $s->uid;
            $ossz  += $meret;
        }

        if ($kell === []) {
            return 0;
        }

        $db = 0;

        foreach ($imap->nyers_levelek($kell) as $uid => $nyers) {
            self::tar_ir($mappa, (int) $uid, $nyers);
            $db++;
        }

        return $db;
    }

    /**
     * A teljes levél feldolgozva: a gyorsítótárból, ha megvan; különben a szerverről
     * (és onnan a gyorsítótárba). Ha a szerveren már nincs meg, a listából is törlődik.
     * A kapcsolat csak akkor épül fel, ha tényleg kell (`$imap` addig null).
     */
    private static function level_letolt(object $sor, object $mappa, array $fiok, ?SDH_Muhely_Imap &$imap = null): array
    {
        global $wpdb;

        $nyers = self::tar_olvas($mappa, (int) $sor->uid);

        if ($nyers === '') {
            $imap = $imap ?? self::imap($fiok);
            $imap->kivalaszt((string) $mappa->nyers);

            $nyers = $imap->nyers_level((int) $sor->uid);

            if ($nyers === '') {
                $wpdb->delete(self::tabla(), ['id' => (int) $sor->id]);

                throw new SDH_Muhely_Imap_Hiba('Ez a levél már nincs meg ebben a mappában (máshol törölték vagy áthelyezték).');
            }

            self::tar_ir($mappa, (int) $sor->uid, $nyers);
        }

        return SDH_Muhely_Mime::feldolgoz($nyers);
    }

    /**
     * A levél HTML-je biztonságos megjelenítéshez. A valódi védelem a homokozó
     * (sandbox: nincs szkript) és a tartalombiztonsági szabály (távoli tartalom
     * csak engedéllyel); ez a tisztítás a második vonal.
     *
     * @param array<int, array<string, mixed>> $csatolmanyok
     */
    private static function html_tisztit(string $html, array $csatolmanyok, bool &$tavoli): string
    {
        $veszelyes = 'script|iframe|frame|frameset|object|embed|applet|noscript|template|svg|math';

        $html = (string) preg_replace('~<(' . $veszelyes . ')\b[^>]*>.*?</\1\s*>~is', '', $html);
        $html = (string) preg_replace('~</?(' . $veszelyes . '|meta|base|link|title)\b[^>]*>~i', '', $html);
        $html = (string) preg_replace('~<!DOCTYPE[^>]*>~i', '', $html);
        $html = (string) preg_replace('~\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)~i', '', $html);
        $html = (string) preg_replace('~(href|src|action|formaction|background|xlink:href)\s*=\s*(["\']?)\s*(?:javascript|vbscript|data:text/html)[^"\'>\s]*~i', '$1=$2#', $html);

        // A levélbe ágyazott képek (cid:) a csatolmányokból kerülnek be.
        foreach ($csatolmanyok as $c) {
            if ((string) $c['cid'] !== '' && strncmp((string) $c['tipus'], 'image/', 6) === 0 && (int) $c['meret'] <= 3 * 1024 * 1024 && preg_match('~^image/(png|jpe?g|gif|webp|bmp)$~', (string) $c['tipus']) === 1) {
                $html = str_ireplace('cid:' . $c['cid'], 'data:' . $c['tipus'] . ';base64,' . base64_encode((string) $c['tartalom']), $html);
            }
        }

        $tavoli = preg_match('~(?:src|background)\s*=\s*["\']?\s*(?:https?:)?//~i', $html) === 1 || preg_match('~url\(\s*["\']?\s*(?:https?:)?//~i', $html) === 1;

        return $html;
    }

    /** A levél megjelenítő kerete (az iframe tartalma). */
    private static function keret(string $torzs, bool $kepek): string
    {
        $kep = $kepek ? 'data: https: http:' : 'data:';

        return '<!doctype html><html><head><meta charset="utf-8">'
            . '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; img-src ' . $kep . '; style-src \'unsafe-inline\'; font-src data:; media-src \'none\'; form-action \'none\'">'
            . '<meta name="referrer" content="no-referrer"><base target="_blank">'
            . '<style>html{background:#fff}body{margin:14px 16px;font:14px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;color:#1c1c1e;word-wrap:break-word;overflow-wrap:anywhere}'
            . 'img{max-width:100%;height:auto}table{max-width:100%}pre{white-space:pre-wrap}blockquote{margin:0 0 0 .6em;padding-left:.8em;border-left:3px solid #d0d0d5;color:#555}a{color:#0b57d0}'
            . '.sdh-szoveg{white-space:pre-wrap;font-family:inherit}</style></head><body>' . $torzs . '</body></html>';
    }

    public static function ajax_olvas(): void
    {
        global $wpdb;

        self::jog_ellenorzes();

        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $id    = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $kepek = !empty($_POST['kepek']);
        // phpcs:enable

        [$sor, $mappa, $fiok] = self::level_kell($id);

        $imap      = null;
        $jelolendo = false;

        try {
            // Gyorsítótárból a levél azonnal megvan: a szerverhez ilyenkor nem kapcsolódunk.
            $level = self::level_letolt($sor, $mappa, $fiok, $imap);

            // Megnyitáskor olvasottá válik – a Gmailben is.
            if ((int) $sor->olvasott !== 1) {
                if ($imap !== null) {
                    $imap->jelzo((string) (int) $sor->uid, true, ['\\Seen']);
                } else {
                    // A szerveren a böngésző külön, háttérben futó kérése jelöli meg (ajax_olvasva): a megjelenítés nem vár rá.
                    $jelolendo = true;
                }

                $wpdb->update(self::tabla(), ['olvasott' => 1], ['id' => (int) $sor->id]);
                $wpdb->query($wpdb->prepare('UPDATE ' . self::mappa_tabla() . ' SET olvasatlan = CASE WHEN olvasatlan > 0 THEN olvasatlan - 1 ELSE 0 END WHERE id = %d', (int) $mappa->id));
            }

            if ($imap !== null) {
                $imap->kilep();
            }
        } catch (\Throwable $hiba) {
            wp_send_json_error(['uzenet' => $hiba->getMessage(), 'eltunt' => strpos($hiba->getMessage(), 'már nincs meg') !== false]);
        }

        $tavoli = false;

        if (trim($level['html']) !== '') {
            $torzs = self::html_tisztit($level['html'], $level['csatolmanyok'], $tavoli);
        } else {
            $torzs = '<div class="sdh-szoveg">' . make_clickable(esc_html($level['szoveg'])) . '</div>';
        }

        $csat = [];

        foreach ($level['csatolmanyok'] as $n => $c) {
            // A levél szövegében megjelenő beágyazott kép nem külön csatolmány.
            if ($c['beagyazott'] && stripos($level['html'], 'cid:' . $c['cid']) !== false) {
                continue;
            }

            $csat[] = [
                'n'     => $n,
                'nev'   => (string) $c['nev'],
                'meret' => size_format((int) $c['meret'], (int) $c['meret'] >= 1048576 ? 1 : 0),
                'url'   => add_query_arg(['action' => 'sdh_muhely_level_csatolmany', 'id' => (int) $sor->id, 'n' => $n, '_wpnonce' => wp_create_nonce('sdh_muhely_modal')], admin_url('admin-ajax.php')),
            ];
        }

        if ((int) $sor->csatolmany !== ($csat !== [] ? 1 : 0)) {
            $wpdb->update(self::tabla(), ['csatolmany' => $csat !== [] ? 1 : 0], ['id' => (int) $sor->id]);
        }

        $fej    = $level['fejlec'];
        $ugyfel = self::ugyfel((string) $sor->felado_email);
        $t      = strtotime((string) $sor->datum);

        $ki               = self::sor_kifele($sor);
        $ki['olvasott']   = true;
        $ki['csatolmany'] = $csat !== [];

        wp_send_json_success($ki + [
            'felado_teljes' => (string) ($fej['from'] ?? ''),
            'cimzett_teljes' => (string) ($fej['to'] ?? ''),
            'masolat'       => (string) ($fej['cc'] ?? ''),
            'datum_teljes'  => $t !== false ? gmdate('Y. m. d. H:i', $t) : '',
            'keret'         => self::keret($torzs, $kepek),
            'tavoli_kep'    => $tavoli && !$kepek,
            'csatolmanyok'  => $csat,
            'ugynok'        => (string) $sor->ugynok,
            'jelolendo'     => $jelolendo,
            'szerep'        => (string) $mappa->szerep,
            'ugyfel'        => $ugyfel ? ['id' => (int) $ugyfel->id, 'nev' => (string) $ugyfel->nev] : null,
            'fiokok'        => self::fiokok_kifele(),
        ]);
    }

    /** A gyorsítótárból megnyitott levél megjelölése olvasottként a szerveren (háttérkérés). */
    public static function ajax_olvasva(): void
    {
        self::jog_ellenorzes();

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        [$sor, $mappa, $fiok] = self::level_kell(isset($_POST['id']) ? (int) $_POST['id'] : 0);

        try {
            $imap = self::imap($fiok);
            $imap->kivalaszt((string) $mappa->nyers);
            $imap->jelzo((string) (int) $sor->uid, true, ['\\Seen']);
            $imap->kilep();
        } catch (\Throwable $hiba) {
            wp_send_json_error(['uzenet' => $hiba->getMessage()]);
        }

        wp_send_json_success(['id' => (int) $sor->id]);
    }

    /** Csatolmány letöltése. Mindig letöltésként megy ki (soha nem nyílik meg a böngészőben a CRM nevében). */
    public static function ajax_csatolmany(): void
    {
        self::jog_ellenorzes(false);

        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        $n  = isset($_GET['n']) ? (int) $_GET['n'] : -1;
        // phpcs:enable

        [$sor, $mappa, $fiok] = self::level_kell($id);

        try {
            $imap  = null;
            $level = self::level_letolt($sor, $mappa, $fiok, $imap);

            if ($imap !== null) {
                $imap->kilep();
            }
        } catch (\Throwable $hiba) {
            wp_die(esc_html($hiba->getMessage()));
        }

        $c = $level['csatolmanyok'][$n] ?? null;

        if (!is_array($c)) {
            wp_die('Nincs ilyen csatolmány.');
        }

        $nev = sanitize_file_name((string) $c['nev']);
        $nev = $nev !== '' ? $nev : 'csatolmany.bin';

        nocache_headers();
        header('Content-Type: application/octet-stream');
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: attachment; filename="' . $nev . '"; filename*=UTF-8\'\'' . rawurlencode($nev));
        header('Content-Length: ' . strlen((string) $c['tartalom']));
        echo $c['tartalom']; // phpcs:ignore WordPress.Security.EscapeOutput -- bináris fájl.
        exit;
    }

    /* =================================================================
     * Műveletek a leveleken
     * ============================================================== */

    public static function ajax_muvelet(): void
    {
        global $wpdb;

        self::jog_ellenorzes();

        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $muvelet = isset($_POST['muvelet']) ? sanitize_key(wp_unslash($_POST['muvelet'])) : '';
        $idk     = isset($_POST['idk']) ? array_values(array_unique(array_filter(array_map('intval', explode(',', (string) wp_unslash($_POST['idk'])))))) : [];
        $cel_id  = isset($_POST['cel']) ? (int) $_POST['cel'] : 0;
        // phpcs:enable

        $ismert = ['olvasott', 'olvasatlan', 'csillag', 'csillag_le', 'kuka', 'vegleg', 'archiv', 'spam', 'nem_spam', 'athelyez'];

        if ($idk === [] || count($idk) > 200 || !in_array($muvelet, $ismert, true)) {
            wp_send_json_error(['uzenet' => 'Jelölj ki legalább egy levelet.']);
        }

        $sorok = $wpdb->get_results('SELECT * FROM ' . self::tabla() . ' WHERE id IN (' . implode(',', $idk) . ')');
        $sorok = is_array($sorok) ? $sorok : [];

        if ($sorok === []) {
            wp_send_json_error(['uzenet' => 'A kijelölt levelek már nincsenek meg. Frissítsd a listát.', 'eltunt' => true]);
        }

        // Egy kérés egy mappa leveleire vonatkozik.
        $mappa = self::mappa((int) $sorok[0]->mappa_id);
        $fk    = (string) $sorok[0]->fiok;
        $sorok = array_values(array_filter($sorok, static fn (object $s): bool => (int) $s->mappa_id === (int) $sorok[0]->mappa_id));

        if ($mappa === null) {
            wp_send_json_error(['uzenet' => 'Nincs ilyen mappa.']);
        }

        $fiok   = self::fiok_kell($fk);
        $halmaz = SDH_Muhely_Imap::halmaz(array_map(static fn (object $s): int => (int) $s->uid, $sorok));
        $sor_id = implode(',', array_map(static fn (object $s): int => (int) $s->id, $sorok));
        $szerep = (string) $mappa->szerep;
        $cel    = null;

        // Hová kerül a levél az áthelyező műveleteknél.
        if ($muvelet === 'kuka') {
            $cel = self::szerep_mappa($fk, 'trash');

            // A Kukából és a Piszkozatokból a törlés végleges.
            if ($szerep === 'trash' || $szerep === 'drafts' || $cel === null) {
                $muvelet = 'vegleg';
            }
        } elseif ($muvelet === 'archiv') {
            $cel = self::szerep_mappa($fk, 'all');

            if ($cel === null) {
                wp_send_json_error(['uzenet' => 'Ezen a fiókon nincs archívum („Összes levél") mappa.']);
            }
        } elseif ($muvelet === 'spam') {
            $cel = self::szerep_mappa($fk, 'junk');
        } elseif ($muvelet === 'nem_spam') {
            $cel = self::szerep_mappa($fk, 'inbox');
        } elseif ($muvelet === 'athelyez') {
            $cel = self::mappa($cel_id);

            if ($cel !== null && ((string) $cel->fiok !== $fk || (int) $cel->valaszthato !== 1)) {
                $cel = null;
            }
        }

        if (in_array($muvelet, ['kuka', 'archiv', 'spam', 'nem_spam', 'athelyez'], true) && ($cel === null || (int) $cel->id === (int) $mappa->id)) {
            wp_send_json_error(['uzenet' => $cel === null ? 'Nincs meg a célmappa.' : 'A levél már ebben a mappában van.']);
        }

        if ($muvelet === 'vegleg' && !in_array($szerep, ['trash', 'junk', 'drafts'], true) && self::szerep_mappa($fk, 'trash') !== null) {
            wp_send_json_error(['uzenet' => 'Véglegesen törölni csak a Kukából, a Spamből és a Piszkozatokból lehet.']);
        }

        try {
            $imap = self::imap($fiok);
            $imap->kivalaszt((string) $mappa->nyers);

            switch ($muvelet) {
                case 'olvasott':
                case 'olvasatlan':
                    $imap->jelzo($halmaz, $muvelet === 'olvasott', ['\\Seen']);
                    $wpdb->query('UPDATE ' . self::tabla() . ' SET olvasott = ' . ($muvelet === 'olvasott' ? 1 : 0) . " WHERE id IN ({$sor_id})");
                    break;

                case 'csillag':
                case 'csillag_le':
                    $imap->jelzo($halmaz, $muvelet === 'csillag', ['\\Flagged']);
                    $wpdb->query('UPDATE ' . self::tabla() . ' SET csillag = ' . ($muvelet === 'csillag' ? 1 : 0) . " WHERE id IN ({$sor_id})");
                    break;

                case 'vegleg':
                    $imap->vegleg_torol($halmaz);
                    $wpdb->query('DELETE FROM ' . self::tabla() . " WHERE id IN ({$sor_id})");
                    break;

                default:
                    $imap->athelyez($halmaz, (string) $cel->nyers);
                    $wpdb->query('DELETE FROM ' . self::tabla() . " WHERE id IN ({$sor_id})");
            }

            // A számlálók helyben igazodnak (a következő szinkron a szerver szerint pontosítja): így a
            // művelet nem vár három további kérésre.
            $db_ossz  = count($sorok);
            $db_olv   = count(array_filter($sorok, static fn (object $x): bool => (int) $x->olvasott !== 1));
            $m_tabla  = self::mappa_tabla();

            if ($muvelet === 'olvasott' || $muvelet === 'olvasatlan') {
                $kul = $muvelet === 'olvasott' ? -$db_olv : $db_ossz - $db_olv;
                $wpdb->query($wpdb->prepare("UPDATE {$m_tabla} SET olvasatlan = CASE WHEN olvasatlan + %d < 0 THEN 0 ELSE olvasatlan + %d END WHERE id = %d", $kul, $kul, (int) $mappa->id));
            } elseif ($muvelet !== 'csillag' && $muvelet !== 'csillag_le') {
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$m_tabla} SET osszes = CASE WHEN osszes < %d THEN 0 ELSE osszes - %d END, olvasatlan = CASE WHEN olvasatlan < %d THEN 0 ELSE olvasatlan - %d END WHERE id = %d",
                    $db_ossz,
                    $db_ossz,
                    $db_olv,
                    $db_olv,
                    (int) $mappa->id
                ));

                if ($cel !== null) {
                    $wpdb->query($wpdb->prepare("UPDATE {$m_tabla} SET osszes = osszes + %d, olvasatlan = olvasatlan + %d WHERE id = %d", $db_ossz, $db_olv, (int) $cel->id));
                }
            }

            $imap->kilep();
        } catch (\Throwable $hiba) {
            wp_send_json_error(['uzenet' => $hiba->getMessage()]);
        }

        wp_send_json_success(['fiokok' => self::fiokok_kifele(), 'db' => count($sorok), 'muvelet' => $muvelet]);
    }

    /** Az ügynök jelzésének lezárása („Elintézve") vagy visszanyitása – csak a CRM-ben, a postafiókot nem érinti. */
    public static function ajax_elintezve(): void
    {
        global $wpdb;

        self::jog_ellenorzes();

        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $be = !isset($_POST['be']) || !empty($_POST['be']);
        // phpcs:enable

        [$sor] = self::level_kell($id);

        $wpdb->update(self::tabla(), ['elintezve' => $be ? 1 : 0], ['id' => (int) $sor->id]);

        wp_send_json_success(['elintezve' => $be]);
    }

    /* =================================================================
     * Rendező ügynök: terv → jóváhagyás → végrehajtás (csak mappa létrehozása és áthelyezés)
     * ============================================================== */

    /** Be van-e kapcsolva a rendező ügynök (a gombja csak akkor látszik). */
    public static function rendezo_be(): bool
    {
        return !empty(self::beallitas()['rendezo']['be']);
    }

    /**
     * A rendező ügynök jogai ehhez a fiókhoz és mappához – vagy a hiba szövege, ha itt nem dolgozhat.
     *
     * @return array<string, mixed>|string
     */
    private static function rendezo_jogok(string $fk, ?object $mappa)
    {
        $j = self::beallitas()['rendezo'];

        if (empty($j['be'])) {
            return 'A rendező ügynök ki van kapcsolva (Beállítások → Levelezés → Rendező ügynök).';
        }

        if (empty($j['fiokok'][$fk])) {
            return 'A rendező ügynök ebben a fiókban nem dolgozhat (a Beállításokban nincs engedélyezve).';
        }

        if ($mappa === null || (string) $mappa->fiok !== $fk || (int) $mappa->valaszthato !== 1) {
            return 'Előbb nyiss meg egy mappát.';
        }

        if ((string) $j['forras'] === 'inbox' && (string) $mappa->szerep !== 'inbox') {
            return 'A rendező ügynök csak a Beérkezett mappából mozgathat (így van beállítva).';
        }

        return $j;
    }

    private static function rendezo_naplo(string $szoveg): void
    {
        $naplo   = get_option('sdh_muhely_level_naplo', []);
        $naplo   = is_array($naplo) ? $naplo : [];
        $naplo[] = ['ido' => current_time('mysql'), 'ki' => wp_get_current_user()->display_name, 'mit' => mb_substr($szoveg, 0, 300)];

        update_option('sdh_muhely_level_naplo', array_slice($naplo, -100), false);
    }

    /** A rendező ügynök ablaka. */
    public static function ajax_rendezo_urlap(): void
    {
        self::jog_ellenorzes(false);

        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $fk    = isset($_GET['fiok']) ? sanitize_key(wp_unslash($_GET['fiok'])) : '';
        $mappa = self::mappa(isset($_GET['mappa']) ? (int) $_GET['mappa'] : 0);
        $idk   = isset($_GET['idk']) ? array_values(array_filter(array_map('intval', explode(',', (string) wp_unslash($_GET['idk']))))) : [];
        // phpcs:enable

        $fiok = self::fiokok()[$fk] ?? null;
        $j    = is_array($fiok) && !self::zarva($fk) ? self::rendezo_jogok($fk, $mappa) : 'Ez a fiók nincs megnyitva.';

        echo '<h2 class="sdh-modal__cim">Rendező ügynök';

        if (is_array($fiok) && $mappa !== null) {
            echo ' <span class="sdh-modal__cim-megj">' . esc_html(self::fiok_nev($fiok) . ' · ' . self::mappa_nev($mappa)) . '</span>';
        }

        echo '</h2>';

        if (!is_array($j)) {
            echo '<div class="sdh-uzenet sdh-uzenet--hiba">' . esc_html($j) . '</div>';
            wp_die();
        }

        $b      = self::beallitas();
        $van_ai = self::visszafejt((string) $b['ugynok']['ai_kulcs']) !== '';
        $naplo  = get_option('sdh_muhely_level_naplo', []);
        $naplo  = array_reverse(array_slice(is_array($naplo) ? $naplo : [], -3));

        ?>
        <p class="sdh-modal__alcim">
            Megmondod, mit rendezzen; megmutatja, mely leveleket hová tenné; és csak azt hajtja végre, amit jóváhagysz.
            Leveleket áthelyezni és (engedéllyel) mappát létrehozni tud – semmi mást.
        </p>

        <form class="sdh-urlap sdh-rendezo" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
              data-sdh-ajax-action="sdh_muhely_level_rendezo_terv" data-sdh-rendezo data-lepes="keres">
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('sdh_muhely_modal')); ?>">
            <input type="hidden" name="fiok" value="<?php echo esc_attr($fk); ?>">
            <input type="hidden" name="mappa" value="<?php echo (int) $mappa->id; ?>">
            <input type="hidden" name="idk" value="<?php echo esc_attr(implode(',', $idk)); ?>">

            <ul class="sdh-rendezo__jogok" data-rendezo-jogok aria-label="Amit az ügynök tehet">
                <?php foreach (SDH_Muhely_Level_Rendezo::jogok_szoveg($j) as [$szabad, $szoveg]) : ?>
                    <li class="<?php echo $szabad ? 'is-szabad' : 'is-tilos'; ?>"><?php echo esc_html(($szabad ? '✓ ' : '✗ ') . $szoveg); ?></li>
                <?php endforeach; ?>
            </ul>

            <div data-rendezo-lepes="keres">
                <?php if ($van_ai) : ?>
                    <textarea name="keres" class="sdh-rendezo__keres" rows="3" maxlength="1000" aria-label="Mit rendezzen az ügynök"
                              placeholder="Pl. A hírleveleket és a reklámokat tedd a Hírlevelek mappába. A beszállítói számlákat a Számlák mappába."></textarea>
                <?php else : ?>
                    <p class="sdh-rendezo__megj">
                        Szabad szavas kéréshez Claude API-kulcs kell (Beállítások → Levelezés). Kulcs nélkül szűrővel dolgozik:
                    </p>
                    <div class="sdh-ugyfelurlap">
                        <div class="sdh-ig">
                            <label for="rendezo_felado">A feladó tartalmazza</label>
                            <input type="text" name="szuro_felado" id="rendezo_felado" maxlength="120" autocomplete="off" placeholder="pl. hirlevel@ vagy bolt.hu">
                        </div>
                        <div class="sdh-ig">
                            <label for="rendezo_targy">A tárgy tartalmazza</label>
                            <input type="text" name="szuro_targy" id="rendezo_targy" maxlength="120" autocomplete="off" placeholder="pl. számla">
                        </div>
                        <div class="sdh-ig">
                            <label for="rendezo_cel">Ebbe a mappába</label>
                            <input type="text" name="szuro_cel" id="rendezo_cel" maxlength="80" autocomplete="off" placeholder="meglévő vagy új mappa neve">
                        </div>
                    </div>
                <?php endif; ?>

                <p class="sdh-rendezo__megj" data-rendezo-hatokor>
                    <?php
                    echo esc_html($idk !== []
                        ? 'Hatókör: a kijelölt ' . count($idk) . ' levél.'
                        : 'Hatókör: a megnyitott mappa (' . self::mappa_nev($mappa) . ') legújabb, legfeljebb ' . SDH_Muhely_Level_Rendezo::LEVEL_MAX . ' letöltött levele. Kevesebbhez jelöld ki a leveleket a listában.');
                    ?>
                </p>
            </div>

            <div data-rendezo-lepes="terv" hidden></div>
            <div data-rendezo-lepes="eredmeny" hidden></div>

            <?php if ($naplo !== []) : ?>
                <p class="sdh-rendezo__naplo" data-rendezo-naplo>
                    Legutóbb: <?php echo esc_html(implode(' · ', array_map(static fn (array $n): string => mysql2date('m. d. H:i', (string) $n['ido']) . ' ' . $n['mit'], $naplo))); ?>
                </p>
            <?php endif; ?>

            <div class="sdh-urlap__lablec">
                <button type="submit" class="sdh-gomb sdh-gomb--elsodleges" data-rendezo-gomb>Terv készítése</button>
                <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-rendezo-vissza hidden>Vissza</button>
                <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-megsem>Mégsem</button>
            </div>
        </form>
        <?php

        wp_die();
    }

    /** A terv a böngészőnek: csoportonként a célmappa, a darabszám és néhány levél mintának. */
    private static function rendezo_terv_kifele(array $terv, array $levelek): array
    {
        $szerint = [];

        foreach ($levelek as $l) {
            $szerint[(int) $l['id']] = $l;
        }

        $csoportok = [];

        foreach ($terv['csoportok'] as $i => $cs) {
            $csoportok[] = [
                'i'     => $i,
                'cel'   => $cs['cel_nev'],
                'uj'    => $cs['uj'],
                'db'    => count($cs['idk']),
                'minta' => array_map(static fn (int $id): array => ['felado' => (string) $szerint[$id]['felado'], 'targy' => (string) $szerint[$id]['targy']], array_slice($cs['idk'], 0, 4)),
            ];
        }

        return ['uzenet' => $terv['uzenet'], 'uj_mappak' => $terv['uj_mappak'], 'csoportok' => $csoportok, 'kihagyva' => $terv['kihagyva'], 'db' => $terv['db']];
    }

    /** Terv készítése a kérésből. Semmit nem hajt végre – kivéve, ha a jóváhagyás ki van kapcsolva, és nem kell új mappa. */
    public static function ajax_rendezo_terv(): void
    {
        global $wpdb;

        self::jog_ellenorzes();

        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $szoveg = static fn (string $k, int $h): string => isset($_POST[$k]) && is_scalar($_POST[$k]) ? trim(mb_substr(sanitize_textarea_field(wp_unslash((string) $_POST[$k])), 0, $h)) : '';
        $fk     = isset($_POST['fiok']) ? sanitize_key(wp_unslash($_POST['fiok'])) : '';
        $mappa  = self::mappa(isset($_POST['mappa']) ? (int) $_POST['mappa'] : 0);
        $idk    = isset($_POST['idk']) ? array_values(array_unique(array_filter(array_map('intval', explode(',', (string) wp_unslash($_POST['idk'])))))) : [];
        // phpcs:enable

        $fiok = self::fiok_kell($fk);
        $j    = self::rendezo_jogok($fk, $mappa);

        if (!is_array($j)) {
            wp_send_json_error(['uzenet' => $j]);
        }

        // Amit az ügynök láthat: a kijelölt levelek, vagy a mappa legújabb letöltött levelei.
        $t     = self::tabla();
        $sorok = $idk !== []
            ? $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t} WHERE mappa_id = %d AND id IN (" . implode(',', array_slice($idk, 0, SDH_Muhely_Level_Rendezo::LEVEL_MAX)) . ') ORDER BY uid DESC', (int) $mappa->id))
            : $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t} WHERE mappa_id = %d AND uid >= %d ORDER BY uid DESC LIMIT %d", (int) $mappa->id, (int) $mappa->min_uid, SDH_Muhely_Level_Rendezo::LEVEL_MAX));

        $levelek = array_map([self::class, 'sor_kifele'], is_array($sorok) ? $sorok : []);

        if ($levelek === []) {
            wp_send_json_error(['uzenet' => 'Ebben a mappában nincs (letöltött) levél, amit rendezni lehetne.']);
        }

        $mappak = self::mappak_kifele($fk);
        $b      = self::beallitas();
        $kulcs  = self::visszafejt((string) $b['ugynok']['ai_kulcs']);
        $keres  = $szoveg('keres', 1000);

        @set_time_limit(120); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

        if ($kulcs !== '' && $szoveg('szuro_cel', 80) === '') {
            $nyers = SDH_Muhely_Level_Rendezo::terv_ai(
                $keres,
                $levelek,
                array_map(static fn (array $m): string => (string) $m['teljes'], array_filter($mappak, static fn (array $m): bool => !empty($m['valaszthato']))),
                self::mappa_nev($mappa),
                $j,
                ['kulcs' => $kulcs, 'modell' => (string) $b['ugynok']['ai_modell']]
            );
        } else {
            $keres = 'Szűrő: feladó „' . $szoveg('szuro_felado', 120) . '", tárgy „' . $szoveg('szuro_targy', 120) . '" → ' . $szoveg('szuro_cel', 80);
            $nyers = SDH_Muhely_Level_Rendezo::terv_szuro(['felado' => $szoveg('szuro_felado', 120), 'targy' => $szoveg('szuro_targy', 120), 'cel' => $szoveg('szuro_cel', 80)], $levelek);
        }

        if (!is_array($nyers)) {
            wp_send_json_error(['uzenet' => $nyers]);
        }

        // Bármit javasolt is a modell, innen csak az megy tovább, amit a beállított jogok megengednek.
        $terv = SDH_Muhely_Level_Rendezo::ellenoriz($nyers, $levelek, $mappak, (int) $mappa->id, $j);

        $token = wp_generate_password(32, false);

        set_transient('sdh_level_terv_' . $token, [
            'felhasznalo' => get_current_user_id(),
            'fiok'        => $fk,
            'mappa'       => (int) $mappa->id,
            'keres'       => $keres,
            'terv'        => $terv,
        ], 20 * MINUTE_IN_SECONDS);

        $ki = ['token' => $token, 'terv' => self::rendezo_terv_kifele($terv, $levelek), 'jovahagyas' => !empty($j['jovahagyas'])];

        // Jóváhagyás nélküli mód: ami meglévő mappába megy, az rögtön végrehajtódik; az új mappához így is engedély kell.
        if (empty($j['jovahagyas']) && $terv['csoportok'] !== []) {
            $meglevo = array_keys(array_filter($terv['csoportok'], static fn (array $cs): bool => !$cs['uj']));

            if ($meglevo !== []) {
                $ki['eredmeny'] = self::rendezo_vegrehajt($token, [], $meglevo, $terv['uj_mappak'] === []);
                $ki['fiokok']   = self::fiokok_kifele();
            }
        }

        wp_send_json_success($ki);
    }

    public static function ajax_rendezo_vegrehajt(): void
    {
        self::jog_ellenorzes();

        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $token    = isset($_POST['token']) ? (string) preg_replace('/[^A-Za-z0-9]/', '', (string) wp_unslash($_POST['token'])) : '';
        $uj       = isset($_POST['uj']) && is_array($_POST['uj']) ? array_map(static fn ($n): string => trim(sanitize_text_field(wp_unslash((string) $n))), $_POST['uj']) : [];
        $csoport  = isset($_POST['csoport']) && is_array($_POST['csoport']) ? array_map('intval', $_POST['csoport']) : [];
        // phpcs:enable

        $eredmeny = self::rendezo_vegrehajt($token, $uj, $csoport, true);

        wp_send_json_success(['eredmeny' => $eredmeny, 'fiokok' => self::fiokok_kifele()]);
    }

    /**
     * A jóváhagyott terv végrehajtása. Ez a függvény a levelezőszerveren KIZÁRÓLAG két
     * műveletet végez: mappát hoz létre (mappa_letrehoz) és levelet helyez át (athelyez).
     *
     * @param array<int, string> $engedelyezett_uj A felhasználó által engedélyezett új mappák neve.
     * @param array<int, int>    $csoportok        A jóváhagyott csoportok sorszáma a tervben.
     * @return array<int, string> Mi történt (a felhasználónak).
     */
    private static function rendezo_vegrehajt(string $token, array $engedelyezett_uj, array $csoportok, bool $lezar): array
    {
        global $wpdb;

        $adat = $token !== '' ? get_transient('sdh_level_terv_' . $token) : false;

        if (!is_array($adat) || (int) $adat['felhasznalo'] !== get_current_user_id()) {
            wp_send_json_error(['uzenet' => 'Ez a terv lejárt vagy már végre lett hajtva. Készíts újat.']);
        }

        $fk    = (string) $adat['fiok'];
        $fiok  = self::fiok_kell($fk);
        $mappa = self::mappa((int) $adat['mappa']);
        // A jogok a végrehajtás pillanatában is érvényesek kell legyenek (közben kikapcsolhatták).
        $j     = self::rendezo_jogok($fk, $mappa);

        if (!is_array($j) || empty($j['mozgathat'])) {
            wp_send_json_error(['uzenet' => is_array($j) ? 'A levelek áthelyezése nincs engedélyezve.' : $j]);
        }

        $terv     = $adat['terv'];
        $eredmeny = [];
        $maradt   = $terv['csoportok'];

        @set_time_limit(120); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

        try {
            $imap = self::imap($fiok);

            // 1. Új mappák – csak amit a terv tartalmaz ÉS a felhasználó most engedélyezett.
            $letrejott = [];

            foreach ($terv['uj_mappak'] as $nev) {
                if (!in_array($nev, $engedelyezett_uj, true) || empty($j['mappat'])) {
                    continue;
                }

                try {
                    $imap->mappa_letrehoz($nev);
                    $eredmeny[] = 'Új mappa létrehozva: ' . $nev . '.';
                    self::rendezo_naplo('új mappa: ' . $nev);
                } catch (SDH_Muhely_Imap_Hiba $hiba) {
                    // Ha közben valaki már létrehozta, az nem hiba: a levelek mehetnek bele.
                    if (stripos($hiba->getMessage(), 'exist') === false && stripos($hiba->getMessage(), 'duplicate') === false) {
                        $eredmeny[] = '„' . $nev . '" mappát nem sikerült létrehozni: ' . $hiba->getMessage();

                        continue;
                    }
                }

                $letrejott[] = $nev;
            }

            if ($letrejott !== []) {
                self::mappak_frissit($fk, $imap, false);
            }

            // 2. Áthelyezés – csoportonként egy kéréssel.
            $imap->kivalaszt((string) $mappa->nyers);

            foreach ($terv['csoportok'] as $i => $cs) {
                if (!in_array((int) $i, $csoportok, true)) {
                    continue;
                }

                $cel = null;

                if ($cs['uj']) {
                    if (!in_array($cs['cel_nev'], $letrejott, true)) {
                        $eredmeny[] = '„' . $cs['cel_nev'] . '": a mappa létrehozása nem volt engedélyezve, a levelek a helyükön maradtak.';

                        continue;
                    }

                    foreach (self::mappak($fk) as $m) {
                        if ((string) $m->nev === $cs['cel_nev']) {
                            $cel = $m;
                        }
                    }
                } else {
                    $cel = self::mappa((int) $cs['cel_id']);
                }

                // A célt végrehajtáskor újra ellenőrizzük (a terv és a végrehajtás között a mappák változhattak).
                if ($cel === null || (string) $cel->fiok !== $fk || (int) $cel->valaszthato !== 1 || (int) $cel->id === (int) $mappa->id
                    || in_array((string) $cel->szerep, ['drafts', 'sent', 'flagged', 'important'], true)
                    || (in_array((string) $cel->szerep, ['trash', 'junk'], true) && empty($j['kukaba']))) {
                    $eredmeny[] = '„' . $cs['cel_nev'] . '": ebbe a mappába most nem lehet áthelyezni.';

                    continue;
                }

                $sorok = $wpdb->get_results('SELECT id, uid, olvasott FROM ' . self::tabla() . ' WHERE mappa_id = ' . (int) $mappa->id . ' AND id IN (' . implode(',', array_map('intval', $cs['idk'])) . ')');
                $sorok = is_array($sorok) ? $sorok : [];

                if ($sorok === []) {
                    $eredmeny[] = '„' . $cs['cel_nev'] . '": a levelek már nincsenek ebben a mappában.';
                    unset($maradt[$i]);

                    continue;
                }

                $imap->athelyez(SDH_Muhely_Imap::halmaz(array_map(static fn (object $s): int => (int) $s->uid, $sorok)), (string) $cel->nyers);

                $db      = count($sorok);
                $db_olv  = count(array_filter($sorok, static fn (object $s): bool => (int) $s->olvasott !== 1));
                $m_tabla = self::mappa_tabla();

                $wpdb->query('DELETE FROM ' . self::tabla() . ' WHERE id IN (' . implode(',', array_map(static fn (object $s): int => (int) $s->id, $sorok)) . ')');
                $wpdb->query($wpdb->prepare("UPDATE {$m_tabla} SET osszes = CASE WHEN osszes < %d THEN 0 ELSE osszes - %d END, olvasatlan = CASE WHEN olvasatlan < %d THEN 0 ELSE olvasatlan - %d END WHERE id = %d", $db, $db, $db_olv, $db_olv, (int) $mappa->id));
                $wpdb->query($wpdb->prepare("UPDATE {$m_tabla} SET osszes = osszes + %d, olvasatlan = olvasatlan + %d WHERE id = %d", $db, $db_olv, (int) $cel->id));

                $eredmeny[] = $db . ' levél áthelyezve ide: ' . self::mappa_nev($cel) . '.';
                self::rendezo_naplo($db . ' levél → ' . self::mappa_nev($cel) . ' (' . mb_substr((string) $adat['keres'], 0, 120) . ')');
                unset($maradt[$i]);
            }

            $imap->kilep();
        } catch (\Throwable $hiba) {
            $eredmeny[] = 'A végrehajtás megszakadt: ' . $hiba->getMessage();
        }

        if ($lezar) {
            delete_transient('sdh_level_terv_' . $token);
        }

        return $eredmeny !== [] ? $eredmeny : ['Nem volt végrehajtandó lépés.'];
    }

    /* =================================================================
     * Levélírás
     * ============================================================== */

    /** A kimenő levelek küldője (a WordPress saját PHPMailer-példánya helyett külön, fiókonként beállítva). */
    private static function levelkuldo(array $fiok): \PHPMailer\PHPMailer\PHPMailer
    {
        if (!class_exists('\PHPMailer\PHPMailer\PHPMailer')) {
            require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
            require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
            require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
        }

        $m = new \PHPMailer\PHPMailer\PHPMailer(true);

        $m->isSMTP();
        $m->Host        = (string) $fiok['smtp_host'];
        $m->Port        = (int) $fiok['smtp_port'];
        $m->SMTPAuth    = true;
        $m->Username    = trim((string) $fiok['felhasznalo']) !== '' ? (string) $fiok['felhasznalo'] : (string) $fiok['email'];
        $m->Password    = self::visszafejt((string) $fiok['jelszo']);
        $m->SMTPSecure  = (string) $fiok['smtp_titk'] === 'nincs' ? '' : ((string) $fiok['smtp_titk'] === 'tls' ? 'tls' : 'ssl');
        $m->SMTPAutoTLS = (string) $fiok['smtp_titk'] !== 'nincs';
        $m->Timeout     = 25;
        $m->CharSet     = 'UTF-8';
        $m->Encoding    = 'base64';
        $m->XMailer     = ' ';
        $m->setFrom((string) $fiok['email'], self::fiok_nev($fiok), false);

        return $m;
    }

    private static function valasz_targy(string $targy, string $elotag): string
    {
        $targy = trim($targy);

        return preg_match('/^' . preg_quote($elotag, '/') . '\s*:/i', $targy) === 1 ? $targy : $elotag . ': ' . $targy;
    }

    /** A levélíró ablak (új levél, válasz, továbbítás, piszkozat folytatása). */
    public static function ajax_urlap(): void
    {
        self::jog_ellenorzes(false);
        self::konyvtarak();

        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $id   = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        $mod  = isset($_GET['mod']) ? sanitize_key(wp_unslash($_GET['mod'])) : 'uj';
        $mod  = in_array($mod, ['uj', 'valasz', 'mindenkinek', 'tovabbit', 'piszkozat'], true) ? $mod : 'uj';
        $fk   = isset($_GET['fiok']) ? sanitize_key(wp_unslash($_GET['fiok'])) : '';
        $kinek = isset($_GET['cimzett']) && is_email(wp_unslash($_GET['cimzett'])) ? sanitize_email(wp_unslash($_GET['cimzett'])) : '';
        // phpcs:enable

        $nyitott = array_filter(self::fiokok(), static fn ($k): bool => !self::zarva((string) $k), ARRAY_FILTER_USE_KEY);

        if ($nyitott === []) {
            echo '<h2 class="sdh-modal__cim">Új levél</h2><div class="sdh-uzenet sdh-uzenet--hiba">Nincs megnyitott e-mail-fiók. Állítsd be a Beállítások → Levelezés alatt, vagy nyisd meg a jelszavával.</div>';
            wp_die();
        }

        $adat  = ['cimzett' => $kinek, 'masolat' => '', 'titkos' => '', 'targy' => '', 'szoveg' => '', 'csatolmanyok' => []];
        $cim   = 'Új levél';

        if ($id > 0 && $mod !== 'uj') {
            global $wpdb;

            $sor   = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::tabla() . ' WHERE id = %d', $id));
            $mappa = is_object($sor) ? self::mappa((int) $sor->mappa_id) : null;

            if (!is_object($sor) || $mappa === null || !isset($nyitott[(string) $sor->fiok])) {
                echo '<h2 class="sdh-modal__cim">Levél</h2><div class="sdh-uzenet sdh-uzenet--hiba">Ez a levél már nincs meg, vagy a fiókja zárva van.</div>';
                wp_die();
            }

            $fk = (string) $sor->fiok;

            try {
                $imap  = null;
                $level = self::level_letolt($sor, $mappa, $nyitott[$fk], $imap);

                if ($imap !== null) {
                    $imap->kilep();
                }
            } catch (\Throwable $hiba) {
                echo '<h2 class="sdh-modal__cim">Levél</h2><div class="sdh-uzenet sdh-uzenet--hiba">' . esc_html($hiba->getMessage()) . '</div>';
                wp_die();
            }

            $fej     = $level['fejlec'];
            $szoveg  = SDH_Muhely_Mime::olvashato($level);
            $sajat   = strtolower((string) $nyitott[$fk]['email']);
            $cimek   = static fn (string $f): array => SDH_Muhely_Mime::cimek($f);
            $lista   = static fn (array $c): string => implode(', ', array_map(static fn (array $x): string => $x['email'], $c));
            $t       = strtotime((string) $sor->datum);
            $mikor   = $t !== false ? gmdate('Y. m. d. H:i', $t) : '';

            if ($mod === 'piszkozat') {
                $cim  = 'Piszkozat';
                $adat = [
                    'cimzett' => $lista($cimek((string) ($fej['to'] ?? ''))), 'masolat' => $lista($cimek((string) ($fej['cc'] ?? ''))), 'titkos' => $lista($cimek((string) ($fej['bcc'] ?? ''))),
                    'targy' => (string) ($fej['subject'] ?? ''), 'szoveg' => $szoveg, 'csatolmanyok' => $level['csatolmanyok'],
                ];
            } elseif ($mod === 'tovabbit') {
                $cim  = 'Továbbítás';
                $adat['targy']        = self::valasz_targy((string) ($fej['subject'] ?? ''), 'Fwd');
                $adat['szoveg']       = "\n\n---------- Továbbított levél ----------\nFeladó: " . ($fej['from'] ?? '') . "\nDátum: " . $mikor . "\nTárgy: " . ($fej['subject'] ?? '')
                    . "\nCímzett: " . ($fej['to'] ?? '') . "\n\n" . $szoveg;
                $adat['csatolmanyok'] = $level['csatolmanyok'];
            } else {
                $cim     = $mod === 'mindenkinek' ? 'Válasz mindenkinek' : 'Válasz';
                $valasz  = $cimek((string) ($fej['reply-to'] ?? '')) ?: $cimek((string) ($fej['from'] ?? ''));

                // A saját elküldött levelünkre adott „válasz" az eredeti címzettnek megy.
                if ($valasz !== [] && $valasz[0]['email'] === $sajat) {
                    $valasz = $cimek((string) ($fej['to'] ?? ''));
                }

                $adat['cimzett'] = $lista($valasz);

                if ($mod === 'mindenkinek') {
                    $mar    = array_merge([$sajat], array_map(static fn (array $x): string => $x['email'], $valasz));
                    $tobbi  = array_filter(array_merge($cimek((string) ($fej['to'] ?? '')), $cimek((string) ($fej['cc'] ?? ''))), static fn (array $x): bool => !in_array($x['email'], $mar, true));
                    $adat['masolat'] = $lista(array_values($tobbi));
                }

                $idezet = implode("\n", array_map(static fn (string $s): string => '> ' . $s, preg_split('/\r?\n/', trim($szoveg)) ?: []));

                $adat['targy']  = self::valasz_targy((string) ($fej['subject'] ?? ''), 'Re');
                $adat['szoveg'] = "\n\n" . $mikor . ', ' . ($fej['from'] ?? '') . " írta:\n" . $idezet;
            }
        }

        if (!isset($nyitott[$fk])) {
            $fk = (string) array_key_first($nyitott);
        }

        // Az aláírás a szöveg elé kerül (válasznál az idézet fölé), piszkozatnál már benne van.
        $alairasok = [];

        foreach ($nyitott as $k => $f) {
            $alairasok[$k] = trim((string) $f['alairas']);
        }

        if ($mod !== 'piszkozat' && $alairasok[$fk] !== '') {
            $adat['szoveg'] = "\n\n" . $alairasok[$fk] . $adat['szoveg'];
        }

        ?>
        <h2 class="sdh-modal__cim"><?php echo esc_html($cim); ?></h2>

        <form class="sdh-urlap sdh-leveliro" method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
              data-sdh-ajax-action="sdh_muhely_level_kuld" data-sdh-leveliro>
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('sdh_muhely_modal')); ?>">
            <input type="hidden" name="eredeti" value="<?php echo (int) ($mod === 'uj' ? 0 : $id); ?>">
            <input type="hidden" name="mod" value="<?php echo esc_attr($mod); ?>">
            <input type="hidden" name="piszkozat" value="0" data-sdh-level-piszkozat-jel>

            <div class="sdh-ugyfelurlap sdh-leveliro__mezok">
                <div class="sdh-ig">
                    <label for="level_fiok">Feladó</label>
                    <select name="fiok" id="level_fiok" <?php echo $mod === 'uj' ? '' : 'data-sdh-rogzitett'; ?>>
                        <?php foreach ($nyitott as $k => $f) : ?>
                            <?php // Válasznál és piszkozatnál a levél a saját fiókjából megy. ?>
                            <?php if ($mod !== 'uj' && $k !== $fk) { continue; } ?>
                            <option value="<?php echo esc_attr((string) $k); ?>" <?php selected($fk, $k); ?> data-alairas="<?php echo esc_attr($alairasok[$k]); ?>">
                                <?php echo esc_html(self::fiok_nev($f) . ' <' . $f['email'] . '>'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="sdh-ig">
                    <label for="level_cimzett">Címzett</label>
                    <input type="text" name="cimzett" id="level_cimzett" autocomplete="off" required placeholder="cim@pelda.hu – több címet vesszővel válassz el"
                           value="<?php echo esc_attr($adat['cimzett']); ?>">
                </div>

                <div class="sdh-ig">
                    <label for="level_masolat">Másolat</label>
                    <span class="sdh-leveliro__ketto">
                        <input type="text" name="masolat" id="level_masolat" autocomplete="off" placeholder="Másolat (Cc)" value="<?php echo esc_attr($adat['masolat']); ?>">
                        <input type="text" name="titkos" autocomplete="off" placeholder="Titkos másolat (Bcc)" aria-label="Titkos másolat" value="<?php echo esc_attr($adat['titkos']); ?>">
                    </span>
                </div>

                <div class="sdh-ig">
                    <label for="level_targy">Tárgy</label>
                    <input type="text" name="targy" id="level_targy" autocomplete="off" maxlength="250" value="<?php echo esc_attr($adat['targy']); ?>">
                </div>

                <textarea name="szoveg" class="sdh-leveliro__szoveg" aria-label="A levél szövege" rows="12"><?php echo esc_textarea($adat['szoveg']); ?></textarea>

                <div class="sdh-ig">
                    <label for="level_csatolmany">Csatolmány</label>
                    <span class="sdh-leveliro__csat">
                        <input type="file" name="csatolmany[]" id="level_csatolmany" multiple>
                        <?php foreach ($adat['csatolmanyok'] as $n => $c) : ?>
                            <?php if (!empty($c['beagyazott'])) { continue; } ?>
                            <label class="sdh-jelolo" title="Az eredeti levél csatolmánya">
                                <input type="checkbox" name="eredeti_csat[]" value="<?php echo (int) $n; ?>" checked>
                                <?php echo esc_html((string) $c['nev']); ?>
                            </label>
                        <?php endforeach; ?>
                    </span>
                </div>

                <div class="sdh-urlap__lablec">
                    <button type="submit" class="sdh-gomb sdh-gomb--elsodleges">Küldés</button>
                    <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-level-piszkozat>Mentés piszkozatként</button>
                    <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-sdh-megsem>Mégsem</button>
                </div>
            </div>
        </form>
        <?php

        wp_die();
    }

    /**
     * Címlista a beírt szövegből; hibás cím esetén null.
     *
     * @return array<int, array{nev: string, email: string}>|null
     */
    private static function cimlista(string $szoveg): ?array
    {
        $ki = [];

        foreach (preg_split('/[;,]+(?=(?:[^"]*"[^"]*")*[^"]*$)/', $szoveg) ?: [] as $darab) {
            $darab = trim($darab);

            if ($darab === '') {
                continue;
            }

            $cimek = SDH_Muhely_Mime::cimek($darab);

            if (count($cimek) !== 1 || !is_email($cimek[0]['email'])) {
                return null;
            }

            // A megjelenő névben nem maradhat semmi, ami a fejlécet megtörné.
            $cimek[0]['nev'] = trim(mb_substr((string) preg_replace('/[\x00-\x1f\x7f<>"]+/', ' ', $cimek[0]['nev']), 0, 120));
            $ki[]            = $cimek[0];
        }

        return $ki;
    }

    /** Levél küldése vagy mentése piszkozatként. */
    public static function ajax_kuld(): void
    {
        global $wpdb;

        self::jog_ellenorzes();
        self::konyvtarak();

        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $szoveg_mezo = static fn (string $k, int $h): string => isset($_POST[$k]) && is_scalar($_POST[$k]) ? trim(mb_substr(sanitize_text_field(wp_unslash((string) $_POST[$k])), 0, $h)) : '';

        // A címmezőkben a „Név <cim@pelda.hu>" alak hegyes zárójele nem HTML: csak a vezérlőkarakterek
        // (sortörés – fejléc-befecskendezés) maradnak ki, a címeket a cimlista() ellenőrzi egyenként.
        $cim_mezo    = static fn (string $k): string => isset($_POST[$k]) && is_scalar($_POST[$k])
            ? trim(mb_substr((string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', wp_check_invalid_utf8((string) wp_unslash($_POST[$k]))), 0, 2000))
            : '';

        $fk         = isset($_POST['fiok']) ? sanitize_key(wp_unslash($_POST['fiok'])) : '';
        $mod        = $szoveg_mezo('mod', 12);
        $eredeti_id = isset($_POST['eredeti']) ? (int) $_POST['eredeti'] : 0;
        $piszkozat  = !empty($_POST['piszkozat']);
        $targy      = $szoveg_mezo('targy', 250);
        $torzs      = isset($_POST['szoveg']) && is_scalar($_POST['szoveg']) ? str_replace(["\r\n", "\r"], "\n", (string) wp_unslash($_POST['szoveg'])) : '';
        $torzs      = wp_check_invalid_utf8($torzs);
        $er_csat    = isset($_POST['eredeti_csat']) && is_array($_POST['eredeti_csat']) ? array_map('intval', $_POST['eredeti_csat']) : [];
        // phpcs:enable

        $fiok = self::fiok_kell($fk);

        $cimzettek = self::cimlista($cim_mezo('cimzett'));
        $masolat   = self::cimlista($cim_mezo('masolat'));
        $titkos    = self::cimlista($cim_mezo('titkos'));

        if ($cimzettek === null || $masolat === null || $titkos === null) {
            wp_send_json_error(['uzenet' => 'Valamelyik e-mail-cím hibás. A címeket vesszővel válaszd el.']);
        }

        if (!$piszkozat && $cimzettek === [] && $masolat === [] && $titkos === []) {
            wp_send_json_error(['uzenet' => 'Adj meg legalább egy címzettet.']);
        }

        if (!$piszkozat && $targy === '' && trim($torzs) === '') {
            wp_send_json_error(['uzenet' => 'Üres levelet nem küldök: írj tárgyat vagy szöveget.']);
        }

        // Az eredeti levél (válasz, továbbítás, piszkozat): a hivatkozó fejlécekhez és a továbbvitt csatolmányokhoz.
        $eredeti = null;
        $er_sor  = null;
        $er_mappa = null;

        if ($eredeti_id > 0 && in_array($mod, ['valasz', 'mindenkinek', 'tovabbit', 'piszkozat'], true)) {
            $er_sor   = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::tabla() . ' WHERE id = %d AND fiok = %s', $eredeti_id, $fk));
            $er_mappa = is_object($er_sor) ? self::mappa((int) $er_sor->mappa_id) : null;
        }

        @set_time_limit(120); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

        try {
            $imap = null;

            if (is_object($er_sor) && $er_mappa !== null) {
                $eredeti = self::level_letolt($er_sor, $er_mappa, $fiok, $imap);
            }

            $m = self::levelkuldo($fiok);

            foreach ($cimzettek as $c) {
                $m->addAddress($c['email'], $c['nev']);
            }

            foreach ($masolat as $c) {
                $m->addCC($c['email'], $c['nev']);
            }

            foreach ($titkos as $c) {
                $m->addBCC($c['email'], $c['nev']);
            }

            $m->Subject = $targy;
            $m->isHTML(false);
            $m->Body = $torzs !== '' ? $torzs : ' ';

            if ($eredeti !== null && in_array($mod, ['valasz', 'mindenkinek'], true)) {
                $mid = trim((string) ($eredeti['fejlec']['message-id'] ?? ''));

                if ($mid !== '') {
                    $m->addCustomHeader('In-Reply-To', $mid);
                    $m->addCustomHeader('References', trim(trim((string) ($eredeti['fejlec']['references'] ?? '')) . ' ' . $mid));
                }
            }

            $meret = 0;

            // Az eredeti levélből továbbvitt csatolmányok.
            if ($eredeti !== null && in_array($mod, ['tovabbit', 'piszkozat'], true)) {
                foreach ($er_csat as $n) {
                    if (isset($eredeti['csatolmanyok'][$n])) {
                        $c      = $eredeti['csatolmanyok'][$n];
                        $meret += (int) $c['meret'];
                        $m->addStringAttachment((string) $c['tartalom'], (string) $c['nev'], 'base64', (string) $c['tipus']);
                    }
                }
            }

            // A most feltöltött fájlok.
            $fajlok = $_FILES['csatolmany'] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput

            if (is_array($fajlok) && is_array($fajlok['name'] ?? null)) {
                foreach ($fajlok['name'] as $i => $nev) {
                    $hiba = (int) ($fajlok['error'][$i] ?? UPLOAD_ERR_NO_FILE);

                    if ($hiba === UPLOAD_ERR_NO_FILE) {
                        continue;
                    }

                    if ($hiba !== UPLOAD_ERR_OK || !is_uploaded_file((string) $fajlok['tmp_name'][$i])) {
                        throw new \RuntimeException('„' . sanitize_file_name((string) $nev) . '" feltöltése nem sikerült (túl nagy lehet).');
                    }

                    $meret += (int) $fajlok['size'][$i];
                    $m->addAttachment((string) $fajlok['tmp_name'][$i], sanitize_file_name((string) $nev));
                }
            }

            if ($meret > self::CSATOLMANY_MAX) {
                throw new \RuntimeException('A csatolmányok együtt túl nagyok (' . size_format($meret) . '); a korlát ' . size_format(self::CSATOLMANY_MAX) . '.');
            }

            if ($piszkozat) {
                $piszk = self::szerep_mappa($fk, 'drafts');

                if ($piszk === null) {
                    throw new \RuntimeException('Ezen a fiókon nincs Piszkozatok mappa.');
                }

                // Címzett nélkül a levél nem állítható össze: piszkozathoz ez nem akadály.
                if ($cimzettek === [] && $masolat === [] && $titkos === []) {
                    $m->addAddress('undisclosed-recipients@invalid.invalid');
                }

                $m->preSend();

                $nyers = (string) preg_replace('/^To: undisclosed-recipients@invalid\.invalid\r?\n/mi', '', $m->getSentMIMEMessage());
                $imap  = $imap ?? self::imap($fiok);

                $imap->hozzafuz((string) $piszk->nyers, $nyers, ['\\Draft', '\\Seen']);
            } else {
                $m->send();

                // A Gmail az elküldött levelet maga teszi az Elküldött mappába; más szerveren nekünk kell.
                if (!self::gmail($fiok) && !self::helyi_host((string) $fiok['smtp_host'])) {
                    $elk = self::szerep_mappa($fk, 'sent');

                    if ($elk !== null) {
                        $imap = $imap ?? self::imap($fiok);
                        $imap->hozzafuz((string) $elk->nyers, $m->getSentMIMEMessage(), ['\\Seen']);
                    }
                }
            }

            // Utómunka az eredeti levélen: megválaszolt jelző, a régi piszkozat törlése.
            if (is_object($er_sor) && $er_mappa !== null) {
                $imap = $imap ?? self::imap($fiok);
                $imap->kivalaszt((string) $er_mappa->nyers);

                if ($mod === 'piszkozat') {
                    $imap->vegleg_torol((string) (int) $er_sor->uid);
                    $wpdb->delete(self::tabla(), ['id' => (int) $er_sor->id]);
                } elseif (!$piszkozat && in_array($mod, ['valasz', 'mindenkinek'], true)) {
                    $imap->jelzo((string) (int) $er_sor->uid, true, ['\\Answered']);
                    // A megválaszolt levél már nem teendő.
                    $wpdb->update(self::tabla(), ['valaszolt' => 1, 'elintezve' => 1], ['id' => (int) $er_sor->id]);
                } elseif (!$piszkozat && $mod === 'tovabbit') {
                    $imap->jelzo((string) (int) $er_sor->uid, true, ['$Forwarded']);
                }
            }

            if ($imap !== null) {
                $imap->kilep();
            }
        } catch (\Throwable $hiba) {
            wp_send_json_error(['uzenet' => ($piszkozat ? 'A piszkozat mentése nem sikerült: ' : 'A levél NEM ment el: ') . wp_strip_all_tags($hiba->getMessage())]);
        }

        wp_send_json_success([
            'uzenet'    => $piszkozat ? 'Piszkozatként elmentve.' : 'A levél elment.',
            'piszkozat' => $piszkozat,
        ]);
    }

    /* =================================================================
     * Az oldal
     * ============================================================== */

    public static function admin_eszkozok(): void
    {
        $oldal = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if (!str_starts_with($oldal, SDH_Muhely_Admin_UI::FOMENU)) {
            return;
        }

        wp_enqueue_style('sdh-muhely-levelezes', SDH_MUHELY_URL . 'assets/levelezes.css', ['sdh-muhely-admin'], SDH_Muhely_Admin_UI::eszkoz_verzio('assets/levelezes.css'));
        // Minden CRM-oldalon fut: az új levél jelzése nem csak a Levelezés oldalon él.
        wp_enqueue_script('sdh-muhely-levelezes', SDH_MUHELY_URL . 'assets/levelezes.js', ['sdh-muhely-app'], SDH_Muhely_Admin_UI::eszkoz_verzio('assets/levelezes.js'), true);
    }

    /** Van-e legalább egy használható fiók (a jelző-lekérdezés csak akkor indul). */
    public static function van_fiok(): bool
    {
        return self::fiokok() !== [];
    }

    public static function oldal(): void
    {
        $fiokok = self::fiokok();

        ?>
        <div class="sdh-wrap sdh-level-oldal">
            <?php if ($fiokok === []) : ?>
                <?php SDH_Muhely_Admin_UI::fejlec('Levelezés', 'A szerviz e-mail-fiókjai egy helyen.'); ?>
                <div class="sdh-doboz">
                    <p>Még nincs bekötve e-mail-fiók. A fiókok címét és alkalmazásjelszavát itt add meg:
                        <a href="<?php echo esc_url(SDH_Muhely_Modulok::admin_url('beallitasok') . '#levelezes'); ?>">Beállítások → Levelezés – e-mail-fiókok</a>.</p>
                </div>
            <?php else : ?>
                <div class="sdh-level" data-sdh-level
                     data-fiokok="<?php echo esc_attr((string) wp_json_encode(self::fiokok_kifele())); ?>"
                     data-ugyfel-url="<?php echo esc_attr(SDH_Muhely_Modulok::url('ugyfelek')); ?>">
                    <noscript><div class="sdh-uzenet sdh-uzenet--hiba">A levelezőhöz JavaScript kell.</div></noscript>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
